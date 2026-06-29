<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_statement_lines', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->foreignId('credit_card_statement_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('所属する月次明細');
            $table->foreignId('credit_card_import_batch_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete()
                ->comment('元の取込バッチ');
            $table->unsignedInteger('line_number')
                ->comment('CSV内の行番号');
            $table->date('used_on')
                ->nullable()
                ->comment('利用日');
            $table->date('posted_on')
                ->nullable()
                ->comment('カード会社側の計上日');
            $table->string('merchant_name')
                ->nullable()
                ->comment('利用先名称');
            $table->string('description')
                ->nullable()
                ->comment('明細摘要');
            $table->bigInteger('amount')
                ->comment('利用金額');
            $table->string('fingerprint', 128)
                ->comment('重複検知用フィンガープリント');
            $table->enum('status', ['unreviewed', 'registered', 'private', 'duplicate', 'ignored'])
                ->default('unreviewed')
                ->comment('明細行の処理状態');
            $table->boolean('is_active')
                ->default(true)
                ->comment('現在有効な明細行かどうか');
            $table->text('memo')
                ->nullable()
                ->comment('レビュー用メモ');
            $table->json('raw_payload')
                ->nullable()
                ->comment('元CSVの正規化前データ');
            $table->foreignId('transaction_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete()
                ->comment('登録済み取引');
            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('レビューしたユーザー');
            $table->timestamp('reviewed_at')
                ->nullable()
                ->comment('レビュー完了日時');
            $table->timestamps();

            $table->unique(['credit_card_import_batch_id', 'line_number'], 'credit_card_batch_line_number_unique');
            $table->index(['credit_card_statement_id', 'status'], 'ccsl_stmt_status_idx');
            $table->index(['credit_card_statement_id', 'is_active'], 'ccsl_stmt_active_idx');
            $table->index(['credit_card_statement_id', 'fingerprint'], 'ccsl_stmt_fingerprint_idx');
            $table->index('used_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_statement_lines');
    }
};
