<?php

namespace App\Validators;

use Illuminate\Support\Facades\Validator;

class JournalEntryValidator
{
    /**
     * バリデーションと正規化を行う
     *
     * @param array $data 入力データ
     * @return array 検証済みデータ
     * @throws ValidationException バリデーションエラー時
     */
    public static function validate(array $data, $requireTransactionId): array
    {
        // tax_typeがあるのにtax_amountが未定義なら → 0に補完
        if (array_key_exists('tax_type', $data) && !array_key_exists('tax_amount', $data)) {
            $data['tax_amount'] = 0;
        }

        return Validator::make($data, self::rules($requireTransactionId), [], self::attributes())
            ->validate();
    }

    /**
     * バリデーションルール
     */
    public static function rules(bool $requireTransactionId = true): array
    {
        return array_merge([
            'sub_account_id'   => ['required', 'exists:sub_accounts,id'],
            'type'             => ['required', 'in:debit,credit'],
            'amount'           => ['required', 'integer', 'min:1'],
            'tax_amount'       => ['required_with:tax_type', 'numeric', 'min:0'],
            'tax_type'         => ['nullable', 'in:taxable_sales_10,taxable_sales_8,taxable_purchases_10,non_taxable,tax_free'],
            'is_effective'     => ['boolean'],
        ], $requireTransactionId ? [
            'transaction_id'   => ['required', 'exists:transactions,id'],
        ] : []);
    }

    /**
     * フィールド名のラベルを返す（エラー時の表示用）
     */
    public static function attributes(): array
    {
        return [
            'transaction_id'   => '取引ID',
            'sub_account_id'   => '勘定科目の補助科目',
            'type'             => '区分',
            'amount'           => '金額',
            'tax_amount'       => '消費税額',
            'tax_type'         => '消費税区分',
            'is_effective'     => '有効フラグ',
        ];
    }
}
