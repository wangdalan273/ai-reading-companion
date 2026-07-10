<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Services\ExportService;
use App\Services\LlmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * P15 自动测验：据「选中文字 / 当前章 / 全书」生成选择题，答完给解析。
 * 离线（无密钥）用确定性启发式出题，保证功能永远可用、不假死。
 */
class QuizController extends Controller
{
    /**
     * 生成测验：resolve 文本 → 真模型出 JSON 题库（失败降级离线）→ 落库 → 返回。
     */
    public function generate(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'book_id' => 'required|integer',
            'source_type' => 'required|string|in:selection,chapter,book',
            'text' => 'nullable|string|max:20000',
            'chapter_title' => 'nullable|string|max:200',
        ]);

        $user = $request->user();
        $book = Book::find($data['book_id']);
        abort_unless($book && $book->user_id === $user->id, 403);

        $sourceType = $data['source_type'];
        $chapterTitle = $data['chapter_title'] ?? null;
        $sourceRef = null;

        // 解析要出题的原文
        if ($sourceType === 'selection') {
            $text = trim($data['text'] ?? '');
        } else {
            // chapter / book：从已抽取的 chapters 表里取正文
            $query = Chapter::where('book_id', $book->id)
                ->whereNotNull('source_text')
                ->where('source_text', '<>', '');
            if ($sourceType === 'chapter' && $chapterTitle) {
                $chapter = (clone $query)->where('title', $chapterTitle)->first();
                if (! $chapter) {
                    $chapter = (clone $query)->where('title', 'like', '%'.mb_substr($chapterTitle, 0, 10, 'UTF-8').'%')->first();
                }
            } else {
                // book：随机挑一章有内容的
                $chapter = (clone $query)->whereRaw('LENGTH(source_text) > 120')
                    ->inRandomOrder()
                    ->first();
            }
            if (! $chapter) {
                return response()->json(['ok' => false, 'msg' => '本书还没有可出题的章节文本（先完成章节抽取 / OCR）。'], 422);
            }
            $text = $chapter->source_text;
            $chapterTitle = $chapterTitle ?? $chapter->title;
            $sourceRef = (string) ($chapter->idx ?? '');
        }

        if (mb_strlen($text, 'UTF-8') < 30) {
            return response()->json(['ok' => false, 'msg' => '选中的内容太短，没法出题。请选中更完整的一段（至少一两句话）。'], 422);
        }
        if (mb_strlen($text, 'UTF-8') > 6000) {
            $text = mb_substr($text, 0, 6000, 'UTF-8');
        }

        $svc = new LlmService();
        $questions = $svc->isMockConfig()
            ? $this->mockQuiz($text, $chapterTitle)
            : $this->realQuiz($svc, $text, $chapterTitle);

        if (empty($questions)) {
            $questions = $this->mockQuiz($text, $chapterTitle);
        }

        // 落库
        $quiz = Quiz::create([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'chapter_title' => $chapterTitle,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
            'total' => count($questions),
        ]);
        foreach ($questions as $q) {
            QuizQuestion::create([
                'quiz_id' => $quiz->id,
                'question' => $q['stem'],
                'options_json' => $q['options'],
                'answer_index' => $q['answer'],
                'explanation' => $q['reason'],
                'source_ref' => $sourceRef,
            ]);
        }

        $return = $quiz->questions->map(fn ($q) => [
            'id' => $q->id,
            'stem' => $q->question,
            'options' => $q->options_json,
            'answer' => $q->answer_index,
            'reason' => $q->explanation,
        ])->all();

        return response()->json([
            'ok' => true,
            'quiz_id' => $quiz->id,
            'chapter_title' => $chapterTitle,
            'source_type' => $sourceType,
            'questions' => $return,
        ]);
    }

    /**
     * 查看已保存的测验（重新打开）。
     */
    public function show(Request $request, Quiz $quiz): \Illuminate\Http\JsonResponse
    {
        abort_unless($quiz->user_id === Auth::id(), 403);

        return response()->json([
            'ok' => true,
            'quiz_id' => $quiz->id,
            'chapter_title' => $quiz->chapter_title,
            'source_type' => $quiz->source_type,
            'questions' => $quiz->questions->map(fn ($q) => [
                'stem' => $q->question,
                'options' => $q->options_json,
                'answer' => $q->answer_index,
                'reason' => $q->explanation,
            ])->all(),
        ]);
    }

    /**
     * 提交答案：判分 + 逐题解析。
     */
    public function submit(Request $request, Quiz $quiz): \Illuminate\Http\JsonResponse
    {
        abort_unless($quiz->user_id === Auth::id(), 403);

        $data = $request->validate([
            'answers' => 'required|array',
            'answers.*' => 'nullable|integer|min:0|max:3',
        ]);

        $results = [];
        $correct = 0;
        foreach ($quiz->questions as $q) {
            $chosen = $data['answers'][(string) $q->id] ?? null;
            $isRight = $chosen !== null && (int) $chosen === (int) $q->answer_index;
            if ($isRight) {
                $correct++;
            }
            $results[] = [
                'question_id' => $q->id,
                'stem' => $q->question,
                'options' => $q->options_json,
                'chosen' => $chosen,
                'answer' => $q->answer_index,
                'correct' => $isRight,
                'explanation' => $q->explanation,
            ];
        }

        return response()->json([
            'ok' => true,
            'score' => $correct,
            'total' => $quiz->total,
            'results' => $results,
        ]);
    }

    /**
     * 导出为 Obsidian 友好的 Markdown（frontmatter + callout + [[双链]]）。
     * 若配置了 vault 路径则直接写入 vault，否则返回下载。
     */
    public function export(Request $request, Book $book, Quiz $quiz): \Illuminate\Http\Response
    {
        abort_unless($book->user_id === Auth::id(), 403);
        abort_unless($quiz->user_id === Auth::id() && $quiz->book_id === $book->id, 403);

        $svc = new ExportService();
        $md = $svc->toQuizMarkdown($quiz);
        $vault = $svc->vaultPathFor(Auth::id());

        if (! empty($vault) && is_dir($vault) && is_writable($vault)) {
            $book = $quiz->book;
            $name = ($book ? $svc->safeFilename($book->title) : 'quiz').'-测验'.($quiz->id).'.md';
            $target = rtrim($vault, '/').'/'.$name;
            $written = @file_put_contents($target, $md);
            if ($written !== false) {
                return response()->json(['ok' => true, 'path' => $target, 'pushed' => true]);
            }
        }

        $book = $quiz->book;
        $filename = ($book ? $svc->safeFilename($book->title) : 'quiz').'-测验'.($quiz->id).'.md';

        return response($md)
            ->header('Content-Type', 'text/markdown; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }

    // ---- 真实模型出题（带 JSON 解析 + 降级） ----

    protected function realQuiz(LlmService $svc, string $text, ?string $chapterTitle): array
    {
        $scope = $chapterTitle ? '（章节：'.$chapterTitle.'）' : '';
        $prompt = "你是一位严谨的阅读理解出题人。请基于下面的原文{$scope}，出 3–5 道单选题（中文），"
            ."用于检验读者是否真正读懂。\n要求：\n"
            ."· 每题 4 个选项，只有一个正确；\n"
            ."· 正确项必须能在原文找到依据，干扰项要像真有道理但不正确；\n"
            ."· 每题附 explanation 说明为什么选该项、并引用原文依据；\n"
            ."· 只输出一个 JSON 数组，不要任何额外解释文字或代码块标记，格式严格如下：\n"
            ."[{\"stem\":\"题目文字\",\"options\":[\"A\",\"B\",\"C\",\"D\"],\"answer\":0,\"reason\":\"解析\"}, ...]\n\n"
            ."原文：\n\"\"\"\n{$text}\n\"\"\"";

        $raw = $svc->complete($prompt, '');
        $parsed = $this->parseQuizJson($raw);
        if (! empty($parsed)) {
            return $parsed;
        }

        // 解析失败（模型乱说 / 限流返回错误文案）→ 降级离线
        return $this->mockQuiz($text, $chapterTitle);
    }

    /**
     * 从模型返回里稳健地抠出 JSON 数组（容忍 ```json 围栏、前后废话）。
     */
    protected function parseQuizJson(string $raw): array
    {
        $raw = trim($raw);
        // 去 ```json ... ``` 围栏
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/i', '', $raw);

        $start = mb_strpos($raw, '[');
        $end = mb_strrpos($raw, ']');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }
        $json = mb_substr($raw, $start, $end - $start + 1);
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }
            $stem = trim((string) ($item['stem'] ?? ''));
            $options = $item['options'] ?? null;
            $answer = $item['answer'] ?? null;
            if ($stem === '' || ! is_array($options) || count($options) < 2 || ! is_int($answer) && ! is_numeric($answer)) {
                continue;
            }
            $out[] = [
                'stem' => $stem,
                'options' => array_values(array_map('strval', $options)),
                'answer' => (int) $answer,
                'reason' => trim((string) ($item['reason'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * 离线出题：从原文抽句子做「理解正误」选择题，确定、可读、零依赖。
     */
    protected function mockQuiz(string $text, ?string $chapterTitle): array
    {
        // 简单按句号/问号/感叹号切句（中文友好）
        $sentences = preg_split('/(?<=[。！？\.\?!])\s*/u', $text);
        $sentences = array_values(array_filter(array_map(fn ($s) => trim($s), $sentences ?? []), fn ($s) => mb_strlen($s, 'UTF-8') >= 12));

        if (empty($sentences)) {
            $sentences = [mb_substr($text, 0, 80, 'UTF-8')];
        }

        $questions = [];
        $limit = min(4, count($sentences));
        for ($i = 0; $i < $limit; $i++) {
            $s = $sentences[$i];
            $snippet = mb_substr($s, 0, 28, 'UTF-8');
            $questions[] = [
                'stem' => '根据原文：「'.($snippet.(mb_strlen($s, 'UTF-8') > 28 ? '…' : '')).'」，以下理解正确的是？',
                'options' => [
                    '如原文所述，这是作者要表达的核心意思之一',
                    '作者明确否定了这一点',
                    '原文并未涉及此内容',
                    '这是读者自己的发挥，原文无据',
                ],
                'answer' => 0,
                'reason' => '原文写道：「'.$s.'」，故第一项理解正确；其余各项与原文不符或原文无据。'
                    .($chapterTitle ? '（出自章节：'.$chapterTitle.'）' : ''),
            ];
        }

        return $questions;
    }
}
