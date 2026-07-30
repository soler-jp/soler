# Business Unit Multi User Design

このドキュメントは、`BusinessUnit` を複数ユーザーで共同利用するための完成形の設計を整理したものである。

この設計では、既存データとの互換性や段階移行は考えない。
既存データは引き継がず、新しいモデル構成で再登録する前提とする。

ここで扱うのは次の範囲である。

- `BusinessUnit` への所属
- role ベースの権限制御
- 現在選択中の `BusinessUnit`
- マルチユーザー化に伴う認可境界

承認フローやレビュー履歴は、このドキュメントの対象外とする。

## 目的

- 1 つの `BusinessUnit` に複数ユーザーが所属できるようにする
- 1 人のユーザーが複数の `BusinessUnit` に所属できるようにする
- 税理士のような外部ユーザーが複数事業所へアクセスできるようにする
- `manager` `registrar` `viewer` の role を事業所ごとに持てるようにする

## 基本方針

`User` と `BusinessUnit` は多対多で結ぶ。

- 1 `User` は複数の `BusinessUnit` に所属できる
- 1 `BusinessUnit` は複数の `User` を持てる
- role は `User` 自体ではなく、`BusinessUnit` ごとの membership に持たせる

これにより、同じユーザーが次のように振る舞える。

- A事業所では `manager`
- B事業所では `viewer`
- C事業所では `registrar`

そのうえで、`BusinessUnit` には責任者を表す `responsible_user_id` を持たせる。

## 用語

「責任者」と「role」は別概念であり、名前も分ける。

### 責任者

- 保存場所: `business_units.responsible_user_id`
- 意味: その事業所の責任を負う 1 人を一意に決める
- 認可: 使わない

### role

- 保存場所: `business_unit_memberships.role`
- 意味: その事業所で何ができるかを表す権限区分
- 認可: 使う

### role の値

| 値 | 意味 |
| --- | --- |
| `manager` | 事業所設定とメンバー管理ができる |
| `registrar` | 帳簿データを登録・更新できる |
| `viewer` | 閲覧のみできる |

責任者は「所有者」ではない。移譲によって変わるため、作成者でもない。
「今この事業所の責任を負っているのは誰か」を指すだけの列である。

認可の判断材料は role だけとし、`responsible_user_id` は認可判定に使わない。
この分離を崩すと、片方だけを見るコードが混ざって権限バグになる。

## データモデル

### migration 方針

既存アプリから移行する場合は、`business_units.user_id` を
`business_units.responsible_user_id` へ変更することを前提とする。

これは単なるカラム名変更ではなく、意味の変更でもある。

- 変更前の `user_id`
  - 事業所の唯一の所有者・権限保持者のように使われている
- 変更後の `responsible_user_id`
  - その事業所の責任者 1 人を表す
  - 認可には使わない

したがって migration 時には、少なくとも次を同時に行う。

- `business_units.user_id` を `responsible_user_id` へ変更する
- `business_unit_memberships` を作成する
- 旧 `user_id` のユーザーへ `ROLE_MANAGER` の membership を補完する
- アプリコード上の `business_units.user_id === $user->id` 前提を廃止する

この migration 以後、権限判定は `business_unit_memberships.role` を使う。
`responsible_user_id` は責任者を表す列としてだけ扱う。

### テーブル構成

想定する主要テーブルは次の通り。

- `users`
- `business_units`
- `business_unit_memberships`

`business_unit_memberships` は、`User` と `BusinessUnit` の所属関係と role を表す。

### `business_units`

`business_units` は事業所そのものを表す。

責任者を表すカラムを 1 つ持つ。

```php
$table->foreignId('responsible_user_id')
    ->constrained('users')
    ->restrictOnDelete()
    ->comment('この事業所の責任者。managerのmembershipを必ず持つ');
```

`responsible_user_id` は NOT NULL とする。
これにより、責任者不在の `BusinessUnit` が存在し得ない状態を DB レベルで保証する。

