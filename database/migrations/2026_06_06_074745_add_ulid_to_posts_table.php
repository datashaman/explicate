<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->ulid('ulid')->nullable()->after('id')->unique();
        });

        DB::table('posts')
            ->whereNull('ulid')
            ->orderBy('id')
            ->get(['id'])
            ->each(function (object $post): void {
                DB::table('posts')
                    ->where('id', $post->id)
                    ->update(['ulid' => (string) Str::ulid()]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('ulid');
        });
    }
};
