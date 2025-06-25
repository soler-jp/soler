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
    public static function validate(array $data): array
    {
        return Validator::make($data, self::rules(), [], self::attributes())
            ->validate();
    }

    /**
     * バリデーションルール
     */
    public static function rules(): array
    {
        return [
            'transaction_id'   => ['required', 'exists:transactions,id'],
            'account_id'       => ['required', 'exists:accounts,id'],
            'sub_account_id'   => ['nullable', 'exists:sub_accounts,id'],
            'type'             => ['required', 'in:debit,credit'],
            'amount'           => ['required', 'integer', 'min:1'],
            'tax_amount'       => ['nullable', 'integer', 'min:0'],
            'tax_type'         => ['nullable', 'in:taxable_sales_10,taxable_sales_8,taxable_purchases_10,non_taxable,tax_free'],
            'is_effective'     => ['boolean'],
        ];
    }

    /**
     * フィールド名のラベルを返す（エラー時の表示用）
     */
    public static function attributes(): array
    {
        return [
            'transaction_id'   => '取引ID',
            'account_id'       => '勘定科目',
            'sub_account_id'   => '補助科目',
            'type'             => '区分',
            'amount'           => '金額',
            'tax_amount'       => '消費税額',
            'tax_type'         => '消費税区分',
            'is_effective'     => '有効フラグ',
        ];
    }
}
