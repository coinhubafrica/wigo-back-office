{{-- Réponses types du support. Le bouton de création vit dans l'en-tête du
     layout et parle à la racine par évènement Alpine. --}}
<div x-on:open-template-form.window="$wire.newTemplate()">
    <x-slot:back>
        <x-back-link :href="route('bo.support-requests')">{{ __('backoffice.support_requests.back_to_queue') }}</x-back-link>
    </x-slot:back>
    <x-slot:actions>
        <x-button x-on:click="$dispatch('open-template-form')">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            {{ __('backoffice.support_requests.template_new') }}
        </x-button>
    </x-slot:actions>

    <p class="text-sm text-muted">{{ __('backoffice.support_requests.templates_hint') }}</p>

    <x-panel class="mt-4" flush>
        <x-table>
            <x-slot:head>
                <x-th>{{ __('backoffice.support_requests.template_title') }}</x-th>
                <x-th>{{ __('backoffice.support_requests.template_shortcut') }}</x-th>
                <x-th>{{ __('backoffice.support_requests.category') }}</x-th>
                <x-th align="right">{{ __('backoffice.support_requests.template_uses') }}</x-th>
                <x-th>{{ __('backoffice.support_requests.status') }}</x-th>
                <x-th><span class="sr-only">{{ __('backoffice.support_requests.edit') }}</span></x-th>
            </x-slot:head>

            @foreach ($templates as $template)
                <tr wire:key="template-{{ $template->id }}" class="transition-colors hover:bg-surface">
                    <x-td>
                        <p class="text-sm font-semibold text-ink">{{ $template->title }}</p>
                        <p class="mt-0.5 line-clamp-1 max-w-[360px] text-xs text-muted">{{ $template->body }}</p>
                    </x-td>
                    <x-td>
                        @if ($template->shortcut)
                            <code class="rounded bg-neutral-bg px-1.5 py-0.5 font-mono text-xs text-neutral-text">{{ $template->shortcut }}</code>
                        @else
                            <span class="text-xs text-muted">—</span>
                        @endif
                    </x-td>
                    <x-td muted>{{ $template->category ? \App\Enums\SupportRequestCategory::from($template->category)->label() : '—' }}</x-td>
                    <x-td align="right" muted class="tabular-nums">{{ $template->usage_count }}</x-td>
                    <x-td>
                        <x-badge :tone="$template->is_active ? 'ok' : 'neutral'">
                            {{ $template->is_active ? __('backoffice.support_requests.template_active') : __('backoffice.support_requests.template_paused') }}
                        </x-badge>
                    </x-td>
                    <x-td align="right" nowrap>
                        <div class="flex items-center justify-end gap-2">
                            <x-button variant="secondary" size="sm" wire:click="edit('{{ $template->id }}')" target="edit">{{ __('backoffice.support_requests.edit') }}</x-button>
                            <x-button variant="secondary" size="sm" wire:click="toggle('{{ $template->id }}')" target="toggle">
                                {{ $template->is_active ? __('backoffice.support_requests.pause') : __('backoffice.support_requests.activate') }}
                            </x-button>
                            <x-button variant="danger-outline" size="sm" icon wire:click="confirmDelete('{{ $template->id }}')" target="confirmDelete"
                                      :aria-label="__('backoffice.support_requests.aria_delete_template', ['title' => $template->title])"
                                      :title="__('backoffice.support_requests.delete')">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16M9 7V4.8h6V7M6.5 7l.9 12.2A1.5 1.5 0 0 0 8.9 20.6h6.2a1.5 1.5 0 0 0 1.5-1.4L17.5 7"/></svg>
                            </x-button>
                        </div>
                    </x-td>
                </tr>
            @endforeach

            @if ($templates->isEmpty())
                <x-slot:empty>
                    <x-empty-state tone="primary" :title="__('backoffice.support_requests.templates_none')" :hint="__('backoffice.support_requests.templates_none_hint')">
                        <x-slot:action>
                            <x-button wire:click="newTemplate" target="newTemplate">{{ __('backoffice.support_requests.template_new') }}</x-button>
                        </x-slot:action>
                    </x-empty-state>
                </x-slot:empty>
            @endif
        </x-table>
    </x-panel>

    @if ($formOpen)
        {{-- `close` est un nom de méthode : `$set('formOpen', false)` en ligne
             donnait `$wire.$set(…)()` et Échap ne fermait plus. --}}
        <x-modal close="closeForm" align="start"
                 :title="$editingId ? __('backoffice.support_requests.template_edit') : __('backoffice.support_requests.template_new')">
            <form id="template-form" wire:submit="save" class="space-y-4">
                <x-field :label="__('backoffice.support_requests.template_title')" name="title" wire:model="title" required />
                <x-field :label="__('backoffice.support_requests.template_body')" name="body" type="textarea" rows="5" wire:model="body" required />

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field :label="__('backoffice.support_requests.category')" name="category" type="select" wire:model="category">
                        <option value="">{{ __('backoffice.support_requests.template_any_category') }}</option>
                        @foreach (\App\Enums\SupportRequestCategory::cases() as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </x-field>
                    <x-field :label="__('backoffice.support_requests.template_shortcut')" name="shortcut" wire:model="shortcut" placeholder="/remb" />
                </div>

                <label for="template-active" class="flex items-center gap-2.5 text-sm text-ink">
                    <input wire:model="active" id="template-active" type="checkbox" class="size-4 rounded border-input text-primary">
                    {{ __('backoffice.support_requests.template_is_active') }}
                </label>
            </form>

            <x-slot:footer>
                <x-button variant="secondary" wire:click="closeForm">{{ __('backoffice.support_requests.cancel') }}</x-button>
                <x-button type="submit" form="template-form" target="save">
                    {{ __('backoffice.support_requests.save') }}
                    <x-slot:loading>{{ __('backoffice.common.saving') }}</x-slot:loading>
                </x-button>
            </x-slot:footer>
        </x-modal>
    @endif

    @if ($confirmingDeleteId !== null)
        <x-confirm close="cancelDelete" action="delete" variant="danger"
                   :title="__('backoffice.support_requests.template_delete_confirm')"
                   :confirm-label="__('backoffice.support_requests.delete')"
                   :loading="__('backoffice.common.deleting')" />
    @endif
</div>
