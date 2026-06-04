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
        Schema::create('agent_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('provider');
            $table->string('model');
            $table->string('reasoning_effort')->nullable();
            $table->text('prompt')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['agent_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_versions');
    }
};
