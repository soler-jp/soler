<?php

namespace App\Livewire\Dashboard;

use App\Models\BusinessUnit;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Livewire\Component;
use LogicException;

class NextFiscalYearPrompt extends Component
{
    public int $businessUnitId;

    public int $currentYear;

    public int $nextYear;

    public ?string $errorMessage = null;

    public function mount(BusinessUnit $businessUnit): void
    {
        $currentFiscalYear = $businessUnit->currentFiscalYear;

        if ($currentFiscalYear === null) {
            throw new LogicException('NextFiscalYearPrompt は current fiscal year が設定されている事業体でのみ利用できます。');
        }

        $this->businessUnitId = $businessUnit->id;
        $this->currentYear = $currentFiscalYear->year;
        $this->nextYear = $currentFiscalYear->year + 1;
    }

    public function start(): mixed
    {
        $this->errorMessage = null;

        $user = auth()->user();

        if (! $user instanceof User) {
            throw new LogicException('NextFiscalYearPrompt は認証済みユーザーからのみ利用できます。');
        }

        $businessUnit = BusinessUnit::query()->findOrFail($this->businessUnitId);
        $currentFiscalYear = $businessUnit->currentFiscalYear;

        if ($currentFiscalYear === null) {
            $this->errorMessage = '現在の会計年度が設定されていません。';

            return null;
        }

        if ($businessUnit->fiscalYears()->where('year', $currentFiscalYear->year + 1)->exists()) {
            $this->errorMessage = '翌年度はすでに作成されています。';

            return null;
        }

        try {
            $nextFiscalYear = $businessUnit->createNextFiscalYearFrom($currentFiscalYear, $user);
            $businessUnit->activateFiscalYear($nextFiscalYear, $user);
        } catch (AuthorizationException|DomainException|InvalidArgumentException $exception) {
            $this->errorMessage = $exception->getMessage();

            return null;
        }

        session()->flash('message', sprintf('%d年度の会計を開始しました。', $nextFiscalYear->year));

        return $this->redirect(route('dashboard'), navigate: false);
    }

    public function render()
    {
        return view('livewire.dashboard.next-fiscal-year-prompt');
    }
}
