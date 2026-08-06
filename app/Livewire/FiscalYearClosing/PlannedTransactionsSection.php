<?php

namespace App\Livewire\FiscalYearClosing;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionRegistrar;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use LogicException;

class PlannedTransactionsSection extends Component
{
    use AuthorizesBusinessUnitAccess;

    #[Locked]
    public int $fiscalYearId;

    public ?string $noticeMessage = null;

    public ?string $errorMessage = null;

    public function mount(int $fiscalYearId): void
    {
        $this->fiscalYearId = $fiscalYearId;

        $this->requireFiscalYear();
    }

    public function confirm(int $transactionId): void
    {
        $this->runOnPlannedTransaction(
            $transactionId,
            fn (Transaction $tx, User $user) => app(TransactionRegistrar::class)->confirmPlanned($tx, $user),
            successKey: 'fiscal_year_closing.planned.confirm_success',
        );
    }

    public function cancel(int $transactionId): void
    {
        $this->runOnPlannedTransaction(
            $transactionId,
            fn (Transaction $tx, User $user) => app(TransactionRegistrar::class)->cancelPlanned($tx, $user),
            successKey: 'fiscal_year_closing.planned.cancel_success',
        );
    }

    public function render(): View
    {
        return view('livewire.fiscal-year-closing.planned-transactions-section', [
            'fiscalYear' => $this->requireFiscalYear(),
            'items' => $this->loadItems(),
        ]);
    }

    /**
     * @return array<int, array{id: int, date: string, description: string, amount: int}>
     */
    private function loadItems(): array
    {
        return $this->requireFiscalYear()
            ->transactions()
            ->active()
            ->where('is_planned', true)
            ->with('journalEntries')
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (Transaction $tx): array => [
                'id' => (int) $tx->id,
                'date' => $tx->date?->toDateString() ?? '',
                'description' => (string) $tx->description,
                'amount' => $this->extractAmount($tx),
            ])
            ->all();
    }

    private function extractAmount(Transaction $tx): int
    {
        $credit = $tx->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);

        if ($credit === null) {
            return 0;
        }

        return (int) $credit->net_amount + (int) $credit->tax_amount;
    }

    private function runOnPlannedTransaction(int $transactionId, callable $action, string $successKey): void
    {
        $this->noticeMessage = null;
        $this->errorMessage = null;

        $user = $this->requireUser();
        $fiscalYear = $this->requireFiscalYear();

        $transaction = $fiscalYear->transactions()
            ->where('is_planned', true)
            ->findOrFail($transactionId);

        try {
            $action($transaction, $user);
        } catch (AuthorizationException|DomainException|InvalidArgumentException|ValidationException $exception) {
            $this->errorMessage = $exception->getMessage();

            return;
        }

        $this->noticeMessage = __($successKey, ['description' => $transaction->description]);
    }

    private function requireFiscalYear(): FiscalYear
    {
        $fiscalYear = FiscalYear::query()->findOrFail($this->fiscalYearId);

        $this->authorizeBusinessUnitAccess(
            $fiscalYear,
            $this->requireUser(),
            'この会計年度の予定取引を閲覧する権限がありません。',
        );

        return $fiscalYear;
    }

    private function requireUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new LogicException('PlannedTransactionsSection は認証済みユーザーからのみ利用できます。');
        }

        return $user;
    }
}
