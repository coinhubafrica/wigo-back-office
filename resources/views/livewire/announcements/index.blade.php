{{-- Le bouton de création vit dans l'en-tête du layout, hors de la racine
     Livewire : il émet un évènement Alpine que la racine relaie à `$wire`. --}}
<div x-on:open-announcement-form.window="$wire.newAnnouncement()">
    <x-slot:actions>
        <x-button x-on:click="$dispatch('open-announcement-form')">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            {{ __('backoffice.announcements.new') }}
        </x-button>
    </x-slot:actions>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-kpi-card :label="__('backoffice.announcements.total')" :value="$total" tone="primary">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('backoffice.announcements.active')" :value="$activeCount" tone="ok">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('backoffice.announcements.paused')" :value="$pausedCount">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('backoffice.announcements.videos')" :value="$videoCount" tone="warn">
            <x-slot:icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 8-6 4 6 4V8Z"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </div>

    <p class="mt-5 text-sm text-muted">{{ trans_choice('backoffice.announcements.count', $total, ['count' => $total]) }}</p>

    @if ($announcements->isEmpty())
        <x-panel class="mt-4">
            <x-empty-state tone="primary" :title="__('backoffice.announcements.none')" :hint="__('backoffice.announcements.none_hint')">
                <x-slot:action>
                    <x-button wire:click="newAnnouncement" target="newAnnouncement">{{ __('backoffice.announcements.new') }}</x-button>
                </x-slot:action>
            </x-empty-state>
        </x-panel>
    @else
        <div wire:sort="reorder" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($announcements as $announcement)
                <div wire:key="announcement-{{ $announcement->id }}" wire:sort:item="{{ $announcement->id }}"
                     {{-- Une annonce en pause s'atténue par son fond et non par
                          `opacity`, qui faisait passer titre et libellés sous le
                          seuil AA. La pastille « en pause » porte l'état. --}}
                     @class(['rounded border border-line shadow-sm', 'bg-card' => $announcement->is_active, 'bg-surface' => ! $announcement->is_active])>
                    <div class="flex items-center gap-2 border-b border-line px-4 py-3">
                        <span wire:sort:handle title="{{ __('backoffice.announcements.drag_to_reorder') }}" class="flex cursor-grab items-center text-muted active:cursor-grabbing">
                            <svg class="size-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="8" cy="6" r="1.4"/><circle cx="16" cy="6" r="1.4"/><circle cx="8" cy="12" r="1.4"/><circle cx="16" cy="12" r="1.4"/><circle cx="8" cy="18" r="1.4"/><circle cx="16" cy="18" r="1.4"/></svg>
                        </span>
                        <span class="text-[11px] font-semibold uppercase tracking-wide text-muted">
                            {{ $announcement->media_type === \App\Enums\AnnouncementMediaType::Video ? __('backoffice.announcements.video_badge') : __('backoffice.announcements.image_badge') }}
                        </span>
                        <x-badge :tone="$announcement->is_active ? 'ok' : 'neutral'" class="ml-auto">
                            {{ $announcement->is_active ? __('backoffice.announcements.status_active') : __('backoffice.announcements.status_paused') }}
                        </x-badge>
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
                            @if ($announcement->is_active)
                                <x-button variant="secondary" size="sm" wire:click="toggle('{{ $announcement->id }}')" target="toggle">{{ __('backoffice.announcements.pause') }}</x-button>
                            @else
                                <x-button size="sm" wire:click="toggle('{{ $announcement->id }}')" target="toggle">{{ __('backoffice.announcements.publish') }}</x-button>
                            @endif
                            <x-button variant="secondary" size="sm" wire:click="edit('{{ $announcement->id }}')" target="edit">{{ __('backoffice.announcements.modify') }}</x-button>
                            <x-button variant="secondary" size="sm" wire:click="duplicate('{{ $announcement->id }}')" target="duplicate">{{ __('backoffice.announcements.duplicate') }}</x-button>
                            <x-button variant="danger-outline" size="sm" icon class="ml-auto"
                                      wire:click="confirmDelete('{{ $announcement->id }}')" target="confirmDelete"
                                      :aria-label="__('backoffice.announcements.aria_delete', ['title' => $announcement->title])"
                                      :title="__('backoffice.announcements.delete')">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16M9 7V4.8h6V7M6.5 7l.9 12.2A1.5 1.5 0 0 0 8.9 20.6h6.2a1.5 1.5 0 0 0 1.5-1.4L17.5 7"/></svg>
                            </x-button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($formOpen)
        <x-modal close="closeForm" align="start"
                 :title="$editingId === null ? __('backoffice.announcements.new') : __('backoffice.announcements.modify')">
            <form id="announcement-form" wire:submit="save" class="space-y-4">
                <x-field :label="__('backoffice.announcements.field_title')" name="title" wire:model="title" required />

                <x-field :label="__('backoffice.announcements.field_media_type')" name="mediaType" type="select" wire:model="mediaType">
                    <option value="image">{{ __('backoffice.announcements.image_badge') }}</option>
                    <option value="video">{{ __('backoffice.announcements.video_badge') }}</option>
                </x-field>

                <div>
                    <x-field :label="__('backoffice.announcements.field_media')" name="media" type="file" wire:model="media" accept="image/*,video/*" />
                    <p wire:loading wire:target="media" class="mt-1.5 text-xs text-muted">{{ __('backoffice.announcements.uploading') }}</p>
                </div>

                <label for="announcement-active" class="flex items-center gap-2.5 text-sm text-ink">
                    <input wire:model="active" id="announcement-active" type="checkbox" class="size-4 rounded border-input text-primary">
                    {{ __('backoffice.announcements.publish_immediately') }}
                </label>
            </form>

            <x-slot:footer>
                <x-button variant="secondary" wire:click="closeForm">{{ __('backoffice.announcements.cancel') }}</x-button>
                <x-button type="submit" form="announcement-form" target="save">
                    {{ $editingId === null ? __('backoffice.announcements.create') : __('backoffice.announcements.save') }}
                    <x-slot:loading>{{ __('backoffice.common.saving') }}</x-slot:loading>
                </x-button>
            </x-slot:footer>
        </x-modal>
    @endif

    @if ($confirmingDeleteId !== null)
        <x-confirm close="cancelDelete" action="delete" variant="danger"
                   :title="__('backoffice.announcements.confirm_delete_title')"
                   :body="__('backoffice.announcements.confirm_delete_body')"
                   :confirm-label="__('backoffice.announcements.delete')"
                   :loading="__('backoffice.common.deleting')" />
    @endif
</div>
