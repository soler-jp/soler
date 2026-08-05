<?php

namespace App\Livewire\Dashboard;

use App\Models\FiscalYear;
use Livewire\Component;

// TODO: `ProfitSummary` は経営サマリー全体（売上・経費・仕入れ・差し引き・現金）を表示する
// コンテナだが、名前が「利益」に限定されて誤解を招く。`Dashboard/ManagementSummary` にリネームし、
// 併せて子の `MonthlyAccountTypePanel` / `MonthlyTransactionTable` を
// `Dashboard/ManagementSummary/AccountPanel` / `TransactionTable` に揃える。
class ProfitSummary extends Component
{
    public int $fiscalYearId;

    public function mount(FiscalYear $fiscalYear): void
    {
        $this->fiscalYearId = $fiscalYear->id;
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     type: string,
     *     title: string,
     *     variant: string,
     *     amount?: int,
     *     note_lines?: array<int, string>,
     *     account_type?: string,
     *     account_names?: array<int, string>,
     *     excluded_account_names?: array<int, string>
     * }>
     */
    public function cards(): array
    {
        return FiscalYear::query()->findOrFail($this->fiscalYearId)->managementSummaryCards();
    }

    public function render()
    {
        return view('livewire.dashboard.profit-summary');
    }
}
