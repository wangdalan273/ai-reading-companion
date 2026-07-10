<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 复习闪卡（间隔重复 / Leitner 简易版）。
     * front = 划线原文；back = 可选备注/释义；box = 记忆盒层级(0 新→5 熟)；
     * due_date = 到期复习日。划线可一键转闪卡。
     */
    public function up(): void
    {
        Schema::create('flashcards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('annotation_id')->nullable()->constrained('annotations')->nullOnDelete();
            $table->text('front');
            $table->text('back')->nullable();
            $table->unsignedTinyInteger('box')->default(0);
            $table->date('due_date');
            $table->timestamps();

            $table->index(['user_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flashcards');
    }
};
