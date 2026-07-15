<?php

namespace App\Http\Controllers;

use App\Http\Requests\BlueReturnStatementPdfRequest;
use App\Models\FiscalYear;
use App\Services\BlueReturnPdf\TemplateResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class BlueReturnStatementPdfController extends Controller
{
    private const HEADER_FIELDS = [
        'filing_number',
        'address',
        'name_kana',
        'name',
        'business_address',
        'home_phone_number',
        'business_phone_number',
        'business_type',
        'trade_name',
        'association_name',
        'tax_accountant_office_address',
        'tax_accountant_name',
        'tax_accountant_phone_number',
    ];

    public function __construct(
        private readonly TemplateResolver $templateResolver
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $fiscalYear = $this->currentFiscalYear($request);

        if ($fiscalYear instanceof RedirectResponse) {
            return $fiscalYear;
        }

        $templateLabel = null;
        $templateError = null;

        try {
            $templateLabel = $this->templateLabel($this->templateResolver->resolve($fiscalYear));
        } catch (InvalidArgumentException $exception) {
            $templateError = $exception->getMessage();
        }

        return response()->view('blue-return-statement.pdf', [
            'defaultValues' => [
                'blue_return_deduction' => 650000,
                'name' => $request->user()?->name ?? '',
            ],
            'fiscalYear' => $fiscalYear,
            'templateError' => $templateError,
            'templateLabel' => $templateLabel,
        ]);
    }

    public function download(BlueReturnStatementPdfRequest $request): Response|RedirectResponse
    {
        $fiscalYear = $this->currentFiscalYear($request);

        if ($fiscalYear instanceof RedirectResponse) {
            return $fiscalYear;
        }

        $validated = $request->validated();

        try {
            $pdf = $fiscalYear->generateBlueReturnStatementPdf(
                blueReturnDeduction: (int) $validated['blue_return_deduction'],
                header: $this->headerValues($validated),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'fiscal_year' => $exception->getMessage(),
            ]);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="blue-return-statement-%d.pdf"', $fiscalYear->year),
        ]);
    }

    private function currentFiscalYear(Request $request): FiscalYear|RedirectResponse
    {
        $fiscalYear = $request->user()?->selectedBusinessUnit?->currentFiscalYear;

        if ($fiscalYear instanceof FiscalYear) {
            return $fiscalYear;
        }

        return redirect()->route('initialize');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, string>
     */
    private function headerValues(array $validated): array
    {
        return collect(Arr::only($validated, self::HEADER_FIELDS))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->all();
    }

    private function templateLabel(string $templateVersion): string
    {
        return match ($templateVersion) {
            'from2023' => '令和五年分以降用',
            'from2020' => '令和二年分以降用',
            default => $templateVersion,
        };
    }
}
