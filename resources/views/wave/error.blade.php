{{--
    Page de retour de Wave après un paiement abandonné ou refusé.

    Rien n'a été prélevé à ce stade. Si un prélèvement a malgré tout eu lieu, le
    webhook fera foi et la recharge sera créditée : c'est pourquoi la page
    invite à vérifier l'historique plutôt qu'à payer une seconde fois.
--}}
<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Paiement non abouti — WiGO PRO</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex h-full items-center justify-center bg-surface p-6 font-sans text-ink antialiased">
    <div class="w-full max-w-sm rounded border border-line bg-card p-8 text-center shadow-sm">
        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-err-bg">
            <svg class="h-7 w-7 text-err-text" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 6 6 18M6 6l12 12"></path>
            </svg>
        </div>

        <h1 class="mt-5 text-lg font-semibold text-ink">Paiement non abouti</h1>

        <p class="mt-2 text-sm text-muted">
            Votre recharge n'a pas été finalisée et aucun montant n'a été
            prélevé. Vous pouvez relancer l'opération depuis l'application.
        </p>

        <p class="mt-4 text-xs text-muted">
            Si votre compte Wave a malgré tout été débité, ne payez pas une
            seconde fois : vérifiez l'historique de vos recharges, ou
            contactez le support depuis WiGO PRO.
        </p>
    </div>
</body>
</html>
