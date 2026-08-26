<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Flash from './Flash.vue'

const props = defineProps({ title: { type: String, default: '' } })

const page = usePage()
const isMenuOpen = ref(false)
const user = computed(() => page.props.auth?.user)
const adminBadges = computed(() => page.props.adminBadges || {})
// The reference dashboard opens in the navy/cyan operating view.  Keep a
// per-browser preference so the user's explicit light-mode choice survives
// navigation, while first-time dashboard sessions always start in dark mode.
function savedTheme() {
    try {
        return window.localStorage.getItem('almunjaz-admin-theme')
    } catch {
        return null
    }
}

function persistTheme(value) {
    try {
        window.localStorage.setItem('almunjaz-admin-theme', value)
    } catch {
        // Storage can be disabled in a private browser session. The server
        // preference still receives the change below.
    }
}

const theme = ref(savedTheme() === 'light' ? 'light' : 'dark')
const locale = ref(page.props.locale || user.value?.locale || 'ar')
const currentPath = computed(() => new URL(page.url, window.location.origin).pathname.replace(/\/$/, ''))
const branding = computed(() => page.props.branding || {
    name: t('Al-Munjaz Al-Saree'),
    tagline: t('Admin Dashboard'),
    logo_url: '/logo.png',
})

const localeNames = {
    ar: { ar: 'العربية', en: 'Arabic', ku: 'عەرەبی' },
    en: { ar: 'الإنجليزية', en: 'English', ku: 'ئینگلیزی' },
    ku: { ar: 'الكردية', en: 'Kurdish', ku: 'کوردی' },
}

const fallbackLabels = {
    'Branches': { ar: 'الفروع', en: 'Branches', ku: 'لقەکان' },
    'Branches and Funds': { ar: 'الفروع والصناديق', en: 'Branches and funds', ku: 'لقەکان و سندوقەکان' },
    'Transfers': { ar: 'تحويلات الفروع', en: 'Branch transfers', ku: 'گواستنەوەی لقەکان' },
    'Cashboxes': { ar: 'الصناديق', en: 'Cashboxes', ku: 'سندووقەکان' },
    'Pricing': { ar: 'الباقات والتسعير', en: 'Pricing', ku: 'نرخدانان' },
    'Reports': { ar: 'التقارير والتحليلات', en: 'Reports & analytics', ku: 'ڕاپۆرت و شیکاری' },
    'Operational Team': { ar: 'الفريق والصلاحيات', en: 'Operational team', ku: 'تیمی کارپێکردن' },
    'Platform Control': { ar: 'إدارة المنصة', en: 'Platform control', ku: 'بەڕێوەبردنی پلاتفۆرم' },
    'Mobile Content': { ar: 'محتوى التطبيق', en: 'Mobile content', ku: 'ناوەڕۆکی ئەپ' },
    'Loyalty Points': { ar: 'نقاط الولاء', en: 'Loyalty points', ku: 'خاڵەکانی دڵسۆزی' },
}

const availableLocales = computed(() => (page.props.locales?.length ? page.props.locales : ['ar', 'en', 'ku']))
const pageTitle = computed(() => {
    if (props.title === 'الفروع') return locale.value === 'en' ? 'Branches' : locale.value === 'ku' ? 'لقەکان' : 'الفروع'
    return t(props.title || 'Dashboard')
})
const nav = computed(() => [
    { label: t('Dashboard'), icon: 'grid', route: 'admin.dashboard' },
    { label: localized('Platform Control'), icon: 'building', route: 'admin.platform' },
    { label: t('Orders'), icon: 'box', route: 'admin.orders' },
    { label: localized('Branches and Funds'), icon: 'building', route: 'admin.branches' },
    { label: localized('Transfers'), icon: 'transfer', route: 'admin.transfers' },
    { label: t('Merchants'), icon: 'shop', route: 'admin.merchants' },
    { label: localized('Operational Team'), icon: 'users', route: 'admin.couriers' },
    { label: t('Finance'), icon: 'card', route: 'admin.finance', badge: adminBadges.value.finance },
    { label: localized('Cashboxes'), icon: 'cashbox', route: 'admin.cashboxes' },
    { label: localized('Pricing'), icon: 'tag', route: 'admin.pricing' },
    { label: localized('Reports'), icon: 'chart', route: 'admin.reports' },
    { label: t('Chat'), icon: 'chat', route: 'admin.chat', badge: adminBadges.value.chat },
    { label: t('Notifications'), icon: 'bell', route: 'admin.notifications', badge: adminBadges.value.notifications },
    { label: localized('Mobile Content'), icon: 'image', route: 'admin.content' },
    { label: localized('Loyalty Points'), icon: 'star', route: 'admin.loyalty' },
    { label: t('Settings'), icon: 'settings', route: 'admin.settings' },
].map((item) => ({ ...item, url: route(item.route) })))

