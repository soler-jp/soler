<?php

namespace Tests\Unit\Auditing;

use App\Auditing\AuditChanges;
use App\Models\JournalEntry;
use App\Models\Transaction;
use Tests\TestCase;

class AuditChangesTest extends TestCase
{
    public function test_default_construction_produces_empty_shape(): void
    {
        $changes = new AuditChanges;

        $this->assertTrue($changes->isEmpty());
        $this->assertSame(1, $changes->payloadVersion);
    }

    public function test_to_array_serializes_empty_subject_and_related_as_json_objects(): void
    {
        $changes = new AuditChanges;

        $encoded = json_encode($changes->toArray());
        $this->assertSame('{"subject":{},"related":{}}', $encoded);
    }

    public function test_to_array_serializes_populated_subject_as_json_object(): void
    {
        $changes = new AuditChanges(
            subject: ['is_active' => [true, false]],
        );

        $encoded = json_encode($changes->toArray());
        $this->assertSame('{"subject":{"is_active":[true,false]},"related":{}}', $encoded);
    }

    public function test_to_array_serializes_populated_related_correctly(): void
    {
        $changes = new AuditChanges(
            subject: ['date' => [null, '2026-08-07']],
            related: [
                'journal_entries' => [
                    'created' => [
                        ['id' => 100, 'attributes' => ['sub_account_id' => 5]],
                    ],
                ],
            ],
        );

        $decoded = json_decode(json_encode($changes->toArray()), true);
        $this->assertSame([null, '2026-08-07'], $decoded['subject']['date']);
        $this->assertSame(100, $decoded['related']['journal_entries']['created'][0]['id']);
    }

    public function test_is_empty_returns_true_only_when_both_subject_and_related_are_empty(): void
    {
        $this->assertTrue((new AuditChanges)->isEmpty());
        $this->assertFalse((new AuditChanges(subject: ['x' => [1, 2]]))->isEmpty());
        $this->assertFalse((new AuditChanges(related: ['rel' => ['created' => []]]))->isEmpty());
    }

    public function test_payload_version_is_preserved(): void
    {
        $changes = new AuditChanges(payloadVersion: 3);

        $this->assertSame(3, $changes->payloadVersion);
    }

    public function test_for_transaction_deactivated_shape_is_fixed(): void
    {
        // shape 固定テスト。将来 shape を変える際は payloadVersion 増分と合わせる。
        $transaction = new Transaction;
        $transaction->deactivated_at = new \DateTimeImmutable('2026-08-07T12:34:56+00:00');

        $changes = AuditChanges::forTransactionDeactivated($transaction);

        $this->assertSame(1, $changes->payloadVersion);
        $this->assertSame(
            [
                'is_active' => [true, false],
                'deactivated_at' => [null, '2026-08-07T12:34:56+00:00'],
            ],
            $changes->subject,
        );
        $this->assertSame([], $changes->related);
    }

    public function test_for_transaction_revised_shape_is_fixed(): void
    {
        $transaction = new Transaction;
        $transaction->revision_reason = '金額入力ミスの修正';
        $transaction->setRelation('journalEntries', collect([
            new JournalEntry([
                'sub_account_id' => 5,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 1000,
                'tax_amount' => 100,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ]),
            new JournalEntry([
                'sub_account_id' => 8,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 1100,
                'tax_amount' => 0,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ]),
        ]));

        $transaction->journalEntries[0]->id = 100;
        $transaction->journalEntries[1]->id = 101;

        $changes = AuditChanges::forTransactionRevised($transaction);

        $this->assertSame(1, $changes->payloadVersion);
        $this->assertSame(
            [
                'revision_reason' => [null, '金額入力ミスの修正'],
            ],
            $changes->subject,
        );
        $this->assertSame(
            [
                'journal_entries' => [
                    'created' => [
                        [
                            'id' => 100,
                            'attributes' => [
                                'sub_account_id' => 5,
                                'type' => JournalEntry::TYPE_DEBIT,
                                'net_amount' => 1000,
                                'tax_amount' => 100,
                                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
                            ],
                        ],
                        [
                            'id' => 101,
                            'attributes' => [
                                'sub_account_id' => 8,
                                'type' => JournalEntry::TYPE_CREDIT,
                                'net_amount' => 1100,
                                'tax_amount' => 0,
                                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                            ],
                        ],
                    ],
                ],
            ],
            $changes->related,
        );
    }
}
