<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Flash from './Flash.vue'
import { subscribeToUserRealtime } from '../Utils/realtimeChat'

const props = defineProps({
    title: { type: String, default: '' },
    branchMode: { type: Boolean, default: false },
})

const page = usePage()
const isMenuOpen = ref(false)
const isSidebarCollapsed = ref(savedSidebarCollapsed())
const isCompactViewport = ref(false)
let viewportQuery = null
let unsubscribeDashboardRealtime = () => {}
const user = computed(() => page.props.auth?.user)
const adminBadges = computed(() => page.props.adminBadges || {})
const dashboardActivityFeed = computed(() => page.props.dashboardActivityFeed || [])
const activityMenuOpen = ref(false)
const isSuperAdmin = computed(() => Boolean(user.value?.is_super_admin))
const adminPermissions = computed(() => user.value?.admin_permissions || page.props.adminPermissions || page.props.admin_permissions || {})
// A branch manager deliberately uses the same dashboard shell as the platform
// owner. The distinction is server-enforced data scope, not a second, reduced
// client application. `branchMode` remains for the legacy owner portal while
// the role check covers every normal /dashboard screen for a branch manager.
const isBranchManager = computed(() => user.value?.role === 'branch_manager')
const branchDashboard = computed(() => page.props.branchDashboard || page.props.branch_dashboard || page.props.branchScope || page.props.branch_scope || {})
const isBranchScopedDashboard = computed(() => isBranchManager.value && Boolean(branchDashboard.value?.active))
const isLegacyBranchPortal = computed(() => props.branchMode)
// A branch account without a branch permission profile is the principal
// manager.  The shared server flag deliberately distinguishes it from a
// branch employee who uses the same `branch_manager` login role but has a
// restricted profile.
const isPrincipalBranchManager = computed(() => {
    const principal = branchDashboard.value?.is_principal_manager ?? branchDashboard.value?.isPrincipalManager

    return isBranchScopedDashboard.value && (principal === true || principal === 1 || principal === '1')
})
const scopedBranch = computed(() => branchDashboard.value?.branch || branchDashboard.value?.current_branch || branchDashboard.value?.currentBranch || {})
const branchName = computed(() => {
    const branch = scopedBranch.value

    return branch?.name
        || branch?.name_ar
        || branch?.name_en
        || branch?.name_ku
        || branchDashboard.value?.branch_name
        || branchDashboard.value?.branchName
        || user.value?.branch_name
        || ''
})
const branchScopeLabel = computed(() => branchName.value
    ? `${localized('Branch scope')}: ${branchName.value}`
    : localized('Branch scope'))

// Navigation is only a convenience; the server independently protects every
// route.  A restricted dashboard admin needs `view` for the matching module
// before the link is exposed, while the audited super administrator sees the
// complete dashboard navigation.
function canViewModule(module) {
    if (isSuperAdmin.value) return true

    const actions = adminPermissions.value?.[module]
    if (Array.isArray(actions)) return actions.includes('view')
    if (actions && typeof actions === 'object') return Boolean(actions.view)

    return false
}

// Settings is a tabbed page shared by several narrowly scoped permissions.
// Point its sidebar link at the first tab this operator may actually open so
// a content- or governorate-only account never lands on the hidden general
// tab first.
const firstSettingsTab = computed(() => {
    if (isSuperAdmin.value || canViewModule('settings')) return 'general'
    if (canViewModule('provinces')) return 'provinces'
    if (canViewModule('content')) return 'slider'

    return null
})
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

// Unlike the temporary mobile drawer, this is a deliberate desktop layout
// preference. Keep it in the browser so opening another dashboard page does
// not make the user reopen the sidebar every time.
function savedSidebarCollapsed() {
    try {
        return window.localStorage.getItem('almunjaz-admin-sidebar') === 'collapsed'
    } catch {
        return false
    }
}

function persistSidebarCollapsed(collapsed) {
    try {
        window.localStorage.setItem('almunjaz-admin-sidebar', collapsed ? 'collapsed' : 'expanded')
    } catch {
        // A blocked browser storage session can still use the control for the
        // current page; only persistence between visits is unavailable.
    }
}

