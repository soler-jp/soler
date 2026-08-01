<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\User;
use App\Services\TransactionRevisor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReviseOpeningEntryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 期首仕訳を_revisorで改訂できる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '期首改訂テスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $original = $fiscalYear->registerOpeningEntry([
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
                'amount' => 100000,
            ],
        ], $user);

        $this->assertTrue($original->is_opening_entry);
        $this->assertCount(2, $original->journalEntries);

        $debitSubAccount = $original->journalEntries
            ->firstWhere('type', JournalEntry::TYPE_DEBIT)->sub_account_id;
        $creditSubAccount = $original->journalEntries
            ->firstWhere('type', JournalEntry::TYPE_CREDIT)->sub_account_id;

        $revised = app(TransactionRevisor::class)->revise($original, $user, [
            'transaction' => [
                'revision_reason' => '期首残高の修正',
            ],
            'journal_entries' => [
                [
                    'sub_account_id' => $debitSubAccount,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'net_amount' => 200000,
                ],
                [
                    'sub_account_id' => $creditSubAccount,
                    'type' => JournalEntry::TYPE_CREDIT,
                    'net_amount' => 200000,
                ],
            ],
        ]);

        $original->refresh();

        $this->assertFalse($original->is_active);
        $this->assertTrue($revised->is_active);
        $this->assertTrue($revised->is_opening_entry);
        $this->assertSame($original->id, $revised->revised_from_transaction_id);
        $this->assertSame('期首残高の修正', $revised->revision_reason);
        $this->assertSame(200000, $revised->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT)->net_amount);
    }

    #[Test]
    public function 改訂された期首仕訳をさらに改訂できる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '連続改訂テスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $v1 = $fiscalYear->registerOpeningEntry([
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
                'amount' => 100000,
            ],
        ], $user);

        $debitSubId = $v1->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT)->sub_account_id;
        $creditSubId = $v1->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT)->sub_account_id;
        $revisor = app(TransactionRevisor::class);

        $v2 = $revisor->revise($v1, $user, [
            'transaction' => ['revision_reason' => '1回目の修正'],
            'journal_entries' => [
                ['sub_account_id' => $debitSubId, 'type' => JournalEntry::TYPE_DEBIT, 'net_amount' => 200000],
                ['sub_account_id' => $creditSubId, 'type' => JournalEntry::TYPE_CREDIT, 'net_amount' => 200000],
            ],
        ]);

        $v3 = $revisor->revise($v2, $user, [
            'transaction' => ['revision_reason' => '2回目の修正'],
            'journal_entries' => [
                ['sub_account_id' => $debitSubId, 'type' => JournalEntry::TYPE_DEBIT, 'net_amount' => 300000],
                ['sub_account_id' => $creditSubId, 'type' => JournalEntry::TYPE_CREDIT, 'net_amount' => 300000],
            ],
        ]);

        $v1->refresh();
        $v2->refresh();

        $this->assertFalse($v1->is_active);
        $this->assertFalse($v2->is_active);
        $this->assertTrue($v3->is_active);
        $this->assertSame($v2->id, $v3->revised_from_transaction_id);

        $activeOpeningEntries = $fiscalYear->transactions()
            ->where('is_opening_entry', true)
            ->where('is_active', true)
            ->get();
        $this->assertCount(1, $activeOpeningEntries);
        $this->assertTrue($activeOpeningEntries->first()->is($v3));
    }

    #[Test]
    public function 改訂後もis_opening_entryフラグが引き継がれる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'フラグ引継テスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $original = $fiscalYear->registerOpeningEntry([
            [
                'account_name' => 'その他の預金',
                'sub_account_name' => 'メインバンク',
                'amount' => 500000,
            ],
        ], $user);

        $debitSubId = $original->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT)->sub_account_id;
        $creditSubId = $original->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT)->sub_account_id;

        $revised = app(TransactionRevisor::class)->revise($original, $user, [
            'transaction' => ['revision_reason' => '銀行口座追加'],
            'journal_entries' => [
                ['sub_account_id' => $debitSubId, 'type' => JournalEntry::TYPE_DEBIT, 'net_amount' => 600000],
                ['sub_account_id' => $creditSubId, 'type' => JournalEntry::TYPE_CREDIT, 'net_amount' => 600000],
            ],
        ]);

        $this->assertTrue($revised->is_opening_entry);
        $this->assertSame($fiscalYear->start_date->toDateString(), $revised->date->toDateString());
        $this->assertSame('期首残高設定', $revised->description);
    }

    #[Test]
    public function 他ユーザーは期首仕訳を改訂できない(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '認可テスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $original = $fiscalYear->registerOpeningEntry([
            ['account_name' => '現金', 'sub_account_name' => '現金', 'amount' => 100000],
        ], $user);

        $debitSubId = $original->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT)->sub_account_id;
        $creditSubId = $original->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT)->sub_account_id;

        $this->expectException(AuthorizationException::class);

        app(TransactionRevisor::class)->revise($original, $otherUser, [
            'transaction' => ['revision_reason' => '不正修正'],
            'journal_entries' => [
                ['sub_account_id' => $debitSubId, 'type' => JournalEntry::TYPE_DEBIT, 'net_amount' => 200000],
                ['sub_account_id' => $creditSubId, 'type' => JournalEntry::TYPE_CREDIT, 'net_amount' => 200000],
            ],
        ]);
    }
}
