import { readFileSync } from 'node:fs';
import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const tls = env.DEV_TLS_CERT && env.DEV_TLS_KEY ? {
        cert: readFileSync(env.DEV_TLS_CERT),
        key: readFileSync(env.DEV_TLS_KEY),
    } : undefined;

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
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
            ...(tls ? {
                https: tls,
                origin: `https://${new URL(env.APP_URL).hostname}:5173`,
                cors: { origin: new URL(env.APP_URL).origin },
                hmr: { host: new URL(env.APP_URL).hostname, protocol: 'wss' },
            } : {}),
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
