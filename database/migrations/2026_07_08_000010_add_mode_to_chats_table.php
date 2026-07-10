<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a `mode` column so we can label special conversation types
     * (e.g. "devil" for 魔鬼代言人) when showing history / exporting to Obsidian.
     */
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->string('mode')->nullable()->after('context');
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
