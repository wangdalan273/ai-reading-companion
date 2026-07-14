<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Carbon\Carbon;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\AiSettingsController;
use App\Http\Controllers\CompanionController;
use App\Http\Controllers\AnalyzeController;
use App\Http\Controllers\GraphController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\ArgumentController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\RagController;
use App\Http\Controllers\KnowledgeGraphController;
use App\Http\Controllers\KnowledgeBaseController;

use App\Models\Book;
use App\Models\Annotation;
use App\Models\Flashcard;
use App\Models\ReadingLog;
use App\Services\ExportService;

/*
 * 移动端 API（阶段 1，命名空间 /v1）
 * ───────────────────────────────────────────────────────────────────────
 * 为什么独立 /v1 而不复用 /api：
 *   - web.php 里已有 /api/companion/*、/api/reading/* 等端点，走 session-cookie
 *     鉴权（电脑端 Livewire/Volt 前端在用）。移动端用 Bearer Token，若路径相同
 *     会先命中 session 端点导致 token 鉴权失败。
 *   - 因此移动端路由统一写在 /v1/* 下；Laravel 为 api.php 自动增加 /api
 *     前缀，所以公网契约为 /api/v1/*，移动端 client 与此保持一致。
 *     由 auth:sanctum 保护，与电脑端零冲突。
 *   - 业务逻辑完全复用现有 Controller（Companion/Analyze/Graph/...），仅换入口与鉴权。
 *
 * 约定：返回纯 JSON（不含页面）；SSE 端点（companion/ask）返回 text/event-stream。
 */

// 书籍响应整形：补充移动端需要的绝对 URL（封面 + 文件流地址）
$mapBook = function (Book $book): array {
    $base = rtrim(config('app.url'), '/');

    return [
        'id'                => $book->id,
        'user_id'           => $book->user_id,
        'title'             => $book->title,
        'author'            => $book->author,
        'format'            => $book->format,
        'size'              => $book->size,
        'cover_path'        => $book->coverUrl(),   // 绝对封面 URL（无则为 null）
        'cover_url'         => $book->coverUrl(),
        'cover_gradient'    => $book->coverGradient(),
        'file_url'          => "{$base}/api/v1/books/{$book->id}/file",
        'mindmap_md'        => $book->mindmap_md,
        'concept_graph_status'  => $book->concept_graph_status,
        'character_graph_status' => $book->character_graph_status,
        'argument_map_status'    => $book->argument_map_status,
        'created_at'        => $book->created_at,
        'updated_at'        => $book->updated_at,
        'deleted_at'        => $book->deleted_at,
    ];
};

// ── 公开端点：登录 / 注册（返回 Bearer token） ──────────────────────────────
Route::post('/v1/login', [AuthController::class, 'login']);
Route::post('/v1/register', [AuthController::class, 'register']);

