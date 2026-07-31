<?php

namespace App\Livewire;

use App\Models\InitialSetupData;
use App\Setup\Initializers\GeneralBusinessInitializer;
use Livewire\Component;

class SetupWizard extends Component
{
    private const MIN_SUPPORTED_YEAR = 2023;

    public int $step = 1;

    public int $max_unlocked_step = 1;

    public string $name = '個人事業';

    public string $business_type = 'general';

    public ?int $year = null;

    public string $submitError = '';

    public string $opening_context = InitialSetupData::OPENING_CONTEXT_FIRST_YEAR;

    public string $bank_account_answer = '';

    public string $cash_on_hand_answer = '';

    public string $fixed_asset_answer = '';

    public string $recurring_expense_answer = '';

    public string $recurring_income_answer = '';

    public string $counterparty_answer = '';

    public bool $is_taxable = false;

    protected function rulesPerStep(): array
    {
        return [
            1 => [
                'name' => ['required', 'string'],
            ],
            2 => [
                'year' => ['required', 'integer', 'in:'.implode(',', $this->availableYears())],
            ],
            3 => [
                'opening_context' => ['required', 'in:'.implode(',', InitialSetupData::OPENING_CONTEXTS)],
            ],
            4 => [
                'bank_account_answer' => ['required', 'in:'.implode(',', InitialSetupData::BINARY_ANSWERS)],
                'cash_on_hand_answer' => ['required', 'in:'.implode(',', InitialSetupData::BINARY_ANSWERS)],
                'fixed_asset_answer' => ['required', 'in:'.implode(',', InitialSetupData::BINARY_ANSWERS)],
                'recurring_expense_answer' => ['required', 'in:'.implode(',', InitialSetupData::BINARY_ANSWERS)],
                'recurring_income_answer' => ['required', 'in:'.implode(',', InitialSetupData::BINARY_ANSWERS)],
                'counterparty_answer' => ['required', 'in:'.implode(',', InitialSetupData::BINARY_ANSWERS)],
            ],
            5 => [
                'is_taxable' => ['required', 'boolean'],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        // TODO: Move these validation strings into the framework language catalog when the broader i18n pass is done.
        return [
            'required' => ':attributeを選択してください。',
            'name.required' => '事業名を入力してください。',
            'year.in' => '記録を始める年を選択してください。',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'name' => '事業名',
            'year' => '記録を始める年',
            'opening_context' => '開始状態',
            'bank_account_answer' => '銀行口座の有無',
            'cash_on_hand_answer' => '現金の有無',
            'fixed_asset_answer' => '固定資産の有無',
            'recurring_expense_answer' => '定期的な支払いの有無',
            'recurring_income_answer' => '定期的な収入の有無',
            'counterparty_answer' => '取引相手の有無',
            'is_taxable' => '消費税申告の要否',
        ];
    }

    public function next(): void
    {
        $rules = $this->rulesPerStep()[$this->step] ?? [];
        $this->validate($rules);
        $this->step++;
        $this->max_unlocked_step = max($this->max_unlocked_step, $this->step);
    }

    public function goToStep(int $targetStep): void
    {
        if ($targetStep < 1 || $targetStep > 6) {
            return;
        }

        if ($targetStep > $this->max_unlocked_step) {
            return;
        }

        if ($targetStep === $this->step) {
            return;
        }

        $this->step = $targetStep;
    }

    public function submit()
    {
        $allRules = collect($this->rulesPerStep())->collapse()->all();
        $this->validate($allRules);

        $inputs = [
            'name' => $this->name,
            'type' => $this->business_type,
            'year' => $this->year,
            'is_taxable' => $this->is_taxable,
            'is_tax_exclusive' => false,
            'opening_context' => $this->opening_context,
            'bank_account_answer' => $this->bank_account_answer,
            'cash_on_hand_answer' => $this->cash_on_hand_answer,
            'fixed_asset_answer' => $this->fixed_asset_answer,
            'recurring_expense_answer' => $this->recurring_expense_answer,
            'recurring_income_answer' => $this->recurring_income_answer,
            'counterparty_answer' => $this->counterparty_answer,
        ];

        try {
            $initializer = app(GeneralBusinessInitializer::class);
            $initializer->initialize(auth()->user(), $inputs);

            return $this->redirect(route('dashboard'));
        } catch (\InvalidArgumentException $e) {
            $this->submitError = $e->getMessage();
            \Log::error($e);
        } catch (\Throwable $e) {
            $this->submitError = '登録中に予期せぬエラーが発生しました。';
            \Log::error($e);
        }
    }

    public function answerLabel(string $answer): string
    {
        return match ($answer) {
            InitialSetupData::ANSWER_YES => 'はい',
            InitialSetupData::ANSWER_NO => 'いいえ',
            default => '',
        };
    }

    public function consumptionTaxStatusLabel(): string
    {
        return $this->is_taxable ? '必要' : '不要';
    }

    public function mount(): void
    {
        $this->year = (int) date('Y');
    }

    public function render()
    {
        return view('livewire.setup-wizard', [
            'availableYears' => $this->availableYears(),
            'setupQuestions' => $this->setupQuestions(),
        ]);
    }

    public function yearLabel(): string
    {
        return sprintf('%d年', $this->year);
    }

    /**
     * @return array<int, array{key: string, field: string, title: string, description: string}>
     */
    private function setupQuestions(): array
    {
        return [
            [
                'key' => InitialSetupData::KEY_BANK_ACCOUNT,
                'field' => 'bank_account_answer',
                'title' => '事業専用の銀行口座はありますか？',
                'description' => '事業専用の銀行口座があれば「はい」を選んでください。生活費にも使っている口座は、この質問には含めません。',
            ],
            [
                'key' => InitialSetupData::KEY_CASH_ON_HAND,
                'field' => 'cash_on_hand_answer',
                'title' => '事業用として管理している現金はありますか？',
                'description' => 'レジ現金、金庫などがある場合は「はい」を選んでください。普段使いの財布の中のお金は関係ありません。',
            ],
            [
                'key' => InitialSetupData::KEY_FIXED_ASSET,
                'field' => 'fixed_asset_answer',
                'title' => '仕事で1年以上使うもののうち、1つ（または1組）で10万円以上したものはありますか？',
                'description' => 'パソコン、車、家具など。開業前から持っていたものや、仕事と私生活の両方で使うものも含みます。',
            ],
            [
                'key' => InitialSetupData::KEY_RECURRING_EXPENSE,
                'field' => 'recurring_expense_answer',
                'title' => '定期的な支払いはありますか？',
                'description' => '家賃・スマホ代・インターネット代・車検代などの支払いがある場合は「はい」を選んでください。',
            ],
            [
                'key' => InitialSetupData::KEY_RECURRING_INCOME,
                'field' => 'recurring_income_answer',
                'title' => '定期的な収入はありますか？',
                'description' => 'ピアノ教室で月謝を受け取ったり、業務委託で毎月の謝金があったり、1年分のHPの運用管理費などの収入がある場合は「はい」を選んでください。',
            ],
            [
                'key' => InitialSetupData::KEY_COUNTERPARTY,
                'field' => 'counterparty_answer',
                'title' => 'よく請求する相手や、よく支払う相手はありますか？',
                'description' => '登録しておくと、売上や支払いの入力が楽になります。あとからでも登録できます。',
            ],
        ];
    }

    /**
     * @return array<int, int>
     */
    private function availableYears(): array
    {
        return range(self::MIN_SUPPORTED_YEAR, (int) date('Y'));
    }
}
