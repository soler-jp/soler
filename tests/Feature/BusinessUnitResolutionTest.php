<?php

namespace Tests\Feature;

use App\Models\BlueReturnInput;
use App\Models\Counterparty;
use App\Models\CreditCard;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\CreditCardImport\CreditCardImportService;
use App\Services\DepreciationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BusinessUnitResolutionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 各モデルがapi経由の生成から所属事業体を解決できる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '解決テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $expenseSubAccount = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $cashSubAccount = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $cardLiabilitySubAccount = $unit->getAccountByName('未払金')->subAccounts()->firstOrFail();

        // 取引・仕訳・取引先（counterparty_name を渡すと取引登録の中で作成される）
        $transaction = $fiscalYear->registerTransaction(
            [
                'date' => '2025-01-10',
                'description' => '解決テスト取引',
                'counterparty_name' => '解決テスト取引先',
            ],
            [
                [
                    'sub_account_id' => $expenseSubAccount->id,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'net_amount' => 1000,
                ],
                [
                    'sub_account_id' => $cashSubAccount->id,
                    'type' => JournalEntry::TYPE_CREDIT,
                    'net_amount' => 1000,
                ],
            ]
        );
        $journalEntry = $transaction->journalEntries()->firstOrFail();

        // 取引先と適格判定イベント
        $counterparty = $unit->counterparties()->firstOrFail();
        $counterparty->setQualificationStatus(Counterparty::QUALIFICATION_STATUS_QUALIFIED);
        $qualificationEvent = $counterparty->qualificationEvents()->firstOrFail();

        // 固定資産と減価償却エントリ
        $fixedAsset = app(DepreciationService::class)->registerNewStandardCar(
            $fiscalYear,
            $cashSubAccount,
            [
                'name' => 'PRIUS',
                'acquisition_date' => '2025-10-03',
                'taxable_amount' => 3_000_000,
                'tax_amount' => 300_000,
            ],
            [
                'date' => '2025-10-03',
                'description' => 'PRIUSを購入',
            ],
        );
        $depreciationEntry = $fixedAsset->depreciationEntries()->firstOrFail();

        // 定期取引計画
        $plan = $unit->createRecurringTransactionPlan([
            'name' => '定期費用',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'is_income' => false,
            'debit_sub_account_id' => $expenseSubAccount->id,
            'credit_sub_account_id' => $cashSubAccount->id,
            'amount' => 1000,
            'tax_amount' => 0,
        ]);

        // 決算書入力
        $blueReturnInput = $fiscalYear->saveBlueReturnInput(BlueReturnInput::KEY_RENT_EXPENSES, [
            'rows' => [
                [
                    'address' => '東京都千代田区1-1-1',
                    'name' => '株式会社サンプル',
                    'rent_amount' => 120_000,
                    'deductible_amount' => 90_000,
                ],
            ],
        ]);

        // クレジットカードと明細取込（statement / batch / line）
        $creditCard = CreditCard::create([
            'business_unit_id' => $unit->id,
            'name' => '事業用楽天カード',
            'issuer_name' => 'Rakuten',
            'network' => 'visa',
            'last_four' => '9876',
            'ownership_type' => CreditCard::OWNERSHIP_TYPE_BUSINESS,
            'parser_key' => 'rakuten_csv_v1',
            'liability_sub_account_id' => $cardLiabilitySubAccount->id,
            'is_active' => true,
        ]);

        $csvContents = <<<'CSV'
利用日,利用店名・商品名,利用者,支払方法,利用金額,手数料/利息,支払総額,支払月,7月支払金額,当月請求額,8月繰越残高,8月以降請求額
2026/06/28,ＶＩＳＡ国内利用　VS STEREO,本人,1回払い,2000,0,2000,7月,2000,2000,0,
CSV;

        $batch = app(CreditCardImportService::class)->import(
            $creditCard,
            $csvContents,
            'rakuten.csv',
            $user,
        );
        $statement = $batch->statement()->firstOrFail();
        $line = $statement->lines()->firstOrFail();

        $models = [
            $unit,
            $fiscalYear,
            $expenseSubAccount->account,
            $expenseSubAccount,
            $fixedAsset,
            $depreciationEntry,
            $counterparty,
            $qualificationEvent,
            $plan,
            $transaction,
            $journalEntry,
            $blueReturnInput,
            $creditCard,
            $statement,
            $batch,
            $line,
        ];

        foreach ($models as $model) {
            $this->assertTrue(
                $model->resolveBusinessUnit()->is($unit),
                $model::class.' failed to resolve its business unit.'
            );
        }
    }
}
