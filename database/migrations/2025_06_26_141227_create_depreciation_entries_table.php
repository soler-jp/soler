<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depreciation_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('fiscal_year_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('この償却が属する年度');

            $table->foreignId('fixed_asset_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('対象となる固定資産');

            $table->unsignedTinyInteger('months')
                ->comment('その年度における償却月数（最大12）');

            $table->unsignedBigInteger('ordinary_amount')
                ->comment('普通償却費');

            $table->unsignedBigInteger('special_amount')
                ->default(0)
                ->comment('特別償却費（使わない場合は0）');

            $table->unsignedBigInteger('total_amount')
                ->comment('償却費合計（普通＋特別）');

            $table->decimal('business_usage_ratio', 5, 2)
                ->default(1.00)
                ->comment('事業専用割合（例: 0.80）');

            $table->unsignedBigInteger('deductible_amount')
                ->comment('本年分の必要経費算入額（償却費合計 × 事業専用割合）');

            $table->foreignId('journal_entry_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete()
                ->comment('この償却に対応する仕訳（未登録ならnull）');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_entries');
    }
};
