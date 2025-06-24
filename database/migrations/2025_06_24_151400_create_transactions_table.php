<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id()->comment('ID');

            $table->unsignedInteger('entry_number')
                ->comment('会計年度内での連番');

            $table->foreignId('fiscal_year_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('この取引が属する会計年度');

            $table->date('date')
                ->comment('取引日（仕訳日）');

            $table->string('description')
                ->comment('取引の摘要（内容）');

            $table->text('remarks')
                ->nullable()
                ->comment('備考（自由記述）');

            $table->boolean('is_adjusting_entry')
                ->default(false)
                ->comment('決算整理仕訳かどうか（true=決算仕訳）');

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('この取引を作成したユーザー（削除時はnull）'); // 将来的に、複数人が一つのFiscalYearを扱う場合を考慮

            $table->timestamps();

            $table->unique(['fiscal_year_id', 'entry_number'], 'unique_entry_number_per_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
