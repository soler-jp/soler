<?php

namespace Tests\Feature;

use App\Livewire\Recurring\IncomeForm;
use App\Livewire\Recurring\IncomeRealizationList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecurringIncomePageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 定期収入管理ページを表示できる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '定期収入ページ']);
        $unit->createFiscalYear(2026, $user);

        $response = $this->actingAs($user)->get(route('recurring-incomes'));

        $response->assertOk()
            ->assertSeeLivewire(IncomeForm::class)
            ->assertSeeLivewire(IncomeRealizationList::class)
            ->assertSee(__('navigation.recurring_incomes'));
    }
}