`restrictOnDelete()` にするのは、責任者のユーザーレコードが消えることで
アクセスできない `BusinessUnit` が残る事故を防ぐためである。
退会時の具体的な扱いは「ユーザー退会時の扱い」で定義する。

### `business_unit_memberships`

`business_unit_memberships` は所属関係と role を保持する。

想定カラム:

- `id`
- `business_unit_id`
- `user_id`
- `role`
- `created_at`
- `updated_at`

制約:

- `unique(['business_unit_id', 'user_id'])`
- `business_unit_id` 外部キーは `cascadeOnDelete()`
- `user_id` 外部キーは `cascadeOnDelete()`

`id` を持つため、単なる pivot ではなく独立したモデルとして扱う。

モデル名は `BusinessUnitMembership` を想定する。

### `role` カラムの型

`role` は `enum` ではなく `string` とし、値の妥当性はモデル定数とバリデーションで担保する。

```php
$table->string('role')->comment('この事業所での役割');
```

このアプリでは `enum` カラムを使っている先例があるが、
`journal_entries.tax_type` のように後から値を追加するたびにマイグレーションが必要になる。

role は将来増える可能性が高いため、`string` を採る。

### `BusinessUnitMembership` の role 定数

role は生文字列を直接散らさず、モデル定数で扱う。

```php
public const ROLE_MANAGER = 'manager';
public const ROLE_REGISTRAR = 'registrar';
public const ROLE_VIEWER = 'viewer';

public const ROLES = [
    self::ROLE_MANAGER,
    self::ROLE_REGISTRAR,
    self::ROLE_VIEWER,
];

/** 通常のメンバー管理画面から指定できる role */
public const ASSIGNABLE_ROLES = [
    self::ROLE_MANAGER,
    self::ROLE_REGISTRAR,
    self::ROLE_VIEWER,
];
```

`ROLES` は「値として妥当な role の一覧」であり、
`ASSIGNABLE_ROLES` は「メンバー追加・role 変更の入力として受け付ける role の一覧」である。

メンバー追加・role 変更の入力は `Rule::in(BusinessUnitMembership::ASSIGNABLE_ROLES)` で検証する。

テストコードでも生文字列は使わず、この定数を参照する。

### 不変条件

この設計が常に満たすべき条件は次の 2 つである。

1. `business_units.responsible_user_id` のユーザーは、その `BusinessUnit` に `ROLE_MANAGER` の membership を持つ
2. 責任者の membership は、単独では削除も降格もできない

責任者を変えるには、後述の移譲処理を通す。

この 2 つを守る限り、責任者が管理権限を持たない状態は発生しない。
`ROLE_MANAGER` の membership 自体は複数件存在してよい。

これらは DB 制約だけでは閉じない。
`responsible_user_id` の NOT NULL が保証するのは「責任者として指定されたユーザーが存在すること」までであり、
その人が `ROLE_MANAGER` の membership を持つことまでは保証しない。

DB で閉じようとすると `business_units` と `business_unit_memberships` の相互参照になり、
どちらを先に INSERT しても外部キーを満たせない。

したがって、この 3 つは「不変条件を守る実装境界」で定義する経路の限定によって守る。

## role の意味

### `manager`

- 事業所設定を変更できる
- メンバーを追加・削除できる
- メンバーの role を `manager` `registrar` `viewer` の範囲で変更できる
- 取引や関連データを登録・更新できる

ただし、責任者本人の membership は通常のメンバー管理では変更できない。
責任者の role は常に `ROLE_MANAGER` であり、責任者の変更は移譲処理だけが行う。

### `registrar`

- 取引や関連データを登録・更新できる
- 事業所設定やメンバー管理はできない

### `viewer`

- 閲覧のみできる
- 取引や関連データは変更できない

`viewer` は純粋な閲覧権限として扱う。
誤操作防止のために自分自身を一時的に読み取り専用へ落とすモードは、この設計の対象外とする。

