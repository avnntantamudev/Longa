import '../css/app.css';

import AppLayout from '@/layouts/AppLayout.vue';
import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';

const appName = import.meta.env.VITE_APP_NAME || 'LONGA';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) => {
        const pages = import.meta.glob<DefineComponent>('./pages/**/*.vue');
        const page = pages[`./pages/${name}.vue`];
        if (!page) {
            throw new Error(`Página Inertia não encontrada: ${name}`);
        }
        return page();
    },
    // Todas as páginas usam o AppLayout, exceto as que definem o seu próprio layout
    layout: () => AppLayout,
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#FF7A00',
        delay: 250,
    },
});
