<?php

namespace App\Support;

use App\Data\TransactionDiffResult;
use App\Models\JournalEntry;
use App\Models\Transaction;

class AuditLogTransactionDiffFormatter
{
    /**
     * @return list<string>
     */
    public function format(Transaction $old, Transaction $new, TransactionDiffResult $diff): array
    {
        $derivedChanges = $diff->derivedChanges();

        return [
            ...$this->subjectDiffLines($diff->subjectChanges()),
            ...$this->derivedDiffLines($derivedChanges),
            ...$this->formattedJournalEntryDiffLines(
                $old,
                $new,
                $derivedChanges,
                $diff->relatedChanges()['journal_entries'] ?? [],
            ),
        ];
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $subjectChanges
     * @return list<string>
     */
    private function subjectDiffLines(array $subjectChanges): array
    {
        $lines = [];

        if (isset($subjectChanges['date'])) {
            $lines[] = __('audit_logs.diff.subject.date', [
                'old' => $subjectChanges['date'][0] ?? '-',
                'new' => $subjectChanges['date'][1] ?? '-',
            ]);
        }

        if (isset($subjectChanges['description'])) {
            $lines[] = __('audit_logs.diff.subject.description', [
                'old' => $this->displayString($subjectChanges['description'][0]),
                'new' => $this->displayString($subjectChanges['description'][1]),
            ]);
        }

        if (isset($subjectChanges['remarks'])) {
            $lines[] = __('audit_logs.diff.subject.remarks', [
                'old' => $this->displayString($subjectChanges['remarks'][0]),
                'new' => $this->displayString($subjectChanges['remarks'][1]),
            ]);
        }

        if (isset($subjectChanges['business_ratio'])) {
            $lines[] = __('audit_logs.diff.subject.business_ratio', [
                'old' => $this->formatPercentage($subjectChanges['business_ratio'][0]),
                'new' => $this->formatPercentage($subjectChanges['business_ratio'][1]),
            ]);
        }

        return $lines;
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $derivedChanges
     * @return list<string>
     */
    private function derivedDiffLines(array $derivedChanges): array
    {
        $lines = [];

        if (isset($derivedChanges['total_amount'])) {
            $lines[] = __('audit_logs.diff.derived.total_amount', [
                'old' => $this->formatAmount((int) $derivedChanges['total_amount'][0]),
                'new' => $this->formatAmount((int) $derivedChanges['total_amount'][1]),
            ]);
        }

        return $lines;
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $derivedChanges
     * @param  array{created?: list<array<string, mixed>>, updated?: list<array<string, mixed>>, deleted?: list<array<string, mixed>>}  $journalEntryChanges
     * @return list<string>
     */
    private function formattedJournalEntryDiffLines(
        Transaction $old,
        Transaction $new,
        array $derivedChanges,
        array $journalEntryChanges,
    ): array {
        $singlePairCandidates = $this->singlePairJournalEntryCandidates($old, $new);

        if ($singlePairCandidates !== null) {
            return $this->resolveSinglePairCandidates($singlePairCandidates, isset($derivedChanges['total_amount']));
        }

        $lines = [];
        $oldLabels = $this->journalEntryLabels($old);
        $newLabels = $this->journalEntryLabels($new);

        foreach ($journalEntryChanges['updated'] ?? [] as $change) {
            $before = $change['before'] ?? [];
            $after = $change['after'] ?? [];
            $side = $this->entrySideLabel($before['type'] ?? $after['type'] ?? null);
            $beforeLabel = $oldLabels[(int) ($before['sub_account_id'] ?? 0)] ?? '-';
            $afterLabel = $newLabels[(int) ($after['sub_account_id'] ?? 0)] ?? '-';
            $beforeAmount = $this->formatAmount((int) ($before['gross_amount'] ?? $before['net_amount'] ?? 0));
            $afterAmount = $this->formatAmount((int) ($after['gross_amount'] ?? $after['net_amount'] ?? 0));

            $lines[] = __('audit_logs.diff.entries.updated', [
                'side' => $side,
                'old_account' => $beforeLabel,
                'new_account' => $afterLabel,
                'old_amount' => $beforeAmount,
                'new_amount' => $afterAmount,
            ]);
        }

        foreach ($journalEntryChanges['created'] ?? [] as $change) {
            $attributes = $change['attributes'] ?? [];
            $lines[] = __('audit_logs.diff.entries.created', [
                'side' => $this->entrySideLabel($attributes['type'] ?? null),
                'account' => $newLabels[(int) ($attributes['sub_account_id'] ?? 0)] ?? '-',
                'amount' => $this->formatAmount((int) ($attributes['gross_amount'] ?? $attributes['net_amount'] ?? 0)),
            ]);
        }

        foreach ($journalEntryChanges['deleted'] ?? [] as $change) {
            $attributes = $change['attributes'] ?? [];
            $lines[] = __('audit_logs.diff.entries.deleted', [
                'side' => $this->entrySideLabel($attributes['type'] ?? null),
                'account' => $oldLabels[(int) ($attributes['sub_account_id'] ?? 0)] ?? '-',
                'amount' => $this->formatAmount((int) ($attributes['gross_amount'] ?? $attributes['net_amount'] ?? 0)),
            ]);
        }

        return $lines;
    }

    /**
     * @return list<array{
     *     type: 'account_changed'|'amount_changed'|'account_and_amount_changed',
     *     side: string,
     *     old_account: string,
     *     new_account: string,
     *     old_amount: int,
     *     new_amount: int
     * }>|null
     */
    private function singlePairJournalEntryCandidates(
        Transaction $old,
        Transaction $new,
    ): ?array {
        if (! $old->is_single_pair || ! $new->is_single_pair) {
            return null;
        }

        $oldDebit = $old->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT);
        $oldCredit = $old->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);
        $newDebit = $new->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT);
        $newCredit = $new->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);

