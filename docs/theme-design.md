# Soler テーマ設計ルール

## 目的

その場その場で Tailwind ユーティリティを書き足すことで発生している見た目の不統一を解消し、あわせて将来「配色」「アール（角丸）」などをユーザーが選び分けられる仕組みへ拡張できる基盤を用意する。

現段階では `default` テーマのみを実装する。テーマ切替 UI、複数テーマ、ダークモードは実装しない。ただし、後から追加したときに **既存の Blade / Livewire コンポーネントを変更せずに済む構造** にしておく。

---

## 現段階の方針

- テーマは `default` の 1 つだけ
- テーマ切替 UI・ユーザー設定・ダークモード・多テーマは実装しない
- Blade / Livewire では、色・アール・影について **契約で定義された意味ベースのユーティリティ**（`bg-surface` / `text-content-muted` / `rounded-card` / `shadow-card` など）だけを使う
- Tailwind の素のスケール（`bg-blue-500` / `rounded-xl` / `shadow-md` など）は色・アール・影の用途では使わない
- 既存コードは一括置換しない。新規 UI から適用し、既存画面は大きく触るときに合わせて移行する
- 新規 UI コンポーネント（`resources/views/components/ui/**`）にはアーキテクチャテストで規約を機械強制する

---

## 設計原則: 4 つの責務

デザイントークンの構造を「層」ではなく **責務** で説明する。

1. **テーマ契約**  
   すべてのテーマが実装すべき CSS 変数の一覧。名前と意味だけを規定し、具体値は規定しない。
2. **テーマ実装**  
   具体テーマファイル（例: `themes/default.css`）が契約変数に値を割り当てる。**内部で使う生の色パレットやスケール値は、テーマ内で自己完結させる**（他テーマからは参照しない）。
3. **Tailwind アダプター**  
   `tailwind.config.js` が契約変数を Tailwind ユーティリティ（`bg-surface` `rounded-card` など）に接続する。
4. **UI コンポーネント**  
   `resources/views/components/ui/**` の Blade コンポーネントは、アダプター経由のユーティリティだけを使って組む。契約変数を直接読まない。

### プリミティブはテーマ内に閉じる

各テーマは自分のパレットを持ってよいが、それは **テーマファイル内の私的変数**（`--theme-*`）として扱い、契約には露出させない。共通パレットファイルは作らない。

理由: 異なるテーマが「共通の色語彙」に縛られないようにするため。`warm` テーマがオレンジ系パレットを、`monochrome` テーマがグレー系だけを持てるようにする。

### 意味カテゴリは固定・色相バンドは可変

セマンティックカラー（`status-*` / `action-danger-*` など）は、

- **技術的には** テーマファイル内で値を変えられる
- **規律として** 意味カテゴリを裏切る変更は禁止（`status-danger-*` を緑にする等）
- **ユーザー向け設定 UI には露出しない**

同じ「danger」でも、ダークモードやアクセシビリティ配慮テーマで彩度・明度が変わることは前提とする。

---

## ファイル構成

```
resources/css/
  app.css                       ← エントリ（読み込み順を管理）
  design-system/
    themes/
      default.css               ← default テーマの全定義（自己完結）
      debug.css                 ← 開発中の器の検証用（本番では読まない）
resources/views/components/ui/
  button.blade.php
  card.blade.php
  input.blade.php
tailwind.config.js              ← 契約変数を Tailwind utility に接続
tests/Unit/Architecture/
  ThemeTokenTest.php            ← 規約の機械強制
```

---

## テーマ契約

すべてのテーマは以下の CSS 変数を必ず定義する。**追加や削除はドキュメントとアーキテクチャテストを同時に更新した上で行う**。

### 表層

- `--color-canvas`         … アプリ全体の背景
- `--color-surface`        … カードやパネル等の面
- `--color-surface-muted`  … 面の中でわずかに区切る背景
- `--color-line`           … 罫線・区切り

### テキスト

- `--color-content`         … 本文
- `--color-content-muted`   … 補助・説明文
- `--color-content-onbrand` … ブランド色背景に載せる文字

