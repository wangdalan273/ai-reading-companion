<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeGraph;
use App\Services\RagService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * N12 个人知识库图谱控制器。
 *
 * 图由 RagService::buildKnowledgeGraph 从 rag_chunks 实时聚合（来源无关）。
 * 落库 knowledge_graphs 仅为"下次进入可见"的缓存；降级铁律：构建失败也存
 * 部分图 + error 信息，App 永不假死。
 */
class KnowledgeGraphController extends Controller
{
    protected RagService $rag;

    public function __construct(RagService $rag)
    {
        $this->rag = $rag;
    }

    public function show()
    {
        $row = KnowledgeGraph::where('user_id', Auth::id())->first();

        return view('book-knowledge', [
            'hasCache' => $row && $row->status === 'done' && ! empty($row->graph_json),
            'stats' => $row && $row->graph_json ? ($row->graph_json['stats'] ?? null) : null,
        ]);
    }

    public function fetch()
    {
        $row = KnowledgeGraph::where('user_id', Auth::id())->first();

        if (! $row || $row->status !== 'done' || empty($row->graph_json)) {
            return response()->json(['ok' => false, 'msg' => '尚未生成知识库图谱，点「生成」开始。']);
        }

        return response()->json(['ok' => true, 'graph' => $row->graph_json]);
    }

    public function generate()
    {
        $userId = Auth::id();
        try {
            $graph = $this->rag->buildKnowledgeGraph($userId);
            $row = KnowledgeGraph::updateOrCreate(
                ['user_id' => $userId],
                [
                    'graph_json' => $graph,
                    'status' => 'done',
                    'error' => null,
                    'updated_at' => now(),
                ]
            );

            return response()->json([
                'ok' => true,
                'graph' => $graph,
                'msg' => '已构建全局知识库图谱（离线 BM25 + Obsidian 双链）。',
            ]);
        } catch (\Throwable $e) {
            // 降级：存错误状态，但若有部分图也保留
            KnowledgeGraph::updateOrCreate(
                ['user_id' => $userId],
                [
                    'status' => 'error',
                    'error' => mb_substr($e->getMessage(), 0, 500),
                    'updated_at' => now(),
                ]
            );

            return response()->json(['ok' => false, 'msg' => '构建失败：'.$e->getMessage()]);
        }
    }
}
