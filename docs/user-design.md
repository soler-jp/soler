# User Design

このドキュメントは、`User` モデルの設計意図と、現在テストで確認できている範囲を整理するためのメモである。

`manual/setup.md` が利用手順を扱うのに対し、このドキュメントは `User` の責務、既存テストの到達点、今後追加したい確認項目を扱う。

## 目的

- `User` が何を責務とするかを明確にする
- `BusinessUnit` と `FiscalYear` へのつながりを整理する
- 既存テストで固定できている挙動を記録する
- 不足しがちなケースを明確にする

## 現状認識

`User` には、現在次の責務がある。

- ログインユーザーとしての基本属性を持つ
- 所有する事業体を持つ
- 現在選択中の事業体を持つ
- 事業体を作成するときに標準勘定科目の作成までまとめて行う
- 他人の事業体を選択できないようにする

`User` は `BusinessUnit` の入口でもあり、固定資産や年度集計の前提でもある。
ただし、初期セットアップの実行単位は `GeneralBusinessInitializer` とする。

## 現在の公開 API

### `createBusinessUnitWithDefaults(array $attributes): BusinessUnit`

事業体を作成し、標準勘定科目も同時に登録する。

副作用:

- 作成した `BusinessUnit` の `user_id` に自分自身を設定する
- `current_business_unit_id` をその事業体に更新する

### `selectedBusinessUnit(): BelongsTo`

現在選択中の事業体を返す。

### `setSelectedBusinessUnit(BusinessUnit $unit): void`

選択中の事業体を切り替える。

制約:

- 他人の事業体は選べない

### `businessUnits()`

ユーザーが所有する事業体一覧を返す。

## 現在の利用導線

現状のアプリでは、`User` から次の流れで使われる。

1. ユーザーが `GeneralBusinessInitializer` を呼ぶ
2. `GeneralBusinessInitializer` が `User::createBusinessUnitWithDefaults()` を通して事業体を作る
3. `current_business_unit_id` に作成した事業体が入る
4. `GeneralBusinessInitializer` が `FiscalYear` を作る
5. `selectedBusinessUnit` から現在の事業体を参照する
6. 事業体に紐づく年度を前提に取引登録や固定資産登録を行う

`PortalController` や `SetupWizard` では、この流れを前提に処理している。

`manual/setup.md` は、この初期セットアップの入口として `GeneralBusinessInitializer` を案内するだけに絞る。

## 現在の確認済みケース

`tests/Feature/UserTest.php` で確認できているケースは次の通りである。

- `selectedBusinessUnit` の取得
- `createBusinessUnitWithDefaults()` で `current_business_unit_id` が設定される
- `current_business_unit_id` が未設定のときは `null`
- 選択中の事業体が削除されたら `null`
- `setSelectedBusinessUnit()` の正常系
- 他人の事業体を選んだときの例外

## 不足しがちなケース

次のケースは、必要なら追加検討できる。

- `businessUnits()` の一覧取得
- `createBusinessUnitWithDefaults()` の返り値が作成済みの `BusinessUnit` であること
- `setSelectedBusinessUnit()` の後に `fresh()` しても選択状態が維持されること
- `current_business_unit_id` を直接変更したときの挙動
- `selectedBusinessUnit` と `currentFiscalYear` を組み合わせたときの導線

## 設計上の補足

- `User` は「個人情報の入れ物」だけではなく、事業体選択の起点でもある
- `BusinessUnit` を先に選べる状態は、年度・取引・固定資産の全機能の前提になる
- `FiscalYear` の選択は `BusinessUnit` の責務であり、`User` はその入口を提供する
- 初期セットアップのオーケストレーションは `GeneralBusinessInitializer` に寄せる
- `SetupWizard` は入力収集と呼び出しに専念し、実際の作成処理は initializer に委譲する

## 次に検討したいこと

- `businessUnits()` の一覧取得を manual と test で明示する
- `selectedBusinessUnit` と `currentFiscalYear` のサンプルを docs に追加する
- setup 直後の初期選択フローを別途図示する
