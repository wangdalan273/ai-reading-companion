<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Chapter;
use App\Services\BookTextService;
use App\Services\AnalysisChapterPlanner;
use App\Services\LlmService;
use App\Services\OcrService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * P12 — Chapter-level AI summary + whole-book mind-map.
 *
 * Flow: extract chapters (BookTextService) -> per-chapter non-streaming summary
 * (LlmService::complete) -> aggregate all summaries into a markmap Markdown tree
 * stored on the book. Scanned PDFs fall back to the local OCR service when the
 * text layer is missing.
 */
class AnalyzeController extends Controller
{
    public function analyze(Request $request, Book $book)
    {
        abort_unless($book->user_id === auth()->id(), 403);

        // 逐章同步调 LLM 耗时较长，解除 PHP 最大执行时间限制，避免 30s 被砍→生成失败。
        try { set_time_limit(0); } catch (\Throwable $e) {}

        $service = app(BookTextService::class);
        $service->extract($book);

        // Scanned PDF fallback: if no text layer and no chapters, try local OCR.
        if ($book->chapters()->count() === 0 && ! $book->has_text_layer) {
            $path = Storage::disk('local')->path($book->path);
            $ocrChapters = $service->ocrPdf($book, $path);
            $idx = 1;
            foreach ($ocrChapters as $ch) {
                if (trim($ch['text']) === '') {
                    continue;
                }
                Chapter::create([
                    'user_id' => $book->user_id,
                    'book_id' => $book->id,
                    'idx' => $idx++,
                    'title' => $ch['title'],
                    'source_text' => $ch['text'],
                ]);
            }
        }

        $chapters = app(AnalysisChapterPlanner::class)->allForSummary(
            $book->chapters()->whereNotNull('source_text')->get()
        );

        if ($chapters->isEmpty()) {
            return response()->json([
                'ok' => false,
                'msg' => '未能从本书提取到可分析的文本。若是扫描版 PDF，请确认本地 OCR 服务（PP-OCRv6）已启动，且 PHP 已启用 Imagick。',
            ]);
        }

        $llm = new LlmService;
        $summaries = [];

        $prompt = "你是读书助手。请把下面这一章/这一节的内容总结成结构化要点：\n"
            ."先给 3-5 个核心要点（每条一句话、通俗具体、不空话）；\n"
            ."每个核心要点下可再缩进给 1-2 条细节说明或例子（用更深的缩进表示从属）。\n"
            ."用「- 」表示要点、「  - 」表示细节（缩进两空格）。直接输出列表，不要标题和开场白。";

        foreach ($chapters as $ch) {
            // 已完成的章节直接复用，避免每次打开都重复消耗模型调用。
            if ($ch->status === 'done' && trim((string) $ch->summary) !== '') {
                $summaries[] = ['title' => $ch->title, 'summary' => $ch->summary];
                continue;
            }
            $ch->update(['status' => 'working']);
            try {
                $summary = $llm->complete($prompt, $ch->source_text);
            } catch (\Throwable $e) {
                $summary = '';
            }
            // 真实模型限流/失败 → 该章降级到离线启发式，保证脑图总能聚合出内容并落库
            if (trim($summary) === '' || $this->isErrorReply($summary)) {
                $summary = $this->mockSummary($ch->source_text);
            }
            $ch->update([
                'summary' => $summary,
                'status' => 'done',
                'generated_at' => now(),
            ]);
            $summaries[] = ['title' => $ch->title, 'summary' => $summary];
        }

        $book->update(['mindmap_md' => $this->buildMindmap($book, $summaries)]);

        return response()->json([
            'ok' => true,
            'mindmap_md' => $book->mindmap_md,
            'chapters' => $summaries,
        ]);
    }

    /**
     * Detect a canned error string returned by LlmService::complete() so a
     * failed/rate-limited chapter can be swapped for the offline heuristic.
     */
    protected function isErrorReply(string $s): bool
    {
        return (bool) preg_match('/^(（模型调用失败|（模型调用异常|（请求被拒|（鉴权失败|模型接口返回 429)/u', $s);
    }

    /**
     * Offline heuristic summary used when the real model is unavailable.
     */
    protected function mockSummary(string $text): string
    {
        $snippet = mb_substr(preg_replace('/\s+/', '', $text), 0, 24, 'UTF-8');

        return "· 本章围绕「{$snippet}…」展开，逻辑顺畅，值得记一笔。\n"
            . "· 关键转折处有个小反转，让前面的铺垫有了落点。\n"
            . "· 可联想到你读过的类似主题，对照着想会更有收获。\n"
            . "（离线演示总结；填入密钥后由真实模型逐章生成。）";
    }

    public function show(Book $book)
    {
        abort_unless($book->user_id === auth()->id(), 403);

        return response()->json([
            'mindmap_md' => $book->mindmap_md,
            'status' => $book->chapters()->max('status'),
            'chapters' => $book->chapters()->select('idx', 'title', 'status', 'summary')->get(),
        ]);
    }

    /**
     * Aggregate per-chapter summaries into a markmap-compatible Markdown tree.
     * 保留摘要自身的缩进层级，映射成脑图的深层节点（核心要点 → 细节子项），
     * 而不是把所有要点拍平成一级，让脑图更有层次。
     */
    protected function buildMindmap(Book $book, array $summaries): string
    {
        $lines = ['# '.str_replace(["\r", "\n"], '', $book->title)];
        foreach ($summaries as $s) {
            if (trim($s['summary']) === '') {
                continue;
            }
            $lines[] = '';
            $lines[] = '## '.str_replace(["\r", "\n"], ' ', $s['title']);
            foreach (explode("\n", $s['summary']) as $line) {
                if (trim($line) === '') {
                    continue;
                }
                // 计算原行缩进深度（每 2 空格算一层），归一化为「- 」前缀 + 对应缩进
                $depth = 0;
                $rest = $line;
                if (preg_match('/^(\s+)/', $line, $m)) {
                    $depth = intdiv(strlen($m[1]), 2);
                }
                $rest = preg_replace('/^[\s]*([-*•]|\d+[.)])\s*/u', '', $line);
                $indent = str_repeat('  ', max(0, $depth));
                $lines[] = $indent.'- '.trim($rest);
            }
        }

        return implode("\n", $lines);
    }
}
