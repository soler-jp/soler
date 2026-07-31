<?php

namespace App\Services;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Concerns\SkipActorGuard;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FiscalYearCloser
{
    use AuthorizesBusinessUnitAccess;

    /**
     * 会計年度を締める前の検証結果を返す。
     *
     * @return array{
     *     closable: bool,
     *     errors: array<int, array{key: string, count: int}>,
     *     warnings: array<int, array{key: string}>
     * }
     */
    #[SkipActorGuard('read-only な締め前検証。TODO: 決算画面を actor 認可下に置いた後、この API にも actor を追加する。')]
    public function validate(FiscalYear $fiscalYear): array
    {
        $fiscalYear->loadMissing('businessUnit');

        $errors = [];
        $warnings = [];

        $plannedTransactionsRemaining = $fiscalYear->transactions()
            ->active()
            ->where('is_planned', true)
            ->count();

        if ($plannedTransactionsRemaining > 0) {
            $errors[] = [
                'key' => 'planned_transactions_remaining',
                'count' => $plannedTransactionsRemaining,
            ];
        }

        [$depreciationEntriesNotPrepared, $depreciationEntriesUnposted] = $this->inspectDepreciationEntries($fiscalYear);

        if ($depreciationEntriesNotPrepared > 0) {
            $errors[] = [
                'key' => 'depreciation_entries_not_prepared',
                'count' => $depreciationEntriesNotPrepared,
            ];
        }

        if ($depreciationEntriesUnposted > 0) {
            $errors[] = [
                'key' => 'depreciation_entries_unposted',
                'count' => $depreciationEntriesUnposted,
            ];
        }

        if ($this->needsInventoryClosingWarning($fiscalYear)) {
            $warnings[] = [
                'key' => 'inventory_transfer_missing',
            ];
        }

        return [
            'closable' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    public function close(FiscalYear $fiscalYear, User $user): FiscalYear
    {
        $this->authorizeBusinessUnitAccess($fiscalYear, $user, 'この会計年度を決算する権限がありません。');

        return DB::transaction(function () use ($fiscalYear, $user): FiscalYear {
            $lockedFiscalYear = FiscalYear::query()
                ->with('businessUnit')
                ->lockForUpdate()
                ->findOrFail($fiscalYear->getKey());

            if ($lockedFiscalYear->is_closed) {
                throw new \InvalidArgumentException('この会計年度はすでに決算済みです。');
            }

            $validation = $this->validate($lockedFiscalYear);

            if ($validation['errors'] !== []) {
                throw ValidationException::withMessages($this->buildValidationMessages($validation['errors']));
            }

            $lockedFiscalYear->forceFill([
                'is_closed' => true,
                'is_active' => false,
                'closed_at' => now(),
                'closed_by' => $user->id,
            ])->saveOrFail();

            return $lockedFiscalYear->refresh();
        }, attempts: 5);
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function inspectDepreciationEntries(FiscalYear $fiscalYear): array
    {
        $depreciatingFixedAssets = $fiscalYear->businessUnit->depreciatingFixedAssets($fiscalYear);

        $entriesNotPrepared = 0;
        $entriesUnposted = 0;

        foreach ($depreciatingFixedAssets as $fixedAsset) {
            $entry = $fixedAsset->depreciationEntries
                ->firstWhere('fiscal_year_id', $fiscalYear->id);

            if ($entry === null) {
                $entriesNotPrepared++;

                continue;
            }

            if ($entry->transaction_id === null) {
                $entriesUnposted++;
            }
        }

        return [$entriesNotPrepared, $entriesUnposted];
    }

    protected function needsInventoryClosingWarning(FiscalYear $fiscalYear): bool
    {
        $inventoryBalance = $this->inventoryBalance($fiscalYear);

        if ($inventoryBalance <= 0) {
            return false;
        }

        return ! $fiscalYear->transactions()
            ->active()
            ->where('adjusting_entry_type', Transaction::ADJUSTING_ENTRY_TYPE_INVENTORY_CLOSING)
            ->exists();
    }

    protected function inventoryBalance(FiscalYear $fiscalYear): int
    {
        $summary = $fiscalYear->calculateBalanceSummary();

        foreach ($summary['asset']['accounts'] as $account) {
            if ($account['account_name'] === '棚卸資産') {
                return (int) $account['balance'];
            }
        }

        return 0;
    }

    /**
     * @param  array<int, array{key: string, count: int}>  $errors
     * @return array<string, array<int, string>>
     */
    protected function buildValidationMessages(array $errors): array
    {
        $messages = [];

        foreach ($errors as $error) {
            $messages[$error['key']][] = match ($error['key']) {
                'planned_transactions_remaining' => sprintf('未処理の予定取引が %d 件残っています。', $error['count']),
                'depreciation_entries_not_prepared' => sprintf('未準備の減価償却明細が %d 件あります。', $error['count']),
                'depreciation_entries_unposted' => sprintf('未計上の減価償却明細が %d 件あります。', $error['count']),
                default => '締め前チェックに失敗しました。',
            };
        }

        return $messages;
    }
}
