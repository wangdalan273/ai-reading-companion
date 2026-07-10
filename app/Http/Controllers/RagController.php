<?php

namespace App\Http\Controllers;

use App\Models\AiConfig;
use App\Models\RagChunk;
use App\Models\UserPrompt;
use App\Services\RagService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 3 — P13 通用 RAG 控制器。
 *
 * 端点：
 *  GET  /rag                  记忆/知识库页（索引状态 + 连接器配置 + 自定义 prompt + 问答）
 *  POST /rag/index            重建索引（书 + vault + 笔记文件夹）
 *  POST /rag/ask              流式带引用问答（SSE）
 *  POST /rag/settings         保存 vault_path / note_folder（按用户）
 *  POST /rag/prompts          新增自定义 prompt
 *  PUT  /rag/prompts/{id}     更新
 *  DELETE /rag/prompts/{id}   删除
 */
class RagController extends Controller
{
    protected RagService $rag;

    public function __construct(RagService $rag)
    {
        $this->rag = $rag;
    }

    public function show()
    {
        $userId = Auth::id();
        $stats = [
            'book' => RagChunk::where('user_id', $userId)->where('source_type', 'book')->count(),
            'obsidian' => RagChunk::where('user_id', $userId)->where('source_type', 'obsidian')->count(),
            'note' => RagChunk::where('user_id', $userId)->where('source_type', 'note')->count(),
        ];
        $cfg = AiConfig::where('user_id', $userId)->first();
        $prompts = UserPrompt::where('user_id', $userId)->orderBy('is_default', 'desc')->get();

        return view('book-rag', [
            'stats' => $stats,
            'vault_path' => $cfg?->vault_path ?: '',
            'note_folder' => $cfg?->note_folder ?: '',
            'prompts' => $prompts,
        ]);
    }

    public function index(Request $request)
    {
        $userId = Auth::id();
        $this->rag->reindexAll($userId);
        $stats = [
            'book' => RagChunk::where('user_id', $userId)->where('source_type', 'book')->count(),
            'obsidian' => RagChunk::where('user_id', $userId)->where('source_type', 'obsidian')->count(),
            'note' => RagChunk::where('user_id', $userId)->where('source_type', 'note')->count(),
        ];

        return response()->json(['ok' => true, 'stats' => $stats]);
    }

    /**
     * 返回 top 检索片段（供前端展示「参考来源」面板，与流式问答并行）。
     */
    public function hits(Request $request)
    {
        $request->validate(['query' => 'required|string|max:2000']);
        $userId = Auth::id();
        $list = $this->rag->search($request->input('query'), $userId, 6);

        return response()->json([
            'ok' => true,
            'hits' => array_map(fn ($h) => [
                'citation' => $this->rag->citation($h['chunk']),
                'snippet' => $h['snippet'],
                'source_type' => $h['chunk']->source_type,
            ], $list),
        ]);
    }

    public function ask(Request $request): StreamedResponse
    {
        $request->validate(['query' => 'required|string|max:2000']);
        $userId = Auth::id();
        $query = $request->input('query');
        $promptId = $request->input('prompt_id') ? (int) $request->input('prompt_id') : null;

        return response()->stream(function () use ($query, $userId, $promptId) {
            echo "retry: 3000\n\n";
            foreach ($this->rag->answer($query, $userId, $promptId) as $token) {
                echo 'data: '.json_encode($token, JSON_UNESCAPED_UNICODE)."\n\n";
                ob_flush();
                flush();
            }
            echo "data: \"[DONE]\"\n\n";
            ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    public function settings(Request $request)
    {
        $userId = Auth::id();
        $request->validate([
            'vault_path' => 'nullable|string|max:2000',
            'note_folder' => 'nullable|string|max:2000',
        ]);
        $vault = $request->input('vault_path') ?: null;
        $note = $request->input('note_folder') ?: null;
        $cfg = AiConfig::firstOrCreate(['user_id' => $userId]);
        $cfg->update([
            'vault_path' => $vault,
            'note_folder' => $note,
        ]);

        // 即时校验路径是否存在，给用户明确反馈（避免「填错路径→重建索引→0 笔记」的困惑）
        $notes = [];
        if ($vault !== null && $vault !== '') {
            $notes[] = is_dir($vault)
                ? 'Obsidian vault 路径有效'
                : '⚠️ vault 路径不存在：'.$vault;
        }
        if ($note !== null && $note !== '') {
            $notes[] = is_dir($note)
                ? '通用笔记文件夹有效'
                : '⚠️ 笔记文件夹不存在：'.$note;
        }
        $noteText = $notes ? implode('；', $notes) : '路径已清空';

        return response()->json(['ok' => true, 'note' => $noteText]);
    }

    public function storePrompt(Request $request)
    {
        $userId = Auth::id();
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'prompt' => 'required|string',
            'is_default' => 'boolean',
        ]);
        if (! empty($data['is_default'])) {
            UserPrompt::where('user_id', $userId)->update(['is_default' => false]);
        }
        $prompt = UserPrompt::create([
            'user_id' => $userId,
            'name' => $data['name'],
            'prompt' => $data['prompt'],
            'is_default' => ! empty($data['is_default']),
        ]);

        return response()->json(['ok' => true, 'id' => $prompt->id]);
    }

    public function updatePrompt(Request $request, UserPrompt $prompt)
    {
        abort_unless($prompt->user_id === Auth::id(), 403);
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'prompt' => 'required|string',
            'is_default' => 'boolean',
        ]);
        if (! empty($data['is_default'])) {
            UserPrompt::where('user_id', $prompt->user_id)->where('id', '<>', $prompt->id)
                ->update(['is_default' => false]);
        }
        $prompt->update($data);

        return response()->json(['ok' => true]);
    }

    public function deletePrompt(UserPrompt $prompt)
    {
        abort_unless($prompt->user_id === Auth::id(), 403);
        $prompt->delete();

        return response()->json(['ok' => true]);
    }
}
