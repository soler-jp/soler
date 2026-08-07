<?php

namespace App\Auditing;

use App\Contracts\ResolvesBusinessUnit;
use Illuminate\Database\Eloquent\Model;

final class AuditTarget
{
    public function __construct(
        public readonly AuditTargetRole $role,
        public readonly Model&ResolvesBusinessUnit $model,
    ) {}
}