## BusinessUnit 作成

`BusinessUnit` の作成者は、その事業所の最初の責任者になる。

作成処理は次を 1 つのトランザクションで行う。

- `BusinessUnit` を作成する（`responsible_user_id` に作成者を設定する）
- 作成者の membership を `ROLE_MANAGER` で作成する
- 標準勘定科目など初期データを作成する
- 作成者の `current_business_unit_id` をその `BusinessUnit` に設定する

`BusinessUnit::createWithDefaultAccounts()` は既に `DB::transaction` を張っているため、
membership の作成もこのトランザクションの内側に入れる。

membership を伴わない `BusinessUnit` の生成 API は提供しない。
提供すると、不変条件を満たさない `BusinessUnit` が正規の経路で作れてしまう。

## モデル設計

### `User`

`User` は複数の `BusinessUnit` に所属できる。

```php
public function businessUnits(): BelongsToMany
{
    return $this->belongsToMany(BusinessUnit::class, 'business_unit_memberships')
        ->withPivot('role')
        ->withTimestamps();
}

public function businessUnitMemberships(): HasMany
{
    return $this->hasMany(BusinessUnitMembership::class);
}
```

`->using(BusinessUnitMembership::class)` は使わない。

`Illuminate\Database\Eloquent\Relations\Pivot` は `$incrementing = false` を既定に持つため、
`id` 列を持つモデルを `using()` 経由で扱うと、採番された `id` がインスタンスへ戻らず、
`hasMany` 経由で取得したインスタンスの `save()` や `delete()` が主キーではなく外部キーの組で走る。

role の付け外しは `BusinessUnitMembership` を素のモデルとして直接操作するため、`using()` の利点はない。

### `BusinessUnit`

`BusinessUnit` は複数の `User` を持つ。

```php
public function users(): BelongsToMany
{
    return $this->belongsToMany(User::class, 'business_unit_memberships')
        ->withPivot('role')
        ->withTimestamps();
}

public function memberships(): HasMany
{
    return $this->hasMany(BusinessUnitMembership::class);
}

public function responsibleUser(): BelongsTo
{
    return $this->belongsTo(User::class, 'responsible_user_id');
}
```

補助的に次の helper を持つ。

- `membershipFor(User $user): ?BusinessUnitMembership`
- `hasMember(User $user): bool`
- `managers()`
- `registrars()`
- `viewers()`

### `BusinessUnitMembership`

素の `Model` として実装する。

```php
public function businessUnit(): BelongsTo
{
    return $this->belongsTo(BusinessUnit::class);
}

public function user(): BelongsTo
{
    return $this->belongsTo(User::class);
}
```

role 判定は文字列比較を散らさず、モデル側のメソッドに寄せる。

- `isManager(): bool`
- `canRegister(): bool`
- `canManageMembers(): bool`

## 現在選択中の BusinessUnit

`users.current_business_unit_id` は持ってよい。

これは所属や責任を表す列ではなく、
「今ログイン中のユーザーがどの `BusinessUnit` を見ているか」を示す UI 状態として扱う。

外部キーは `nullOnDelete()` とし、`BusinessUnit` が削除されたら自動的に `null` に戻す。

### 選択条件

`current_business_unit_id` に設定できるのは、そのユーザーが現在所属している `BusinessUnit` だけとする。

つまり `setSelectedBusinessUnit()` 相当の処理では、

- 対象 `BusinessUnit` に membership があること

を必須条件にする。

### membership 剥奪時の扱い

あるユーザーの membership を外すときは、そのユーザーの `current_business_unit_id` が
同じ `BusinessUnit` を指していれば `null` に戻す。

さらに、読み取り側でも「現在もその `BusinessUnit` に所属しているか」を検証する。

したがって、`selectedBusinessUnit` の取得は単純な `belongsTo` の生返しではなく、
所属確認込みのメソッド経由で扱う。

### membership の解決とキャッシュ

所属確認を素直に書くと、認可判定のたびに membership を引くことになる。

