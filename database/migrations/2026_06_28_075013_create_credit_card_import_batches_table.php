<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_import_batches', function (Blueprint $table) {
            $table->id()->comment('ID');
            $table->foreignId('credit_card_statement_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('対象の月次明細');
            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('アップロードしたユーザー');
            $table->string('source_filename')
                ->comment('元ファイル名');
            $table->string('source_hash', 64)
                ->comment('元ファイルのハッシュ');
            $table->string('parser_key')
                ->comment('使用したパーサ');
            $table->enum('status', ['processing', 'completed', 'failed'])
                ->default('processing')
                ->comment('取込処理状態');
            $table->boolean('is_active')
                ->default(true)
                ->comment('現在有効な取込バッチかどうか');
            $table->unsignedInteger('row_count')
                ->default(0)
                ->comment('CSV行数');
            $table->unsignedInteger('success_count')
                ->default(0)
                ->comment('正常取込件数');
            $table->unsignedInteger('duplicate_count')
                ->default(0)
                ->comment('重複件数');
            $table->unsignedInteger('error_count')
                ->default(0)
                ->comment('エラー件数');
            $table->timestamp('imported_at')
                ->nullable()
                ->comment('取込完了日時');
            $table->timestamp('deactivated_at')
                ->nullable()
                ->comment('無効化日時');
            $table->foreignId('deactivated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('無効化したユーザー');
            $table->string('deactivation_reason')
                ->nullable()
                ->comment('無効化理由');
            $table->text('error_summary')
                ->nullable()
                ->comment('エラー要約');
            $table->timestamps();

            $table->index(['credit_card_statement_id', 'status']);
            $table->index(['credit_card_statement_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_import_batches');
    }
};
