<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI conversation turns tied to a book (the "ask AI" side panel).
     */
    public function up(): void
    {
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');   // user | assistant
            $table->text('content');
            $table->text('context')->nullable(); // selected quote / chapter context
            $table->timestamps();

            $table->index(['book_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chats');
    }
};
