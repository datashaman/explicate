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
        Schema::table('threads', function (Blueprint $table) {
            $table->foreignId('parent_post_id')
                ->nullable()
                ->after('topic_id')
                ->constrained('posts')
                ->nullOnDelete();
            $table->unique('parent_post_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->dropUnique(['parent_post_id']);
            $table->dropConstrainedForeignId('parent_post_id');
        });
    }
};
