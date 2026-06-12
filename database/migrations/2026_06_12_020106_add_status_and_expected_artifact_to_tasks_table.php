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
        Schema::table('tasks', function (Blueprint $table) {
            $table->text('expected_artifact')->nullable()->after('text');
            $table->string('status')->default('pending')->after('expected_artifact');
        });

        DB::table('tasks')->where('done', true)->update(['status' => 'done']);

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('done');

            $table->index(['plan_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['plan_id', 'status']);
            $table->boolean('done')->default(false)->after('status');
        });

        DB::table('tasks')->where('status', 'done')->update(['done' => true]);

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['status', 'expected_artifact']);
        });
    }
};