function localized(key) {
    const translated = t(key)
    return translated === key ? (fallbackLabels[key]?.[locale.value] || fallbackLabels[key]?.ar || key) : translated
}

function localeName(code) {
    return localeNames[code]?.[locale.value] || code
}

function active(item) {
    return currentPath.value === new URL(item.url, window.location.origin).pathname.replace(/\/$/, '')
}

function navigate(url) {
    isMenuOpen.value = false
    router.visit(url)
}

function applyTheme(value) {
    document.documentElement.dataset.theme = value
    document.body.dataset.theme = value
}

function applyLocale(value) {
    document.documentElement.lang = value
    document.documentElement.dir = value === 'en' ? 'ltr' : 'rtl'
}

function toggleTheme() {
    const previous = theme.value
    const next = previous === 'dark' ? 'light' : 'dark'
    theme.value = next
    applyTheme(next)
    persistTheme(next)

    router.post(route('admin.preferences.theme'), { theme: next }, {
        preserveScroll: true,
        preserveState: true,
        onError: () => {
            theme.value = previous
            applyTheme(previous)
            persistTheme(previous)
        },
    })
}

function changeLocale(event) {
    const next = event.target.value
    if (next === locale.value) return

    const previous = locale.value
    locale.value = next
    applyLocale(next)

    router.post(route('admin.preferences.locale'), { locale: next }, {
        preserveScroll: true,
        onError: () => {
            locale.value = previous
            applyLocale(previous)
        },
        // Translation payloads are generated server-side. A single reload here
        // guarantees all dashboard labels and server-provided content change
        // together, rather than leaving a partial mixed-language interface.
        onSuccess: () => window.location.reload(),
    })
}

function logout() {
    router.post(route('logout'))
}

