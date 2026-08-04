<?php

namespace Tests\Unit\Architecture;

use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * `resources/views/components/ui/**` 配下の Blade コンポーネントが、
 * 色・アール（角丸）・影の用途で契約由来のユーティリティだけを使うことを保証する。
 * また `resources/css/design-system/themes/**` の全テーマファイルが、
 * テーマ契約の変数を過不足なく定義していることを保証する。
 *
 * 詳細は docs/theme-design.md を参照。
 */
class ThemeTokenTest extends TestCase
{
    /**
     * 契約由来のセマンティックカラー名。ここに含まれないカラー名 (Tailwind 標準スケール等) は
     * ui コンポーネントで色 utility として使ってはならない。
     */
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
        'accent-revenue', 'accent-revenue-fg', 'accent-revenue-border',
        'accent-expense', 'accent-expense-fg', 'accent-expense-border',
        'accent-purchase', 'accent-purchase-fg', 'accent-purchase-border',
        'transparent', 'current', 'inherit',
    ];

    private const ALLOWED_RADIUS_NAMES = ['control', 'card', 'badge', 'modal', 'none'];

    private const ALLOWED_SHADOW_NAMES = ['card', 'popover', 'none'];

    /**
     * Tailwind 既定のカラースケール名。`bg-<name>-500` 等のパターンを検出するために使う。
     * `black` / `white` は数値サフィックスなしでも色として解決されるため別扱い。
     */
    private const TAILWIND_DEFAULT_COLOR_ROOTS = [
        'slate', 'gray', 'zinc', 'neutral', 'stone',
        'red', 'orange', 'amber', 'yellow', 'lime',
        'green', 'emerald', 'teal', 'cyan', 'sky', 'blue',
        'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose',
    ];

    private const TAILWIND_STANDALONE_COLORS = ['black', 'white'];

    /**
     * 色 utility として扱う接頭辞。ここに含まれる接頭辞のクラスは色検査の対象になる。
     */
    private const COLOR_UTILITY_PREFIXES = [
        'bg', 'text', 'border', 'ring', 'ring-offset', 'divide', 'outline',
        'decoration', 'placeholder', 'accent', 'caret', 'fill', 'stroke',
        'from', 'via', 'to',
    ];

    /**
     * テーマ契約: 色トークン。
     */
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
        'color-accent-revenue-bg', 'color-accent-revenue-fg', 'color-accent-revenue-border',
        'color-accent-expense-bg', 'color-accent-expense-fg', 'color-accent-expense-border',
        'color-accent-purchase-bg', 'color-accent-purchase-fg', 'color-accent-purchase-border',
    ];

    private const EXPECTED_RADIUS_TOKENS = [
        'radius-control', 'radius-card', 'radius-badge', 'radius-modal',
    ];

    private const EXPECTED_SHADOW_TOKENS = [
        'shadow-card', 'shadow-popover',
    ];

    public function test_ui_components_use_only_contract_color_radius_shadow_utilities(): void
    {
        $violations = [];

        foreach ($this->uiComponentFiles() as $file) {
            $path = $file->getRelativePathname();
            $classes = $this->extractClasses((string) file_get_contents($file->getPathname()));

            foreach ($classes as $raw) {
                $stripped = $this->stripVariantsAndOpacity($raw);
                if ($stripped === '') {
                    continue;
                }

                $error = $this->classifyAndCheck($stripped);
                if ($error !== null) {
                    $violations[] = "{$path}: `{$raw}` — {$error}";
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "テーマ規約違反 (詳細は docs/theme-design.md 参照):\n - ".implode("\n - ", $violations),
        );
    }

    public function test_all_theme_files_define_exactly_the_expected_contract_variables(): void
    {
        $expected = array_merge(
            self::EXPECTED_COLOR_TOKENS,
            self::EXPECTED_RADIUS_TOKENS,
            self::EXPECTED_SHADOW_TOKENS,
        );
        sort($expected);

        $violations = [];

        foreach ($this->themeFiles() as $file) {
            $path = $file->getRelativePathname();
            $contents = (string) file_get_contents($file->getPathname());

            $defined = $this->extractContractVariables($contents);
            sort($defined);

            $missing = array_values(array_diff($expected, $defined));
            $unexpected = array_values(array_diff($defined, $expected));

            if ($missing !== []) {
                $violations[] = "{$path}: 契約変数の未定義: ".implode(', ', $missing);
            }
            if ($unexpected !== []) {
                $violations[] = "{$path}: 契約外の変数を定義: ".implode(', ', $unexpected);
            }
        }

        $this->assertSame(
            [],
            $violations,
            "テーマ契約整合性違反 (詳細は docs/theme-design.md 参照):\n - ".implode("\n - ", $violations),
        );
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function uiComponentFiles(): iterable
    {
        $dir = resource_path('views/components/ui');
        if (! is_dir($dir)) {
            return [];
        }

        return (new Finder)
            ->files()
            ->in($dir)
            ->name('*.blade.php');
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function themeFiles(): iterable
    {
        $dir = resource_path('css/design-system/themes');
        if (! is_dir($dir)) {
            return [];
        }

        return (new Finder)
            ->files()
            ->in($dir)
            ->name('*.css');
    }

    /**
     * Blade 中のあらゆる静的文字列からクラス名候補を抽出する。
     * `class="..."` 属性、`class => '...'` の配列、match 式の文字列リテラルなどを
     * ざっくり拾えるように、単に「Tailwind クラス風のトークン」を全部集める。
     *
     * @return list<string>
     */
    private function extractClasses(string $source): array
    {
        // 属性: class="..." または class='...'
        $classes = [];
        if (preg_match_all('/class\s*=\s*(["\'])(.*?)\1/s', $source, $m1)) {
            foreach ($m1[2] as $group) {
                foreach (preg_split('/\s+/', $group) ?: [] as $c) {
                    if ($c !== '') {
                        $classes[] = $c;
                    }
                }
            }
        }

        // 配列/文字列リテラル: 'class' => '...' もしくは "class" => "..."
        if (preg_match_all('/["\']class["\']\s*=>\s*(["\'])(.*?)\1/s', $source, $m2)) {
            foreach ($m2[2] as $group) {
                foreach (preg_split('/\s+/', $group) ?: [] as $c) {
                    if ($c !== '') {
                        $classes[] = $c;
                    }
                }
            }
        }

        // @php ブロック内など: 一般の文字列リテラルからも Tailwind っぽいクラスを抽出
        if (preg_match_all("/'([^'\n]*)'/", $source, $m3)) {
            foreach ($m3[1] as $group) {
                foreach (preg_split('/\s+/', $group) ?: [] as $c) {
                    if ($this->looksLikeUtilityClass($c)) {
                        $classes[] = $c;
                    }
                }
            }
        }

        return array_values(array_unique($classes));
    }

    private function looksLikeUtilityClass(string $token): bool
    {
        if ($token === '' || strlen($token) > 80) {
            return false;
        }

        // Tailwind っぽい: [a-z0-9:\-/\[\]#().] のみ、かつ先頭は英字
        return preg_match('/^[a-z][a-z0-9:\-\/\[\]#().]*$/', $token) === 1;
    }

    /**
     * 先頭の variant (`hover:`, `md:`, `dark:` など) と末尾の opacity 修飾子 (`/50` など) を剥がす。
     */
    private function stripVariantsAndOpacity(string $class): string
    {
        // 末尾 `/xx`
        $class = preg_replace('#/[0-9]+$#', '', $class) ?? $class;
        // 先頭 variants: 任意個の `xxx:`
        $class = preg_replace('/^(?:[a-z0-9\-\[\]#()]+:)+/', '', $class) ?? $class;

        return $class;
    }

    /**
     * 対象クラスを色/アール/影に分類し、契約外なら理由文字列を返す。許可なら null。
     */
    private function classifyAndCheck(string $class): ?string
    {
        // 影
        if ($class === 'shadow') {
            return 'bare `shadow` は禁止 (契約由来の shadow-card / shadow-popover / shadow-none を使うこと)';
        }
        if (preg_match('/^shadow-(.+)$/', $class, $m)) {
            $rest = $m[1];
            // shadow-<color> の可能性を除外するため、まず allowed shadow name をチェック
            if (in_array($rest, self::ALLOWED_SHADOW_NAMES, true)) {
                return null;
            }
            // arbitrary value
            if (str_starts_with($rest, '[')) {
                return 'arbitrary shadow value は禁止';
            }
            // Tailwind 既定の shadow スケール (sm/md/lg/xl/2xl/inner)、shadow-<color>
            if ($this->isColorishSuffix($rest)) {
                return '契約外の shadow カラー修飾は禁止 (許可: '.implode(', ', self::ALLOWED_SHADOW_NAMES).')';
            }

            return "契約外の shadow: `shadow-{$rest}` (許可: ".implode(', ', self::ALLOWED_SHADOW_NAMES).')';
        }

        // アール
        if ($class === 'rounded') {
            return 'bare `rounded` は禁止 (契約由来の rounded-card / rounded-control / rounded-badge / rounded-modal / rounded-none を使うこと)';
        }
        if (preg_match('/^rounded(?:-(?:t|b|l|r|tl|tr|bl|br|s|e|ss|se|es|ee))?-(.+)$/', $class, $m)) {
            $rest = $m[1];
            if (str_starts_with($rest, '[')) {
                return 'arbitrary radius value は禁止';
            }
            if (! in_array($rest, self::ALLOWED_RADIUS_NAMES, true)) {
                return "契約外の radius: `{$class}` (許可: ".implode(', ', self::ALLOWED_RADIUS_NAMES).')';
            }

            return null;
        }
        // 方向のみで値なし (`rounded-t` など) も bare 相当として禁止
        if (preg_match('/^rounded-(?:t|b|l|r|tl|tr|bl|br|s|e|ss|se|es|ee)$/', $class)) {
            return "bare `{$class}` は禁止 (契約由来のキーを明示すること)";
        }

        // 色
        return $this->checkColorUtility($class);
    }

    private function checkColorUtility(string $class): ?string
    {
        foreach (self::COLOR_UTILITY_PREFIXES as $prefix) {
            if (! str_starts_with($class, $prefix.'-')) {
                continue;
            }
            $rest = substr($class, strlen($prefix) + 1);

            // arbitrary color: `bg-[#fff]`
            if (str_starts_with($rest, '[')) {
                return "arbitrary color value は禁止 (prefix=`{$prefix}`)";
            }

            // 契約カラー
            if (in_array($rest, self::ALLOWED_COLOR_NAMES, true)) {
                return null;
            }

            // Tailwind 既定カラースケール: <root>-<shade> / <root>
            $colorRoot = preg_replace('/-[0-9]+$/', '', $rest);
            if (in_array($colorRoot, self::TAILWIND_DEFAULT_COLOR_ROOTS, true)) {
                return "生の Tailwind カラースケール `{$class}` は禁止 (契約由来のカラーを使うこと)";
            }
            if (in_array($rest, self::TAILWIND_STANDALONE_COLORS, true)) {
                return "生の Tailwind カラー `{$class}` は禁止 (契約由来のカラーを使うこと)";
            }

            // それ以外の suffix は非カラー utility (例: text-sm, border-2, ring-2) とみなし許可
            return null;
        }

        return null;
    }

    /**
     * suffix が `<root>-<shade>` あるいは既知の色っぽい文字列かどうか。
     */
    private function isColorishSuffix(string $suffix): bool
    {
        $root = preg_replace('/-[0-9]+$/', '', $suffix);
        if (in_array($root, self::TAILWIND_DEFAULT_COLOR_ROOTS, true)) {
            return true;
        }
        if (in_array($suffix, self::TAILWIND_STANDALONE_COLORS, true)) {
            return true;
        }

        return false;
    }

    /**
     * CSS ファイル中で `--foo:` または `--foo :` の形で定義されている契約変数名 (色/アール/影) を返す。
     * `--theme-*` などの私的変数は契約対象外なので除外する。
     *
     * @return list<string>
     */
    private function extractContractVariables(string $css): array
    {
        if (! preg_match_all('/--([a-z0-9\-]+)\s*:/i', $css, $m)) {
            return [];
        }

        $names = array_unique($m[1]);

        return array_values(array_filter($names, function (string $name): bool {
            return str_starts_with($name, 'color-')
                || str_starts_with($name, 'radius-')
                || str_starts_with($name, 'shadow-');
        }));
    }
}
