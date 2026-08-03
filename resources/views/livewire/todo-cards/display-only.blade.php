<div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
    <div class="flex items-start justify-between gap-4">
        <div class="space-y-3">
            <div class="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                <span>ToDo</span>
                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-600">{{ $todo->priority }}</span>
                @if ($todo->due_on !== null)
                    <span class="rounded-full bg-amber-50 px-2.5 py-1 text-amber-700">
                        期限 {{ $todo->due_on->format('Y-m-d') }}
                    </span>
                @endif
            </div>

            <div class="space-y-2">
                <h2 class="text-xl font-semibold tracking-tight text-slate-900">{{ $todo->title }}</h2>

                <x-todo-body :body="$todo->body" />
            </div>
        </div>

        <x-ui.button-submit type="button" wire:click="complete" wire:loading.attr="disabled" class="shrink-0">完了にする</x-ui.button-submit>
    </div>
</div>
