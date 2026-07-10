<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 给可离线编辑的业务表补充软删除（墓碑），支撑多端删除同步。
     * 不破坏现有数据：仅新增 deleted_at 列。
     */
    public function up(): void
    {
        $tables = [
            'books', 'annotations', 'flashcards', 'chats',
            'companion_messages', 'user_prompts', 'personas',
        ];

        foreach ($tables as $table) {
            if (! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        $tables = [
            'books', 'annotations', 'flashcards', 'chats',
            'companion_messages', 'user_prompts', 'personas',
        ];

        foreach ($tables as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropSoftDeletes();
                });
            }
        }
    }
};
