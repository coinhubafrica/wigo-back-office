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

        /**
         * Livewire insère la modale déjà « ouverte » : sans ce drapeau basculé
         * après l'insertion, `x-transition` n'a rien à animer à l'entrée.
         */
        show: false,

        init() {
            this.previouslyFocused = document.activeElement

            // Basculé au tick suivant, sinon `x-show` voit déjà `true` au
            // premier rendu et l'entrée n'est pas animée.
            this.$nextTick(() => { this.show = true })

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

/**
 * Coquille de l'application : barre latérale escamotable sous `lg`.
 *
 * Fermée par la navigation, par Échap et par le passage au format large, pour
 * qu'un volet ouvert sur mobile ne survive pas à un redimensionnement.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('appShell', () => ({
        sidebarOpen: false,

        init() {
            this.onNavigated = () => this.close()
            window.addEventListener('livewire:navigated', this.onNavigated)

            this.media = window.matchMedia('(min-width: 1024px)')
            this.onResize = (event) => {
                if (event.matches) {
                    this.close()
                }
            }
            this.media.addEventListener('change', this.onResize)
        },

        destroy() {
            window.removeEventListener('livewire:navigated', this.onNavigated)
            this.media?.removeEventListener('change', this.onResize)
        },

        toggle() {
            this.sidebarOpen = ! this.sidebarOpen
        },

        close() {
            this.sidebarOpen = false
        },
    }))
})

/**
 * Notifications éphémères.
 *
 * Reçoit `toast` sur `window`, avec soit une chaîne (succès), soit
 * `{ message, tone }` où `tone` vaut `success`, `error` ou `info`. Une erreur
 * reste affichée plus longtemps et peut toujours être fermée à la main.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('toasts', () => ({
        messages: [],

        push(detail) {
            const payload = typeof detail === 'string' ? { message: detail } : (detail ?? {})
            const tone = ['success', 'error', 'info'].includes(payload.tone) ? payload.tone : 'success'
            const id = Date.now() + Math.random()

            this.messages.push({ id, text: payload.message ?? '', tone })

            setTimeout(() => this.dismiss(id), tone === 'error' ? 8000 : 5000)
        },

        dismiss(id) {
            this.messages = this.messages.filter((message) => message.id !== id)
        },
    }))
})

/**
 * Champ masqué que l'on peut révéler le temps de le relire.
 *
 * Sert aux clés d'API et au mot de passe (`<x-field type="password">`). Le
 * champ bascule entre `password` et `text` : ce que l'on révèle est donc
 * toujours *ce que l'on vient de saisir*, jamais un secret enregistré — les
 * clés stockées ne sont pas renvoyées au navigateur et le champ arrive vide.
 *
 * Le type est porté par Alpine (`:type`) et non par l'attribut initial, et le
 * champ revient masqué à chaque navigation : un onglet laissé ouvert sur un
 * écran de réglages ne garde pas une clé en clair à l'écran.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('revealable', () => ({
        revealed: false,

        init() {
            this.onNavigated = () => { this.revealed = false }
            window.addEventListener('livewire:navigated', this.onNavigated)
        },

        destroy() {
            window.removeEventListener('livewire:navigated', this.onNavigated)
        },

        toggle() {
            this.revealed = ! this.revealed
        },
    }))
})

/**
 * Menu de la barre d'en-tête du site vitrine.
 *
 * Sous `lg` la navigation est repliée derrière un bouton. Le volet se referme
 * au choix d'une section, à Échap et au passage au format large — un menu
 * ouvert ne doit pas survivre à une rotation d'écran.
 *
 * Calqué sur `appShell` : même problème, même forme. Deux différences — pas de
 * `livewire:navigated` à écouter (la page est statique, tous les liens sont
 * des ancres), et c'est le clic sur un lien qui referme, posé dans le gabarit.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('siteNav', () => ({
        open: false,

        init() {
            this.media = window.matchMedia('(min-width: 1024px)')
            this.onResize = (event) => {
                if (event.matches) {
                    this.close()
                }
            }
            this.media.addEventListener('change', this.onResize)
        },

        destroy() {
            this.media?.removeEventListener('change', this.onResize)
        },

        toggle() {
            this.open = ! this.open
        },

        close() {
            this.open = false
        },
    }))
})

/**
 * Apparition au défilement du site vitrine, et compteurs animés.
 *
 * Un seul observateur pour les deux : les compteurs partent quand la première
 * carte de chiffres entre dans le cadre, ils ne sont donc pas un mécanisme
 * séparé.
 *
 * Les éléments partent VISIBLES et c'est ce composant qui les masque
 * (`.site-reveal`) avant de les révéler : sans JavaScript, la page reste
 * entièrement lisible. Les chiffres sont d'ailleurs rendus par le serveur à
 * leur valeur finale — l'animation les ramène à zéro puis les recompte, elle
 * n'est jamais la source de la valeur affichée.
 *
 * `prefers-reduced-motion` court-circuite tout : rien n'est masqué, rien n'est
 * compté. La transition CSS est déjà neutralisée par la règle globale
 * d'`app.css`, mais un compteur en JavaScript ne l'est pas.
 *
 * L'adhésion se fait par attribut (`data-site-reveal`, `data-site-counter`) et
 * non par une liste de sélecteurs de classes : renommer un utilitaire Tailwind
 * ne doit pas éteindre l'animation en silence.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('siteReveal', () => ({
        counted: false,

        init() {
            const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches

            if (reduced || ! ('IntersectionObserver' in window)) {
                return
            }

            /*
             * Onglet en arrière-plan : le navigateur bride `requestAnimationFrame`
             * et les transitions. Masquer maintenant figerait la page à
             * `opacity: 0` jusqu'à ce que le visiteur l'active. On attend donc
             * que le document soit visible pour armer quoi que ce soit.
             */
            if (document.hidden) {
                document.addEventListener('visibilitychange', () => this.arm(), { once: true })

                return
            }

            this.arm()
        },

        /** Masque les cibles puis les révèle à leur entrée dans le cadre. */
        arm() {
            const targets = [...this.$el.querySelectorAll('[data-site-reveal]')]
            targets.forEach((el) => el.classList.add('site-reveal'))

            this.observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (! entry.isIntersecting) {
                        return
                    }

                    entry.target.classList.add('site-reveal--visible')
                    this.observer.unobserve(entry.target)

                    if (! this.counted && entry.target.hasAttribute('data-site-counter')) {
                        this.counted = true
                        this.countUp()
                    }
                })
            }, { threshold: 0.15 })

            targets.forEach((el) => this.observer.observe(el))
        },

        destroy() {
            this.observer?.disconnect()
        },

        /** Rampe cubique sur 1400 ms, mise en forme comme le reste de l'app. */
        countUp() {
            const format = new Intl.NumberFormat('fr-FR')

            this.$el.querySelectorAll('[data-site-target]').forEach((el) => {
                const target = Number.parseInt(el.dataset.siteTarget, 10) || 0
                const duration = 1400
                let started = null

                const step = (now) => {
                    if (started === null) {
                        started = now
                    }

                    const progress = Math.min((now - started) / duration, 1)
                    const value = Math.round(target * (1 - Math.pow(1 - progress, 3)))

                    // Espace fine insécable, comme les colonnes chiffrées du
                    // back-office : `Intl` pose une espace insécable normale.
                    el.textContent = format.format(value).replace(/ /g, ' ')

                    if (progress < 1) {
                        requestAnimationFrame(step)
                    }
                }

                requestAnimationFrame(step)
            })
        },
    }))
})
