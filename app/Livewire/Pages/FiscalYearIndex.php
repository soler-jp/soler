<?php

namespace App\Livewire\Pages;

use App\Models\BusinessUnit;
use App\Models\FiscalYear;
use App\Services\FiscalYearCloser;
use App\Services\FiscalYearRollover;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Component;

class FiscalYearIndex extends Component
{
    public ?string $noticeMessage = null;

    public ?string $errorMessage = null;

    public bool $showCloseConfirmModal = false;

    public ?int $closeFiscalYearId = null;

    /**
     * @var array{
     *     closable: bool,
     *     errors: array<int, array{key: string, count: int}>,
     *     warnings: array<int, array{key: string}>
     * }
     */
    public array $closeValidation = [
        'closable' => false,
        'errors' => [],
        'warnings' => [],
    ];

    public bool $showRolloverConfirmModal = false;

    public ?int $rolloverFiscalYearId = null;

    public bool $nextYearIsTaxable = false;

    public bool $nextYearIsTaxExclusive = false;

    /**
     * @var array{
     *     next_year: int|null,
     *     current_profit: int,
     *     opening_entries: array<int, array{account_name: string, sub_account_name: string, amount: int, type: 'debit'|'credit'}>,
     *     capital_entry: array{account_name: string, sub_account_name: string, amount: int, type: 'debit'|'credit'}|null
     * }
     */
    public array $rolloverPreview = [
        'next_year' => null,
        'current_profit' => 0,
        'opening_entries' => [],
        'capital_entry' => null,
    ];

    public function switchFiscalYear(int $fiscalYearId): void
    {
        $this->resetMessages();

        $businessUnit = $this->selectedBusinessUnit();

        if ($businessUnit === null) {
            $this->errorMessage = '事業体が選択されていません。';

            return;
        }

        $fiscalYear = $businessUnit->fiscalYears()->findOrFail($fiscalYearId);

        $businessUnit->activateFiscalYear($fiscalYear);

        $this->noticeMessage = sprintf('%d年度を表示中に切り替えました。', $fiscalYear->year);
    }

    public function openCloseConfirm(int $fiscalYearId): void
    {
        $this->resetMessages();

        $businessUnit = $this->selectedBusinessUnit();

        if ($businessUnit === null) {
            $this->errorMessage = '事業体が選択されていません。';

            return;
        }

        $fiscalYear = $businessUnit->fiscalYears()->findOrFail($fiscalYearId);

        $this->closeFiscalYearId = $fiscalYear->id;
        $this->closeValidation = app(FiscalYearCloser::class)->validate($fiscalYear);
        $this->showCloseConfirmModal = true;
    }

    public function closeConfirmModal(): void
    {
        $this->showCloseConfirmModal = false;
        $this->closeFiscalYearId = null;
        $this->closeValidation = [
            'closable' => false,
            'errors' => [],
            'warnings' => [],
        ];
    }

    public function confirmCloseFiscalYear(): void
    {
        $this->resetMessages();

        $businessUnit = $this->selectedBusinessUnit();

        if ($businessUnit === null || $this->closeFiscalYearId === null) {
            $this->errorMessage = '事業体または対象年度が見つかりません。';

            return;
        }

        $fiscalYear = $businessUnit->fiscalYears()->findOrFail($this->closeFiscalYearId);

        try {
            app(FiscalYearCloser::class)->close($fiscalYear, auth()->user());

            $this->noticeMessage = sprintf('%d年度を締めました。', $fiscalYear->year);
            $this->closeConfirmModal();
        } catch (ValidationException $exception) {
            $this->closeValidation = app(FiscalYearCloser::class)->validate($fiscalYear->fresh());
            $this->errorMessage = collect($exception->errors())
                ->flatten()
                ->implode(' ');
        } catch (InvalidArgumentException $exception) {
            $this->errorMessage = $exception->getMessage();
        }
    }

    public function openRolloverConfirm(int $fiscalYearId): void
    {
        $this->resetMessages();

        $businessUnit = $this->selectedBusinessUnit();

        if ($businessUnit === null) {
            $this->errorMessage = '事業体が選択されていません。';

            return;
        }

        $closedFiscalYear = $businessUnit->fiscalYears()->findOrFail($fiscalYearId);

        $rolloverData = $closedFiscalYear->calculateRolloverData();

        $this->rolloverFiscalYearId = $closedFiscalYear->id;
        $this->nextYearIsTaxable = (bool) $closedFiscalYear->is_taxable;
        $this->nextYearIsTaxExclusive = (bool) $closedFiscalYear->is_tax_exclusive;
        $this->rolloverPreview = $rolloverData;
        $this->showRolloverConfirmModal = true;
    }

    public function closeRolloverConfirmModal(): void
    {
        $this->showRolloverConfirmModal = false;
        $this->rolloverFiscalYearId = null;
        $this->nextYearIsTaxable = false;
        $this->nextYearIsTaxExclusive = false;
        $this->rolloverPreview = [
            'next_year' => null,
            'current_profit' => 0,
            'opening_entries' => [],
            'capital_entry' => null,
        ];
    }

