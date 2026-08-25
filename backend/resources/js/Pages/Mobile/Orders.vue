<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppShell from '../../Components/AppShell.vue'
import StatusBadge from '../../Components/StatusBadge.vue'
import SheetModal from '../../Components/SheetModal.vue'
import OrderForm from '../../Components/OrderForm.vue'

const props = defineProps({
    orders: { type: Array, required: true },
    counts: { type: Object, required: true },
    filter: { type: String, default: 'all' },
    q: { type: String, default: '' },
    isCourier: { type: Boolean, default: false },
    wallet: { type: Object, default: () => ({ balance: 0, budget: 0 }) },
})

const page = usePage()
const user = computed(() => page.props.auth?.user)

const query = ref(props.q)
const active = ref(props.filter)
const merchantOverview = ref(!props.isCourier && props.filter === 'all' && !props.q)
const courierOverview = ref(props.isCourier && props.filter === 'all' && !props.q)
const selected = ref(null)
const showForm = ref(false)
const editing = ref(null)
const busy = ref(null)
const now = ref(Date.now())
let ticker

const filters = computed(() => {
    const list = [{ key: 'all', label: t('All') }]
    for (const s of ['pending', 'approved', 'courier', 'delivered', 'returned']) {
        list.push({ key: s, label: tStatus(s) })
    }
    return list
})

const merchantStatusCards = computed(() => [
    { key: 'all', label: 'جميع الطلبات', icon: '▦', tone: 'all', count: props.counts.all ?? 0 },
    { key: 'pending', label: 'قيد الانتظار', icon: '⌛', tone: 'pending', count: props.counts.pending ?? 0 },
    { key: 'approved', label: 'تم قبول الطلب', icon: '✓', tone: 'approved', count: props.counts.approved ?? 0 },
    { key: 'courier', label: 'بحوزة المندوب', icon: '⌁', tone: 'courier', count: props.counts.courier ?? 0 },
    { key: 'delivered', label: 'تم التسليم', icon: '✓', tone: 'delivered', count: props.counts.delivered ?? 0 },
    { key: 'returned', label: 'راجع', icon: '↩', tone: 'returned', count: props.counts.returned ?? 0 },
])

const courierStatusCards = computed(() => [
    { key: 'pending', label: 'قيد الانتظار', icon: '⌛', tone: 'pending', count: props.counts.pending ?? 0 },
    { key: 'approved', label: 'تم قبول الطلب', icon: '✓', tone: 'approved', count: props.counts.approved ?? 0 },
    { key: 'courier', label: 'بحوزتي', icon: '⌁', tone: 'courier', count: props.counts.courier ?? 0 },
    { key: 'delivered', label: 'تم التسليم', icon: '✓', tone: 'delivered', count: props.counts.delivered ?? 0 },
    { key: 'returned', label: 'راجع', icon: '↩', tone: 'returned', count: props.counts.returned ?? 0 },
])

function tStatus(s) {
    const m = { pending: t('Pending'), approved: t('Approved'), courier: t('With Courier'), delivered: t('Delivered'), returned: t('Returned') }
    return m[s] || s
}

function changeFilter(key) {
    active.value = key
    if (props.isCourier) courierOverview.value = false
    else merchantOverview.value = false
    router.get(route('app.orders'), { filter: key, q: query.value }, { preserveState: true, replace: true })
}

function showMerchantOrders(key) {
    query.value = ''
    changeFilter(key)
}

function backToMerchantOverview() {
    merchantOverview.value = true
    active.value = 'all'
    query.value = ''
    router.get(route('app.orders'), {}, { preserveState: true, replace: true })
}

function backToCourierOverview() {
    courierOverview.value = true
    active.value = 'all'
    query.value = ''
    router.get(route('app.orders'), {}, { preserveState: true, replace: true })
}

function doSearch() {
    router.get(route('app.orders'), { filter: active.value, q: query.value }, { preserveState: true, replace: true })
}

function openOrder(o) {
    selected.value = o
}

function setStatus(order, status) {
    if (busy.value) return
    busy.value = order.id
    router.post(
        route('app.orders.status', order.id),
        { status },
        {
            preserveScroll: true,
            onSuccess: () => {
                selected.value = { ...selected.value, status }
                busy.value = null
            },
            onFinish: () => (busy.value = null),
        }
    )
}

function canAct(order) {
    if (props.isCourier) {
        if (order.status === 'approved') return ['courier']
        if (order.status === 'courier') return ['delivered', 'returned']
        return []
    }
    return []
}

