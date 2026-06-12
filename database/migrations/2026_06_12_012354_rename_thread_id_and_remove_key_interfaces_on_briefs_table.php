<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('briefs', function (Blueprint $table) {
            $table->foreignId('source_thread_id')->nullable()->after('workspace_id')->constrained('threads')->nullOnDelete();
        });

        DB::table('briefs')->update([
            'source_thread_id' => DB::raw('thread_id'),
        ]);

        Schema::table('briefs', function (Blueprint $table) {
            $table->dropForeign(['thread_id']);
            $table->dropColumn(['thread_id', 'key_interfaces']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('briefs', function (Blueprint $table) {
            $table->foreignId('thread_id')->nullable()->after('workspace_id')->constrained()->nullOnDelete();
            $table->json('key_interfaces')->after('expected_behaviour')->default('[]');
        });

        DB::table('briefs')->update([
            'thread_id' => DB::raw('source_thread_id'),
        ]);

        Schema::table('briefs', function (Blueprint $table) {
            $table->dropForeign(['source_thread_id']);
            $table->dropColumn('source_thread_id');
        });
    }
};
