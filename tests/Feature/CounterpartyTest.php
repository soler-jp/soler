<?php

namespace Tests\Feature;

use App\Models\Counterparty;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CounterpartyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function factoryで_counterpartyを作成できる()
    {
        $counterparty = Counterparty::factory()->create();

        $this->assertDatabaseHas('counterparties', [
            'id' => $counterparty->id,
            'name' => $counterparty->name,
        ]);
    }

    #[Test]
    public function business_unitにcounterpartyを紐づけられる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '取引先テスト事業体']);

        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
            'name' => 'ABC商店',
        ]);

        $this->assertTrue($counterparty->businessUnit->is($unit));
        $this->assertTrue($unit->counterparties->contains($counterparty));
    }

    #[Test]
    public function transactionからcounterpartyを参照できる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '取引先テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
            'name' => 'XYZストア',
        ]);

        $transaction = Transaction::factory()->create([
            'fiscal_year_id' => $fiscalYear->id,
            'counterparty_id' => $counterparty->id,
        ]);

        $this->assertTrue($transaction->counterparty->is($counterparty));
    }
}
