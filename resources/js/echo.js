import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

/**
 * Client temps réel.
 *
 * Rien n'est construit si la clé n'est pas configurée : `.env.example` ne
 * porte pas d'identifiants Reverb, et un clone frais ou une construction de CI
 * doit pouvoir charger la page sans lever. Les composants retombent alors sur
 * leur `wire:poll`.
 */
const key = import.meta.env.VITE_REVERB_APP_KEY

if (key) {
    window.Pusher = Pusher

    const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https'

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    })
}
