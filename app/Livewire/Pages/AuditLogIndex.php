<?php

namespace App\Livewire\Pages;

use App\Auditing\AuditTargetRole;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\Transaction;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class AuditLogIndex extends Component
{
    use WithPagination;

    public int $perPage = 50;

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function fiscalYear(): FiscalYear
    {
        return auth()->user()->selectedBusinessUnitOrFail()->currentFiscalYear;
    }

    #[Computed]
    public function logs(): LengthAwarePaginator
    {
        $fiscalYear = $this->fiscalYear;

        return AuditLog::query()
            ->forFiscalYear($fiscalYear)
            ->with([
                'actor:id,name',
                'targets.auditable' => function (MorphTo $morphTo): void {
                    $morphTo->constrain([
                        Transaction::class => fn ($query) => $query->with(['fiscalYear', 'journalEntries']),
                    ]);
                },
            ])
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->paginate($this->perPage);
    }

    public function eventLabel(AuditLog $log): string
    {
        /** @var array<string, string> $labels */
        $labels = __('audit_logs.event_labels');

        return $labels[$log->event_type->value] ?? $log->event_type->value;
    }

    public function actorLabel(AuditLog $log): string
    {
        return $log->actor_label
            ?? $log->actor?->name
            ?? __('audit_logs.actor_system');
    }

    public function reasonLabel(AuditLog $log): string
    {
        return $log->reason ?: __('audit_logs.reason_none');
    }

    /**
     * @return array{voucher_number: string, date: string, description: string, amount: string}|null
     */
    public function sourceTransactionRow(AuditLog $log): ?array
    {
        $transaction = $this->sourceTransaction($log);

        if (! $transaction instanceof Transaction) {
            return null;
        }

        return [
            'voucher_number' => $transaction->display_number,
            'date' => $transaction->date?->format('Y-m-d') ?? '-',
            'description' => $transaction->description ?: __('audit_logs.transaction.no_description'),
            'amount' => number_format($transaction->total_amount).'円',
        ];
    }

    public function render(): View
    {
        return view('livewire.pages.audit-log-index');
    }

    private function sourceTransaction(AuditLog $log): ?Transaction
    {
        $prioritizedTarget = $log->targets
            ->first(fn ($target): bool => $target->role === AuditTargetRole::Source && $target->auditable instanceof Transaction)
            ?? $log->targets->first(fn ($target): bool => $target->role === AuditTargetRole::Subject && $target->auditable instanceof Transaction)
            ?? $log->targets->first(fn ($target): bool => $target->role === AuditTargetRole::Result && $target->auditable instanceof Transaction)
            ?? $log->targets->first(fn ($target): bool => $target->role === AuditTargetRole::Affected && $target->auditable instanceof Transaction);

        return $prioritizedTarget?->auditable instanceof Transaction
            ? $prioritizedTarget->auditable
            : null;
    }
}
