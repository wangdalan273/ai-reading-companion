<?php

namespace App\Services;

use App\Models\Book;
use App\Models\RagChunk;
use App\Models\UserPrompt;
use App\Services\BookTextService;
use App\Services\LlmService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

/**
 * Phase 3 — P13 通用跨书 / 跨笔记 RAG 引擎。
 *
 * 第一性原理：检索单元（RagChunk）是「来源无关」的——书、Obsidian vault、
 * 任意 markdown 文件夹、粘贴笔记，共用同一套索引 + 检索 + 问答。Obsidian 只是
 * 第 1 个实现的连接器（保留 [[双链]]），不是唯一路径。
 *
 * 检索双路：
 *  - BM25 关键词路：始终可用，离线也能答（中文做 字+二元 切分）。
 *  - 向量路：可插拔；embeddings() 返回 null（无 key / 非 openai 协议 / 失败）
 *    时整路失效，BM25 兜底，永不假死。
 */
class RagService
{
    protected LlmService $llm;

    public function __construct(LlmService $llm)
    {
        $this->llm = $llm;
    }

    /**
     * 重建某用户全部索引：书 + Obsidian vault（若配置）+ 通用笔记文件夹（若配置）。
     * @return array{books:int, vault:int, notes:int, chunks:int}
     */
    public function reindexAll(int $userId): array
    {
        $books = 0;
        $chunks = 0;
        foreach (Book::where('user_id', $userId)->get() as $book) {
            $n = $this->indexBook($book);
            if ($n > 0) {
                $books++;
                $chunks += $n;
            }
        }

        $vault = 0;
        $notes = 0;
        $cfg = \App\Models\AiConfig::where('user_id', $userId)->first();
        if ($cfg?->vault_path && is_dir($cfg->vault_path)) {
            $vault = $this->indexVault($cfg->vault_path, $userId);
            $chunks += $vault;
        }
        if ($cfg?->note_folder && is_dir($cfg->note_folder)) {
            $notes = $this->indexNoteFolder($cfg->note_folder, $userId);
            $chunks += $notes;
        }

        return ['books' => $books, 'vault' => $vault, 'notes' => $notes, 'chunks' => $chunks];
    }

