<?php

namespace Tests\Feature\Setup;

use App\Models\JournalEntry;
use App\Models\User;
use App\Services\BankAccountRegistrationService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BankAccountRegistrationServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 銀行名からその他の預金の補助科目を登録できる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '銀行口座登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);

        $subAccounts = app(BankAccountRegistrationService::class)
            ->register($businessUnit, $fiscalYear, [
                ['label' => 'ひかり青空銀行', 'opening_balance' => 120000],
                ['label' => 'みらい星銀行', 'opening_balance' => 80000],
            ], $user);

        $bankAccount = $businessUnit->getAccountByName('その他の預金');
        $capitalSubAccount = $businessUnit->getSubAccountByName('元入金', '元入金');

        $this->assertCount(2, $subAccounts);
        $this->assertSame(['ひかり青空銀行', 'みらい星銀行'], array_map(fn ($subAccount) => $subAccount->name, $subAccounts));
        $this->assertDatabaseHas('sub_accounts', [
            'account_id' => $bankAccount?->id,
            'name' => 'ひかり青空銀行',
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
    public function actorが事業体にアクセスできない場合は銀行口座登録を拒否する(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '銀行口座登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('この事業体に銀行口座を登録する権限がありません。');

        app(BankAccountRegistrationService::class)
            ->register($businessUnit, $fiscalYear, [['label' => 'ひかり青空銀行', 'opening_balance' => 120000]], $otherUser);
    }

    #[Test]
    public function 同名の銀行口座は重複登録できない(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '銀行口座登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);
        $service = app(BankAccountRegistrationService::class);

        $service->register($businessUnit, $fiscalYear, [['label' => 'ひかり青空銀行', 'opening_balance' => 120000]], $user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('同名の銀行口座はすでに登録されています。');

        $service->register($businessUnit, $fiscalYear, [['label' => 'ひかり青空銀行', 'opening_balance' => 120000]], $user);
    }

    #[Test]
    public function 同一リクエスト内で同名の銀行口座は登録できない(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '銀行口座登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('同名の銀行口座は同時に登録できません。');

        app(BankAccountRegistrationService::class)->register($businessUnit, $fiscalYear, [
            ['label' => 'ひかり青空銀行', 'opening_balance' => 120000],
            ['label' => 'ひかり青空銀行', 'opening_balance' => 80000],
        ], $user);
    }

    #[Test]
    public function 既存の期首仕訳がある場合は銀行口座と元入金の金額を改訂する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '銀行口座登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);
        $service = app(BankAccountRegistrationService::class);

        $fiscalYear->registerOpeningEntry([
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
                'amount' => 50000,
            ],
        ], $user);

        $subAccounts = $service->register($businessUnit, $fiscalYear, [
            ['label' => 'ひかり青空銀行', 'opening_balance' => 120000],
            ['label' => 'みらい星銀行', 'opening_balance' => 80000],
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
        $cashSubAccount = $businessUnit->getSubAccountByName('現金', '現金');
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $activeOpeningEntry->id,
            'sub_account_id' => $cashSubAccount?->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 50000,
        ]);
    }

    #[Test]
    public function 期首残高が0円でも銀行口座を登録できる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '銀行口座登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);

        $subAccounts = app(BankAccountRegistrationService::class)
            ->register($businessUnit, $fiscalYear, [
                ['label' => 'みらい星銀行', 'opening_balance' => 0],
                ['label' => '地方信用金庫', 'opening_balance' => 0],
            ], $user);

        $bankAccount = $businessUnit->getAccountByName('その他の預金');

        $this->assertCount(2, $subAccounts);
        $this->assertDatabaseHas('sub_accounts', [
            'account_id' => $bankAccount?->id,
            'name' => 'みらい星銀行',
        ]);
        $this->assertDatabaseHas('sub_accounts', [
            'account_id' => $bankAccount?->id,
            'name' => '地方信用金庫',
        ]);
        $this->assertDatabaseMissing('journal_entries', [
            'sub_account_id' => $subAccounts[0]->id,
        ]);
        $this->assertDatabaseMissing('journal_entries', [
            'sub_account_id' => $subAccounts[1]->id,
        ]);
    }

    #[Test]
    public function 銀行名が空白のみの場合は登録を拒否する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '銀行口座登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('銀行名を入力してください。');

        app(BankAccountRegistrationService::class)
            ->register($businessUnit, $fiscalYear, [['label' => '   ', 'opening_balance' => 1000]], $user);
    }

    #[Test]
    public function 期首残高が負数の場合は登録を拒否する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '銀行口座登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('期首残高は0円以上で入力してください。');

        app(BankAccountRegistrationService::class)
            ->register($businessUnit, $fiscalYear, [['label' => 'ひかり青空銀行', 'opening_balance' => -1]], $user);
    }

    #[Test]
    public function 期首残高が欠落している場合は登録を拒否する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '銀行口座登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('期首残高を入力してください。');

        app(BankAccountRegistrationService::class)
            ->register($businessUnit, $fiscalYear, [['label' => 'ひかり青空銀行']], $user);
    }

    #[Test]
    public function 別事業体の会計年度では登録を拒否する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '銀行口座登録テスト']);
        $otherBusinessUnit = $user->createBusinessUnitWithDefaults(['name' => '別事業体']);
        $otherFiscalYear = $otherBusinessUnit->createFiscalYear(2026, $user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('指定された会計年度は対象の事業体に属していません。');

        app(BankAccountRegistrationService::class)
            ->register($businessUnit, $otherFiscalYear, [['label' => 'ひかり青空銀行', 'opening_balance' => 1000]], $user);
    }

    #[Test]
    public function 既存の期首仕訳に貸方行が複数ある場合は登録を拒否する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '銀行口座登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);

        $openingEntry = $fiscalYear->registerOpeningEntry([
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
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
        $this->expectExceptionMessage('既存の期首仕訳に貸方行が複数存在するため、銀行口座の登録を続行できません。');

        app(BankAccountRegistrationService::class)
            ->register($businessUnit, $fiscalYear, [['label' => 'ひかり青空銀行', 'opening_balance' => 1000]], $user);
    }
}
