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
        Schema::create('todos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_unit_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('対象事業体ID');
            $table->foreignId('fiscal_year_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete()
                ->comment('対象年度ID。年度に紐づかない Todo は null');
            $table->string('source_type')
                ->comment('Todo の発生源種別。manual, recurring, system');
            $table->nullableMorphs('source_model');
            $table->string('title')
                ->comment('Todo の表示文言（1行）');
            $table->text('body')
                ->nullable()
                ->comment('補足説明');
            $table->date('due_on')
                ->nullable()
                ->comment('期日');
            $table->string('priority')
                ->default('normal')
                ->comment('優先度。high, normal, low');
            $table->string('status')
                ->default('pending')
                ->comment('状態。pending, completed, dismissed');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->index(['business_unit_id', 'status', 'fiscal_year_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('todos');
    }
};