const theme = ref(savedTheme() === 'light' ? 'light' : 'dark')
const locale = ref(page.props.locale || user.value?.locale || 'ar')
const currentPath = computed(() => new URL(page.url, window.location.origin).pathname.replace(/\/$/, ''))
// The server still validates every branch_id. Keeping a super-admin's
// selection while they move between operational modules makes the filter a
// coherent audit context instead of a one-page-only control.
const selectedBranchFilterId = computed(() => {
    if (!isSuperAdmin.value) return null

    const value = new URL(page.url, window.location.origin).searchParams.get('branch_id')

    return value && /^\d+$/.test(value) ? value : null
})
const branchFilterRoutes = new Set([
    'admin.dashboard', 'admin.orders', 'admin.branches', 'admin.merchants', 'admin.couriers', 'admin.couriers.locations',
    'admin.finance', 'admin.cashboxes', 'admin.pricing', 'admin.reports', 'admin.transfers',
    'admin.chat', 'admin.notifications', 'admin.loyalty', 'admin.employees', 'admin.permissions',
    'admin.settings',
])
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
    'Cashboxes': { ar: 'الصناديق', en: 'Cashboxes', ku: 'سندووقەکان' },
    'Reports': { ar: 'التقارير والتحليلات', en: 'Reports & analytics', ku: 'ڕاپۆرت و شیکاری' },
    'Operational Team': { ar: 'الفريق والصلاحيات', en: 'Operational team', ku: 'تیمی کارپێکردن' },
    'System Employees': { ar: 'موظفو النظام', en: 'System Employees', ku: 'کارمەندانی سیستەم' },
    'Permissions': { ar: 'الصلاحيات', en: 'Permissions', ku: 'دەسەڵاتەکان' },
    'Courier Points': { ar: 'نقاط المندوب', en: 'Courier points', ku: 'خاڵەکانی گەیەنەر' },
    'Courier locations': { ar: 'مواقع المندوبين', en: 'Courier locations', ku: 'شوێنەکانی گەیەنەران' },
    'Pricing': { ar: 'التسعير', en: 'Pricing', ku: 'نرخبەندی' },
    'Platform': { ar: 'المنصة', en: 'Platform', ku: 'پلاتفۆرم' },
    'Transfers': { ar: 'التحويلات', en: 'Transfers', ku: 'گواستنەوەکان' },
    'Branch scope': { ar: 'نطاق الفرع', en: 'Branch scope', ku: 'سنووری لق' },
    'Branch dashboard': { ar: 'لوحة الفرع', en: 'Branch dashboard', ku: 'داشبۆردی لق' },
    'My branch and funds': { ar: 'فرعي والصناديق', en: 'My branch and funds', ku: 'لقی من و سندوقەکان' },
    'Branch orders': { ar: 'طلبات الفرع', en: 'Branch orders', ku: 'داواکارییەکانی لق' },
    'Branch merchants': { ar: 'تجار الفرع', en: 'Branch merchants', ku: 'بازرگانانی لق' },
    'Branch couriers': { ar: 'مندوبي الفرع', en: 'Branch couriers', ku: 'گەیەنەرانی لق' },
    'Branch courier locations': { ar: 'مواقع مندوبي الفرع', en: 'Branch courier locations', ku: 'شوێنەکانی گەیەنەرانی لق' },
    'Branch finance': { ar: 'مالية الفرع', en: 'Branch finance', ku: 'دارایی لق' },
    'Branch cashboxes': { ar: 'صناديق الفرع', en: 'Branch cashboxes', ku: 'سندووقەکانی لق' },
    'Branch pricing': { ar: 'تسعير الفرع', en: 'Branch pricing', ku: 'نرخبەندی لق' },
    'Branch reports': { ar: 'تقارير الفرع', en: 'Branch reports', ku: 'ڕاپۆرتەکانی لق' },
    'Branch profile': { ar: 'ملف الفرع', en: 'Branch profile', ku: 'پڕۆفایلی لق' },
    'Branch transfers': { ar: 'تحويلات الفرع', en: 'Branch transfers', ku: 'گواستنەوەکانی لق' },
    'Branch chat': { ar: 'دردشة الفرع', en: 'Branch chat', ku: 'چاتی لق' },
    'Branch notifications': { ar: 'إشعارات الفرع', en: 'Branch notifications', ku: 'ئاگادارکردنەوەکانی لق' },
    'Branch courier points': { ar: 'نقاط مندوبي الفرع', en: 'Branch courier points', ku: 'خاڵەکانی گەیەنەرانی لق' },
    'Branch employees': { ar: 'موظفو الفرع', en: 'Branch employees', ku: 'کارمەندانی لق' },
    'Branch permissions': { ar: 'صلاحيات الفرع', en: 'Branch permissions', ku: 'دەسەڵاتەکانی لق' },
    'Branch settings': { ar: 'إعدادات الفرع', en: 'Branch settings', ku: 'ڕێکخستنەکانی لق' },
    'Branch management': { ar: 'إدارة الفرع', en: 'Branch management', ku: 'بەڕێوەبردنی لق' },
    'Live branch data': { ar: 'بيانات الفرع المباشرة', en: 'Live branch data', ku: 'داتای ڕاستەوخۆی لق' },
}

