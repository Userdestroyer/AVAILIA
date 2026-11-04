import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { vFrost } from './composables/useFrost';

const appName = import.meta.env.VITE_APP_NAME || 'AVAILIA';

createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),
    // Страницы грузятся отдельными чанками: вход не тянет Markdown, подсветку кода и т.п.
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.vue');
        return pages[`./pages/${name}.vue`]();
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .directive('frost', vFrost)
            .mount(el);
    },
});
