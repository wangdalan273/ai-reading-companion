<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 3 — P13 通用 RAG 的检索单元（来源无关）。
 *
 * source_type 枚举：book（已导入书）/ obsidian（vault 笔记）/ note（通用文件夹或粘贴）/ other。
 * embedding 可空：无向量端点时置 null，检索自动降级 BM25，永不假死。
 */
class RagChunk extends Model
{
    protected $fillable = [
        'user_id', 'source_type', 'source_path', 'book_id', 'title',
        'content', 'chunk_index', 'links', 'meta', 'embedding',
    ];

    protected $casts = [
        'links' => 'json',
        'meta' => 'json',
        'embedding' => 'json',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
