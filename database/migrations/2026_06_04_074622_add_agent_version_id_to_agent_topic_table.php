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
        Schema::table('agent_topic', function (Blueprint $table) {
            $table->foreignId('agent_version_id')->nullable()->constrained('agent_versions')->nullOnDelete()->after('topic_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_topic', function (Blueprint $table) {
            $table->dropForeign(['agent_version_id']);
            $table->dropColumn('agent_version_id');
        });
    }
};
