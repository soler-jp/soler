# UIコンポーネント

Soler の UI は、テーマ契約に基づいた共通コンポーネントを `resources/views/components/ui/` に置いています。

この manual では、現在提供しているコンポーネントの使い方と、Blade / Livewire で色・アール・影を書くときの共通ルールを説明します。

設計の背景は [`docs/theme-design.md`](../docs/theme-design.md) を参照してください。

## 前提

- ページ側の `<html>` に `data-theme="default"` が付与されていること
- `resources/css/app.css` から `themes/default.css` が読み込まれていること
- Vite ビルドが最新であること (`vendor/bin/sail npm run build` または `vendor/bin/sail npm run dev`)

`layouts/app.blade.php` / `layouts/guest.blade.php` / `layouts/setup.blade.php` は既に対応済みです。

## 使ってよい / いけないクラス

色・アール・影の用途では、契約由来のユーティリティだけを使います。レイアウト・タイポ・スペーシングは素の Tailwind をそのまま使えます。

- ✅ `bg-surface` `bg-canvas` `text-content` `text-content-muted` `border-line` `rounded-card` `shadow-card`
- ✅ `bg-action-primary text-action-primary-fg hover:bg-action-primary-hover`
- ✅ `bg-status-danger text-status-danger-fg border border-status-danger-border`
- ✅ `p-4` `flex` `gap-2` `text-sm` `w-full` `grid grid-cols-2`（レイアウト系は素のまま）
- ❌ `bg-blue-500` `text-slate-700` `border-gray-200` `rounded-xl` `shadow-md`

`resources/views/components/ui/**` は `tests/Unit/Architecture/ThemeTokenTest.php` で機械的に強制されます。生の Tailwind スケールを新規追加すると CI が落ちます。

## 提供しているコンポーネント

### `<x-ui.button>`

`variant` で見た目を切り替えるボタンです。省略時は `primary` です。

- `primary` … 主 CTA
- `secondary` … 地味なボタン
- `danger` … 削除など危険操作
- `ghost` … 背景なし。ツールバー等

#### code例

```blade
<x-ui.button variant="primary" wire:click="save">保存する</x-ui.button>
<x-ui.button variant="secondary" type="button">キャンセル</x-ui.button>
<x-ui.button variant="danger" wire:click="delete">削除</x-ui.button>
<x-ui.button variant="ghost">閉じる</x-ui.button>
```

`type` は省略時に `button` になります。フォーム送信ボタンにする場合は明示的に `type="submit"` を指定してください。

`disabled` などの HTML 属性はそのまま渡ります。

```blade
<x-ui.button variant="primary" disabled>保存する</x-ui.button>
```

### `<x-ui.card>` / `<x-ui.card-header>` / `<x-ui.card-body>`

面（surface）を持つカードです。ヘッダーとボディを分けて使えます。

#### code例

```blade
<x-ui.card>
    <x-ui.card-header>売上サマリー</x-ui.card-header>
    <x-ui.card-body>
        <p class="text-sm text-content-muted">今月の実績</p>
        <p class="text-2xl font-semibold text-content">¥ 1,234,567</p>
    </x-ui.card-body>
</x-ui.card>
```

面の中でわずかに区切る背景が欲しい場合は、`bg-surface-muted` を上書きします。契約由来のユーティリティなので違反にはなりません。

```blade
<x-ui.card class="bg-surface-muted">
    <x-ui.card-body>...</x-ui.card-body>
</x-ui.card>
```

### `<x-ui.input>`

テキスト入力です。`type` を渡すと HTML 標準の input タイプを切り替えられます（省略時は `text`）。

#### code例

```blade
<label class="block space-y-1">
    <span class="text-sm text-content-muted">メールアドレス</span>
    <x-ui.input type="email" wire:model="email" placeholder="you@example.com" />
</label>
```

`wire:model` などの属性はそのまま渡ります。

## ステータス表示

淡背景の警告ボックスは、bg / fg / border の三点セットで組みます。danger ボタン (`bg-action-danger`) と danger ステータス (`bg-status-danger` 等) を混同しないでください。

```blade
<div class="rounded-control border px-3 py-2 text-sm bg-status-danger text-status-danger-fg border-status-danger-border">
    保存に失敗しました。
</div>
```

`status-warning` / `status-success` / `status-info` も同じ形で使えます。

## テーマ契約の一覧

Blade から使えるユーティリティのうち、色・アール・影は契約由来のものだけです。

### 色

- 表層: `bg-canvas` `bg-surface` `bg-surface-muted` `border-line`
- テキスト: `text-content` `text-content-muted` `text-content-onbrand`
- ブランド: `text-brand` `text-link` `ring-focus`
- アクション: `bg-action-primary` `text-action-primary-fg` `hover:bg-action-primary-hover`（`action-danger` も同形）
- ステータス: `bg-status-danger` `text-status-danger-fg` `border-status-danger-border`（`warning` / `success` / `info` も同形）

### アール

- `rounded-control` … 入力・ボタン
- `rounded-card`
- `rounded-badge`
- `rounded-modal`

### 影

- `shadow-card`
- `shadow-popover`

契約の完全な一覧と CSS 変数名は [`docs/theme-design.md`](../docs/theme-design.md) を参照してください。

## 新しいコンポーネントを追加するには

1. `resources/views/components/ui/` に Blade ファイルを追加する
2. 色・アール・影は上記の契約由来ユーティリティだけを使う
3. `vendor/bin/sail artisan test --compact tests/Unit/Architecture/ThemeTokenTest.php` を実行して規約違反がないことを確認する

新しいセマンティックトークンが必要になったときは、`docs/theme-design.md` の「テーマ契約」節、`themes/*.css`、`tailwind.config.js`、`ThemeTokenTest.php` の期待集合を同時に更新します。

## 参考

- `resources/views/components/ui/`
- `resources/css/design-system/themes/default.css`
- `tailwind.config.js`
- `tests/Unit/Architecture/ThemeTokenTest.php`
- [`docs/theme-design.md`](../docs/theme-design.md)
