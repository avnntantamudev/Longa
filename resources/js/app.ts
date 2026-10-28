import '../css/app.css';

import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';

const appName = import.meta.env.VITE_APP_NAME || 'BISCA';

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
    // Login e registo usam o AuthLayout (sem navegação); as restantes páginas usam o AppLayout
    layout: (name) => (name.startsWith('Auth/') || name.startsWith('Onboarding/') ? AuthLayout : AppLayout),
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
