<?php

namespace App\Livewire\FiscalYearClosing;

use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InventoryClosingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use LogicException;

class InventoryClosingSection extends Component
{
    #[Locked]
    public int $fiscalYearId;

    public ?string $noticeMessage = null;

    public ?string $errorMessage = null;

    /**
     * @var array<int, int|string>
     */
    public array $closingAmounts = [];

    public function mount(int $fiscalYearId): void
    {
        $this->fiscalYearId = $fiscalYearId;

        foreach ($this->loadPreview()['sub_accounts'] as $sub) {
            $this->closingAmounts[$sub['id']] = 0;
        }
    }

    public function register(): void
    {
        $this->noticeMessage = null;
        $this->errorMessage = null;

        $fiscalYear = $this->requireFiscalYear();
        $user = $this->requireUser();

        $normalized = [];

        foreach ($this->closingAmounts as $subAccountId => $amount) {
            $normalized[(int) $subAccountId] = is_string($amount) ? trim($amount) : $amount;
        }

        try {
            $transaction = app(InventoryClosingService::class)->registerFor($fiscalYear, $normalized, $user);
        } catch (AuthorizationException|InvalidArgumentException $exception) {
            $this->errorMessage = $exception->getMessage();

            return;
        }

        $this->noticeMessage = $transaction instanceof Transaction
            ? __('fiscal_year_closing.inventory.success')
            : __('fiscal_year_closing.inventory.noop');
    }

    public function render(): View
    {
        $fiscalYear = $this->requireFiscalYear();
        $preview = $this->loadPreview();

        return view('livewire.fiscal-year-closing.inventory-closing-section', [
            'fiscalYear' => $fiscalYear,
            'preview' => $preview,
        ]);
    }

    private function loadPreview(): array
    {
        return app(InventoryClosingService::class)->previewFor(
            $this->requireFiscalYear(),
            $this->requireUser(),
        );
    }

    private function requireFiscalYear(): FiscalYear
    {
        return FiscalYear::query()->findOrFail($this->fiscalYearId);
    }

    private function requireUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new LogicException('InventoryClosingSection は認証済みユーザーからのみ利用できます。');
        }

        return $user;
    }
}
