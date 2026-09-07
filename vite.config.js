import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from "@tailwindcss/vite";
import { globSync } from 'node:fs';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/images/logo-wigo-pro-white.png',
                // Images du site vitrine. Déclarées par motif : elles sont
                // nombreuses et purement statiques, les énumérer une à une
                // inviterait l'oubli au prochain ajout.
                ...globSync('resources/images/site/**/*.{png,jpg,webp}'),
            ],
            refresh: true,
            // Polices de la charte WiGO PRO, téléchargées et auto-hébergées
            // au build (aucun appel à un CDN tiers à l'exécution).
            fonts: [
                bunny('Archivo', {
                    weights: [400, 500, 600, 700],
                }),
                bunny('IBM Plex Mono', {
                    weights: [400, 500],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
