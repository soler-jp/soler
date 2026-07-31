<?php

namespace App\Concerns;

use Attribute;

/**
 * Service クラスまたはそのメソッドを、認可ガード規約
 * ([[AuthorizesBusinessUnitAccess]] による actor ガード) の対象外にするマーカー。
 *
 * クラスに付与するとそのクラス全体、メソッドに付与するとそのメソッドのみが
 * `ActorAuthorizationTest` の検査対象外になる。
 * どちらの場合も除外理由を `reason` に必ず記載すること。
 *
 * クラスレベルの属性は親クラスにも遡って探索されるため、
 * 抽象基底クラスに付与すればすべての子クラスへ暗黙的に伝播する。
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class SkipActorGuard
{
    public function __construct(public string $reason) {}
}
