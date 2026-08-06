<?php

namespace App\Livewire\Layout;

use App\Models\BusinessUnit;
use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use LogicException;

class FiscalYearSwitcher extends Component
{
    public ?string $errorMessage = null;

    #[Computed]
    public function businessUnit(): ?BusinessUnit
    {
        return auth()->user()?->selectedBusinessUnit;
    }

    #[Computed]
    public function currentFiscalYear(): ?FiscalYear
    {
        return $this->businessUnit?->currentFiscalYear;
    }

    /**
     * @return Collection<int, FiscalYear>
     */
    #[Computed]
    public function switchableFiscalYears(): Collection
    {
        return $this->businessUnit?->switchableFiscalYears() ?? collect();
    }

    #[Computed]
    public function shouldShow(): bool
    {
        return $this->switchableFiscalYears->isNotEmpty();
    }

    public function switchTo(int $fiscalYearId): mixed
    {
        $this->errorMessage = null;

        $user = auth()->user();

        if (! $user instanceof User) {
            throw new LogicException('FiscalYearSwitcher は認証済みユーザーからのみ利用できます。');
        }

        $businessUnit = $this->businessUnit;

        if ($businessUnit === null) {
            $this->errorMessage = '切り替え先の会計年度が見つかりません。';

            return null;
        }

        $target = $businessUnit->switchableFiscalYears()
            ->firstWhere('id', $fiscalYearId);

        if ($target === null) {
            $this->errorMessage = '切り替え先の会計年度が見つかりません。';

            return null;
        }

        try {
            $businessUnit->activateFiscalYear($target, $user);
        } catch (AuthorizationException|InvalidArgumentException $exception) {
            $this->errorMessage = $exception->getMessage();

            return null;
        }

        return $this->redirect(request()->header('Referer', route('dashboard')), navigate: false);
    }

    public function render()
    {
        return view('livewire.layout.fiscal-year-switcher');
    }
}
