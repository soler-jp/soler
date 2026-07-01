<?php

namespace Tests\Feature;

use App\Models\Counterparty;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CounterpartyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function factoryで_counterpartyを作成できる()
    {
        $counterparty = Counterparty::factory()->create([
            'qualification_status' => Counterparty::QUALIFICATION_STATUS_QUALIFIED,
        ]);

        $this->assertDatabaseHas('counterparties', [
            'id' => $counterparty->id,
            'name' => $counterparty->name,
            'qualification_status' => Counterparty::QUALIFICATION_STATUS_QUALIFIED,
        ]);

        $this->assertDatabaseHas('counterparty_qualification_events', [
            'counterparty_id' => $counterparty->id,
            'qualification_status' => Counterparty::QUALIFICATION_STATUS_QUALIFIED,
        ]);
    }

    #[Test]
    public function qualification_statusが未指定ならunknownになる()
    {
        $counterparty = Counterparty::factory()->create();

        $this->assertSame(Counterparty::QUALIFICATION_STATUS_UNKNOWN, $counterparty->qualification_status);

        $this->assertDatabaseHas('counterparties', [
            'id' => $counterparty->id,
            'qualification_status' => Counterparty::QUALIFICATION_STATUS_UNKNOWN,
        ]);

        $this->assertDatabaseHas('counterparty_qualification_events', [
            'counterparty_id' => $counterparty->id,
            'qualification_status' => Counterparty::QUALIFICATION_STATUS_UNKNOWN,
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

    #[Test]
    public function qualification_statusの変更履歴を記録できる()
    {
        Carbon::setTestNow(Carbon::parse('2026-01-04 09:00:00'));

        try {
            $counterparty = Counterparty::factory()->create();

            Carbon::setTestNow(Carbon::parse('2026-01-10 09:00:00'));
            $counterparty->setQualificationStatus(
                Counterparty::QUALIFICATION_STATUS_QUALIFIED,
                Carbon::parse('2026-01-10 00:00:00'),
            );

            Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00'));
            $counterparty->setQualificationStatus(
                Counterparty::QUALIFICATION_STATUS_NON_QUALIFIED,
                Carbon::parse('2026-10-01 00:00:00'),
            );

            $counterparty->refresh();

            $events = $counterparty->qualificationEvents()
                ->orderBy('recorded_at')
                ->orderBy('id')
                ->get();

            $this->assertCount(3, $events);
            $this->assertSame(Counterparty::QUALIFICATION_STATUS_UNKNOWN, $events->first()->qualification_status);
            $this->assertSame(Counterparty::QUALIFICATION_STATUS_QUALIFIED, $events->get(1)->qualification_status);
            $this->assertSame(Counterparty::QUALIFICATION_STATUS_NON_QUALIFIED, $events->last()->qualification_status);
            $this->assertSame('2026-01-10', $events->get(1)->effective_from->toDateString());
            $this->assertSame('2026-10-01', $events->last()->effective_from->toDateString());

            $this->assertSame(
                Counterparty::QUALIFICATION_STATUS_QUALIFIED,
                $counterparty->qualificationStatusAt(Carbon::parse('2026-01-01 00:00:00')),
            );
            $this->assertSame(
                Counterparty::QUALIFICATION_STATUS_QUALIFIED,
                $counterparty->qualificationStatusAt(Carbon::parse('2026-01-03 00:00:00')),
            );
            $this->assertSame(
                Counterparty::QUALIFICATION_STATUS_QUALIFIED,
                $counterparty->qualificationStatusAt(Carbon::parse('2026-01-08 00:00:00')),
            );
            $this->assertSame(
                Counterparty::QUALIFICATION_STATUS_QUALIFIED,
                $counterparty->qualificationStatusAt(Carbon::parse('2026-01-10 00:00:00')),
            );
            $this->assertSame(
                Counterparty::QUALIFICATION_STATUS_NON_QUALIFIED,
                $counterparty->qualificationStatusAt(Carbon::parse('2026-10-31 00:00:00')),
            );
            $this->assertSame(Counterparty::QUALIFICATION_STATUS_NON_QUALIFIED, $counterparty->qualification_status);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    #[TestWith([Counterparty::QUALIFICATION_STATUS_QUALIFIED])]
    #[TestWith([Counterparty::QUALIFICATION_STATUS_NON_QUALIFIED])]
    public function qualification_statusが1月3日に登録された状態はそれ以前にも適用される(string $qualificationStatus)
    {
        Carbon::setTestNow(Carbon::parse('2026-01-03 09:00:00'));

        try {
            $counterparty = Counterparty::factory()->create();

            $counterparty->setQualificationStatus(
                $qualificationStatus,
                Carbon::parse('2026-01-03 00:00:00'),
            );

            $this->assertSame(
                $qualificationStatus,
                $counterparty->qualificationStatusAt(Carbon::parse('2026-01-01 00:00:00')),
            );
            $this->assertSame(
                $qualificationStatus,
                $counterparty->qualificationStatusAt(Carbon::parse('2026-01-03 00:00:00')),
            );
            $this->assertSame(
                $qualificationStatus,
                $counterparty->qualificationStatusAt(Carbon::parse('2026-01-08 00:00:00')),
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function unknownには変更できない()
    {
        $counterparty = Counterparty::factory()->create([
            'qualification_status' => Counterparty::QUALIFICATION_STATUS_QUALIFIED,
        ]);

        try {
            $counterparty->setQualificationStatus(Counterparty::QUALIFICATION_STATUS_UNKNOWN);

            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('unknown には変更できません。', $exception->getMessage());
            $this->assertSame(Counterparty::QUALIFICATION_STATUS_QUALIFIED, $counterparty->refresh()->qualification_status);
            $this->assertCount(1, $counterparty->qualificationEvents);
        }
    }
}
