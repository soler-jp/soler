<?php

namespace Tests\Feature;

use App\Models\CreditCard;
use App\Models\CreditCardImportBatch;
use App\Models\CreditCardStatement;
use App\Models\CreditCardStatementLine;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CreditCardImport\CreditCardImportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreditCardImportServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function クレジットカード明細を取り込んでstatement_batch_lineを保存できる(): void
    {
        $user = User::factory()->create();
        $creditCard = CreditCard::factory()->create([
            'business_unit_id' => $user->createBusinessUnitWithDefaults(['name' => '取込テスト事業'])->id,
            'parser_key' => 'orico_csv_v1',
        ]);

        $batch = $this->app->make(CreditCardImportService::class)->import(
            $creditCard,
            $this->fixture('orico-visa.csv'),
            'orico-visa.csv',
            $user,
        );

        $statement = CreditCardStatement::query()->firstOrFail();

        $this->assertSame(CreditCardImportBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame('orico_csv_v1', $batch->parser_key);
        $this->assertSame(5, $batch->row_count);
        $this->assertSame(5, $batch->success_count);
        $this->assertSame(0, $batch->duplicate_count);
        $this->assertTrue($batch->is_active);
        $this->assertSame($creditCard->id, $statement->credit_card_id);
        $this->assertSame(2026, $statement->statement_year);
        $this->assertSame(7, $statement->statement_month);
        $this->assertSame('2026-07-27', $statement->billed_on?->toDateString());
        $this->assertSame(15468, $statement->total_amount);
        $this->assertSame(5, $statement->line_count);
        $this->assertCount(5, $statement->lines);
        $this->assertSame(CreditCardStatementLine::STATUS_UNREVIEWED, $statement->lines[0]->status);
    }

    #[Test]
    public function 同一import内の内容一致明細も全件unreviewedで保存できる(): void
    {
        $user = User::factory()->create();
        $creditCard = CreditCard::factory()->create([
            'business_unit_id' => $user->createBusinessUnitWithDefaults(['name' => '重複テスト事業'])->id,
            'parser_key' => 'rakuten_csv_v1',
        ]);

        $csvContents = <<<'CSV'
利用日,利用店名・商品名,利用者,支払方法,利用金額,手数料/利息,支払総額,支払月,7月支払金額,当月請求額,8月繰越残高,8月以降請求額
2026/06/28,ＶＩＳＡ国内利用　VS STEREO,本人,1回払い,2000,0,2000,7月,2000,2000,0,
2026/06/28,ＶＩＳＡ国内利用　VS STEREO,本人,1回払い,2000,0,2000,7月,2000,2000,0,
CSV;

        $batch = $this->app->make(CreditCardImportService::class)->import(
            $creditCard,
            $csvContents,
            'duplicate-rakuten.csv',
            $user,
        );

        $lines = CreditCardStatementLine::query()->orderBy('line_number')->get();

        $this->assertSame(2, $batch->row_count);
        $this->assertSame(2, $batch->success_count);
        $this->assertSame(0, $batch->duplicate_count);
        $this->assertCount(2, $lines);
        $this->assertSame(CreditCardStatementLine::STATUS_UNREVIEWED, $lines[0]->status);
        $this->assertSame(CreditCardStatementLine::STATUS_UNREVIEWED, $lines[1]->status);
        $this->assertNotSame($lines[0]->fingerprint, $lines[1]->fingerprint);
    }

    #[Test]
    public function 同月の再importで旧batchと関連line_transactionを無効化する(): void
    {
        $user = User::factory()->create();
        $creditCard = CreditCard::factory()->create([
            'business_unit_id' => $user->createBusinessUnitWithDefaults(['name' => '再取込テスト事業'])->id,
            'parser_key' => 'orico_csv_v1',
        ]);

        $service = $this->app->make(CreditCardImportService::class);
        $firstBatch = $service->import($creditCard, $this->fixture('orico-visa.csv'), 'orico-visa.csv', $user);

        $transaction = Transaction::factory()->create([
            'credit_card_import_batch_id' => $firstBatch->id,
        ]);

        $firstLine = CreditCardStatementLine::query()->where('credit_card_import_batch_id', $firstBatch->id)->firstOrFail();
        $firstLine->forceFill([
            'transaction_id' => $transaction->id,
            'status' => CreditCardStatementLine::STATUS_REGISTERED,
        ])->save();

        $secondBatch = $service->import($creditCard, $this->fixture('orico-visa.csv'), 'orico-visa-v2.csv', $user);

        $this->assertFalse($firstBatch->fresh()->is_active);
        $this->assertFalse($firstLine->fresh()->is_active);
        $this->assertFalse($transaction->fresh()->is_active);
        $this->assertSame('CSVを再取り込みしました。', $firstBatch->fresh()->deactivation_reason);
        $this->assertTrue($secondBatch->fresh()->is_active);
        $this->assertSame(5, $secondBatch->fresh()->row_count);
        $this->assertSame(2026, $secondBatch->statement->statement_year);
        $this->assertSame(7, $secondBatch->statement->statement_month);
    }

    #[Test]
    public function 未対応parser_keyでは何も保存しない(): void
    {
        $user = User::factory()->create();
        $creditCard = CreditCard::factory()->create([
            'business_unit_id' => $user->createBusinessUnitWithDefaults(['name' => '未対応パーサ事業'])->id,
            'parser_key' => 'unsupported_parser',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported credit card parser key [unsupported_parser].');

        try {
            $this->app->make(CreditCardImportService::class)->import(
                $creditCard,
                $this->fixture('orico-master.csv'),
                'orico-master.csv',
                $user,
            );
        } finally {
            $this->assertDatabaseCount('credit_card_statements', 0);
            $this->assertDatabaseCount('credit_card_import_batches', 0);
            $this->assertDatabaseCount('credit_card_statement_lines', 0);
        }
    }

    #[Test]
    public function 壊れたcsvでは何も保存しない(): void
    {
        $user = User::factory()->create();
        $creditCard = CreditCard::factory()->create([
            'business_unit_id' => $user->createBusinessUnitWithDefaults(['name' => '壊れたCSV事業'])->id,
            'parser_key' => 'orico_csv_v1',
        ]);

        $csv = <<<'CSV'
登録番号,T12345
お支払日,2025年9月29日
ご請求総額,"\1,444"
<利用明細>
ご利用日,ご利用先など,新旧,ご利用者,支払開始年月,支払区分,お支払回数,何回目,ご利用金額,手数料・利息,年利%,その他,当月ご請求額,翌月繰越残高
2025年8月19日,コンビニSOLER,*,本人,2025年9月,アド,1,1,abc,,,\0,\210,\0
CSV;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Failed to parse ORICO statement line.');

        try {
            $this->app->make(CreditCardImportService::class)->import(
                $creditCard,
                $csv,
                'broken-orico.csv',
                $user,
            );
        } finally {
            $this->assertDatabaseCount('credit_card_statements', 0);
            $this->assertDatabaseCount('credit_card_import_batches', 0);
            $this->assertDatabaseCount('credit_card_statement_lines', 0);
        }
    }

    #[Test]
    public function カード設定と違う形式のcsvを渡すと実際の形式を示して例外になる(): void
    {
        $user = User::factory()->create();
        $creditCard = CreditCard::factory()->create([
            'business_unit_id' => $user->createBusinessUnitWithDefaults(['name' => '形式不一致事業'])->id,
            'parser_key' => 'orico_csv_v1',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('カード設定のCSV形式と一致しません。渡された形式は aeon_csv_v1 形式です。');

        try {
            $this->app->make(CreditCardImportService::class)->import(
                $creditCard,
                $this->fixture('aeon.csv'),
                'aeon.csv',
                $user,
            );
        } finally {
            $this->assertDatabaseCount('credit_card_statements', 0);
            $this->assertDatabaseCount('credit_card_import_batches', 0);
            $this->assertDatabaseCount('credit_card_statement_lines', 0);
        }
    }

    #[Test]
    public function generic_parser設定なら形式違いでも自動判別で取り込める(): void
    {
        $user = User::factory()->create();
        $creditCard = CreditCard::factory()->create([
            'business_unit_id' => $user->createBusinessUnitWithDefaults(['name' => 'generic事業'])->id,
            'parser_key' => 'generic_csv_v1',
        ]);

        $batch = $this->app->make(CreditCardImportService::class)->import(
            $creditCard,
            $this->fixture('aeon.csv'),
            'aeon.csv',
            $user,
        );

        $this->assertSame('aeon_csv_v1', $batch->parser_key);
        $this->assertSame(2, $batch->row_count);
        $this->assertSame(2026, $batch->statement->statement_year);
        $this->assertSame(7, $batch->statement->statement_month);
    }

    #[Test]
    public function generic_parser設定で判別できない_cs_vは例外になり何も保存しない(): void
    {
        $user = User::factory()->create();
        $creditCard = CreditCard::factory()->create([
            'business_unit_id' => $user->createBusinessUnitWithDefaults(['name' => 'generic判別不可事業'])->id,
            'parser_key' => 'generic_csv_v1',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to detect supported credit card CSV format.');

        try {
            $this->app->make(CreditCardImportService::class)->import(
                $creditCard,
                "foo,bar,baz\n1,2,3\n",
                'unknown.csv',
                $user,
            );
        } finally {
            $this->assertDatabaseCount('credit_card_statements', 0);
            $this->assertDatabaseCount('credit_card_import_batches', 0);
            $this->assertDatabaseCount('credit_card_statement_lines', 0);
        }
    }

    #[Test]
    public function 再import時に決算済み取引があるとロールバックされる(): void
    {
        $user = User::factory()->create();
        $creditCard = CreditCard::factory()->create([
            'business_unit_id' => $user->createBusinessUnitWithDefaults(['name' => '決算済み再取込事業'])->id,
            'parser_key' => 'orico_csv_v1',
        ]);

        $service = $this->app->make(CreditCardImportService::class);
        $firstBatch = $service->import($creditCard, $this->fixture('orico-visa.csv'), 'orico-visa.csv', $user);
        $statement = $firstBatch->statement()->firstOrFail();
        $originalTotalAmount = $statement->total_amount;

        $closedFiscalYear = FiscalYear::factory()->create([
            'business_unit_id' => $creditCard->business_unit_id,
            'is_closed' => true,
        ]);

        $transaction = Transaction::factory()->create([
            'fiscal_year_id' => $closedFiscalYear->id,
            'credit_card_import_batch_id' => $firstBatch->id,
        ]);

        CreditCardStatementLine::query()
            ->where('credit_card_import_batch_id', $firstBatch->id)
            ->firstOrFail()
            ->forceFill([
                'transaction_id' => $transaction->id,
                'status' => CreditCardStatementLine::STATUS_REGISTERED,
            ])
            ->save();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('決算済みの会計年度に属する取引は無効化できません。');

        try {
            $service->import($creditCard, $this->fixture('orico-visa.csv'), 'orico-visa-v2.csv', $user);
        } finally {
            $this->assertTrue($firstBatch->fresh()->is_active);
            $this->assertTrue($transaction->fresh()->is_active);
            $this->assertSame(1, CreditCardImportBatch::query()->count());
            $this->assertSame(5, CreditCardStatementLine::query()->active()->count());
            $this->assertSame($originalTotalAmount, $statement->fresh()->total_amount);
            $this->assertSame('orico-visa.csv', $firstBatch->fresh()->source_filename);
        }
    }

    private function fixture(string $filename): string
    {
        $path = base_path('tests/Fixtures/CreditCardCsv/'.$filename);
        $contents = file_get_contents($path);

        $this->assertNotFalse($contents, sprintf('Failed to read fixture [%s].', $path));

        return $contents;
    }

    #[Test]
    public function 他ユーザーは明細を取り込めない(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $creditCard = CreditCard::factory()->create([
            'business_unit_id' => $user->createBusinessUnitWithDefaults(['name' => '取込認可テスト'])->id,
            'parser_key' => 'rakuten_csv_v1',
        ]);

        $this->expectException(AuthorizationException::class);

        // 認可は parse より前に走るため、CSV 内容の妥当性までは問われない
        $this->app->make(CreditCardImportService::class)->import(
            $creditCard,
            'dummy',
            'rakuten.csv',
            $otherUser,
        );
    }

    #[Test]
    public function actorがnullなら取り込めない(): void
    {
        $user = User::factory()->create();
        $creditCard = CreditCard::factory()->create([
            'business_unit_id' => $user->createBusinessUnitWithDefaults(['name' => 'システム取込テスト'])->id,
            'parser_key' => 'rakuten_csv_v1',
        ]);

        $this->expectException(AuthorizationException::class);

        // 認可は parse より前に走るため、CSV 内容の妥当性までは問われない
        $this->app->make(CreditCardImportService::class)->import(
            $creditCard,
            'dummy',
            'rakuten.csv',
            null,
        );
    }
}
