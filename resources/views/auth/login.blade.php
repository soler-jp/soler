<x-guest-layout>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    {{-- ロゴ --}}
    <div class="text-center">
        <img src="{{ asset('logo_alpha.png') }}" alt="Soler α ロゴ" class="h-14 mx-auto mb-2">
    </div>

    {{-- Session Status --}}
    <x-auth-session-status class="mb-4" :status="session('status')" />


    <form method="POST" action="{{ route('login') }}">
        @csrf
        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input wire:model="form.email" id="email" class="block mt-1 w-full" type="email" name="email"
                required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('form.email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input wire:model="form.password" id="password" class="block mt-1 w-full" type="password"
                name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('form.password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="flex items-center">
            <input wire:model="form.remember" id="remember" type="checkbox"
                class="rounded border-gray-300 text-orange-500 shadow-sm focus:ring-orange-500" name="remember">
            <label for="remember" class="ms-2 text-sm text-gray-600">{{ __('Remember me') }}</label>
        </div>

        <div class="flex items-center justify-between">
            @if (Route::has('password.request'))
                <a class="underline text-sm text-orange-600 hover:text-orange-800"
                    href="{{ route('password.request') }}" wire:navigate>
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <x-primary-button class="bg-orange-500 hover:bg-orange-600 focus:ring-orange-500">
                {{ __('Log in') }}
            </x-primary-button>
        </div>

        {{-- 免責事項 --}}
        <p class="text-xs text-gray-500 text-center mt-4">
            このサービスは現在 <span class="text-orange-500 font-semibold">α版</span> です。<br>
            内容や機能は予告なく変更される可能性があります。<br>
            ご利用による損害について、開発者は一切責任を負いません。
        </p>
    </form>
</x-guest-layout>
