<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Services\BookTextService;
use App\Services\AnalysisChapterPlanner;
use App\Services\LlmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * N6 — Argument map (claims / evidence / counter-evidence + critical challenge).
 *
 * Non-fiction reading is about *judging*, not *remembering*: what is the author
 * asserting, on what grounds, and have they considered the opposite? We surface
 * that hidden argument structure as an interactive map.
 *
 * Flow mirrors N3/N7: ensure chapters are extracted (BookTextService), then build
 * an argument map from the structured source text. With a real AI key the model
 * extracts claims / evidence / counter-evidence JSON per chapter (DeepA2-style
 * claim–premise–conclusion tagging) and we merge + normalize across chapters;
 * offline (no key) a lightweight assertion/evidence/rebuttal marker heuristic
 * produces a demoable "argument skeleton" so the whole UX works without any
 * external dependency and never soft-locks.
 *
 * Stored on the book as `argument_map_json`:
 *   { genre, claims:[{id,text,chapter,type,challenge}],
 *     evidence:[{id,claim_id,text,type}], counter:[{id,claim_id,text,type}] }
 */
class ArgumentController extends Controller
{
    protected $genre = 'unknown';

    public function show(Book $book)
    {
        abort_unless($book->user_id === auth()->id(), 403);

        return view('book-argument', ['book' => $book]);
    }

    public function fetch(Book $book)
    {
        abort_unless($book->user_id === auth()->id(), 403);

        return response()->json([
            'ok' => true,
            'status' => $book->argument_map_status,
            'map' => $book->argument_map_json ? json_decode($book->argument_map_json, true) : null,
        ]);
    }

    public function generate(Request $request, Book $book)
    {
        abort_unless($book->user_id === auth()->id(), 403);

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

        $book->update(['argument_map_status' => 'working', 'argument_map_error' => null]);

        $llm = new LlmService;
        $note = '';

        try {
            if ($llm->isMockConfig()) {
                $map = $this->mockArguments($chapters);
            } else {
                try {
                    $map = $this->llmArguments($llm, $chapters);
                } catch (\Throwable $e) {
                    $map = null;
                    $note = '（真实模型调用出错：'.$e->getMessage().'，已用离线启发式生成）';
                }
                // 真实模型大量 429 / 限流 / 未返回有效论证 → 降级到离线启发式，
                // 保证 argument_map_json 一定落库、下次进入可见。
                if (empty($map) || count($map['claims'] ?? []) < 2) {
                    $map = $this->mockArguments($chapters);
                    $note = $note ?: '（真实模型触发限流 / 未返回有效论证，已用离线启发式生成；额度恢复后在「AI 设置」重填密钥并重新生成即可获得真实论证地图）';
                }
            }

            // 离线兜底也可能漏掉 challenge —— 统一补齐，保证面板有"批判性质询"。
            $map = $this->ensureChallenges($map);

            $book->update([
                'argument_map_json' => json_encode($map, JSON_UNESCAPED_UNICODE),
                'argument_map_status' => 'done',
                'argument_map_at' => now(),
                'argument_map_error' => $note ?: null,
            ]);
        } catch (\Throwable $e) {
            $book->update(['argument_map_status' => 'failed', 'argument_map_error' => $e->getMessage()]);

            return response()->json(['ok' => false, 'msg' => $e->getMessage()]);
        }

        return response()->json(['ok' => true, 'map' => $map, 'msg' => $note]);
    }

