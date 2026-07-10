<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->text('concept_graph_json')->nullable()->after('mindmap_md');
            $table->string('concept_graph_status')->nullable()->after('concept_graph_json');
            $table->text('concept_graph_error')->nullable()->after('concept_graph_status');
            $table->timestamp('concept_graph_at')->nullable()->after('concept_graph_error');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['concept_graph_json', 'concept_graph_status', 'concept_graph_error', 'concept_graph_at']);
        });
    }
};
