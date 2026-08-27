<div>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded border border-line bg-card p-5">
            <p class="text-sm text-muted">Chauffeurs actifs</p>
            <p class="mt-1 text-2xl font-semibold text-ink">{{ number_format($activeDrivers, 0, ',', ' ') }}</p>
        </div>
        <div class="rounded border border-line bg-card p-5">
            <p class="text-sm text-muted">Chauffeurs suspendus</p>
            <p class="mt-1 text-2xl font-semibold text-ink">{{ number_format($suspendedDrivers, 0, ',', ' ') }}</p>
        </div>
    </div>

    <p class="mt-6 text-sm text-muted">
        Les indicateurs d'activité, les escalades SLA et la performance du support
        seront alimentés par les modules correspondants.
    </p>
</div>