const availableLocales = computed(() => (page.props.locales?.length ? page.props.locales : ['ar', 'en', 'ku']))
const pageTitle = computed(() => {
    if (props.title === 'الفروع') return locale.value === 'en' ? 'Branches' : locale.value === 'ku' ? 'لقەکان' : 'الفروع'
    return t(props.title || 'Dashboard')
})

const nav = computed(() => {
    // Preserve the separate, existing owner portal. The full dashboard mode
    // below is intentionally reserved for a branch_manager account.
    if (isLegacyBranchPortal.value) {
        return [
            { label: t('Dashboard'), icon: 'grid', route: 'admin.branch.portal' },
        ].map((item) => ({ ...item, url: route(item.route) }))
    }

    const items = [
        // The normal dashboard route carries a branch-filtered response for
        // a branch manager. The shell never points them at the legacy portal.
        { label: t('Dashboard'), branchLabel: localized('Branch dashboard'), icon: 'grid', route: 'admin.dashboard', superOnly: true, principalOnly: true },
        { label: t('Orders'), branchLabel: localized('Branch orders'), icon: 'box', route: 'admin.orders', module: 'orders' },
        { label: localized('Branches and Funds'), branchLabel: localized('My branch and funds'), icon: 'building', route: 'admin.branches', module: 'branches', principalOnly: true },
        { label: t('Merchants'), branchLabel: localized('Branch merchants'), icon: 'shop', route: 'admin.merchants', module: 'merchants' },
        { label: t('Couriers'), branchLabel: localized('Branch couriers'), icon: 'bike', route: 'admin.couriers', module: 'couriers' },
        { label: localized('Courier Points'), branchLabel: localized('Branch courier points'), icon: 'award', route: 'admin.loyalty', module: 'loyalty' },
        { label: localized('Courier locations'), branchLabel: localized('Branch courier locations'), icon: 'pin', route: 'admin.couriers.locations', module: 'courier_locations' },
        { label: t('Finance'), branchLabel: localized('Branch finance'), icon: 'card', route: 'admin.finance', module: 'finance', badge: adminBadges.value.finance },
        { label: localized('Reports'), branchLabel: localized('Branch reports'), icon: 'chart', route: 'admin.reports', module: 'reports' },
        { label: t('Chat'), branchLabel: localized('Branch chat'), icon: 'chat', route: 'admin.chat', module: 'chat', badge: adminBadges.value.chat },
        { label: t('Notifications'), branchLabel: localized('Branch notifications'), icon: 'bell', route: 'admin.notifications', module: 'notifications', badge: adminBadges.value.notifications },
        // Only the branch's principal manager administers local employees
        // and permission profiles. The server scopes every query and mutation
        // by the current branch.
        { label: localized('System Employees'), branchLabel: localized('Branch employees'), icon: 'users', route: 'admin.employees', superOnly: true, principalOnly: true },
        { label: localized('Permissions'), branchLabel: localized('Branch permissions'), icon: 'shield', route: 'admin.permissions', superOnly: true, principalOnly: true },
        // Global province/network tabs are suppressed by the server in branch
        // mode; this link lands on the branch-safe settings representation.
        { label: t('Settings'), branchLabel: localized('Branch settings'), icon: 'settings', route: 'admin.settings', modules: ['settings', 'content', 'provinces'] },
    ]

    return items
        .filter((item) => {
            if (isBranchScopedDashboard.value) {
                if (item.branchHidden) return false
                if (item.principalOnly) return isPrincipalBranchManager.value

                // The principal sees the complete local operating dashboard;
                // a branch employee sees only modules whose profile grants
                // `view`. This is navigation ergonomics only: each route is
                // independently authorized and branch-scoped on the server.
                return isPrincipalBranchManager.value
                    || (item.modules || [item.module]).some((module) => canViewModule(module))
            }

            return item.superOnly
                ? isSuperAdmin.value
                : (item.modules || [item.module]).some((module) => canViewModule(module))
        })
        .map((item) => {
            const params = {}

            if (item.route === 'admin.settings' && firstSettingsTab.value && firstSettingsTab.value !== 'general') {
                params.tab = firstSettingsTab.value
            }
            if (selectedBranchFilterId.value && branchFilterRoutes.has(item.route)) {
                params.branch_id = selectedBranchFilterId.value
            }

            return {
                ...item,
                label: isBranchScopedDashboard.value ? (item.branchLabel || item.label) : item.label,
                url: Object.keys(params).length ? route(item.route, params) : route(item.route),
            }
        })
})

