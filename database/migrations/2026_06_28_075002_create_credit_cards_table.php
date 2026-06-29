<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_cards', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->foreignId('business_unit_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('このカードを管理する事業体');
            $table->string('name')
                ->comment('カード表示名');
            $table->string('issuer_name')
                ->comment('発行会社名');
            $table->string('network')
                ->nullable()
                ->comment('国際ブランド');
            $table->string('last_four', 4)
                ->nullable()
                ->comment('カード番号下4桁');
            $table->enum('ownership_type', ['business', 'personal'])
                ->comment('事業専用カードか個人カードか');
            $table->string('parser_key')
                ->comment('利用するCSVパーサ識別子');
            $table->foreignId('liability_sub_account_id')
                ->nullable()
                ->constrained('sub_accounts')
                ->nullOnDelete()
                ->comment('事業カード用の貸方既定補助科目');
            $table->foreignId('owner_draw_sub_account_id')
                ->nullable()
                ->constrained('sub_accounts')
                ->nullOnDelete()
                ->comment('個人カード用の貸方既定補助科目');
            $table->boolean('is_active')
                ->default(true)
                ->comment('利用中のカードかどうか');
            $table->text('notes')
                ->nullable()
                ->comment('補足メモ');
            $table->timestamps();

            $table->unique(['business_unit_id', 'name']);
            $table->index(['business_unit_id', 'issuer_name', 'network']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_cards');
    }
};
