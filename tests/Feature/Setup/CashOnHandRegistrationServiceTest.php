<?php

namespace Tests\Feature\Setup;

use App\Models\JournalEntry;
use App\Models\User;
use App\Services\CashOnHandRegistrationService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CashOnHandRegistrationServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 複数の事業用現金を登録できる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '事業用現金登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);

        $subAccounts = app(CashOnHandRegistrationService::class)->register(
            $businessUnit,
            $fiscalYear,
            [
                ['label' => 'レジ現金', 'opening_balance' => 120000],
                ['label' => '金庫', 'opening_balance' => 80000],
            ],
            $user,
        );

        $cashAccount = $businessUnit->getAccountByName('現金');
        $capitalSubAccount = $businessUnit->getSubAccountByName('元入金', '元入金');

        $this->assertCount(2, $subAccounts);
        $this->assertSame(['レジ現金', '金庫'], array_map(fn ($subAccount) => $subAccount->name, $subAccounts));
        $this->assertDatabaseHas('sub_accounts', [
            'account_id' => $cashAccount?->id,
            'name' => 'レジ現金',
        ]);
        $this->assertDatabaseHas('sub_accounts', [
            'account_id' => $cashAccount?->id,
            'name' => '金庫',
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $subAccounts[0]->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 120000,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $subAccounts[1]->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 80000,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $capitalSubAccount?->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 200000,
        ]);
    }

    #[Test]
    public function actorが事業体にアクセスできない場合は事業用現金登録を拒否する(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '事業用現金登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('この事業体に事業用現金を登録する権限がありません。');

        app(CashOnHandRegistrationService::class)->register(
            $businessUnit,
            $fiscalYear,
            [['label' => 'レジ現金', 'opening_balance' => 1000]],
            $otherUser,
        );
    }

    #[Test]
    public function 同名の事業用現金は重複登録できない(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '事業用現金登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);
        $service = app(CashOnHandRegistrationService::class);

        $service->register($businessUnit, $fiscalYear, [
            ['label' => 'レジ現金', 'opening_balance' => 1000],
        ], $user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('同名の事業用現金はすでに登録されています。');

        $service->register($businessUnit, $fiscalYear, [
            ['label' => 'レジ現金', 'opening_balance' => 2000],
        ], $user);
    }

    #[Test]
    public function 同一リクエスト内で同名の事業用現金は登録できない(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '事業用現金登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('同名の事業用現金は同時に登録できません。');

        app(CashOnHandRegistrationService::class)->register($businessUnit, $fiscalYear, [
            ['label' => 'レジ現金', 'opening_balance' => 1000],
            ['label' => 'レジ現金', 'opening_balance' => 2000],
        ], $user);
    }

    #[Test]
    public function 既存の期首仕訳がある場合は事業用現金と元入金の金額を改訂する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '事業用現金登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);
        $service = app(CashOnHandRegistrationService::class);

        $fiscalYear->registerOpeningEntry([
            [
                'account_name' => 'その他の預金',
                'sub_account_name' => 'メインバンク',
                'amount' => 50000,
            ],
        ], $user);

        $subAccounts = $service->register($businessUnit, $fiscalYear, [
            ['label' => 'レジ現金', 'opening_balance' => 120000],
            ['label' => '金庫', 'opening_balance' => 80000],
        ], $user);

        $activeOpeningEntry = $fiscalYear->transactions()
            ->where('is_opening_entry', true)
            ->where('is_active', true)
            ->firstOrFail();

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $activeOpeningEntry->id,
            'sub_account_id' => $subAccounts[0]->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 120000,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $activeOpeningEntry->id,
            'sub_account_id' => $subAccounts[1]->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 80000,
        ]);

        $capitalSubAccount = $businessUnit->getSubAccountByName('元入金', '元入金');
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $activeOpeningEntry->id,
            'sub_account_id' => $capitalSubAccount?->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 250000,
        ]);
        $bankSubAccount = $businessUnit->getSubAccountByName('その他の預金', 'メインバンク');
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $activeOpeningEntry->id,
            'sub_account_id' => $bankSubAccount?->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 50000,
        ]);
    }

    #[Test]
    public function 期首残高が0円でも事業用現金を登録できる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '事業用現金登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);

        $subAccounts = app(CashOnHandRegistrationService::class)->register($businessUnit, $fiscalYear, [
            ['label' => 'レジ現金', 'opening_balance' => 0],
            ['label' => '金庫', 'opening_balance' => 0],
        ], $user);

        $cashAccount = $businessUnit->getAccountByName('現金');

        $this->assertCount(2, $subAccounts);
        $this->assertDatabaseHas('sub_accounts', [
            'account_id' => $cashAccount?->id,
            'name' => 'レジ現金',
        ]);
        $this->assertDatabaseHas('sub_accounts', [
            'account_id' => $cashAccount?->id,
            'name' => '金庫',
        ]);
        $this->assertDatabaseMissing('journal_entries', [
            'sub_account_id' => $subAccounts[0]->id,
        ]);
        $this->assertDatabaseMissing('journal_entries', [
            'sub_account_id' => $subAccounts[1]->id,
        ]);
    }

    #[Test]
    public function 別事業体の会計年度では登録を拒否する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '事業用現金登録テスト']);
        $otherBusinessUnit = $user->createBusinessUnitWithDefaults(['name' => '別事業体']);
        $otherFiscalYear = $otherBusinessUnit->createFiscalYear(2026, $user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('指定された会計年度は対象の事業体に属していません。');

        app(CashOnHandRegistrationService::class)->register(
            $businessUnit,
            $otherFiscalYear,
            [['label' => 'レジ現金', 'opening_balance' => 1000]],
            $user,
        );
    }

    #[Test]
    public function 既存の期首仕訳に貸方行が複数ある場合は登録を拒否する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '事業用現金登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);

        $openingEntry = $fiscalYear->registerOpeningEntry([
            [
                'account_name' => 'その他の預金',
                'sub_account_name' => 'メインバンク',
                'amount' => 50000,
            ],
        ], $user);

        $capitalSubAccount = $businessUnit->getSubAccountByName('元入金', '元入金');
        $openingEntry->journalEntries()->create([
            'sub_account_id' => $capitalSubAccount?->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 1,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('既存の期首仕訳に貸方行が複数存在するため、事業用現金の登録を続行できません。');

        app(CashOnHandRegistrationService::class)->register(
            $businessUnit,
            $fiscalYear,
            [['label' => 'レジ現金', 'opening_balance' => 1000]],
            $user,
        );
    }

    #[Test]
    public function 期首残高が欠落している場合は登録を拒否する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '事業用現金登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('期首残高を入力してください。');

        app(CashOnHandRegistrationService::class)->register(
            $businessUnit,
            $fiscalYear,
            [['label' => 'レジ現金']],
            $user,
        );
    }
}
