<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('backoffice.sign_in') }} — WiGO PRO</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-card font-sans text-ink antialiased">
    <div class="flex min-h-full">
        {{-- Panneau orange de la charte --}}
        <div class="hidden w-1/2 flex-col justify-between bg-primary p-12 lg:flex">
            <p class="flex items-center gap-3 text-xs font-semibold uppercase tracking-widest text-white/90">
                <span class="h-px w-6 bg-white/60"></span>
                {{ __('backoffice.restricted_access') }}
            </p>

            <div>
                <img src="{{ Vite::asset('resources/images/logo-wigo-pro-white.png') }}"
                     alt="WiGO PRO" class="w-52">
                <p class="mt-6 text-3xl font-semibold text-white">
                    {{ __('backoffice.the_back_office') }}
                </p>
            </div>

            <div class="h-px w-full bg-white/30"></div>
        </div>

        <div class="flex w-full items-center justify-center p-8 lg:w-1/2">
            <div class="w-full max-w-sm">
                {{ $slot }}
            </div>
        </div>
    </div>
</body>
</html>
