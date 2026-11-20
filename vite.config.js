import { readFileSync } from 'node:fs';
import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import { local } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const port = Number(env.VITE_PORT || 5173);
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
                    local('Instrument Sans', {
                        variants: [
                            { src: 'resources/fonts/instrument-sans/instrument-sans-400.woff2', weight: 400 },
                            { src: 'resources/fonts/instrument-sans/instrument-sans-500.woff2', weight: 500 },
                            { src: 'resources/fonts/instrument-sans/instrument-sans-600.woff2', weight: 600 },
                        ],
                    }),
                ],
            }),
            tailwindcss(),
        ],
        server: {
            port,
            strictPort: true,
            ...(tls ? {
                https: tls,
                origin: `https://${new URL(env.APP_URL).hostname}:${port}`,
                cors: { origin: new URL(env.APP_URL).origin },
                hmr: { host: new URL(env.APP_URL).hostname, protocol: 'wss' },
            } : {}),
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
