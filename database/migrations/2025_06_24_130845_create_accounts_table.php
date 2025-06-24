<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Account;

return new class extends Migration {

    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_unit_id')->constrained()->cascadeOnDelete();
            $table->string('name')->comment('勘定科目名');
            $table->enum('type', Account::TYPES)->comment('勘定科目分類');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
