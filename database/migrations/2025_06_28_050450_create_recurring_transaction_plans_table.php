<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('recurring_transaction_plans', function (Blueprint $table) {
            $table->id();

            $table->foreignId('business_unit_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('このプランが属する帳簿');

            $table->string('name')
                ->comment('プラン名（例：家賃、電気代）');

            $table->enum('interval', ['monthly', 'bimonthly', 'yearly'])
                ->comment('繰り返し間隔');

            $table->unsignedTinyInteger('day_of_month')
                ->comment('毎月何日に発生するか（1〜31）');

            $table->boolean('is_income')
                ->comment('収入か支出か（true: 収入）');

            $table->foreignId('debit_account_id')
                ->constrained('accounts')
                ->comment('借方の勘定科目');

            $table->foreignId('credit_account_id')
                ->constrained('accounts')
                ->comment('貸方の勘定科目');

            $table->unsignedBigInteger('amount')
                ->comment('税抜金額');

            $table->unsignedBigInteger('tax_amount')
                ->nullable()
                ->comment('消費税額');

            $table->string('tax_type')
                ->nullable()
                ->comment('税区分（例：taxable_purchases_10）');

            $table->text('description')
                ->nullable()
                ->comment('備考');

            $table->boolean('is_active')
                ->default(true)
                ->comment('このプランが有効かどうか');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        $table->dropTable('recurring_transaction_plans');
    }
};
