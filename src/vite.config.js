import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Inter', {
                    weights: [200, 300, 400, 500],
                    // По умолчанию грузится только latin — русский текст падал бы в системный шрифт
                    subsets: ['latin', 'cyrillic'],
                }),
                // Шрифты акцентов стиля JIVA. Предзагружается только Jura (по умолчанию); остальные объявлены
                // и подтягиваются при первом показе — их заранее прогревает вкладка «Оформление»
                bunny('Jura', { weights: [300, 400, 500], subsets: ['latin', 'cyrillic'] }),
                bunny('Exo 2', { weights: [300, 400, 500], subsets: ['latin', 'cyrillic'], preload: false }),
                bunny('Unbounded', { weights: [300, 400], subsets: ['latin', 'cyrillic'], preload: false }),
            ],
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    server: {
        // Vite крутится в контейнере npm: слушаем все интерфейсы, браузер ходит на localhost
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'localhost',
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