### ブランド・リンク・フォーカス

- `--color-brand`  … ロゴ・ヘッダー装飾
- `--color-link`   … インラインテキストリンク
- `--color-focus`  … フォーカスリング

### アクション（CTA）

- `--color-action-primary-bg` / `--color-action-primary-fg` / `--color-action-primary-hover`
- `--color-action-confirm-bg` / `--color-action-confirm-fg` / `--color-action-confirm-hover`
- `--color-action-danger-bg`  / `--color-action-danger-fg`  / `--color-action-danger-hover`

`primary` は「進める」アクション（追加・編集・次へ）。`confirm` は「押したら戻れない最終確定」アクション（送信・確定・締め）で、色相を primary と別にして誤操作を防ぐ。`danger` は削除。

セカンダリボタン（地味なボタン）はテーマ契約に持たない。`--color-surface` / `--color-line` / `--color-content` の組み合わせで組む。

### chrome (navmenu 等の濃色クローム)

- `--color-chrome-bg`    … navmenu 等の濃色領域の背景
- `--color-chrome-fg`    … chrome 上のテキスト（本文相当）
- `--color-chrome-muted` … chrome 上の補助テキスト・境界線色（透過して使う）
- `--color-chrome-hover` … chrome 上のホバー背景

chrome は「本文エリア (canvas/surface) とは対照的な、常時濃色で表示される領域」のための独立系統。ブランド色と同系統の濃色にすることが多い（default では emerald-950）。

### ステータス（bg / fg / border の三点セット）

淡い背景の警告ボックスと濃い背景の danger ボタンを同じ 1 色で処理しようとすると破綻するため、三点セットで持つ。

- `--color-status-danger-bg`  / `--color-status-danger-fg`  / `--color-status-danger-border`
- `--color-status-warning-bg` / `--color-status-warning-fg` / `--color-status-warning-border`
- `--color-status-success-bg` / `--color-status-success-fg` / `--color-status-success-border`
- `--color-status-info-bg`    / `--color-status-info-fg`    / `--color-status-info-border`

### 取引区分アクセント（bg / fg / border の三点セット）

取引区分（売上・経費・仕入）を淡背景で識別するためのカテゴリ。`status-*` が「イベント通知（成功／失敗／警告／情報）」を表すのに対し、`accent-*` は「業務ドメインの意味」を表す。同じ淡背景でも意味カテゴリが異なるため、混在してもよい（例: revenue カードの中に status-success の flash が出る）。

- `--color-accent-revenue-bg`  / `--color-accent-revenue-fg`  / `--color-accent-revenue-border`
- `--color-accent-expense-bg`  / `--color-accent-expense-fg`  / `--color-accent-expense-border`
- `--color-accent-purchase-bg` / `--color-accent-purchase-fg` / `--color-accent-purchase-border`

このカテゴリはドメイン上固定される取引区分に対してだけ設ける。動的に増える識別（クライアント別・案件別のアクセント色等）はスコープ外に置き、必要になった時点で別カテゴリを検討する。

### 形（アール）

- `--radius-control` … 入力・ボタン等の操作系
- `--radius-card`
- `--radius-badge`
- `--radius-modal`

### 濃度（影）

- `--shadow-card`
- `--shadow-popover`

---

## テーマ実装: `themes/default.css`

契約を満たす具体値を、テーマ内部で完結して定義する。

