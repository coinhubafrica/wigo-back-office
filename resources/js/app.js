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
