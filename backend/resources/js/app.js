import './bootstrap';
import '../css/app.css';
import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';

// The project intentionally keeps the web client independent of a Ziggy PHP
// package.  Provide the named Laravel routes used by the Vue application here
// so both the app and the separate dashboard can generate URLs on their own
// current host.  Without this configuration `route()` crashes after login.
const ziggyRoutes = {
    login: { uri: 'login', methods: ['GET', 'HEAD'] },
    'admin.login': { uri: 'dashboard/login', methods: ['GET', 'HEAD'] },
    register: { uri: 'register/{role}', methods: ['GET', 'HEAD'] },
    logout: { uri: 'logout', methods: ['POST'] },
    app: { uri: 'app', methods: ['GET', 'HEAD'] },
    'app.profile': { uri: 'app/profile', methods: ['GET', 'HEAD'] },
    'profile.update': { uri: 'profile/update', methods: ['POST'] },
    'profile.theme': { uri: 'profile/theme', methods: ['POST'] },
    'profile.locale': { uri: 'profile/locale', methods: ['POST'] },
    'profile.verification': { uri: 'profile/verification', methods: ['POST'] },
    'locale.set': { uri: 'locale', methods: ['POST'] },
    'app.orders': { uri: 'app/orders', methods: ['GET', 'HEAD'] },
    'app.reports': { uri: 'app/reports', methods: ['GET', 'HEAD'] },
    'app.orders.store': { uri: 'app/orders', methods: ['POST'] },
    'app.orders.update': { uri: 'app/orders/{order}/update', methods: ['POST'] },
    'app.orders.status': { uri: 'app/orders/{order}/status', methods: ['POST'] },
    'app.orders.return': { uri: 'app/orders/{order}/return', methods: ['POST'] },
    'app.orders.return-to-merchant': { uri: 'app/orders/{order}/return-to-merchant', methods: ['POST'] },
    'app.orders.recreate': { uri: 'app/orders/{order}/recreate', methods: ['POST'] },
    'app.orders.claim': { uri: 'app/orders/{order}/claim', methods: ['POST'] },
    'app.duty': { uri: 'app/duty', methods: ['POST'] },
    'app.location.update': { uri: 'app/location', methods: ['POST'] },
    'app.location.clear': { uri: 'app/location', methods: ['DELETE'] },
    'app.wallet': { uri: 'app/wallet', methods: ['GET', 'HEAD'] },
    'app.wallet.withdraw': { uri: 'app/wallet/withdraw', methods: ['POST'] },
    'app.wallet.handover': { uri: 'app/wallet/handover', methods: ['POST'] },
    'app.wallet.recharge': { uri: 'app/wallet/recharge', methods: ['POST'] },
    'app.wallet.budget': { uri: 'app/wallet/budget', methods: ['POST'] },
    'app.chats': { uri: 'app/chats', methods: ['GET', 'HEAD'] },
    'app.chats.show': { uri: 'app/chats/{chat}', methods: ['GET', 'HEAD'] },
    'app.chats.messages': { uri: 'app/chats/{chat}/messages', methods: ['GET', 'HEAD'] },
    'app.chats.send': { uri: 'app/chats/{chat}/send', methods: ['POST'] },
    'app.chats.open': { uri: 'app/chats/open', methods: ['POST'] },
    'app.notifications': { uri: 'app/notifications', methods: ['GET', 'HEAD'] },
    'app.notifications.feed': { uri: 'app/notifications/feed', methods: ['GET', 'HEAD'] },
    'app.notifications.read-all': { uri: 'app/notifications/read-all', methods: ['POST'] },
    'app.push.config': { uri: 'app/push/config', methods: ['GET', 'HEAD'] },
    'app.push.subscribe': { uri: 'app/push/subscriptions', methods: ['POST'] },
    'app.push.unsubscribe': { uri: 'app/push/subscriptions', methods: ['DELETE'] },
    'admin.dashboard': { uri: 'dashboard', methods: ['GET', 'HEAD'] },
    'admin.orders': { uri: 'dashboard/orders', methods: ['GET', 'HEAD'] },
    'admin.transfers': { uri: 'dashboard/transfers', methods: ['GET', 'HEAD'] },
    'admin.transfers.store': { uri: 'dashboard/transfers', methods: ['POST'] },
    'admin.transfers.dispatch': { uri: 'dashboard/transfers/{transfer}/dispatch', methods: ['POST'] },
    'admin.transfers.receive': { uri: 'dashboard/transfers/{transfer}/receive', methods: ['POST'] },
    'admin.branches': { uri: 'dashboard/branches', methods: ['GET', 'HEAD'] },
    'admin.branches.store': { uri: 'dashboard/branches', methods: ['POST'] },
    'admin.branches.update': { uri: 'dashboard/branches/{branch}', methods: ['PUT'] },
    'admin.branches.status': { uri: 'dashboard/branches/{branch}/status', methods: ['PATCH'] },
    'admin.branches.access.store': { uri: 'dashboard/branches/{branch}/access', methods: ['POST'] },
    'admin.branch.portal': { uri: 'dashboard/branch', methods: ['GET', 'HEAD'] },
    'admin.branch.content': { uri: 'dashboard/branch/content', methods: ['GET', 'HEAD'] },
    'admin.branch.content.store': { uri: 'dashboard/branch/content', methods: ['POST'] },
    'admin.branch.content.update': { uri: 'dashboard/branch/content/{mobileSlide}', methods: ['PUT'] },
    'admin.branch.content.destroy': { uri: 'dashboard/branch/content/{mobileSlide}', methods: ['DELETE'] },
    'admin.branch.preferences.theme': { uri: 'dashboard/branch/preferences/theme', methods: ['POST'] },
    'admin.branch.preferences.locale': { uri: 'dashboard/branch/preferences/locale', methods: ['POST'] },
    'admin.orders.status': { uri: 'dashboard/orders/{order}/status', methods: ['POST'] },
    'admin.orders.courier': { uri: 'dashboard/orders/{order}/courier', methods: ['POST'] },
    'admin.orders.branches': { uri: 'dashboard/orders/{order}/branches', methods: ['POST'] },
    'admin.merchants': { uri: 'dashboard/merchants', methods: ['GET', 'HEAD'] },
    'admin.couriers': { uri: 'dashboard/couriers', methods: ['GET', 'HEAD'] },
    'admin.users.status': { uri: 'dashboard/users/{user}/status', methods: ['POST'] },
    'admin.users.update': { uri: 'dashboard/users/{user}', methods: ['PUT'] },
    'admin.users.documents.review': { uri: 'dashboard/users/{user}/documents/{document}/review', methods: ['POST'] },
    'admin.finance': { uri: 'dashboard/finance', methods: ['GET', 'HEAD'] },
    'admin.finance.approve': { uri: 'dashboard/finance/requests/{financeRequest}/approve', methods: ['POST'] },
    'admin.finance.reject': { uri: 'dashboard/finance/requests/{financeRequest}/reject', methods: ['POST'] },
    'admin.finance.settlements.store': { uri: 'dashboard/finance/settlements', methods: ['POST'] },
    'admin.cashboxes': { uri: 'dashboard/cashboxes', methods: ['GET', 'HEAD'] },
    'admin.cashboxes.store': { uri: 'dashboard/cashboxes', methods: ['POST'] },
    'admin.cashboxes.status': { uri: 'dashboard/cashboxes/{cashbox}/status', methods: ['PATCH'] },
    'admin.cashboxes.voucher': { uri: 'dashboard/cashboxes/voucher', methods: ['POST'] },
    'admin.cashboxes.transfer': { uri: 'dashboard/cashboxes/transfer', methods: ['POST'] },
    'admin.pricing': { uri: 'dashboard/pricing', methods: ['GET', 'HEAD'] },
    'admin.pricing.store': { uri: 'dashboard/pricing', methods: ['POST'] },
    'admin.pricing.update': { uri: 'dashboard/pricing/{pricingRule}', methods: ['PUT'] },
    'admin.pricing.status': { uri: 'dashboard/pricing/{pricingRule}/status', methods: ['PATCH'] },
    'admin.reports': { uri: 'dashboard/reports', methods: ['GET', 'HEAD'] },
    'admin.platform': { uri: 'dashboard/platform', methods: ['GET', 'HEAD'] },
    'admin.platform.companies.store': { uri: 'dashboard/platform/companies', methods: ['POST'] },
    'admin.platform.companies.update': { uri: 'dashboard/platform/companies/{tenant}', methods: ['PUT'] },
    'admin.platform.plans.store': { uri: 'dashboard/platform/plans', methods: ['POST'] },
    'admin.platform.plans.update': { uri: 'dashboard/platform/plans/{plan}', methods: ['PUT'] },
    'admin.platform.subscriptions.store': { uri: 'dashboard/platform/subscriptions', methods: ['POST'] },
    'admin.platform.subscriptions.status': { uri: 'dashboard/platform/subscriptions/{subscription}', methods: ['PATCH'] },
    'admin.platform.invoices.store': { uri: 'dashboard/platform/invoices', methods: ['POST'] },
    'admin.platform.invoices.status': { uri: 'dashboard/platform/invoices/{invoice}', methods: ['PATCH'] },
    'admin.platform.invitations.store': { uri: 'dashboard/platform/invitations', methods: ['POST'] },
    'admin.invitations.accept': { uri: 'dashboard/invitations/{token}', methods: ['GET', 'HEAD'] },
    'admin.invitations.accept.store': { uri: 'dashboard/invitations/{token}', methods: ['POST'] },
    'admin.notifications': { uri: 'dashboard/notifications', methods: ['GET', 'HEAD'] },
    'admin.notifications.store': { uri: 'dashboard/notifications', methods: ['POST'] },
    'admin.settings': { uri: 'dashboard/settings', methods: ['GET', 'HEAD'] },
    'admin.settings.update': { uri: 'dashboard/settings', methods: ['POST'] },
    'admin.content': { uri: 'dashboard/content', methods: ['GET', 'HEAD'] },
    'admin.content.store': { uri: 'dashboard/content', methods: ['POST'] },
    'admin.content.update': { uri: 'dashboard/content/{mobileSlide}', methods: ['PUT'] },
    'admin.content.destroy': { uri: 'dashboard/content/{mobileSlide}', methods: ['DELETE'] },
    'admin.loyalty': { uri: 'dashboard/loyalty', methods: ['GET', 'HEAD'] },
    'admin.loyalty.settings': { uri: 'dashboard/loyalty/settings', methods: ['POST'] },
    'admin.loyalty.adjust': { uri: 'dashboard/loyalty/adjust', methods: ['POST'] },
    'admin.chat': { uri: 'dashboard/chat', methods: ['GET', 'HEAD'] },
    'admin.chat.show': { uri: 'dashboard/chat/{chat}', methods: ['GET', 'HEAD'] },
    'admin.chat.messages': { uri: 'dashboard/chat/{chat}/messages', methods: ['GET', 'HEAD'] },
    'admin.chat.send': { uri: 'dashboard/chat/{chat}/send', methods: ['POST'] },
    'admin.preferences.theme': { uri: 'dashboard/preferences/theme', methods: ['POST'] },
    'admin.preferences.locale': { uri: 'dashboard/preferences/locale', methods: ['POST'] },
};