```css
:root,
[data-theme="default"] {
  /* ── テーマ内部の私的パレット（契約ではない・Blade から参照しない） ── */
  --theme-blue-600:   37 99 235;
  --theme-blue-700:   29 78 216;
  --theme-slate-50:   248 250 252;
  --theme-slate-100:  241 245 249;
  --theme-slate-200:  226 232 240;
  --theme-slate-500:  100 116 139;
  --theme-slate-900:  15 23 42;
  --theme-red-50:     254 242 242;
  --theme-red-200:    254 202 202;
  --theme-red-600:    220 38 38;
  --theme-red-700:    185 28 28;
  --theme-green-50:   240 253 244;
  --theme-green-200:  187 247 208;
  --theme-green-600:  22 163 74;
  --theme-green-700:  21 128 61;
  --theme-amber-50:   255 251 235;
  --theme-amber-200:  253 230 138;
  --theme-amber-500:  245 158 11;
  --theme-amber-700:  180 83 9;
  --theme-blue-50:    239 246 255;
  --theme-blue-200:   191 219 254;
  --theme-blue-800:   30 64 175;

  /* ── 契約: 表層 ── */
  --color-canvas:         var(--theme-slate-50);
  --color-surface:        255 255 255;
  --color-surface-muted:  var(--theme-slate-100);
  --color-line:           var(--theme-slate-200);

  /* ── 契約: テキスト ── */
  --color-content:         var(--theme-slate-900);
  --color-content-muted:   var(--theme-slate-500);
  --color-content-onbrand: 255 255 255;

  /* ── 契約: ブランド・リンク・フォーカス ── */
  --color-brand: var(--theme-blue-600);
  --color-link:  var(--theme-blue-600);
  --color-focus: var(--theme-blue-600);

  /* ── 契約: アクション ── */
  --color-action-primary-bg:    var(--theme-blue-600);
  --color-action-primary-fg:    255 255 255;
  --color-action-primary-hover: var(--theme-blue-700);
  --color-action-danger-bg:     var(--theme-red-600);
  --color-action-danger-fg:     255 255 255;
  --color-action-danger-hover:  var(--theme-red-700);

  /* ── 契約: ステータス ── */
  --color-status-danger-bg:      var(--theme-red-50);
  --color-status-danger-fg:      var(--theme-red-700);
  --color-status-danger-border:  var(--theme-red-200);
  --color-status-warning-bg:     var(--theme-amber-50);
  --color-status-warning-fg:     var(--theme-amber-700);
  --color-status-warning-border: var(--theme-amber-200);
  --color-status-success-bg:     var(--theme-green-50);
  --color-status-success-fg:     var(--theme-green-700);
  --color-status-success-border: var(--theme-green-200);
  --color-status-info-bg:        var(--theme-blue-50);
  --color-status-info-fg:        var(--theme-blue-800);
  --color-status-info-border:    var(--theme-blue-200);

  /* ── 契約: 形 ── */
  --radius-control: 0.5rem;
  --radius-card:    0.75rem;
  --radius-badge:   9999px;
  --radius-modal:   1rem;

  /* ── 契約: 濃度 ── */
  --shadow-card:    0 1px 2px rgb(0 0 0 / 0.05);
  --shadow-popover: 0 10px 15px -3px rgb(0 0 0 / 0.10);
}
```

具体値は `default` テーマ確定時に、既存 UI（ダッシュボード等）で頻用されている値を吸い上げてから最終決定する。

---

## Tailwind アダプター

`tailwind.config.js` の `theme.extend` で、契約変数を参照するユーティリティを追加する。既存 Tailwind スケールは残す（削除すると未移行画面が一斉に壊れるため）。

プロジェクトの `tailwind.config.js` は ESM（`export default`）で書かれているため、既存形式に合わせる。以下は既存の `content`、`fontFamily`、`plugins` を保持した完全例であり、テーマ対応時もこれらを削除しない。`import` している依存（`@tailwindcss/forms` など）は本プロジェクトの `package.json` に既に含まれる想定であり、この設計変更で新規に追加はしない。

