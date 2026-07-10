<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Chapter extends Model
{
    protected $fillable = [
        'user_id', 'book_id', 'idx', 'title',
        'source_text', 'summary', 'status', 'error', 'generated_at',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
