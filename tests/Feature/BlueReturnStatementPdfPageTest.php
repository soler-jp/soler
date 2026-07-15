<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BlueReturnStatementPdfPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 青色申告決算書_pdfページを表示できる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);
        $unit->createFiscalYear(2025);

        $response = $this->actingAs($user)->get(route('blue-return-statement.pdf.show'));

        $response->assertOk();
        $response->assertSee('青色申告決算書PDF');
        $response->assertSee('令和五年分以降用');
        $response->assertSee('PDFを出力');
    }

    #[Test]
    public function 青色申告決算書_pdfをインライン表示で出力できる(): void
    {
        $user = User::factory()->create(['name' => '山田 太郎']);
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);
        $unit->createFiscalYear(2025);

        $response = $this->actingAs($user)->post(route('blue-return-statement.pdf.download'), [
            'blue_return_deduction' => 650000,
            'name' => '山田 太郎',
            'name_kana' => 'ヤマダ タロウ',
            'business_type' => 'ソフトウェア開発業',
        ]);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $response->assertHeader('content-disposition', 'inline; filename="blue-return-statement-2025.pdf"');
        $this->assertSame(4, preg_match_all('/\/Type\s*\/Page\b/', (string) $response->getContent()));
    }

    #[Test]
    public function 青色申告特別控除額が不正ならバリデーションエラーになる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);
        $unit->createFiscalYear(2025);

        $response = $this
            ->from(route('blue-return-statement.pdf.show'))
            ->actingAs($user)
            ->post(route('blue-return-statement.pdf.download'), [
                'blue_return_deduction' => -1,
            ]);

        $response->assertRedirect(route('blue-return-statement.pdf.show'));
        $response->assertSessionHasErrors('blue_return_deduction');
    }
}
