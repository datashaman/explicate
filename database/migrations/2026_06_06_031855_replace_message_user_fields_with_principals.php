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
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('sender_principal_id')
                ->nullable()
                ->index();

            $table->foreignId('recipient_principal_id')
                ->nullable()
                ->index();
        });

        //
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('messages', 'sender_principal_id')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->dropColumn('sender_principal_id');
            });
        }

        if (Schema::hasColumn('messages', 'recipient_principal_id')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->dropColumn('recipient_principal_id');
            });
        }
    }
};
