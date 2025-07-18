<?php

namespace App\Livewire\Admin;

use App\Models\User;
use Livewire\Component;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class Users extends Component
{

    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $submitError = '';

    public function createUser(): void
    {

        $this->validate([
            'name' => ['required', 'string'],
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email'),
            ],
            'password' => ['required', 'string', 'min:8'],
        ]);

        User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make($this->password),
            'is_admin' => false,
        ]);
    }

    public function deleteUser(int $userId): void
    {
        $user = User::findOrFail($userId);

        // 自分自身や管理者を削除する制限はこの段階では入れない
        $user->delete();
    }


    public function render()
    {
        return view('livewire.admin.users', [
            'users' => User::orderBy('id')->get(),
        ]);
    }
}
