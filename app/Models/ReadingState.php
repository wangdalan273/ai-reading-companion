<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadingState extends Model
{
    protected $fillable = [
        'user_id', 'book_id', 'format', 'locator', 'page', 'total_pages',
        'progress', 'section_title', 'bookmarks', 'client_updated_at',
    ];

    protected $casts = [
        'page' => 'integer',
        'total_pages' => 'integer',
        'progress' => 'float',
        'bookmarks' => 'array',
        'client_updated_at' => 'datetime',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
