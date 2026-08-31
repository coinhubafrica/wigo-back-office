// Client temps réel (Reverb). Ne construit rien sans identifiants configurés.
import './echo'

/**
 * Comportement de modale partagé : piège de tabulation, focus initial et
 * retour du focus sur l'élément déclencheur.
 *
 * Enregistré sur `alpine:init` plutôt qu'importé : Alpine est fourni par le
 * bundle de Livewire et n'est pas une dépendance npm du projet, donc rien
 * n'est à importer ici.
 *
 * Usage : `x-data="modalFocus"` sur le fond de la modale, `x-ref="panel"` sur
 * le panneau, et `x-on:keydown.tab="trap($event)"`.
 */
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

document.addEventListener('alpine:init', () => {
    window.Alpine.data('modalFocus', () => ({
        /** Élément focalisé avant l'ouverture, pour y revenir à la fermeture. */
        previouslyFocused: null,

        /** Position de cet élément dans l'ordre de tabulation du document. */
        previousIndex: -1,

        init() {
            this.previouslyFocused = document.activeElement

            // Livewire re-rend souvent la liste en fermant la modale, et le
            // déclencheur d'origine est alors remplacé par un noeud neuf. On
            // note sa position parmi les éléments focalisables du document pour
            // pouvoir retrouver son équivalent à la fermeture.
            this.previousIndex = this.documentFocusables().indexOf(this.previouslyFocused)

            // Le premier contrôle du panneau reçoit le focus : sans cela, la
            // tabulation repartirait du haut du document, derrière la modale.
            this.$nextTick(() => this.focusables()[0]?.focus())
        },

        destroy() {
            const previous = this.previouslyFocused

            if (previous === null) {
                return
            }

            // Le déclencheur a survécu au re-rendu : cas courant des modales
            // ouvertes depuis un bouton hors de la liste re-rendue.
            if (previous.isConnected) {
                previous.focus()

                return
            }

            // Sinon `focus()` sur un noeud détaché ne fait rien et le focus
            // retombe sur `<body>` : on reprend l'élément qui occupe désormais
            // la même position, c'est-à-dire le déclencheur reconstruit.
            const focusables = this.documentFocusables()

            if (this.previousIndex >= 0 && focusables[this.previousIndex] !== undefined) {
                focusables[this.previousIndex].focus()
            }
        },

        /** Éléments focalisables du document, dans l'ordre de tabulation. */
        documentFocusables() {
            return [...document.querySelectorAll(FOCUSABLE)]
                .filter((el) => el.offsetParent !== null)
        },

        /** Éléments focalisables du panneau, dans l'ordre de tabulation. */
        focusables() {
            return [...this.$refs.panel.querySelectorAll(FOCUSABLE)]
                .filter((el) => el.offsetParent !== null)
        },

        /** Piège de tabulation : le focus boucle dans le panneau. */
        trap(event) {
            const items = this.focusables()

            if (items.length === 0) {
                return
            }

            const first = items[0]
            const last = items[items.length - 1]

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault()
                last.focus()
            } else if (! event.shiftKey && document.activeElement === last) {
                event.preventDefault()
                first.focus()
            }
        },
    }))
})

/**
 * Temps réel de la file de traitement.
 *
 * Le composant écoute la file et le fil ouvert, et se contente de demander un
 * rechargement : la trame reçue est un signal, jamais la source du rendu. Rien
 * ne s'affiche donc qui n'ait été autorisé côté serveur.
 *
 * Sans `window.Echo` — pas d'identifiants Reverb configurés — le composant ne
 * fait rien et l'écran retombe sur son `wire:poll`.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('supportRealtime', (selected) => ({
        /** Identifiant de la conversation suivie, pour pouvoir s'en détacher. */
        followed: null,

        init() {
            if (! window.Echo) {
                return
            }

            window.Echo.private('support-queue')
                .listen('.message.sent', () => this.refresh())

            this.follow(selected)

            this.$watch('$wire.selected', (id) => this.follow(id))

            // `livewire:navigating` : quitter la page sans se désabonner
            // laisserait la connexion accumuler des canaux morts.
            this.leaveOnNavigate = () => this.unfollow()
            window.addEventListener('livewire:navigating', this.leaveOnNavigate)
        },

        destroy() {
            this.unfollow()
            window.removeEventListener('livewire:navigating', this.leaveOnNavigate)
            window.Echo?.leave('support-queue')
        },

        /**
         * Recharge le composant, puis ramène le fil en bas : un message qui
         * arrive sous la ligne de flottaison passerait inaperçu.
         */
        async refresh() {
            await this.$wire.$refresh()

            window.dispatchEvent(new CustomEvent('thread-updated'))
        },

        follow(id) {
            if (this.followed === id) {
                return
            }

            this.unfollow()

            if (! id) {
                return
            }

            this.followed = id

            window.Echo.private(`conversation.${id}`)
                .listen('.message.sent', () => this.refresh())
                .listen('.message.read', () => this.refresh())
        },

        unfollow() {
            if (this.followed) {
                window.Echo?.leave(`conversation.${this.followed}`)
                this.followed = null
            }
        },
    }))
})