Route::middleware('auth:sanctum')->group(function () use (
    $mapBook
) {
    // 当前用户 + 登出
    Route::get('/v1/me', [AuthController::class, 'me']);
    Route::post('/v1/logout', [AuthController::class, 'logout']);

    // Account-scoped AI settings are shared by the desktop and mobile clients.
    Route::get('/v1/ai/settings', [AiSettingsController::class, 'show']);
    Route::put('/v1/ai/settings', [AiSettingsController::class, 'update']);
    Route::post('/v1/ai/settings/test', [AiSettingsController::class, 'test']);

    // 增量同步拉取（updated_at 游标；软删墓碑）
    Route::get('/v1/sync', [SyncController::class, 'pull']);

    // ── 书籍 ──────────────────────────────────────────────────────────
    Route::get('/v1/books', function () use ($mapBook) {
        $books = Book::where('user_id', auth()->id())
            ->orderByDesc('updated_at')
            ->get();

        return $books->map($mapBook)->values()->all();
    });

    // 移动端从手机导入书籍（EPUB/PDF 上传），复用本地磁盘存储
    Route::post('/v1/books', function (Request $request) use ($mapBook) {
        $file = $request->file('file');
        if (! $file) {
            // expo-document-picker 可能以 base64 形式传，这里兼容 file 字段
            abort(422, '缺少文件');
        }

        $ext = strtolower($file->getClientOriginalExtension());
        abort_unless(in_array($ext, ['epub', 'pdf']), 422, '仅支持 EPUB / PDF');

        $format = $ext === 'pdf' ? 'pdf' : 'epub';
        $title = $request->input('title') ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $author = $request->input('author');

        $path = Storage::disk('local')->putFile('books', $file);

        $book = Book::create([
            'user_id' => auth()->id(),
            'title'   => $title,
            'author'  => $author,
            'format'  => $format,
            'path'    => $path,
            'size'    => $file->getSize(),
        ]);

        return response()->json($mapBook($book), 201);
    });

    Route::get('/v1/books/{book}', function (Book $book) {
        abort_unless($book->user_id === auth()->id(), 403);

        return response()->json([
            'id'                => $book->id,
            'user_id'           => $book->user_id,
            'title'             => $book->title,
            'author'            => $book->author,
            'format'            => $book->format,
            'size'              => $book->size,
            'cover_path'        => $book->coverUrl(),
            'cover_url'         => $book->coverUrl(),
            'cover_gradient'    => $book->coverGradient(),
            'file_url'          => rtrim(config('app.url'), '/')."/api/v1/books/{$book->id}/file",
            'mindmap_md'        => $book->mindmap_md,
            'concept_graph_status'  => $book->concept_graph_status,
            'character_graph_status' => $book->character_graph_status,
            'argument_map_status'    => $book->argument_map_status,
            'created_at'        => $book->created_at,
            'updated_at'        => $book->updated_at,
            'deleted_at'        => $book->deleted_at,
        ]);
    });

    // 流式返回书籍二进制（EPUB/PDF），移动端下载到本地后由阅读器渲染
    Route::get('/v1/books/{book}/file', function (Book $book) {
        abort_unless($book->user_id === auth()->id(), 403);
        abort_unless(Storage::disk('local')->exists($book->path), 404, '本书文件已丢失，请重新导入');

        $full = Storage::disk('local')->path($book->path);
        $mime = $book->format === 'pdf' ? 'application/pdf' : 'application/epub+zip';

        return response()->file($full, [
            'Content-Type'  => $mime,
            'Content-Disposition' => 'inline; filename="'.basename($full).'"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    });

    // ── 划线（高亮） ───────────────────────────────────────────────────
    Route::get('/v1/books/{book}/annotations', function (Book $book) {
        abort_unless($book->user_id === auth()->id(), 403);

        return response()->json([
            'annotations' => Annotation::where('book_id', $book->id)
                ->where('user_id', auth()->id())
                ->orderBy('loc')
                ->get(['id', 'book_id', 'user_id', 'loc', 'quote', 'tag', 'note', 'created_at', 'updated_at', 'deleted_at']),
        ]);
    });

    Route::post('/v1/books/{book}/annotations', function (Request $request, Book $book) {
        abort_unless($book->user_id === auth()->id(), 403);

        $data = $request->validate([
            'loc'   => 'required|string',
            'quote' => 'required|string|max:8000',
            'tag'   => 'nullable|string|max:60',
            'note'  => 'nullable|string|max:8000',
        ]);

        $ann = Annotation::create([
            'book_id' => $book->id,
            'user_id' => auth()->id(),
            'loc'     => $data['loc'],
            'quote'   => $data['quote'],
            'tag'     => $data['tag'] ?? null,
            'note'    => $data['note'] ?? null,
        ]);

        return response()->json(['ok' => true, 'id' => $ann->id]);
    });

    Route::delete('/v1/books/{book}/annotations/{annotation}', function (Book $book, Annotation $annotation) {
        abort_unless($book->user_id === auth()->id(), 403);
        abort_unless($annotation->user_id === auth()->id() && $annotation->book_id === $book->id, 403);

        $annotation->delete();

        return response()->json(['ok' => true]);
    });

    // ── 闪卡（间隔重复） ───────────────────────────────────────────────
    Route::post('/v1/books/{book}/flashcards', function (Request $request, Book $book) {
        abort_unless($book->user_id === auth()->id(), 403);

        $data = $request->validate([
            'quote'          => 'required|string|max:8000',
            'front'          => 'nullable|string|max:8000',
            'annotation_id'  => 'nullable|exists:annotations,id',
        ]);

        $card = Flashcard::create([
            'user_id'        => auth()->id(),
            'book_id'        => $book->id,
            'annotation_id'  => $data['annotation_id'] ?? null,
            'front'          => $data['front'] ?? $data['quote'],
            'back'           => '《'.$book->title.'》',
            'box'            => 1,
            'due_date'       => Carbon::today(),
        ]);

        return response()->json(['ok' => true, 'id' => $card->id]);
    });

    Route::get('/v1/flashcards/due', function () {
        $cards = Flashcard::where('user_id', auth()->id())
            ->where('due_date', '<=', Carbon::today())
            ->orderBy('box')
            ->orderBy('due_date')
            ->limit(50)
            ->get(['id', 'front', 'back', 'box', 'book_id']);

        $cards->load('book:id,title');

        return response()->json(['cards' => $cards]);
    });

    Route::post('/v1/flashcards/{flashcard}/review', function (Request $request, Flashcard $flashcard) {
        abort_unless($flashcard->user_id === auth()->id(), 403);

        $known = $request->boolean('known');
        $intervals = [1, 2, 4, 7, 14, 30, 60];

        if ($known) {
            $flashcard->box = min($flashcard->box + 1, count($intervals));
            $flashcard->due_date = Carbon::today()->addDays($intervals[$flashcard->box - 1]);
        } else {
            $flashcard->box = 1;
            $flashcard->due_date = Carbon::today()->addDay();
        }
        $flashcard->save();

        return response()->json([
            'ok'       => true,
            'box'      => $flashcard->box,
            'due_date' => $flashcard->due_date->toDateString(),
        ]);
    });

    Route::delete('/v1/flashcards/{flashcard}', function (Flashcard $flashcard) {
        abort_unless($flashcard->user_id === auth()->id(), 403);
        $flashcard->delete();

        return response()->json(['ok' => true]);
    });

    // ── 阅读时长 ───────────────────────────────────────────────────────
    Route::post('/v1/reading/log', function (Request $request) {
        $data = $request->validate([
            'book_id' => 'required|exists:books,id',
            'seconds' => 'required|integer|min:1|max:3600',
        ]);

        $book = Book::find($data['book_id']);
        abort_unless($book->user_id === auth()->id(), 403);

        $log = ReadingLog::firstOrNew([
            'user_id'  => auth()->id(),
            'book_id'  => $book->id,
            'log_date' => Carbon::today(),
        ]);
        $log->seconds += $data['seconds'];
        $log->save();

        return response()->json(['ok' => true, 'total_today' => $log->seconds]);
    });

    Route::get('/v1/reading/stats', function () {
        $userId = auth()->id();
        $logs = ReadingLog::where('user_id', $userId)
            ->where('log_date', '>=', Carbon::today()->subDays(364))
            ->orderBy('log_date')
            ->get(['log_date', 'seconds']);

        $streak = 0;
        $cursor = Carbon::today();
        $datesSet = $logs->pluck('log_date')->map(fn ($d) => $d->toDateString())->flip();
        while ($datesSet->has($cursor->toDateString())) {
            $streak++;
            $cursor = $cursor->subDay();
        }

        $longest = 0;
        $cur = 0;
        $prev = null;
        foreach ($logs as $log) {
            $d = $log->log_date->toDateString();
            if ($prev && Carbon::parse($prev)->diffInDays(Carbon::parse($d)) === 1) {
                $cur++;
            } else {
                $cur = 1;
            }
            $longest = max($longest, $cur);
            $prev = $d;
        }

        $totalSeconds = $logs->sum('seconds');
        $totalBooks = Book::where('user_id', $userId)->count();

        return response()->json([
            'days'           => $logs->map(fn ($l) => ['date' => $l->log_date->toDateString(), 'seconds' => $l->seconds]),
            'streak'         => $streak,
            'longest'        => $longest,
            'total_seconds'  => $totalSeconds,
            'total_minutes'  => round($totalSeconds / 60),
            'total_books'    => $totalBooks,
        ]);
    });

    // ── 导出（Obsidian 友好 Markdown） ─────────────────────────────────
    Route::get('/v1/books/{book}/export/markdown', function (Request $request, Book $book) {
        abort_unless($book->user_id === auth()->id(), 403);

        $md = app(ExportService::class)->toMarkdown($book);
        $filename = $book->id.'-'.preg_replace('/[^\p{L}\p{N}_-]/u', '-', $book->title).'-伴读.md';

        // 移动端无法直接吃附件下载，统一返回预览 JSON
        return response()->json(['ok' => true, 'markdown' => $md, 'filename' => $filename]);
    });

    Route::post('/v1/books/{book}/export/obsidian', function (Book $book) {
        abort_unless($book->user_id === auth()->id(), 403);
        $res = app(ExportService::class)->pushToObsidian($book);

        return response()->json($res);
    });

    // ── 伴读 AI 对话（SSE 流式 + 人格 + 跨书检索） ─────────────────────
    Route::post('/v1/companion/ask', [CompanionController::class, 'ask']);
    Route::get('/v1/companion/personas', [CompanionController::class, 'personasIndex']);
    Route::post('/v1/companion/personas', [CompanionController::class, 'personasStore']);
    Route::put('/v1/companion/personas/{persona}', [CompanionController::class, 'personasUpdate']);
    Route::delete('/v1/companion/personas/{persona}', [CompanionController::class, 'personasDestroy']);
    Route::post('/v1/companion/add-to-kb', [CompanionController::class, 'addToKb']);
    Route::get('/v1/companion/messages', [CompanionController::class, 'companionMessages']);
    Route::post('/v1/companion/define', [CompanionController::class, 'define']);
    Route::get('/v1/companion/history', [CompanionController::class, 'history']);
    Route::get('/v1/book/{book}/conversations', [CompanionController::class, 'listConversations']);
    Route::post('/v1/book/{book}/conversations', [CompanionController::class, 'createConversation']);
    Route::put('/v1/conversations/{conversation}', [CompanionController::class, 'renameConversation']);
    Route::delete('/v1/conversations/{conversation}', [CompanionController::class, 'deleteConversation']);

    // ── 本书 AI 分析（二期核心） ───────────────────────────────────────
    Route::post('/v1/book/{book}/analyze', [AnalyzeController::class, 'analyze']);
    Route::post('/v1/book/{book}/concept-graph', [GraphController::class, 'generate']);
    Route::get('/v1/book/{book}/concept-graph', [GraphController::class, 'fetch']);
    Route::post('/v1/book/{book}/characters', [CharacterController::class, 'generate']);
    Route::get('/v1/book/{book}/characters', [CharacterController::class, 'fetch']);
    Route::post('/v1/book/{book}/argument', [ArgumentController::class, 'generate']);
    Route::get('/v1/book/{book}/argument', [ArgumentController::class, 'fetch']);
    Route::post('/v1/book/{book}/quiz/generate', [QuizController::class, 'generate']);
    Route::get('/v1/book/{book}/quiz/{quiz}', [QuizController::class, 'show']);
    Route::post('/v1/quiz/{quiz}/submit', [QuizController::class, 'submit']);
    Route::get('/v1/book/{book}/quiz/{quiz}/export', [QuizController::class, 'export']);

    // ── RAG 跨书/跨笔记检索 ───────────────────────────────────────────
    Route::post('/v1/rag/index', [RagController::class, 'index']);
    Route::post('/v1/rag/ask', [RagController::class, 'ask']);
    Route::post('/v1/rag/hits', [RagController::class, 'hits']);
    Route::post('/v1/rag/settings', [RagController::class, 'settings']);
    Route::post('/v1/rag/prompts', [RagController::class, 'storePrompt']);
    Route::put('/v1/rag/prompts/{prompt}', [RagController::class, 'updatePrompt']);
    Route::delete('/v1/rag/prompts/{prompt}', [RagController::class, 'deletePrompt']);

    // ── 个人知识库图谱 ─────────────────────────────────────────────────
    Route::get('/v1/knowledge', [KnowledgeGraphController::class, 'fetch']);
    Route::post('/v1/knowledge', [KnowledgeGraphController::class, 'generate']);
    Route::get('/v1/knowledge/notes', [KnowledgeBaseController::class, 'notes']);
    Route::delete('/v1/knowledge/notes', [KnowledgeBaseController::class, 'deleteNotes']);
    Route::get('/v1/knowledge/chunks', [KnowledgeBaseController::class, 'chunks']);
});
