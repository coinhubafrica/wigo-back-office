@props([
    /** Numéro affiché dans la pastille. */
    'step',
    'title',
    /** Ligne d'aide sous le titre. */
    'hint' => null,
    /** Marque l'étape comme facultative. */
    'optional' => false,
])

{{--
    Titre d'étape d'un formulaire long. La pastille numérotée donne une
    progression là où une pile de champs n'en montrait aucune : on sait
    combien il reste avant d'envoyer.
--}}
<div class="flex items-start gap-2.5">
    <span class="mt-px flex size-6 shrink-0 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-white"
          aria-hidden="true">{{ $step }}</span>
    <span class="min-w-0">
        <span class="flex flex-wrap items-center gap-2">
            <span class="text-[13px] font-bold text-ink">{{ $title }}</span>
            @if ($optional)
                <span class="rounded bg-surface px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted">
                    {{ __('backoffice.common.optional') }}
                </span>
            @endif
        </span>
        @if ($hint)
            <span class="mt-0.5 block text-xs text-muted">{{ $hint }}</span>
        @endif
    </span>
</div>
