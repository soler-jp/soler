<?php

namespace App\Auditing;

enum AuditTargetRole: string
{
    case Subject = 'subject';
    case Source = 'source';
    case Result = 'result';
    case Affected = 'affected';
}
