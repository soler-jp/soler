<?php

namespace App\Concerns;

use App\Contracts\ResolvesBusinessUnit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

trait AuthorizesBusinessUnitAccess
{
    /**
     * actor が対象リソースの属する BusinessUnit にアクセスできることを保証する。
     *
     * actor が null（認証されていない／actor 不明）の場合も拒否する。
     * 認可は検証できない actor に対して fail-closed で振る舞う。
     *
     * @throws AuthorizationException
     */
    protected function authorizeBusinessUnitAccess(
        ResolvesBusinessUnit $resource,
        ?User $user,
        string $message = 'この操作を行う権限がありません。',
    ): void {
        if ($user === null || ! $resource->resolveBusinessUnit()->canAccess($user)) {
            throw new AuthorizationException($message);
        }
    }
}
