<?php

namespace App\Validators;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TransactionValidator
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
    protected static function rules(): array
    {
        return [
            'fiscal_year_id' => ['required', 'exists:fiscal_years,id'],
            'date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
            'is_adjusting_entry' => ['boolean'],
            'is_planned' => ['nullable', 'boolean'],
            'is_opening_entry' => ['nullable', 'boolean'],
            'recurring_transaction_plan_id' => ['nullable', 'exists:recurring_transaction_plans,id'],
            'created_by' => ['nullable', 'exists:users,id'],
        ];
    }

    /**
     * 属性名（エラーメッセージ用）
     */
    protected static function attributes(): array
    {
        return [
            'fiscal_year_id' => '会計年度',
            'date' => '取引日',
            'description' => '摘要',
            'remarks' => '備考',
            'tax_type' => '消費税区分',
            'is_opening_entry' => '期首仕訳フラグ',
            'is_adjusting_entry' => '決算整理仕訳フラグ',
            'recurring_transaction_plan_id' => '定期取引計画ID',
            'created_by' => '登録ユーザー',
        ];
    }
}
