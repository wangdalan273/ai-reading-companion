<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 阶段3：书架封面。cover_path 存封面文件名（位于 public/covers），
     * 为空时卡片回退到“渐变占位 + 书名首字”的优雅兜底。
     */
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('cover_path')->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn('cover_path');
        });
    }
};
