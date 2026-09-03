<div x-on:open-prize-form.window="$wire.newPrize()">
    <x-slot:actions>
        <x-button x-on:click="$dispatch('open-prize-form')">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            {{ __('backoffice.prizes.new') }}
        </x-button>
    </x-slot:actions>

    @include('livewire.challenges.partials.tabs', ['active' => 'prizes'])

    <x-panel class="mt-4" :title="__('backoffice.prizes.title')" :count="$prizes->count()" :subtitle="__('backoffice.prizes.subtitle')" :flush="! $formOpen">
        @if ($formOpen)
            <form id="prize-form" wire:submit="save" class="grid gap-4 sm:grid-cols-2">
                <x-field :label="__('backoffice.prizes.field_name')" name="name" id="prize-name" wire:model="name" required autofocus />
                <div>
                    <x-field :label="__('backoffice.prizes.field_photo')" name="photo" id="prize-photo" type="file" wire:model="photo" accept="image/*" />
                    <p wire:loading wire:target="photo" class="mt-1 text-xs text-muted">{{ __('backoffice.prizes.uploading') }}</p>
                </div>
                <div class="flex gap-2 sm:col-span-2">
                    <x-button type="submit" form="prize-form" target="save">
                        {{ $editingId === null ? __('backoffice.prizes.create') : __('backoffice.prizes.save') }}
                        <x-slot:loading>{{ __('backoffice.common.saving') }}</x-slot:loading>
                    </x-button>
                    <x-button variant="secondary" wire:click="closeForm">{{ __('backoffice.prizes.cancel') }}</x-button>
                </div>
            </form>
        @endif

        {{-- Grille de cartes : un lot est un objet physique, l'image doit
             porter la reconnaissance plutôt qu'une vignette de tableau. --}}
        @if ($prizes->isEmpty())
            <x-empty-state tone="primary" :title="__('backoffice.prizes.none')" :hint="__('backoffice.prizes.none_hint')" @class(['border-t border-line' => $formOpen])>
                <x-slot:action>
                    <x-button wire:click="newPrize" target="newPrize">{{ __('backoffice.prizes.new') }}</x-button>
                </x-slot:action>
            </x-empty-state>
        @else
            <div @class(['grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4', 'p-5' => ! $formOpen, 'mt-5 border-t border-line pt-5' => $formOpen])>
                @foreach ($prizes as $prize)
                    <div wire:key="prize-{{ $prize->id }}"
                         class="group flex flex-col overflow-hidden rounded border border-line bg-card shadow-sm transition-colors hover:border-input">
                        @if ($prize->photo_url)
                            <img src="{{ \Illuminate\Support\Facades\Storage::url($prize->photo_url) }}" alt="{{ $prize->name }}"
                                 class="aspect-[4/3] w-full border-b border-line object-cover">
                        @else
                            <div class="flex aspect-[4/3] w-full items-center justify-center border-b border-line bg-surface" aria-hidden="true">
                                <svg class="size-8 text-muted/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>
                                </svg>
                            </div>
                        @endif

                        <div class="flex flex-1 flex-col p-3.5">
                            <b class="text-sm leading-snug text-ink">{{ $prize->name }}</b>
                            <span @class(['mt-1 text-xs', 'font-semibold text-primary-text' => $prize->challenges_count > 0, 'text-muted' => $prize->challenges_count === 0])>
                                {{ trans_choice('backoffice.prizes.used_in', $prize->challenges_count, ['count' => $prize->challenges_count]) }}
                            </span>

                            <div class="mt-3.5 flex items-center gap-2 border-t border-line pt-3">
                                <x-button variant="secondary" size="sm" class="flex-1" wire:click="edit('{{ $prize->id }}')" target="edit">{{ __('backoffice.prizes.modify') }}</x-button>
                                <x-button variant="danger-outline" size="sm" icon wire:click="confirmDelete('{{ $prize->id }}')" target="confirmDelete"
                                          :aria-label="__('backoffice.prizes.delete').' — '.$prize->name" :title="__('backoffice.prizes.delete')">
                                    <svg class="size-[15px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m2 0v14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V6"/>
                                    </svg>
                                </x-button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-panel>

    {{-- Confirmation en dialogue et non en ligne dans la carte : un dialogue
         nommé, avec piège de focus, plutôt qu'un bouton rouge qui apparaît. --}}
    @if ($confirmingDeleteId !== null)
        <x-confirm close="cancelDelete" action="delete" variant="danger"
                   :title="__('backoffice.prizes.confirm_delete')"
                   :confirm-label="__('backoffice.prizes.delete')"
                   :loading="__('backoffice.common.deleting')" />
    @endif
</div>
