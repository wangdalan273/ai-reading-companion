<?php

use App\Http\Controllers\CompanionController;
use App\Models\Annotation;
use App\Models\Book;
use App\Models\Flashcard;
use App\Models\ReadingLog;
use App\Services\ExportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

// In-app AI provider settings (key stored encrypted server-side, never to browser).
Route::view('settings/ai', 'settings')
    ->middleware(['auth'])
    ->name('settings.ai');

// Reader page (EPUB rendered client-side via epub.js; PDF shows a placeholder)
Route::get('/read/{book}', function (Book $book) {
    abort_unless($book->user_id === auth()->id(), 403);

    return view('read', ['book' => $book]);
})->middleware(['auth'])->name('read');

// Stream the book file to the browser (same-origin fetch for epub.js)
Route::get('/book/{book}/file', function (Book $book) {
    abort_unless($book->user_id === auth()->id(), 403);
    abort_unless(Storage::disk('local')->exists($book->path), 404, '本书文件已丢失，请重新导入');

    // Serve the book binary with the correct MIME per format, so epub.js can
    // open EPUBs and browsers can inline-render PDFs (而非触发下载)。
    $full = Storage::disk('local')->path($book->path);
    $mime = $book->format === 'pdf' ? 'application/pdf' : 'application/epub+zip';

    return response()->file($full, [
        'Content-Type' => $mime,
        'Content-Disposition' => 'inline; filename="'.basename($full).'"',
        'Cache-Control' => 'private, max-age=300',
    ]);
})->middleware(['auth'])->name('book.file');

// Annotations (highlights) for a book — persisted as an EPUB CFI + quoted text.
Route::get('/book/{book}/annotations', function (Book $book) {
    abort_unless($book->user_id === auth()->id(), 403);

    return response()->json([
        'annotations' => Annotation::where('book_id', $book->id)
            ->where('user_id', auth()->id())
            ->get(['id', 'loc', 'quote']),
    ]);
})->middleware(['auth'])->name('book.annotations.index');

Route::post('/book/{book}/annotations', function (Request $request, Book $book) {
    abort_unless($book->user_id === auth()->id(), 403);

    $data = $request->validate([
        'loc' => 'required|string',
        'quote' => 'required|string|max:8000',
    ]);

    $ann = Annotation::create([
        'book_id' => $book->id,
        'user_id' => auth()->id(),
        'loc' => $data['loc'],
        'quote' => $data['quote'],
    ]);

    return response()->json(['ok' => true, 'id' => $ann->id]);
})->middleware(['auth'])->name('book.annotations.store');

Route::delete('/book/{book}/annotations/{annotation}', function (Book $book, Annotation $annotation) {
    abort_unless($book->user_id === auth()->id(), 403);
    abort_unless($annotation->user_id === auth()->id() && $annotation->book_id === $book->id, 403);

    $annotation->delete();

    return response()->json(['ok' => true]);
})->middleware(['auth'])->name('book.annotations.destroy');

Route::get('/book/{book}/annotations/{annotation}', function (Book $book, Annotation $annotation) {
    abort_unless($book->user_id === auth()->id(), 403);
    abort_unless($annotation->user_id === auth()->id() && $annotation->book_id === $book->id, 403);

    return response()->json([
        'id' => $annotation->id,
        'quote' => $annotation->quote,
        'note' => $annotation->note,
    ]);
})->middleware(['auth'])->name('book.annotations.show');

// Streaming "ask the AI" (SSE). Key stays server-side; browser only gets tokens.
Route::post('/api/companion/ask', [CompanionController::class, 'ask'])
    ->middleware(['auth'])
    ->name('companion.ask');

// 阶段2 — 伴读（跨书/跨笔记）独立模块 + 人格库
Route::view('companion', 'companion')
    ->middleware(['auth'])
    ->name('companion');

Route::view('companion/personas', 'companion-personas')
    ->middleware(['auth'])
    ->name('companion.personas');

Route::get('/api/companion/personas', [CompanionController::class, 'personasIndex'])
    ->middleware(['auth'])
    ->name('companion.personas.list');

Route::post('/api/companion/personas', [CompanionController::class, 'personasStore'])
    ->middleware(['auth'])
    ->name('companion.personas.store');

Route::put('/api/companion/personas/{persona}', [CompanionController::class, 'personasUpdate'])
    ->middleware(['auth'])
    ->name('companion.personas.update');

