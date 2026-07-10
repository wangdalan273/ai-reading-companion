<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Books imported by a user (EPUB / PDF). Stored with user_id for data isolation
     * (commercial-ready multi-tenant foundation).
     */
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('author')->nullable();
            $table->string('format'); // epub | pdf
            $table->string('path');   // private storage path
            $table->unsignedInteger('size')->nullable(); // bytes
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
