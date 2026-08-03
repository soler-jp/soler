@php
    $navigationItems = [
        ['label' => __('Dashboard'), 'route' => 'dashboard'],
        ['label' => '売上一覧', 'route' => 'transactions.revenues'],
        ['label' => '経費の月別一覧', 'route' => 'transactions.expenses'],
        ['label' => '経費の種類別一覧', 'route' => 'transactions.expense-types'],
        ['label' => '仕入れ一覧', 'route' => 'transactions.purchases'],
        ['label' => '仕訳帳一覧', 'route' => 'transactions.journal'],
        ['label' => '勘定科目集計', 'route' => 'accounts.summary'],
        ['label' => '年度管理', 'route' => 'fiscal-years.index'],
        ['label' => '固定費', 'route' => 'fixed-expenses'],
        ['label' => '青色申告決算書PDF', 'route' => 'blue-return-statement.pdf.show'],
        ['label' => 'Help', 'route' => 'help.accounting-basics'],
    ];

    $adminItems = [];

    if (Auth::user()?->is_admin) {
        $adminItems[] = ['label' => 'ユーザー管理', 'route' => 'admin.users'];
    }
@endphp

@php
    $fiscalYearLabel = Auth::user()?->selectedBusinessUnit?->currentFiscalYear?->year
        ? Auth::user()->selectedBusinessUnit->currentFiscalYear->year . '年度'
        : '年度未設定';
@endphp

<nav x-data="{ open: false }"
    class="border-b border-chrome-muted/20 bg-chrome lg:sticky lg:top-0 lg:flex lg:h-screen lg:w-64 lg:self-start lg:flex-col lg:overflow-hidden lg:border-b-0 lg:border-r lg:border-r-chrome-muted/20">
    <div class="flex h-14 items-center justify-between border-b border-chrome-muted/20 bg-chrome px-4 lg:hidden">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5">
            <span class="flex h-8 w-8 items-center justify-center">
                <img src="{{ asset('brand/logo-mark-light.png') }}" alt="{{ config('app.name', 'Laravel') }} logo"
                    class="h-7 w-7" />
            </span>
            <span class="text-sm font-semibold text-chrome-fg">{{ $fiscalYearLabel }}</span>
        </a>

        <button @click="open = ! open"
            class="inline-flex items-center justify-center border border-chrome-muted/30 bg-chrome-hover p-2 text-chrome-fg transition hover:bg-chrome-hover hover:text-chrome-fg focus:outline-none">
            <svg class="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                <path :class="{ 'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round"
                    stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                <path :class="{ 'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round"
                    stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <div class="hidden lg:flex lg:h-full lg:flex-1 lg:flex-col">
        <div class="border-b border-chrome-muted/20 bg-chrome px-5 py-4">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center">
                    <img src="{{ asset('brand/logo-mark-light.png') }}" alt="{{ config('app.name', 'Laravel') }} logo"
                        class="h-9 w-9" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-chrome-muted">会計年度</p>
                    <p class="truncate text-base font-semibold text-chrome-fg">{{ $fiscalYearLabel }}</p>
                </div>
            </a>
        </div>

        <div class="flex-1 overflow-y-auto py-3">
            <div>
                <p class="border-b border-chrome-muted/20 px-5 py-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-chrome-muted">Main Menu</p>
                <div class="py-1">
                    @foreach ($navigationItems as $item)
                        @php
                            $isActive = request()->routeIs($item['route']);
                            $linkClasses = $isActive
                                ? 'border-brand bg-surface font-semibold text-content'
                                : 'border-transparent text-chrome-fg hover:border-chrome-muted/40 hover:bg-chrome-hover hover:text-chrome-fg';
                        @endphp
                        <a href="{{ route($item['route']) }}"
                            class="{{ $linkClasses }} flex items-center border-l-[3px] px-5 py-2.5 text-[13px] leading-5 transition">
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>

            @if ($adminItems !== [])
                <div class="mt-4">
                    <p class="border-b border-chrome-muted/20 px-5 py-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-chrome-muted">Admin</p>
                    <div class="py-1">
                        @foreach ($adminItems as $item)
                        @php
                            $isActive = request()->routeIs($item['route']);
                            $linkClasses = $isActive
                                ? 'border-brand bg-surface font-semibold text-content'
                                : 'border-transparent text-chrome-fg hover:border-chrome-muted/40 hover:bg-chrome-hover hover:text-chrome-fg';
                            @endphp
                            <a href="{{ route($item['route']) }}"
                                class="{{ $linkClasses }} flex items-center border-l-[3px] px-5 py-2.5 text-[13px] leading-5 transition">
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="border-t border-chrome-muted/20 bg-chrome px-5 py-4">
            <p class="text-[13px] font-semibold text-chrome-fg">{{ Auth::user()->name }}</p>
            <p class="mt-0.5 truncate text-[12px] text-chrome-muted">{{ Auth::user()->email }}</p>

            <div class="mt-3 border-t border-chrome-muted/20 pt-3">

                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <button type="submit"
                        class="mt-1 flex w-full items-center px-0 py-1.5 text-left text-[13px] text-chrome-fg transition hover:text-chrome-fg">
                        {{ __('Log Out') }}
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div :class="{ 'block': open, 'hidden': ! open }" class="hidden border-t border-chrome-muted/20 bg-chrome lg:hidden">
        <div class="border-b border-chrome-muted/20 px-4 py-3">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-chrome-muted">会計年度</p>
            <p class="text-sm font-semibold text-chrome-fg">{{ $fiscalYearLabel }}</p>
        </div>

        <div class="py-1">
            @foreach ($navigationItems as $item)
                @php
                    $isActive = request()->routeIs($item['route']);
                    $linkClasses = $isActive
                        ? 'border-brand bg-surface font-semibold text-content'
                        : 'border-transparent text-chrome-fg hover:border-chrome-muted/40 hover:bg-chrome-hover hover:text-chrome-fg';
                @endphp
                <a href="{{ route($item['route']) }}"
                    class="{{ $linkClasses }} block border-l-[3px] px-4 py-2.5 text-sm transition">
                    {{ $item['label'] }}
                </a>
            @endforeach
        </div>

        @if ($adminItems !== [])
            <div class="border-t border-chrome-muted/20 py-2">
                <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-chrome-muted">Admin</p>
                <div>
                    @foreach ($adminItems as $item)
                        @php
                            $isActive = request()->routeIs($item['route']);
                            $linkClasses = $isActive
                                ? 'border-brand bg-surface font-semibold text-content'
                                : 'border-transparent text-chrome-fg hover:border-chrome-muted/40 hover:bg-chrome-hover hover:text-chrome-fg';
                        @endphp
                        <a href="{{ route($item['route']) }}"
                            class="{{ $linkClasses }} block border-l-[3px] px-4 py-2.5 text-sm transition">
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="border-t border-chrome-muted/20 px-4 py-3">
            <div class="text-[13px] font-semibold text-chrome-fg">{{ Auth::user()->name }}</div>
            <div class="text-[12px] text-chrome-muted">{{ Auth::user()->email }}</div>

            <div class="mt-2 space-y-1">

                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <a href="{{ route('logout') }}"
                        class="block border-l-[3px] border-transparent px-4 py-2.5 text-sm text-chrome-fg transition hover:border-chrome-muted/40 hover:bg-chrome-hover hover:text-chrome-fg"
                        onclick="event.preventDefault(); this.closest('form').submit();">
                        {{ __('Log Out') }}
                    </a>
                </form>
            </div>
        </div>
    </div>
</nav>
