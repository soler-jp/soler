<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\JournalEntry;

return new class extends Migration {

    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id()->comment('仕訳明細ID');

            $table->foreignId('transaction_id')
                ->constrained()
                ->restrictOnDelete()
                ->comment('この仕訳明細が属するTransaction');

            $table->foreignId('account_id')
                ->constrained()
                ->restrictOnDelete()
                ->comment('勘定科目');

            $table->foreignId('sub_account_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete()
                ->comment('補助科目（任意）');

            $table->enum('type', JournalEntry::TYPES)
                ->comment('借方 or 貸方');

            $table->unsignedInteger('amount')
                ->comment('金額（税抜）');

            $table->unsignedInteger('tax_amount')
                ->default(0)
                ->comment('消費税額');

            $table->enum('tax_type', JournalEntry::TAX_TYPES)
                ->nullable()
                ->comment('税区分（課税・非課税など）');

            $table->boolean('is_effective')
                ->default(true)
                ->comment('帳簿上有効な明細か');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
