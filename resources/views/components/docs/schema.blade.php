@props(['schema', 'spec', 'name' => null, 'required' => false, 'depth' => 0])

@php
    /**
     * Résout une référence interne vers le composant qu'elle désigne, en
     * conservant les clés voisines (une `description` posée à côté d'un `$ref`).
     */
    $resolve = function (array $node) use ($spec): array {
        if (! isset($node['$ref']) || ! is_string($node['$ref'])) {
            return $node;
        }

        $target = $spec;

        foreach (explode('/', ltrim($node['$ref'], '#/')) as $segment) {
            $target = $target[$segment] ?? [];
        }

        $siblings = $node;
        unset($siblings['$ref']);

        return array_merge(is_array($target) ? $target : [], $siblings);
    };

    $reference = $schema['$ref'] ?? null;
    $resolved = $resolve($schema);

    $type = $resolved['type'] ?? null;
    $type = is_array($type) ? implode(' | ', $type) : $type;

    if ($type === null && isset($resolved['properties'])) {
        $type = 'object';
    }

    $title = $resolved['title'] ?? (is_string($reference) ? class_basename($reference) : null);
    $requiredKeys = $resolved['required'] ?? [];
@endphp

{{--
    Chaque niveau de nesting porte son propre rail vertical et son propre
    retrait (`pl-4`) : à la différence d'un simple `border-l` unique, la
    barre de chaque profondeur reste visible même quand un parent n'en a pas
    dessiné une lui-même (`data`, `data.limits`, `data.limits.remaining_today`
    doivent rester visuellement distinguables au coup d'œil).
--}}
<div @class(['border-l border-line/70 pl-4' => $depth > 0, 'py-0.5' => $depth > 0])>
    <p class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
        @if ($name !== null)
            <code class="font-mono text-[13px] font-semibold">{{ $name }}</code>
        @endif

        @if ($type !== null)
            <span class="rounded bg-primary-tint px-1.5 py-0.5 text-[11px] font-medium text-primary-text">{{ $type }}</span>
        @endif

        @if (isset($resolved['format']))
            <span class="text-[12px] text-muted">{{ $resolved['format'] }}</span>
        @endif

        @if ($required)
            <span class="rounded bg-err-bg px-1.5 py-0.5 text-[11px] font-semibold text-err-text">requis</span>
        @endif

        @if ($title !== null && $depth > 0)
            <span class="text-[12px] text-muted">{{ $title }}</span>
        @endif
    </p>

    @if (isset($resolved['description']))
        <p class="mt-0.5 text-[13px] text-muted">{{ $resolved['description'] }}</p>
    @endif

    @if (isset($resolved['enum']))
        <p class="mt-1 flex flex-wrap gap-1">
            @foreach ($resolved['enum'] as $value)
                <code class="rounded bg-neutral-bg px-1.5 py-0.5 font-mono text-[12px] text-neutral-text">{{ $value }}</code>
            @endforeach
        </p>
    @endif

    @if (isset($resolved['example']))
        <p class="mt-1 text-[12px] text-muted">
            Exemple : <code class="font-mono">{{ is_scalar($resolved['example']) ? $resolved['example'] : json_encode($resolved['example']) }}</code>
        </p>
    @endif

    {{-- Garde de profondeur : un schéma qui se référence lui-même ne doit pas
         faire boucler le rendu. --}}
    @if ($depth < 6)
        @if (isset($resolved['properties']))
            <div class="mt-1.5 space-y-1.5">
                @foreach ($resolved['properties'] as $key => $property)
                    <x-docs.schema :schema="$property" :spec="$spec" :name="$key"
                                   :required="in_array($key, $requiredKeys, true)"
                                   :depth="$depth + 1" />
                @endforeach
            </div>
        @endif

        @if (isset($resolved['items']) && $resolved['items'] !== [])
            <div class="mt-1.5">
                <x-docs.schema :schema="$resolved['items']" :spec="$spec"
                               name="[]" :depth="$depth + 1" />
            </div>
        @endif
    @endif
</div>
