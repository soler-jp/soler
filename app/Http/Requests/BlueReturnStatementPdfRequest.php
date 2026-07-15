<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BlueReturnStatementPdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'blue_return_deduction' => ['required', 'integer', 'min:0', 'max:999999999'],
            'filing_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'name_kana' => ['nullable', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:100'],
            'business_address' => ['nullable', 'string', 'max:255'],
            'home_phone_number' => ['nullable', 'string', 'max:50'],
            'business_phone_number' => ['nullable', 'string', 'max:50'],
            'business_type' => ['nullable', 'string', 'max:100'],
            'trade_name' => ['nullable', 'string', 'max:100'],
            'association_name' => ['nullable', 'string', 'max:100'],
            'tax_accountant_office_address' => ['nullable', 'string', 'max:255'],
            'tax_accountant_name' => ['nullable', 'string', 'max:100'],
            'tax_accountant_phone_number' => ['nullable', 'string', 'max:50'],
        ];
    }
}
