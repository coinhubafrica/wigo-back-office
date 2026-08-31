<div>
    @include('livewire.challenges.partials.tabs', ['active' => 'prizes'])

    <div class="mt-4 overflow-hidden rounded border border-line bg-card">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4">
            <div>
                <p class="text-[15px] font-bold text-ink">
                    {{ __('backoffice.prizes.title') }}
                    <span class="ml-1.5 text-sm font-normal text-muted">{{ trans_choice('backoffice.prizes.count', $prizes->count(), ['count' => $prizes->count()]) }}</span>
                </p>
                <p class="mt-0.5 text-xs text-muted">{{ __('backoffice.prizes.subtitle') }}</p>
            </div>

            <button type="button" wire:click="newPrize"
                    class="flex items-center gap-2 rounded bg-primary px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-hover">
                <span class="text-base leading-none">+</span>
                {{ __('backoffice.prizes.new') }}
            </button>
        </div>

        @if ($formOpen)
            <form wire:submit="save" class="border-b border-line bg-surface p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="prize-name" class="block text-[10.5px] font-bold uppercase tracking-[0.1em] text-muted">
                            {{ __('backoffice.prizes.field_name') }}
                        </label>
                        <input wire:model="name" id="prize-name" type="text"
                               class="mt-1.5 block w-full rounded border border-input bg-card px-3 py-2.5 text-sm focus:border-primary">
                        @error('name') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="prize-photo" class="block text-[10.5px] font-bold uppercase tracking-[0.1em] text-muted">
                            {{ __('backoffice.prizes.field_photo') }}
                        </label>
                        <input wire:model="photo" id="prize-photo" type="file" accept="image/*"
                               class="mt-1.5 block w-full text-sm text-muted file:mr-3 file:rounded file:border file:border-input file:bg-card file:px-3 file:py-2 file:text-[13px] file:font-semibold file:text-ink">
                        <div wire:loading wire:target="photo" class="mt-1 text-xs text-muted">{{ __('backoffice.prizes.uploading') }}</div>
                        @error('photo') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-5 flex gap-2">
                    <button type="submit" class="rounded bg-primary px-4 py-2.5 text-[13px] font-bold text-white hover:bg-primary-hover">
                        {{ $editingId === null ? __('backoffice.prizes.create') : __('backoffice.prizes.save') }}
                    </button>
                    <button type="button" wire:click="closeForm" class="rounded border border-line bg-card px-4 py-2.5 text-[13px] font-bold text-ink hover:bg-line">
                        {{ __('backoffice.prizes.cancel') }}
                    </button>
                </div>
            </form>
        @endif

        {{-- Grille de cartes : un lot est un objet physique, l'image doit
             porter la reconnaissance plutôt qu'une vignette de tableau. --}}
        @if ($prizes->isEmpty())
            <div class="px-5 py-14 text-center">
                <p class="text-sm font-semibold text-ink">{{ __('backoffice.prizes.none') }}</p>
                <p class="mt-1 text-xs text-muted">{{ __('backoffice.prizes.none_hint') }}</p>
            </div>
        @else
            <div class="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($prizes as $prize)
                    <div wire:key="prize-{{ $prize->id }}"
                         class="group flex flex-col overflow-hidden rounded border border-line bg-card transition-colors hover:border-input">
                        @if ($prize->photo_url)
                            <img src="{{ \Illuminate\Support\Facades\Storage::url($prize->photo_url) }}" alt="{{ $prize->name }}"
                                 class="aspect-[4/3] w-full border-b border-line object-cover">
                        @else
                            <div class="flex aspect-[4/3] w-full items-center justify-center border-b border-line bg-surface">
                                <svg class="size-8 text-muted/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>
                                </svg>
                            </div>
                        @endif

                        <div class="flex flex-1 flex-col p-3.5">
                            <b class="text-[14px] leading-snug text-ink">{{ $prize->name }}</b>
                            <span @class([
                                'mt-1 text-xs',
                                'font-semibold text-primary-text' => $prize->challenges_count > 0,
                                'text-muted' => $prize->challenges_count === 0,
                            ])>
                                {{ trans_choice('backoffice.prizes.used_in', $prize->challenges_count, ['count' => $prize->challenges_count]) }}
                            </span>

                            <div class="mt-3.5 flex items-center gap-2 border-t border-line pt-3">
                                @if ($confirmingDeleteId === $prize->id)
                                    <span class="flex-1 text-xs text-muted">{{ __('backoffice.prizes.confirm_delete') }}</span>
                                    <button wire:click="delete" class="rounded bg-err-text px-3 py-1.5 text-[11.5px] font-bold text-white">
                                        {{ __('backoffice.prizes.delete') }}
                                    </button>
                                    <button wire:click="cancelDelete" class="rounded border border-line px-2.5 py-1.5 text-[11.5px] font-semibold text-muted hover:bg-surface">
                                        {{ __('backoffice.prizes.cancel') }}
                                    </button>
                                @else
                                    <button wire:click="edit('{{ $prize->id }}')"
                                            class="flex-1 rounded border border-line px-3 py-1.5 text-[11.5px] font-semibold text-ink hover:bg-surface">
                                        {{ __('backoffice.prizes.modify') }}
                                    </button>
                                    <button wire:click="confirmDelete('{{ $prize->id }}')"
                                            aria-label="{{ __('backoffice.prizes.delete') }}"
                                            class="rounded border border-line px-2.5 py-1.5 text-[11.5px] font-semibold text-err-text hover:bg-err-bg">
                                        <svg class="size-[15px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m2 0v14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V6"/>
                                        </svg>
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

    </div>
</div>
