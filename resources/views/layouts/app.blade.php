@props(['module' => null])

@php
    /** @var \App\Models\User $user */
    $user = auth()->user();
    // Le filtre par permission et par route livrée est désactivé temporairement
    // pour visualiser l'intégralité de la navigation pendant la construction du BO.
    // Les modules non livrés apparaissent en lien inactif (pas de route à générer).
    $grouped = collect(\App\Enums\BackOfficeModule::cases())
        ->groupBy(fn (\App\Enums\BackOfficeModule $m) => $m->group() ?? '');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $module?->title() ?? config('app.name') }} — WiGO PRO</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-surface font-sans text-ink antialiased">
    <div class="flex min-h-full">
        {{-- Barre latérale fixe 260 px --}}
        <aside class="fixed inset-y-0 left-0 z-20 flex w-[260px] flex-col bg-sidebar">
            <div class="shrink-0 border-b border-sidebar-line px-5 py-4">
                <a href="{{ route(\App\Enums\BackOfficeModule::Dashboard->route()) }}" wire:navigate>
                    <img src="{{ Vite::asset('resources/images/logo-wigo-pro-white.png') }}"
                         alt="WiGO PRO" class="w-[130px]">
                </a>
                <p class="mt-2 text-[11px] font-semibold uppercase tracking-widest text-zinc-400">
                    {{ __('backoffice.back_office') }}
                </p>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 py-4">
                @foreach ($grouped as $group => $groupModules)
                    @if ($group !== '')
                        <p class="mt-5 mb-1.5 px-2 text-[11px] font-semibold uppercase tracking-wider text-zinc-400">
                            {{ $group }}
                        </p>
                    @endif

                    @foreach ($groupModules as $item)
                        @php
                            $isActive = $module === $item;
                            $isBuilt = \Illuminate\Support\Facades\Route::has($item->route());
                        @endphp
                        {{-- Un module non livré est rendu en `<span>` et non en
                             ancre morte : `aria-disabled` sur un `<a href="#">`
                             restait focalisable et offrait un arrêt sans issue au
                             clavier. La pastille « bientôt » porte le sens. --}}
                        <{{ $isBuilt ? 'a' : 'span' }}
                           @if ($isBuilt) href="{{ route($item->route()) }}" wire:navigate @endif
                           @class([
                               'mb-0.5 flex items-center gap-2.5 rounded px-2.5 py-2 text-sm transition-colors',
                               'bg-primary font-medium text-white' => $isActive,
                               'text-zinc-300 hover:bg-sidebar-line hover:text-white' => ! $isActive && $isBuilt,
                               'cursor-default text-zinc-400' => ! $isBuilt,
                           ])
                           @if ($isActive) aria-current="page" @endif>
                            <svg class="size-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="{{ $item->icon() }}"/>
                            </svg>
                            {{ $item->label() }}
                            @unless ($isBuilt)
                                <span class="ml-auto text-[10px] font-semibold uppercase tracking-wide text-zinc-400">{{ __('backoffice.soon') }}</span>
                            @endunless
                        </{{ $isBuilt ? 'a' : 'span' }}>
                    @endforeach
                @endforeach
            </nav>

            {{-- Bloc utilisateur --}}
            <div class="flex shrink-0 items-center gap-3 border-t border-sidebar-line px-4 py-3">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary-tint text-sm font-semibold text-primary-text">
                    {{ $user->initials() }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-white">{{ $user->fullName() }}</p>
                    <p class="truncate text-xs text-zinc-400">{{ $user->email }}</p>
                </div>
                <form method="POST" action="{{ route('bo.logout') }}">
                    @csrf
                    <button type="submit" title="{{ __('backoffice.sign_out') }}"
                            class="flex size-8 shrink-0 items-center justify-center rounded text-zinc-400 transition-colors hover:bg-sidebar-line hover:text-white">
                        <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75"/>
                        </svg>
                    </button>
                </form>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col pl-[260px]">
            {{-- Barre supérieure : titre du module + rôle --}}
            <header class="sticky top-0 z-10 flex items-start justify-between gap-6 border-b border-line bg-card px-8 py-4">
                <div class="min-w-0">
                    @if ($module)
                        <p class="text-xs font-semibold uppercase tracking-widest text-primary">{{ $module->eyebrow() }}</p>
                    @endif
                    <h1 class="truncate text-lg font-semibold text-ink">{{ $module?->title() }}</h1>
                    @if ($module)
                        <p class="mt-0.5 truncate text-sm text-muted">{{ $module->subtitle() }}</p>
                    @endif
                </div>

                @if ($user->roleLabel())
                    <span class="shrink-0 rounded-full bg-primary-tint px-3 py-1 text-xs font-semibold text-primary-text">
                        {{ $user->roleLabel() }}
                    </span>
                @endif
            </header>

            <main class="flex-1 px-8 py-6">
                {{ $slot }}
            </main>
        </div>
    </div>

    {{-- Toasts : pilotés par Livewire via $dispatch('toast', ...) --}}
    <div x-data="{ messages: [] }"
         @toast.window="
            const id = Date.now();
            messages.push({ id, text: $event.detail.message ?? $event.detail });
            setTimeout(() => messages = messages.filter(m => m.id !== id), 4000);
         "
         role="status" aria-live="polite" aria-atomic="false"
         class="pointer-events-none fixed inset-x-0 bottom-6 z-50 flex flex-col items-center gap-2">
        <template x-for="message in messages" :key="message.id">
            <div x-transition
                 class="pointer-events-auto rounded bg-ink px-4 py-2.5 text-sm font-medium text-white shadow-lg">
                <span x-text="message.text"></span>
            </div>
        </template>
    </div>
</body>
</html>