function preferenceRoute(kind) {
    return props.branchMode ? `admin.branch.preferences.${kind}` : `admin.preferences.${kind}`
}

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

const canViewNotifications = computed(() => isSuperAdmin.value || canViewModule('notifications'))
const canViewChat = computed(() => isSuperAdmin.value || canViewModule('chat'))

function toggleActivityMenu() {
    activityMenuOpen.value = !activityMenuOpen.value
}

function openNotifications() {
    activityMenuOpen.value = false
    router.visit(route('admin.notifications'))
}

function openChatNotification(url) {
    activityMenuOpen.value = false
    router.visit(url || route('admin.chat'))
}

function refreshChatNotifications() {
    if (! canViewChat.value) return

    router.reload({
        only: ['adminBadges', 'dashboardActivityFeed'],
        preserveScroll: true,
        preserveState: true,
    })
}

function navigate(url) {
    isMenuOpen.value = false
    router.visit(url)
}

const sidebarIsOpen = computed(() => isCompactViewport.value
    ? isMenuOpen.value
    : !isSidebarCollapsed.value)
const sidebarToggleLabel = computed(() => sidebarIsOpen.value ? t('Close') : t('Open'))

function syncViewportMode() {
    isCompactViewport.value = Boolean(viewportQuery?.matches)
    if (! isCompactViewport.value) isMenuOpen.value = false
}

function toggleSidebar() {
    if (isCompactViewport.value) {
        isMenuOpen.value = !isMenuOpen.value

        return
    }

    isSidebarCollapsed.value = !isSidebarCollapsed.value
    persistSidebarCollapsed(isSidebarCollapsed.value)
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

    // This is deliberately not an Inertia visit.  A theme toggle must keep
    // the current dashboard screen mounted and save only the preference.
    window.axios.post(route(preferenceRoute('theme')), { theme: next }).catch(() => {
        // An earlier request can fail after the user has toggled again; only
        // roll back when this failed request is still the active preference.
        if (theme.value !== next) return

        theme.value = previous
        applyTheme(previous)
        persistTheme(previous)
    })
}