これを避けるため、リクエストごとに 1 回だけ解決してリクエストスコープで使い回す。

- middleware で「現在のユーザー」「現在の `BusinessUnit`」「その membership」を解決する
- 解決に失敗した場合（未所属・未選択）はその時点で弾く
- Policy やサービスは、解決済みの membership を参照する

Livewire コンポーネントはリクエストごとに再構築されるため、
コンポーネント内でも同じ解決経路（middleware が用意したコンテキスト、またはそれを返すサービス）を通す。
コンポーネントが独自に membership を引き直す実装は避ける。

## 認可方針

role は大分類に留め、実際の操作可否は ability 単位で判定する。

ここでいう ability は、実際の操作単位を表す。

例:

- `viewBusinessUnit`
- `manageBusinessUnit`
- `manageBusinessUnitMembers`
- `viewTransaction`
- `createTransaction`
- `updateTransaction`
- `deleteTransaction`
- `viewFixedAsset`
- `updateFixedAsset`
- `viewTaxReturnData`
- `updateTaxReturnData`

`manager` `registrar` `viewer` は人間が理解しやすい role であり、
実際に何ができるかは ability へ分解して判断する。

### role と ability の対応イメージ

- `manager`
  - すべて許可
- `registrar`
  - 閲覧、取引登録、取引更新、固定資産登録などを許可
  - 事業所設定変更、メンバー管理は不可
- `viewer`
  - 閲覧のみ許可

この形にしておくと、将来的に

- 仕訳登録はできるが金額変更は不可
- 申告データは見られない
- メンバー管理だけできない

のような差を role の爆発なしに表現しやすい。

## ability 判定時の BusinessUnit 解決

ability の判定には「そのリソースがどの `BusinessUnit` のものか」が必要になる。

現状、`BusinessUnit` への到達経路はモデルごとに異なる。

- `business_unit_id` を直接持つ
  - `Account`
  - `FiscalYear`
  - `FixedAsset`
  - `RecurringTransactionPlan`
  - `CreditCard`
  - `Counterparty`
- 1 段経由
  - `SubAccount` → `Account`
  - `Transaction` → `FiscalYear`
  - `DepreciationEntry` → `FiscalYear`
  - `BlueReturnInput` → `FiscalYear`
  - `CreditCardStatement` → `CreditCard`
- 2 段経由
  - `JournalEntry` → `Transaction` → `FiscalYear`
  - `CreditCardStatementLine` → `CreditCardStatement` → `CreditCard`

Policy がこの経路を個別に知る形にはしない。

各モデルに `businessUnit()` に相当する解決手段を用意し、Policy はそれだけを見る。

判定時は `with()` で経路を eager load し、一覧画面で N+1 を起こさないようにする。

対象モデルが存在しない ability（`createTransaction` など）は、
対象の `BusinessUnit` を明示的に渡して判定する。

```php
Gate::allows('createTransaction', [Transaction::class, $businessUnit]);
```

## 認可レイヤ

Laravel の実装としては Policy を第一候補とする。

ただし、この設計の本質は「Policy を使うこと」ではなく、
全書き込み入口で membership と role を検証することにある。

少なくとも次の層に認可が必要である。

- Livewire コンポーネント
- Form Request
- Controller
- Service

つまり、
「画面にボタンが出ていない」だけでは不十分であり、
保存処理の入口で必ず membership と ability を確認する。

## クエリスコープ

認可とは別に、読み取り系クエリでも `BusinessUnit` スコープを必須とする。

一覧・集計・検索で、所属外 `BusinessUnit` のデータが混ざらないことを不変条件とする。

したがって、

- `selectedBusinessUnit` を起点にデータを引く
- もしくは対象 `BusinessUnit` を明示的に受け取り、その配下だけを検索する

のどちらかに統一する。`BusinessUnit` をまたぐクエリは書かない。

ID 直指定で単体取得する場合は、取得後に対象の `BusinessUnit` 一致を必ず検証する。

