<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 多对话支持：每本书可同时存在多个独立对话。
     *  - conversations 表：对话元信息（归属用户/书、标题）。
     *  - chats.conversation_id：把每条对话归属到某个对话；历史数据回填为「默认对话」。
     */
    public function up(): void
    {
        // 1) conversations 表
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->default('新对话');
            $table->timestamps();

            $table->index(['user_id', 'book_id']);
        });

        // 2) chats 增加 conversation_id
        Schema::table('chats', function (Blueprint $table) {
            $table->foreignId('conversation_id')->nullable()->after('context');
            $table->index(['conversation_id', 'created_at']);
        });

        // 3) 回填：每个 (user, book) 建一个默认对话，并把已有 chats 归入
        $rows = DB::table('chats')->select('user_id', 'book_id')->distinct()->get();
        foreach ($rows as $r) {
            $convId = DB::table('conversations')->insertGetId([
                'user_id' => $r->user_id,
                'book_id' => $r->book_id,
                'title' => '默认对话',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('chats')
                ->where('user_id', $r->user_id)
                ->where('book_id', $r->book_id)
                ->update(['conversation_id' => $convId]);
        }

        // 4) 加外键（回填后保证引用有效）
        Schema::table('chats', function (Blueprint $table) {
            $table->foreign('conversation_id')->references('id')->on('conversations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropForeign(['conversation_id']);
            $table->dropColumn('conversation_id');
        });
        Schema::dropIfExists('conversations');
    }
};
