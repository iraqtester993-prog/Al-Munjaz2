import './bootstrap';
import '../css/app.css';
import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import PopupSelect from './Components/PopupSelect.vue';

// Page modules remain code-split for a light first launch. The mobile shell
// can warm the module for the tab a user has started to touch, so the screen
// is ready by the time its Inertia response arrives.
const pageModules = import.meta.glob('./Pages/**/*.vue');
window.__almunjazPreloadPage = (name) => {
    const load = pageModules[`./Pages/${name}.vue`];
    if (load) load().catch(() => {});
};

// Changes the entry-file fingerprint on every production release. This lets
// installed copies fetch the current application shell instead of reusing a
// previously cached entry bundle.
document.documentElement.dataset.clientRelease = '2026-09-04-mobile-direct-input-r4';

// The project intentionally keeps the web client independent of a Ziggy PHP
// package.  Provide the named Laravel routes used by the Vue application here
// so both the app and the separate dashboard can generate URLs on their own
// current host.  Without this configuration `route()` crashes after login.
const ziggyRoutes = {
    login: { uri: 'login', methods: ['GET', 'HEAD'] },
    'admin.login': { uri: 'dashboard/login', methods: ['GET', 'HEAD'] },
    register: { uri: 'register/{role}', methods: ['GET', 'HEAD'] },
    'legal.privacy': { uri: 'privacy-policy', methods: ['GET', 'HEAD'] },
    'legal.terms': { uri: 'terms-of-use', methods: ['GET', 'HEAD'] },
    logout: { uri: 'logout', methods: ['POST'] },
    app: { uri: 'app', methods: ['GET', 'HEAD'] },
    'app.profile': { uri: 'app/profile', methods: ['GET', 'HEAD'] },
    'profile.update': { uri: 'profile/update', methods: ['POST'] },
    'profile.theme': { uri: 'profile/theme', methods: ['POST'] },
    'profile.locale': { uri: 'profile/locale', methods: ['POST'] },
    'profile.documents.show': { uri: 'profile/documents/{document}', methods: ['GET', 'HEAD'] },
    'profile.documents.replace': { uri: 'profile/documents/{document}', methods: ['POST'] },
    'profile.verification': { uri: 'profile/verification', methods: ['POST'] },
    'locale.set': { uri: 'locale', methods: ['POST'] },
    'app.orders': { uri: 'app/orders', methods: ['GET', 'HEAD'] },
    'app.reports': { uri: 'app/reports', methods: ['GET', 'HEAD'] },
    'app.orders.store': { uri: 'app/orders', methods: ['POST'] },
    'app.orders.update': { uri: 'app/orders/{order}/update', methods: ['POST'] },
    'app.orders.destroy': { uri: 'app/orders/{order}', methods: ['DELETE'] },
    'app.orders.status': { uri: 'app/orders/{order}/status', methods: ['POST'] },
    'app.orders.return': { uri: 'app/orders/{order}/return', methods: ['POST'] },
    'app.orders.return-to-merchant': { uri: 'app/orders/{order}/return-to-merchant', methods: ['POST'] },
    'app.orders.recreate': { uri: 'app/orders/{order}/recreate', methods: ['POST'] },
    'app.orders.archive': { uri: 'app/orders/{order}/archive', methods: ['POST'] },
    'app.orders.republish': { uri: 'app/orders/{order}/republish', methods: ['POST'] },
    'app.orders.claim': { uri: 'app/orders/{order}/claim', methods: ['POST'] },
    'app.duty': { uri: 'app/duty', methods: ['POST'] },
    'app.location.update': { uri: 'app/location', methods: ['POST'] },
    'app.location.clear': { uri: 'app/location', methods: ['DELETE'] },
    'app.wallet': { uri: 'app/wallet', methods: ['GET', 'HEAD'] },
    'app.wallet.withdraw': { uri: 'app/wallet/withdraw', methods: ['POST'] },
    'app.wallet.handover': { uri: 'app/wallet/handover', methods: ['POST'] },
    'app.wallet.recharge': { uri: 'app/wallet/recharge', methods: ['POST'] },
    'app.wallet.budget': { uri: 'app/wallet/budget', methods: ['POST'] },
    'app.wallet.budget.reduce': { uri: 'app/wallet/budget/reduce', methods: ['POST'] },
    'app.chats': { uri: 'app/chats', methods: ['GET', 'HEAD'] },
    'app.chats.show': { uri: 'app/chats/{chat}', methods: ['GET', 'HEAD'] },
    'app.chats.messages': { uri: 'app/chats/{chat}/messages', methods: ['GET', 'HEAD'] },
    'app.chats.presence': { uri: 'app/chats/{chat}/presence', methods: ['POST'] },
    'app.chats.unread': { uri: 'app/chats/unread', methods: ['GET', 'HEAD'] },
    'app.chats.send': { uri: 'app/chats/{chat}/send', methods: ['POST'] },
    'app.chats.open': { uri: 'app/chats/open', methods: ['POST'] },
    'app.notifications': { uri: 'app/notifications', methods: ['GET', 'HEAD'] },
    'app.notifications.feed': { uri: 'app/notifications/feed', methods: ['GET', 'HEAD'] },
    'app.notifications.read': { uri: 'app/notifications/{notification}/read', methods: ['PATCH'] },
    'app.notifications.read-all': { uri: 'app/notifications/read-all', methods: ['POST'] },
    'app.notifications.destroy': { uri: 'app/notifications/{notification}', methods: ['DELETE'] },
    'app.push.config': { uri: 'app/push/config', methods: ['GET', 'HEAD'] },
    'app.push.subscribe': { uri: 'app/push/subscriptions', methods: ['POST'] },
    'app.push.unsubscribe': { uri: 'app/push/subscriptions', methods: ['DELETE'] },
    'admin.dashboard': { uri: 'dashboard', methods: ['GET', 'HEAD'] },
    'admin.orders': { uri: 'dashboard/orders', methods: ['GET', 'HEAD'] },
    'admin.orders.update': { uri: 'dashboard/orders/{order}', methods: ['PUT'] },
    'admin.orders.destroy': { uri: 'dashboard/orders/{order}', methods: ['DELETE'] },
    'admin.transfers': { uri: 'dashboard/transfers', methods: ['GET', 'HEAD'] },
    'admin.transfers.store': { uri: 'dashboard/transfers', methods: ['POST'] },
    'admin.transfers.dispatch': { uri: 'dashboard/transfers/{transfer}/dispatch', methods: ['POST'] },
    'admin.transfers.receive': { uri: 'dashboard/transfers/{transfer}/receive', methods: ['POST'] },
    'admin.branches': { uri: 'dashboard/branches', methods: ['GET', 'HEAD'] },
    'admin.branches.store': { uri: 'dashboard/branches', methods: ['POST'] },
    'admin.branches.update': { uri: 'dashboard/branches/{branch}', methods: ['PUT'] },
    'admin.branches.destroy': { uri: 'dashboard/branches/{branch}', methods: ['DELETE'] },
    'admin.branches.status': { uri: 'dashboard/branches/{branch}/status', methods: ['PATCH'] },
    'admin.branches.access.store': { uri: 'dashboard/branches/{branch}/access', methods: ['POST'] },
    'admin.branch.portal': { uri: 'dashboard/branch', methods: ['GET', 'HEAD'] },
    'admin.branch.preferences.theme': { uri: 'dashboard/branch/preferences/theme', methods: ['POST'] },
    'admin.branch.preferences.locale': { uri: 'dashboard/branch/preferences/locale', methods: ['POST'] },
    'admin.branch.orders.status': { uri: 'dashboard/branch/orders/{order}/status', methods: ['POST'] },
    'admin.branch.orders.courier': { uri: 'dashboard/branch/orders/{order}/courier', methods: ['POST'] },
    'admin.branch.orders.reoffer-overdue-pickup': { uri: 'dashboard/branch/orders/{order}/reoffer-overdue-pickup', methods: ['POST'] },
    'admin.branch.users.update': { uri: 'dashboard/branch/users/{user}', methods: ['PUT'] },
    'admin.branch.users.status': { uri: 'dashboard/branch/users/{user}/status', methods: ['POST'] },
    'admin.branch.users.merchant-verification': { uri: 'dashboard/branch/users/{user}/merchant-verification', methods: ['POST'] },
    'admin.branch.users.documents.show': { uri: 'dashboard/branch/users/{user}/documents/{document}', methods: ['GET', 'HEAD'] },
    'admin.branch.users.documents.review': { uri: 'dashboard/branch/users/{user}/documents/{document}/review', methods: ['POST'] },
    'admin.branch.users.destroy': { uri: 'dashboard/branch/users/{user}', methods: ['DELETE'] },
    'admin.orders.status': { uri: 'dashboard/orders/{order}/status', methods: ['POST'] },
    'admin.orders.courier': { uri: 'dashboard/orders/{order}/courier', methods: ['POST'] },
    'admin.orders.reoffer-overdue-pickup': { uri: 'dashboard/orders/{order}/reoffer-overdue-pickup', methods: ['POST'] },
    'admin.orders.branches': { uri: 'dashboard/orders/{order}/branches', methods: ['POST'] },
    'admin.orders.restore': { uri: 'dashboard/orders/{orderId}/restore', methods: ['POST'] },
    'admin.merchants': { uri: 'dashboard/merchants', methods: ['GET', 'HEAD'] },
    'admin.couriers': { uri: 'dashboard/couriers', methods: ['GET', 'HEAD'] },
    'admin.couriers.locations': { uri: 'dashboard/couriers/locations', methods: ['GET', 'HEAD'] },
    'admin.users.status': { uri: 'dashboard/users/{user}/status', methods: ['POST'] },
    'admin.users.update': { uri: 'dashboard/users/{user}', methods: ['PUT'] },
    'admin.users.courier-deduction.update': { uri: 'dashboard/users/{user}/courier-deduction', methods: ['PATCH'] },
    'admin.users.merchant-verification': { uri: 'dashboard/users/{user}/merchant-verification', methods: ['POST'] },
    'admin.users.courier-verification': { uri: 'dashboard/users/{user}/courier-verification', methods: ['POST'] },
    'admin.users.destroy': { uri: 'dashboard/users/{user}', methods: ['DELETE'] },
    'admin.users.documents.show': { uri: 'dashboard/users/{user}/documents/{document}', methods: ['GET', 'HEAD'] },
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
    'admin.employees': { uri: 'dashboard/employees', methods: ['GET', 'HEAD'] },
    'admin.employees.store': { uri: 'dashboard/employees', methods: ['POST'] },
    'admin.employees.invitations.store': { uri: 'dashboard/employees/invitations', methods: ['POST'] },
    'admin.employees.update': { uri: 'dashboard/employees/{user}', methods: ['PUT'] },
    'admin.employees.status': { uri: 'dashboard/employees/{user}/status', methods: ['PATCH'] },
    'admin.employees.destroy': { uri: 'dashboard/employees/{user}', methods: ['DELETE'] },
    'admin.invitations.accept': { uri: 'dashboard/invitations/{token}', methods: ['GET', 'HEAD'] },
    'admin.invitations.accept.store': { uri: 'dashboard/invitations/{token}', methods: ['POST'] },
    'admin.notifications': { uri: 'dashboard/notifications', methods: ['GET', 'HEAD'] },
    'admin.notifications.store': { uri: 'dashboard/notifications', methods: ['POST'] },
    'admin.settings': { uri: 'dashboard/settings', methods: ['GET', 'HEAD'] },
    'admin.settings.update': { uri: 'dashboard/settings', methods: ['POST'] },
    'admin.settings.branding.update': { uri: 'dashboard/settings/branding', methods: ['POST'] },
    'admin.settings.support.update': { uri: 'dashboard/settings/support', methods: ['POST'] },
    'admin.settings.financial-defaults.update': { uri: 'dashboard/settings/financial-defaults', methods: ['POST'] },
    'admin.settings.courier-deduction-default.update': { uri: 'dashboard/settings/courier-deduction-default', methods: ['POST'] },
    'admin.settings.timing.update': { uri: 'dashboard/settings/timing', methods: ['POST'] },
    'admin.settings.public-content.update': { uri: 'dashboard/settings/public-content', methods: ['POST'] },
    'admin.provinces.store': { uri: 'dashboard/settings/provinces', methods: ['POST'] },
    'admin.provinces.update': { uri: 'dashboard/settings/provinces/{province}', methods: ['PUT'] },
    'admin.provinces.status': { uri: 'dashboard/settings/provinces/{province}/status', methods: ['PATCH'] },
    'admin.settings.slides.store': { uri: 'dashboard/settings/slides', methods: ['POST'] },
    'admin.settings.slides.update': { uri: 'dashboard/settings/slides/{mobileSlide}', methods: ['PUT'] },
    'admin.settings.slides.destroy': { uri: 'dashboard/settings/slides/{mobileSlide}', methods: ['DELETE'] },
    'admin.loyalty': { uri: 'dashboard/loyalty', methods: ['GET', 'HEAD'] },
    'admin.loyalty.settings': { uri: 'dashboard/loyalty/settings', methods: ['POST'] },
    'admin.loyalty.adjust': { uri: 'dashboard/loyalty/adjust', methods: ['POST'] },
    'admin.permissions': { uri: 'dashboard/permissions', methods: ['GET', 'HEAD'] },
    'admin.permissions.store': { uri: 'dashboard/permissions', methods: ['POST'] },
    'admin.permissions.update': { uri: 'dashboard/permissions/{permissionProfile}', methods: ['PUT'] },
    'admin.permissions.destroy': { uri: 'dashboard/permissions/{permissionProfile}', methods: ['DELETE'] },
    'admin.permissions.assignments.update': { uri: 'dashboard/permissions/users/{user}', methods: ['PUT'] },
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

// The initial Inertia page already contains the active-language dictionary.
// Keeping another copy in the Blade HTML made every first page unnecessarily
// large, especially on a mobile connection.
let translations = {};

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
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, pageModules),
    // Some Android/PWA browsers animate a visit from the side through the
    // View Transitions API. It is explicitly disabled for every visit.
    defaults: {
        visitOptions: (_href, options) => ({
            ...options,
            viewTransition: false,
        }),
    },
    setup({ el, App, props, plugin }) {
        translations = props.initialPage.props.translations || translations;

        const initialLocale = props.initialPage.props.locale;
        if (initialLocale) {
            document.documentElement.lang = initialLocale;
            document.documentElement.dir = initialLocale === 'en' ? 'ltr' : 'rtl';
        }

        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue, window.Ziggy)
            .component('PopupSelect', PopupSelect);

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
