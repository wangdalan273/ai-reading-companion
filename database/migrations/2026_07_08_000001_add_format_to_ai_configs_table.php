<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P11: make the custom AI setting provider-agnostic.
     * `format` records which request/response shape LlmService should use:
     * openai | anthropic | gemini.
     */
    public function up(): void
    {
        Schema::table('ai_configs', function (Blueprint $table) {
            $table->string('format')->default('openai')->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('ai_configs', function (Blueprint $table) {
            $table->dropColumn('format');
        });
    }
};