    /**
     * Real-key path: per-chapter claim / evidence / counter extraction, then merge.
     */
    protected function llmArguments(LlmService $llm, $chapters): array
    {
        $claims = [];   // norm(text) => {id,text,chapter,type,challenge,chapters:set,count}
        $evidence = []; // key => {id,claim_id,text,type}
        $counter = [];  // key => {id,claim_id,text,type}
        $this->genre = 'unknown';
        $planned = app(AnalysisChapterPlanner::class)->representative($chapters, 8);
        foreach ($planned as $ch) {
            $text = mb_substr($ch->source_text, 0, 6000, 'UTF-8');
            $raw = $llm->complete(
                "你是论证结构分析师，擅长用批判性思维拆解非虚构文本。阅读下面这段书摘，抽取其中的论证骨架。\n"
                ."输出 JSON：{\"genre\":\"nonfiction 或 novel 或 unknown\",\"claims\":[{\"text\":\"一条主张/论点（合并同一主张的不同表述，用最凝练的一句话，不超过 60 字）\",\"type\":\"主论点 或 分论点\",\"challenge\":\"针对这条主张的一句批判性质询，逼读者思考反例或前提假设（如：这个说法的反面证据可能是什么？它的前提成立吗？）\"}],"
                ."\"evidence\":[{\"claim_text\":\"它支撑的那条主张原文（须与 claims 中某条一致）\",\"text\":\"支撑证据（数据/案例/引用/推理，不超过 80 字）\",\"type\":\"数据 或 案例 或 引用 或 逻辑\"}],"
                ."\"counter\":[{\"claim_text\":\"它反驳/限定的那条主张原文（须与 claims 中某条一致）\",\"text\":\"反驳、反方观点或该主张的局限（不超过 80 字）\",\"type\":\"反驳 或 反方 或 局限\"}]}。\n"
                ."只输出 JSON，不要任何解释或代码围栏。最多 14 条主张、20 条证据、16 条反驳；证据/反驳务必关联到具体主张。",
                $text
            );
            $data = $this->parseJson($raw);
            if (! $data) {
                continue;
            }
            if (($data['genre'] ?? '') && $this->genre === 'unknown') {
                $this->genre = $data['genre'];
            }
            $this->mergeChapter($data, $claims, $evidence, $counter, $ch->idx);
        }

        return $this->finalize($claims, $evidence, $counter, $this->genre);
    }

    protected function mergeChapter(array $data, array &$claims, array &$evidence, array &$counter, int $chapterIdx): void
    {
        // 先建章内 claim 文本 → 全局 key 映射
        $localMap = [];
        foreach ($data['claims'] ?? [] as $c) {
            $text = trim($c['text'] ?? '');
            $key = $this->norm($text);
            if ($key === '') {
                continue;
            }
            $localMap[trim($c['id'] ?? '')] = $key;
            if (! isset($claims[$key])) {
                $claims[$key] = [
                    'id' => $key, 'text' => $text,
                    'type' => trim($c['type'] ?? '分论点'),
                    'challenge' => trim($c['challenge'] ?? ''),
                    'chapters' => [], 'count' => 0,
                ];
            }
            $claims[$key]['count']++;
            if (! in_array($chapterIdx, $claims[$key]['chapters'], true)) {
                $claims[$key]['chapters'][] = $chapterIdx;
            }
        }

        foreach ($data['evidence'] ?? [] as $e) {
            $ct = $this->norm(trim($e['claim_text'] ?? ''));
            // 找不到精确主张时，退而求其次关联到第一条主张
            if (! $ct || ! isset($claims[$ct])) {
                $ct = $localMap[trim($e['claim_text'] ?? '')] ?? (count($claims) ? array_key_first($claims) : '');
            }
            if ($ct === '' || ! isset($claims[$ct])) {
                continue;
            }
            $key = $ct.'||'.md5(trim($e['text'] ?? ''));
            if (! isset($evidence[$key])) {
                $evidence[$key] = [
                    'id' => 'e'.count($evidence),
                    'claim_id' => $ct,
                    'text' => trim($e['text'] ?? ''),
                    'type' => trim($e['type'] ?? '逻辑'),
                ];
            }
        }

        foreach ($data['counter'] ?? [] as $k) {
            $ct = $this->norm(trim($k['claim_text'] ?? ''));
            if (! $ct || ! isset($claims[$ct])) {
                $ct = $localMap[trim($k['claim_text'] ?? '')] ?? (count($claims) ? array_key_first($claims) : '');
            }
            if ($ct === '' || ! isset($claims[$ct])) {
                continue;
            }
            $key = $ct.'||'.md5(trim($k['text'] ?? ''));
            if (! isset($counter[$key])) {
                $counter[$key] = [
                    'id' => 'k'.count($counter),
                    'claim_id' => $ct,
                    'text' => trim($k['text'] ?? ''),
                    'type' => trim($k['type'] ?? '反驳'),
                ];
            }
        }
    }

