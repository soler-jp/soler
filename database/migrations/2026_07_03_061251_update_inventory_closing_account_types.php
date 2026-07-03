<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('accounts')
            ->whereIn('name', ['期首商品（棚卸高）', '期末商品（棚卸高）'])
            ->update(['type' => Account::TYPE_EXPENSE]);
    }

    public function down(): void
    {
        DB::table('accounts')
            ->whereIn('name', ['期首商品（棚卸高）', '期末商品（棚卸高）'])
            ->update(['type' => Account::TYPE_ASSET]);
    }
};
