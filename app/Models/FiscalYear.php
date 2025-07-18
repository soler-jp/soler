<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Services\TransactionRegistrar;
use App\Models\Transaction;
use App\Models\BusinessUnit;
use App\Models\JournalEntry;
use App\Models\SubAccount;
use Illuminate\Support\Facades\DB;


class FiscalYear extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_unit_id',
        'year',
        'is_active',
        'is_closed',    // 決算済フラグ
        'is_taxable',   // 課税事業者ならtrue, 免税事業者なfalse
        'is_tax_exclusive',  // 税抜経理ならtrue, 税込経理ならfalse
        'start_date',
        'end_date',

    ];

    protected $casts = [
        'is_taxable' => 'boolean',
        'is_tax_exclusive' => 'boolean',
        'is_active' => 'boolean',
        'is_closed'  => 'boolean',
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    public function businessUnit()
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function journalEntries()
    {
        return $this->hasManyThrough(
            JournalEntry::class,
            Transaction::class
        );
    }

    public function registerTransaction(
        array $transactionData,
        array $journalEntriesData,
        ?TransactionRegistrar $registrar = null
    ): Transaction {
        $registrar ??= app(TransactionRegistrar::class);

        return $registrar->register($this, $transactionData, $journalEntriesData);
    }

    public function calculateSummary(): array
    {
        // 実績（is_planned = false）
        $actualIncome = $this->journalEntries()
            ->whereHas('transaction', fn($q) => $q->where('is_planned', false))
            ->whereHas('subAccount.account', fn($q) => $q->where('type', 'revenue'))
            ->where('type', 'credit')
            ->sum(\DB::raw('amount + COALESCE(tax_amount, 0)'));

        $actualExpense = $this->journalEntries()
            ->whereHas('transaction', fn($q) => $q->where('is_planned', false))
            ->whereHas('subAccount.account', fn($q) => $q->where('type', 'expense'))
            ->where('type', 'debit')
            ->sum(\DB::raw('amount + COALESCE(tax_amount, 0)'));

        // 予定（is_planned = true）
        $plannedIncome = $this->journalEntries()
            ->whereHas('transaction', fn($q) => $q->where('is_planned', true))
            ->whereHas('subAccount.account', fn($q) => $q->where('type', 'revenue'))
            ->where('type', 'credit')
            ->sum(\DB::raw('amount + COALESCE(tax_amount, 0)'));

        $plannedExpense = $this->journalEntries()
            ->whereHas('transaction', fn($q) => $q->where('is_planned', true))
            ->whereHas('subAccount.account', fn($q) => $q->where('type', 'expense'))
            ->where('type', 'debit')
            ->sum(\DB::raw('amount + COALESCE(tax_amount, 0)'));

        return [
            'actual' => [
                'total_income' => (int) $actualIncome,
                'total_expense' => (int) $actualExpense,
                'profit' => (int) ($actualIncome - $actualExpense),
            ],
            'planned' => [
                'total_income' => (int) $plannedIncome,
                'total_expense' => (int) $plannedExpense,
                'profit' => (int) ($plannedIncome - $plannedExpense),
            ],
        ];
    }



    public function registerOpeningEntry(array $entries): ?Transaction
    {
        if ($entries === []) {
            return null;
        }


        return \DB::transaction(function () use ($entries) {

            $allowedDebitAccounts = ['現金', '定期預金', 'その他の預金', '車両運搬具', '棚卸資産'];

            $transactionData = [
                'date' => $this->start_date,
                'description' => '期首残高設定',
                'is_opening_entry' => true,
                'fiscal_year_id' => $this->id,
            ];

            $journalEntriesData = [];
            $totalAmount = 0;

            $capitalAccount = $this->businessUnit->accounts()
                ->where('name', '元入金')
                ->firstOrFail();

            $capitalSub = $capitalAccount->subAccounts()->firstOrCreate([
                'name' => $capitalAccount->name,
            ]);

            foreach ($entries as $entry) {


                if (empty($entry['sub_account_name'])) {
                    throw new \InvalidArgumentException('sub_account_name は必須です。');
                }

                if (!isset($entry['amount']) || !is_numeric($entry['amount']) || $entry['amount'] <= 0) {
                    throw new \InvalidArgumentException("金額が不正です: {$entry['amount']}");
                }


                $account = $this->businessUnit->accounts()
                    ->where('name', $entry['account_name'])
                    ->firstOrFail();


                $subAccount = $account->subAccounts()->firstOrCreate([
                    'name' => $entry['sub_account_name'],
                ]);

                if (!$subAccount) {
                    throw new \InvalidArgumentException("補助科目「{$entry['sub_account_name']}」が存在しませんが、 account_nameも指定されていません。");
                }

                if (!in_array($account->name, $allowedDebitAccounts)) {
                    throw new \InvalidArgumentException(
                        '借方の勘定科目は「現金」「定期預金」「その他の預金」「車両運搬具」「棚卸資産」のみ使用できます。'
                    );
                }

                $journalEntriesData[] = [
                    'sub_account_id' => $subAccount->id,
                    'type' => 'debit',
                    'amount' => (int) $entry['amount'],

                ];

                $totalAmount += $entry['amount'];
            }

            $journalEntriesData[] = [
                'sub_account_id' => $capitalSub->id,
                'type' => 'credit',
                'amount' => $totalAmount,
            ];

            return $this->registerTransaction($transactionData, $journalEntriesData);
        });
    }
}
