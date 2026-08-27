<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Flash from '../../Components/Flash.vue'

const props = defineProps({
    branches: { type: Array, default: () => [] },
    recentOrders: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
})

const page = usePage()
const selectedBranchKey = ref('all')
const theme = ref('light')
const locale = ref('ar')

const copy = {
    ar: {
        portal: 'بوابة الفروع',
        subtitle: 'نظرة آمنة ومركزة على الفروع المخوّلة لك فقط.',
        allBranches: 'كل الفروع المصرّح بها',
        branchDetails: 'تفاصيل الفرع',
        branches: 'الفروع',
        activeBranches: 'الفروع النشطة',
        totalOrders: 'إجمالي الطلبات',
        activeOrders: 'طلبات قيد التشغيل',
        deliveredOrders: 'الطلبات المسلّمة',
        todayOrders: 'طلبات اليوم',
        cashBalance: 'رصيد الصندوق',
        orderActivity: 'حركة الطلبات الأخيرة',
        noOrders: 'لا توجد طلبات ضمن الفرع المحدد حالياً.',
        noBranches: 'لا توجد فروع مخوّلة لهذا الحساب حالياً.',
        access: 'نوع الوصول',
        owner: 'مالك الفرع',
        manager: 'مدير الفرع',
        active: 'نشط',
        inactive: 'موقوف',
        branchCode: 'رمز الفرع',
        contact: 'بيانات الاتصال',
        city: 'المدينة',
        address: 'العنوان',
        phone: 'الهاتف',
        order: 'طلب',
        customer: 'العميل',
        date: 'التاريخ',
        status: 'الحالة',
        value: 'القيمة',
        includedBranches: 'الفروع المرتبطة',
        lastUpdated: 'تُعرض البيانات من النظام التشغيلي مباشرة.',
        logout: 'تسجيل الخروج',
        language: 'اللغة',
        light: 'الوضع الفاتح',
        dark: 'الوضع الداكن',
        noContact: 'لا توجد بيانات اتصال مسجلة',
        selectBranch: 'اختر فرعاً لعرض تفاصيله',
        noData: 'لا توجد بيانات بعد',
        currency: 'د.ع',
        role: 'الدور',
    },
    en: {
        portal: 'Branch Portal',
        subtitle: 'A secure, focused view of only the branches assigned to you.',
        allBranches: 'All authorised branches',
        branchDetails: 'Branch details',
        branches: 'Branches',
        activeBranches: 'Active branches',
        totalOrders: 'Total orders',
        activeOrders: 'Active orders',
        deliveredOrders: 'Delivered orders',
        todayOrders: 'Today’s orders',
        cashBalance: 'Cashbox balance',
        orderActivity: 'Recent order activity',
        noOrders: 'There are no orders for the selected branch yet.',
        noBranches: 'There are no branches assigned to this account yet.',
        access: 'Access level',
        owner: 'Branch owner',
        manager: 'Branch manager',
        active: 'Active',
        inactive: 'Inactive',
        branchCode: 'Branch code',
        contact: 'Contact details',
        city: 'City',
        address: 'Address',
        phone: 'Phone',
        order: 'Order',
        customer: 'Customer',
        date: 'Date',
        status: 'Status',
        value: 'Value',
        includedBranches: 'Related branches',
        lastUpdated: 'Data is shown directly from the operational system.',
        logout: 'Log out',
        language: 'Language',
        light: 'Light mode',
        dark: 'Dark mode',
        noContact: 'No contact information is on record',
        selectBranch: 'Choose a branch to see its details',
        noData: 'No data yet',
        currency: 'IQD',
        role: 'Role',
    },
    ku: {
        portal: 'پۆرتاڵی لقەکان',
        subtitle: 'بینینێکی پارێزراو و سەرنج‌دراو بۆ تەنها ئەو لقانەی مۆڵەتت پێدراوە.',
        allBranches: 'هەموو لقە ڕێگەپێدراوەکان',
        branchDetails: 'وردەکاری لق',
        branches: 'لقەکان',
        activeBranches: 'لقە چالاکەکان',
        totalOrders: 'کۆی داواکارییەکان',
        activeOrders: 'داواکارییە چالاکەکان',
        deliveredOrders: 'داواکارییە گەیەنراوەکان',
        todayOrders: 'داواکارییەکانی ئەمڕۆ',
        cashBalance: 'باڵانسی سندوق',
        orderActivity: 'جووڵەی دوا داواکارییەکان',
        noOrders: 'لە ئێستادا بۆ ئەم لقە هیچ داواکارییەک نییە.',
        noBranches: 'بۆ ئەم هەژمارە هیچ لقێک دیاری نەکراوە.',
        access: 'ئاستی دەسەڵات',
        owner: 'خاوەنی لق',
        manager: 'بەڕێوەبەری لق',
        active: 'چالاک',
        inactive: 'ناچالاک',
        branchCode: 'کۆدی لق',
        contact: 'زانیاری پەیوەندی',
        city: 'شار',
        address: 'ناونیشان',
        phone: 'تەلەفۆن',
        order: 'داواکاری',
        customer: 'کڕیار',
        date: 'بەروار',
        status: 'دۆخ',
        value: 'بەها',
        includedBranches: 'لقە پەیوەندیدارەکان',
        lastUpdated: 'داتا بە ڕاستەوخۆ لە سیستەمی کارپێکردن نیشان دەدرێت.',
        logout: 'چوونەدەرەوە',
        language: 'زمان',
        light: 'دۆخی ڕووناک',
        dark: 'دۆخی تاریک',
        noContact: 'هیچ زانیارییەکی پەیوەندی تۆمار نەکراوە',
        selectBranch: 'لقێک هەڵبژێرە بۆ بینینی وردەکارییەکان',
        noData: 'هێشتا داتا نییە',
        currency: 'د.ع',
        role: 'ڕۆڵ',
    },
}

