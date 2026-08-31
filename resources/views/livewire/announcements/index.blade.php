<div>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.announcements.total') }}</p>
            <p class="mt-1.5 text-2xl font-semibold text-ink">{{ $total }}</p>
        </div>
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.announcements.active') }}</p>
            <p class="mt-1.5 text-2xl font-semibold text-ink">{{ $activeCount }}</p>
        </div>
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.announcements.paused') }}</p>
            <p class="mt-1.5 text-2xl font-semibold text-ink">{{ $pausedCount }}</p>
        </div>
        <div class="rounded border border-line bg-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('backoffice.announcements.videos') }}</p>
            <p class="mt-1.5 text-2xl font-semibold text-ink">{{ $videoCount }}</p>
        </div>
    </div>

    <div class="mt-5 flex items-center gap-3">
        <p class="text-sm text-muted">{{ trans_choice('backoffice.announcements.count', $total, ['count' => $total]) }}</p>
        <span class="flex-1"></span>
        <button wire:click="newAnnouncement" class="flex items-center gap-1.5 rounded bg-primary px-3.5 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
            {{ __('backoffice.announcements.new') }}
        </button>
    </div>

    @if ($announcements->isEmpty())
        <div class="mt-4 rounded border border-dashed border-input bg-card p-10 text-center">
            <p class="text-sm font-semibold text-ink">{{ __('backoffice.announcements.none') }}</p>
            <p class="mt-1 text-xs text-muted">{{ __('backoffice.announcements.none_hint') }}</p>
        </div>
    @else
        <div wire:sort="reorder" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($announcements as $announcement)
                <div wire:key="announcement-{{ $announcement->id }}" wire:sort:item="{{ $announcement->id }}"
                     {{-- Une annonce en pause s'atténue par son fond et non par
                          `opacity`, qui faisait passer titre et libellés sous le
                          seuil AA. La pastille « en pause » porte l'état. --}}
                     class="rounded border border-line {{ $announcement->is_active ? 'bg-card' : 'bg-surface' }}">
                    <div class="flex items-center gap-2 border-b border-line px-4 py-3">
                        <span wire:sort:handle title="{{ __('backoffice.announcements.drag_to_reorder') }}" class="flex cursor-grab items-center text-muted active:cursor-grabbing">
                            <svg class="size-4" viewBox="0 0 24 24" fill="currentColor"><circle cx="8" cy="6" r="1.4"/><circle cx="16" cy="6" r="1.4"/><circle cx="8" cy="12" r="1.4"/><circle cx="16" cy="12" r="1.4"/><circle cx="8" cy="18" r="1.4"/><circle cx="16" cy="18" r="1.4"/></svg>
                        </span>
                        <span class="text-xs font-semibold uppercase tracking-wide text-muted">
                            {{ $announcement->media_type === \App\Enums\AnnouncementMediaType::Video ? __('backoffice.announcements.video_badge') : __('backoffice.announcements.image_badge') }}
                        </span>
                        <span class="flex-1"></span>
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $announcement->is_active ? 'bg-ok-bg text-ok-text' : 'bg-neutral-bg text-neutral-text' }}">
                            {{ $announcement->is_active ? __('backoffice.announcements.status_active') : __('backoffice.announcements.status_paused') }}
                        </span>
                    </div>
                    <div class="p-4">
                        <div class="mb-3 overflow-hidden rounded border border-line bg-surface">
                            @if ($announcement->media_type === \App\Enums\AnnouncementMediaType::Video)
                                {{-- `preload="none"` : la grille chargeait chaque vidéo
                                     au rendu, pour des vignettes rarement lues. --}}
                                <video src="{{ \Illuminate\Support\Facades\Storage::url($announcement->media_url) }}"
                                       class="h-40 w-full object-cover" controls preload="none"></video>
                            @else
                                <img src="{{ \Illuminate\Support\Facades\Storage::url($announcement->media_url) }}" alt="{{ $announcement->title }}" class="h-40 w-full object-cover">
                            @endif
                        </div>
                        <p class="mb-3 text-sm font-semibold text-ink">{{ $announcement->title }}</p>
                        <div wire:sort:ignore class="flex flex-wrap items-center gap-2">
                            <button wire:click="toggle('{{ $announcement->id }}')"
                                    class="rounded px-3 py-1.5 text-xs font-semibold text-white {{ $announcement->is_active ? 'bg-zinc-500 hover:bg-zinc-600' : 'bg-ok-text hover:opacity-90' }}">
                                {{ $announcement->is_active ? __('backoffice.announcements.pause') : __('backoffice.announcements.publish') }}
                            </button>
                            <button wire:click="edit('{{ $announcement->id }}')" class="rounded border border-line bg-surface px-3 py-1.5 text-xs font-semibold text-ink hover:bg-line">
                                {{ __('backoffice.announcements.modify') }}
                            </button>
                            <button wire:click="duplicate('{{ $announcement->id }}')" class="rounded border border-line px-3 py-1.5 text-xs font-semibold text-muted hover:bg-surface">
                                {{ __('backoffice.announcements.duplicate') }}
                            </button>
                            <span class="flex-1"></span>
                            <button wire:click="confirmDelete('{{ $announcement->id }}')" title="{{ __('backoffice.announcements.delete') }}"
                                    class="flex items-center justify-center rounded border border-line p-2 text-err-text hover:border-err-text hover:bg-err-bg">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16M9 7V4.8h6V7M6.5 7l.9 12.2A1.5 1.5 0 0 0 8.9 20.6h6.2a1.5 1.5 0 0 0 1.5-1.4L17.5 7"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($formOpen)
        <x-modal close="closeForm" align="start"
                 :title="$editingId === null ? __('backoffice.announcements.new') : __('backoffice.announcements.modify')">
                <form wire:submit="save" class="space-y-4 px-5 py-4">
                    <div>
                        <label for="title" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.announcements.field_title') }}</label>
                        <input wire:model="title" id="title" type="text"
                               class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                        @error('title') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="mediaType" class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.announcements.field_media_type') }}</label>
                        <select wire:model="mediaType" id="mediaType" class="block w-full rounded border border-input px-3 py-2 text-sm focus:border-primary">
                            <option value="image">{{ __('backoffice.announcements.image_badge') }}</option>
                            <option value="video">{{ __('backoffice.announcements.video_badge') }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-muted">{{ __('backoffice.announcements.field_media') }}</label>
                        <input wire:model="media" type="file" accept="image/*,video/*"
                               class="block w-full rounded border border-input px-3 py-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-surface file:px-3 file:py-1.5 file:text-sm">
                        <div wire:loading wire:target="media" class="mt-1.5 text-xs text-muted">{{ __('backoffice.announcements.uploading') }}</div>
                        @error('media') <p class="mt-1 text-sm text-err-text">{{ $message }}</p> @enderror
                    </div>

                    <label class="flex items-center gap-2.5 text-sm text-ink">
                        <input wire:model="active" type="checkbox" class="size-4 rounded border-input text-primary focus:ring-primary">
                        {{ __('backoffice.announcements.publish_immediately') }}
                    </label>
                </form>

                <div class="flex justify-end gap-2.5 border-t border-line px-5 py-4">
                    <button wire:click="closeForm" class="rounded border border-line bg-card px-3.5 py-2 text-sm font-semibold text-muted hover:bg-surface">
                        {{ __('backoffice.announcements.cancel') }}
                    </button>
                    <button wire:click="save" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
                        {{ $editingId === null ? __('backoffice.announcements.create') : __('backoffice.announcements.save') }}
                    </button>
                </div>
        </x-modal>
    @endif

    @if ($confirmingDeleteId !== null)
        <x-modal close="cancelDelete" max-width="max-w-sm"
                 :label="__('backoffice.announcements.confirm_delete_title')">
                <div class="px-5 pb-4 pt-5">
                    <p class="text-sm font-semibold text-ink">{{ __('backoffice.announcements.confirm_delete_title') }}</p>
                    <p class="mt-1.5 text-sm text-muted">{{ __('backoffice.announcements.confirm_delete_body') }}</p>
                </div>
                <div class="flex justify-end gap-2.5 border-t border-line px-5 py-4">
                    <button wire:click="cancelDelete" class="rounded border border-line bg-card px-3.5 py-2 text-sm font-semibold text-muted hover:bg-surface">
                        {{ __('backoffice.announcements.cancel') }}
                    </button>
                    <button wire:click="delete" class="rounded bg-err-text px-4 py-2 text-sm font-semibold text-white hover:opacity-90">
                        {{ __('backoffice.announcements.delete') }}
                    </button>
                </div>
        </x-modal>
    @endif
</div>