window.Ziggy = {
    url: window.location.origin,
    port: null,
    defaults: {},
    routes: ziggyRoutes,
};

let translations = window.__translations || {};

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

// Inertia keeps the JavaScript application alive between visits.  Refresh
// the server-provided dictionary on every successful visit so a user never
// lands in a partially translated screen after switching language or logging
// in from a guest page with a different locale.
router.on('success', (event) => {
    const nextPage = event.detail?.page;
    if (!nextPage?.props) return;

    translations = nextPage.props.translations || translations;
    const nextLocale = nextPage.props.locale;
    if (nextLocale) {
        document.documentElement.lang = nextLocale;
        document.documentElement.dir = nextLocale === 'en' ? 'ltr' : 'rtl';
    }
});

createInertiaApp({
    // Keep the installed experience branded as one product. Role labels belong
    // inside the screens, not in the application/window title.
    title: (title) => (title ? `${title} — المنجز السريع` : 'المنجز السريع'),
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        translations = props.initialPage.props.translations || translations;

        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue, window.Ziggy);

        // Vue templates access helpers through the component instance.
        app.config.globalProperties.t = window.t;
        app.config.globalProperties.fmt = window.fmt;

        app.mount(el);
    },
    progress: {
        delay: 250,
        color: '#0B6E68',
    },
});
