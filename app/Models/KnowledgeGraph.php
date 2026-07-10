<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * N12 个人知识库图谱缓存（用户级，不绑书）。
 * 图由 RagService::buildKnowledgeGraph 从 rag_chunks 实时聚合，落库仅为缓存。
 */
class KnowledgeGraph extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'graph_json', 'status', 'error', 'updated_at',
    ];

    protected $casts = [
        'graph_json' => 'json',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
