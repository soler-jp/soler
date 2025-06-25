<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sub_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('account_id')
                ->constrained()
                ->restrictOnDelete()
                ->comment('この補助科目が属する勘定科目');

            $table->string('name')
                ->comment('補助科目名（得意先名・部門名など）');

            $table->timestamps();

            $table->unique(['account_id', 'name'], 'unique_sub_account_per_account');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_accounts');
    }
};
