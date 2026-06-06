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
        Schema::table('messages', function (Blueprint $table) {
            $table->ulid('ulid')->nullable()->after('id')->unique();
        });

        DB::table('messages')
            ->whereNull('ulid')
            ->orderBy('id')
            ->get(['id'])
            ->each(function (object $message): void {
                DB::table('messages')
                    ->where('id', $message->id)
                    ->update(['ulid' => (string) Str::ulid()]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('ulid');
        });
    }
};
