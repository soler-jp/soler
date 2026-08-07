@php
    $navigationSections = [
        [
            'items' => [
                ['label' => __('Dashboard'), 'route' => 'dashboard'],
            ],
        ],
        [
            'items' => [
                ['label' => __('navigation.revenue'), 'route' => 'transactions.revenues'],
                ['label' => __('navigation.expense'), 'route' => 'transactions.expenses'],
                ['label' => __('navigation.purchase'), 'route' => 'transactions.purchases'],
            ],
        ],
        [
            'header' => __('navigation.section_review'),
            'items' => [
                ['label' => __('navigation.expense_by_type'), 'route' => 'transactions.expense-types'],
                ['label' => __('navigation.journal'), 'route' => 'transactions.journal'],
                ['label' => __('navigation.account_summary'), 'route' => 'accounts.summary'],
                ['label' => __('navigation.fixed_expenses'), 'route' => 'fixed-expenses'],
            ],
        ],
        [
            'header' => __('navigation.section_other'),
            'items' => [
                ['label' => __('navigation.fixed_assets'), 'route' => 'fixed-assets.index'],
                ['label' => __('navigation.blue_return_pdf'), 'route' => 'blue-return-statement.pdf.show'],
                ['label' => __('navigation.audit_logs'), 'route' => 'audit-logs.index'],
            ],
        ],
        [
            'header' => __('navigation.section_settings'),
            'items' => [
                ['label' => __('navigation.fiscal_years'), 'route' => 'fiscal-years.index'],
            ],
        ],
        [
            'items' => [
                ['label' => 'Help', 'route' => 'help.accounting-basics'],
            ],
        ],
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
    class="border-b border-chrome-muted/20 bg-chrome lg:sticky lg:top-0 lg:flex lg:h-screen lg:w-64 lg:self-start lg:flex-col lg:overflow-hidden lg:border-b-0">
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
            @foreach ($navigationSections as $sectionIndex => $section)
                <div @class(['mt-4' => $sectionIndex > 0])>
                    @if (isset($section['header']))
                        <p class="border-b border-chrome-muted/20 px-5 py-2 text-[10px] font-medium uppercase tracking-[0.2em] text-chrome-muted/70">
                            {{ $section['header'] }}
                        </p>
                    @elseif ($sectionIndex > 0)
                        <div class="border-t border-chrome-muted/20"></div>
                    @endif
                    <div class="py-1">
                        @foreach ($section['items'] as $item)
                            @if (isset($item['route']))
                                @php
                                    $isActive = request()->routeIs($item['route']);
                                @endphp
                                <a href="{{ route($item['route']) }}"
                                    @class([
                                        'flex items-center border-l-[3px] py-2.5 text-[13px] leading-5 transition',
                                        'border-brand bg-canvas pl-5 font-semibold text-content' => $isActive,
                                        'border-transparent px-5 text-chrome-fg hover:border-chrome-muted/40 hover:bg-chrome-hover' => ! $isActive,
                                    ])>
                                    {{ $item['label'] }}
                                </a>
                            @else
                                <span
                                    class="flex cursor-default items-center border-l-[3px] border-transparent px-5 py-2.5 text-[13px] leading-5 text-chrome-muted/50">
                                    {{ $item['label'] }}
                                </span>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endforeach

            @if ($adminItems !== [])
                <div class="mt-4">
                    <p class="border-b border-chrome-muted/20 px-5 py-2 text-[10px] font-medium uppercase tracking-[0.2em] text-chrome-muted/70">Admin</p>
                    <div class="py-1">
                        @foreach ($adminItems as $item)
                            @php
                                $isActive = request()->routeIs($item['route']);
                            @endphp
                            <a href="{{ route($item['route']) }}"
                                @class([
                                    'flex items-center border-l-[3px] py-2.5 text-[13px] leading-5 transition',
                                    'border-brand bg-canvas pl-5 font-semibold text-content' => $isActive,
                                    'border-transparent px-5 text-chrome-fg hover:border-chrome-muted/40 hover:bg-chrome-hover' => ! $isActive,
                                ])>
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

        @foreach ($navigationSections as $sectionIndex => $section)
            <div @class([
                'py-1',
                'border-t border-chrome-muted/20' => $sectionIndex > 0,
            ])>
                @if (isset($section['header']))
                    <p class="px-4 pb-1 pt-2 text-[10px] font-medium uppercase tracking-[0.2em] text-chrome-muted/70">
                        {{ $section['header'] }}
                    </p>
                @endif
                @foreach ($section['items'] as $item)
                    @if (isset($item['route']))
                        @php
                            $isActive = request()->routeIs($item['route']);
                            $linkClasses = $isActive
                                ? 'border-brand bg-canvas font-semibold text-content'
                                : 'border-transparent text-chrome-fg hover:border-chrome-muted/40 hover:bg-chrome-hover';
                        @endphp
                        <a href="{{ route($item['route']) }}"
                            class="{{ $linkClasses }} block border-l-[3px] px-4 py-2.5 text-sm transition">
                            {{ $item['label'] }}
                        </a>
                    @else
                        <span
                            class="block cursor-default border-l-[3px] border-transparent px-4 py-2.5 text-sm text-chrome-muted/50">
                            {{ $item['label'] }}
                        </span>
                    @endif
                @endforeach
            </div>
        @endforeach

        @if ($adminItems !== [])
            <div class="border-t border-chrome-muted/20 py-2">
                <p class="px-3 pb-1 text-[10px] font-medium uppercase tracking-[0.2em] text-chrome-muted/70">Admin</p>
                <div>
                    @foreach ($adminItems as $item)
                        @php
                            $isActive = request()->routeIs($item['route']);
                            $linkClasses = $isActive
                                ? 'border-brand bg-canvas font-semibold text-content'
                                : 'border-transparent text-chrome-fg hover:border-chrome-muted/40 hover:bg-chrome-hover';
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
                        class="block border-l-[3px] border-transparent px-4 py-2.5 text-sm text-chrome-fg transition hover:border-chrome-muted/40 hover:bg-chrome-hover"
                        onclick="event.preventDefault(); this.closest('form').submit();">
                        {{ __('Log Out') }}
                    </a>
                </form>
            </div>
        </div>
    </div>
</nav>
