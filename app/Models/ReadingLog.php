<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ReadingLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'book_id', 'log_date', 'seconds',
    ];

    protected $casts = [
        'log_date' => 'date',
        'seconds' => 'integer',
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