書き込みだけを塞いでも、一覧に他事業所のデータが混入すれば情報漏洩になる。
ID 直指定だけでなく、一覧画面や集計画面でも所属外データが混ざらないことをテストで固定する。

## 書き込み入口の前提

マルチユーザー化後は、`selectedBusinessUnit` を持っているだけで書き込める状態を残してはいけない。

たとえば次のような処理は、すべて role ベースの認可を通す前提とする。

- 仕訳登録
- 仕訳更新
- 固定資産登録
- 固定資産更新
- 定期取引登録
- 勘定科目追加
- 補助科目追加
- 申告データ更新

`viewer` が所属しているだけで既存の保存経路を実行できる状態は不正である。

## 管理者との関係

`users.is_admin` のような全体管理者フラグは、membership role とは別概念として扱う。

- membership role
  - 事業所ごとの権限
- `is_admin`
  - アプリ全体の運営権限

初期方針としては、
`is_admin` が自動的にすべての `BusinessUnit` の member になるとはみなさない。

必要なら admin 用の例外ルールを認可レイヤへ明示的に追加する。

## 責任者の扱い

責任者は `business_units.responsible_user_id` で表し、常にちょうど 1 人存在する。

責任者を role として表現しない理由は 2 つある。

- 「誰が責任を負うか」は権限の強さとは別の情報であり、退会時や通知先の判断で一意な答えが必要になる
- role だけで表すと「最後の `manager` を外せない」というアプリ制約に頼ることになり、
  同時実行で管理者 0 人になり得る

責任者の membership は、常に `ROLE_MANAGER` である。
他の `ROLE_MANAGER` を持つ membership が存在してもよい。

### 移譲

責任者の変更は `transferResponsibility()` に相当する 1 つの処理に閉じ込める。

この処理は次を 1 トランザクションで行う。

- 新責任者が、その `BusinessUnit` の member であることを確認する
- 新責任者の membership を `ROLE_MANAGER` に変更する
- `business_units.responsible_user_id` を新責任者に更新する

旧責任者の membership は、そのまま `ROLE_MANAGER` に残してよい。
必要なら、その後の通常のメンバー管理で `registrar` または `viewer` に変更できる。

個別に role を更新する経路、およびメンバー削除の経路からは、次を禁止する。

- 責任者の membership を対象にすること（降格・削除の禁止）

### 複数の manager を許容する場合

この設計では、初期実装から `ROLE_MANAGER` の membership を複数許容する。
責任者を複数にするのではなく、管理権限だけを複数人へ配る。

`responsible_user_id` は NOT NULL のままなので、これを緩めても管理者不在にはならない。
責任者の membership は降格も削除もできないため、
少なくとも責任者 1 人は常に `ROLE_MANAGER` を保つ。

責任者を列として分離しておく利点はここにある。

## 不変条件を守る実装境界

不変条件は DB では閉じないため、更新経路を次のように限定する。

### membership の作成・更新・削除

`business_unit_memberships` の書き込みは、メンバー管理サービス
（`BusinessUnitMemberService` 相当）と移譲処理だけが行う。

- `BusinessUnitMembership::$fillable` に `role` を含めない
  - mass assignment で role が書き換わる経路を作らない
- コントローラや Livewire コンポーネントから `membership->role = ...` を直接書かない
- メンバー追加・role 変更の入力値は `Rule::in(BusinessUnitMembership::ASSIGNABLE_ROLES)` で検証する
  - ただし責任者の membership は入力対象から除外する

### `responsible_user_id` の更新

- `BusinessUnit::$fillable` に `responsible_user_id` を含めない
- 値を設定するのは次の 2 箇所だけとする
  - `BusinessUnit` 作成処理（初期値の設定）
  - `transferResponsibility()`（移譲）
- どちらも、同一トランザクション内で `ROLE_MANAGER` の membership 作成・更新と対で行う

### 経路の一致

