<script setup>
import { computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'
import BranchFilter from '../../Components/BranchFilter.vue'
import DonutChart from '../../Components/DonutChart.vue'
import StatusBadge from '../../Components/StatusBadge.vue'

const props = defineProps({
    kpis: { type: Object, required: true },
    financials: { type: Object, default: () => ({}) },
    statusCounts: { type: Object, required: true },
    week: { type: Array, default: () => [] },
    recentOrders: { type: Array, default: () => [] },
    recentNotifs: { type: Array, default: () => [] },
    topMerchants: { type: Array, default: () => [] },
    branchFilter: { type: Object, default: () => ({}) },
})

const page = usePage()
const locale = computed(() => page.props.locale || 'ar')

const primaryKpis = computed(() => [
    { icon: 'box', label: t('Orders'), value: props.kpis.orders, tint: 'var(--primary-tint)', color: 'var(--primary-strong)' },
    { icon: 'clock', label: t('Pending'), value: props.kpis.pending, tint: 'var(--st-pending-tint)', color: 'var(--st-pending)' },
    { icon: 'bike', label: t('With Courier'), value: props.kpis.courier, tint: 'var(--st-courier-tint)', color: 'var(--st-courier)' },
    { icon: 'check', label: t('Delivered'), value: props.kpis.delivered, tint: 'var(--success-tint)', color: 'var(--success)' },
])

const statusRows = computed(() => {
    const statuses = ['pending', 'approved', 'courier', 'delivered', 'returned']
    const maximum = Math.max(1, ...statuses.map((status) => props.statusCounts[status] || 0))

    return statuses.map((status) => ({
        status,
        label: statusLabel(status),
        value: props.statusCounts[status] || 0,
        percent: Math.round(((props.statusCounts[status] || 0) / maximum) * 100),
        color: statusColor(status),
    }))
})

const donutItems = computed(() => statusRows.value
    .filter((row) => row.value > 0)
    .map((row) => ({ label: row.label, value: row.value })))

const financialRows = computed(() => [
    { label: t('Order Value'), value: props.financials.value ?? props.kpis.value, tone: 'default' },
    { label: t('Delivered Value'), value: props.financials.deliveredValue ?? props.kpis.deliveredValue, tone: 'positive' },
    { label: t('Delivery Fees'), value: props.financials.fees ?? props.kpis.fees, tone: 'accent' },
    { label: t('Merchant Balance'), value: props.financials.merchantBalance, tone: 'default' },
    { label: t('Courier Budget'), value: props.financials.courierBudget, tone: 'default' },
    { label: t('Courier Collections'), value: props.financials.collected, tone: 'positive' },
])

const maxWeek = computed(() => Math.max(1, ...props.week.map((item) => item.count)))

function kpiIcon(name) {
    const paths = {
        box: 'M21 8 12 3 3 8v8l9 5 9-5V8Z M3 8l9 5 9-5 M12 13v8',
        clock: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0-13v5l3 2',
        bike: 'M5 18a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm14-8a4 4 0 1 1 0 8 4 4 0 0 1 0-8Z M5 10h14 M12 10l-2-4h5',
        check: 'M20 6 9 17l-5-5',
        team: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm13 10v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
        alert: 'M12 9v4m0 4h.01M10.3 3.9 2.5 17.4A2 2 0 0 0 4.2 20h15.6a2 2 0 0 0 1.7-2.6L13.7 3.9a2 2 0 0 0-3.4 0Z',
    }
    return paths[name]
}

function statusLabel(status) {
    const labels = {
        pending: t('Pending'),
        approved: t('Approved'),
        courier: t('With Courier'),
        delivered: t('Delivered'),
        returned: t('Returned'),
    }
    return labels[status] || status
}

function statusColor(status) {
    const colors = {
        pending: 'var(--st-pending)',
        approved: 'var(--st-approved)',
        courier: 'var(--st-courier)',
        delivered: 'var(--success)',
        returned: 'var(--st-returned)',
    }
    return colors[status] || 'var(--primary)'
}

function weekHeight(item) {
    return `${Math.max(6, Math.round((item.count / maxWeek.value) * 100))}%`
}

function notificationMeta(notification) {
    const map = {
        order: { tint: 'var(--primary-tint)', color: 'var(--primary-strong)' },
        account: { tint: 'var(--warning-tint)', color: 'var(--warning)' },
        chat: { tint: 'var(--st-approved-tint)', color: 'var(--st-approved)' },
    }
    return map[notification.type] || { tint: 'var(--surface-2)', color: 'var(--ink-soft)' }
}

function customerName(order) {
    return locale.value === 'en' && order.customer_name_en
        ? order.customer_name_en
        : order.customer_name_ar
}

function sourceLabel(source) {
    return source === 'merchant' ? t('Merchant orders') : t('Courier deliveries')
}

function shortDate(value) {
    if (!value) return '—'

    const date = new Date(`${value}T00:00:00`)
    const language = { ar: 'ar-IQ-u-nu-latn', en: 'en-US', ku: 'ku-IQ-u-nu-latn' }[locale.value] || 'en-US'
    return new Intl.DateTimeFormat(language, { day: 'numeric', month: 'short' }).format(date)
}

function money(value) {
    return `${fmt(value || 0)} ${t('IQD')}`
}

function changeBranchFilter(branchId) {
    router.get(route('admin.dashboard'), branchId ? { branch_id: branchId } : {}, {
        preserveScroll: true,
        preserveState: false,
    })
}

</script>

<template>
    <AdminShell title="Dashboard">
        <div v-if="branchFilter?.enabled" class="dashboard-filter-row">
            <BranchFilter :filter="branchFilter" @change="changeBranchFilter" />
        </div>
        <div class="kpi-grid dashboard-kpis">
            <div v-for="kpi in primaryKpis" :key="kpi.label" class="kpi">
                <div class="ki" :style="{ background: kpi.tint, color: kpi.color }">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="kpiIcon(kpi.icon)" />
                    </svg>
                </div>
                <div>
                    <div class="kval mono">{{ kpi.value }}</div>
                    <div class="klab">{{ kpi.label }}</div>
                </div>
            </div>
        </div>

        <section class="panel courier-location-panel">
            <div class="panel-head courier-location-head">
                <div>
                    <h3>{{ t('Courier locations') }}</h3>
                    <small>{{ t('Last known positions only — no route history is recorded.') }}</small>
                </div>
                <button class="location-directory-link" type="button" @click="$inertia.visit(route('admin.couriers.locations'))">
                    {{ t('Courier locations') }}
                </button>
            </div>
        </section>

        <div class="overview-grid">
            <section class="panel distribution-panel">
                <div class="panel-head"><h3>{{ t('Orders by Status') }}</h3><span class="source-pill">{{ kpis.orders }} {{ t('Orders') }}</span></div>
                <div class="panel-body distribution-layout">
                    <div class="status-distribution">
                        <div v-for="row in statusRows" :key="row.status" class="distribution-row">
                            <span class="distribution-label">{{ row.label }}</span>
                            <div class="distribution-track"><i :style="{ width: `${row.percent}%`, background: row.color }"></i></div>
                            <b class="mono">{{ row.value }}</b>
                        </div>
                    </div>
                    <div class="donut-wrap"><DonutChart :items="donutItems" /></div>
                </div>
            </section>

            <section class="panel financial-panel">
                <div class="panel-head"><h3>{{ t('Financial Summary') }}</h3><button class="link link-button" type="button" @click="$inertia.visit(route('admin.finance'))">{{ t('See all') }}</button></div>
                <div class="financial-list">
                    <div v-for="item in financialRows" :key="item.label" class="financial-row">
                        <span>{{ item.label }}</span>
                        <b class="mono" :class="item.tone">{{ money(item.value) }}</b>
                    </div>
                </div>
            </section>
        </div>

        <div class="overview-grid merchant-row">
            <section class="panel">
                <div class="panel-head"><h3>{{ t('Top Active Merchants') }}</h3><button class="link link-button" type="button" @click="$inertia.visit(route('admin.merchants'))">{{ t('See all') }}</button></div>
                <div class="table-wrap">
                    <table class="tbl">
                        <thead><tr><th>{{ t('Merchant') }}</th><th>{{ t('Phone') }}</th><th>{{ t('Orders') }}</th><th>{{ t('Collected') }}</th></tr></thead>
                        <tbody>
                            <tr v-for="merchant in topMerchants" :key="merchant.id">
                                <td>
                                    <div class="merchant-cell">
                                        <span class="merchant-avatar">{{ merchant.name?.slice(0, 1) }}</span>
                                        <div><b>{{ merchant.name }}</b><small>{{ merchant.shop_name || t('Merchant') }}</small></div>
                                    </div>
                                </td>
                                <td class="mono text-muted">{{ merchant.phone || '—' }}</td>
                                <td class="mono"><b>{{ merchant.orders }}</b></td>
                                <td class="mono positive"><b>{{ money(merchant.collected) }}</b></td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="!topMerchants.length" class="empty">{{ t('No merchants yet') }}</div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head"><h3>{{ t('Recent Notifications') }}</h3><button class="link link-button" type="button" @click="$inertia.visit(route('admin.notifications'))">{{ t('See all') }}</button></div>
                <div class="notification-list">
                    <div v-for="notification in recentNotifs" :key="notification.id" class="notif-item" :class="{ unread: !notification.read }">
                        <div class="notif-ic" :style="{ background: notificationMeta(notification).tint, color: notificationMeta(notification).color }">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" /></svg>
                        </div>
                        <div class="notif-body"><b>{{ notification.title }}</b><span>{{ notification.body }}</span></div>
                        <time class="notif-time">{{ notification.time }}</time>
                    </div>
                    <div v-if="!recentNotifs.length" class="empty">{{ t('No notifications yet') }}</div>
                </div>
            </section>
        </div>

        <section class="panel weekly-panel">
            <div class="panel-head"><h3>{{ t('Orders This Week') }}</h3><span class="weekly-total">{{ t('Live totals') }}</span></div>
            <div class="panel-body">
                <div class="week-chart">
                    <div v-for="item in week" :key="item.label" class="week-col">
                        <b class="week-value mono">{{ item.count }}</b>
                        <div class="week-rail"><i class="week-bar" :style="{ height: weekHeight(item) }"></i></div>
                        <span class="week-label">{{ item.label }}</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><h3>{{ t('Recent Orders') }}</h3><button class="link link-button" type="button" @click="$inertia.visit(route('admin.orders'))">{{ t('See all') }}</button></div>
            <div class="table-wrap">
                <table class="tbl">
                    <thead><tr><th>{{ t('Tracking Number') }}</th><th>{{ t('Customer') }}</th><th>{{ t('Source') }}</th><th>{{ t('Value') }}</th><th>{{ t('Date') }}</th><th>{{ t('Status') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="order in recentOrders" :key="order.id">
                            <td class="mono tracking">{{ order.track_no }}</td>
                            <td><b>{{ customerName(order) }}</b><small class="text-muted mono">{{ order.phone }}</small></td>
                            <td><span class="src-tag">{{ sourceLabel(order.source) }}</span></td>
                            <td class="mono"><b>{{ money(order.price) }}</b></td>
                            <td class="mono text-muted">{{ shortDate(order.date) }}</td>
                            <td><StatusBadge :status="order.status" /></td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!recentOrders.length" class="empty">{{ t('No orders yet') }}</div>
            </div>
        </section>
    </AdminShell>
</template>

<style scoped>
.dashboard-filter-row{display:flex;justify-content:flex-end;margin:-7px 0 15px}
.dashboard-kpis{grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:18px}.overview-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr);gap:18px}.merchant-row{grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr)}.distribution-layout{display:grid;grid-template-columns:minmax(0,1fr) 150px;gap:16px;align-items:center}.status-distribution{display:grid;gap:13px}.distribution-row{display:grid;grid-template-columns:90px minmax(44px,1fr) 24px;align-items:center;gap:9px;font-size:11px}.distribution-label{color:var(--ink-soft);font-weight:800}.distribution-track{height:7px;background:var(--surface-2);border-radius:20px;overflow:hidden}.distribution-track i{display:block;height:100%;border-radius:20px;min-width:3px}.distribution-row b{font-size:11px;text-align:end}.donut-wrap{min-width:0}.source-pill,.weekly-total{padding:3px 8px;border-radius:8px;background:var(--surface-2);color:var(--ink-soft);font-size:10px;font-weight:800}.link-button{border:0;background:transparent;font:inherit;font-size:11px;font-weight:800}
.courier-location-panel{margin-bottom:18px;overflow:hidden}.courier-location-head{align-items:flex-start}.courier-location-head h3{margin:0}.courier-location-head small{display:block;max-width:500px;margin-top:4px;color:var(--ink-faint);font-size:10px;font-weight:700;line-height:1.5}.courier-location-layout{display:grid;grid-template-columns:minmax(220px,.56fr) minmax(0,1.44fr);min-height:328px;border-top:1px solid var(--border)}.courier-location-list{display:grid;align-content:start;max-height:328px;overflow:auto;border-inline-end:1px solid var(--border)}.courier-location-row{display:flex;align-items:center;gap:10px;width:100%;min-width:0;padding:12px 14px;border:0;border-bottom:1px solid var(--border);background:transparent;color:var(--ink);font:inherit;text-align:start;cursor:pointer;transition:background .15s}.courier-location-row:hover,.courier-location-row.active{background:var(--primary-tint)}.courier-location-avatar{display:grid;place-items:center;width:33px;height:33px;border-radius:11px;background:var(--surface-2);color:var(--primary-strong);font-size:12px;font-weight:950;flex:none}.courier-location-row.active .courier-location-avatar{background:var(--primary);color:#fff}.courier-location-copy{display:grid;min-width:0;flex:1;gap:2px}.courier-location-copy b,.courier-location-copy small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.courier-location-copy b{font-size:11.5px;font-weight:900}.courier-location-copy small{color:var(--ink-faint);font-size:9.5px;font-weight:700}.courier-location-presence{width:9px;height:9px;border-radius:50%;background:var(--ink-faint);box-shadow:0 0 0 4px var(--surface-2);flex:none}.courier-location-presence.online{background:var(--success);box-shadow:0 0 0 4px var(--success-tint)}.courier-location-map-wrap{position:relative;min-width:0;background:var(--surface-2)}.courier-location-map{display:block;width:100%;height:328px;border:0;background:var(--surface-2)}.courier-location-map-info{position:absolute;z-index:2;right:12px;bottom:12px;left:12px;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 11px;border:1px solid color-mix(in srgb,var(--border) 88%,transparent);border-radius:11px;background:color-mix(in srgb,var(--surface) 93%,transparent);box-shadow:0 8px 22px rgba(0,0,0,.16);backdrop-filter:blur(8px)}.courier-location-map-info div{display:grid;min-width:0;gap:2px}.courier-location-map-info b,.courier-location-map-info small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.courier-location-map-info b{font-size:11px;font-weight:950}.courier-location-map-info small{color:var(--ink-soft);font-size:9px;font-weight:700}.courier-location-map-info a{flex:none;padding:7px 9px;border-radius:8px;background:var(--primary);color:#fff;font-size:9.5px;font-weight:900;text-decoration:none}.courier-location-empty{display:flex;align-items:center;gap:12px;padding:30px 18px;color:var(--ink-soft)}.courier-location-empty>span{display:grid;place-items:center;width:40px;height:40px;border-radius:12px;background:var(--primary-tint);color:var(--primary-strong);font-size:24px}.courier-location-empty div{display:grid;gap:3px}.courier-location-empty b{font-size:12px;color:var(--ink)}.courier-location-empty small{font-size:10px;line-height:1.6}
.location-directory-link{flex:none;padding:8px 11px;border:1px solid var(--border);border-radius:9px;background:var(--primary);color:#fff;font:850 10.5px var(--font);cursor:pointer;white-space:nowrap}
.financial-list{padding:3px 18px}.financial-row{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 0;border-bottom:1px solid var(--border);font-size:11.5px;font-weight:700;color:var(--ink-soft)}.financial-row:last-child{border-bottom:0}.financial-row b{font-size:12.5px;color:var(--ink)}.financial-row b.positive{color:var(--success)}.financial-row b.accent{color:var(--accent)}.table-wrap{overflow-x:auto}.merchant-cell{display:flex;align-items:center;gap:9px;min-width:175px}.merchant-avatar{display:grid;place-items:center;width:31px;height:31px;border-radius:11px;background:var(--primary-tint);color:var(--primary-strong);font-size:12px;font-weight:950}.merchant-cell b,.merchant-cell small{display:block}.merchant-cell small{margin-top:2px;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--ink-faint);font-size:10px}.positive{color:var(--success)}.text-muted{color:var(--ink-faint)}.notification-list{min-height:183px}.notification-list .notif-item{padding:13px 16px}.notif-body{min-width:0}.notif-body b,.notif-body span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.notif-body span{margin-top:3px;color:var(--ink-soft);font-size:10.5px}.notif-time{margin-inline-start:auto;white-space:nowrap;color:var(--ink-faint);font-size:9.5px}.weekly-panel{margin-top:0}.week-chart{height:135px;gap:13px;padding:6px 8px 0}.week-col{position:relative;display:flex;flex:1;min-width:30px;height:100%;align-items:center;flex-direction:column;justify-content:flex-end;gap:7px}.week-rail{position:relative;display:flex;width:100%;height:86px;align-items:flex-end;border-radius:7px;background:var(--surface-2);overflow:hidden}.week-bar{width:100%;border-radius:7px 7px 0 0;background:linear-gradient(180deg,var(--primary),var(--primary-strong))}.week-value{font-size:10px;color:var(--ink-soft)}.week-label{font-size:10px;color:var(--ink-faint);font-weight:800}.tracking{color:var(--primary-strong);font-weight:900}.tbl td small{display:block;margin-top:3px;font-size:10px}.tbl td{white-space:nowrap}
@media(max-width:1180px){.dashboard-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.overview-grid,.merchant-row{grid-template-columns:1fr}.distribution-layout{grid-template-columns:minmax(0,1fr) 180px}.courier-location-layout{grid-template-columns:250px minmax(0,1fr)}}
@media(max-width:650px){.distribution-layout{grid-template-columns:1fr}.donut-wrap{max-width:180px;margin:auto}.financial-list{padding-inline:15px}.week-chart{gap:7px;padding-inline:0}.week-label{font-size:8.5px}.dashboard-kpis{gap:10px}.tbl th,.tbl td{padding:10px}.courier-location-layout{grid-template-columns:1fr}.courier-location-list{display:flex;max-height:none;overflow:auto;border-inline-end:0;border-bottom:1px solid var(--border)}.courier-location-row{min-width:200px;border-bottom:0;border-inline-end:1px solid var(--border)}.courier-location-map,.courier-location-layout{min-height:295px}.courier-location-map{height:295px}.courier-location-map-info{right:9px;bottom:9px;left:9px}.courier-location-map-info small{max-width:190px}}
@media(max-width:650px){.dashboard-filter-row{justify-content:stretch;margin:0 0 14px}}
</style>