const statusCopy = {
    pending: { ar: 'بانتظار المراجعة', en: 'Pending review', ku: 'لە چاوەڕوانی پشکنین' },
    approved: { ar: 'تمت الموافقة', en: 'Approved', ku: 'پەسەند کرا' },
    courier: { ar: 'مع المندوب', en: 'With courier', ku: 'لەگەڵ پێگەیەنەر' },
    delivered: { ar: 'تم التسليم', en: 'Delivered', ku: 'گەیەنرا' },
    returned: { ar: 'مرتجع', en: 'Returned', ku: 'گەڕێنراوە' },
    cancelled: { ar: 'ملغى', en: 'Cancelled', ku: 'هەڵوەشاوە' },
}

const user = computed(() => page.props.auth?.user || {})
const branding = computed(() => page.props.branding || {})
const locales = computed(() => page.props.locales || ['ar', 'en', 'ku'])
const selectedBranch = computed(() => props.branches.find((branch) => String(branch.id) === selectedBranchKey.value) || null)
const isAllBranches = computed(() => selectedBranchKey.value === 'all')

const dashboardSummary = computed(() => {
    if (!selectedBranch.value) return props.summary || {}

    return {
        branches: 1,
        activeBranches: selectedBranch.value.is_active ? 1 : 0,
        orders: Number(selectedBranch.value.orders?.total || 0),
        activeOrders: Number(selectedBranch.value.orders?.active || 0),
        deliveredOrders: Number(selectedBranch.value.orders?.delivered || 0),
        todayOrders: Number(selectedBranch.value.orders?.today || 0),
    }
})

const metrics = computed(() => [
    { key: 'branches', label: text('branches'), value: dashboardSummary.value.branches || 0, icon: 'branch' },
    { key: 'active', label: text('activeOrders'), value: dashboardSummary.value.activeOrders || 0, icon: 'bolt' },
    { key: 'delivered', label: text('deliveredOrders'), value: dashboardSummary.value.deliveredOrders || 0, icon: 'check' },
    { key: 'today', label: text('todayOrders'), value: dashboardSummary.value.todayOrders || 0, icon: 'calendar' },
])

const visibleOrders = computed(() => {
    if (isAllBranches.value) return props.recentOrders
    const branchId = Number(selectedBranchKey.value)
    return props.recentOrders.filter((order) => order.branches?.some((branch) => Number(branch.id) === branchId))
})

