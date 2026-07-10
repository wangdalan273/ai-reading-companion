<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Chapter;
use App\Services\BookTextService;
use App\Services\LlmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * N3 — Concept / knowledge graph.
 *
 * Flow (mirrors P12): ensure chapters are extracted (BookTextService), then
 * build a concept graph from the structured source text. With a real AI key the
 * model extracts subject-relation-object triples + short definitions per text
 * chunk; offline (no key) a lightweight co-occurrence heuristic produces a
 * demoable graph so the whole UX works without any external dependency.
 *
 * Stored on the book as `concept_graph_json`:
 *   { nodes:[{id,label,count,chapters:[idx...],def}], edges:[{from,to,label,weight}] }
 */
class GraphController extends Controller
{
    public function show(Book $book)
    {
        abort_unless($book->user_id === auth()->id(), 403);

        return view('book-graph', ['book' => $book]);
    }

    public function fetch(Book $book)
    {
        abort_unless($book->user_id === auth()->id(), 403);

        $graph = $book->concept_graph_json ? json_decode($book->concept_graph_json, true) : null;
        if (! empty($graph['nodes'])) {
            $graph = $this->enrichWithQuotes($graph, $book);
        }

        return response()->json([
            'ok' => true,
            'status' => $book->concept_graph_status,
            'graph' => $graph,
        ]);
    }

    /**
     * 为每个概念节点附加原文摘录：从章节中找包含该概念的句子。
     */
    protected function enrichWithQuotes(array $graph, Book $book): array
    {
        $chapters = $book->chapters()->whereNotNull('source_text')->get();
        $text = $chapters->implode('source_text', "\n");
        $sentences = preg_split('/[。！？\n;；]+/u', $text);

        foreach ($graph['nodes'] as $i => $node) {
            $label = $node['label'] ?? '';
            if (! $label) {
                $graph['nodes'][$i]['quotes'] = [];
                continue;
            }
            $quotes = [];
            foreach ($sentences as $s) {
                $s = trim($s);
                if ($s === '' || mb_strlen($s, 'UTF-8') < 5) {
                    continue;
                }
                if (mb_strpos($s, $label, 0, 'UTF-8') !== false) {
                    $quotes[] = $s;
                    if (count($quotes) >= 3) {
                        break;
                    }
                }
            }
            $graph['nodes'][$i]['quotes'] = $quotes;
            // 离线模式没有定义时，用第一句做兜底定义
            if (empty($graph['nodes'][$i]['def']) && ! empty($quotes[0])) {
                $graph['nodes'][$i]['def'] = $quotes[0];
            }
        }

        return $graph;
    }

    public function generate(Request $request, Book $book)
    {
        abort_unless($book->user_id === auth()->id(), 403);

        // Real-key mode issues many LLM calls; lift the script timeout so a long
        // generation is not killed at 30s. Guarded in case the env disallows it.
        try { set_time_limit(0); } catch (\Throwable $e) {}

        $service = app(BookTextService::class);
        $service->extract($book);

        $chapters = $book->chapters()->whereNotNull('source_text')->get();

        if ($chapters->isEmpty()) {
            return response()->json([
                'ok' => false,
                'msg' => '未能从本书提取到可分析的文本。若是扫描版 PDF，请确认本地 OCR 服务（PP-OCRv6）已启动，且 PHP 已启用 Imagick。',
            ]);
        }

        $book->update(['concept_graph_status' => 'working', 'concept_graph_error' => null]);

        $llm = new LlmService;
        $note = '';

        try {
            if ($llm->isMockConfig()) {
                $graph = $this->mockGraph($chapters);
            } else {
                try {
                    $graph = $this->llmGraph($llm, $chapters);
                } catch (\Throwable $e) {
                    $graph = null;
                    $note = '（真实模型调用出错：'.$e->getMessage().'，已用离线启发式生成）';
                }
                // 真实模型大量 429 / 限流时，llmGraph 不会产生有效节点 →
                // 降级到离线启发式，保证 concept_graph_json 一定落库、下次进入可见。
                if (empty($graph) || count($graph['nodes'] ?? []) < 3) {
                    $graph = $this->mockGraph($chapters);
                    $note = $note ?: '（真实模型触发限流 / 未返回有效概念，已用离线启发式生成；额度恢复后在「AI 设置」重填密钥并重新生成即可获得真实图谱）';
                }
            }

            $book->update([
                'concept_graph_json' => json_encode($graph, JSON_UNESCAPED_UNICODE),
                'concept_graph_status' => 'done',
                'concept_graph_at' => now(),
                'concept_graph_error' => $note ?: null,
            ]);
        } catch (\Throwable $e) {
            // 连离线启发式都崩 → 才标记失败
            $book->update(['concept_graph_status' => 'failed', 'concept_graph_error' => $e->getMessage()]);

            return response()->json(['ok' => false, 'msg' => $e->getMessage()]);
        }

        return response()->json(['ok' => true, 'graph' => $graph, 'msg' => $note]);
    }

