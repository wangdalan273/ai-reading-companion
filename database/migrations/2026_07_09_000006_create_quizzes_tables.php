<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P15 自动测验：quizzes（一次测验的元数据）+ quiz_questions（每题选项/答案/解析）。
     * 与书、用户关联，离线生成也能落库，下次进书可见。
     */
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('book_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('chapter_title')->nullable();   // 来源章节标题（selection 时为 null）
            $table->string('source_type')->default('selection'); // selection | chapter | book
            $table->string('source_ref')->nullable();      // 章节 href / 笔记路径等
            $table->integer('total')->default(0);
            $table->timestamps();
        });

        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->onDelete('cascade');
            $table->text('question');
            $table->json('options_json');   // ["A...","B...","C...","D..."]
            $table->unsignedTinyInteger('answer_index')->default(0);
            $table->text('explanation')->nullable();
            $table->string('source_ref')->nullable(); // 出处章节/页码等
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_questions');
        Schema::dropIfExists('quizzes');
    }
};
