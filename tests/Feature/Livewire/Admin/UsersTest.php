<?php

namespace Tests\Feature\Livewire\Admin;

use App\Livewire\Admin\Users;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Livewire\Livewire;
use Tests\TestCase;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Hash;

class UsersTest extends TestCase
{

    use RefreshDatabase;

    #[Test]
    public function 管理者はユーザー一覧を表示できる()
    {
        $admin = User::factory()->create([
            'is_admin' => true,
        ]);

        $user1 = User::factory()->create(['name' => 'ユーザーA']);
        $user2 = User::factory()->create(['name' => 'ユーザーB']);

        $this->actingAs($admin);

        Livewire::test('admin.users')
            ->assertStatus(200)
            ->assertSee('ユーザーA')
            ->assertSee('ユーザーB');
    }


    #[Test]
    public function 管理者はユーザーを登録できる()
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin);

        Livewire::test('admin.users')
            ->set('name', '新規ユーザー')
            ->set('email', 'new@example.com')
            ->set('password', 'password123')
            ->call('createUser');

        $this->assertDatabaseHas('users', [
            'name' => '新規ユーザー',
            'email' => 'new@example.com',
        ]);

        $this->assertTrue(Hash::check('password123', User::where('email', 'new@example.com')->first()->password));
    }

    #[Test]
    public function メールアドレスは必須でユニークでなければならない()
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        // 既存のユーザー（ユニークチェック用）
        User::factory()->create([
            'email' => 'duplicate@example.com',
        ]);

        Livewire::test('admin.users')
            ->set('name', 'テストユーザー')
            ->set('email', '') // 空（必須エラー）
            ->set('password', 'password123')
            ->call('createUser')
            ->assertHasErrors(['email' => 'required']);

        Livewire::test('admin.users')
            ->set('name', 'テストユーザー')
            ->set('email', 'duplicate@example.com') // 重複（ユニークエラー）
            ->set('password', 'password123')
            ->call('createUser')
            ->assertHasErrors(['email' => 'unique']);
    }

    #[Test]
    public function 管理者はユーザーを削除できる()
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $user = User::factory()->create([
            'name' => '削除対象ユーザー',
            'email' => 'delete@example.com',
        ]);

        Livewire::test('admin.users')
            ->call('deleteUser', $user->id);

        $this->assertDatabaseMissing('users', [
            'id' => $user->id,
            'email' => 'delete@example.com',
        ]);
    }

    #[Test]
    public function 管理者でないユーザーはユーザー管理画面にアクセスできない()
    {
        $user = User::factory()->create([
            'is_admin' => false,
        ]);

        $this->actingAs($user);

        $response = $this->get(route('admin.users'));

        $response->assertForbidden(); // 403を期待
    }
}
