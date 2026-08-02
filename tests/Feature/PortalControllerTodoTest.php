<?php

namespace Tests\Feature;

use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PortalControllerTodoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function pending_todoがdashboardに表示される(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => 'ToDo 表示事業体']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);

        Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $fiscalYear->id,
            'title' => '銀行口座を登録する',
            'todo_type' => Todo::TODO_TYPE_WIZARD_BANK_ACCOUNT,
            'status' => Todo::STATUS_PENDING,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('先に進める準備')
            ->assertSee('銀行口座を登録する')
            ->assertSeeLivewire('todo-card');
    }
}
