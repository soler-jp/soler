<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_unit_id')->constrained()->cascadeOnDelete();
            $table->integer('year')->comment('会計年度の西暦年');
            $table->boolean('is_active')->default(false)->comment('操作中の年度フラグ');
            $table->boolean('is_closed')->default(false)->comment('決算済みフラグ');
            $table->date('start_date')->comment('会計年度開始日');  // 個人事業主は1/1で固定だが、将来的に法人対応も考慮
            $table->date('end_date')->comment('会計年度終了日');    // 個人事業主は12/31で固定だが、将来的に法人対応も考慮
            $table->timestamps();

            $table->unique(['business_unit_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_years');
    }
};
