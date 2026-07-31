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
        Schema::create('initial_setup_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_unit_id')->constrained()->cascadeOnDelete();
            $table->integer('year');
            $table->string('opening_context');
            $table->boolean('is_taxable')->default(false);
            $table->string('bank_account_answer');
            $table->string('cash_on_hand_answer');
            $table->string('fixed_asset_answer');
            $table->string('recurring_expense_answer');
            $table->string('recurring_income_answer');
            $table->string('counterparty_answer');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique('business_unit_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('initial_setup_data');
    }
};
