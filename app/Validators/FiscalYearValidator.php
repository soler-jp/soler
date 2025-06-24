<?php

namespace App\Validators;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class FiscalYearValidator
{
    /**
     * FiscalYearデータのバリデーションを実行し、
     * 合格すればクリーンな配列を返す。
     * 失敗すればValidationExceptionを投げる。
     *
     * @param array $data
     * @return array
     * @throws ValidationException
     */
    public static function validate(array $data): array
    {
        return Validator::validate($data, [
            'business_unit_id' => ['required', 'exists:business_units,id'],
            'year' => [
                'required',
                'integer',
                Rule::unique('fiscal_years')->where(function ($query) use ($data) {
                    return $query->where('business_unit_id', $data['business_unit_id']);
                }),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'is_closed' => ['boolean'],
        ]);
    }
}
