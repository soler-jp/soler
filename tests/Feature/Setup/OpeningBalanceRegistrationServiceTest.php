<?php

namespace Tests\Feature\Setup;

use App\Models\JournalEntry;
use App\Models\User;
use App\Services\OpeningBalanceRegistrationService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpeningBalanceRegistrationServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 開始残高から期首仕訳を登録できる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '開始残高登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);

        $transaction = app(OpeningBalanceRegistrationService::class)->register(
            $businessUnit,
            $fiscalYear,
            [
                'asset_accounts' => [
                    ['account_name' => '売掛金', 'amount' => 120000],
                    ['account_name' => '棚卸資産', 'amount' => 30000],
                ],
                'custom_asset_accounts' => [
                    ['account_name' => '敷金', 'amount' => 50000],
                ],
                'liability_accounts' => [
                    ['account_name' => '借入金', 'amount' => 70000],
                ],
                'custom_liability_accounts' => [
                    ['account_name' => '未払費用', 'amount' => 10000],
                ],
            ],
            $user,
        );

        $this->assertNotNull($transaction);
        $this->assertTrue((bool) $transaction?->is_opening_entry);
        $this->assertDatabaseHas('accounts', [
            'business_unit_id' => $businessUnit->id,
            'name' => '敷金',
            'type' => 'asset',
        ]);
        $this->assertDatabaseHas('accounts', [
            'business_unit_id' => $businessUnit->id,
            'name' => '未払費用',
            'type' => 'liability',
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $transaction?->id,
            'sub_account_id' => $businessUnit->getSubAccountByName('売掛金', '売掛金')?->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 120000,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $transaction?->id,
            'sub_account_id' => $businessUnit->getSubAccountByName('借入金', '借入金')?->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 70000,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $transaction?->id,
            'sub_account_id' => $businessUnit->getSubAccountByName('元入金', '元入金')?->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 120000,
        ]);
    }

    #[Test]
    public function 負債超過の開始残高では元入金を借方で登録する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '負債超過開始残高']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);

        $transaction = app(OpeningBalanceRegistrationService::class)->register(
            $businessUnit,
            $fiscalYear,
            [
                'asset_accounts' => [
                    ['account_name' => '売掛金', 'amount' => 10000],
                ],
                'custom_asset_accounts' => [],
                'liability_accounts' => [
                    ['account_name' => '借入金', 'amount' => 50000],
                ],
                'custom_liability_accounts' => [],
            ],
            $user,
        );

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $transaction?->id,
            'sub_account_id' => $businessUnit->getSubAccountByName('元入金', '元入金')?->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 40000,
        ]);
    }

    #[Test]
    public function 既存の期首仕訳がある場合は開始残高を改訂する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '開始残高改訂テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);

        // 事業用現金など、開始残高フロー以外が登録した既存の期首残高。
        $original = $fiscalYear->registerOpeningEntry([
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
                'amount' => 50000,
            ],
        ], $user);

        $revised = app(OpeningBalanceRegistrationService::class)->register(
            $businessUnit,
            $fiscalYear,
            [
                'asset_accounts' => [
                    ['account_name' => '売掛金', 'amount' => 120000],
                ],
                'custom_asset_accounts' => [],
                'liability_accounts' => [
                    ['account_name' => '借入金', 'amount' => 70000],
                ],
                'custom_liability_accounts' => [],
            ],
            $user,
        );

        $this->assertNotNull($revised);
        $this->assertNotSame($original?->id, $revised?->id);
        $this->assertDatabaseHas('transactions', [
            'id' => $original?->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('transactions', [
            'id' => $revised?->id,
            'is_active' => true,
            'revision_reason' => '開始残高の資産・負債を更新',
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $revised?->id,
            'sub_account_id' => $businessUnit->getSubAccountByName('売掛金', '売掛金')?->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 120000,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $revised?->id,
            'sub_account_id' => $businessUnit->getSubAccountByName('借入金', '借入金')?->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 70000,
        ]);

        // 開始残高フロー以外が登録した現金の借方行は保持される。
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $revised?->id,
            'sub_account_id' => $businessUnit->getSubAccountByName('現金', '現金')?->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 50000,
        ]);

        // 元入金は 現金50000 + 売掛金120000 - 借入金70000 = 100000 で再計算される。
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $revised?->id,
            'sub_account_id' => $businessUnit->getSubAccountByName('元入金', '元入金')?->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 100000,
        ]);
    }

    #[Test]
    public function actorが事業体にアクセスできない場合は開始残高登録を拒否する(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '開始残高登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('この事業体に開始残高を登録する権限がありません。');

        app(OpeningBalanceRegistrationService::class)->register(
            $businessUnit,
            $fiscalYear,
            $this->emptyInputs(),
            $otherUser,
        );
    }

    #[Test]
    public function 別事業体の会計年度では登録を拒否する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '開始残高登録テスト']);
        $otherBusinessUnit = $user->createBusinessUnitWithDefaults(['name' => '別事業体']);
        $otherFiscalYear = $otherBusinessUnit->createFiscalYear(2026, $user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('指定された会計年度は対象の事業体に属していません。');

        app(OpeningBalanceRegistrationService::class)->register(
            $businessUnit,
            $otherFiscalYear,
            $this->emptyInputs(),
            $user,
        );
    }

    #[Test]
    public function 自由入力が既存の別区分勘定科目と同名なら登録を拒否する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '開始残高登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('同名の勘定科目が別の区分ですでに存在します。');

        app(OpeningBalanceRegistrationService::class)->register(
            $businessUnit,
            $fiscalYear,
            [
                'asset_accounts' => [],
                'custom_asset_accounts' => [
                    ['account_name' => '未払金', 'amount' => 10000],
                ],
                'liability_accounts' => [],
                'custom_liability_accounts' => [],
            ],
            $user,
        );
    }

    #[Test]
    public function 固定入力に不正な勘定科目がある場合は登録を拒否する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '開始残高登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('開始残高の勘定科目が不正です。');

        app(OpeningBalanceRegistrationService::class)->register(
            $businessUnit,
            $fiscalYear,
            [
                'asset_accounts' => [
                    ['account_name' => '売上高', 'amount' => 10000],
                ],
                'custom_asset_accounts' => [],
                'liability_accounts' => [],
                'custom_liability_accounts' => [],
            ],
            $user,
        );
    }

    /**
     * @return array{
     *     asset_accounts: array<int, array{account_name: string, amount: int}>,
     *     custom_asset_accounts: array<int, array{account_name: string, amount: int}>,
     *     liability_accounts: array<int, array{account_name: string, amount: int}>,
     *     custom_liability_accounts: array<int, array{account_name: string, amount: int}>
     * }
     */
    private function emptyInputs(): array
    {
        return [
            'asset_accounts' => [],
            'custom_asset_accounts' => [],
            'liability_accounts' => [],
            'custom_liability_accounts' => [],
        ];
    }
}
