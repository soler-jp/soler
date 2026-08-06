<?php

namespace App\Livewire\Dashboard;

use App\Models\BusinessUnit;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\FiscalYearRollover;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Component;
use LogicException;

class PreviousFiscalYearRolloverPrompt extends Component
{
    public int $businessUnitId;

    public int $previousFiscalYearId;

    public int $currentFiscalYearId;

    public int $previousYear;

    public int $currentYear;

    public bool $previousFiscalYearClosed = false;

    public bool $showConfirmation = false;

    public ?string $errorMessage = null;

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

    public function mount(BusinessUnit $businessUnit): void
    {
        $currentFiscalYear = $businessUnit->currentFiscalYear;

        if ($currentFiscalYear === null) {
            throw new LogicException('PreviousFiscalYearRolloverPrompt は current fiscal year が設定されている事業体でのみ利用できます。');
        }

        $previousFiscalYear = $businessUnit->fiscalYears()
            ->where('year', $currentFiscalYear->year - 1)
            ->first();

        if (! $previousFiscalYear instanceof FiscalYear) {
            throw new LogicException('PreviousFiscalYearRolloverPrompt は前年度が存在する事業体でのみ利用できます。');
        }

        $this->businessUnitId = $businessUnit->id;
        $this->previousFiscalYearId = $previousFiscalYear->id;
        $this->currentFiscalYearId = $currentFiscalYear->id;
        $this->previousYear = $previousFiscalYear->year;
        $this->currentYear = $currentFiscalYear->year;
        $this->previousFiscalYearClosed = (bool) $previousFiscalYear->is_closed;
        $this->rolloverPreview = $previousFiscalYear->calculateRolloverData();
    }

    public function openConfirmation(): void
    {
        $this->errorMessage = null;
        $this->refreshPreviousFiscalYearState();

        if (! $this->previousFiscalYearClosed) {
            return;
        }

        $this->showConfirmation = true;
    }

    public function closeConfirmation(): void
    {
        $this->showConfirmation = false;
    }

    public function start(): mixed
    {
        $this->errorMessage = null;

        $user = auth()->user();

        if (! $user instanceof User) {
            throw new LogicException('PreviousFiscalYearRolloverPrompt は認証済みユーザーからのみ利用できます。');
        }

        $businessUnit = BusinessUnit::query()->findOrFail($this->businessUnitId);
        $currentFiscalYear = $businessUnit->fiscalYears()->findOrFail($this->currentFiscalYearId);
        $previousFiscalYear = $this->refreshPreviousFiscalYearState($businessUnit);

        if (! $previousFiscalYear->is_closed) {
            $this->errorMessage = __(
                'previous_fiscal_year_rollover_prompt.not_closed',
                ['year' => $this->previousYear]
            );

            return null;
        }

        if ($previousFiscalYear->rollover_at !== null) {
            $this->errorMessage = __('previous_fiscal_year_rollover_prompt.already_loaded');

            return null;
        }

        try {
            app(FiscalYearRollover::class)->rollover($previousFiscalYear, $currentFiscalYear, $user);
        } catch (AuthorizationException|DomainException|InvalidArgumentException $exception) {
            $this->errorMessage = $exception->getMessage();

            return null;
        }

        session()->flash(
            'message',
            __('previous_fiscal_year_rollover_prompt.completed', [
                'previous_year' => $this->previousYear,
                'current_year' => $this->currentYear,
            ])
        );

        return $this->redirect(route('dashboard'), navigate: false);
    }

    public function render()
    {
        return view('livewire.dashboard.previous-fiscal-year-rollover-prompt', [
            'rolloverDebitTotal' => $this->rolloverEntriesForPreview()
                ->where('type', 'debit')
                ->sum('amount'),
            'rolloverCreditTotal' => $this->rolloverEntriesForPreview()
                ->where('type', 'credit')
                ->sum('amount'),
        ]);
    }

    /**
     * @return Collection<int, array{account_name: string, sub_account_name: string, amount: int, type: 'debit'|'credit'}>
     */
    private function rolloverEntriesForPreview(): Collection
    {
        $entries = collect($this->rolloverPreview['opening_entries']);

        if ($this->rolloverPreview['capital_entry'] !== null) {
            $entries->push($this->rolloverPreview['capital_entry']);
        }

        return $entries;
    }

    private function refreshPreviousFiscalYearState(?BusinessUnit $businessUnit = null): FiscalYear
    {
        $businessUnit ??= BusinessUnit::query()->findOrFail($this->businessUnitId);

        $previousFiscalYear = $businessUnit->fiscalYears()->findOrFail($this->previousFiscalYearId);

        $this->previousFiscalYearClosed = (bool) $previousFiscalYear->is_closed;
        $this->rolloverPreview = $previousFiscalYear->calculateRolloverData();

        return $previousFiscalYear;
    }
}
