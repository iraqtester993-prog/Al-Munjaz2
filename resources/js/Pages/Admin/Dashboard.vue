<script setup>
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AdminShell from '../../Components/AdminShell.vue'
import DonutChart from '../../Components/DonutChart.vue'
import StatusBadge from '../../Components/StatusBadge.vue'

const props = defineProps({
    kpis: { type: Object, required: true },
    statusCounts: { type: Object, required: true },
    week: { type: Array, default: () => [] },
    recentOrders: { type: Array, default: () => [] },
    recentNotifs: { type: Array, default: () => [] },
})

const kpis = computed(() => [
    { icon: 'box', label: t('Orders'), value: props.kpis.orders, tint: 'var(--primary-tint)', color: 'var(--primary-strong)' },
    { icon: 'clock', label: t('Pending'), value: props.kpis.pending, tint: 'var(--st-pending-tint)', color: 'var(--st-pending)' },
    { icon: 'bike', label: t('With Courier'), value: props.kpis.courier, tint: 'var(--st-courier-tint)', color: 'var(--st-courier)' },
    { icon: 'check', label: t('Delivered'), value: props.kpis.delivered, tint: 'var(--success-tint)', color: 'var(--success)' },
    { icon: 'cash', label: t('Order Value'), value: fmt(props.kpis.value), tint: 'var(--accent-tint)', color: 'var(--accent)' },
    { icon: 'card', label: t('Fees'), value: fmt(props.kpis.fees), tint: 'var(--surface-2)', color: 'var(--ink-soft)' },
    { icon: 'shop', label: t('Merchants'), value: props.kpis.merchants, tint: 'var(--st-approved-tint)', color: 'var(--st-approved)' },
    { icon: 'users', label: t('Couriers'), value: props.kpis.couriers, tint: 'var(--st-returned-tint)', color: 'var(--st-returned)' },
])

function kpiIcon(name) {
    const paths = {
        box: 'M21 8 12 3 3 8v8l9 5 9-5V8Z M3 8l9 5 9-5 M12 13v8',
        clock: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0-13v5l3 2',
        bike: 'M5 18a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm14-8a4 4 0 1 1 0 8 4 4 0 0 1 0-8Z M5 10h14 M12 10l-2-4h5',
        check: 'M20 6 9 17l-5-5',
        cash: 'M3 6h18v12H3z M3 10h18 M7 15h4',
        card: 'M3 6h18v12H3z M3 10h18 M7 15h4',
        shop: 'M4 10v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V10 M2 7l1-3h18l1 3a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0Z',
        users: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm13 10v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    }
    return paths[name]
}

const donutItems = computed(() => {
    const s = props.statusCounts
    const order = ['pending', 'approved', 'courier', 'delivered', 'returned']
    return order
        .map((k) => ({ label: statusLabel(k), value: s[k] || 0 }))
        .filter((x) => x.value > 0)
})

function statusLabel(s) {
    const m = { pending: t('Pending'), approved: t('Approved'), courier: t('With Courier'), delivered: t('Delivered'), returned: t('Returned') }
    return m[s] || s
}

const maxWeek = computed(() => Math.max(1, ...props.week.map((w) => w.count)))

function weekHeight(w) {
    return Math.max(6, Math.round((w.count / maxWeek.value) * 100)) + '%'
}

function notifMeta(n) {
    const map = {
        order: { tint: 'var(--primary-tint)', color: 'var(--primary-strong)' },
        account: { tint: 'var(--warning-tint)', color: 'var(--warning)' },
        chat: { tint: 'var(--st-approved-tint)', color: 'var(--st-approved)' },
    }
    return map[n.type] || { tint: 'var(--surface-2)', color: 'var(--ink-soft)' }
}
</script>

<template>
    <AdminShell title="Dashboard">
        <div class="kpi-grid">
            <div v-for="k in kpis" :key="k.label" class="kpi">
                <div class="ki" :style="{ background: k.tint, color: k.color }">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="kpiIcon(k.icon)" />
                    </svg>
                </div>
                <div>
                    <div class="kval mono">{{ k.value }}</div>
                    <div class="klab">{{ k.label }}</div>
                </div>
            </div>
        </div>

        <div class="two-col">
            <div class="panel">
                <div class="panel-head">
                    <h3>{{ t('Orders by Status') }}</h3>
                </div>
                <div class="panel-body">
                    <DonutChart :items="donutItems" />
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <h3>{{ t('Orders This Week') }}</h3>
                </div>
                <div class="panel-body">
                    <div class="week-chart">
                        <div v-for="w in week" :key="w.label" class="week-col">
                            <div class="week-bar" :style="{ height: weekHeight(w) }"></div>
                            <span class="week-label">{{ w.label }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="two-col">
            <div class="panel">
                <div class="panel-head">
                    <h3>{{ t('Recent Orders') }}</h3>
                    <a class="link" @click="$inertia.visit(route('admin.orders'))">{{ t('See all') }}</a>
                </div>
                <div class="panel-body" style="padding: 0">
                    <table class="tbl">
                        <tbody>
                            <tr v-for="o in recentOrders" :key="o.id">
                                <td>
                                    <div class="user-cell">
                                        <div>
                                            <b>{{ o.customer_name_ar }}</b>
                                            <div class="text-muted mono" style="font-size: 10px">{{ o.track_no }} · {{ o.phone }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="src-tag">{{ o.source }}</span></td>
                                <td><b class="mono">{{ fmt(o.price) }}</b></td>
                                <td><StatusBadge :status="o.status" /></td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="!recentOrders.length" class="empty">{{ t('No orders yet') }}</div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <h3>{{ t('Recent Notifications') }}</h3>
                    <a class="link" @click="$inertia.visit(route('admin.notifications'))">{{ t('See all') }}</a>
                </div>
                <div class="panel-body" style="padding: 0">
                    <div v-for="n in recentNotifs" :key="n.title + n.time" class="notif-item" :class="{ unread: !n.read }">
                        <div class="notif-ic" :style="{ background: notifMeta(n).tint, color: notifMeta(n).color }">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 5v14M5 12h14" />
                            </svg>
                        </div>
                        <div class="notif-body">
                            <b>{{ n.title }}</b>
                            <span>{{ n.body }}</span>
                        </div>
                        <div class="notif-time">{{ n.time }}</div>
                    </div>
                    <div v-if="!recentNotifs.length" class="empty">{{ t('No notifications yet') }}</div>
                </div>
            </div>
        </div>
    </AdminShell>
</template>