function icon(name) {
    const paths = {
        grid: 'M4 4h6v6H4z M14 4h6v6h-6z M4 14h6v6H4z M14 14h6v6h-6z',
        box: 'm21 8-9 5-9-5 9-5 9 5ZM3 8v8l9 5 9-5V8M12 13v8',
        building: 'M4 21V4a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v17M8 7h.01M12 7h.01M16 7h.01M8 11h.01M12 11h.01M16 11h.01M10 21v-5h4v5',
        shop: 'M4 10v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V10M2 7l1-3h18l1 3a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0Z',
        bike: 'M5 18a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm14-8a4 4 0 1 1 0 8 4 4 0 0 1 0-8ZM5 10h14m-7 0-2-4h5',
        users: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m14-11a4 4 0 1 0 0-8m-6 4a4 4 0 1 0 0-8',
        transfer: 'M7 7h12m0 0-3-3m3 3-3 3M17 17H5m0 0 3 3m-3-3 3-3',
        cashbox: 'M3 7h18v12H3zM7 7V4h10v3m-9 5h.01M12 12h.01M16 12h.01M8 16h8',
        tag: 'M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3.4 13.4A2 2 0 0 1 3 12V5a2 2 0 0 1 2-2h7a2 2 0 0 1 1.4.6l7.2 7.2a2 2 0 0 1 0 2.6ZM8 8h.01',
        chart: 'M4 20V10m6 10V4m6 16v-7m6 7V7',
        card: 'M3 6h18v12H3zM3 10h18M7 15h4',
        chat: 'M21 12a8 8 0 0 1-8 8H4l1.5-3.5A8 8 0 1 1 21 12Z',
        bell: 'M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6Zm5 11a2 2 0 0 0 4 0',
        image: 'M4 5.5A1.5 1.5 0 0 1 5.5 4h13A1.5 1.5 0 0 1 20 5.5v13A1.5 1.5 0 0 1 18.5 20h-13A1.5 1.5 0 0 1 4 18.5v-13ZM7 16l3.3-3.3a1.3 1.3 0 0 1 1.8 0l1.9 1.9 1.5-1.5a1.3 1.3 0 0 1 1.8 0L20 16M9 9h.01',
        star: 'm12 3 2.75 5.57 6.15.9-4.45 4.34 1.05 6.13L12 17.05 6.5 19.94l1.05-6.13L3.1 9.47l6.15-.9L12 3Z',
        settings: 'M12 15.25A3.25 3.25 0 1 0 12 8.75a3.25 3.25 0 0 0 0 6.5Zm0-12.25v2m0 14v2m9-9h-2M5 12H3m15.36-6.36-1.42 1.42M7.06 16.94l-1.42 1.42m12.72 0-1.42-1.42M7.06 7.06 5.64 5.64',
        menu: 'M4 7h16M4 12h16M4 17h16',
        logout: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4m7 14 5-5-5-5m5 5H9',
    }
    return paths[name]
}

watch(() => user.value?.theme, (value) => {
    if (! value) return
    theme.value = value === 'dark' ? 'dark' : 'light'
    applyTheme(theme.value)
})

watch(() => page.props.locale, (value) => {
    if (! value) return
    locale.value = value
    applyLocale(value)
})

onMounted(() => {
    applyTheme(theme.value)
    applyLocale(locale.value)
})
</script>

<template>
    <div class="dashboard-shell" :class="[`dashboard-theme-${theme}`, { 'dashboard-menu-open': isMenuOpen }]">
        <Flash />

        <button
            class="dashboard-backdrop"
            type="button"
            :aria-label="t('Close')"
            @click="isMenuOpen = false"
        />

        <aside class="dashboard-sidebar">
            <div class="dashboard-brand">
                <div class="dashboard-brand-mark" aria-hidden="true"><img :src="branding.logo_url" alt="" /></div>
                <div>
                    <b>{{ branding.name }}</b>
                    <span>{{ branding.tagline || t('Platform control center') }}</span>
                </div>
            </div>

            <nav class="dashboard-nav" :aria-label="t('Dashboard')">
                <button
                    v-for="item in nav"
                    :key="item.route"
                    type="button"
                    class="dashboard-nav-item"
                    :class="{ active: active(item) }"
                    @click="navigate(item.url)"
                >
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="icon(item.icon)" />
                    </svg>
                    <span>{{ item.label }}</span>
                    <b v-if="item.badge" class="dashboard-nav-badge">{{ item.badge > 99 ? '99+' : item.badge }}</b>
                </button>
            </nav>

            <div class="dashboard-sidebar-footer">
                <div class="dashboard-operator">
                    <div class="dashboard-avatar">{{ user?.name?.charAt(0) || 'إ' }}</div>
                    <div>
                        <b>{{ user?.name || t('Admin Dashboard') }}</b>
                        <span>{{ t('Platform management') }}</span>
                    </div>
                </div>
                <button class="dashboard-logout" type="button" @click="logout">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="icon('logout')" />
                    </svg>
                    {{ t('Log Out') }}
                </button>
            </div>
        </aside>

        <main class="dashboard-main">
            <header class="dashboard-topbar">
                <button class="dashboard-menu-toggle" type="button" :aria-label="t('Dashboard')" @click="isMenuOpen = true">
                    <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="icon('menu')" />
                    </svg>
                </button>

                <div class="dashboard-title">
                    <h1>{{ pageTitle }}</h1>
                </div>

                <div class="dashboard-top-spacer" />
                <span class="dashboard-top-live"><i /> {{ t('Live data from app') }}</span>

                <label class="dashboard-language">
                    <span class="sr-only">{{ t('Language') }}</span>
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" />
                    </svg>
                    <select :value="locale" :aria-label="t('Language')" @change="changeLocale">
                        <option v-for="code in availableLocales" :key="code" :value="code">{{ localeName(code) }}</option>
                    </select>
                </label>

                <button
                    class="dashboard-icon-button"
                    type="button"
                    :aria-label="theme === 'dark' ? t('Light') : t('Dark')"
                    @click="toggleTheme"
                >
                    <svg v-if="theme === 'dark'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="4" />
                        <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
                    </svg>
                    <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 12.8A8.5 8.5 0 1 1 11.2 3 6.5 6.5 0 0 0 21 12.8Z" />
                    </svg>
                </button>

                <slot name="topbar-actions" />
            </header>

            <div class="dashboard-content content">
                <slot />
            </div>
        </main>
    </div>