function changeLocale(event) {
    const next = event.target.value
    if (next === locale.value) return

    const previous = locale.value
    locale.value = next
    applyLocale(next)

    router.post(route(preferenceRoute('locale')), { locale: next }, {
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
        award: 'm12 3 2.45 4.96 5.48.8-3.96 3.86.93 5.46L12 15.5l-4.9 2.58.93-5.46L4.07 8.76l5.48-.8L12 3Zm-4 16 1.2 2 2.8-1.3 2.8 1.3 1.2-2',
        pin: 'M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Zm-8 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
        users: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m14-11a4 4 0 1 0 0-8m-6 4a4 4 0 1 0 0-8',
        cashbox: 'M3 7h18v12H3zM7 7V4h10v3m-9 5h.01M12 12h.01M16 12h.01M8 16h8',
        chart: 'M4 20V10m6 10V4m6 16v-7m6 7V7',
        card: 'M3 6h18v12H3zM3 10h18M7 15h4',
        chat: 'M21 12a8 8 0 0 1-8 8H4l1.5-3.5A8 8 0 1 1 21 12Z',
        bell: 'M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6Zm5 11a2 2 0 0 0 4 0',
        image: 'M4 5.5A1.5 1.5 0 0 1 5.5 4h13A1.5 1.5 0 0 1 20 5.5v13A1.5 1.5 0 0 1 18.5 20h-13A1.5 1.5 0 0 1 4 18.5v-13ZM7 16l3.3-3.3a1.3 1.3 0 0 1 1.8 0l1.9 1.9 1.5-1.5a1.3 1.3 0 0 1 1.8 0L20 16M9 9h.01',
        star: 'm12 3 2.75 5.57 6.15.9-4.45 4.34 1.05 6.13L12 17.05 6.5 19.94l1.05-6.13L3.1 9.47l6.15-.9L12 3Z',
        shield: 'M12 3 20 6v5c0 5.14-3.41 8.9-8 10-4.59-1.1-8-4.86-8-10V6l8-3Zm-3.2 9.1 2.1 2.1 4.4-4.4',
        transfer: 'M7 7h12l-3-3m3 3-3 3M17 17H5l3 3m-3-3 3-3',
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

    viewportQuery = window.matchMedia('(max-width: 980px)')
    syncViewportMode()
    viewportQuery.addEventListener('change', syncViewportMode)

    if (canViewChat.value && user.value?.id) {
        unsubscribeDashboardRealtime = subscribeToUserRealtime(user.value.id, (event) => {
            if (event === 'chat.message') refreshChatNotifications()
        })
    }
})

onBeforeUnmount(() => {
    viewportQuery?.removeEventListener('change', syncViewportMode)
    unsubscribeDashboardRealtime()
})
</script>

<template>
    <div class="dashboard-shell" :class="[`dashboard-theme-${theme}`, { 'dashboard-menu-open': isMenuOpen, 'dashboard-sidebar-collapsed': isSidebarCollapsed }]">
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
                        <span>{{ isBranchScopedDashboard ? localized('Branch management') : t('Platform management') }}</span>
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
                <button
                    class="dashboard-menu-toggle"
                    type="button"
                    :aria-label="sidebarToggleLabel"
                    :aria-expanded="sidebarIsOpen"
                    :title="sidebarToggleLabel"
                    @click="toggleSidebar"
                >
                    <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="icon('menu')" />
                    </svg>
                </button>

                <div class="dashboard-title">
                    <h1>{{ pageTitle }}</h1>
                </div>

                <span v-if="isBranchScopedDashboard" class="dashboard-scope-pill">{{ branchScopeLabel }}</span>

                <div class="dashboard-top-spacer" />
                <span class="dashboard-top-live"><i /> {{ isBranchScopedDashboard ? localized('Live branch data') : t('Live data from app') }}</span>

                <div v-if="canViewChat" class="dashboard-notification-wrap">
                    <button
                        class="dashboard-icon-button dashboard-notification-button"
                        type="button"
                        :aria-label="t('Notifications')"
                        :aria-expanded="activityMenuOpen"
                        title="رسائل الدعم الجديدة"
                        @click="toggleActivityMenu"
                    >
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" />
                        </svg>
                        <b v-if="adminBadges.chat" class="dashboard-notification-badge">{{ adminBadges.chat > 9 ? '9+' : adminBadges.chat }}</b>
                    </button>

                    <section v-if="activityMenuOpen" class="dashboard-activity-menu" role="dialog" aria-label="إشعارات الدردشة">
                        <header>
                            <div><small>مركز الدردشة</small><b>رسائل الدعم الجديدة</b></div>
                            <button type="button" aria-label="إغلاق" @click="activityMenuOpen = false">×</button>
                        </header>
                        <div v-if="dashboardActivityFeed.length" class="dashboard-activity-list">
                            <button v-for="activity in dashboardActivityFeed" :key="activity.id" type="button" class="dashboard-activity-row" @click="openChatNotification(activity.url)">
                                <i aria-hidden="true">●</i>
                                <div><b>{{ activity.title }}</b><p>{{ activity.detail }}</p><small><span v-if="activity.actor">{{ activity.actor }} · </span>{{ activity.created_at }}</small></div>
                            </button>
                        </div>
                        <p v-else class="dashboard-activity-empty">لا توجد رسائل دعم جديدة حالياً.</p>
                        <button class="dashboard-activity-all" type="button" @click="openChatNotification()">فتح الدردشة</button>
                    </section>
                </div>

                <label class="dashboard-language">
                    <span class="sr-only">{{ t('Language') }}</span>
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" />
                    </svg>
                    <PopupSelect :model-value="locale" :aria-label="t('Language')" @change="changeLocale">
                        <option v-for="code in availableLocales" :key="code" :value="code">{{ localeName(code) }}</option>
                    </PopupSelect>
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
    /* One desktop-wide display scale. Keeping it in a variable makes a
       rollback to the original density a one-value change. */
    --dashboard-ui-scale: 1;
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
    zoom: var(--dashboard-ui-scale);
    transition: grid-template-columns .22s ease;
}

.dashboard-shell.dashboard-sidebar-collapsed {
    grid-template-columns: 0 minmax(0, 1fr);
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
    transition: transform .22s ease, opacity .16s ease, border-color .16s ease;
}

