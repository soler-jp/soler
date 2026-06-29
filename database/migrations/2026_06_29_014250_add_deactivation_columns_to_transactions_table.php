<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('is_active')
                ->default(true)
                ->after('created_by')
                ->comment('現在有効な取引かどうか');
            $table->timestamp('deactivated_at')
                ->nullable()
                ->after('is_active')
                ->comment('無効化日時');
            $table->foreignId('deactivated_by')
                ->nullable()
                ->after('deactivated_at')
                ->constrained('users')
                ->nullOnDelete()
                ->comment('無効化したユーザー');
            $table->string('deactivation_reason')
                ->nullable()
                ->after('deactivated_by')
                ->comment('無効化理由');

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn('deactivated_at');
            $table->dropColumn('deactivation_reason');
            $table->dropColumn('is_active');
        });
    }
};
