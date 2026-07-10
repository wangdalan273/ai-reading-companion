<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Highlights / notes made while reading.
     * loc = EPUB CFI string (or PDF page+char offset in a later phase).
     */
    public function up(): void
    {
        Schema::create('annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('loc');        // EPUB CFI / PDF loc
            $table->text('quote');        // highlighted text
            $table->string('tag')->nullable(); // optional category (forward-compat)
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['book_id', 'created_at']);
            $table->index(['user_id', 'book_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annotations');
    }
};
