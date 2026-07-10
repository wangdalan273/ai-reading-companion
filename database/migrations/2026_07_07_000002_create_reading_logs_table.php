<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 阅读时长记录：按「用户 + 书 + 日期」聚合当天有效阅读秒数。
     * 用于日历热力图、连续打卡(streak)、累计统计。
     */
    public function up(): void
    {
        Schema::create('reading_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->date('log_date');
            $table->unsignedInteger('seconds')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'book_id', 'log_date']);
            $table->index(['user_id', 'log_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_logs');
    }
};