不変条件と、それを守る場所の対応は次の通り。

| 不変条件 | 守る場所 |
| --- | --- |
| 1. 責任者は `ROLE_MANAGER` の membership を持つ | `BusinessUnit` 作成処理、`transferResponsibility()` |
| 2. 責任者の membership を降格・削除できない | メンバー管理サービス（対象から除外） |

この 2 つはいずれも、経路を限定して初めて成立する。
モデルを直接操作する実装が 1 箇所でも混ざると崩れるため、テストで固定する。

## ユーザー退会時の扱い

`business_unit_memberships.user_id` は `cascadeOnDelete()` なので、
ユーザーを削除すると所属は自動的に消える。

一方 `business_units.responsible_user_id` は `restrictOnDelete()` であるため、
自分が責任者の `BusinessUnit` が残っている限り、ユーザーは削除できない。

退会処理では、そのユーザーが責任者である `BusinessUnit` ごとに次のように分岐する。

- 他にメンバーがいない場合
  - その `BusinessUnit` を削除する
  - 帳簿データも申告データもここで消える
- 他にメンバーがいる場合
  - 退会をブロックし、責任者の移譲を要求する

無条件に `cascadeOnDelete()` にはしない。
共同利用している事業所が、責任者 1 人の操作で他メンバーに予告なく消えるのを避けるためである。

また帳簿書類には保存義務があるため、削除は明示的な意思決定として扱う。

DB の `restrictOnDelete()` は、この分岐を通さない削除に対する最後の砦として置く。
退会サービス以外の削除経路（管理画面、バッチ、tinker）があっても、
責任者不在の `BusinessUnit` は生まれない。

## 個人情報・申告データの扱い

帳簿データと申告データは、同じ ability 粒度で扱わない前提にする。

たとえば `BlueReturnInput` のような申告系データは、帳簿の閲覧可否とは別に ability を分ける。

例:

- `viewTransaction`
- `updateTransaction`
- `viewTaxReturnData`
- `updateTaxReturnData`

これにより、将来的に

- 帳簿は見せる
- 家族情報を含む申告データは見せない

といった分離が可能になる。

## 作成者情報との関係

`transactions.created_by` のような作成者カラムは、そのまま活用できる。

ただし、保存時には

- `created_by` に入るユーザーが、その `BusinessUnit` の current member であること

を検証対象に含める。

なお `responsible_user_id` は移譲で変わるため、作成者の記録にはならない。
「誰が作ったか」を残す必要があるデータには、`created_by` のような専用カラムを使う。

## 招待・所属追加

完成形として必要なのは次の操作である。

- `manager` がユーザーを事業所へ追加できる
- `manager` が role を `manager` `registrar` `viewer` の範囲で変更できる
- `manager` がユーザーを事業所から外せる

また、責任者の membership は追加・変更・削除のいずれの対象にもしない。
責任者の変更は移譲処理を通す。

### 初期実装の前提

初期実装では、**既に登録済みのユーザーだけ**を事業所へ追加できるものとする。

`business_unit_memberships.user_id` は必須であり、
アカウントを持たない相手を membership だけで表現することはできない。

未登録の税理士などを招く導線が必要になった場合は、
membership とは別に invitation（メールアドレスと role を保持し、
登録完了時に membership へ変換する）を設ける。

このドキュメントでは invitation の仕様までは扱わない。

## 改修対象コード

データを引き継がない場合でも、コード側は以下の修正が必要になる。

- `app/Models/User.php`
  - `businessUnits()` を `hasMany` から `belongsToMany` へ変更する
  - 削除フックは、所属する `BusinessUnit` をすべて削除している。
    多対多化後は他人の事業所を消すことになるため、削除して退会処理へ置き換える
  - `setSelectedBusinessUnit()` は `BusinessUnit.user_id` との比較をやめ、membership の有無で判定する
  - `createBusinessUnitWithDefaults()` は `user_id` の埋め込みをやめ、
    `responsible_user_id` の設定と `ROLE_MANAGER` の membership 作成に置き換える