    /**
     * 索引单本书（章节 source_text 切片）。返回新增 chunk 数。
     */
    public function indexBook(Book $book): int
    {
        RagChunk::where('user_id', $book->user_id)
            ->where('source_type', 'book')
            ->where('book_id', $book->id)
            ->delete();

        app(BookTextService::class)->extract($book);
        $chapters = $book->chapters()->whereNotNull('source_text')->get();
        if ($chapters->isEmpty()) {
            return 0;
        }

        $count = 0;
        foreach ($chapters as $ch) {
            $pieces = $this->chunkText($ch->source_text, 500, 80);
            foreach ($pieces as $i => $text) {
                RagChunk::create([
                    'user_id' => $book->user_id,
                    'source_type' => 'book',
                    'book_id' => $book->id,
                    'title' => $ch->title,
                    'content' => $text,
                    'chunk_index' => $i,
                    'meta' => ['chapter_idx' => $ch->idx, 'chapter_title' => $ch->title],
                ]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * 索引 Obsidian vault（头等连接器）：递归 .md，保留 [[双链]]，排除系统目录。
     */
    public function indexVault(string $path, int $userId): int
    {
        return $this->indexMarkdownDir($path, $userId, 'obsidian');
    }

    /**
     * 索引通用 markdown 文件夹（不绑定 Obsidian）：同样切分与 [[双链]] 解析。
     */
    public function indexNoteFolder(string $path, int $userId): int
    {
        return $this->indexMarkdownDir($path, $userId, 'note');
    }

    protected function indexMarkdownDir(string $root, int $userId, string $sourceType): int
    {
        RagChunk::where('user_id', $userId)
            ->where('source_type', $sourceType)
            ->delete();

        if (! is_dir($root)) {
            return 0;
        }

        $files = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'md') {
                continue;
            }
            $full = $file->getPathname();
            // 排除 Obsidian / git / 模板等系统目录
            if (preg_match('#[\\/](\.obsidian|\.trash|\.git|templates)[\\/]#', $full)) {
                continue;
            }
            $files[] = $full;
        }

        $count = 0;
        foreach ($files as $full) {
            $content = @file_get_contents($full);
            if ($content === false) {
                continue;
            }
            $rel = ltrim(str_replace($root, '', $full), '/\\');
            $parsed = $this->parseMarkdown($content);
            $segments = $this->segmentMarkdown($parsed['body']);
            $i = 0;
            foreach ($segments as $seg) {
                $text = trim($seg['heading']."\n".$seg['text']);
                $text = $text !== '' ? $text : $parsed['title'];
                if ($text === '') {
                    continue;
                }
                RagChunk::create([
                    'user_id' => $userId,
                    'source_type' => $sourceType,
                    'source_path' => $rel,
                    'title' => $parsed['title'],
                    'content' => $text,
                    'chunk_index' => $i++,
                    'links' => $parsed['links'],
                    'meta' => ['tags' => $parsed['tags'], 'file' => $rel],
                ]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * BM25 检索（通用，不区分来源）。返回带 score 与 snippet 的 chunk 数组。
     * @return array<int,array{chunk:RagChunk,score:float,snippet:string}>
     */
    public function search(string $query, int $userId, int $topK = 8): array
    {
        $chunks = RagChunk::where('user_id', $userId)->get();
        if ($chunks->isEmpty()) {
            return [];
        }

        $docs = $chunks->map(function (RagChunk $c) {
            return $this->tokenize($c->content);
        })->all();

        $queryTokens = $this->tokenize($query);
        if (empty($queryTokens)) {
            return [];
        }

        $N = count($docs);
        $docLens = array_map('count', $docs);
        $avgdl = $N ? array_sum($docLens) / $N : 0;

        // IDF per query token
        $idf = [];
        $df = [];
        foreach ($queryTokens as $t) {
            if (isset($df[$t])) {
                continue;
            }
            $n = 0;
            foreach ($docs as $dt) {
                if (isset(array_count_values($dt)[$t])) {
                    $n++;
                }
            }
            $df[$t] = $n;
            $idf[$t] = log(1 + ($N - $n + 0.5) / ($n + 0.5));
        }

        // 向量路（可插拔）：query 有向量且部分 chunk 有 embedding 才生效
        $queryVec = $this->llm->embeddings([$query]);
        $queryVec = (is_array($queryVec) && ! empty($queryVec[0])) ? $queryVec[0] : null;
        $hasVec = $queryVec !== null;
        $cosine = [];
        if ($hasVec) {
            foreach ($chunks as $idx => $c) {
                $v = $c->embedding;
                $cosine[$idx] = ($v && is_array($v)) ? $this->cosine($queryVec, $v) : 0.0;
            }
        }

        $k1 = 1.5;
        $b = 0.75;
        $scored = [];
        foreach ($chunks as $idx => $c) {
            $dt = $docs[$idx];
            $tf = array_count_values($dt);
            $score = 0.0;
            foreach ($queryTokens as $t) {
                $f = $tf[$t] ?? 0;
                if ($f === 0) {
                    continue;
                }
                $score += $idf[$t] * ($f * ($k1 + 1)) / ($f + $k1 * (1 - $b + $b * $docLens[$idx] / max($avgdl, 1)));
            }
            $scored[$idx] = $score;
        }

        // 归一化融合
        $bm25max = max($scored) ?: 1;
        $cosmax = $hasVec ? (max($cosine) ?: 1) : 1;
        $fused = [];
        foreach ($scored as $idx => $s) {
            $bs = $s / $bm25max;
            $cs = $hasVec ? ($cosine[$idx] / $cosmax) : 0;
            $fused[$idx] = $bs * 0.7 + $cs * 0.3;
        }

        arsort($fused);
        $top = array_slice($fused, 0, $topK, true);

        $result = [];
        foreach ($top as $idx => $score) {
            if ($score <= 0) {
                continue;
            }
            $c = $chunks[$idx];
            $result[] = [
                'chunk' => $c,
                'score' => $score,
                'snippet' => $this->snippet($c->content, $queryTokens),
            ];
        }

        return $result;
    }

    /**
     * 带引用流式问答。检索 topK → 拼 context → 注入自定义 prompt → 流式。
     */
    public function answer(string $query, int $userId, ?int $promptId = null): \Generator
    {
        $hits = $this->search($query, $userId, 8);
        if (empty($hits)) {
            yield '（检索库暂无内容：请先在「🧠 记忆」页点「重建索引」，或导入更多书 / 配置 Obsidian vault 路径。）';

            return;
        }

        $contextParts = [];
        foreach ($hits as $h) {
            $contextParts[] = '【'.($this->citation($h['chunk']))."】\n".$h['chunk']->content;
        }
        $context = implode("\n\n---\n\n", $contextParts);

        $system = $this->buildSystem($userId, $promptId);

        yield from $this->llm->stream($query, $context, '', $system);
    }

    protected function buildSystem(int $userId, ?int $promptId): string
    {
        $custom = null;
        if ($promptId) {
            $custom = UserPrompt::where('user_id', $userId)->where('id', $promptId)->value('prompt');
        }
        if ($custom === null) {
            $custom = UserPrompt::where('user_id', $userId)->where('is_default', true)->value('prompt');
        }
        $base = $custom !== null && $custom !== ''
            ? $custom
            : config('companion.system_prompt', '');

        return $base."\n\n【跨书 / 跨笔记检索问答规则】\n"
            ."你是基于读者个人知识库的伴读助手。下面「【来源】…」块是检索到的资料，"
            ."请结合它们回答。引用时务必在句末用来源标记：\n"
            ."- 书：《书名》第N章\n"
            ."- Obsidian 笔记：[[笔记标题]]\n"
            ."- 通用笔记：《笔记标题》\n"
            ."资料不足时坦诚说明，不要编造。回答用通俗中文，像给朋友讲解。";
    }

    // ---------- 工具 ----------

    /**
     * 中英混合分词：ASCII 词 + 中文单字 + 中文二元。
     */
    protected function tokenize(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');
        $tokens = [];

        // ASCII 词
        if (preg_match_all('/[a-z0-9]+/', $text, $m)) {
            foreach ($m[0] as $w) {
                $tokens[] = $w;
            }
        }

        // 中文：去非中文后做 单字 + 二元
        preg_match_all('/[一-鿿]/u', $text, $cjk);
        $chars = $cjk[0] ?? [];
        foreach ($chars as $ch) {
            $tokens[] = 'c:'.$ch;
        }
        for ($i = 0; $i < count($chars) - 1; $i++) {
            $tokens[] = 'b:'.$chars[$i].$chars[$i + 1];
        }

        return $tokens;
    }

    protected function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $dot += ($a[$i] ?? 0) * ($b[$i] ?? 0);
            $na += ($a[$i] ?? 0) ** 2;
            $nb += ($b[$i] ?? 0) ** 2;
        }

        return ($na > 0 && $nb > 0) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }

    /**
     * 长文按窗口切片（带重叠）。
     */
    protected function chunkText(string $text, int $size = 500, int $overlap = 80): array
    {
        $text = preg_replace('/\s+/', "\n", $text);
        $len = mb_strlen($text, 'UTF-8');
        if ($len <= $size) {
            return [trim($text)];
        }
        $pieces = [];
        $start = 0;
        while ($start < $len) {
            $piece = mb_substr($text, $start, $size, 'UTF-8');
            $pieces[] = trim($piece);
            if ($start + $size >= $len) {
                break;
            }
            $start += $size - $overlap;
        }

        return $pieces;
    }

    /**
     * 解析 markdown：frontmatter tags + [[双链]] + 标题。
     */
    protected function parseMarkdown(string $content): array
    {
        $tags = [];
        $title = '';
        $body = $content;

        if (preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $content, $fm)) {
            $body = substr($content, strlen($fm[0]));
            if (preg_match('/tags:\s*\[(.*?)\]/s', $fm[1], $t)) {
                $tags = array_map('trim', explode(',', $t[1]));
            } elseif (preg_match_all('/-\s*(\S+)/', $fm[1], $t)) {
                $tags = $t[1];
            }
        }

        if (preg_match('/^#\s+(.+)$/m', $body, $h)) {
            $title = trim($h[1]);
        }

        // [[双链]]，支持 [[A|别名]]
        $links = [];
        if (preg_match_all('/\[\[([^\]]+)\]\]/', $body, $l)) {
            foreach ($l[1] as $raw) {
                $name = trim(explode('|', $raw)[0]);
                if ($name !== '') {
                    $links[] = $name;
                }
            }
        }

        return ['title' => $title, 'tags' => $tags, 'links' => array_values(array_unique($links)), 'body' => $body];
    }

    /**
     * 按标题切分为语义段。
     */
    protected function segmentMarkdown(string $body): array
    {
        $lines = explode("\n", $body);
        $segments = [];
        $curHeading = '';
        $curText = [];
        foreach ($lines as $line) {
            if (preg_match('/^#{1,6}\s+(.+)$/', $line, $m)) {
                if ($curHeading !== '' || $curText !== []) {
                    $segments[] = ['heading' => $curHeading, 'text' => implode("\n", $curText)];
                }
                $curHeading = trim($m[1]);
                $curText = [];
            } else {
                $curText[] = $line;
            }
        }
        if ($curHeading !== '' || $curText !== []) {
            $segments[] = ['heading' => $curHeading, 'text' => implode("\n", $curText)];
        }

        // 超长段再按窗口切
        $out = [];
        foreach ($segments as $seg) {
            $text = trim($seg['text']);
            if (mb_strlen($text, 'UTF-8') <= 600) {
                $out[] = $seg;
            } else {
                foreach ($this->chunkText($text, 500, 80) as $piece) {
                    $out[] = ['heading' => $seg['heading'], 'text' => $piece];
                }
            }
        }

        return $out;
    }

    /**
     * N12 个人知识库图谱：从 rag_chunks 聚合"卡片"并建边（离线，不调模型）。
     * 节点：书 / 笔记文件 / [[双链]] stub；边：wikilink（强）+ related（BM25 弱）。
     * @return array{nodes:array,edges:array,stats:array}
     */
    public function buildKnowledgeGraph(int $userId): array
    {
        $chunks = RagChunk::where('user_id', $userId)->get();
        if ($chunks->isEmpty()) {
            return ['nodes' => [], 'edges' => [], 'stats' => ['books' => 0, 'notes' => 0, 'wikilinks' => 0, 'related' => 0]];
        }

        // 1) 聚合成卡片（按书 / 笔记文件）
        $cards = [];
        foreach ($chunks as $c) {
            $key = $c->source_type === 'book'
                ? 'book:'.(int) $c->book_id
                : 'note:'.($c->source_path ?: $c->title ?: 'untitled');
            if (! isset($cards[$key])) {
                $cards[$key] = [
                    'type' => $c->source_type,
                    'title' => $c->source_type === 'book'
                        ? ($c->book ? $c->book->title : '未知书')
                        : ($c->title ?: basename($c->source_path ?? '笔记')),
                    'book_id' => $c->book_id,
                    'source_path' => $c->source_path,
                    'content' => '',
                    'links' => [],
                ];
            }
            $cards[$key]['content'] .= "\n".$c->content;
            if ($c->links && is_array($c->links)) {
                $cards[$key]['links'] = array_merge($cards[$key]['links'], $c->links);
            }
        }
        foreach ($cards as &$cd) {
            $cd['links'] = array_values(array_unique($cd['links']));
        }
        unset($cd);

        // 2) 节点
        $nodes = [];
        $titleToIdx = [];
        $idx = 0;
        foreach ($cards as $cd) {
            $nodes[] = [
                'id' => $idx,
                'type' => $cd['type'],
                'title' => $cd['title'],
                'book_id' => $cd['book_id'],
                'source_path' => $cd['source_path'],
                'preview' => mb_substr(preg_replace('/\s+/', ' ', $cd['content']), 0, 160),
                'links' => $cd['links'],
                'degree' => 0,
            ];
            $titleToIdx[$cd['title']] = $idx;
            $idx++;
        }

        // 3) [[双链]] stub：links 中提到的标题无对应卡片 → 建 stub 节点
        $linkSet = [];
        foreach ($cards as $cd) {
            foreach ($cd['links'] as $lk) {
                $linkSet[$lk] = true;
            }
        }
        foreach ($linkSet as $lk => $v) {
            if (! isset($titleToIdx[$lk])) {
                $nodes[] = [
                    'id' => $idx, 'type' => 'wikilink', 'title' => $lk,
                    'book_id' => null, 'source_path' => null,
                    'preview' => '（双链目标：尚未导入对应笔记，可在 Obsidian 创建同名文件补全）',
                    'links' => [], 'degree' => 0,
                ];
                $titleToIdx[$lk] = $idx;
                $idx++;
            }
        }

        // 4) 边：wikilink（强）
        $edges = [];
        $edgeKey = [];
        foreach ($cards as $cd) {
            $fromIdx = $titleToIdx[$cd['title']] ?? null;
            if ($fromIdx === null) {
                continue;
            }
            foreach ($cd['links'] as $lk) {
                $toIdx = $titleToIdx[$lk] ?? null;
                if ($toIdx === null || $toIdx === $fromIdx) {
                    continue;
                }
                $k = $fromIdx.'-'.$toIdx;
                if (isset($edgeKey[$k])) {
                    continue;
                }
                $edgeKey[$k] = true;
                $edges[] = ['a' => $fromIdx, 'b' => $toIdx, 'type' => 'wikilink', 'label' => '双链'];
                $nodes[$fromIdx]['degree']++;
                $nodes[$toIdx]['degree']++;
            }
        }

        // 5) 边：related（弱，BM25 共现，每节点限 K 条）
        $tokenized = [];
        foreach ($nodes as $i => $n) {
            $tokenized[$i] = $this->tokenize($n['preview'] ?: $n['title']);
        }
        $N = count($nodes);
        $df = [];
        foreach ($tokenized as $toks) {
            foreach (array_unique($toks) as $t) {
                $df[$t] = ($df[$t] ?? 0) + 1;
            }
        }
        $K = 4;
        $relCount = array_fill(0, $N, 0);
        for ($i = 0; $i < $N; $i++) {
            for ($j = $i + 1; $j < $N; $j++) {
                if ($relCount[$i] >= $K && $relCount[$j] >= $K) {
                    continue;
                }
                $score = $this->bm25Pair($tokenized[$i], $tokenized[$j], $df, $N);
                if ($score > 1.3) {
                    $k = $i.'-'.$j;
                    if (isset($edgeKey[$k])) {
                        continue;
                    }
                    $edgeKey[$k] = true;
                    $edges[] = ['a' => $i, 'b' => $j, 'type' => 'related', 'label' => '相关'];
                    $nodes[$i]['degree']++;
                    $nodes[$j]['degree']++;
                    $relCount[$i]++;
                    $relCount[$j]++;
                }
            }
        }

        $stats = [
            'books' => count(array_filter($nodes, fn ($n) => $n['type'] === 'book')),
            'notes' => count(array_filter($nodes, fn ($n) => in_array($n['type'], ['note', 'obsidian'], true))),
            'wikilinks' => count(array_filter($edges, fn ($e) => $e['type'] === 'wikilink')),
            'related' => count(array_filter($edges, fn ($e) => $e['type'] === 'related')),
        ];

        return ['nodes' => $nodes, 'edges' => $edges, 'stats' => $stats];
    }

    /**
     * 两卡片 BM25 风格相似度（用于 related 弱边，对称取共同词）。
     */
    protected function bm25Pair(array $ta, array $tb, array $df, int $N): float
    {
        if (empty($ta) || empty($tb)) {
            return 0.0;
        }
        $fa = array_count_values($ta);
        $fb = array_count_values($tb);
        $common = array_intersect(array_keys($fa), array_keys($fb));
        if (empty($common)) {
            return 0.0;
        }
        $lenA = count($ta);
        $avgdl = max(1, ($lenA + count($tb)) / 2);
        $k1 = 1.5;
        $b = 0.75;
        $score = 0.0;
        foreach ($common as $t) {
            if (($df[$t] ?? 0) >= $N) {
                continue; // 几乎全文共有的词不贡献
            }
            $idf = log(1 + ($N - ($df[$t] ?? 1) + 0.5) / (($df[$t] ?? 1) + 0.5));
            $ft = min($fa[$t], $fb[$t]);
            $score += $idf * ($ft * ($k1 + 1)) / ($ft + $k1 * (1 - $b + $b * $lenA / $avgdl));
        }

        return $score;
    }

    public function citation(RagChunk $c): string
    {
        if ($c->source_type === 'book') {
            $book = $c->book;
            $name = $book ? $book->title : '未知书';

            return '《'.($name).'》'.($c->meta['chapter_title'] ?? '');
        }
        if ($c->source_type === 'obsidian') {
            return '[[笔记：'.($c->title ?: $c->source_path).']]';
        }

        return '笔记《'.($c->title ?: $c->source_path).'》';
    }

    protected function snippet(string $content, array $queryTokens): string
    {
        $text = preg_replace('/\s+/', ' ', $content);
        $len = mb_strlen($text, 'UTF-8');
        if ($len <= 120) {
            return $text;
        }
        // 找第一个命中词的位置
        $pos = null;
        foreach ($queryTokens as $t) {
            $clean = ltrim($t, 'cb:');
            $p = mb_stripos($text, $clean, 0, 'UTF-8');
            if ($p !== false) {
                $pos = $pos === null ? $p : min($pos, $p);
            }
        }
        $start = $pos !== null ? max(0, $pos - 40) : 0;

        return '…'.mb_substr($text, $start, 120, 'UTF-8').'…';
    }
}