Route::delete('/api/companion/personas/{persona}', [CompanionController::class, 'personasDestroy'])
    ->middleware(['auth'])
    ->name('companion.personas.destroy');

Route::post('/api/companion/add-to-kb', [CompanionController::class, 'addToKb'])
    ->middleware(['auth'])
    ->name('companion.addToKb');

Route::get('/api/companion/messages', [CompanionController::class, 'companionMessages'])
    ->middleware(['auth'])
    ->name('companion.messages');

// N5 术语悬停：选中词 → 返回结合语境的通俗解释（非流式，key 不出服务端）
Route::post('/api/companion/define', [CompanionController::class, 'define'])
    ->middleware(['auth'])
    ->name('companion.define');

// 拉取某本书的历史 AI 对话（重新打开书时回显面板）
Route::get('/api/companion/history', [CompanionController::class, 'history'])
    ->middleware(['auth'])
    ->name('companion.history');

// 多对话：同一本书下创建 / 列出 / 重命名 / 删除独立对话
Route::get('/api/book/{book}/conversations', [CompanionController::class, 'listConversations'])
    ->middleware(['auth'])->name('book.conversations.index');
Route::post('/api/book/{book}/conversations', [CompanionController::class, 'createConversation'])
    ->middleware(['auth'])->name('book.conversations.store');
Route::put('/api/conversations/{conversation}', [CompanionController::class, 'renameConversation'])
    ->middleware(['auth'])->name('conversations.update');
Route::delete('/api/conversations/{conversation}', [CompanionController::class, 'deleteConversation'])
    ->middleware(['auth'])->name('conversations.destroy');

// 集中查看已划线内容（跨书，按书分组）
Route::get('/highlights', function () {
    $userId = auth()->id();
    $annotations = Annotation::where('user_id', $userId)
        ->with('book:id,title,format')
        ->orderByDesc('created_at')
        ->get();
    $books = Book::where('user_id', $userId)
        ->withCount(['annotations' => fn ($q) => $q->where('user_id', $userId)])
        ->get();

    return view('highlights', ['annotations' => $annotations, 'books' => $books]);
})->middleware(['auth'])->name('highlights');

// Export a book's highlights + AI notes as an Obsidian-friendly Markdown file.
// 加 ?preview=1 时返回 JSON（供「预览后下载」页面渲染），否则直接下载附件。
Route::get('/book/{book}/export/markdown', function (Request $request, Book $book) {
    abort_unless($book->user_id === auth()->id(), 403);

    $md = app(ExportService::class)->toMarkdown($book);
    $filename = $book->id.'-'.preg_replace('/[^\p{L}\p{N}_-]/u', '-', $book->title).'-伴读.md';

    if ($request->query('preview') || $request->wantsJson()) {
        return response()->json(['ok' => true, 'markdown' => $md, 'filename' => $filename]);
    }

    return response($md, 200, [
        'Content-Type' => 'text/markdown; charset=utf-8',
        'Content-Disposition' => 'attachment; filename="'.$filename.'"',
    ]);
})->middleware(['auth'])->name('book.export.markdown');

// Export a book's AI conversation log as a standalone Obsidian-friendly Markdown file.
Route::get('/book/{book}/export/conversation', function (Request $request, Book $book) {
    abort_unless($book->user_id === auth()->id(), 403);

    $md = app(ExportService::class)->toConversationMarkdown($book);
    $filename = $book->id.'-'.preg_replace('/[^\p{L}\p{N}_-]/u', '-', $book->title).'-对话.md';

    if ($request->query('preview') || $request->wantsJson()) {
        return response()->json(['ok' => true, 'markdown' => $md, 'filename' => $filename]);
    }

    return response($md, 200, [
        'Content-Type' => 'text/markdown; charset=utf-8',
        'Content-Disposition' => 'attachment; filename="'.$filename.'"',
    ]);
})->middleware(['auth'])->name('book.export.conversation');

// 「预览后下载」页面：把 MD 渲染出来，用户确认后再下载 / 写入 Obsidian。
Route::get('/book/{book}/export/preview', function (Book $book) {
    abort_unless($book->user_id === auth()->id(), 403);
    $type = request('type', 'markdown');

    return view('book-export-preview', ['book' => $book, 'type' => $type]);
})->middleware(['auth'])->name('book.export.preview');