- `app/Models/BusinessUnit.php`
  - `$fillable` の `user_id` を `responsible_user_id` に置き換える
  - `createWithDefaultAccounts()` のトランザクション内に membership の作成を含める
- `database/factories/BusinessUnitFactory.php`
  - `user_id` を設定している。`responsible_user_id` と `ROLE_MANAGER` の membership を
    同時に用意する state に置き換える
- `app/Services/CreditCardStatementLineRegistrar.php`
  - `$businessUnit->user_id !== $user->id` による所有チェックを membership ベースの認可に置き換える
- `app/Policies/*`
  - 現在はすべて `false` を返すスタブであり、どこからも呼ばれていない。ability 単位の判定として実装し直す

## テスト観点

最低限、次のケースはテストで固定したい。

所属とrole:

- 1 ユーザーが複数 `BusinessUnit` に所属できる
- 1 `BusinessUnit` に複数ユーザーが所属できる
- 同一 `BusinessUnit` と `User` の二重 attach ができない
- 税理士ユーザーが複数事業所を切り替えられる
- 所属していない `BusinessUnit` は選択できない
- membership 剥奪後、`current_business_unit_id` が残っていてもアクセスできない
- `BusinessUnit` を削除すると `current_business_unit_id` が `null` になる

責任者:

- `BusinessUnit` 作成直後、作成者が `responsible_user_id` かつ `ROLE_MANAGER` の membership を持つ
- 責任者のいない `BusinessUnit` を正規経路で作れない
- 責任者の membership を削除できない
- 責任者を直接降格できない
- 移譲すると、`responsible_user_id` と membership の role が同時に入れ替わる
- member でないユーザーへは移譲できない
- role 変更で `registrar` や `viewer` を `ROLE_MANAGER` に昇格できる
- 責任者以外の `manager` を `registrar` や `viewer` に降格できる
- `responsible_user_id` と `role` が mass assignment で書き換わらない

認可:

- `viewer` は閲覧できるが更新できない
- `registrar` は取引登録できる
- `registrar` はメンバー管理できない
- `manager` はメンバー管理できる
- 所属していない `BusinessUnit` のリソースを ID 直指定で操作できない
- 所属外の `BusinessUnit` のデータが一覧・集計に混入しない
- `viewTaxReturnData` を持たないユーザーは申告データにアクセスできない
- `created_by` に、その `BusinessUnit` の非メンバーを入れられない

退会:

- 自分が責任者で、他にメンバーがいる `BusinessUnit` が残っているユーザーは退会できない
- 自分が責任者で、他にメンバーがいない `BusinessUnit` は退会時に削除される
- 他ユーザーが責任者の `BusinessUnit` は、自分の退会で削除されない

## この設計でできること

この設計により、次の要件を自然に表現できる。

- 事業者本人と社内担当者が同じ事業所を使う
- 1 人の税理士が複数の顧問先事業所にアクセスする
- 事業所ごとに異なる role を割り当てる
- 責任者を明確にしたまま、担当者だけを入れ替える
- 将来、帳簿データと申告データの可視範囲を分ける

## まとめ

`BusinessUnit` のマルチユーザー対応では、所属と権限を membership で表し、責任者だけを列で固定する。

- `User` と `BusinessUnit` は多対多にする
- role は `business_unit_memberships` に `string` で持たせる
- `business_units.responsible_user_id` で責任者を一意に決め、責任者不在を構造的に防ぐ
- 認可の判断材料は role だけとし、`responsible_user_id` は認可に使わない
- `BusinessUnit` 作成時に作成者へ `ROLE_MANAGER` の membership を付与する
- `current_business_unit_id` は UI 上の選択状態として持つ
- 書き込み入口と読み取りスコープの両方で `BusinessUnit` 境界を守る

この構造にしておけば、税理士を含む複数ユーザーが複数事業所へ安全に関与できる。
