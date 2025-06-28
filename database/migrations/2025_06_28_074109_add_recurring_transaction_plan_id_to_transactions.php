<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('recurring_transaction_plan_id')
                ->nullable()
                ->after('created_by')
                ->constrained()
                ->restrictOnDelete()
                ->comment('この取引が属する定期取引プラン');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['recurring_transaction_plan_id']);
            $table->dropColumn('recurring_transaction_plan_id');
        });
    }
};