function text(key) {
    return copy[locale.value]?.[key] || copy.ar[key] || key
}

function localizedBranch(branch) {
    if (!branch) return ''
    return branch[`name_${locale.value}`] || branch.name || branch.name_ar || branch.name_en || branch.name_ku || branch.code
}

function formatMoney(value) {
    return `${new Intl.NumberFormat('en-US', { numberingSystem: 'latn', maximumFractionDigits: 0 }).format(Number(value || 0))} ${text('currency')}`
}

function formatDate(value) {
    if (!value) return '—'
    try {
        const language = locale.value === 'ar' ? 'ar-IQ-u-nu-latn' : locale.value === 'ku' ? 'ku-IQ-u-nu-latn' : 'en-US'
        return new Intl.DateTimeFormat(language, { day: 'numeric', month: 'short', year: 'numeric', numberingSystem: 'latn' }).format(new Date(`${value}T00:00:00`))
    } catch (_) {
        return value
    }
}

function statusLabel(status) {
    return statusCopy[status]?.[locale.value] || statusCopy[status]?.ar || status || '—'
}

function statusTone(status) {
    return {
        pending: 'pending',
        approved: 'approved',
        courier: 'courier',
        delivered: 'delivered',
        returned: 'returned',
        cancelled: 'returned',
    }[status] || 'default'
}

function accessLabel(role) {
    return role === 'owner' ? text('owner') : text('manager')
}

function selectBranch(branch) {
    selectedBranchKey.value = String(branch.id)
}

function applyTheme(value) {
    document.documentElement.dataset.theme = value
    document.documentElement.lang = locale.value
    document.documentElement.dir = locale.value === 'en' ? 'ltr' : 'rtl'
}

function toggleTheme() {
    const previous = theme.value
    const next = previous === 'dark' ? 'light' : 'dark'
    theme.value = next
    applyTheme(next)
    router.post(route('admin.branch.preferences.theme'), { theme: next }, {
        preserveScroll: true,
        preserveState: true,
        onError: () => {
            theme.value = previous
            applyTheme(previous)
        },
    })
}

function changeLocale(event) {
    const previous = locale.value
    const next = event.target.value
    if (!locales.value.includes(next) || next === previous) return

    locale.value = next
    applyTheme(theme.value)
    router.post(route('admin.branch.preferences.locale'), { locale: next }, {
        preserveScroll: true,
        onError: () => {
            locale.value = previous
            applyTheme(theme.value)
        },
        onSuccess: () => window.location.reload(),
    })
}

function logout() {
    router.post(route('logout'))
}

function icon(name) {
    return {
        branch: 'M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16M3 21h18M9 7h.01M13 7h.01M9 11h.01M13 11h.01M9 15h.01M13 15h.01',
        bolt: 'm13 2-9 12h7l-1 8 9-12h-7l1-8Z',
        check: 'M20 6 9 17l-5-5',
        calendar: 'M7 3v3m10-3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z',
        logout: 'M10 17l5-5-5-5m5 5H3m7 9H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4',
        moon: 'M21 12.8A8.5 8.5 0 1 1 11.2 3 6.5 6.5 0 0 0 21 12.8Z',
        sun: 'M12 3v2m0 14v2m9-9h-2M5 12H3m15.36-6.36-1.42 1.42M7.06 16.94l-1.42 1.42m12.72 0-1.42-1.42M7.06 7.06 5.64 5.64M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
    }[name] || ''
}

watch(() => user.value?.theme, (value) => {
    if (value === 'dark' || value === 'light') {
        theme.value = value
        applyTheme(value)
    }
}, { immediate: true })

watch(() => page.props.locale, (value) => {
    if (value && copy[value]) {
        locale.value = value
        applyTheme(theme.value)
    }
}, { immediate: true })

onMounted(() => applyTheme(theme.value))
</script>

