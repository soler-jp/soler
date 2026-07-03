<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->unsignedTinyInteger('business_ratio')
                ->nullable()
                ->after('tax_type')
                ->comment('事業割合（%）');

            $table->uuid('allocation_group_id')
                ->nullable()
                ->after('business_ratio')
                ->index()
                ->comment('同一按分入力から生成された行群の識別子');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex(['allocation_group_id']);
            $table->dropColumn(['business_ratio', 'allocation_group_id']);
        });
    }
};
