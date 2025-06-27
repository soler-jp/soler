<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->unsignedInteger('taxable_amount')
                ->after('acquisition_date')
                ->comment('取得価額（税抜）');

            $table->unsignedInteger('tax_amount')
                ->nullable()
                ->after('taxable_amount')
                ->comment('取得時の消費税額');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropColumn('taxable_amount');
            $table->dropColumn('tax_amount');
        });
    }
};
