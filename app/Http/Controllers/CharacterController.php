<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Chapter;
use App\Services\BookTextService;
use App\Services\LlmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * N7 — Character relationship graph + timeline.
 *
 * Flow mirrors N3: ensure chapters are extracted (BookTextService), then build a
 * character graph from the structured source text. With a real AI key the model
 * extracts characters / relations / events JSON per chapter and we merge +
 * normalize across chapters; offline (no key) a lightweight keyword-frequency
 * heuristic (same shape as N3's concept-graph fallback) produces a demoable
 * "important-term relationship" graph so the whole UX works without any external
 * dependency and never soft-locks.
 *
 * Stored on the book as `character_graph_json`:
 *   { genre, characters:[{name,faction,desc,chapters:[]}],
 *     relations:[{from,to,type,desc}], events:[{time,desc,characters:[]}] }
 */
class CharacterController extends Controller
{
    protected $genre = 'unknown';

    public function show(Book $book)
    {
        abort_unless($book->user_id === auth()->id(), 403);

        return view('book-characters', ['book' => $book]);
    }

    public function fetch(Book $book)
    {
        abort_unless($book->user_id === auth()->id(), 403);

        $graph = $book->character_graph_json ? json_decode($book->character_graph_json, true) : null;
        if (! empty($graph['characters'])) {
            $graph = $this->enrichWithQuotes($graph, $book);
        }

        return response()->json([
            'ok' => true,
            'status' => $book->character_graph_status,
            'graph' => $graph,
        ]);
    }

    /**
     * 为每个人物节点附加原文摘录。
     */
    protected function enrichWithQuotes(array $graph, Book $book): array
    {
        $chapters = $book->chapters()->whereNotNull('source_text')->get();
        $text = $chapters->implode('source_text', "\n");
        $sentences = preg_split('/[。！？\n;；]+/u', $text);

        foreach ($graph['characters'] as $i => $c) {
            $name = $c['name'] ?? '';
            if (! $name) {
                $graph['characters'][$i]['quotes'] = [];
                continue;
            }
            $quotes = [];
            foreach ($sentences as $s) {
                $s = trim($s);
                if ($s === '' || mb_strlen($s, 'UTF-8') < 5) {
                    continue;
                }
                if (mb_strpos($s, $name, 0, 'UTF-8') !== false) {
                    $quotes[] = $s;
                    if (count($quotes) >= 3) {
                        break;
                    }
                }
            }
            $graph['characters'][$i]['quotes'] = $quotes;
            if (empty($graph['characters'][$i]['desc']) && ! empty($quotes[0])) {
                $graph['characters'][$i]['desc'] = '书中提到：'.$quotes[0];
            }
        }

        return $graph;
    }

    public function generate(Request $request, Book $book)
    {
        abort_unless($book->user_id === auth()->id(), 403);

        // Real-key mode may issue many LLM calls; lift the script timeout so a long
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

        $book->update(['character_graph_status' => 'working', 'character_graph_error' => null]);

        $llm = new LlmService;
        $note = '';

        try {
            if ($llm->isMockConfig()) {
                $graph = $this->mockCharacters($chapters);
            } else {
                try {
                    $graph = $this->llmCharacters($llm, $chapters);
                } catch (\Throwable $e) {
                    $graph = null;
                    $note = '（真实模型调用出错：'.$e->getMessage().'，已用离线启发式生成）';
                }
                // 真实模型大量 429 / 限流 / 未返回有效人物 → 降级到离线启发式，
                // 保证 character_graph_json 一定落库、下次进入可见。
                if (empty($graph) || count($graph['characters'] ?? []) < 2) {
                    $graph = $this->mockCharacters($chapters);
                    $note = $note ?: '（真实模型触发限流 / 未返回有效人物，已用离线启发式生成；额度恢复后在「AI 设置」重填密钥并重新生成即可获得真实人物图）';
                }
            }

            $book->update([
                'character_graph_json' => json_encode($graph, JSON_UNESCAPED_UNICODE),
                'character_graph_status' => 'done',
                'character_graph_at' => now(),
                'character_graph_error' => $note ?: null,
            ]);
        } catch (\Throwable $e) {
            // 连离线启发式都崩 → 才标记失败
            $book->update(['character_graph_status' => 'failed', 'character_graph_error' => $e->getMessage()]);

            return response()->json(['ok' => false, 'msg' => $e->getMessage()]);
        }

        return response()->json(['ok' => true, 'graph' => $graph, 'msg' => $note]);
    }

    /**
     * Real-key path: per-chapter character / relation / event extraction, then merge.
     */
    protected function llmCharacters(LlmService $llm, $chapters): array
    {
        $chars = [];   // norm(name) => {name,faction,desc,chapters:set,count}
        $rels = [];    // key => {from,to,type,desc}
        $events = [];  // key => {time,desc,characters:set}
        $this->genre = 'unknown';
        $total = 0;

        foreach ($chapters as $ch) {
            // 单进程 artisan serve 下逐章同步调 LLM 会阻塞整站；人物关系前 8 章
            // 足够建立主要人物/关系/事件，限制章节数把阻塞控制在数十秒内。
            if ($total >= 8) {
                break;
            }
            $text = mb_substr($ch->source_text, 0, 5000, 'UTF-8');
            $total++;
            $raw = $llm->complete(
                "你是小说人物关系分析师。阅读下面这段书摘，抽取其中出现的人物与关系。\n"
                ."输出 JSON：{\"genre\":\"novel 或 nonfiction\",\"characters\":[{\"name\":\"人物名（合并同一人不同称呼，用最常用名，2-5字）\",\"faction\":\"阵营/势力，可空\",\"desc\":\"一句话介绍\"}],"
                ."\"relations\":[{\"from\":\"人物甲\",\"to\":\"人物乙\",\"type\":\"关系类型（如 亲属/敌对/合作/师徒/爱慕/君臣/朋友）\",\"desc\":\"一句话说明\"}],"
                ."\"events\":[{\"time\":\"时间点或章节（尽量可排序）\",\"desc\":\"关键事件\",\"characters\":[\"相关人物\"]}]}。\n"
                ."只输出 JSON，不要任何解释或代码围栏。最多 16 个人物、24 条关系、10 个事件；非小说也尽量抽真实提及的人物。",
                $text
            );
            $data = $this->parseJson($raw);
            if (! $data) {
                continue;
            }
            if (($data['genre'] ?? '') && $this->genre === 'unknown') {
                $this->genre = $data['genre'];
            }
            $this->mergeChapter($data, $chars, $rels, $events, $ch->idx);
        }

        return $this->finalize($chars, $rels, $events, $this->genre);
    }

    protected function mergeChapter(array $data, array &$chars, array &$rels, array &$events, int $chapterIdx): void
    {
        foreach ($data['characters'] ?? [] as $c) {
            $name = $this->norm($c['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (! isset($chars[$name])) {
                $chars[$name] = ['name' => $c['name'] ?? $name, 'faction' => '', 'desc' => '', 'chapters' => [], 'count' => 0];
            }
            $chars[$name]['count']++;
            if (! in_array($chapterIdx, $chars[$name]['chapters'], true)) {
                $chars[$name]['chapters'][] = $chapterIdx;
            }
            if (($chars[$name]['faction'] === '') && isset($c['faction'])) {
                $chars[$name]['faction'] = trim($c['faction']);
            }
            if (($chars[$name]['desc'] === '') && isset($c['desc'])) {
                $chars[$name]['desc'] = trim($c['desc']);
            }
        }
        foreach ($data['relations'] ?? [] as $r) {
            $f = $this->norm($r['from'] ?? '');
            $t = $this->norm($r['to'] ?? '');
            $type = trim($r['type'] ?? '');
            if ($f === '' || $t === '' || $f === $t) {
                continue;
            }
            if (! isset($chars[$f]) || ! isset($chars[$t])) {
                continue; // 关系两端必须都已识别
            }
            $key = $f.'||'.$t.'||'.$type;
            if (! isset($rels[$key])) {
                $rels[$key] = ['from' => $chars[$f]['name'], 'to' => $chars[$t]['name'], 'type' => $type, 'desc' => ''];
            }
            if ($rels[$key]['desc'] === '' && isset($r['desc'])) {
                $rels[$key]['desc'] = trim($r['desc']);
            }
        }
        foreach ($data['events'] ?? [] as $e) {
            $time = trim($e['time'] ?? '');
            $desc = trim($e['desc'] ?? '');
            if ($desc === '') {
                continue;
            }
            $key = $time.'||'.$desc;
            if (! isset($events[$key])) {
                $events[$key] = ['time' => $time, 'desc' => $desc, 'characters' => []];
            }
            foreach (($e['characters'] ?? []) as $p) {
                if ($p !== '' && ! in_array($p, $events[$key]['characters'], true)) {
                    $events[$key]['characters'][] = $p;
                }
            }
        }
    }

    /**
     * Offline path: keyword-frequency + co-occurrence (same shape as N3's concept
     * fallback). Produces a readable "important-term relationship" graph that
     * demonstrates the UX without any external dependency.
     */
    protected function mockCharacters($chapters): array
    {
        $stop = $this->stopwords();
        $chars = [];
        $rels = [];

        foreach ($chapters as $ch) {
            $text = $ch->source_text;
            $len = mb_strlen($text, 'UTF-8');
            $freq = [];
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
            $top = array_slice(array_keys($freq), 0, 12, true);

            foreach ($top as $w) {
                $k = $this->norm($w);
                if (! isset($chars[$k])) {
                    $chars[$k] = ['name' => $w, 'faction' => '', 'desc' => '', 'chapters' => [], 'count' => 0];
                }
                $chars[$k]['count']++;
                if (! in_array($ch->idx, $chars[$k]['chapters'], true)) {
                    $chars[$k]['chapters'][] = $ch->idx;
                }
            }

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
                        $key = $ka.'||'.$kb.'||共现';
                        if (! isset($rels[$key])) {
                            $rels[$key] = ['from' => $chars[$ka]['name'], 'to' => $chars[$kb]['name'], 'type' => '共现', 'desc' => ''];
                        }
                    }
                }
            }
        }

        return $this->finalize($chars, $rels, [], 'unknown');
    }

    protected function finalize(array $chars, array $rels, array $events, string $genre = 'unknown'): array
    {
        $sorted = collect(array_values($chars))->sortByDesc('count')->values()->all();
        $keep = array_slice($sorted, 0, 60);
        $keepNames = collect($keep)->pluck('name')->flip();

        $keepRels = collect(array_values($rels))
            ->filter(fn ($r) => $keepNames->has($r['from']) && $keepNames->has($r['to']))
            ->slice(0, 120)
            ->values()
            ->all();

        return [
            'genre' => $genre,
            'characters' => array_values($keep),
            'relations' => array_values($keepRels),
            'events' => array_values($events),
        ];
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
            '其中','为了','从而','一些','一直','一样','不会','不能','不得','不少','之上','之下','之中','出来','起来',
            '孩子们','孩子','它们','她们','大家','别人','怎么','怎样','如何','为何','多少','一切','所有','任何','每个',
            '许多','很多','某些','某种','有点','非常','十分','更加','比较','似乎','仿佛','犹如','成为','作为','称为',
            '中医','本书','作者','来说','而言','方面','情况','问题','一种','一种','这些','那些','这个','那个','我们','他们',
            '因为','所以','由于','如果','虽然','但是','然而','于是','并且','或者','而且','之后','之前','时候','现在','这里','那里'];

        return array_flip($list);
    }
}
