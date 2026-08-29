{{--
    Page de retour de Wave après un paiement accepté.

    Purement informative : elle ne crédite rien et ne prouve rien. Le crédit du
    solde Yango est confirmé par le webhook de Wave, qui peut arriver quelques
    secondes plus tard — d'où la formulation prudente, qui n'annonce jamais un
    solde déjà crédité.
--}}
<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Paiement reçu — WiGO PRO</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex h-full items-center justify-center bg-surface p-6 font-sans text-ink antialiased">
    <div class="w-full max-w-sm rounded border border-line bg-card p-8 text-center shadow-sm">
        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-ok-bg">
            <svg class="h-7 w-7 text-ok-text" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 6 9 17l-5-5"></path>
            </svg>
        </div>

        <h1 class="mt-5 text-lg font-semibold text-ink">Paiement reçu</h1>

        <p class="mt-2 text-sm text-muted">
            Votre paiement Wave a bien été enregistré. Le crédit de votre solde
            YANGO PRO est en cours de confirmation : il apparaîtra dans
            l'application d'ici quelques instants.
        </p>

        <p class="mt-4 text-xs text-muted">
            Vous pouvez fermer cette page et revenir dans WiGO PRO.
        </p>
    </div>
</body>
</html>