    public function confirmCreateNextFiscalYearFromRollover(): void
    {
        $this->resetMessages();

        $businessUnit = $this->selectedBusinessUnit();

        if ($businessUnit === null || $this->rolloverFiscalYearId === null) {
            $this->errorMessage = '事業体または対象年度が見つかりません。';

            return;
        }

        $closedFiscalYear = $businessUnit->fiscalYears()->findOrFail($this->rolloverFiscalYearId);

        try {
            $nextFiscalYear = $businessUnit->createNextFiscalYearFrom(
                $closedFiscalYear,
                isTaxable: $this->nextYearIsTaxable,
                isTaxExclusive: $this->nextYearIsTaxExclusive,
            );

            app(FiscalYearRollover::class)->rollover($closedFiscalYear->refresh(), $nextFiscalYear);

            $businessUnit->activateFiscalYear($nextFiscalYear);

            $this->noticeMessage = sprintf(
                '%d年度を繰越データで作成し、表示中に切り替えました。',
                $nextFiscalYear->year,
            );
            $this->closeRolloverConfirmModal();
        } catch (DomainException|InvalidArgumentException $exception) {
            $this->errorMessage = $exception->getMessage();
        }
    }

    public function render(): View
    {
        return view('livewire.pages.fiscal-year-index', [
            'currentFiscalYearId' => $this->selectedBusinessUnit()?->current_fiscal_year_id,
            'currentFiscalYearLabel' => $this->selectedBusinessUnit()?->currentFiscalYear?->year
                ? $this->selectedBusinessUnit()->currentFiscalYear->year.'年度'
                : '未選択',
            'fiscalYears' => $this->fiscalYears(),
            'closeErrorMessages' => $this->closeErrorMessages(),
            'closeWarningMessages' => $this->closeWarningMessages(),
            'rolloverDebitTotal' => $this->rolloverEntriesForPreview()
                ->where('type', 'debit')
                ->sum('amount'),
            'rolloverCreditTotal' => $this->rolloverEntriesForPreview()
                ->where('type', 'credit')
                ->sum('amount'),
        ]);
    }

    /**
     * @return array<int, array{
     *     id: int,
     *     year: int,
     *     is_closed: bool,
     *     is_active: bool,
     *     is_taxable: bool,
     *     is_tax_exclusive: bool,
     *     can_close: bool,
     *     can_create_rollover: bool,
     *     next_year: int
     * }>
     */
    private function fiscalYears(): array
    {
        $businessUnit = $this->selectedBusinessUnit();

        if ($businessUnit === null) {
            return [];
        }

        $fiscalYears = $businessUnit->fiscalYears()
            ->orderByDesc('year')
            ->get();

        $existingYears = $fiscalYears->pluck('year')->all();

        return $fiscalYears
            ->map(function (FiscalYear $fiscalYear) use ($existingYears): array {
                $nextYear = $fiscalYear->year + 1;

                return [
                    'id' => $fiscalYear->id,
                    'year' => $fiscalYear->year,
                    'is_closed' => (bool) $fiscalYear->is_closed,
                    'is_active' => (bool) $fiscalYear->is_active,
                    'is_taxable' => (bool) $fiscalYear->is_taxable,
                    'is_tax_exclusive' => (bool) $fiscalYear->is_tax_exclusive,
                    'can_close' => ! $fiscalYear->is_closed,
                    'can_create_rollover' => $fiscalYear->is_closed && ! in_array($nextYear, $existingYears, true),
                    'next_year' => $nextYear,
                ];
            })
            ->all();
    }

    private function selectedBusinessUnit(): ?BusinessUnit
    {
        return auth()->user()?->selectedBusinessUnit;
    }

    private function resetMessages(): void
    {
        $this->noticeMessage = null;
        $this->errorMessage = null;
    }

    /**
     * @return Collection<int, array{account_name: string, sub_account_name: string, amount: int, type: 'debit'|'credit'}>
     */
    private function rolloverOpeningEntries(): Collection
    {
        return collect($this->rolloverPreview['opening_entries']);
    }

    /**
     * @return Collection<int, array{account_name: string, sub_account_name: string, amount: int, type: 'debit'|'credit'}>
     */
    private function rolloverEntriesForPreview(): Collection
    {
        $entries = $this->rolloverOpeningEntries();

        if ($this->rolloverPreview['capital_entry'] !== null) {
            $entries->push($this->rolloverPreview['capital_entry']);
        }

        return $entries;
    }

    /**
     * @return array<int, string>
     */
    private function closeErrorMessages(): array
    {
        return collect($this->closeValidation['errors'])
            ->map(fn (array $error): string => match ($error['key']) {
                'planned_transactions_remaining' => sprintf('未処理の予定取引が %d 件残っています。', $error['count']),
                'depreciation_entries_not_prepared' => sprintf('未準備の減価償却明細が %d 件あります。', $error['count']),
                'depreciation_entries_unposted' => sprintf('未計上の減価償却明細が %d 件あります。', $error['count']),
                default => '締め前チェックに失敗しました。',
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function closeWarningMessages(): array
    {
        return collect($this->closeValidation['warnings'])
            ->map(fn (array $warning): string => match ($warning['key']) {
                'inventory_transfer_missing' => '棚卸資産の残高がありますが、棚卸の決算整理仕訳が未登録です。',
                default => '確認したい警告があります。',
            })
            ->values()
            ->all();
    }
}
