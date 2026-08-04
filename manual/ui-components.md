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

`variant` で見た目を切り替える基底ボタンです。省略時は `primary` です。

- `primary` … 進めるアクション（追加・編集・次へ・保存など、繰り返し押される日常操作）
- `confirm` … **押したら戻れない最終確定**（送信・確定・締めなど）。色相を primary と分けて誤操作を防ぐ
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

#### アイコンを付ける

`icon` prop でアイコンを、`icon-position` で位置 (`left` / `right`、省略時は `left`) を指定できます。使えるアイコン名は [`<x-ui.icon>`](#x-uiicon) の一覧を参照してください。

```blade
<x-ui.button variant="primary" icon="plus">追加</x-ui.button>
<x-ui.button variant="primary" icon="arrow-right" icon-position="right">次へ</x-ui.button>
```

### specialized ボタン

頻出するアクションについては、意味・アイコン・variant・デフォルトラベルを固定した薄いラッパーを用意しています。ラベルはスロットで上書きできます。`show-icon="false"` でアイコンを非表示にできます。

| コンポーネント | variant | icon | 位置 | デフォルトラベル | type |
| --- | --- | --- | --- | --- | --- |
| `<x-ui.button-next>` | primary | arrow-right | right | 次へ | button |
| `<x-ui.button-back>` | ghost | arrow-left | left | 戻る | button |
| `<x-ui.button-submit>` | **confirm** | check | left | 送信 | **submit** |
| `<x-ui.button-cancel>` | secondary | x-mark | left | キャンセル | button |
| `<x-ui.button-delete>` | danger | trash | left | 削除 | button |
| `<x-ui.button-add>` | primary | plus | left | 追加 | button |
| `<x-ui.button-edit>` | secondary | pencil | left | 編集 | button |
| `<x-ui.button-close>` | ghost | x-mark | left | 閉じる | button |
| `<x-ui.button-download>` | secondary | arrow-down-tray | left | ダウンロード | button |

#### code例

```blade
<x-ui.button-next />                             {{-- 「次へ →」 --}}
<x-ui.button-next>確認へ進む</x-ui.button-next>  {{-- ラベル上書き --}}
<x-ui.button-delete wire:click="destroy" />
<x-ui.button-download :show-icon="false">CSV</x-ui.button-download>

<form method="post" action="...">
    @csrf
    <x-ui.button-submit>登録する</x-ui.button-submit>
</form>
```

`wire:click`、`disabled`、`href` を付けたいときの `type="button"` 上書きなど、追加属性はそのまま渡ります。

### `<x-ui.icon>`

`name` で SVG アイコンを描画します。ボタン外でも使えます。色は `currentColor` を継承するので、親の `text-*` で色付けします。

現在使えるアイコン: `plus` / `pencil` / `x-mark` / `arrow-right` / `arrow-left` / `arrow-down-tray` / `trash` / `check` / `chevron-down`

```blade
<x-ui.icon name="plus" />
<x-ui.icon name="trash" class="w-5 h-5 text-status-danger-fg" />
```

新しいアイコンを追加する場合は `resources/views/components/ui/icon.blade.php` の `$paths` に SVG path (`d`) を足してください。

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

#### `variant` — 取引区分アクセント

`variant` に取引区分を指定すると、カード全体が淡背景＋アクセント色の罫線に切り替わります。取引フォーム（売上・経費・仕入）の識別に使います。

| variant | 意味 | 契約トークン |
| --- | --- | --- |
| `default`（省略時） | 通常の面 | `bg-surface` / `border-line` |
| `revenue` | 売上・収入 | `bg-accent-revenue` / `border-accent-revenue-border` |
| `expense` | 経費・支出 | `bg-accent-expense` / `border-accent-expense-border` |
| `purchase` | 仕入 | `bg-accent-purchase` / `border-accent-purchase-border` |

`<x-ui.card-header>` にも同じ `variant` を渡すと、ヘッダー下の罫線がアクセント色に揃います。

```blade
<x-ui.card variant="revenue">
    <x-ui.card-header variant="revenue">売上を登録</x-ui.card-header>
    <x-ui.card-body>...</x-ui.card-body>
</x-ui.card>
```

`status-*`（成功／警告／情報／エラーの通知）と `accent-*`（取引区分の識別）は意味カテゴリが独立です。revenue カードの中で status-success の flash を出す、といった組み合わせは前提として設計しています。

#### `collapsible` — ヘッダーだけ残して本体を畳む

`<x-ui.card>` に `collapsible` を付けると、カード全体が Alpine.js の `x-data="{ open: true }"` を持ちます。あわせて `<x-ui.card-header>` に `toggle` を付けるとヘッダー全体がトグルボタンになり、右端に chevron が出ます。本体側は自分で `x-show="open"` を貼ります（`x-cloak` を併用すると初期描画のちらつきを防げます）。

初期状態を畳んだ状態にしたい場合は `:collapsed="true"` を渡します。

```blade
<x-ui.card variant="expense" collapsible>
    <x-ui.card-header toggle variant="expense">経費を登録</x-ui.card-header>
    <div x-show="open" x-cloak class="p-4 space-y-4">
        {{-- 本体 --}}
    </div>
</x-ui.card>

{{-- 初期は畳んで表示 --}}
<x-ui.card collapsible :collapsed="true">
    <x-ui.card-header toggle>詳細設定</x-ui.card-header>
    <div x-show="open" x-cloak class="p-4">...</div>
</x-ui.card>
```

`toggle` を付けなくても `<x-ui.card-header>` は使えます。その場合は普通の見出しとしてだけ表示されます。

#### ヘッダーの見た目

`<x-ui.card-header>` は `text-base` + `font-bold` で表示されます。`variant` を渡すと下罫線が対応するアクセント色になります。トグル時はヘッダー全体にホバー背景（`hover:bg-surface-muted`）が乗ります。

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
- アクション: `bg-action-primary` `text-action-primary-fg` `hover:bg-action-primary-hover`（`action-confirm` / `action-danger` も同形）
- chrome (navmenu 等): `bg-chrome` `text-chrome-fg` `text-chrome-muted` `hover:bg-chrome-hover`
- ステータス: `bg-status-danger` `text-status-danger-fg` `border-status-danger-border`（`warning` / `success` / `info` も同形）
- 取引区分アクセント: `bg-accent-revenue` `text-accent-revenue-fg` `border-accent-revenue-border`（`expense` / `purchase` も同形。取引フォームの識別に使う）

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
