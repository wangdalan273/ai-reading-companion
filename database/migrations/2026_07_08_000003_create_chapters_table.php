<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('idx');
            $table->string('title');
            $table->longText('source_text')->nullable();
            $table->longText('summary')->nullable();
            $table->string('status')->default('pending'); // pending|working|done|failed
            $table->text('error')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
            $table->index(['book_id', 'idx']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chapters');
    }
};
