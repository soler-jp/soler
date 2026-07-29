<?php

namespace App\Services;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class FiscalYearRollover
{
    use AuthorizesBusinessUnitAccess;

    public function rollover(FiscalYear $closedYear, FiscalYear $nextYear, User $actor): Transaction
    {
        return DB::transaction(function () use ($closedYear, $nextYear, $actor): Transaction {
            $closedYear = FiscalYear::query()
                ->with('businessUnit')
                ->lockForUpdate()
                ->findOrFail($closedYear->getKey());

            $nextYear = FiscalYear::query()
                ->with('businessUnit')
                ->lockForUpdate()
                ->findOrFail($nextYear->getKey());

            $this->authorizeBusinessUnitAccess($closedYear, $actor, 'この会計年度を繰り越す権限がありません。');

            $this->ensureRolloverable($closedYear, $nextYear);

            $rolloverData = $closedYear->calculateRolloverData();

            $openingTransaction = app(OpeningEntryRegistrar::class)->registerForRollover(
                $nextYear,
                $rolloverData['opening_entries'],
                $rolloverData['capital_entry'],
            );

            if ($openingTransaction === null) {
                throw new DomainException('繰越する残高がありません。');
            }

            return $openingTransaction;
        }, attempts: 5);
    }

    protected function ensureRolloverable(FiscalYear $closedYear, FiscalYear $nextYear): void
    {
        if (! $closedYear->is_closed) {
            throw new DomainException('締め済みの会計年度のみ繰越できます。');
        }

        if ($closedYear->business_unit_id !== $nextYear->business_unit_id) {
            throw new DomainException('繰越元と繰越先は同じ事業体でなければなりません。');
        }

        if ($nextYear->year !== $closedYear->year + 1) {
            throw new DomainException('繰越先は翌年度でなければなりません。');
        }

        if ($nextYear->transactions()->where('is_opening_entry', true)->exists()) {
            throw new DomainException('繰越先の会計年度にはすでに期首仕訳があります。');
        }
    }
}
