<?php

namespace App\Models;

use App\Models\Chapter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Book extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'title', 'author', 'format', 'path', 'size', 'cover_path',
        'ocr_text', 'has_text_layer', 'mindmap_md', 'text_extracted_at',
        'concept_graph_json', 'concept_graph_status', 'concept_graph_error', 'concept_graph_at',
        'character_graph_json', 'character_graph_status', 'character_graph_error', 'character_graph_at',
        'argument_map_json', 'argument_map_status', 'argument_map_error', 'argument_map_at',
    ];

    protected $casts = [
        'size' => 'integer',
        'has_text_layer' => 'boolean',
        'text_extracted_at' => 'datetime',
        'concept_graph_at' => 'datetime',
        'character_graph_at' => 'datetime',
        'argument_map_at' => 'datetime',
    ];

    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('idx');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(Annotation::class);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class);
    }

    public function exports(): HasMany
    {
        return $this->hasMany(Export::class);
    }

    public function readingLogs(): HasMany
    {
        return $this->hasMany(ReadingLog::class);
    }

    public function flashcards(): HasMany
    {
        return $this->hasMany(Flashcard::class);
    }

    /**
     * Public URL of the extracted cover image, or null when absent.
     * Covers live in public/covers (written at import time by BookTextService).
     */
    public function coverUrl(): ?string
    {
        if (! $this->cover_path) {
            return null;
        }
        $abs = public_path('covers/'.$this->cover_path);
        if (! is_file($abs)) {
            return null;
        }

        return asset('covers/'.$this->cover_path);
    }

    /**
     * Deterministic, pleasing gradient for the placeholder cover so every book
     * gets a distinct look without a real image. Keyed by id for stability.
     */
    public function coverGradient(): string
    {
        $palette = [
            'from-rose-500 to-orange-400',
            'from-sky-500 to-indigo-500',
            'from-emerald-500 to-teal-400',
            'from-violet-500 to-fuchsia-500',
            'from-amber-500 to-pink-500',
            'from-cyan-500 to-blue-500',
            'from-lime-500 to-emerald-500',
            'from-fuchsia-500 to-purple-500',
        ];

        return $palette[($this->id ?? 0) % count($palette)];
    }
}
