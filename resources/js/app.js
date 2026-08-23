import './bootstrap';
import '../css/app.css';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';

const translations = window.__translations || {};

window.t = (key, params = {}) => {
    let str = translations[key] ?? key;
    for (const [k, v] of Object.entries(params)) {
        str = str.replaceAll(':' + k, v);
    }
    return str;
};

window.fmt = (n) => {
    try {
        return (Math.round(n) || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    } catch (e) {
        return '0';
    }
};

createInertiaApp({
    title: (title) => (title ? `${title} — ${translations['Merchant App']}` : translations['Merchant App']),
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        delay: 250,
        color: '#0B6E68',
    },
});
