<div class="max-w-2xl mx-auto space-y-8">

    <h1 class="text-2xl font-bold">ユーザー管理</h1>

    {{-- 登録フォーム --}}
    <div class="border rounded p-4 space-y-4 shadow">
        <h2 class="text-lg font-semibold">新規ユーザー登録</h2>

        <div class="space-y-2">
            <label class="block">
                名前:
                <input type="text" wire:model.defer="name" class="border rounded w-full px-2 py-1">
                @error('name')
                    <span class="text-red-500 text-sm">{{ $message }}</span>
                @enderror
            </label>

            <label class="block">
                メールアドレス:
                <input type="email" wire:model.defer="email" class="border rounded w-full px-2 py-1">
                @error('email')
                    <span class="text-red-500 text-sm">{{ $message }}</span>
                @enderror
            </label>

            <label class="block">
                パスワード:
                <input type="password" wire:model.defer="password" class="border rounded w-full px-2 py-1">
                @error('password')
                    <span class="text-red-500 text-sm">{{ $message }}</span>
                @enderror
            </label>

            <button wire:click="createUser" class="bg-blue-600 text-white px-4 py-1 rounded">
                登録
            </button>
        </div>
    </div>

    {{-- 一覧表示 --}}
    <div class="border rounded p-4 shadow">
        <h2 class="text-lg font-semibold mb-2">ユーザー一覧</h2>

        <table class="w-full table-auto border">
            <thead class="bg-gray-100">
                <tr>
                    <th class="border px-4 py-2 text-left">ID</th>
                    <th class="border px-4 py-2 text-left">名前</th>
                    <th class="border px-4 py-2 text-left">メール</th>
                    <th class="border px-4 py-2 text-left">管理者</th>
                    <th class="border px-4 py-2 text-left">操作</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td class="border px-4 py-2">{{ $user->id }}</td>
                        <td class="border px-4 py-2">{{ $user->name }}</td>
                        <td class="border px-4 py-2">{{ $user->email }}</td>
                        <td class="border px-4 py-2">
                            {{ $user->is_admin ? '✔' : '' }}
                        </td>
                        <td class="border px-4 py-2">
                            <button wire:click="deleteUser({{ $user->id }})"
                                class="text-sm text-red-600 hover:underline" onclick="return confirm('本当に削除しますか？')">
                                削除
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

</div>
