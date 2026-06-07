<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('workspace_files');
    }

    public function down(): void
    {
        Schema::create('workspace_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('workspace_files')->cascadeOnDelete();
            $table->string('type');
            $table->string('name');
            $table->string('path');
            $table->longText('content')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'path']);
            $table->index(['workspace_id', 'parent_id', 'type', 'name']);
        });
    }
};
