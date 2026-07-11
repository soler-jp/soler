<?php

namespace Tests\Unit;

use App\Services\BlueReturnPdf\Proof\FieldCatalog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BlueReturnProofFieldCatalogTest extends TestCase
{
    #[Test]
    public function 損益計算書の全45欄を欄番号順に持つ(): void
    {
        $fields = FieldCatalog::profitAndLossFields();

        $this->assertCount(45, $fields);
        $this->assertSame(range(1, 45), array_column($fields, 'field_number'));

        foreach ($fields as $fieldKey => $definition) {
            $this->assertNotSame('', $definition['label'], "{$fieldKey} のラベルが空です");
        }
    }
}
