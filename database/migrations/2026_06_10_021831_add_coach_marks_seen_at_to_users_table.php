<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('coach_marks_seen_at')->nullable()->after('current_workspace_id');
        });

        // Backfill all existing users so they don't see coaching marks they've never opted into.
        DB::table('users')->update(['coach_marks_seen_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('coach_marks_seen_at');
        });
    }
};