.dashboard-sidebar-collapsed .dashboard-sidebar {
    opacity: 0;
    pointer-events: none;
    transform: translateX(108%);
    border-color: transparent;
}

[dir="ltr"] .dashboard-sidebar-collapsed .dashboard-sidebar {
    transform: translateX(-108%);
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
    display: grid;
    place-items: center;
    flex: none;
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--ink-soft);
    background: var(--surface);
    cursor: pointer;
    transition: background .15s, color .15s, transform .15s;
}

.dashboard-menu-toggle:hover {
    color: var(--primary);
    background: var(--primary-tint);
}

.dashboard-menu-toggle:active {
    transform: scale(.96);
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

.dashboard-scope-pill {
    max-width: min(260px, 24vw);
    overflow: hidden;
    padding: 5px 9px;
    border: 1px solid color-mix(in srgb, var(--primary) 34%, var(--border));
    border-radius: 999px;
    color: var(--primary);
    background: var(--primary-tint);
    font-size: 10px;
    font-weight: 850;
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

.dashboard-notification-wrap { position: relative; flex: none; }
.dashboard-notification-button { position: relative; }
.dashboard-notification-badge { position: absolute; top: -5px; inset-inline-end: -5px; min-width: 16px; height: 16px; display: grid; place-items: center; padding: 0 3px; border: 2px solid var(--surface-3); border-radius: 999px; color: #fff; background: var(--danger); font-size: 8px; font-weight: 900; line-height: 1; }
.dashboard-activity-menu { position: absolute; z-index: 75; top: calc(100% + 9px); inset-inline-end: 0; width: min(390px, calc(100vw - 26px)); overflow: hidden; border: 1px solid var(--border); border-radius: 14px; color: var(--ink); background: var(--surface); box-shadow: 0 20px 48px rgba(2, 10, 25, .28); }
.dashboard-activity-menu > header { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 13px; border-bottom: 1px solid var(--border); background: var(--surface-2); }
.dashboard-activity-menu header > div { display: grid; gap: 2px; }.dashboard-activity-menu header small { color: var(--primary); font-size: 8.5px; font-weight: 900; }.dashboard-activity-menu header b { font-size: 12px; font-weight: 900; }.dashboard-activity-menu header button { width: 27px; height: 27px; border: 0; border-radius: 8px; color: var(--ink-soft); background: var(--surface); font: 900 19px/1 sans-serif; cursor: pointer; }
.dashboard-activity-list { max-height: min(54vh, 390px); overflow-y: auto; }.dashboard-activity-row { display: grid; width: 100%; grid-template-columns: 12px minmax(0, 1fr); gap: 8px; padding: 10px 13px; border: 0; border-bottom: 1px solid var(--border); color: inherit; background: transparent; text-align: start; font: inherit; cursor: pointer; }.dashboard-activity-row:hover,.dashboard-activity-row:focus-visible { background: var(--primary-tint); outline: 0; }.dashboard-activity-row:last-child { border-bottom: 0; }.dashboard-activity-row > i { padding-top: 4px; color: var(--primary); font-size: 9px; font-style: normal; }.dashboard-activity-row b,.dashboard-activity-row p,.dashboard-activity-row small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }.dashboard-activity-row b { color: var(--ink); font-size: 10.5px; font-weight: 900; }.dashboard-activity-row p { margin: 2px 0; color: var(--ink-soft); font-size: 9.5px; font-weight: 700; }.dashboard-activity-row small { color: var(--ink-faint); font-size: 8.5px; font-weight: 750; }.dashboard-activity-empty { margin: 0; padding: 22px 13px; color: var(--ink-faint); font-size: 10px; font-weight: 750; text-align: center; }.dashboard-activity-all { width: 100%; min-height: 39px; border: 0; border-top: 1px solid var(--border); color: var(--primary-strong); background: var(--surface-2); font: 850 10px var(--font); cursor: pointer; }

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
        --dashboard-ui-scale: 1;
        grid-template-columns: minmax(0, 1fr);
    }

    .dashboard-shell.dashboard-sidebar-collapsed {
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

    /* A saved desktop preference must not disable the mobile drawer. */
    .dashboard-sidebar-collapsed .dashboard-sidebar {
        opacity: 1;
        pointer-events: auto;
        border-color: var(--border);
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

    .dashboard-scope-pill {
        max-width: 34vw;
        padding: 4px 7px;
        font-size: 9px;
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
