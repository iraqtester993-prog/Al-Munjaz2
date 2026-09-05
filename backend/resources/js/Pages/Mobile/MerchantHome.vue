<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppShell from '../../Components/AppShell.vue'
import HeroSlider from '../../Components/HeroSlider.vue'
import StatusBadge from '../../Components/StatusBadge.vue'
import DonutChart from '../../Components/DonutChart.vue'
import OrderForm from '../../Components/OrderForm.vue'

const props = defineProps({
    stats: { type: Object, required: true },
    recentOrders: { type: Array, default: () => [] },
    heroSlides: { type: Array, default: () => [] },
})

const page = usePage()
const user = computed(() => page.props.auth?.user)
const tenant = computed(() => page.props.auth?.tenant)
const locale = computed(() => page.props.locale || 'ar')
const showOrderForm = ref(false)
const now = ref(Date.now())
let ticker

const deliveryRate = computed(() => props.stats.total ? Math.round((props.stats.delivered / props.stats.total) * 100) : 0)
const statusItems = computed(() => [
    { label: t('Pending'), value: props.stats.pending, color: 'var(--st-pending)' },
    { label: t('Approved'), value: props.stats.approved, color: 'var(--st-approved)' },
    { label: t('With Courier'), value: props.stats.courier, color: 'var(--st-courier)' },
    { label: t('Delivered'), value: props.stats.delivered, color: 'var(--st-delivered)' },
    { label: t('Returned'), value: props.stats.returned, color: 'var(--st-returned)' },
])

const merchantName = computed(() => user.value?.name || t('Merchant Account'))
const shopName = computed(() => user.value?.shop_name || tenant.value?.name || '')
const merchantSubtitle = computed(() => shopName.value && shopName.value !== merchantName.value ? shopName.value : '')
const greeting = computed(() => `${t('Welcome')}، ${merchantName.value}`)

function localizedOrderValue(order, key) {
    const preferred = locale.value === 'en' ? 'en' : locale.value === 'ku' ? 'ku' : 'ar'

    return order?.[`${key}_${preferred}`]
        || order?.[`${key}_en`]
        || order?.[`${key}_ar`]
        || ''
}

function customerName(order) {
    return localizedOrderValue(order, 'customer_name')
}

function vehicleLabel(vehicle) {
    return {
        bike: t('Motorcycle'),
        sedan: t('Sedan'),
        suv: t('SUV'),
        truck: t('Van / Truck'),
        normal: t('Regular Delivery'),
    }[vehicle] || vehicle || ''
}

function openOrderChat(order) {
    router.post(route('app.chats.open'), { order_id: order.id }, { preserveScroll: true })
}

function openComplaint(order) {
    router.post(route('app.chats.open'), { order_id: order.id, complaint: true }, { preserveScroll: true })
}

function pickupRemaining(order) {
    if (!order.pickup_deadline_at) return null

    return Math.max(0, new Date(order.pickup_deadline_at).getTime() - now.value)
}

function pickupRemainingText(order) {
    const remaining = pickupRemaining(order)
    if (remaining === null) return null

    const seconds = Math.floor(remaining / 1000)
    const minutes = Math.floor(seconds / 60)

    return `${String(minutes).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')} ${t('Minutes abbreviation')}`
}

onMounted(() => {
    ticker = window.setInterval(() => { now.value = Date.now() }, 1000)
})

onUnmounted(() => window.clearInterval(ticker))
</script>