function actionsFor(order) {
    const acts = {
        approved: { label: t('Accept'), next: 'approved' },
        courier: { label: t('Start Delivery'), next: 'courier' },
        delivered: { label: t('Mark Delivered'), next: 'delivered', kind: 'success' },
        returned: { label: t('Mark Returned'), next: 'returned', kind: 'danger' },
    }
    return canAct(order).map((s) => ({
        label: s === 'approved' ? t('Accept') : s === 'courier' ? t('Start Delivery') : s === 'delivered' ? t('Mark Delivered') : t('Mark Returned'),
        next: s,
        kind: s === 'returned' ? 'danger' : s === 'delivered' ? 'success' : 'primary',
    }))
}

function openEdit() {
    if (!selected.value) return
    editing.value = selected.value
    showForm.value = true
}

function openComplaint(order) {
    router.post(route('app.chats.open'), { order_id: order.id }, { preserveScroll: true })
}

function vehicleLabel(order) {
    return {
        normal: 'طلب عادي',
        bike: 'دراجة نارية',
        sedan: 'سيارة صالون',
        suv: 'سيارة كبيرة',
        truck: 'سيارة نقل',
    }[order.delivery_vehicle] || 'طلب عادي'
}

function pickupRemaining(order) {
    if (!order.pickup_deadline_at) return null

    return Math.max(0, new Date(order.pickup_deadline_at).getTime() - now.value)
}

function pickupText(order) {
    const remaining = pickupRemaining(order)
    if (remaining === null) return null

    const seconds = Math.floor(remaining / 1000)
    const minutes = Math.floor(seconds / 60)

    return `${String(minutes).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')} د`
}

const STATUS_FLOW = ['pending', 'approved', 'courier', 'delivered']

function flowIndex(status) {
    const i = STATUS_FLOW.indexOf(status)
    return i >= 0 ? i : (status === 'returned' ? 0 : 0)
}

const steps = computed(() => {
    if (!selected.value) return []
    const cur = flowIndex(selected.value.status)
    return STATUS_FLOW.map((s, i) => ({
        key: s,
        label: tStatus(s),
        done: selected.value.status === 'delivered' ? i < cur + 1 : i < cur,
        current: i === cur && selected.value.status !== 'returned',
    }))
})

onMounted(() => {
    ticker = window.setInterval(() => { now.value = Date.now() }, 1000)
})

onUnmounted(() => window.clearInterval(ticker))
</script>

