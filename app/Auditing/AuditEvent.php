<?php

namespace App\Auditing;

enum AuditEvent: string
{
    case TransactionCreated = 'transaction.created';
    case TransactionDeactivated = 'transaction.deactivated';
    case TransactionRevised = 'transaction.revised';
    case FiscalYearClosed = 'fiscal_year.closed';
    case FiscalYearRolledOver = 'fiscal_year.rolled_over';
}
