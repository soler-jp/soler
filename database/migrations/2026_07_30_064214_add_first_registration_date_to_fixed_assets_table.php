<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->date('first_registration_date')
                ->nullable()
                ->comment('中古車の初度登録日');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropColumn('first_registration_date');
        });
    }
};