// 把本书划线/笔记写入已配置的 Obsidian vault（返回 JSON 供前端提示）。
Route::post('/book/{book}/export/obsidian', function (Book $book) {
    abort_unless($book->user_id === auth()->id(), 403);
    $res = app(ExportService::class)->pushToObsidian($book);

    return response()->json($res);
})->middleware(['auth'])->name('book.export.obsidian');

// ===== 阅读时长追踪 =====
// Heartbeat: reader.js 每 30 秒上报一次本会话累计的有效阅读秒数。
Route::post('/api/reading/log', function (Request $request) {
    $data = $request->validate([
        'book_id' => 'required|exists:books,id',
        'seconds' => 'required|integer|min:1|max:3600',
    ]);

    $book = Book::find($data['book_id']);
    abort_unless($book->user_id === auth()->id(), 403);

    $today = Carbon::today();
    $log = ReadingLog::firstOrNew([
        'user_id' => auth()->id(),
        'book_id' => $book->id,
        'log_date' => $today,
    ]);
    $log->seconds += $data['seconds'];
    $log->save();

    return response()->json(['ok' => true, 'total_today' => $log->seconds]);
})->middleware(['auth'])->name('reading.log');

// Stats: 返回近 365 天的阅读秒数 + 当前/最长连读天数。
Route::get('/api/reading/stats', function () {
    $userId = auth()->id();
    $logs = ReadingLog::where('user_id', $userId)
        ->where('log_date', '>=', Carbon::today()->subDays(364))
        ->orderBy('log_date')
        ->get(['log_date', 'seconds']);

    // 当前连读
    $streak = 0;
    $cursor = Carbon::today();
    $datesSet = $logs->pluck('log_date')->map(fn ($d) => $d->toDateString())->flip();
    while ($datesSet->has($cursor->toDateString())) {
        $streak++;
        $cursor = $cursor->subDay();
    }

    // 最长连读
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

    return response()->json([
        'days' => $logs->map(fn ($l) => [
            'date' => $l->log_date->toDateString(),
            'seconds' => $l->seconds,
        ]),
        'streak' => $streak,
        'longest' => $longest,
        'total_seconds' => $totalSeconds,
        'total_minutes' => round($totalSeconds / 60),
    ]);
})->middleware(['auth'])->name('reading.stats');

// 阅读统计页面
Route::view('stats', 'stats')->middleware(['auth'])->name('stats');

// ===== 闪卡系统 =====
// 从划线创建闪卡
Route::post('/book/{book}/flashcards', function (Request $request, Book $book) {
    abort_unless($book->user_id === auth()->id(), 403);

    $data = $request->validate([
        'quote' => 'required|string|max:8000',
        'front' => 'nullable|string|max:8000',
        'annotation_id' => 'nullable|exists:annotations,id',
    ]);

    $card = Flashcard::create([
        'user_id' => auth()->id(),
        'book_id' => $book->id,
        'annotation_id' => $data['annotation_id'] ?? null,
        'front' => $data['front'] ?? $data['quote'],
        'back' => '《'.$book->title.'》',
        'box' => 1,
        'due_date' => Carbon::today(),
    ]);

    return response()->json(['ok' => true, 'id' => $card->id]);
})->middleware(['auth'])->name('book.flashcards.store');

// 闪卡复习页面
Route::view('flashcards', 'flashcards')->middleware(['auth'])->name('flashcards');

// 获取到期闪卡列表
Route::get('/api/flashcards/due', function () {
    $cards = Flashcard::where('user_id', auth()->id())
        ->where('due_date', '<=', Carbon::today())
        ->orderBy('box')
        ->orderBy('due_date')
        ->limit(50)
        ->get(['id', 'front', 'back', 'box', 'book_id']);

    $cards->load('book:id,title');

    return response()->json(['cards' => $cards]);
})->middleware(['auth'])->name('flashcards.due');

// 复习一张闪卡（认识→升盒+延长到期；不认识→降回第 1 盒+明天到期）
Route::post('/api/flashcards/{flashcard}/review', function (Flashcard $flashcard, Request $request) {
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

    return response()->json(['ok' => true, 'box' => $flashcard->box, 'due_date' => $flashcard->due_date->toDateString()]);
})->middleware(['auth'])->name('flashcards.review');

