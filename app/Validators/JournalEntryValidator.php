<?php

namespace App\Validators;

use App\Models\JournalEntry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class JournalEntryValidator
{
    /**
     * バリデーションと正規化を行う
     *
     * @param  array  $data  入力データ
     * @return array 検証済みデータ
     *
     * @throws ValidationException バリデーションエラー時
     */
    public static function validate(array $data, $requireTransactionId): array
    {
        // tax_typeがあるのにtax_amountが未定義なら → 0に補完
        if (array_key_exists('tax_type', $data) && ! array_key_exists('tax_amount', $data)) {
            $data['tax_amount'] = 0;
        }

        $validated = Validator::make($data, self::rules($requireTransactionId), [], self::attributes())
            ->validate();

        self::ensureTaxTypeMatchesEntryType($validated);

        return $validated;
    }

    /**
     * バリデーションルール
     */
    public static function rules(bool $requireTransactionId = true): array
    {
        return array_merge([
            'account_id' => ['missing'],
            'sub_account_id' => ['required', 'exists:sub_accounts,id'],
            'type' => ['required', 'in:debit,credit'],
            'net_amount' => ['required', 'integer', 'min:1'],
            'tax_amount' => ['required_with:tax_type', 'numeric', 'min:0'],
            'tax_type' => ['nullable', 'in:'.implode(',', JournalEntry::TAX_TYPES)],
            'is_effective' => ['boolean'],
        ], $requireTransactionId ? [
            'transaction_id' => ['required', 'exists:transactions,id'],
        ] : []);
    }

    protected static function ensureTaxTypeMatchesEntryType(array $validated): void
    {
        $taxType = $validated['tax_type'] ?? null;

        if ($taxType === null) {
            return;
        }

        $entryType = $validated['type'];

        if (in_array($taxType, self::purchaseTaxTypes(), true) && $entryType !== JournalEntry::TYPE_DEBIT) {
            throw ValidationException::withMessages([
                'tax_type' => ['仕入・経費の消費税区分は借方でのみ使用できます。'],
            ]);
        }

        if (in_array($taxType, self::salesTaxTypes(), true) && $entryType !== JournalEntry::TYPE_CREDIT) {
            throw ValidationException::withMessages([
                'tax_type' => ['売上の消費税区分は貸方でのみ使用できます。'],
            ]);
        }
    }

    protected static function purchaseTaxTypes(): array
    {
        return [
            JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_8,
            JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10,
        ];
    }

    protected static function salesTaxTypes(): array
    {
        return [
            JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
            JournalEntry::TAX_TYPE_TAXABLE_SALES_8,
            JournalEntry::TAX_TYPE_DEEMED_TAXABLE_SALES_10,
        ];
    }

    /**
     * フィールド名のラベルを返す（エラー時の表示用）
     */
    public static function attributes(): array
    {
        return [
            'transaction_id' => '取引ID',
            'account_id' => '勘定科目ID',
            'sub_account_id' => '勘定科目の補助科目',
            'type' => '区分',
            'net_amount' => '税抜金額',
            'tax_amount' => '消費税額',
            'tax_type' => '消費税区分',
            'is_effective' => '有効フラグ',
        ];
    }
}