<template>
    <AppShell :title="greeting" :subtitle="merchantSubtitle">

        <HeroSlider :slides="heroSlides" />

        <button class="home-new-order" @click="showOrderForm = true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
            {{ t('Add New Order') }}
        </button>

        <div class="hero-card">
            <div class="hc-label">{{ t('My Orders Today') }}</div>
            <div class="hc-value mono">{{ stats.today }}</div>
            <div class="hc-row">
                <span class="hc-chip">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
                    {{ t('Delivery Rate') }}: {{ deliveryRate }}%
                </span>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-card-head"><h4>{{ t('Order Status Distribution') }}</h4></div>
            <DonutChart :items="statusItems" />
        </div>

        <div class="section-title">
            <h3>{{ t('Recent Orders') }}</h3>
            <a @click="$inertia.visit(route('app.orders', { list: 1 }))">{{ t('See all') }}</a>
        </div>

        <div v-if="recentOrders.length" class="list-card">
            <button v-for="o in recentOrders" :key="o.id" class="merchant-home-order" type="button" @click="$inertia.visit(route('app.orders', { list: 1, open: o.id }))">
                <div class="merchant-order-top">
                    <div class="order-ic">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 8 12 3 3 8v8l9 5 9-5V8Z M3 8l9 5 9-5 M12 13v8" />
                        </svg>
                    </div>
                    <div class="order-mid">
                        <b>{{ customerName(o) }}</b>
                    </div>
                    <div class="order-end">
                        <span class="order-date">{{ o.date }}</span>
                        <b class="mono">{{ fmt(o.price) }}</b>
                        <StatusBadge :status="o.status" />
                    </div>
                </div>
            </button>
        </div>
        <div v-else class="empty-hint">{{ t('No orders yet') }}</div>

        <OrderForm :open="showOrderForm" @close="showOrderForm = false" />
    </AppShell>
</template>

<style scoped>
.home-new-order {
    width: 100%; padding: 16px; border-radius: 16px; background: var(--primary); color: #fff;
    display: flex; align-items: center; justify-content: center; gap: 10px; font: inherit;
    font-size: 14px; font-weight: 800; margin-bottom: 16px; border: 0; cursor: pointer;
    box-shadow: 0 8px 24px -6px color-mix(in srgb, var(--primary) 55%, transparent);
}
.merchant-home-order { display:block; width:100%; padding:12px 14px; border:0; border-bottom:1px solid var(--border); background:transparent; color:inherit; font:inherit; text-align:inherit; cursor:pointer; }
.merchant-home-order:last-child { border-bottom:0; }
.merchant-order-top { display:flex; align-items:center; gap:11px; }
.merchant-order-top .order-end :deep(.badge) { margin-top:4px; }
.order-date { display:block; color:var(--ink-faint); font-size:9px; font-weight:700; }
.merchant-order-note { margin:8px 0 0; padding:6px 8px; border-radius:8px; background:var(--surface-2); color:var(--ink-soft); font-size:10px; font-weight:700; }
.merchant-order-note b { color:var(--primary-strong); }
.merchant-order-vehicle-note { background:var(--primary-tint); }
.merchant-courier-card { display:flex; align-items:center; gap:9px; margin-top:8px; padding:8px; border:1px solid color-mix(in srgb, var(--primary) 20%, var(--border)); border-radius:10px; background:var(--primary-tint); }
.merchant-courier-avatar { width:30px; height:30px; display:grid; place-items:center; flex:none; border-radius:10px; color:#fff; background:var(--primary); font-size:11px; font-weight:900; }
.merchant-courier-copy { display:grid; flex:1; min-width:0; gap:1px; }.merchant-courier-copy small,.merchant-courier-copy em { color:var(--ink-faint); font-size:9px; font-style:normal; font-weight:700; }.merchant-courier-copy b { overflow:hidden; color:var(--ink); font-size:10.5px; text-overflow:ellipsis; white-space:nowrap; }.merchant-courier-card button { padding:5px 8px; border:0; border-radius:8px; color:#fff; background:var(--primary); font:inherit; font-size:9px; font-weight:900; }
.merchant-order-tools { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-top:8px; color:var(--ink-faint); font-size:9.5px; font-weight:700; }
.merchant-pickup-timer { display:inline-flex; align-items:center; gap:4px; color:var(--success); }
.merchant-pickup-timer i { width:7px; height:7px; border-radius:50%; background:var(--success); box-shadow:0 0 7px color-mix(in srgb, var(--success) 75%, transparent); }
.merchant-order-tools button { padding:5px 8px; border:1px solid color-mix(in srgb, var(--danger) 20%, transparent); border-radius:8px; background:color-mix(in srgb, var(--danger-tint) 80%, transparent); color:var(--danger); font:inherit; font-size:9.5px; font-weight:800; }
</style>
