<?php

namespace App\Services;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Data\TransactionDiffResult;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class TransactionDiffer
{
    use AuthorizesBusinessUnitAccess;

    /**
     * @var array<int, string>
     */
    private const SUBJECT_FIELDS = [
        'date',
        'description',
        'remarks',
        'counterparty_id',
        'business_ratio',
        'is_planned',
    ];

    /**
     * @var array<int, string>
     */
    private const UPDATED_FIELDS = [
        'gross_amount',
        'net_amount',
        'tax_amount',
        'business_ratio',
    ];

    public function diff(Transaction $old, Transaction $new, ?User $actor): TransactionDiffResult
    {
        $this->authorizeBusinessUnitAccess($old, $actor, 'この取引差分を確認する権限がありません。');
        $this->authorizeBusinessUnitAccess($new, $actor, 'この取引差分を確認する権限がありません。');

        if ($old->resolveBusinessUnit()->isNot($new->resolveBusinessUnit())) {
            throw new InvalidArgumentException('同一事業体に属する取引同士のみ比較できます。');
        }

        $old->loadMissing('journalEntries');
        $new->loadMissing('journalEntries');

        return new TransactionDiffResult(
            subjectChanges: $this->buildSubjectChanges($old, $new),
            derivedChanges: $this->buildDerivedChanges($old, $new),
            relatedChanges: [
                'journal_entries' => $this->buildJournalEntryChanges($old->journalEntries, $new->journalEntries),
            ],
        );
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function buildSubjectChanges(Transaction $old, Transaction $new): array
    {
        $changes = [];

        foreach (self::SUBJECT_FIELDS as $field) {
            $oldValue = $this->transactionValue($old, $field);
            $newValue = $this->transactionValue($new, $field);

            if ($oldValue !== $newValue) {
                $changes[$field] = [$oldValue, $newValue];
            }
        }

        return $changes;
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function buildDerivedChanges(Transaction $old, Transaction $new): array
    {
        $derived = [
            'total_amount' => [$old->total_amount, $new->total_amount],
            'debit_entry_count' => [
                $old->journalEntries->where('type', JournalEntry::TYPE_DEBIT)->count(),
                $new->journalEntries->where('type', JournalEntry::TYPE_DEBIT)->count(),
            ],
            'credit_entry_count' => [
                $old->journalEntries->where('type', JournalEntry::TYPE_CREDIT)->count(),
                $new->journalEntries->where('type', JournalEntry::TYPE_CREDIT)->count(),
            ],
        ];

        return array_filter(
            $derived,
            static fn (array $values): bool => $values[0] !== $values[1],
        );
    }

    /**
     * @param  Collection<int, JournalEntry>  $oldEntries
     * @param  Collection<int, JournalEntry>  $newEntries
     * @return array{created: list<array<string, mixed>>, updated: list<array<string, mixed>>, deleted: list<array<string, mixed>>}
     */
    private function buildJournalEntryChanges(Collection $oldEntries, Collection $newEntries): array
    {
        $changes = [
            'created' => [],
            'updated' => [],
            'deleted' => [],
        ];

        $groupedOldEntries = $this->groupEntriesByMatchKey($oldEntries);
        $groupedNewEntries = $this->groupEntriesByMatchKey($newEntries);
        $allKeys = array_values(array_unique([
            ...array_keys($groupedOldEntries),
            ...array_keys($groupedNewEntries),
        ]));

        foreach ($allKeys as $key) {
            $oldGroup = $groupedOldEntries[$key] ?? [];
            $newGroup = $groupedNewEntries[$key] ?? [];

            [$oldRemaining, $newRemaining] = $this->removeExactMatches($oldGroup, $newGroup);

            if ($oldRemaining === [] && $newRemaining === []) {
                continue;
            }

            if (count($oldRemaining) === count($newRemaining)) {
                foreach (array_keys($oldRemaining) as $index) {
                    $before = $oldRemaining[$index];
                    $after = $newRemaining[$index];
                    $entryChanges = $this->buildUpdatedFieldChanges($before, $after);

                    if ($entryChanges === []) {
                        continue;
                    }

                    $changes['updated'][] = [
                        'before' => $before,
                        'after' => $after,
                        'changes' => $entryChanges,
                    ];
                }

                continue;
            }

            foreach ($oldRemaining as $attributes) {
                $changes['deleted'][] = [
                    'attributes' => $attributes,
                ];
            }

            foreach ($newRemaining as $attributes) {
                $changes['created'][] = [
                    'attributes' => $attributes,
                ];
            }
        }

        return $changes;
    }

    /**
     * @param  Collection<int, JournalEntry>  $entries
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupEntriesByMatchKey(Collection $entries): array
    {
        return $entries
            ->values()
            ->map(fn (JournalEntry $entry): array => $this->normalizeJournalEntry($entry))
            ->groupBy(fn (array $attributes): string => $this->journalEntryMatchKey($attributes))
            ->map(fn (Collection $group): array => $group->values()->all())
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $oldEntries
     * @param  list<array<string, mixed>>  $newEntries
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function removeExactMatches(array $oldEntries, array $newEntries): array
    {
        $remainingNewEntries = array_values($newEntries);

        foreach ($oldEntries as $oldIndex => $oldEntry) {
            foreach ($remainingNewEntries as $newIndex => $newEntry) {
                if ($oldEntry !== $newEntry) {
                    continue;
                }

                unset($oldEntries[$oldIndex], $remainingNewEntries[$newIndex]);

                break;
            }
        }

        return [
            array_values($oldEntries),
            array_values($remainingNewEntries),
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function buildUpdatedFieldChanges(array $before, array $after): array
    {
        $changes = [];

        foreach (self::UPDATED_FIELDS as $field) {
            if ($before[$field] === $after[$field]) {
                continue;
            }

            $changes[$field] = [$before[$field], $after[$field]];
        }

        return $changes;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeJournalEntry(JournalEntry $entry): array
    {
        return [
            'type' => $entry->type,
            'sub_account_id' => $entry->sub_account_id,
            'tax_type' => $entry->tax_type,
            'gross_amount' => $entry->gross_amount,
            'net_amount' => $entry->net_amount,
            'tax_amount' => $entry->tax_amount,
            'business_ratio' => $entry->business_ratio,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function journalEntryMatchKey(array $attributes): string
    {
        return implode(':', [
            $attributes['type'],
            (string) $attributes['sub_account_id'],
            (string) $attributes['tax_type'],
        ]);
    }

    private function transactionValue(Transaction $transaction, string $field): mixed
    {
        return match ($field) {
            'date' => $transaction->date?->toDateString(),
            'business_ratio' => $transaction->business_ratio,
            default => $transaction->{$field},
        };
    }
}