</template>

<style scoped>
.dashboard-shell {
    --bg: #0f172a;
    --surface: #16213a;
    --surface-2: #1d2a47;
    --surface-3: #0b1220;
    --border: rgba(255, 255, 255, .08);
    --ink: #e6edf7;
    --ink-soft: #9aa8bf;
    --ink-faint: #64748b;
    --primary: #22d3ee;
    --primary-strong: #38e4fb;
    --primary-tint: rgba(34, 211, 238, .12);
    --accent: #fbbf24;
    --accent-tint: rgba(251, 191, 36, .13);
    --success: #34d399;
    --success-tint: rgba(52, 211, 153, .13);
    --warning: #fbbf24;
    --warning-tint: rgba(251, 191, 36, .13);
    --danger: #f87171;
    --danger-tint: rgba(248, 113, 113, .13);
    --st-pending: #fbbf24;
    --st-pending-tint: rgba(251, 191, 36, .13);
    --st-approved: #38bdf8;
    --st-approved-tint: rgba(56, 189, 248, .13);
    --st-courier: #a78bfa;
    --st-courier-tint: rgba(167, 139, 250, .13);
    --st-delivered: #34d399;
    --st-delivered-tint: rgba(52, 211, 153, .13);
    --st-returned: #f87171;
    --st-returned-tint: rgba(248, 113, 113, .13);
    --shadow: 0 18px 48px rgba(0, 0, 0, .18);
    width: 100%;
    height: 100dvh;
    min-height: 100vh;
    display: grid;
    grid-template-columns: 242px minmax(0, 1fr);
    overflow: hidden;
    color: var(--ink);
    background: var(--bg);
}

.dashboard-shell.dashboard-theme-light {
    --bg: #edf4f6;
    --surface: #ffffff;
    --surface-2: #f1f6f8;
    --surface-3: #e1ebef;
    --border: rgba(15, 39, 60, .10);
    --ink: #102a43;
    --ink-soft: #587086;
    --ink-faint: #7890a4;
    --primary: #0891b2;
    --primary-strong: #0e7490;
    --primary-tint: rgba(8, 145, 178, .11);
    --accent: #d97706;
    --accent-tint: rgba(217, 119, 6, .12);
    --success: #059669;
    --success-tint: rgba(5, 150, 105, .12);
    --warning: #d97706;
    --warning-tint: rgba(217, 119, 6, .12);
    --danger: #dc2626;
    --danger-tint: rgba(220, 38, 38, .11);
    --st-pending: #d97706;
    --st-pending-tint: rgba(217, 119, 6, .12);
    --st-approved: #0284c7;
    --st-approved-tint: rgba(2, 132, 199, .12);
    --st-courier: #7c3aed;
    --st-courier-tint: rgba(124, 58, 237, .12);
    --st-delivered: #059669;
    --st-delivered-tint: rgba(5, 150, 105, .12);
    --st-returned: #dc2626;
    --st-returned-tint: rgba(220, 38, 38, .11);
    --shadow: 0 18px 46px rgba(15, 39, 60, .10);
}