    /**
     * Real-key path: per-chunk SPO extraction, then merge + normalize.
     */
    protected function llmGraph(LlmService $llm, $chapters): array
    {
        $nodes = [];   // label(lower) => {id,label,count,chapters:set,def}
        $edges = [];   // key => {from,to,label,weight}
        $order = 0;

        $totalChunks = 0;
        foreach ($chapters as $ch) {
            // 单进程 dev server 下每次 complete() 都阻塞；概念图谱取前 6 个分块
            // （约前 1-2 章切分）已足够建立主要概念关系，把耗时控制在 1 分钟内。
            if ($totalChunks >= 6) {
                break;
            }
            $text = mb_substr($ch->source_text, 0, 6000, 'UTF-8');
            $chunks = $this->chunk($text, 2000, 3);
            foreach ($chunks as $piece) {
                if ($totalChunks >= 6) {
                    break;
                }
                $totalChunks++;
                $raw = $llm->complete(
                    "你是知识图谱抽取器。阅读下面这段书摘，抽取其中最重要的概念之间的关系，"
                    ."输出 JSON，格式：{\"triples\":[{\"subject\":\"概念A\",\"relation\":\"关系\",\"object\":\"概念B\"}],"
                    ."\"defs\":{\"概念A\":\"一句话通俗定义\"}}。只输出 JSON，不要任何解释或代码围栏。"
                    ."关系用中文短词（如 导致/属于/对比/支持/举例/组成/反对）。概念控制在 2-6 字，最多 14 个三元组。",
                    $piece
                );
                $data = $this->parseJson($raw);
                if (! $data) {
                    continue;
                }
                $this->mergeTriples($data, $nodes, $edges, $ch->idx, $order);
            }
        }

        return $this->finalize($nodes, $edges);
    }

    protected function mergeTriples(array $data, array &$nodes, array &$edges, int $chapterIdx, int &$order): void
    {
        $triples = $data['triples'] ?? [];
        foreach ($triples as $t) {
            $s = $this->norm($t['subject'] ?? '');
            $o = $this->norm($t['object'] ?? '');
            $rel = trim($t['relation'] ?? '');
            if ($s === '' || $o === '' || $s === $o) {
                continue;
            }
            foreach ([$s, $o] as $lab) {
                if (! isset($nodes[$lab])) {
                    $nodes[$lab] = [
                        'id' => 'n'.($order++),
                        'label' => $lab,
                        'count' => 0,
                        'chapters' => [],
                        'def' => '',
                    ];
                }
                $nodes[$lab]['count']++;
                if (! in_array($chapterIdx, $nodes[$lab]['chapters'], true)) {
                    $nodes[$lab]['chapters'][] = $chapterIdx;
                }
            }
            $defs = $data['defs'] ?? [];
            if (isset($defs[$s]) && $nodes[$s]['def'] === '') {
                $nodes[$s]['def'] = trim($defs[$s]);
            }
            if (isset($defs[$o]) && $nodes[$o]['def'] === '') {
                $nodes[$o]['def'] = trim($defs[$o]);
            }
            $key = $nodes[$s]['id'].'||'.$nodes[$o]['id'].'||'.$rel;
            if (! isset($edges[$key])) {
                $edges[$key] = ['from' => $nodes[$s]['id'], 'to' => $nodes[$o]['id'], 'label' => $rel, 'weight' => 0];
            }
            $edges[$key]['weight']++;
        }
    }

