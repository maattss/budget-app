import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/passkeys.js',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        // Without this, Vite binds to the IPv6 loopback and writes
        // http://[::1]:5173 into public/hot. Asset URLs then point at a
        // different origin than the app, which the browser blocks - the page
        // renders with no CSS at all and no visible error.
        host: 'localhost',
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