.dashboard-sidebar {
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border-inline-end: 1px solid var(--border);
    background: var(--surface-3);
}

.dashboard-brand {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 20px 18px;
    border-bottom: 1px solid var(--border);
}

.dashboard-brand-mark {
    width: 40px;
    height: 40px;
    display: grid;
    place-items: center;
    flex: none;
    border-radius: 12px;
    overflow: hidden;
    color: #062033;
    background: #fff;
}

.dashboard-brand-mark img {
    width: 100%;
    height: 100%;
    display: block;
    object-fit: contain;
    padding: 3px;
}

.dashboard-brand b,
.dashboard-brand span {
    display: block;
}

.dashboard-brand b {
    font-size: 14px;
    font-weight: 900;
    line-height: 1.35;
}

.dashboard-brand span {
    margin-top: 2px;
    color: var(--ink-faint);
    font-size: 10px;
    font-weight: 700;
}

.dashboard-live,
.dashboard-top-live {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--ink-soft);
    font-size: 10.5px;
    font-weight: 800;
    white-space: nowrap;
}

.dashboard-live i,
.dashboard-top-live i {
    width: 8px;
    height: 8px;
    flex: none;
    border-radius: 50%;
    background: var(--success);
    box-shadow: 0 0 0 4px var(--success-tint);
    animation: dashboard-pulse 1.6s ease-in-out infinite;
}

.dashboard-nav {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 14px 10px;
}

.dashboard-nav-item {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 11px;
    margin-bottom: 4px;
    padding: 11px 13px;
    border: 0;
    border-radius: 11px;
    color: var(--ink-soft);
    background: transparent;
    font: inherit;
    font-size: 12.5px;
    font-weight: 800;
    text-align: start;
    transition: background .15s, color .15s;
}

.dashboard-nav-item:hover {
    color: var(--ink);
    background: var(--surface);
}

.dashboard-nav-item.active {
    color: var(--primary);
    background: linear-gradient(90deg, rgba(34, 211, 238, .16), rgba(34, 211, 238, .04));
}

.dashboard-nav-badge {
    min-width: 19px;
    margin-inline-start: auto;
    padding: 2px 5px;
    border-radius: 999px;
    color: #fff;
    background: var(--danger);
    font-size: 9px;
    font-weight: 900;
    line-height: 1.2;
    text-align: center;
}

[dir="ltr"] .dashboard-nav-item.active {
    background: linear-gradient(270deg, rgba(34, 211, 238, .16), rgba(34, 211, 238, .04));
}

.dashboard-sidebar-footer {
    padding: 14px;
    border-top: 1px solid var(--border);
}

.dashboard-operator {
    display: flex;
    align-items: center;
    gap: 9px;
}

.dashboard-operator {
    padding: 0 2px 12px;
}

.dashboard-operator b,
.dashboard-operator span {
    display: block;
}

.dashboard-operator b {
    font-size: 11.5px;
    font-weight: 800;
}

.dashboard-operator span {
    margin-top: 1px;
    color: var(--ink-faint);
    font-size: 9.5px;
    font-weight: 700;
}

.dashboard-avatar {
    width: 34px;
    height: 34px;
    display: grid;
    place-items: center;
    flex: none;
    border-radius: 50%;
    color: #062033;
    background: linear-gradient(135deg, var(--primary), #0ea5e9);
    font-size: 12px;
    font-weight: 900;
}

.dashboard-logout {
    width: 100%;
    min-height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--ink-soft);
    background: transparent;
    font: inherit;
    font-size: 12px;
    font-weight: 800;
    transition: border-color .15s, color .15s, background .15s;
}

