<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizQuestion extends Model
{
    protected $fillable = [
        'quiz_id', 'question', 'options_json', 'answer_index', 'explanation', 'source_ref',
    ];

    protected $casts = [
        'options_json' => 'array',
        'answer_index' => 'integer',
    ];

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }
}
