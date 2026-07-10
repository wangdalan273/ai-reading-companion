<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 一本书下的一个独立对话。同一本书可创建多个对话，自由切换。
 * chats.conversation_id 把每条对话内容归属到具体对话。
 */
class Conversation extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $fillable = [
        'user_id', 'book_id', 'title',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function chats()
    {
        return $this->hasMany(Chat::class, 'conversation_id');
    }
}
