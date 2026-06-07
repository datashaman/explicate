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
        Schema::table('posts', function (Blueprint $table) {
            $table->dropUnique(['topic_id', 'slug']);
            $table->dropColumn(['title', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('title')->after('topic_id')->default('');
            $table->string('slug')->after('title')->nullable();
            $table->unique(['topic_id', 'slug']);
        });
    }
};
