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
        Schema::create('blue_return_inputs', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->foreignId('fiscal_year_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('対象の会計年度');
            $table->string('key')
                ->comment('内訳種別');
            $table->json('value')
                ->comment('内訳データ(JSON)');
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'key'], 'blue_return_inputs_fiscal_year_key_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blue_return_inputs');
    }
};
