<?php

namespace Tests\Unit;

use App\Models\Account;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccountTest extends TestCase
{
    #[Test]
    public function type_labelsは全typeを網羅する(): void
    {
        foreach (Account::TYPES as $type) {
            $this->assertArrayHasKey($type, Account::TYPE_LABELS);
            $this->assertNotSame('', Account::TYPE_LABELS[$type]);
        }
    }

    #[Test]
    public function type_labelsは想定した日本語ラベルを返す(): void
    {
        $this->assertSame('資産', Account::TYPE_LABELS[Account::TYPE_ASSET]);
        $this->assertSame('負債', Account::TYPE_LABELS[Account::TYPE_LIABILITY]);
        $this->assertSame('資本', Account::TYPE_LABELS[Account::TYPE_EQUITY]);
        $this->assertSame('収益', Account::TYPE_LABELS[Account::TYPE_REVENUE]);
        $this->assertSame('費用', Account::TYPE_LABELS[Account::TYPE_EXPENSE]);
    }
}
