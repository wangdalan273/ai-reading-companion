<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 阶段2 — 伴读跨书对话消息（与 chats 解耦）。
 * 不绑定单一本书，支持 persona_id 与 scope（all/vault/book），
 * 用于「跨书 / 跨笔记」的连续对话。
 */
class CompanionMessage extends Model
{
    protected $fillable = [
        'user_id', 'thread_id', 'persona_id', 'scope', 'book_id', 'role', 'content', 'context',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