```js
import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

const withAlpha = (v) => `rgb(var(${v}) / <alpha-value>)`;

/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
    './storage/framework/views/*.php',
    './resources/views/**/*.blade.php',
    './app/**/*.php',
  ],

  theme: {
    extend: {
      fontFamily: {
        sans: ['Figtree', ...defaultTheme.fontFamily.sans],
      },
      colors: {
        canvas:         withAlpha('--color-canvas'),
        surface:        withAlpha('--color-surface'),
        'surface-muted': withAlpha('--color-surface-muted'),
        line:           withAlpha('--color-line'),

        content:        withAlpha('--color-content'),
        'content-muted': withAlpha('--color-content-muted'),
        'content-onbrand': withAlpha('--color-content-onbrand'),

        brand: withAlpha('--color-brand'),
        link:  withAlpha('--color-link'),
        focus: withAlpha('--color-focus'),

        'action-primary': {
          DEFAULT: withAlpha('--color-action-primary-bg'),
          fg:      withAlpha('--color-action-primary-fg'),
          hover:   withAlpha('--color-action-primary-hover'),
        },
        'action-danger': {
          DEFAULT: withAlpha('--color-action-danger-bg'),
          fg:      withAlpha('--color-action-danger-fg'),
          hover:   withAlpha('--color-action-danger-hover'),
        },

        'status-danger': {
          DEFAULT: withAlpha('--color-status-danger-bg'),
          fg:      withAlpha('--color-status-danger-fg'),
          border:  withAlpha('--color-status-danger-border'),
        },
        'status-warning': {
          DEFAULT: withAlpha('--color-status-warning-bg'),
          fg:      withAlpha('--color-status-warning-fg'),
          border:  withAlpha('--color-status-warning-border'),
        },
        'status-success': {
          DEFAULT: withAlpha('--color-status-success-bg'),
          fg:      withAlpha('--color-status-success-fg'),
          border:  withAlpha('--color-status-success-border'),
        },
        'status-info': {
          DEFAULT: withAlpha('--color-status-info-bg'),
          fg:      withAlpha('--color-status-info-fg'),
          border:  withAlpha('--color-status-info-border'),
        },
      },
      borderRadius: {
        control: 'var(--radius-control)',
        card:    'var(--radius-card)',
        badge:   'var(--radius-badge)',
        modal:   'var(--radius-modal)',
      },
      boxShadow: {
        card:    'var(--shadow-card)',
        popover: 'var(--shadow-popover)',
      },
      // ringColor は独立に定義しない。Tailwind の `ring-*` は
      // `theme('ringColor', theme('colors'))` の順で解決するため、
      // `colors.focus` を定義すれば `ring-focus` は自動で機能する。
    },
  },

  plugins: [forms],
};
```

Blade からは `bg-surface text-content border border-line rounded-card shadow-card` や `bg-action-primary text-action-primary-fg hover:bg-action-primary-hover` のように書ける。

---

## Blade 側の規約

### 使ってよいクラス／使ってはいけないクラス

色・アール・影の用途では、契約由来のユーティリティだけを使う。レイアウト・タイポ・スペーシングは素の Tailwind をそのまま使う。

- ✅ `bg-surface` `bg-canvas` `text-content` `text-content-muted` `border-line` `rounded-card` `shadow-card`
- ✅ `bg-action-primary text-action-primary-fg hover:bg-action-primary-hover`
- ✅ `bg-status-danger text-status-danger-fg border border-status-danger-border`
- ❌ `bg-blue-500` `text-slate-700` `border-gray-200` `rounded-xl` `shadow-md`
- ✅ `p-4` `flex` `gap-2` `text-sm` `w-full` `grid grid-cols-2`（レイアウト系は素のまま）

素の Tailwind スケールを色・アール・影に使うと、テーマ切り替えで取り残される。

### `<html>` に `data-theme` を出しておく

現段階では `default` 固定だが、将来のために layout Blade で常に出す。

```blade
<html data-theme="default">
```

---

## UI コンポーネント初期セット

`resources/views/components/ui/` に、まずは 3 つだけ用意する。その他（badge / modal / table / alert など）は必要になった時点で追加する。

- `<x-ui.button variant="primary|secondary|danger|ghost">`
- `<x-ui.card>` / `<x-ui.card-header>` / `<x-ui.card-body>`
- `<x-ui.input>`

このコンポーネント群は、上記の Blade 規約に従い、契約由来のユーティリティのみで組む。

---

## テーマ切替の保証範囲

「CSS ファイルだけで切替可能」は正確ではない。以下が正しい保証範囲。

**保証すること**:

- 新しいテーマを追加しても、`resources/views/**` の Blade / Livewire コンポーネントは変更不要
- 新しいテーマは、テーマ契約に定義された変数集合を過不足なく提供すれば動作する

