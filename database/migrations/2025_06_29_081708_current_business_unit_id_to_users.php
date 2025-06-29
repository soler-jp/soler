<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_business_unit_id')
                ->nullable()
                ->after('id')
                ->constrained('business_units')
                ->nullOnDelete()
                ->comment('現在の事業所ID');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('current_business_unit_id');
            $table->dropForeign(['current_business_unit_id']);
        });
    }
};
