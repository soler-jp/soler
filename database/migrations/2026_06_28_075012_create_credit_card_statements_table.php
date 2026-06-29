<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_statements', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->foreignId('credit_card_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('対象のクレジットカード');
            $table->unsignedSmallInteger('statement_year')
                ->comment('請求年月の年');
            $table->unsignedTinyInteger('statement_month')
                ->comment('請求年月の月');
            $table->date('period_start_on')
                ->nullable()
                ->comment('明細対象期間の開始日');
            $table->date('period_end_on')
                ->nullable()
                ->comment('明細対象期間の終了日');
            $table->date('billed_on')
                ->nullable()
                ->comment('請求確定日');
            $table->date('paid_on')
                ->nullable()
                ->comment('引落予定日');
            $table->bigInteger('total_amount')
                ->default(0)
                ->comment('明細合計額');
            $table->unsignedInteger('line_count')
                ->default(0)
                ->comment('明細行数');
            $table->timestamp('imported_at')
                ->nullable()
                ->comment('最後に取り込んだ日時');
            $table->timestamps();

            $table->unique(['credit_card_id', 'statement_year', 'statement_month'], 'credit_card_statement_month_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_statements');
    }
};