**保証しないこと（テーマ追加時に一度は実装が必要）**:

- テーマ CSS の読み込み（`app.css` への追加）
- `<html data-theme="...">` の値を決めるレイアウト側の実装
- ユーザーが選んだテーマの保存（DB カラム、Setting UI、middleware など）

つまり、「以降のテーマ追加で **UI コンポーネント側は変更不要** である」ことが本設計の中心的な保証。

---

## アーキテクチャテストで規約を強制する

規約を目視レビューだけで維持するのは長期的に破綻するため、`tests/Unit/Architecture/ThemeTokenTest.php` を新設して以下を検証する。既存画面全体を対象にせず、**新規 UI コンポーネントのみに強制**することで段階移行と両立させる。

対象:

- `resources/views/components/ui/**/*.blade.php`

### 検証 1: 色・アール・影の用途では契約由来のユーティリティだけを使う

**「色・アール・影」と「タイポグラフィ・線幅・構造」を分けて扱うこと**が重要。同じ接頭辞でも意味が違う:

- 色系（要検証）: `bg-{color}` / `text-{color}` / `border-{color}` / `ring-{color}` / `divide-{color}` / `outline-{color}` / `placeholder-{color}` / `accent-{color}` / `caret-{color}` / `fill-{color}` / `stroke-{color}` / `from-{color}` / `via-{color}` / `to-{color}`
- 構造系（**検証対象外**、素の Tailwind で自由に使ってよい）: `text-sm` / `text-center` / `text-lg` / `border-2` / `border-dashed` / `border-solid` / `ring-2` / `ring-offset-2` / `ring-inset` など

「`text-*` を一律抽出して色 utility 集合と比較する」ような雑な allowlist はタイポ・線幅・リング幅を巻き添えにするため使わない。

許可集合は Tailwind の resolved config 全体から作らず、テストコード内に明示する。resolved config には段階移行のため残している `blue.500` / `slate.700` / `rounded-md` / `shadow-lg` なども含まれるため、それを許可元にすると生の Tailwind スケールを拒否できない。

```php
private const ALLOWED_COLOR_NAMES = [
    'canvas', 'surface', 'surface-muted', 'line',
    'content', 'content-muted', 'content-onbrand',
    'brand', 'link', 'focus',
    'action-primary', 'action-primary-fg', 'action-primary-hover',
    'action-confirm', 'action-confirm-fg', 'action-confirm-hover',
    'action-danger', 'action-danger-fg', 'action-danger-hover',
    'chrome', 'chrome-fg', 'chrome-muted', 'chrome-hover',
    'status-danger', 'status-danger-fg', 'status-danger-border',
    'status-warning', 'status-warning-fg', 'status-warning-border',
    'status-success', 'status-success-fg', 'status-success-border',
    'status-info', 'status-info-fg', 'status-info-border',
    'transparent', 'current', 'inherit',
];

// 'none' は「効果を打ち消す」用途で明示的に許可（`hover:shadow-none` / `rounded-none` など）。
// bare `shadow` / `rounded`（Tailwind 組み込みの DEFAULT にヒットする）は許可集合に含めない。
private const ALLOWED_RADIUS_NAMES = ['control', 'card', 'badge', 'modal', 'none'];
private const ALLOWED_SHADOW_NAMES = ['card', 'popover', 'none'];
```

具体的な実装方針:

