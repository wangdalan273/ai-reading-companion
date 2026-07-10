<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 3 — P13 用户自定义 System Prompt。
 *
 * 优先级高于全局 companion.system_prompt；is_default 标记默认生效的那条。
 */
class UserPrompt extends Model
{
    protected $fillable = [
        'user_id', 'name', 'prompt', 'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
