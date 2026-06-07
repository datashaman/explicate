<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('agent_tasks', function (Blueprint $table) {
            $table->foreignId('status_post_id')
                ->nullable()
                ->after('post_id')
                ->constrained('posts')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_post_id');
        });
    }
};
