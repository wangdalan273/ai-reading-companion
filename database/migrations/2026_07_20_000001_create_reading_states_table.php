<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->string('format', 12);
            $table->text('locator')->nullable();
            $table->unsignedInteger('page')->nullable();
            $table->unsignedInteger('total_pages')->nullable();
            $table->decimal('progress', 8, 7)->default(0);
            $table->string('section_title')->nullable();
            $table->json('bookmarks')->nullable();
            $table->timestamp('client_updated_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'book_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_states');
    }
};
