<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user AI provider config. The API key is stored encrypted (at rest)
     * and is NEVER exposed to the browser — only the server uses it.
     */
    public function up(): void
    {
        Schema::create('ai_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('openai'); // openai|deepseek|moonshot|custom
            $table->text('api_key')->nullable();           // encrypted at rest
            $table->string('base_url')->nullable();
            $table->string('model')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_configs');
    }
};