<template>
    <AppShell :title="isCourier ? t('My Deliveries') : t('My Orders')">
        <template #title>
            {{ isCourier ? t('My Deliveries') : t('My Orders') }}
            <span v-if="isCourier" class="tb-sub">{{ t('Available') }}: {{ fmt(wallet.budget) }} / {{ fmt(wallet.balance) }} د.ع</span>
        </template>

        <section v-if="!isCourier && merchantOverview" class="merchant-orders-overview">
            <div class="merchant-status-grid">
                <button v-for="card in merchantStatusCards" :key="card.key" class="merchant-status-card" :class="card.tone" type="button" @click="showMerchantOrders(card.key)">
                    <span class="merchant-status-icon">{{ card.icon }}</span>
                    <span class="merchant-status-copy"><b>{{ card.label }}</b><strong class="mono">{{ card.count }}</strong></span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6" /></svg>
                </button>
            </div>
        </section>

        <section v-else-if="isCourier && courierOverview" class="merchant-orders-overview courier-orders-overview">
            <div class="merchant-status-grid">
                <button v-for="card in courierStatusCards" :key="card.key" class="merchant-status-card" :class="card.tone" type="button" @click="changeFilter(card.key)">
                    <span class="merchant-status-icon">{{ card.icon }}</span>
                    <span class="merchant-status-copy"><b>{{ card.label }}</b><strong class="mono">{{ card.count }}</strong></span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6" /></svg>
                </button>
            </div>
        </section>

        <template v-else>
        <div class="orders-list-head">
            <button class="orders-back" type="button" @click="isCourier ? backToCourierOverview() : backToMerchantOverview()">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6" /></svg>
            </button>
            <b>{{ active === 'all' ? 'جميع الطلبات' : tStatus(active) }}</b>
            <span>{{ counts[active] ?? 0 }}</span>
        </div>

        <div v-if="!isCourier" class="search" style="max-width: 100%; margin-bottom: 12px">
            <input v-model="query" :placeholder="t('Search')" @keyup.enter="doSearch" />
        </div>

        <div v-if="orders.length" class="mobile-order-stack">
            <article v-for="o in orders" :key="o.id" class="mobile-order-card" @click="openOrder(o)">
                <header class="mobile-order-head">
                    <div class="order-ic">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 8 12 3 3 8v8l9 5 9-5V8Z M3 8l9 5 9-5 M12 13v8" />
                    </svg>
                    </div>
                    <div class="order-mid">
                        <b>{{ o.customer_name_ar }}</b>
                        <span class="mono">{{ o.track_no }} · {{ o.address_ar }}</span>
                    </div>
                    <StatusBadge :status="o.status" />
                </header>
                <div class="mobile-order-summary">
                    <strong class="mono">{{ fmt(o.price) }} <small>د.ع</small></strong>
                    <span class="mobile-vehicle-badge">{{ vehicleLabel(o) }}</span>
                </div>
                <p v-if="o.vehicle_note || o.notes" class="mobile-order-note"><b>ملاحظة الطلب:</b> {{ o.vehicle_note || o.notes }}</p>
                <footer v-if="o.status === 'approved' && pickupText(o)" class="mobile-order-timer"><i></i> الوقت المتاح للاستلام: <b class="mono">{{ pickupText(o) }}</b></footer>
            </article>
        </div>
        <div v-else class="empty-hint">{{ t('No orders found') }}</div>
        </template>

        <template #fab>
            <button v-if="!isCourier" class="fab" @click="editing = null; showForm = true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round">
                    <path d="M12 5v14M5 12h14" />
                </svg>
            </button>
        </template>

        <SheetModal :open="!!selected" :title="selected?.track_no" :subtitle="selected?.customer_name_ar" @close="selected = null">
            <template v-if="selected">
                <div class="detail-row">
                    <span class="text-muted">{{ t('Status') }}</span>
                    <StatusBadge :status="selected.status" />
                </div>
                <div class="detail-row">
                    <span class="text-muted">{{ t('Customer') }}</span>
                    <b>{{ selected.customer_name_ar }}</b>
                </div>
                <div class="detail-row">
                    <span class="text-muted">{{ t('Phone') }}</span>
                    <b class="mono">{{ selected.phone }}{{ selected.phone2 ? ' / ' + selected.phone2 : '' }}</b>
                </div>
                <div class="detail-row">
                    <span class="text-muted">{{ t('Address') }}</span>
                    <b>{{ selected.address_ar }}</b>
                </div>
                <div class="detail-row">
                    <span class="text-muted">{{ t('Price') }}</span>
                    <b class="mono">{{ fmt(selected.price) }} د.ع</b>
                </div>
                <div class="detail-row">
                    <span class="text-muted">{{ t('Date') }}</span>
                    <b>{{ selected.date }}</b>
                </div>
                <div v-if="selected.courier" class="detail-row">
                    <span class="text-muted">{{ t('Courier') }}</span>
                    <b>{{ selected.courier.name }}</b>
                </div>

                <div v-if="selected.status !== 'returned'" class="sheet-route" style="margin: 14px 0">
                    <div v-for="s in steps" :key="s.key" class="sheet-step" :class="{ done: s.done, current: s.current }">
                        <span class="sheet-line"></span>
                        <span class="sheet-node">{{ s.done ? '✓' : '' }}</span>
                        <span class="sheet-label">{{ s.label }}</span>
                    </div>
                </div>

                <div v-if="actionsFor(selected).length" class="deliv-actions" style="margin-top: 6px">
                    <button v-for="a in actionsFor(selected)" :key="a.next" class="mini-btn" :class="a.kind" :disabled="busy === selected.id" @click="setStatus(selected, a.next)">
                        <span v-if="busy === selected.id" class="loader"></span>
                        {{ a.label }}
                    </button>
                </div>

                <button v-if="['approved', 'courier'].includes(selected.status)" class="order-complaint" type="button" @click="openComplaint(selected)">
                    شكوى / تأخر
                </button>

                <button v-if="!isCourier && selected.status === 'pending'" class="btn btn-ghost" style="width: 100%; margin-top: 10px" @click="openEdit">{{ t('Edit') }}</button>
            </template>
        </SheetModal>

        <OrderForm :open="showForm" :order="editing" @close="showForm = false" />
    </AppShell>
</template>

