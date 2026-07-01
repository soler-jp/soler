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
        Schema::create('counterparty_qualification_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('counterparty_id')->constrained()->cascadeOnDelete();
            $table->string('qualification_status')->default('unknown');
            $table->timestamp('effective_from');
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->index(['counterparty_id', 'effective_from'], 'cpq_events_counterparty_effective_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('counterparty_qualification_events');
    }
};