<template>
    <div class="branch-portal" :class="`theme-${theme}`">
        <Flash />

        <header class="portal-topbar">
            <div class="portal-brand">
                <span class="brand-mark"><img :src="branding.logo_url" alt="" /></span>
                <div>
                    <b>{{ branding.name || 'المنجز السريع' }}</b>
                    <span>{{ text('portal') }}</span>
                </div>
            </div>

            <div class="portal-top-actions">
                <label class="language-picker">
                    <span class="sr-only">{{ text('language') }}</span>
                    <select :value="locale" :aria-label="text('language')" @change="changeLocale">
                        <option v-for="code in locales" :key="code" :value="code">{{ { ar: 'العربية', en: 'English', ku: 'کوردی' }[code] || code }}</option>
                    </select>
                </label>
                <button class="icon-button" type="button" :aria-label="theme === 'dark' ? text('light') : text('dark')" @click="toggleTheme">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="icon(theme === 'dark' ? 'sun' : 'moon')" /></svg>
                </button>
                <button class="logout-button" type="button" @click="logout">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="icon('logout')" /></svg>
                    <span>{{ text('logout') }}</span>
                </button>
            </div>
        </header>

        <main class="portal-main">
            <section class="portal-hero">
                <div>
                    <span class="eyebrow"><i /> {{ text('access') }} · {{ accessLabel(user.role === 'owner' ? 'owner' : 'manager') }}</span>
                    <h1>{{ text('portal') }}</h1>
                    <p>{{ text('subtitle') }}</p>
                </div>
                <div class="operator-card">
                    <span class="operator-avatar">{{ user.name?.slice(0, 1) || 'م' }}</span>
                    <div><b>{{ user.name }}</b><span>{{ accessLabel(user.role === 'owner' ? 'owner' : 'manager') }}</span></div>
                </div>
            </section>

            <section v-if="branches.length" class="portal-controls" aria-label="Branch selector">
                <label>
                    <span>{{ text('branches') }}</span>
                    <select v-model="selectedBranchKey">
                        <option value="all">{{ text('allBranches') }}</option>
                        <option v-for="branch in branches" :key="branch.id" :value="String(branch.id)">{{ localizedBranch(branch) }} · {{ branch.code }}</option>
                    </select>
                </label>
                <p>{{ text('lastUpdated') }}</p>
            </section>

            <section v-if="branches.length" class="metric-grid" :aria-label="text('portal')">
                <article v-for="metric in metrics" :key="metric.key" class="metric-card">
                    <span class="metric-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="icon(metric.icon)" /></svg></span>
                    <div><strong class="mono">{{ fmt(metric.value) }}</strong><span>{{ metric.label }}</span></div>
                </article>
            </section>

            <section v-if="branches.length" class="portal-layout">
                <div class="branch-area">
                    <div class="section-heading"><div><span class="eyebrow">{{ text('branches') }}</span><h2>{{ isAllBranches ? text('allBranches') : text('branchDetails') }}</h2></div><span class="count-chip">{{ isAllBranches ? branches.length : 1 }}</span></div>

                    <div v-if="isAllBranches" class="branch-grid">
                        <button v-for="branch in branches" :key="branch.id" class="branch-card" type="button" @click="selectBranch(branch)">
                            <span class="branch-status" :class="{ inactive: !branch.is_active }">{{ branch.is_active ? text('active') : text('inactive') }}</span>
                            <div class="branch-card-title"><span class="branch-symbol">⌂</span><div><h3>{{ localizedBranch(branch) }}</h3><p>{{ branch.city || text('noData') }} · {{ branch.code }}</p></div></div>
                            <div class="branch-card-data"><span>{{ text('activeOrders') }}<b class="mono">{{ fmt(branch.orders?.active || 0) }}</b></span><span>{{ text('deliveredOrders') }}<b class="mono">{{ fmt(branch.orders?.delivered || 0) }}</b></span><span>{{ text('cashBalance') }}<b>{{ formatMoney(branch.cash_balance) }}</b></span></div>
                        </button>
                    </div>

                    <article v-else-if="selectedBranch" class="branch-detail-card">
                        <div class="detail-head">
                            <div class="branch-card-title"><span class="branch-symbol">⌂</span><div><span class="eyebrow">{{ text('branchCode') }} · {{ selectedBranch.code }}</span><h2>{{ localizedBranch(selectedBranch) }}</h2><p>{{ selectedBranch.city || text('noData') }}</p></div></div>
                            <div class="detail-badges"><span class="branch-status" :class="{ inactive: !selectedBranch.is_active }">{{ selectedBranch.is_active ? text('active') : text('inactive') }}</span><span class="access-badge">{{ accessLabel(selectedBranch.access_role) }}</span></div>
                        </div>
                        <div class="detail-stat-grid"><div><span>{{ text('totalOrders') }}</span><b class="mono">{{ fmt(selectedBranch.orders?.total || 0) }}</b></div><div><span>{{ text('activeOrders') }}</span><b class="mono">{{ fmt(selectedBranch.orders?.active || 0) }}</b></div><div><span>{{ text('deliveredOrders') }}</span><b class="mono">{{ fmt(selectedBranch.orders?.delivered || 0) }}</b></div><div><span>{{ text('todayOrders') }}</span><b class="mono">{{ fmt(selectedBranch.orders?.today || 0) }}</b></div></div>
                        <div class="detail-info-grid"><div><span>{{ text('cashBalance') }}</span><b class="money">{{ formatMoney(selectedBranch.cash_balance) }}</b></div><div><span>{{ text('contact') }}</span><b>{{ selectedBranch.phone || selectedBranch.address || text('noContact') }}</b></div><div><span>{{ text('city') }}</span><b>{{ selectedBranch.city || '—' }}</b></div><div><span>{{ text('address') }}</span><b>{{ selectedBranch.address || '—' }}</b></div></div>
                    </article>
                </div>

                <section class="recent-orders-panel">
                    <div class="section-heading"><div><span class="eyebrow">{{ text('orderActivity') }}</span><h2>{{ text('orderActivity') }}</h2></div><span class="count-chip">{{ visibleOrders.length }}</span></div>
                    <div v-if="visibleOrders.length" class="order-list">
                        <article v-for="order in visibleOrders" :key="order.id" class="order-row">
                            <div class="order-main"><b>{{ order.track_no || `#${order.id}` }}</b><span>{{ order.customer_name || text('noData') }}</span></div>
                            <div class="order-meta"><span class="status-pill" :class="statusTone(order.status)">{{ statusLabel(order.status) }}</span><b class="money">{{ formatMoney(order.price) }}</b></div>
                            <div class="order-foot"><span>{{ formatDate(order.date) }}</span><span>{{ order.branches?.map((branch) => branch.name || branch.code).join(' · ') || '—' }}</span></div>
                        </article>
                    </div>
                    <div v-else class="empty-state"><span>⌁</span><p>{{ text('noOrders') }}</p></div>
                </section>
            </section>

            <section v-else class="empty-portal"><span>⌂</span><h1>{{ text('portal') }}</h1><p>{{ text('noBranches') }}</p></section>
        </main>
    </div>