1. **走査対象**は Blade 中のあらゆる静的文字列（class 属性だけでなく、`@php` の match 式や配列リテラル内の class 名も対象。`data-*="..."` の中身などは対象外にしてよい）
2. 抽出したクラスから variant（`hover:` `md:` `dark:` など）と opacity 修飾子（`/50` など）を剥がす
3. **色検査**: Tailwind の resolved config にある全色名は、クラスが色 utility か、`text-sm` / `bg-cover` / `ring-2` などの非カラー utility かを分類するためだけに使う。`bg-*` / `text-*` / `border-*` / `ring-*` / `ring-offset-*` / `divide-*` / `outline-*` / `decoration-*` / `placeholder-*` / `accent-*` / `caret-*` / `fill-*` / `stroke-*` / `from-*` / `via-*` / `to-*` が色として解決される場合、その色名が `ALLOWED_COLOR_NAMES` に含まれなければ失敗させる。arbitrary color（`bg-[#fff]` など）も失敗させる。色の解決は Tailwind の utility 別のカラーソースフォールバックを踏襲する（例: `border-*` は `theme('borderColor', theme('colors'))`、`divide-*` は `theme('divideColor', theme('borderColor', theme('colors')))`）。現時点では全色を `theme.colors` に集約しているため実質 `colors` だけで済むが、将来 `borderColor` などを独立に定義した場合の取りこぼしを避けるため、分類器は utility ごとのソースを個別参照する
4. **アール検査**: `rounded` および `rounded-*` を抽出し、方向指定（`rounded-t-*` など）を正規化した値が `ALLOWED_RADIUS_NAMES` に含まれる場合だけ許可する。`rounded-sm` / `rounded-md` / `rounded-lg` / `rounded-xl` / `rounded-full` / `rounded-[8px]` は失敗。**bare `rounded`（Tailwind 組み込みの DEFAULT にヒットする）も失敗**とし、明示的な契約由来のキー指定を強制する
5. **影検査**: `shadow` および `shadow-*` を抽出し、値が `ALLOWED_SHADOW_NAMES` に含まれる場合だけ許可する。`shadow-md` / `shadow-lg` / arbitrary value は失敗。**bare `shadow` も失敗**（Tailwind 組み込みの DEFAULT を暗黙に使わせない）

この方式なら `text-sm` `border-2` `ring-2` などのタイポ・線幅・リング幅は接頭辞が同じでも巻き添えにならず、色・アール・影だけを堅く縛れる。実装コストが高すぎる場合の暫定手段としてブラックリストを使う選択はありうるが、その場合はコードレビューと併用する前提とする。

これらの許可集合は **テーマの値ではなく全テーマ共通の utility 契約** である。既存契約を満たす新テーマを追加するときは更新しない。新しいセマンティックトークンを契約へ追加・削除するときだけ、本ドキュメント、Tailwind アダプター、許可集合を同時に更新する。

### 検証 2: テーマ契約整合性

**テストコード内に契約変数の期待集合を明示的に配列で持つ**。全テーマファイルはこの期待集合と過不足なく一致しなければならない。

```php
private const EXPECTED_COLOR_TOKENS = [
    'color-canvas', 'color-surface', 'color-surface-muted', 'color-line',
    'color-content', 'color-content-muted', 'color-content-onbrand',
    'color-brand', 'color-link', 'color-focus',
    'color-action-primary-bg', 'color-action-primary-fg', 'color-action-primary-hover',
    'color-action-confirm-bg', 'color-action-confirm-fg', 'color-action-confirm-hover',
    'color-action-danger-bg', 'color-action-danger-fg', 'color-action-danger-hover',
    'color-chrome-bg', 'color-chrome-fg', 'color-chrome-muted', 'color-chrome-hover',
    'color-status-danger-bg', 'color-status-danger-fg', 'color-status-danger-border',
    'color-status-warning-bg', 'color-status-warning-fg', 'color-status-warning-border',
    'color-status-success-bg', 'color-status-success-fg', 'color-status-success-border',
    'color-status-info-bg', 'color-status-info-fg', 'color-status-info-border',
];
private const EXPECTED_RADIUS_TOKENS = ['radius-control', 'radius-card', 'radius-badge', 'radius-modal'];
private const EXPECTED_SHADOW_TOKENS = ['shadow-card', 'shadow-popover'];
```

`default.css` を基準にする方式は採らない（全テーマから同じ変数を削除しても検出できないため）。契約変数を追加・削除するときは、この配列と本ドキュメントの「テーマ契約」節を同時に更新する（PR レビューで両方の変更を要求する）。

この検証があるため、`themes/` 配下に置くファイルは **すべての契約変数を過不足なく定義する完全テーマ** でなければならない。部分上書きファイル（一部変数だけ差し替える）を作る場合は、`themes/` 以外のディレクトリ（例: `resources/css/design-system/overrides/`）に置いて契約テスト対象外にする。

