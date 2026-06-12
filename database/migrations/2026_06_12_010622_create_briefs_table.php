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
        Schema::create('briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category');
            $table->string('summary');
            $table->text('current_behaviour');
            $table->text('expected_behaviour');
            $table->json('key_interfaces');
            $table->json('acceptance_criteria');
            $table->text('out_of_scope')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('briefs');
    }
};
