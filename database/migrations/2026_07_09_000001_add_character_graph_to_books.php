<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->text('character_graph_json')->nullable()->after('concept_graph_at');
            $table->string('character_graph_status')->nullable()->after('character_graph_json');
            $table->text('character_graph_error')->nullable()->after('character_graph_status');
            $table->timestamp('character_graph_at')->nullable()->after('character_graph_error');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['character_graph_json', 'character_graph_status', 'character_graph_error', 'character_graph_at']);
        });
    }
};
