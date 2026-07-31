<?php

namespace Tests\Unit\Architecture;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Concerns\SkipActorGuard;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * `app/Services/**` 配下の全クラスが認可ガード規約に従うことを保証する。
 *
 * ルール:
 *  - クラスは [[AuthorizesBusinessUnitAccess]] trait を use するか、
 *    クラスレベルの `#[SkipActorGuard]` (親クラスからの継承可) を持つこと。
 *  - trait を use するクラスに新たに宣言された public メソッドは、
 *    本文中で `authorizeBusinessUnitAccess(` を呼び出すか、
 *    メソッドレベルの `#[SkipActorGuard]` を持つこと。
 */
class ActorAuthorizationTest extends TestCase
{
    public function test_all_service_classes_are_guarded_or_explicitly_skipped(): void
    {
        $violations = [];

        foreach ($this->serviceClasses() as $class) {
            $reflection = new ReflectionClass($class);

            if ($this->hasInheritedClassSkip($reflection)) {
                continue;
            }

            if (! $this->usesTraitRecursive($reflection, AuthorizesBusinessUnitAccess::class)) {
                $violations[] = sprintf(
                    "%s: %s を use していません。対象外にする場合は #[SkipActorGuard('理由')] をクラスに付与してください。",
                    $class,
                    AuthorizesBusinessUnitAccess::class,
                );

                continue;
            }

            $guardedMethodNames = $this->guardedMethodNames($reflection);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                    continue;
                }

                if ($method->isConstructor() || $method->isDestructor()) {
                    continue;
                }

                if ($method->getAttributes(SkipActorGuard::class) !== []) {
                    continue;
                }

                if (! isset($guardedMethodNames[$method->getName()])) {
                    $violations[] = sprintf(
                        "%s::%s(): authorizeBusinessUnitAccess() を（直接または同クラス内ヘルパー経由で）呼び出していません。対象外にする場合は #[SkipActorGuard('理由')] をメソッドに付与してください。",
                        $class,
                        $method->getName(),
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "認可ガード規約違反 (詳細は docs/CLAUDE.md 参照):\n - ".implode("\n - ", $violations),
        );
    }

    /**
     * @return iterable<class-string>
     */
    private function serviceClasses(): iterable
    {
        $finder = (new Finder)
            ->files()
            ->in(app_path('Services'))
            ->name('*.php');

        foreach ($finder as $file) {
            $class = $this->classFromFile($file);
            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isInterface() || $reflection->isTrait()) {
                continue;
            }

            yield $class;
        }
    }

    private function classFromFile(SplFileInfo $file): ?string
    {
        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            return null;
        }

        if (! preg_match('/^namespace\s+([^;]+);/m', $contents, $nsMatch)) {
            return null;
        }

        if (! preg_match('/^(?:abstract\s+|final\s+)?class\s+(\w+)/m', $contents, $classMatch)) {
            return null;
        }

        return $nsMatch[1].'\\'.$classMatch[1];
    }

    private function hasInheritedClassSkip(ReflectionClass $class): bool
    {
        $current = $class;
        do {
            if ($current->getAttributes(SkipActorGuard::class) !== []) {
                return true;
            }
            $current = $current->getParentClass();
        } while ($current);

        return false;
    }

    /**
     * @param  class-string  $trait
     */
    private function usesTraitRecursive(ReflectionClass $class, string $trait): bool
    {
        $current = $class;
        do {
            if (in_array($trait, $this->allTraits($current), true)) {
                return true;
            }
            $current = $current->getParentClass();
        } while ($current);

        return false;
    }

    /**
     * @return list<class-string>
     */
    private function allTraits(ReflectionClass $class): array
    {
        $traits = $class->getTraitNames();
        foreach ($class->getTraits() as $trait) {
            $traits = array_merge($traits, $this->allTraits($trait));
        }

        return array_values(array_unique($traits));
    }

    /**
     * このクラス自身に宣言された全メソッドのうち、
     * `authorizeBusinessUnitAccess()` を直接呼び出すもの、
     * および同クラス内で「そうしたメソッド」を呼び出すもの（間接ラッパー）の名前集合を返す。
     *
     * @return array<string, true>
     */
    private function guardedMethodNames(ReflectionClass $class): array
    {
        $ownMethods = array_values(array_filter(
            $class->getMethods(),
            fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $class->getName(),
        ));

        $sources = [];
        foreach ($ownMethods as $method) {
            $sources[$method->getName()] = $this->methodSource($method);
        }

        $guarded = [];
        foreach ($sources as $name => $source) {
            if (str_contains($source, 'authorizeBusinessUnitAccess(')) {
                $guarded[$name] = true;
            }
        }

        do {
            $changed = false;
            foreach ($sources as $name => $source) {
                if (isset($guarded[$name])) {
                    continue;
                }
                foreach ($guarded as $guardedName => $_) {
                    $quoted = preg_quote($guardedName, '/');
                    $pattern = '/(?:\$this\s*->|self\s*::|static\s*::)\s*'.$quoted.'\s*\(/';
                    if (preg_match($pattern, $source) === 1) {
                        $guarded[$name] = true;
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);

        return $guarded;
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $file = $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();

        if ($file === false || $start === false || $end === false) {
            return '';
        }

        $lines = file($file);
        if ($lines === false) {
            return '';
        }

        $raw = implode('', array_slice($lines, $start - 1, $end - $start + 1));

        return $this->stripCommentsAndStrings($raw);
    }

    /**
     * コメント／文字列リテラル中に「呼んでいるフリ」の記述があっても
     * ガード扱いされないよう、実コードだけを残した文字列を返す。
     */
    private function stripCommentsAndStrings(string $source): string
    {
        $tokens = @token_get_all('<?php '.$source);
        $out = '';
        foreach ($tokens as $token) {
            if (is_string($token)) {
                $out .= $token;

                continue;
            }
            [$id, $text] = $token;
            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }
            if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
                continue;
            }
            $out .= $text;
        }

        return $out;
    }
}
