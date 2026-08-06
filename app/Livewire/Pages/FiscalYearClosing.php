<?php

namespace App\Livewire\Pages;

use App\Models\BusinessUnit;
use App\Models\FiscalYear;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use LogicException;

class FiscalYearClosing extends Component
{
    public function render(): View
    {
        $fiscalYear = $this->requireFiscalYear();

        return view('livewire.pages.fiscal-year-closing', [
            'fiscalYear' => $fiscalYear,
        ]);
    }

    private function requireFiscalYear(): FiscalYear
    {
        $fiscalYear = $this->selectedBusinessUnit()?->currentFiscalYear;

        if ($fiscalYear === null) {
            throw new LogicException('FiscalYearClosing は current fiscal year が設定されている事業体でのみ利用できます。');
        }

        return $fiscalYear;
    }

    private function selectedBusinessUnit(): ?BusinessUnit
    {
        return auth()->user()?->selectedBusinessUnit;
    }
}
