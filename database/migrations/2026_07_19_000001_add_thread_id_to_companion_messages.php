<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companion_messages', function (Blueprint $table) {
            $table->string('thread_id', 80)->nullable()->after('user_id');
            $table->index(['user_id', 'thread_id']);
        });
    }

    public function down(): void
    {
        Schema::table('companion_messages', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'thread_id']);
            $table->dropColumn('thread_id');
        });
    }
};
