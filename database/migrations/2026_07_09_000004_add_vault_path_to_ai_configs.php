<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 — P13：把连接器的「源路径」下沉为按用户的配置。
     *
     * vault_path  → Obsidian vault 绝对路径（头等连接器，用户个人需要）
     * note_folder → 任意通用 markdown 文件夹绝对路径（通用版，不绑定 Obsidian）
     * 两个都可为空：都不配时仅索引已导入的书，系统不假死。
     */
    public function up(): void
    {
        Schema::table('ai_configs', function (Blueprint $table) {
            $table->text('vault_path')->nullable()->after('model');
            $table->text('note_folder')->nullable()->after('vault_path');
        });
    }

    public function down(): void
    {
        Schema::table('ai_configs', function (Blueprint $table) {
            $table->dropColumn(['vault_path', 'note_folder']);
        });
    }
};