// 删除闪卡
Route::delete('/api/flashcards/{flashcard}', function (Flashcard $flashcard) {
    abort_unless($flashcard->user_id === auth()->id(), 403);
    $flashcard->delete();
    return response()->json(['ok' => true]);
})->middleware(['auth'])->name('flashcards.destroy');

// ===== P12 章节总结 + 思维导图 =====
Route::post('/api/book/{book}/analyze', [App\Http\Controllers\AnalyzeController::class, 'analyze'])
    ->middleware(['auth'])->name('book.analyze.generate');

Route::get('/book/{book}/mindmap', function (Book $book) {
    abort_unless($book->user_id === auth()->id(), 403);

    return view('book-mindmap', ['book' => $book]);
})->middleware(['auth'])->name('book.mindmap');

// ===== N3 concept / knowledge graph =====
Route::post('/api/book/{book}/concept-graph', [App\Http\Controllers\GraphController::class, 'generate'])
    ->middleware(['auth'])->name('book.graph.generate');

Route::get('/api/book/{book}/concept-graph', [App\Http\Controllers\GraphController::class, 'fetch'])
    ->middleware(['auth'])->name('book.graph.fetch');

Route::get('/book/{book}/graph', function (Book $book) {
    abort_unless($book->user_id === auth()->id(), 403);

    return view('book-graph', ['book' => $book]);
})->middleware(['auth'])->name('book.graph');

// ===== N7 人物关系图 + 时间线 =====
Route::post('/api/book/{book}/characters', [App\Http\Controllers\CharacterController::class, 'generate'])
    ->middleware(['auth'])->name('book.characters.generate');

Route::get('/api/book/{book}/characters', [App\Http\Controllers\CharacterController::class, 'fetch'])
    ->middleware(['auth'])->name('book.characters.fetch');

Route::get('/book/{book}/characters', function (Book $book) {
    abort_unless($book->user_id === auth()->id(), 403);

    return view('book-characters', ['book' => $book]);
})->middleware(['auth'])->name('book.characters');

// ===== N6 论证地图（主张-证据-反驳 + 批判性质询） =====
Route::post('/api/book/{book}/argument', [App\Http\Controllers\ArgumentController::class, 'generate'])
    ->middleware(['auth'])->name('book.argument.generate');

Route::get('/api/book/{book}/argument', [App\Http\Controllers\ArgumentController::class, 'fetch'])
    ->middleware(['auth'])->name('book.argument.fetch');

Route::get('/book/{book}/argument', function (Book $book) {
    abort_unless($book->user_id === auth()->id(), 403);

    return view('book-argument', ['book' => $book]);
})->middleware(['auth'])->name('book.argument');

// ===== Phase 3 · P13 通用 RAG（跨书/跨笔记记忆 + Obsidian 头等连接器） =====
Route::get('/rag', [App\Http\Controllers\RagController::class, 'show'])
    ->middleware(['auth'])->name('rag');

Route::post('/rag/index', [App\Http\Controllers\RagController::class, 'index'])
    ->middleware(['auth'])->name('rag.index');

Route::post('/rag/ask', [App\Http\Controllers\RagController::class, 'ask'])
    ->middleware(['auth'])->name('rag.ask');

Route::post('/rag/hits', [App\Http\Controllers\RagController::class, 'hits'])
    ->middleware(['auth'])->name('rag.hits');

Route::post('/rag/settings', [App\Http\Controllers\RagController::class, 'settings'])
    ->middleware(['auth'])->name('rag.settings');

Route::post('/rag/prompts', [App\Http\Controllers\RagController::class, 'storePrompt'])
    ->middleware(['auth'])->name('rag.prompts.store');

Route::put('/rag/prompts/{prompt}', [App\Http\Controllers\RagController::class, 'updatePrompt'])
    ->middleware(['auth'])->name('rag.prompts.update');

Route::delete('/rag/prompts/{prompt}', [App\Http\Controllers\RagController::class, 'deletePrompt'])
    ->middleware(['auth'])->name('rag.prompts.delete');

// ===== Phase 3 · N12 个人知识库图谱（来源无关，Obsidian 双链优先） =====
Route::get('/knowledge', [App\Http\Controllers\KnowledgeGraphController::class, 'show'])
    ->middleware(['auth'])->name('knowledge');

Route::get('/api/knowledge', [App\Http\Controllers\KnowledgeGraphController::class, 'fetch'])
    ->middleware(['auth'])->name('knowledge.fetch');