.dashboard-logout:hover {
    border-color: rgba(248, 113, 113, .44);
    color: var(--danger);
    background: var(--danger-tint);
}

.dashboard-main {
    min-width: 0;
    min-height: 0;
    display: grid;
    grid-template-rows: 62px minmax(0, 1fr);
}

.dashboard-topbar {
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0 24px;
    border-bottom: 1px solid var(--border);
    background: var(--surface-3);
}

.dashboard-menu-toggle {
    width: 39px;
    height: 39px;
    display: none;
    place-items: center;
    flex: none;
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--ink-soft);
    background: var(--surface);
}

.dashboard-title {
    min-width: 0;
}

.dashboard-title h1 {
    overflow: hidden;
    color: var(--ink);
    font-size: 16px;
    font-weight: 900;
    line-height: 1.35;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.dashboard-top-spacer {
    flex: 1;
}

.dashboard-language {
    min-height: 37px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 0 8px;
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--ink-soft);
    background: var(--surface);
}

.dashboard-language select {
    max-width: 82px;
    border: 0;
    outline: none;
    color: var(--ink-soft);
    background: transparent;
    font: inherit;
    font-size: 10.5px;
    font-weight: 800;
    cursor: pointer;
}

.dashboard-language option {
    color: #102a43;
}

.dashboard-icon-button {
    width: 38px;
    height: 38px;
    display: grid;
    place-items: center;
    flex: none;
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--ink-soft);
    background: var(--surface);
    transition: background .15s, color .15s, transform .15s;
}

.dashboard-icon-button:hover {
    color: var(--primary);
    background: var(--primary-tint);
}

.dashboard-icon-button:active {
    transform: scale(.96);
}

.dashboard-content {
    min-height: 0;
    overflow: auto;
    padding: 24px 26px 32px;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
}

.dashboard-content::-webkit-scrollbar {
    width: 9px;
}

.dashboard-content::-webkit-scrollbar-thumb {
    border: 2px solid transparent;
    border-radius: 999px;
    background: var(--surface-2);
    background-clip: padding-box;
}

.dashboard-backdrop {
    display: none;
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

@keyframes dashboard-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: .4; }
}

@media (max-width: 980px) {
    .dashboard-shell {
        grid-template-columns: minmax(0, 1fr);
    }

    .dashboard-sidebar {
        position: fixed;
        z-index: 60;
        inset-block: 0;
        inset-inline-start: 0;
        width: min(84vw, 286px);
        transform: translateX(108%);
        box-shadow: var(--shadow);
        transition: transform .2s ease;
    }

    [dir="ltr"] .dashboard-sidebar {
        transform: translateX(-108%);
    }

    .dashboard-menu-open .dashboard-sidebar {
        transform: translateX(0);
    }

    .dashboard-backdrop {
        position: fixed;
        z-index: 50;
        inset: 0;
        border: 0;
        background: rgba(4, 12, 26, .52);
    }

    .dashboard-menu-open .dashboard-backdrop {
        display: block;
    }

    .dashboard-menu-toggle {
        display: grid;
    }

    .dashboard-topbar {
        padding: 0 16px;
    }

    .dashboard-content {
        padding: 18px 14px 28px;
    }
}

@media (max-width: 680px) {
    .dashboard-main {
        grid-template-rows: 63px minmax(0, 1fr);
    }

    .dashboard-topbar {
        gap: 8px;
        padding: 0 12px;
    }

    .dashboard-title h1 {
        font-size: 14px;
    }

    .dashboard-top-live {
        display: none;
    }

    .dashboard-language {
        min-height: 36px;
        padding: 0 6px;
    }

    .dashboard-language select {
        max-width: 57px;
    }

    .dashboard-icon-button,
    .dashboard-menu-toggle {
        width: 36px;
        height: 36px;
    }
}
</style>
