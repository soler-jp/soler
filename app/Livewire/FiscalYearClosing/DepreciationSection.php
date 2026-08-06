<?php

namespace App\Livewire\FiscalYearClosing;

use App\Models\DepreciationEntry;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\DepreciationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use LogicException;

class DepreciationSection extends Component
{
    #[Locked]
    public int $fiscalYearId;

    public ?string $noticeMessage = null;

    public ?string $errorMessage = null;

    /**
     * @var array<int, int|string>
     */
    public array $businessUsagePercents = [];

    public function mount(int $fiscalYearId): void
    {
        $this->fiscalYearId = $fiscalYearId;

        foreach ($this->loadPreview() as $item) {
            $this->businessUsagePercents[$item['entry_id']] = (int) round($item['business_usage_ratio'] * 100);
        }
    }

    public function post(int $entryId): void
    {
        $this->noticeMessage = null;
        $this->errorMessage = null;

        $user = $this->requireUser();
        $entry = DepreciationEntry::query()->findOrFail($entryId);

        $rawPercent = $this->businessUsagePercents[$entryId] ?? null;

        if ($rawPercent === null || $rawPercent === '' || ! is_numeric($rawPercent)) {
            $this->errorMessage = __('fiscal_year_closing.depreciation.invalid_percent');

            return;
        }

        $ratio = ((float) $rawPercent) / 100;

        try {
            app(DepreciationService::class)->postWithRatio($entry, $ratio, $user);
        } catch (AuthorizationException|InvalidArgumentException $exception) {
            $this->errorMessage = $exception->getMessage();

            return;
        }

        $this->noticeMessage = __('fiscal_year_closing.depreciation.success', [
            'name' => $entry->fixedAsset->name,
        ]);
    }

    public function render(): View
    {
        return view('livewire.fiscal-year-closing.depreciation-section', [
            'fiscalYear' => $this->requireFiscalYear(),
            'items' => $this->loadPreview(),
        ]);
    }

    /**
     * @return array<int, array{
     *     fixed_asset_id: int,
     *     entry_id: int,
     *     name: string,
     *     total_amount: int,
     *     business_usage_ratio: float,
     *     deductible_amount: int,
     *     is_posted: bool,
     * }>
     */
    private function loadPreview(): array
    {
        return app(DepreciationService::class)->previewFor(
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
            throw new LogicException('DepreciationSection は認証済みユーザーからのみ利用できます。');
        }

        return $user;
    }
}
