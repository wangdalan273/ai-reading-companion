<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 阶段2 — 伴读人格 + 跨书/跨笔记对话。
     * - personas：用户自定义的多套伴读人格（系统提示词、口吻、头像）。
     * - companion_messages：与 chats 解耦，专门存放「伴读」跨书对话，
     *   不绑死某一本书，支持 persona_id 与 scope（all/vault/book）。
     */
    public function up(): void
    {
        Schema::create('personas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('emoji')->default('🤖');
            $table->string('description')->nullable();
            $table->text('system_prompt');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('companion_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $table->string('scope')->default('all');      // all | vault | book
            $table->foreignId('book_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role');                        // user | assistant
            $table->text('content');
            $table->text('context')->nullable();          // 选中引用 / 检索上下文
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companion_messages');
        Schema::dropIfExists('personas');
    }
};