        if (! $oldDebit instanceof JournalEntry || ! $oldCredit instanceof JournalEntry || ! $newDebit instanceof JournalEntry || ! $newCredit instanceof JournalEntry) {
            return null;
        }

        $candidates = [];

        $debitCandidate = $this->singlePairSideCandidate(
            $oldDebit,
            $newDebit,
            JournalEntry::TYPE_DEBIT,
        );
        $creditCandidate = $this->singlePairSideCandidate(
            $oldCredit,
            $newCredit,
            JournalEntry::TYPE_CREDIT,
        );

        if ($debitCandidate !== null) {
            $candidates[] = $debitCandidate;
        }

        if ($creditCandidate !== null) {
            $candidates[] = $creditCandidate;
        }

        return $candidates;
    }

    /**
     * @param  list<array{
     *     type: 'account_changed'|'amount_changed'|'account_and_amount_changed',
     *     side: string,
     *     old_account: string,
     *     new_account: string,
     *     old_amount: int,
     *     new_amount: int
     * }>  $candidates
     * @return list<string>
     */
    private function resolveSinglePairCandidates(array $candidates, bool $hasTotalAmountDiff): array
    {
        $lines = [];

        foreach ($candidates as $candidate) {
            if ($candidate['type'] === 'amount_changed' && $hasTotalAmountDiff) {
                continue;
            }

            if ($candidate['type'] === 'account_changed') {
                $lines[] = __('audit_logs.diff.entries.account_changed', [
                    'side' => $this->entrySideLabel($candidate['side']),
                    'old_account' => $candidate['old_account'],
                    'new_account' => $candidate['new_account'],
                ]);

                continue;
            }

            $lines[] = __('audit_logs.diff.entries.updated', [
                'side' => $this->entrySideLabel($candidate['side']),
                'old_account' => $candidate['old_account'],
                'new_account' => $candidate['new_account'],
                'old_amount' => $this->formatAmount($candidate['old_amount']),
                'new_amount' => $this->formatAmount($candidate['new_amount']),
            ]);
        }

        return $lines;
    }

    /**
     * @return array{
     *     type: 'account_changed'|'amount_changed'|'account_and_amount_changed',
     *     side: string,
     *     old_account: string,
     *     new_account: string,
     *     old_amount: int,
     *     new_amount: int
     * }|null
     */
    private function singlePairSideCandidate(
        JournalEntry $before,
        JournalEntry $after,
        string $side,
    ): ?array {
        $beforeLabel = $before->subAccount?->displayName() ?? $before->subAccount?->name ?? '-';
        $afterLabel = $after->subAccount?->displayName() ?? $after->subAccount?->name ?? '-';
        $accountChanged = $before->sub_account_id !== $after->sub_account_id;
        $amountChanged = $before->gross_amount !== $after->gross_amount;

        if (! $accountChanged && ! $amountChanged) {
            return null;
        }

        return [
            'type' => $accountChanged && $amountChanged
                ? 'account_and_amount_changed'
                : ($accountChanged ? 'account_changed' : 'amount_changed'),
            'side' => $side,
            'old_account' => $beforeLabel,
            'new_account' => $afterLabel,
            'old_amount' => $before->gross_amount,
            'new_amount' => $after->gross_amount,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function journalEntryLabels(Transaction $transaction): array
    {
        return $transaction->journalEntries
            ->mapWithKeys(function (JournalEntry $entry): array {
                $label = $entry->subAccount?->displayName() ?? $entry->subAccount?->name ?? '-';

                return [$entry->sub_account_id => $label];
            })
            ->all();
    }

    private function entrySideLabel(mixed $type): string
    {
        return match ($type) {
            JournalEntry::TYPE_DEBIT => __('audit_logs.diff.entry_sides.debit'),
            JournalEntry::TYPE_CREDIT => __('audit_logs.diff.entry_sides.credit'),
            default => __('audit_logs.diff.entry_sides.unknown'),
        };
    }

    private function formatAmount(int $amount): string
    {
        return number_format($amount).__('audit_logs.diff.currency_suffix');
    }

    private function formatPercentage(mixed $value): string
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return '-';
        }

        return sprintf('%s%%', $value);
    }

    private function displayString(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '-';
        }

        return $value;
    }
}