    /**
     * Offline path: assertion / evidence / rebuttal marker heuristic. Scans each
     * chapter sentence-by-sentence, tags assertive sentences as claims, then folds
     * nearby evidence- and rebuttal-marked sentences under the most recent claim.
     * Produces a demoable argument skeleton without any external dependency.
     */
    protected function mockArguments($chapters): array
    {
        $assertMark = ['我认为','本文主张','总之','可见','应该','必须','本质是','关键在于','换言之','结论是','因此','故','足见','说明','表明','主张','强调','指出','认为','建议','需要','值得','其实','事实上'];
        $evidMark = ['例如','比如','数据显示','据统计','研究表明','据','实验表明','案例','事实证明','调查','报道','显示','证明','样本','比例','百分之','一方面'];
        $counterMark = ['然而','相反','有人认为','但是','但','反驳','局限','不足','另一方面','尽管如此','虽然','可是','不过','反之','退一步','批评','反对','质疑','例外','问题在'];

        $claims = [];
        $evidence = [];
        $counter = [];
        $lastClaimKey = '';
        $nfMark = 0; $novelMark = 0;

        foreach ($chapters as $ch) {
            $sentences = preg_split('/[。！？\n;；]+/u', $ch->source_text);
            foreach ($sentences as $s) {
                $s = trim($s);
                if (mb_strlen($s, 'UTF-8') < 8) {
                    continue;
                }
                $isAssert = $this->hasMark($s, $assertMark);
                $isEvid = $this->hasMark($s, $evidMark);
                $isCounter = $this->hasMark($s, $counterMark);
                if (stripos($s, '研究') !== false || stripos($s, '理论') !== false || stripos($s, '数据') !== false) $nfMark++;
                if (stripos($s, '他说') !== false || stripos($s, '她') !== false || stripos($s, '情节') !== false) $novelMark++;

                if ($isAssert && ! $isEvid && ! $isCounter) {
                    $key = 'c'.count($claims);
                    $claims[$key] = [
                        'id' => $key, 'text' => $s, 'type' => count($claims) === 0 ? '主论点' : '分论点',
                        'challenge' => '', 'chapters' => [$ch->idx], 'count' => 1,
                    ];
                    $lastClaimKey = $key;
                } elseif ($isEvid && $lastClaimKey !== '') {
                    $k = $lastClaimKey.'||'.md5($s);
                    $evidence[$k] = [
                        'id' => 'e'.count($evidence), 'claim_id' => $lastClaimKey,
                        'text' => $s, 'type' => $this->markType($s, $evidMark),
                    ];
                } elseif ($isCounter && $lastClaimKey !== '') {
                    $k = $lastClaimKey.'||'.md5($s);
                    $counter[$k] = [
                        'id' => 'k'.count($counter), 'claim_id' => $lastClaimKey,
                        'text' => $s, 'type' => $this->markType($s, $counterMark),
                    ];
                }
            }
        }

        $genre = 'unknown';
        if ($nfMark > $novelMark * 2) {
            $genre = 'nonfiction';
        } elseif ($novelMark > $nfMark * 2) {
            $genre = 'novel';
        }

        return $this->finalize($claims, $evidence, $counter, $genre);
    }

    protected function markType(string $s, array $marks): string
    {
        foreach ($marks as $m) {
            if (mb_strpos($s, $m, 0, 'UTF-8') !== false) {
                return $m;
            }
        }
        return '其他';
    }

    protected function hasMark(string $s, array $marks): bool
    {
        foreach ($marks as $m) {
            if (mb_strpos($s, $m, 0, 'UTF-8') !== false) {
                return true;
            }
        }
        return false;
    }

    protected function ensureChallenges(array $map): array
    {
        if (! isset($map['claims'])) {
            return $map;
        }
        foreach ($map['claims'] as &$c) {
            if (! isset($c['challenge']) || trim($c['challenge']) === '') {
                $c['challenge'] = '这个说法的反面证据可能是什么？支撑它的前提假设成立吗？有没有被忽略的例外？';
            }
        }
        unset($c);

        return $map;
    }

    protected function finalize(array $claims, array $evidence, array $counter, string $genre = 'unknown'): array
    {
        // 主张按出现频次排序，保留最多的 24 条；证据/反驳只保留挂在保留主张上的
        $sorted = collect(array_values($claims))->sortByDesc('count')->values()->all();
        $keep = array_slice($sorted, 0, 24);
        $keepIds = collect($keep)->pluck('id')->flip();

        $keepEv = collect(array_values($evidence))
            ->filter(fn ($e) => $keepIds->has($e['claim_id']))
            ->slice(0, 50)
            ->values()->all();
        $keepCo = collect(array_values($counter))
            ->filter(fn ($k) => $keepIds->has($k['claim_id']))
            ->slice(0, 40)
            ->values()->all();

        return [
            'genre' => $genre,
            'claims' => array_values($keep),
            'evidence' => array_values($keepEv),
            'counter' => array_values($keepCo),
        ];
    }

    protected function norm(string $s): string
    {
        return trim(preg_replace('/\s+/u', '', $s));
    }

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
}
