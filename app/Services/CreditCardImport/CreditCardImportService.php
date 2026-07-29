<?php

namespace App\Services\CreditCardImport;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Data\ParsedCreditCardStatement;
use App\Data\ParsedCreditCardStatementLine;
use App\Models\CreditCard;
use App\Models\CreditCardImportBatch;
use App\Models\CreditCardStatement;
use App\Models\CreditCardStatementLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreditCardImportService
{
    use AuthorizesBusinessUnitAccess;

    public function __construct(
        private readonly CreditCardCsvParserRegistry $parserRegistry,
    ) {}

    /**
     * @param  array{
     *     statement_year?: int,
     *     statement_month?: int,
     *     billed_on?: string,
     *     paid_on?: string,
     *     period_start_on?: string,
     *     period_end_on?: string
     * }  $overrides
     */
    public function import(
        CreditCard $creditCard,
        string $csvContents,
        string $sourceFilename,
        ?User $uploadedBy = null,
        array $overrides = [],
    ): CreditCardImportBatch {
        $this->authorizeBusinessUnitAccess($creditCard, $uploadedBy, 'このクレジットカードに明細を取り込む権限がありません。');

        $parser = $this->resolveParser($creditCard, $csvContents);
        $parsedStatement = $parser->parse($csvContents, $overrides);

        return DB::transaction(function () use (
            $creditCard,
            $csvContents,
            $sourceFilename,
            $uploadedBy,
            $parsedStatement,
            $parser,
        ): CreditCardImportBatch {
            $statement = $this->upsertStatement($creditCard, $parsedStatement);

            $statement->activeImportBatches()
                ->get()
                ->each(fn (CreditCardImportBatch $batch) => $batch->deactivate($uploadedBy, 'CSVを再取り込みしました。'));

            $batch = $statement->importBatches()->create([
                'uploaded_by' => $uploadedBy?->id,
                'source_filename' => $sourceFilename,
                'source_hash' => hash('sha256', $csvContents),
                'parser_key' => $parser->key(),
                'status' => CreditCardImportBatch::STATUS_PROCESSING,
                'is_active' => true,
                'row_count' => $parsedStatement->lineCount(),
                'success_count' => 0,
                'duplicate_count' => 0,
                'error_count' => 0,
            ]);

            $successCount = $this->storeStatementLines($statement, $batch, $parsedStatement);

            $batch->forceFill([
                'status' => CreditCardImportBatch::STATUS_COMPLETED,
                'success_count' => $successCount,
                'duplicate_count' => 0,
                'imported_at' => now(),
            ])->save();

            $statement->forceFill([
                'line_count' => $statement->activeLines()->count(),
                'imported_at' => now(),
            ])->save();

            return $batch->fresh(['statement', 'lines']);
        });
    }

    /**
     * カードに設定された parser_key で解析器を決める。'generic_csv_v1' の場合は
     * CSV 内容から形式を自動判別し、具体的な parser_key が設定されている場合は
     * 判別結果と食い違わないことを検証する。
     */
    protected function resolveParser(CreditCard $creditCard, string $csvContents): CreditCardCsvParser
    {
        $detectedParser = $this->parserRegistry->detect($csvContents);

        if ($creditCard->parser_key === 'generic_csv_v1') {
            return $detectedParser ?? throw new InvalidArgumentException(
                'Unable to detect supported credit card CSV format.'
            );
        }

        $parser = $this->parserRegistry->resolve($creditCard->parser_key);

        if ($detectedParser !== null && $detectedParser->key() !== $parser->key()) {
            throw new InvalidArgumentException(sprintf(
                'カード設定のCSV形式と一致しません。渡された形式は %s 形式です。',
                $detectedParser->key(),
            ));
        }

        return $parser;
    }

    protected function upsertStatement(CreditCard $creditCard, ParsedCreditCardStatement $parsedStatement): CreditCardStatement
    {
        /** @var CreditCardStatement $statement */
        $statement = CreditCardStatement::query()->updateOrCreate(
            [
                'credit_card_id' => $creditCard->id,
                'statement_year' => $parsedStatement->statementYear,
                'statement_month' => $parsedStatement->statementMonth,
            ],
            [
                'period_start_on' => $parsedStatement->periodStartOn,
                'period_end_on' => $parsedStatement->periodEndOn,
                'billed_on' => $parsedStatement->billedOn,
                'paid_on' => $parsedStatement->paidOn,
                'total_amount' => $parsedStatement->totalAmount ?? 0,
                'line_count' => $parsedStatement->lineCount(),
                'imported_at' => now(),
            ],
        );

        return $statement;
    }

    protected function storeStatementLines(
        CreditCardStatement $statement,
        CreditCardImportBatch $batch,
        ParsedCreditCardStatement $parsedStatement,
    ): int {
        foreach ($parsedStatement->lines as $parsedLine) {
            $statement->lines()->create($this->buildStatementLineAttributes($batch, $parsedLine));
        }

        return $parsedStatement->lineCount();
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildStatementLineAttributes(
        CreditCardImportBatch $batch,
        ParsedCreditCardStatementLine $parsedLine,
    ): array {
        return [
            'credit_card_import_batch_id' => $batch->id,
            'line_number' => $parsedLine->lineNumber,
            'used_on' => $parsedLine->usedOn,
            'merchant_name' => $parsedLine->merchantName,
            'description' => $parsedLine->description,
            'amount' => $parsedLine->amount,
            'fingerprint' => $parsedLine->fingerprint,
            'status' => CreditCardStatementLine::STATUS_UNREVIEWED,
            'is_active' => true,
            'raw_payload' => $parsedLine->rawPayload,
        ];
    }
}
