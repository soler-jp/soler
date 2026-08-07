<?php

namespace App\Auditing;

use App\Models\JournalEntry;
use App\Models\Transaction;

/**
 * `changes` 列に書き込む JSON の shape を型付きで表現する値オブジェクト。
 *
 * トップレベル構造は `{"subject": {...}, "related": {...}}` に固定。
 * インスタンス化は事故防止のためイベント専用ファクトリ経由のみ推奨。
 * 引数なしコンストラクタは「changes なしイベント」のデフォルト値専用。
 */
final class AuditChanges
{
    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $subject
     * @param  array<string, array{created?: array<int, array<string, mixed>>, updated?: array<int, array<string, mixed>>, deleted?: array<int, array<string, mixed>>}>  $related
     */
    public function __construct(
        public readonly array $subject = [],
        public readonly array $related = [],
        public readonly int $payloadVersion = 1,
    ) {}

    /**
     * @return array{subject: mixed, related: mixed}
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject === [] ? new \stdClass : $this->subject,
            'related' => $this->related === [] ? new \stdClass : $this->related,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->subject === [] && $this->related === [];
    }

    /**
     * transaction.deactivated 用の shape を構築する。
     *
     * サンプルファクトリ。同一パターンで他イベントも追加する。
     */
    public static function forTransactionDeactivated(Transaction $transaction): self
    {
        return new self(
            subject: [
                'is_active' => [true, false],
                'deactivated_at' => [null, self::formatTimestamp($transaction->deactivated_at)],
            ],
        );
    }

    public static function forTransactionRevised(Transaction $transaction): self
    {
        $transaction->loadMissing('journalEntries');

        return new self(
            subject: [
                'revision_reason' => [null, $transaction->revision_reason],
            ],
            related: [
                'journal_entries' => [
                    'created' => $transaction->journalEntries
                        ->map(fn (JournalEntry $entry): array => [
                            'id' => $entry->getKey(),
                            'attributes' => array_filter([
                                'sub_account_id' => $entry->sub_account_id,
                                'type' => $entry->type,
                                'net_amount' => $entry->net_amount,
                                'tax_amount' => $entry->tax_amount,
                                'tax_type' => $entry->tax_type,
                                'business_ratio' => $entry->business_ratio,
                            ], fn (mixed $value): bool => $value !== null),
                        ])
                        ->values()
                        ->all(),
                ],
            ],
        );
    }

    private static function formatTimestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        return (string) $value;
    }
}
