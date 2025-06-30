<?php

namespace App\Livewire;

use Livewire\Component;
use App\Setup\Initializers\GeneralBusinessInitializer;

class SetupWizard extends Component
{
    public int $step = 1;

    public string $name = '一般事業所';
    public string $business_type = 'general';
    public bool $is_taxable = false;
    public bool $is_tax_exclusive = false;
    public int|null $year = null;
    public int|null $cash_balance = null;
    public array $bank_accounts = [];
    public array $other_assets = [];
    public string $submitError = '';

    protected function rulesPerStep(): array
    {
        return [
            1 => [
                'name' => ['required', 'string'],
                'business_type' => ['required', 'in:general,agriculture,real_estate'],
            ],
            2 => [
                'year' => ['required', 'integer'],
                'is_taxable' => ['required', 'boolean'],
                'is_tax_exclusive' => ['required', 'boolean'],
            ],
            3 => [
                'cash_balance' => ['nullable', 'integer', 'min:0'],
            ],
            4 => [
                'bank_accounts.*.sub_account_name' => ['required', 'string'],
                'bank_accounts.*.amount' => ['required', 'integer', 'min:0'],
            ],
            5 => [
                'other_assets.*.account_name' => ['required', 'string'],
                'other_assets.*.sub_account_name' => ['nullable', 'string'],
                'other_assets.*.amount' => ['required', 'integer', 'min:0'],
            ],

        ];
    }

    public function next()
    {
        $rules = $this->rulesPerStep()[$this->step] ?? [];
        $this->validate($rules);
        $this->step++;
    }


    public function submit()
    {
        $allRules = collect($this->rulesPerStep())->collapse()->all();
        $this->validate($allRules);

        if ($this->is_taxable || $this->is_tax_exclusive) {
            $this->submitError = '現時点では免税事業者・税込経理のみ対応しています。';
            throw new \InvalidArgumentException($this->submitError);
        }


        $opening_entries = [];

        if (!is_null($this->cash_balance)) {
            $opening_entries[] = [
                'account_name' => '現金',
                'sub_account_name' => null,
                'amount' => $this->cash_balance,
            ];
        }

        foreach ($this->bank_accounts as $bankAccount) {
            $opening_entries[] = [
                'account_name' => 'その他の預金',
                'sub_account_name' => $bankAccount['sub_account_name'],
                'amount' => $bankAccount['amount'],
            ];
        }

        foreach ($this->other_assets as $asset) {
            $opening_entries[] = [
                'account_name' => $asset['account_name'],
                'sub_account_name' => $asset['sub_account_name'] ?? null,
                'amount' => $asset['amount'],
            ];
        }

        $inputs = [
            'name' => $this->name,
            'type' => $this->business_type,
            'year' => $this->year,
            'is_taxable' => $this->is_taxable,
            'is_tax_exclusive' => $this->is_tax_exclusive,
            'opening_entries' => $opening_entries,
        ];

        try {
            $initializer = new GeneralBusinessInitializer();
            $initializer->initialize(auth()->user(), $inputs);

            return $this->redirect(route('dashboard'));
        } catch (\InvalidArgumentException $e) {
            $this->submitError = $e->getMessage();
        } catch (\Throwable $e) {
            $this->submitError = '登録中に予期せぬエラーが発生しました。';
            Log::error($e);
        }
    }

    public function addBankAccount()
    {
        $this->bank_accounts[] = [
            'sub_account_name' => '',
            'amount' => 0,
        ];
    }

    public function removeBankAccount($index)
    {
        unset($this->bank_accounts[$index]);
        $this->bank_accounts = array_values($this->bank_accounts);
    }

    public function addOtherAsset()
    {
        $this->other_assets[] = [
            'account_name' => '',
            'sub_account_name' => '',
            'amount' => 0,
        ];
    }

    public function removeOtherAsset($index)
    {
        unset($this->other_assets[$index]);
        $this->other_assets = array_values($this->other_assets);
    }


    public function mount()
    {
        $this->year = (int)  date('Y');
    }

    public function render()
    {
        return view('livewire.setup-wizard');
    }
}
