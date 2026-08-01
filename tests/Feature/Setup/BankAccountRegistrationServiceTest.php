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

        $subAccount = app(BankAccountRegistrationService::class)
            ->register($businessUnit, $fiscalYear, 'ひかり青空銀行', 120000, $user);

        $bankAccount = $businessUnit->getAccountByName('その他の預金');
        $capitalSubAccount = $businessUnit->getSubAccountByName('元入金', '元入金');

        $this->assertSame('ひかり青空銀行', $subAccount->name);
        $this->assertSame($bankAccount?->id, $subAccount->account_id);
        $this->assertDatabaseHas('sub_accounts', [
            'id' => $subAccount->id,
            'account_id' => $bankAccount?->id,
            'name' => 'ひかり青空銀行',
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $subAccount->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 120000,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $capitalSubAccount?->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 120000,
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
            ->register($businessUnit, $fiscalYear, 'ひかり青空銀行', 120000, $otherUser);
    }

    #[Test]
    public function 同名の銀行口座は重複登録できない(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '銀行口座登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);
        $service = app(BankAccountRegistrationService::class);

        $service->register($businessUnit, $fiscalYear, 'ひかり青空銀行', 120000, $user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('同名の銀行口座はすでに登録されています。');

        $service->register($businessUnit, $fiscalYear, 'ひかり青空銀行', 120000, $user);
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

        $subAccount = $service->register($businessUnit, $fiscalYear, 'ひかり青空銀行', 120000, $user);

        $activeOpeningEntry = $fiscalYear->transactions()
            ->where('is_opening_entry', true)
            ->where('is_active', true)
            ->firstOrFail();

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $activeOpeningEntry->id,
            'sub_account_id' => $subAccount->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 120000,
        ]);

        $capitalSubAccount = $businessUnit->getSubAccountByName('元入金', '元入金');
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $activeOpeningEntry->id,
            'sub_account_id' => $capitalSubAccount?->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 170000,
        ]);
    }

    #[Test]
    public function 期首残高が0円でも銀行口座を登録できる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '銀行口座登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);

        $subAccount = app(BankAccountRegistrationService::class)
            ->register($businessUnit, $fiscalYear, 'みらい星銀行', 0, $user);

        $bankAccount = $businessUnit->getAccountByName('その他の預金');

        $this->assertSame('みらい星銀行', $subAccount->name);
        $this->assertSame($bankAccount?->id, $subAccount->account_id);
        $this->assertDatabaseHas('sub_accounts', [
            'id' => $subAccount->id,
            'account_id' => $bankAccount?->id,
            'name' => 'みらい星銀行',
        ]);
        $this->assertDatabaseMissing('journal_entries', [
            'sub_account_id' => $subAccount->id,
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
            ->register($businessUnit, $fiscalYear, '   ', 1000, $user);
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
            ->register($businessUnit, $fiscalYear, 'ひかり青空銀行', -1, $user);
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
            ->register($businessUnit, $otherFiscalYear, 'ひかり青空銀行', 1000, $user);
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
            ->register($businessUnit, $fiscalYear, 'ひかり青空銀行', 1000, $user);
    }
}
