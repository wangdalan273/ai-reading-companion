<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->text('argument_map_json')->nullable();
            $table->string('argument_map_status')->nullable();
            $table->text('argument_map_error')->nullable();
            $table->timestamp('argument_map_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn([
                'argument_map_json', 'argument_map_status',
                'argument_map_error', 'argument_map_at',
            ]);
        });
    }
};