</template>

<style scoped>
.branch-portal{--bg:#eef5f5;--surface:#fff;--surface-2:#f6faf9;--ink:#102a43;--ink-soft:#5b7481;--ink-faint:#8ca0a9;--border:rgba(16,42,67,.1);--primary:#087b73;--primary-strong:#05645e;--primary-tint:rgba(8,123,115,.1);--success:#059669;--success-tint:rgba(5,150,105,.12);--danger:#dc5a50;--danger-tint:rgba(220,90,80,.12);--warning:#c98316;--warning-tint:rgba(201,131,22,.13);--shadow:0 18px 52px rgba(21,66,73,.09);min-height:100dvh;color:var(--ink);background:radial-gradient(circle at 100% 0,rgba(47,180,166,.13),transparent 30rem),var(--bg);font-family:inherit}.branch-portal.theme-dark{--bg:#0c1720;--surface:#13232d;--surface-2:#172b36;--ink:#e5f0f2;--ink-soft:#a8bcc3;--ink-faint:#718b95;--border:rgba(213,241,240,.1);--primary:#28b3a5;--primary-strong:#66dbcf;--primary-tint:rgba(40,179,165,.13);--success:#52d1a0;--success-tint:rgba(82,209,160,.13);--danger:#fb8d83;--danger-tint:rgba(251,141,131,.14);--warning:#f4bc50;--warning-tint:rgba(244,188,80,.14);--shadow:0 18px 52px rgba(0,0,0,.24)}.portal-topbar{height:72px;box-sizing:border-box;display:flex;align-items:center;justify-content:space-between;gap:20px;padding:0 clamp(18px,4vw,58px);border-bottom:1px solid var(--border);background:color-mix(in srgb,var(--surface) 90%,transparent);backdrop-filter:blur(14px);position:sticky;top:0;z-index:5}.portal-brand,.portal-top-actions,.operator-card,.branch-card-title,.detail-head,.section-heading,.order-meta,.order-foot{display:flex;align-items:center}.portal-brand{gap:10px;min-width:0}.brand-mark{width:42px;height:42px;flex:none;display:grid;place-items:center;overflow:hidden;border:1px solid var(--border);border-radius:13px;background:#fff}.brand-mark img{width:100%;height:100%;object-fit:contain;padding:3px;box-sizing:border-box}.portal-brand b,.portal-brand span{display:block}.portal-brand b{font-size:14px;font-weight:900;line-height:1.2}.portal-brand span{margin-top:3px;color:var(--ink-faint);font-size:10px;font-weight:800}.portal-top-actions{gap:8px}.language-picker select,.icon-button,.logout-button{height:38px;border:1px solid var(--border);border-radius:10px;color:var(--ink-soft);background:var(--surface);font:inherit;font-size:11px;font-weight:800}.language-picker select{padding:0 9px;outline:0;cursor:pointer}.language-picker option{color:#102a43}.icon-button{width:38px;display:grid;place-items:center;cursor:pointer}.logout-button{display:inline-flex;align-items:center;gap:7px;padding:0 11px;cursor:pointer}.logout-button:hover{color:var(--danger);border-color:color-mix(in srgb,var(--danger) 50%,var(--border));background:var(--danger-tint)}.portal-main{width:min(1280px,100%);box-sizing:border-box;margin:0 auto;padding:clamp(22px,4vw,48px) clamp(15px,3vw,34px) 56px}.portal-hero{display:flex;justify-content:space-between;align-items:center;gap:22px;margin-bottom:22px;padding:clamp(22px,3vw,34px);border:1px solid color-mix(in srgb,var(--primary) 20%,var(--border));border-radius:24px;color:#fff;background:linear-gradient(125deg,#056b64,#074d58);box-shadow:var(--shadow);overflow:hidden;position:relative}.portal-hero:after{content:"";position:absolute;inset:auto -80px -155px auto;width:330px;height:330px;border:40px solid rgba(255,255,255,.07);border-radius:50%;pointer-events:none}.portal-hero h1{margin:7px 0 5px;font-size:clamp(23px,3vw,32px);line-height:1.2}.portal-hero p{max-width:670px;margin:0;color:rgba(255,255,255,.8);font-size:13px;line-height:1.75}.eyebrow{display:inline-flex;align-items:center;gap:7px;color:var(--primary-strong);font-size:10px;font-weight:900;letter-spacing:.06em;text-transform:uppercase}.portal-hero .eyebrow{color:#c7fff8}.portal-hero .eyebrow i{width:7px;height:7px;border-radius:50%;background:#69e6b5;box-shadow:0 0 0 4px rgba(105,230,181,.16)}.operator-card{position:relative;z-index:1;gap:10px;min-width:176px;padding:10px 13px;border:1px solid rgba(255,255,255,.16);border-radius:15px;background:rgba(2,32,39,.22)}.operator-avatar{width:36px;height:36px;display:grid;place-items:center;flex:none;border-radius:50%;color:#074e53;background:#c7fff8;font-size:13px;font-weight:900}.operator-card b,.operator-card span{display:block}.operator-card b{font-size:12px}.operator-card span:last-child{margin-top:3px;color:rgba(255,255,255,.7);font-size:10px;font-weight:800}.portal-controls{display:flex;justify-content:space-between;align-items:end;gap:15px;margin:0 0 17px;padding:14px 16px;border:1px solid var(--border);border-radius:15px;background:var(--surface);box-shadow:0 8px 28px rgba(21,66,73,.04)}.portal-controls label{display:grid;gap:5px;color:var(--ink-faint);font-size:10px;font-weight:900}.portal-controls select{min-width:min(370px,70vw);padding:9px 11px;border:1px solid var(--border);border-radius:9px;outline:0;color:var(--ink);background:var(--surface-2);font:inherit;font-size:12px;font-weight:800}.portal-controls p{margin:0;color:var(--ink-faint);font-size:10.5px;font-weight:700}.metric-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:13px;margin-bottom:22px}.metric-card{display:flex;align-items:center;gap:11px;padding:16px;border:1px solid var(--border);border-radius:17px;background:var(--surface);box-shadow:0 10px 30px rgba(21,66,73,.05)}.metric-icon{width:40px;height:40px;display:grid;place-items:center;flex:none;border-radius:12px;color:var(--primary-strong);background:var(--primary-tint)}.metric-card:nth-child(2) .metric-icon{color:var(--warning);background:var(--warning-tint)}.metric-card:nth-child(3) .metric-icon{color:var(--success);background:var(--success-tint)}.metric-card strong,.metric-card span{display:block}.metric-card strong{font-size:21px;line-height:1.15}.metric-card div>span{margin-top:3px;color:var(--ink-faint);font-size:10px;font-weight:800}.portal-layout{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr);gap:18px;align-items:start}.branch-area,.recent-orders-panel{min-width:0;padding:19px;border:1px solid var(--border);border-radius:20px;background:var(--surface);box-shadow:var(--shadow)}.section-heading{justify-content:space-between;gap:12px;margin-bottom:16px}.section-heading h2{margin:3px 0 0;font-size:17px;line-height:1.3}.count-chip{min-width:25px;padding:4px 7px;border-radius:999px;color:var(--primary-strong);background:var(--primary-tint);font-size:11px;font-weight:900;text-align:center}.branch-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(205px,1fr));gap:12px}.branch-card{position:relative;min-height:168px;padding:16px;border:1px solid var(--border);border-radius:16px;color:inherit;background:var(--surface-2);font:inherit;text-align:inherit;cursor:pointer;transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease}.branch-card:hover{border-color:color-mix(in srgb,var(--primary) 55%,var(--border));box-shadow:0 12px 28px rgba(7,87,84,.13);transform:translateY(-2px)}.branch-status,.access-badge,.status-pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;font-size:9px;font-weight:900}.branch-status{position:absolute;inset:13px 13px auto auto;padding:5px 7px;color:var(--success);background:var(--success-tint)}[dir="ltr"] .branch-status{inset:13px auto auto 13px}.branch-status.inactive{color:var(--danger);background:var(--danger-tint)}.branch-card-title{align-items:flex-start;gap:9px;padding-inline-end:52px}.branch-symbol{width:36px;height:36px;display:grid;place-items:center;flex:none;border-radius:11px;color:var(--primary-strong);background:var(--primary-tint);font-size:19px;font-weight:900}.branch-card-title h3,.branch-card-title h2{margin:0;font-size:14px;line-height:1.35}.branch-card-title p{margin:3px 0 0;color:var(--ink-faint);font-size:10px;font-weight:700}.branch-card-data{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:17px}.branch-card-data span{display:flex;flex-direction:column;gap:4px;color:var(--ink-faint);font-size:9px;font-weight:800}.branch-card-data span:last-child{grid-column:span 2;padding-top:9px;border-top:1px solid var(--border)}.branch-card-data b{color:var(--ink);font-size:13px}.branch-card-data span:last-child b{color:var(--primary-strong);font-size:12px}.branch-detail-card{padding:20px;border:1px solid color-mix(in srgb,var(--primary) 25%,var(--border));border-radius:17px;background:linear-gradient(135deg,var(--surface-2),var(--surface))}.detail-head{justify-content:space-between;align-items:flex-start;gap:14px}.detail-head .branch-card-title{padding:0}.detail-head .branch-card-title h2{font-size:19px}.detail-badges{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:6px}.detail-badges .branch-status{position:static}.access-badge{padding:5px 7px;color:var(--primary-strong);background:var(--primary-tint)}.detail-stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:9px;margin:21px 0 12px}.detail-stat-grid>div,.detail-info-grid>div{padding:10px;border:1px solid var(--border);border-radius:11px;background:var(--surface)}.detail-stat-grid span,.detail-info-grid span{display:block;color:var(--ink-faint);font-size:9px;font-weight:800}.detail-stat-grid b{display:block;margin-top:5px;font-size:18px}.detail-info-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:9px}.detail-info-grid b{display:block;margin-top:4px;font-size:11px;line-height:1.45;overflow-wrap:anywhere}.money{color:var(--primary-strong)!important;white-space:nowrap}.recent-orders-panel{min-height:296px}.order-list{display:grid;gap:8px}.order-row{padding:11px;border:1px solid var(--border);border-radius:13px;background:var(--surface-2)}.order-main{display:flex;flex-direction:column;gap:2px;min-width:0}.order-main b{font-size:12px}.order-main span{overflow:hidden;color:var(--ink-soft);font-size:10.5px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}.order-meta{justify-content:space-between;gap:8px;margin-top:8px}.status-pill{padding:4px 7px;color:var(--ink-soft);background:var(--surface)}.status-pill.pending{color:var(--warning);background:var(--warning-tint)}.status-pill.approved{color:#1879bd;background:rgba(24,121,189,.1)}.status-pill.courier{color:#8561d8;background:rgba(133,97,216,.12)}.status-pill.delivered{color:var(--success);background:var(--success-tint)}.status-pill.returned{color:var(--danger);background:var(--danger-tint)}.order-meta .money{font-size:11px}.order-foot{justify-content:space-between;gap:10px;margin-top:9px;padding-top:8px;border-top:1px solid var(--border);color:var(--ink-faint);font-size:9px;font-weight:800}.order-foot span:last-child{overflow:hidden;text-align:end;text-overflow:ellipsis;white-space:nowrap}.empty-state,.empty-portal{display:grid;place-items:center;text-align:center}.empty-state{min-height:215px;padding:18px;color:var(--ink-faint)}.empty-state span,.empty-portal>span{color:var(--primary-strong);font-size:31px;font-weight:900}.empty-state p{max-width:240px;margin:9px 0 0;font-size:12px;line-height:1.7;font-weight:700}.empty-portal{min-height:420px;padding:30px;border:1px dashed color-mix(in srgb,var(--primary) 40%,var(--border));border-radius:25px;background:var(--surface)}.empty-portal h1{margin:11px 0 0;font-size:20px}.empty-portal p{margin:5px 0 0;color:var(--ink-faint);font-size:13px}.sr-only{position:absolute;width:1px;height:1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}@media(max-width:950px){.portal-layout{grid-template-columns:1fr}.metric-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:620px){.portal-topbar{height:64px;padding:0 13px}.portal-brand b{font-size:12px}.portal-brand span{font-size:9px}.brand-mark{width:36px;height:36px}.language-picker select{max-width:64px;padding:0 4px}.logout-button{width:37px;padding:0;justify-content:center}.logout-button span{display:none}.portal-top-actions{gap:5px}.portal-main{padding:18px 12px 36px}.portal-hero{display:block;padding:21px 18px;border-radius:19px}.portal-hero h1{font-size:23px}.operator-card{width:max-content;max-width:100%;margin-top:17px}.portal-controls{align-items:stretch;flex-direction:column;padding:12px}.portal-controls select{min-width:0;width:100%;box-sizing:border-box}.portal-controls p{line-height:1.5}.metric-grid{gap:9px;margin-bottom:15px}.metric-card{gap:8px;padding:12px}.metric-icon{width:34px;height:34px;border-radius:10px}.metric-card strong{font-size:18px}.metric-card div>span{font-size:9px}.branch-area,.recent-orders-panel{padding:14px;border-radius:16px}.branch-grid{grid-template-columns:1fr}.detail-head{display:block}.detail-badges{justify-content:flex-start;margin-top:12px}.detail-stat-grid{grid-template-columns:repeat(2,1fr);margin-top:15px}.detail-info-grid{grid-template-columns:1fr}.section-heading h2{font-size:15px}}
</style>
