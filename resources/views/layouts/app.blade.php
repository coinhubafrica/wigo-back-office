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

{{--
    Coquille de l'application.

    Une page Livewire y arrive par `#[Layout('layouts.app', ['module' => …])]`
    et peut lui confier deux fragments statiques via des slots nommés déclarés
    à la racine de sa vue : `back` (lien de retour d'une fiche) et `actions`
    (boutons d'en-tête). Ces slots sont rendus hors de la racine Livewire :
    des liens `wire:navigate` ou des `$dispatch()` Alpine, jamais `wire:*`.

    Sous `lg`, la barre latérale s'escamote et s'ouvre depuis l'en-tête ;
    `appShell` (resources/js/app.js) la referme à la navigation et sur Échap.
--}}
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
    <a href="#main"
       class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-ink focus:px-3 focus:py-2 focus:text-sm focus:font-semibold focus:text-white">
        {{ __('backoffice.common.skip_to_content') }}
    </a>

    <div x-data="appShell" x-on:keydown.escape.window="close()" class="flex min-h-full">
        {{-- Voile sous `lg` quand la barre est ouverte --}}
        <div x-show="sidebarOpen" x-transition.opacity.duration.150ms x-on:click="close()" x-cloak
             class="fixed inset-0 z-20 bg-ink/45 lg:hidden" aria-hidden="true"></div>

        {{-- Barre latérale 260 px : fixe à partir de `lg`, escamotable en dessous --}}
        <aside id="sidebar"
               :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
               class="fixed inset-y-0 left-0 z-30 flex w-[260px] -translate-x-full flex-col bg-sidebar shadow-xl transition-transform duration-200 ease-out lg:translate-x-0 lg:shadow-none">
            <div class="flex shrink-0 items-start justify-between gap-3 border-b border-sidebar-line px-5 py-4">
                <div>
                    <a href="{{ route(\App\Enums\BackOfficeModule::Dashboard->route()) }}" wire:navigate class="inline-block rounded">
                        <img src="{{ Vite::asset('resources/images/logo-wigo-pro-white.png') }}"
                             alt="WiGO PRO" class="w-[130px]">
                    </a>
                    <p class="mt-2 text-[11px] font-semibold uppercase tracking-widest text-sidebar-muted">
                        {{ __('backoffice.back_office') }}
                    </p>
                </div>
                <button type="button" x-on:click="close()" aria-label="{{ __('backoffice.common.close') }}"
                        class="flex size-8 shrink-0 items-center justify-center rounded text-sidebar-muted transition-colors hover:bg-sidebar-line hover:text-white lg:hidden">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="{{ __('backoffice.common.main_nav') }}">
                @foreach ($grouped as $group => $groupModules)
                    @php $groupId = 'nav-group-'.\Illuminate\Support\Str::slug($group ?: 'general'); @endphp
                    @if ($group !== '')
                        <p id="{{ $groupId }}" class="mb-1.5 mt-5 px-2 text-[11px] font-semibold uppercase tracking-wider text-sidebar-muted">
                            {{ $group }}
                        </p>
                    @endif

                    <ul @if ($group !== '') aria-labelledby="{{ $groupId }}" @endif class="space-y-0.5">
                        @foreach ($groupModules as $item)
                            @php
                                $isActive = $module === $item;
                                $isBuilt = \Illuminate\Support\Facades\Route::has($item->route());
                            @endphp
                            <li>
                                {{-- Un module non livré est rendu en `<span>` et non en
                                     ancre morte : `aria-disabled` sur un `<a href="#">`
                                     restait focalisable et offrait un arrêt sans issue au
                                     clavier. La pastille « bientôt » porte le sens. --}}
                                <{{ $isBuilt ? 'a' : 'span' }}
                                   @if ($isBuilt) href="{{ route($item->route()) }}" wire:navigate @endif
                                   @class([
                                       'flex items-center gap-2.5 rounded px-2.5 py-2 text-sm transition-colors',
                                       'bg-primary font-medium text-white' => $isActive,
                                       'text-zinc-300 hover:bg-sidebar-line hover:text-white' => ! $isActive && $isBuilt,
                                       'cursor-default text-sidebar-muted' => ! $isBuilt,
                                   ])
                                   @if ($isActive) aria-current="page" @endif>
                                    <svg class="size-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="{{ $item->icon() }}"/>
                                    </svg>
                                    {{ $item->label() }}
                                    @unless ($isBuilt)
                                        <span class="ml-auto rounded-full border border-sidebar-line px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sidebar-muted">{{ __('backoffice.soon') }}</span>
                                    @endunless
                                </{{ $isBuilt ? 'a' : 'span' }}>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            </nav>

            {{-- Bloc utilisateur --}}
            <div class="flex shrink-0 items-center gap-3 border-t border-sidebar-line px-4 py-3">
                <x-avatar :initials="$user->initials()" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-white">{{ $user->fullName() }}</p>
                    <p class="truncate text-xs text-sidebar-muted">{{ $user->email }}</p>
                </div>
                <form method="POST" action="{{ route('bo.logout') }}">
                    @csrf
                    <button type="submit" aria-label="{{ __('backoffice.sign_out') }}" title="{{ __('backoffice.sign_out') }}"
                            class="flex size-8 shrink-0 items-center justify-center rounded text-sidebar-muted transition-colors hover:bg-sidebar-line hover:text-white">
                        <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75"/>
                        </svg>
                    </button>
                </form>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col lg:pl-[260px]">
            {{-- Barre supérieure : titre du module, retour, actions, rôle --}}
            <header class="sticky top-0 z-10 border-b border-line bg-card/95 backdrop-blur supports-[backdrop-filter]:bg-card/85">
                <div class="mx-auto flex w-full max-w-[1440px] items-start gap-4 px-4 py-3.5 sm:px-6 lg:px-8 lg:py-4">
                    <button type="button" x-on:click="toggle()" :aria-expanded="sidebarOpen.toString()" aria-controls="sidebar"
                            aria-label="{{ __('backoffice.common.menu') }}"
                            class="flex size-9 shrink-0 items-center justify-center rounded border border-line bg-card text-ink transition-colors hover:bg-surface lg:hidden">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>

                    <div class="min-w-0 flex-1">
                        {{ $back ?? '' }}
                        @if ($module)
                            <p class="text-xs font-semibold uppercase tracking-widest text-primary">{{ $module->eyebrow() }}</p>
                        @endif
                        <h1 class="truncate text-lg font-semibold text-ink">{{ $module?->title() }}</h1>
                        @if ($module)
                            <p class="mt-0.5 truncate text-sm text-muted">{{ $module->subtitle() }}</p>
                        @endif
                    </div>

                    {{-- `x-data` : les actions viennent d'une vue Livewire mais sont rendues
                         hors de sa racine ; Alpine a besoin d'une portée pour `$dispatch`. --}}
                    <div x-data class="flex shrink-0 flex-wrap items-center justify-end gap-2.5 self-center">
                        {{ $actions ?? '' }}
                        @if ($user->roleLabel())
                            <x-badge tone="primary" class="hidden px-3 py-1 text-xs sm:inline-flex">{{ $user->roleLabel() }}</x-badge>
                        @endif
                    </div>
                </div>
            </header>

            <main id="main" class="flex-1 px-4 py-5 sm:px-6 lg:px-8 lg:py-6">
                <div class="mx-auto w-full max-w-[1440px]">
                    {{ $slot }}
                </div>
            </main>
        </div>
    </div>

    {{-- Notifications : pilotées par Livewire via $dispatch('toast', message: …)
         ou $dispatch('toast', { message, tone }) ; un flash de session au
         chargement passe par le même canal. --}}
    <div x-data="toasts"
         x-on:toast.window="push($event.detail)"
         @if (session('status')) x-init="push({ message: @js(session('status')), tone: 'success' })" @elseif (session('error')) x-init="push({ message: @js(session('error')), tone: 'error' })" @endif
         role="status" aria-live="polite" aria-atomic="false"
         class="pointer-events-none fixed inset-x-0 bottom-6 z-50 flex flex-col items-center gap-2 px-4">
        <template x-for="message in messages" :key="message.id">
            <div x-transition
                 :role="message.tone === 'error' ? 'alert' : null"
                 :class="{
                     'border-ok-text': message.tone === 'success',
                     'border-err-text': message.tone === 'error',
                     'border-primary': message.tone === 'info',
                 }"
                 class="pointer-events-auto flex w-full max-w-md items-center gap-3 rounded border-l-[3px] bg-ink py-2.5 pl-4 pr-2 text-sm font-medium text-white shadow-lg">
                <svg x-show="message.tone === 'success'" class="size-4 shrink-0 text-ok-bg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                <svg x-show="message.tone === 'error'" class="size-4 shrink-0 text-err-bg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
                <svg x-show="message.tone === 'info'" class="size-4 shrink-0 text-primary-tint" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                <span x-text="message.text" class="min-w-0 flex-1"></span>
                <button type="button" x-on:click="dismiss(message.id)" aria-label="{{ __('backoffice.common.dismiss') }}"
                        class="flex size-7 shrink-0 items-center justify-center rounded text-white/70 transition-colors hover:bg-white/10 hover:text-white">
                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
        </template>
    </div>
</body>
</html>
