<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('business_unit_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('この固定資産が所属する事業体');

            $table->foreignId('account_id')
                ->constrained()
                ->comment('この資産の取得に使われた勘定科目（例: 器具備品, 車両運搬具）');

            $table->string('name')
                ->comment('固定資産の名称（例: ノートPC, 軽トラック）');

            $table->string('asset_category')
                ->nullable()
                ->comment('資産の種類分類（例: furniture_fixtures, vehicles）');

            $table->date('acquisition_date')
                ->comment('固定資産の取得日');

            $table->unsignedBigInteger('acquisition_cost')
                ->comment('取得価額（税込）');

            $table->unsignedBigInteger('depreciation_base_amount')
                ->comment('償却の基礎になる金額。通常は取得価額と同じ');

            $table->unsignedTinyInteger('useful_life')
                ->nullable()
                ->comment('耐用年数（月単位）');

            $table->string('depreciation_method')
                ->nullable()
                ->comment('償却方法（例: straight_line）');

            $table->boolean('is_disposed')
                ->default(false)
                ->comment('除却済みかどうか');

            $table->date('disposed_at')
                ->nullable()
                ->comment('売却・除却日');

            $table->unsignedBigInteger('disposal_amount')
                ->nullable()
                ->comment('売却金額');

            $table->foreignId('disposal_account_id')
                ->nullable()
                ->constrained('accounts')
                ->nullOnDelete()
                ->comment('売却金額を受け取る勘定科目（例: 普通預金）');

            $table->foreignId('disposal_gain_loss_account_id')
                ->nullable()
                ->constrained('accounts')
                ->nullOnDelete()
                ->comment('売却益・売却損の処理先勘定科目（例: 雑収入, 雑損失）');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};