### 将来的な追加候補（初期は実装しない）

- 主要な bg / fg ペアのコントラスト比検証（WCAG AA 以上）
- 生成 CSS の検査: Tailwind v3 は未定義ユーティリティを書いてもビルドは成功し、単にそのクラスの CSS が出力されないだけなので、build 成功だけでは検出にならない。必要になったら「Tailwind を実行して出力 CSS を parse し、必須セレクタ（`.bg-canvas` 等）が生成されていること」を検証するテストを追加する

---

## 器の検証法

`default` しかない状態でも「テーマの器」が機能しているかを検証するため、開発中だけ `themes/debug.css` を用意する。本番の `app.css` には読み込ませない。

`themes/` 配下は契約テストの対象となるため、debug も **完全テーマとして全契約変数を定義する**（status-* を含む）。値は default から大きく違う色相・アール・影に振ることで、テーマの差し替え面が本当に機能しているかを目視でも確認しやすくする。

```css
[data-theme="debug"] {
  --color-canvas:               255 247 237;
  --color-surface:              255 255 255;
  --color-surface-muted:        254 235 200;
  --color-line:                 253 186 116;

  --color-content:              67 20 7;
  --color-content-muted:        154 52 18;
  --color-content-onbrand:      255 255 255;

  --color-brand:                194 65 12;
  --color-link:                 194 65 12;
  --color-focus:                194 65 12;

  --color-action-primary-bg:    194 65 12;
  --color-action-primary-fg:    255 255 255;
  --color-action-primary-hover: 154 52 18;
  --color-action-danger-bg:     185 28 28;
  --color-action-danger-fg:     255 255 255;
  --color-action-danger-hover:  153 27 27;

  /* status-* も完全に定義する（値は debug らしくビビッドに） */
  --color-status-danger-bg:      254 202 202;
  --color-status-danger-fg:      127 29 29;
  --color-status-danger-border:  248 113 113;
  --color-status-warning-bg:     253 230 138;
  --color-status-warning-fg:     120 53 15;
  --color-status-warning-border: 251 191 36;
  --color-status-success-bg:     187 247 208;
  --color-status-success-fg:     20 83 45;
  --color-status-success-border: 74 222 128;
  --color-status-info-bg:        191 219 254;
  --color-status-info-fg:        30 58 138;
  --color-status-info-border:    96 165 250;

  --radius-control: 9999px;
  --radius-card:    1.5rem;
  --radius-badge:   9999px;
  --radius-modal:   2rem;

  --shadow-card:    0 4px 12px rgb(0 0 0 / 0.15);
  --shadow-popover: 0 20px 40px rgb(0 0 0 / 0.20);
}
```

`<html data-theme="debug">` に切り替えて、**Blade / Livewire コンポーネントに一切変更を加えずに見た目が別物になる** ことを合格ラインとする。

---

## 移行方針

- 新規 UI は最初からこの仕組みに乗せる
- 既存画面は「大きく触るとき」に合わせて移行する（ローカライゼーション規約と同じ段階移行方針）
- 一括置換はしない
- 未整備の期間、既存の素の Tailwind スケールが残っていることは許容する。ただしアーキテクチャテストの対象領域（`resources/views/components/ui/**`）では新規追加を禁じる

---

## スコープ外（現段階では実装しない）

- テーマ切替 UI・ユーザー設定への保存
- 複数テーマ（`default` 以外）
- ダークモード
- 密度切替（余白・行高）
- タイポグラフィのテーマ化
- 業務コンテキストの可視化（クライアント識別のアクセント色、年度状態バッジなど）
- セカンダリボタン専用トークン（`--color-surface` などの組み合わせで組む）
- 選択・ホバー・押下背景の専用トークン（`--color-selection-bg` 等）
- コントラスト比の自動検証
- 生成 CSS を parse して必須ユーティリティが出力されていることを検証する仕組み

これらは、必要になったタイミングで、本設計の器の上に追加する。
