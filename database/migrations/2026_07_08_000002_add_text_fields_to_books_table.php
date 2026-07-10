<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->longText('ocr_text')->nullable()->after('size');
            $table->boolean('has_text_layer')->default(true)->after('ocr_text');
            $table->longText('mindmap_md')->nullable()->after('has_text_layer');
            $table->timestamp('text_extracted_at')->nullable()->after('mindmap_md');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['ocr_text', 'has_text_layer', 'mindmap_md', 'text_extracted_at']);
        });
    }
};
