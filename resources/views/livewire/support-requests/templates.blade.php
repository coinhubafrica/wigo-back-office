<div>
    <div class="flex flex-wrap items-center gap-3">
        <p class="text-sm text-muted">{{ __('backoffice.support_requests.templates_hint') }}</p>

        <span class="flex-1"></span>

        <a href="{{ route('bo.support-requests') }}" wire:navigate
           class="rounded border border-line bg-card px-3.5 py-2 text-sm font-medium text-ink hover:border-primary">
            {{ __('backoffice.support_requests.back_to_queue') }}
        </a>

        <button wire:click="newTemplate"
                class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
            {{ __('backoffice.support_requests.template_new') }}
        </button>
    </div>

    <div class="mt-4 overflow-hidden rounded border border-line bg-card">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead class="bg-surface">
                    <tr>
                        <th class="px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.support_requests.template_title') }}</th>
                        <th class="px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.support_requests.template_shortcut') }}</th>
                        <th class="px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.support_requests.category') }}</th>
                        <th class="px-4 py-2.5 text-right text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.support_requests.template_uses') }}</th>
                        <th class="px-4 py-2.5 text-left text-[10.5px] font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.support_requests.status') }}</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($templates as $template)
                        <tr wire:key="template-{{ $template->id }}" class="border-t border-line">
                            <td class="px-4 py-3">
                                <p class="text-sm font-medium text-ink">{{ $template->title }}</p>
                                <p class="mt-0.5 line-clamp-1 text-xs text-muted">{{ $template->body }}</p>
                            </td>
                            <td class="px-4 py-3">
                                @if ($template->shortcut)
                                    <code class="rounded bg-surface px-1.5 py-0.5 text-xs text-ink">{{ $template->shortcut }}</code>
                                @else
                                    <span class="text-xs text-muted">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-muted">
                                {{ $template->category ? \App\Enums\SupportRequestCategory::from($template->category)->label() : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right text-sm tabular-nums text-muted">{{ $template->usage_count }}</td>
                            <td class="px-4 py-3">
                                <span @class([
                                    'rounded-full px-2.5 py-1 text-xs font-semibold',
                                    'bg-ok-bg text-ok-text' => $template->is_active,
                                    'bg-zinc-100 text-zinc-500' => ! $template->is_active,
                                ])>
                                    {{ $template->is_active ? __('backoffice.support_requests.template_active') : __('backoffice.support_requests.template_paused') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <button wire:click="edit('{{ $template->id }}')" class="text-xs font-semibold text-primary hover:underline">
                                    {{ __('backoffice.support_requests.edit') }}
                                </button>
                                <button wire:click="toggle('{{ $template->id }}')" class="ml-3 text-xs font-semibold text-muted hover:underline">
                                    {{ $template->is_active ? __('backoffice.support_requests.pause') : __('backoffice.support_requests.activate') }}
                                </button>
                                <button wire:click="confirmDelete('{{ $template->id }}')" class="ml-3 text-xs font-semibold text-err-text hover:underline">
                                    {{ __('backoffice.support_requests.delete') }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center">
                                <p class="text-sm font-medium text-ink">{{ __('backoffice.support_requests.templates_none') }}</p>
                                <p class="mt-1 text-xs text-muted">{{ __('backoffice.support_requests.templates_none_hint') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($formOpen)
        <x-modal close="$set('formOpen', false)"
                 :title="$editingId ? __('backoffice.support_requests.template_edit') : __('backoffice.support_requests.template_new')">
            <div class="space-y-4">
                <label class="block">
                    <span class="text-xs font-semibold text-ink">{{ __('backoffice.support_requests.template_title') }}</span>
                    <input wire:model="title" type="text"
                           class="mt-1 w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                    @error('title') <span class="mt-1 block text-xs text-err-text">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="text-xs font-semibold text-ink">{{ __('backoffice.support_requests.template_body') }}</span>
                    <textarea wire:model="body" rows="5"
                              class="mt-1 w-full rounded border border-input px-3 py-2 text-sm focus:border-primary"></textarea>
                    @error('body') <span class="mt-1 block text-xs text-err-text">{{ $message }}</span> @enderror
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-xs font-semibold text-ink">{{ __('backoffice.support_requests.category') }}</span>
                        <select wire:model="category"
                                class="mt-1 w-full rounded border border-input bg-card px-3 py-2 text-sm text-ink focus:border-primary">
                            <option value="">{{ __('backoffice.support_requests.template_any_category') }}</option>
                            @foreach (\App\Enums\SupportRequestCategory::cases() as $case)
                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                            @endforeach
                        </select>
                        @error('category') <span class="mt-1 block text-xs text-err-text">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold text-ink">{{ __('backoffice.support_requests.template_shortcut') }}</span>
                        <input wire:model="shortcut" type="text" placeholder="/remb"
                               class="mt-1 w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                        @error('shortcut') <span class="mt-1 block text-xs text-err-text">{{ $message }}</span> @enderror
                    </label>
                </div>

                <label class="flex items-center gap-2">
                    <input wire:model="active" type="checkbox" class="rounded border-input">
                    <span class="text-sm text-ink">{{ __('backoffice.support_requests.template_is_active') }}</span>
                </label>
            </div>

            <div class="mt-5 flex justify-end gap-2">
                <button wire:click="$set('formOpen', false)"
                        class="rounded border border-line px-4 py-2 text-sm font-medium text-ink hover:border-primary">
                    {{ __('backoffice.support_requests.cancel') }}
                </button>
                <button wire:click="save"
                        class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
                    {{ __('backoffice.support_requests.save') }}
                </button>
            </div>
        </x-modal>
    @endif

    @if ($confirmingDeleteId !== null)
        <x-modal close="cancelDelete" :label="__('backoffice.support_requests.template_delete_confirm')" max-width="max-w-md">
            <p class="text-sm text-ink">{{ __('backoffice.support_requests.template_delete_confirm') }}</p>
            <div class="mt-5 flex justify-end gap-2">
                <button wire:click="cancelDelete"
                        class="rounded border border-line px-4 py-2 text-sm font-medium text-ink hover:border-primary">
                    {{ __('backoffice.support_requests.cancel') }}
                </button>
                <button wire:click="delete"
                        class="rounded bg-err-text px-4 py-2 text-sm font-semibold text-white">
                    {{ __('backoffice.support_requests.delete') }}
                </button>
            </div>
        </x-modal>
    @endif
</div>