<style scoped>
.merchant-status-grid{display:grid;gap:10px}.merchant-status-card{display:flex;align-items:center;gap:13px;min-height:76px;padding:14px 15px;border:1.5px solid var(--border);border-radius:16px;background:var(--surface);color:var(--ink);font:inherit;text-align:right;cursor:pointer;transition:transform .15s}.merchant-status-card:active{transform:scale(.985)}.merchant-status-icon{width:45px;height:45px;display:grid;place-items:center;flex:none;border-radius:13px;background:rgba(255,255,255,.82);font-size:21px;font-weight:900;box-shadow:0 2px 8px rgba(11,110,104,.11)}.merchant-status-copy{display:flex;align-items:center;justify-content:space-between;flex:1;gap:12px}.merchant-status-copy b{font-size:12px;font-weight:900}.merchant-status-copy strong{font-size:24px;font-weight:900;line-height:1}.merchant-status-card>svg{opacity:.58;flex:none}.merchant-status-card.all{border-color:rgba(11,110,104,.25);background:linear-gradient(135deg,#E2F6F4,#C5ECE8);color:#0B6E68}.merchant-status-card.pending{border-color:rgba(217,119,6,.28);background:linear-gradient(135deg,#FFF3E0,#FFE1B3);color:#B45309}.merchant-status-card.approved{border-color:rgba(14,165,233,.28);background:linear-gradient(135deg,#E1F4FF,#C3E9FF);color:#0369A1}.merchant-status-card.courier{border-color:rgba(37,99,235,.28);background:linear-gradient(135deg,#E5EDFF,#CCDCFF);color:#1D4ED8}.merchant-status-card.delivered{border-color:rgba(22,163,74,.28);background:linear-gradient(135deg,#E3F8E9,#C4EFD2);color:#15803D}.merchant-status-card.returned{border-color:rgba(220,38,38,.28);background:linear-gradient(135deg,#FFE9EA,#FFD2D5);color:#B91C1C}.orders-list-head{display:flex;align-items:center;gap:10px;margin-bottom:14px}.orders-back{display:grid;place-items:center;width:36px;height:36px;border:0;border-radius:10px;background:var(--surface-2);color:var(--ink);cursor:pointer}.orders-list-head>b{flex:1;font-size:14px;font-weight:900}.orders-list-head>span{padding:3px 10px;border-radius:20px;background:var(--surface-2);color:var(--ink-soft);font-size:11px;font-weight:800}
.mobile-order-stack{display:grid;gap:10px}.mobile-order-card{overflow:hidden;border:1.5px solid color-mix(in srgb,var(--primary) 35%,var(--border));border-radius:16px;background:linear-gradient(145deg,color-mix(in srgb,var(--primary-tint) 75%,var(--surface)),var(--surface));box-shadow:0 4px 13px rgba(11,110,104,.08);cursor:pointer}.mobile-order-head{display:flex;align-items:center;gap:10px;padding:12px 13px 8px}.mobile-order-head .order-mid{flex:1}.mobile-order-head .order-mid b{font-size:13px}.mobile-order-head :deep(.badge){flex:none}.mobile-order-summary{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:2px 13px 10px}.mobile-order-summary strong{color:var(--primary-strong);font-size:16px;font-weight:900}.mobile-order-summary small{color:var(--ink-faint);font-family:var(--font);font-size:10px}.mobile-vehicle-badge{padding:5px 9px;border:1px solid color-mix(in srgb,var(--primary) 24%,var(--border));border-radius:9px;background:color-mix(in srgb,var(--primary-tint) 75%,var(--surface));color:var(--primary-strong);font-size:10px;font-weight:800}.mobile-order-note{margin:0 13px 10px;padding:6px 8px;border-radius:8px;background:var(--surface-2);color:var(--ink-soft);font-size:10px;font-weight:700}.mobile-order-note b{color:var(--primary-strong)}.mobile-order-timer{display:flex;align-items:center;gap:5px;padding:8px 12px;border-top:1px solid var(--border);background:var(--surface-2);color:var(--success);font-size:10px;font-weight:900}.mobile-order-timer i{width:7px;height:7px;border-radius:50%;background:var(--success);box-shadow:0 0 7px color-mix(in srgb,var(--success) 70%,transparent)}
.order-complaint{display:flex;align-items:center;justify-content:center;width:100%;margin-top:10px;padding:9px 12px;border:1px solid color-mix(in srgb,var(--danger) 24%,transparent);border-radius:10px;background:color-mix(in srgb,var(--danger-tint) 82%,transparent);color:var(--danger);font:inherit;font-size:11px;font-weight:900;cursor:pointer}
.courier-orders-overview .merchant-status-grid{gap:10px}
</style>