    /**
     * Offline path: frequency-based keyword extraction + in-sentence co-occurrence.
     */
    protected function mockGraph($chapters): array
    {
        $stop = $this->stopwords();
        $nodes = [];
        $edges = [];
        $order = 0;
        $perChapterTop = [];

        foreach ($chapters as $ch) {
            $text = $ch->source_text;
            $freq = [];
            $len = mb_strlen($text, 'UTF-8');
            for ($i = 0; $i < $len; $i++) {
                foreach ([2, 3, 4] as $n) {
                    if ($i + $n > $len) {
                        continue;
                    }
                    $gram = mb_substr($text, $i, $n, 'UTF-8');
                    if (! preg_match('/^[一-龥]+$/u', $gram)) {
                        continue;
                    }
                    if (isset($stop[$gram])) {
                        continue;
                    }
                    $freq[$gram] = ($freq[$gram] ?? 0) + 1;
                }
            }
            arsort($freq);
            $top = array_slice(array_keys($freq), 0, 10, true);
            $perChapterTop[$ch->idx] = $top;

            foreach ($top as $w) {
                $k = $this->norm($w);
                if (! isset($nodes[$k])) {
                    $nodes[$k] = ['id' => 'n'.($order++), 'label' => $w, 'count' => 0, 'chapters' => [], 'def' => ''];
                }
                $nodes[$k]['count']++;
                if (! in_array($ch->idx, $nodes[$k]['chapters'], true)) {
                    $nodes[$k]['chapters'][] = $ch->idx;
                }
            }

            // co-occurrence: keywords appearing in the same sentence are linked.
            $sentences = preg_split('/[。！？\n;；]+/u', $text);
            foreach ($sentences as $s) {
                $present = [];
                foreach ($top as $w) {
                    if (mb_strpos($s, $w, 0, 'UTF-8') !== false) {
                        $present[] = $w;
                    }
                }
                for ($a = 0; $a < count($present); $a++) {
                    for ($b = $a + 1; $b < count($present); $b++) {
                        $ka = $this->norm($present[$a]);
                        $kb = $this->norm($present[$b]);
                        if ($ka === $kb) {
                            continue;
                        }
                        $key = $nodes[$ka]['id'].'||'.$nodes[$kb]['id'].'||共现';
                        if (! isset($edges[$key])) {
                            $edges[$key] = ['from' => $nodes[$ka]['id'], 'to' => $nodes[$kb]['id'], 'label' => '共现', 'weight' => 0];
                        }
                        $edges[$key]['weight']++;
                    }
                }
            }
        }

        return $this->finalize($nodes, $edges);
    }

    protected function finalize(array $nodes, array $edges): array
    {
        // Sort nodes by frequency, cap to keep the canvas readable.
        $sorted = collect($values = array_values($nodes))->sortByDesc('count')->values()->all();
        $keep = array_slice($sorted, 0, 90);
        $keepIds = collect($keep)->pluck('id')->flip();

        $keepEdges = collect(array_values($edges))
            ->filter(fn ($e) => $keepIds->has($e['from']) && $keepIds->has($e['to']))
            ->sortByDesc('weight')
            ->slice(0, 200)
            ->values()
            ->all();

        return ['nodes' => array_values($keep), 'edges' => $keepEdges];
    }

    protected function chunk(string $text, int $size, int $max): array
    {
        $len = mb_strlen($text, 'UTF-8');
        if ($len <= $size) {
            return [$text];
        }
        $out = [];
        $step = (int) ceil($len / min($max, (int) ceil($len / $size)));
        for ($i = 0; $i < $len && count($out) < $max; $i += $step) {
            $out[] = mb_substr($text, $i, $size, 'UTF-8');
        }

        return $out;
    }

    protected function norm(string $s): string
    {
        return trim(preg_replace('/\s+/u', '', $s));
    }

    /**
     * Best-effort JSON extraction from an LLM reply (strips code fences / prose).
     */
    protected function parseJson(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?/i', '', $raw);
        $raw = preg_replace('/```$/', '', $raw);
        $raw = trim($raw);
        if (($dec = json_decode($raw, true)) && is_array($dec)) {
            return $dec;
        }
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $dec = json_decode($m[0], true);
            if (is_array($dec)) {
                return $dec;
            }
        }

        return null;
    }

    protected function stopwords(): array
    {
        $list = ['的','了','是','在','和','与','等','一个','我们','他们','因为','所以','这个','那个','可以','这样','一种',
            '以及','通过','对于','自己','什么','这些','那些','已经','可能','就是','还是','不是','没有','也是','这种','那种',
            '进行','由于','如果','虽然','但是','然而','于是','并且','或者','而且','之后','之前','时候','现在','这里','那里',
            '其中','为了','从而','一些','一直','一样','一样','不会','不能','不得','不少','之上','之下','之中','出来','起来',
            '孩子们','孩子','它们','她们','大家','别人','什么','怎么','怎样','如何','为何','多少','一切','所有','任何','每个',
            '许多','很多','一些','某些','某种','有点','非常','十分','更加','比较','十分','似乎','仿佛','犹如','成为','作为','称为'];

        return array_flip($list);
    }
}
