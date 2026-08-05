<?php

use App\Models\Account;
use App\Models\SubAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 追加する勘定科目の定義。
     * sort_order は BusinessUnit::$prioritizedDefaultSubAccounts の末尾（既存 70）に続き、
     * 80 から 10 刻みで割り当てる。
     *
     * @var array<int, array{name: string, example: string, caution: string, sort_order: int}>
     */
    private array $accounts = [
        [
            'name' => '車両費',
            'example' => '事業で使用する自動車のガソリン代、駐車料金、有料道路料金、修理代など',
            'caution' => '私生活でも使用する自動車の費用は、事業で使用した分だけを経費にします。自動車の購入費や資産価値を高める改良費は、固定資産として減価償却する場合があります。',
            'sort_order' => 80,
        ],
        [
            'name' => '新聞図書費',
            'example' => '事業に関連する新聞、書籍、専門誌、業界紙、電子書籍などの購入費',
            'caution' => '個人的な趣味や生活のために購入したものは経費になりません。事業との関係を説明できるものだけを記録します。',
            'sort_order' => 90,
        ],
        [
            'name' => '支払手数料',
            'example' => '振込手数料、決済サービスの利用手数料、販売サービスの手数料など',
            'caution' => '固定資産の購入に直接かかった仲介手数料などは、その年の経費ではなく、固定資産の取得価額に含める場合があります。',
            'sort_order' => 100,
        ],
        [
            'name' => '会議費',
            'example' => '事業上の打ち合わせに使用した会議室代、飲み物代、軽食代など',
            'caution' => '取引先などをもてなすことが主な目的の飲食費や贈答費は、接待交際費として扱う場合があります。',
            'sort_order' => 110,
        ],
        [
            'name' => '研修費',
            'example' => '事業に必要な知識や技術を学ぶための研修、セミナー、講習会、オンライン講座などの参加費',
            'caution' => '事業と直接関係しない講座や、個人的な教養・趣味を目的とした学習費用は経費になりません。',
            'sort_order' => 120,
        ],
        [
            'name' => '諸会費',
            'example' => '商工会議所、商工会、同業者団体、協同組合、商店会、青色申告会などの会費や組合費',
            'caution' => '事業と直接関係しない団体や、個人的な交流を目的とする団体の会費は経費にならない場合があります。',
            'sort_order' => 130,
        ],
        [
            'name' => '繰延資産償却',
            'example' => '開業費、開発費などの繰延資産を当年の必要経費にするための償却額',
            'caution' => '車両、パソコン、建物などの固定資産に係る減価償却費とは区別します。ただし、青色申告決算書では減価償却費に合算します。',
            'sort_order' => 140,
        ],
    ];

    public function up(): void
    {
        $now = now();

        DB::transaction(function () use ($now): void {
            DB::table('business_units')->orderBy('id')->each(function ($businessUnit) use ($now): void {
                foreach ($this->accounts as $definition) {
                    $accountId = DB::table('accounts')
                        ->where('business_unit_id', $businessUnit->id)
                        ->where('name', $definition['name'])
                        ->value('id');

                    if ($accountId === null) {
                        $accountId = DB::table('accounts')->insertGetId([
                            'business_unit_id' => $businessUnit->id,
                            'name' => $definition['name'],
                            'type' => Account::TYPE_EXPENSE,
                            'example' => $definition['example'],
                            'caution' => $definition['caution'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    DB::table('sub_accounts')->updateOrInsert(
                        ['account_id' => $accountId, 'name' => $definition['name']],
                        [
                            'visibility' => SubAccount::VISIBILITY_STANDARD,
                            'sort_order' => $definition['sort_order'],
                            'updated_at' => $now,
                            'created_at' => $now,
                        ],
                    );
                }
            });
        });
    }

    public function down(): void
    {
        $accountNames = array_column($this->accounts, 'name');

        DB::transaction(function () use ($accountNames): void {
            $accountIds = DB::table('accounts')
                ->whereIn('name', $accountNames)
                ->where('type', Account::TYPE_EXPENSE)
                ->pluck('id');

            DB::table('sub_accounts')->whereIn('account_id', $accountIds)->delete();
            DB::table('accounts')->whereIn('id', $accountIds)->delete();
        });
    }
};
