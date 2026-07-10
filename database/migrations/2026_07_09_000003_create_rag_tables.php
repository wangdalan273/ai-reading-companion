<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 — P13 通用 RAG 落库结构。
     *
     * 设计第一性原理：rag_chunks 是「来源无关」的检索单元。
     * source_type 仅作枚举标记（book|obsidian|note|other），
     * 引擎检索时不区分来源——保证通用版（任意 markdown 文件夹/粘贴笔记）
     * 与 Obsidian 连接器共用同一套索引与问答，互不排他。
     */
    public function up(): void
    {
        Schema::create('rag_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_type')->default('book'); // book|obsidian|note|other
            $table->string('source_path')->nullable();      // vault/文件夹内相对路径或书标识
            $table->foreignId('book_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();            // 章节名 / 笔记标题 / 卡片标题
            $table->longText('content');                    // 原子片段正文
            $table->integer('chunk_index')->default(0);
            $table->json('links')->nullable();              // 解析出的 [[双链]] 目标数组
            $table->json('meta')->nullable();               // tags / 页码 / 连接器附加信息
            $table->json('embedding')->nullable();          // 向量（可插拔，无则 null → BM25 兜底）
            $table->timestamps();

            $table->index(['user_id', 'source_type']);
            $table->index(['user_id', 'book_id']);
        });

        Schema::create('user_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');                         // 提示词名称（如「苏格拉底导师」）
            $table->longText('prompt');                     // System Prompt 正文
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_prompts');
        Schema::dropIfExists('rag_chunks');
    }
};
