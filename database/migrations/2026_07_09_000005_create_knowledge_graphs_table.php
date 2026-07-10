<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * N12 个人知识库图谱缓存（用户级，不绑书）。
     * 图完全由 rag_chunks 实时聚合而成，落库仅为"下次进入可见"的缓存。
     */
    public function up(): void
    {
        Schema::create('knowledge_graphs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->json('graph_json')->nullable();
            $table->string('status')->default('pending'); // pending|done|error
            $table->text('error')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_graphs');
    }
};
