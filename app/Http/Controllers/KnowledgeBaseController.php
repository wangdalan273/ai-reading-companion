<?php

namespace App\Http\Controllers;

use App\Models\AiConfig;
use App\Models\Annotation;
use App\Models\Book;
use App\Models\KnowledgeGraph;
use App\Models\RagChunk;
use App\Models\UserPrompt;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class KnowledgeBaseController extends Controller
{
    /**
     * 知识库模块总入口：把「图谱 / 记忆检索 / 划线笔记」统一收纳到一个带标签页的页面。
     * 三块内容分别抽成 partials/kb-graph、kb-rag、kb-highlights，本控制器聚合它们所需的数据。
     */
    public function show(Request $request)
    {
        $userId = Auth::id();

        // —— 图谱（N12 知识库图谱）——
        $row = KnowledgeGraph::where('user_id', $userId)->first();
        $hasCache = $row && $row->status === 'done' && ! empty($row->graph_json);
        $graphStats = $row && $row->graph_json ? ($row->graph_json['stats'] ?? null) : null;

        // —— 记忆检索（通用 RAG）——
        $ragStats = [
            'book'     => RagChunk::where('user_id', $userId)->where('source_type', 'book')->count(),
            'obsidian' => RagChunk::where('user_id', $userId)->where('source_type', 'obsidian')->count(),
            'note'     => RagChunk::where('user_id', $userId)->where('source_type', 'note')->count(),
        ];
        $cfg = AiConfig::where('user_id', $userId)->first();
        $prompts = UserPrompt::where('user_id', $userId)->orderBy('is_default', 'desc')->get();

        // —— 划线笔记 ——
        $annotations = Annotation::where('user_id', $userId)
            ->with('book:id,title,format')
            ->orderByDesc('created_at')
            ->get();
        $books = Book::where('user_id', $userId)
            ->withCount(['annotations' => fn ($q) => $q->where('user_id', $userId)])
            ->get();

        // —— 文本笔记（原子卡片浏览器）——
        $notes = $this->fetchNotes($userId);

        return view('knowledge-base', [
            'hasCache'     => $hasCache,
            'graphStats'  => $graphStats,
            'ragStats'    => $ragStats,
            'vault_path'  => $cfg?->vault_path ?: '',
            'note_folder' => $cfg?->note_folder ?: '',
            'prompts'     => $prompts,
            'annotations' => $annotations,
            'books'       => $books,
            'notes'       => $notes,
            'activeTab'   => $request->query('tab', 'graph'),
        ]);
    }

    /**
     * 返回某张原子卡片下的所有 chunk 原文。
     */
    public function chunks(Request $request)
    {
        $userId = Auth::id();
        $type = $request->query('type', 'note');
        $bookId = $request->query('book_id');
        $sourcePath = $request->query('source_path');
        $title = $request->query('title');

        $query = RagChunk::where('user_id', $userId)->where('source_type', $type);
        if ($bookId) {
            $query->where('book_id', (int) $bookId);
        } else {
            $query->whereNull('book_id');
        }
        if ($sourcePath) {
            $query->where('source_path', $sourcePath);
        } else {
            $query->whereNull('source_path');
        }
        if ($title) {
            $query->where('title', $title);
        }

        $chunks = $query->orderBy('chunk_index')->pluck('content')->all();

        return response()->json(['ok' => true, 'chunks' => $chunks]);
    }
    public function notes(Request $request)
    {
        $userId = Auth::id();
        $q = trim($request->query('q', ''));
        $type = $request->query('type', 'all');

        $items = $this->fetchNotes($userId, $q, $type);

        return response()->json(['ok' => true, 'items' => $items]);
    }

    protected function fetchNotes(int $userId, string $q = '', string $type = 'all'): array
    {
        $query = RagChunk::where('user_id', $userId);
        if ($type !== 'all') {
            $query->where('source_type', $type);
        }
        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('title', 'like', "%{$q}%")
                    ->orWhere('content', 'like', "%{$q}%")
                    ->orWhere('source_path', 'like', "%{$q}%");
            });
        }

        $rows = $query
            ->selectRaw('source_type, book_id, source_path, title, MAX(updated_at) as updated_at, COUNT(*) as chunks')
            ->groupBy('source_type', 'book_id', 'source_path', 'title')
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();

        $items = [];
        foreach ($rows as $r) {
            $firstChunk = RagChunk::where('user_id', $userId)
                ->where('source_type', $r->source_type)
                ->when($r->book_id, fn ($q) => $q->where('book_id', $r->book_id))
                ->when($r->source_path, fn ($q) => $q->where('source_path', $r->source_path))
                ->where('title', $r->title)
                ->orderBy('chunk_index')
                ->first();

            $items[] = [
                'type'        => $r->source_type,
                'title'       => $r->title,
                'book_id'     => $r->book_id,
                'source_path' => $r->source_path,
                'updated_at'  => $r->updated_at,
                'chunks'      => $r->chunks,
                'preview'     => mb_substr(preg_replace('/\s+/', ' ', $firstChunk?->content ?: ''), 0, 220),
                'links'       => $firstChunk?->links ?: [],
                'meta'        => $firstChunk?->meta ?: [],
            ];
        }

        return $items;
    }

    /**
     * 删除指定原子卡片下的所有 chunk（仅移除知识库索引，不删原书/原文件）。
     */
    public function deleteNotes(Request $request)
    {
        $userId = Auth::id();
        $type = $request->query('type', 'note');
        $bookId = $request->query('book_id');
        $sourcePath = $request->query('source_path');
        $title = $request->query('title');

        if (! in_array($type, ['book', 'obsidian', 'note', 'companion', 'other'])) {
            return response()->json(['ok' => false, 'msg' => '无效的来源类型'], 422);
        }

        if (! $title) {
            return response()->json(['ok' => false, 'msg' => '缺少标题'], 422);
        }

        $query = RagChunk::where('user_id', $userId)
            ->where('source_type', $type)
            ->where('title', $title);

        if ($bookId) {
            $query->where('book_id', (int) $bookId);
        } else {
            $query->whereNull('book_id');
        }

        if ($sourcePath) {
            $query->where('source_path', $sourcePath);
        } else {
            $query->whereNull('source_path');
        }

        $count = $query->delete();

        return response()->json(['ok' => true, 'deleted' => $count]);
    }

    /**
     * 编辑用户自己创建的知识卡。书籍和 Obsidian 内容由原始来源管理，保持只读。
     */
    public function updateNotes(Request $request)
    {
        $userId = Auth::id();
        $type = $request->query('type', 'note');
        $bookId = $request->query('book_id');
        $sourcePath = $request->query('source_path');
        $title = $request->query('title');

        if (! in_array($type, ['note', 'companion', 'other'], true)) {
            return response()->json(['ok' => false, 'message' => '该内容由原始来源管理，不能在这里编辑'], 422);
        }

        $data = $request->validate([
            'title' => 'required|string|max:200',
            'content' => 'required|string|max:8000',
        ]);

        $query = RagChunk::where('user_id', $userId)
            ->where('source_type', $type)
            ->where('title', $title);
        $bookId ? $query->where('book_id', (int) $bookId) : $query->whereNull('book_id');
        $sourcePath ? $query->where('source_path', $sourcePath) : $query->whereNull('source_path');

        $chunks = $query->orderBy('chunk_index')->get();
        if ($chunks->isEmpty()) {
            return response()->json(['ok' => false, 'message' => '笔记不存在'], 404);
        }

        $first = $chunks->first();
        $first->update([
            'title' => $data['title'],
            'content' => $data['content'],
            'chunk_index' => 0,
        ]);
        if ($chunks->count() > 1) {
            RagChunk::whereIn('id', $chunks->skip(1)->pluck('id'))->delete();
        }
        KnowledgeGraph::where('user_id', $userId)->delete();

        return response()->json(['ok' => true]);
    }
}
