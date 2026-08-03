<div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="space-y-3">
            <div class="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                <span class="rounded-full bg-sky-50 px-2.5 py-1 text-sky-700">{{ $icon }}</span>
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
    </div>

    <form wire:submit="submit" class="mt-6 space-y-6">
        @foreach ($schema as $field => $definition)
            @php($fieldType = $definition['type'] ?? 'text')

            @if ($fieldType === 'array' && isset($definition['item_schema']))
                @php($items = $inputs[$field] ?? [])
                <section class="space-y-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <x-input-label :value="$definition['label']" />
                            @if (isset($definition['help']))
                                <p class="mt-1 text-sm text-slate-500">{{ $definition['help'] }}</p>
                            @endif
                        </div>

                        <x-ui.button-add wire:click="addItem('{{ $field }}')" />
                    </div>

                    <div class="space-y-4">
                        @foreach ($items as $index => $item)
                            <div wire:key="todo-{{ $todo->id }}-{{ $field }}-{{ $index }}"
                                class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-sm font-semibold text-slate-700">{{ $definition['label'] }} {{ $index + 1 }}</p>

                                    <x-ui.button-delete type="button" wire:click="removeItem('{{ $field }}', {{ $index }})" show-icon="false" />
                                </div>

                                <div class="mt-4 grid gap-4 md:grid-cols-2">
                                    @foreach ($definition['item_schema'] as $itemField => $itemDefinition)
                                        @php($itemFieldType = $itemDefinition['type'] ?? 'text')
                                        @php($inputPath = "inputs.$field.$index.$itemField")

                                        <div class="{{ $itemFieldType === 'textarea' ? 'md:col-span-2' : '' }}">
                                            <x-input-label :value="$itemDefinition['label']" />

                                            @if ($itemFieldType === 'textarea')
                                                <textarea wire:model="{{ $inputPath }}" rows="3"
                                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                                            @elseif ($itemFieldType === 'boolean')
                                                <label class="mt-2 inline-flex items-center gap-2 text-sm text-slate-700">
                                                    <input type="checkbox" wire:model="{{ $inputPath }}"
                                                        class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                                                    <span>{{ $itemDefinition['label'] }}</span>
                                                </label>
                                            @else
                                                <x-text-input :type="$itemFieldType === 'number' ? 'number' : 'text'"
                                                    wire:model="{{ $inputPath }}"
                                                    class="mt-1 block w-full" />
                                            @endif

                                            <x-input-error :messages="$errors->get($inputPath)" class="mt-2" />
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <x-input-error :messages="$errors->get('inputs.'.$field)" />
                </section>
            @else
                @php($inputPath = "inputs.$field")
                <section class="space-y-2">
                    <x-input-label :value="$definition['label']" />

                    @if ($fieldType === 'textarea')
                        <textarea wire:model="{{ $inputPath }}" rows="3"
                            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                    @elseif ($fieldType === 'boolean')
                        <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model="{{ $inputPath }}"
                                class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                            <span>{{ $definition['label'] }}</span>
                        </label>
                    @else
                        <x-text-input :type="$fieldType === 'number' ? 'number' : 'text'"
                            wire:model="{{ $inputPath }}"
                            class="block w-full" />
                    @endif

                    @if (isset($definition['help']))
                        <p class="text-sm text-slate-500">{{ $definition['help'] }}</p>
                    @endif

                    <x-input-error :messages="$errors->get($inputPath)" />
                </section>
            @endif
        @endforeach

        <div class="flex justify-end pt-2">
            <x-ui.button-submit wire:loading.attr="disabled">登録して完了する</x-ui.button-submit>
        </div>
    </form>
</div>