Route::post('/api/knowledge', [App\Http\Controllers\KnowledgeGraphController::class, 'generate'])
    ->middleware(['auth'])->name('knowledge.generate');

Route::get('/api/knowledge/notes', [App\Http\Controllers\KnowledgeBaseController::class, 'notes'])
    ->middleware(['auth'])->name('knowledge.notes');

Route::delete('/api/knowledge/notes', [App\Http\Controllers\KnowledgeBaseController::class, 'deleteNotes'])
    ->middleware(['auth'])->name('knowledge.notes.delete');

Route::get('/api/knowledge/chunks', [App\Http\Controllers\KnowledgeBaseController::class, 'chunks'])
    ->middleware(['auth'])->name('knowledge.chunks');

// ===== 知识库模块总入口：图谱 / 记忆检索 / 划线笔记 统一标签页 =====
Route::get('/knowledge-base', [App\Http\Controllers\KnowledgeBaseController::class, 'show'])
    ->middleware(['auth'])->name('knowledge-base');

// ===== 统一功能入口：本书 AI 工具中心（把图谱/人物/论证/脑图/测验/知识库集中关联） =====
Route::get('/book/{book}/tools', function (Book $book) {
    abort_unless($book->user_id === auth()->id(), 403);

    $stateOf = function ($json, $statusCol) use ($book) {
        $status = $book->{$statusCol};
        if ($status === 'error') return 'error';
        if ($status === 'pending') return 'pending';
        if (! empty($book->{$json})) return 'done';
        return 'none';
    };

    $status = [
        'concept_graph'  => $stateOf('concept_graph_json', 'concept_graph_status'),
        'character_graph' => $stateOf('character_graph_json', 'character_graph_status'),
        'argument_map'   => $stateOf('argument_map_json', 'argument_map_status'),
        'mindmap'        => $book->mindmap_md ? 'done' : 'none',
        'highlights'     => Annotation::where('book_id', $book->id)->where('user_id', auth()->id())->count(),
    ];
    $kgCount = \App\Models\KnowledgeGraph::where('user_id', auth()->id())->count();

    return view('book-tools', ['book' => $book, 'status' => $status, 'kgCount' => $kgCount]);
})->middleware(['auth'])->name('book.tools');

// ===== 统一「本书分析」父页面：概念图谱/人物关系/论证地图/思维导图 作为 4 个子视图 =====
Route::get('/book/{book}/analyze', function (Book $book) {
    abort_unless($book->user_id === auth()->id(), 403);

    $tabs = ['graph', 'characters', 'argument', 'mindmap'];
    $activeTab = request()->query('tab');
    if (! in_array($activeTab, $tabs, true)) {
        $activeTab = 'graph';
    }

    $stateOf = function ($json, $statusCol) use ($book) {
        $status = $book->{$statusCol};
        if ($status === 'error') return 'error';
        if ($status === 'pending') return 'pending';
        if (! empty($book->{$json})) return 'done';
        return 'none';
    };

    $status = [
        'concept_graph'  => $stateOf('concept_graph_json', 'concept_graph_status'),
        'character_graph' => $stateOf('character_graph_json', 'character_graph_status'),
        'argument_map'   => $stateOf('argument_map_json', 'argument_map_status'),
        'mindmap'        => $book->mindmap_md ? 'done' : 'none',
    ];

    return view('book-analyze', ['book' => $book, 'activeTab' => $activeTab, 'status' => $status]);
})->middleware(['auth'])->name('book.analyze');

// ===== Phase 3 · P15 苏格拉底 + 自动测验 =====
Route::post('/book/{book}/quiz/generate', [App\Http\Controllers\QuizController::class, 'generate'])
    ->middleware(['auth'])->name('book.quiz.generate');

Route::get('/book/{book}/quiz/{quiz}', [App\Http\Controllers\QuizController::class, 'show'])
    ->middleware(['auth'])->name('book.quiz.show');

Route::post('/quiz/{quiz}/submit', [App\Http\Controllers\QuizController::class, 'submit'])
    ->middleware(['auth'])->name('book.quiz.submit');

Route::get('/book/{book}/quiz/{quiz}/export', [App\Http\Controllers\QuizController::class, 'export'])
    ->middleware(['auth'])->name('book.quiz.export');

require __DIR__.'/auth.php';
