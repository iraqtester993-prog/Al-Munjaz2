<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppShell from '../../Components/AppShell.vue'
import StatusBadge from '../../Components/StatusBadge.vue'
import SheetModal from '../../Components/SheetModal.vue'
import OrderForm from '../../Components/OrderForm.vue'
import { hasPickupLocation, pickupLocationLabel, pickupNavigationHref } from '../../Utils/pickupLocation'

const props = defineProps({
    orders: { type: Array, required: true },
    counts: { type: Object, required: true },
    filter: { type: String, default: 'all' },
    q: { type: String, default: '' },
    list: { type: Boolean, default: false },
    pagination: { type: Object, default: () => ({ next_cursor: null, has_more: false }) },
    isCourier: { type: Boolean, default: false },
    wallet: { type: Object, default: () => ({ balance: 0, budget: 0 }) },
})

const page = usePage()
const locale = computed(() => page.props.locale || 'ar')
const currentRole = computed(() => page.props.auth?.user?.role || '')
const isGeneralCourier = computed(() => currentRole.value === 'courier')
// The courier home is intentionally a compact summary. These query flags
// let its recent-delivery links skip the status overview and, when requested,
// open the selected order after the scoped order list has loaded.
const homeOrderQuery = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search)
const showCourierOrderList = homeOrderQuery.get('list') === '1'
const homeOrderId = Number(homeOrderQuery.get('open') || 0)

const query = ref(props.q)
const active = ref(props.filter)
const merchantOverview = ref(!props.isCourier && props.filter === 'all' && !props.q && !props.list)
const courierOverview = ref(props.isCourier && props.filter === 'all' && !props.q && !props.list && !showCourierOrderList)
const loadedOrders = ref([...props.orders])
const pagination = ref({ ...props.pagination })
const selected = ref(null)
const showForm = ref(false)
const editing = ref(null)
const busy = ref(null)
const returnFlow = ref(null)
const detailLoading = ref(false)
const loadingMore = ref(false)
const now = ref(Date.now())
let ticker

watch(() => props.orders, (orders) => {
    loadedOrders.value = [...(orders || [])]
})

watch(() => props.pagination, (nextPagination) => {
    pagination.value = { ...(nextPagination || {}) }
})

function moneyDigits(value) {
    return String(value ?? '').replace(/[^0-9]/g, '')
}

const returnFeeInput = computed({
    get: () => String(returnFlow.value?.fee ?? '') === '' ? '' : fmt(Number(returnFlow.value.fee || 0)),
    set: (value) => {
        if (returnFlow.value) returnFlow.value.fee = moneyDigits(value)
    },
})

const filters = computed(() => {
    const list = [{ key: 'all', label: t('All') }]
    for (const s of ['pending', 'approved', 'courier', 'delivered', 'returned']) {
        list.push({ key: s, label: tStatus(s) })
    }
    return list
})

const merchantStatusCards = computed(() => [
    { key: 'all', label: t('All Orders'), tone: 'all', count: props.counts.all ?? loadedOrders.value.length ?? 0 },
    { key: 'pending', label: t('Pending'), tone: 'pending', count: props.counts.pending ?? 0 },
    { key: 'approved', label: t('Approved'), tone: 'approved', count: props.counts.approved ?? 0 },
    { key: 'courier', label: t('With Courier'), tone: 'courier', count: props.counts.courier ?? 0 },
    { key: 'delivered', label: t('Delivered'), tone: 'delivered', count: props.counts.delivered ?? 0 },
    { key: 'returned', label: t('Returned'), tone: 'returned', count: props.counts.returned ?? 0 },
])

const courierStatusCards = computed(() => [
    { key: 'pending', label: t('Pending'), tone: 'pending', count: props.counts.pending ?? 0 },
    { key: 'approved', label: t('Approved'), tone: 'approved', count: props.counts.approved ?? 0 },
    { key: 'courier', label: t('With Me'), tone: 'courier', count: props.counts.courier ?? 0 },
    { key: 'delivered', label: t('Delivered'), tone: 'delivered', count: props.counts.delivered ?? 0 },
    { key: 'returned', label: t('Returned'), tone: 'returned', count: props.counts.returned ?? 0 },
])

// Inertia keeps the current page props visible until the filtered response is
// received.  Without this small client-side guard, selecting (for example)
// "Pending" briefly rendered the previous unfiltered list before the server
// replaced it.  Filtering the in-memory collection with the requested status
// makes the transition immediate while the server remains the source of truth
// for the final list.
const visibleOrders = computed(() => {
    if (active.value === 'all') return loadedOrders.value

    return loadedOrders.value.filter((order) => order.status === active.value)
})

function statusIcon(key) {
    const icons = {
        all: { paths: ['M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z'], circles: [] },
        pending: { paths: ['M12 7v5l3 2'], circles: [{ cx: 12, cy: 12, r: 8 }] },
        approved: { paths: ['M12 3 5.5 6v5.2c0 4.1 2.8 7.7 6.5 9.1 3.7-1.4 6.5-5 6.5-9.1V6L12 3Zm-3 9 2 2 4-4'], circles: [] },
        courier: { paths: ['M5.5 18a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm13 0a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7ZM5.5 14.5h5l2.5-5h3l2.5 5M11 9.5h4M14.5 6.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z'], circles: [] },
        delivered: { paths: ['m5 12 4 4L19 6'], circles: [] },
        returned: { paths: ['M7 7l10 10M17 7 7 17'], circles: [] },
    }

    return icons[key] || icons.all
}

function tStatus(s) {
    const m = {
        pending: t('Pending'),
        approved: t('Approved'),
        courier: t('With Courier'),
        delivered: t('Delivered'),
        returned: t('Returned'),
        cancelled: t('Cancelled'),
        damaged: t('Damaged'),
        rejected: t('Rejected'),
    }
    return m[s] || s
}

function changeFilter(key) {
    active.value = key
    if (props.isCourier) courierOverview.value = false
    else merchantOverview.value = false
    router.get(route('app.orders'), { filter: key, q: query.value, list: 1 }, { preserveState: true, replace: true })
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
    router.get(route('app.orders'), { filter: active.value, q: query.value, list: 1 }, { preserveState: true, replace: true })
}

function orderRequestUrl(parameters = {}) {
    const url = new URL(route('app.orders'), window.location.origin)

    Object.entries(parameters).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') url.searchParams.set(key, String(value))
    })

    return url.toString()
}

async function readOrderJson(parameters = {}) {
    const response = await fetch(orderRequestUrl(parameters), {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    })

    if (!response.ok) throw new Error(`Order request failed with ${response.status}`)

    return response.json()
}

async function openOrder(order) {
    if (!order?.id || detailLoading.value) return

    const orderId = Number(order.id)
    selected.value = { ...order, _detailLoading: true }
    detailLoading.value = true

    try {
        const payload = await readOrderJson({ detail: orderId })
        // The user may close the sheet while the request is in flight. Do not
        // unexpectedly reopen it after that intentional close.
        if (selected.value?.id === orderId && selected.value?._detailLoading) {
            selected.value = payload.order
        }
    } catch {
        if (selected.value?.id === orderId && selected.value?._detailLoading) {
            selected.value = { ...order, _detailError: true }
        }
    } finally {
        detailLoading.value = false
    }
}

async function loadMoreOrders() {
    const cursor = pagination.value?.next_cursor
    if (!cursor || loadingMore.value) return

    loadingMore.value = true

    try {
        const payload = await readOrderJson({
            filter: active.value,
            q: query.value,
            list: 1,
            cursor,
        })
        const loadedIds = new Set(loadedOrders.value.map((order) => Number(order.id)))
        const nextOrders = (payload.orders || []).filter((order) => !loadedIds.has(Number(order.id)))

        loadedOrders.value = [...loadedOrders.value, ...nextOrders]
        pagination.value = { ...(payload.pagination || {}) }
    } finally {
        loadingMore.value = false
    }
}

// The backend is authoritative: whenever it rejects a courier operation
// because the last server location is missing or stale, bring the installed
// app back to the explicit consent/update sheet rather than leaving the user
// with an unexplained validation error.
function reopenLocationGateIfRequired(errors) {
    if (!errors?.location) return

    window.dispatchEvent(new CustomEvent('almunjaz:location-required'))
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
            onError: reopenLocationGateIfRequired,
            onFinish: () => (busy.value = null),
        }
    )
}

function startReturn(order) {
    returnFlow.value = { orderId: order.id, step: 'choice', fee: '' }
}

function cancelReturn() {
    returnFlow.value = null
}

function submitReturn(order, feeMode) {
    if (busy.value) return

    const fee = feeMode === 'fee' ? Number.parseInt(returnFlow.value?.fee, 10) : 0
    if (feeMode === 'fee' && (!fee || fee < 1 || fee > 1000000)) return

    busy.value = order.id
    router.post(
        route('app.orders.return', order.id),
        { fee_mode: feeMode, return_fee_applied: feeMode === 'fee' ? fee : null },
        {
            preserveScroll: true,
            onSuccess: () => {
                selected.value = {
                    ...selected.value,
                    status: 'returned',
                    workflow_stage: 'return_pending_merchant',
                    return_fee_applied: fee,
                    returned_at: new Date().toISOString(),
                    returned_to_merchant_at: null,
                    return_fee_charged_at: null,
                }
                returnFlow.value = null
            },
            onError: reopenLocationGateIfRequired,
            onFinish: () => (busy.value = null),
        }
    )
}

function confirmReturnToMerchant(order) {
    if (busy.value) return

    busy.value = order.id
    router.post(
        route('app.orders.return-to-merchant', order.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                selected.value = {
                    ...selected.value,
                    workflow_stage: 'returned_to_merchant',
                    returned_to_merchant_at: new Date().toISOString(),
                    return_fee_charged_at: Number(selected.value?.return_fee_applied || 0) > 0 ? new Date().toISOString() : null,
                }
            },
            onError: reopenLocationGateIfRequired,
            onFinish: () => (busy.value = null),
        }
    )
}

function handleAction(order, status) {
    if (status === 'returned') {
        startReturn(order)
        return
    }

    setStatus(order, status)
}

function canAct(order) {
    if (!props.isCourier) return []

    // These controls deliberately mirror AppOrderController.  A general
    // courier owns both delivery legs and the physical return, while the
    // specialised accounts can only advance their assigned leg.
    const allowedByRole = {
        courier: {
            approved: ['courier'],
            courier: ['delivered', 'returned'],
        },
        pickup_courier: {
            approved: ['courier'],
        },
        delivery_courier: {
            courier: ['delivered'],
        },
    }

    return allowedByRole[currentRole.value]?.[order.status] || []
}

function actionsFor(order) {
    return canAct(order).map((s) => ({
        label: s === 'courier' ? t('Collect Order') : s === 'delivered' ? t('Mark Delivered') : t('Mark Returned'),
        next: s,
        kind: s === 'returned' ? 'danger' : s === 'delivered' ? 'success' : 'primary',
    }))
}

function openEdit() {
    if (!selected.value) return
    editing.value = selected.value
    showForm.value = true
}

function recreateReturnedOrder(order) {
    if (busy.value) return

    busy.value = order.id
    router.post(route('app.orders.recreate', order.id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            selected.value = null
        },
        onFinish: () => (busy.value = null),
    })
}

function republishOrder(order) {
    if (busy.value) return

    busy.value = order.id
    router.post(route('app.orders.republish', order.id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            // The redirect returns the authoritative server-side availability
            // window. Closing the sheet prevents the old in-memory order from
            // showing a made-up 30-minute timer when the dashboard uses a
            // different configured window.
            selected.value = null
        },
        onFinish: () => (busy.value = null),
    })
}

function canDeletePendingOrder(order) {
    return !props.isCourier && Boolean(order?.can_delete_by_merchant)
}

function shouldShowMerchantSupport(order) {
    // Anything the merchant cannot safely withdraw—an accepted, terminal,
    // assigned, or financially linked order—goes to an order-specific
    // support chat that administration can handle from the dashboard.
    return !props.isCourier && !canDeletePendingOrder(order)
}

function deletePendingOrder(order) {
    if (!canDeletePendingOrder(order) || busy.value) return

    if (!window.confirm(`${t('Delete Order')}?`)) return

    busy.value = order.id
    router.delete(route('app.orders.destroy', order.id), {
        preserveScroll: true,
        onSuccess: () => {
            // The server refresh removes the soft-deleted order from the
            // merchant list. Close the sheet so stale details cannot remain
            // on screen after a successful withdrawal.
            selected.value = null
        },
        onFinish: () => (busy.value = null),
    })
}

function openSupport() {
    router.post(route('app.chats.open'), {}, { preserveScroll: true })
}

function openOrderChat(order) {
    router.post(route('app.chats.open'), { order_id: order.id }, { preserveScroll: true })
}

function openComplaint(order) {
    // The order context accompanies a complaint so the destination thread
    // never becomes an anonymous support conversation.
    router.post(route('app.chats.open'), { order_id: order.id, complaint: true }, { preserveScroll: true })
}

function vehicleLabel(order) {
    return {
        normal: t('Regular Delivery'),
        bike: t('Motorcycle'),
        car: t('Sedan'),
        sedan: t('Sedan'),
        suv: t('SUV'),
        truck: t('Van / Truck'),
    }[order.delivery_vehicle] || t('Regular Delivery')
}

function orderTypeLabel(order) {
    return order?.order_type || t('Not specified')
}

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

function customerAddress(order) {
    return localizedOrderValue(order, 'address')
}

function canViewCustomerPhone(order) {
    return Boolean(order?.phone_revealed || order?.phone)
}

const deliverySteps = computed(() => ([
    { status: 'pending', label: t('Pending') },
    { status: 'approved', label: t('Approved') },
    { status: 'courier', label: t('With Courier') },
    { status: 'delivered', label: t('Delivered') },
    { status: 'returned', label: t('Returned') },
]))

function deliveryStepIndex(order) {
    const index = deliverySteps.value.findIndex((step) => step.status === order?.status)
    return index < 0 ? 0 : index
}

function whatsappUrl(phone) {
    if (!phone) return null
    const digits = String(phone).replace(/\D/g, '')
    return `https://wa.me/${digits.startsWith('0') ? `964${digits.slice(1)}` : digits}`
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

    return `${String(minutes).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')} ${t('Minutes abbreviation')}`
}

onMounted(() => {
    ticker = window.setInterval(() => { now.value = Date.now() }, 1000)

    if (props.isCourier && homeOrderId > 0) openOrder({ id: homeOrderId })
})

onUnmounted(() => window.clearInterval(ticker))
</script>

<template>
    <AppShell :title="isCourier ? t('My Deliveries') : t('My Orders')">
        <template #title>
            {{ isCourier ? t('My Deliveries') : t('My Orders') }}
            <span v-if="isCourier" class="tb-sub">{{ t('Available') }}: {{ fmt(wallet.budget) }} / {{ fmt(wallet.balance) }} {{ t('IQD') }}</span>
        </template>

        <section v-if="!isCourier && merchantOverview" class="merchant-orders-overview">
            <h2 class="orders-overview-title">{{ t('My Orders') }}</h2>
            <div class="merchant-status-grid">
                <button v-for="card in merchantStatusCards" :key="card.key" class="merchant-status-card" :class="card.tone" type="button" @click="showMerchantOrders(card.key)">
                    <span class="merchant-status-icon"><svg width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path v-for="(path, index) in statusIcon(card.key).paths" :key="`path-${index}`" :d="path" /><circle v-for="(circle, index) in statusIcon(card.key).circles" :key="`circle-${index}`" :cx="circle.cx" :cy="circle.cy" :r="circle.r" /></svg></span>
                    <strong class="mono">{{ card.count }}</strong>
                    <b>{{ card.label }}</b>
                </button>
            </div>
        </section>

        <section v-else-if="isCourier && courierOverview" class="merchant-orders-overview courier-orders-overview">
            <h2 class="orders-overview-title">{{ t('My Deliveries') }}</h2>
            <div class="merchant-status-grid">
                <button v-for="card in courierStatusCards" :key="card.key" class="merchant-status-card" :class="card.tone" type="button" @click="changeFilter(card.key)">
                    <span class="merchant-status-icon"><svg width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path v-for="(path, index) in statusIcon(card.key).paths" :key="`path-${index}`" :d="path" /><circle v-for="(circle, index) in statusIcon(card.key).circles" :key="`circle-${index}`" :cx="circle.cx" :cy="circle.cy" :r="circle.r" /></svg></span>
                    <strong class="mono">{{ card.count }}</strong>
                    <b>{{ card.label }}</b>
                </button>
            </div>
        </section>

        <template v-else>
        <div class="orders-list-head">
            <button class="orders-back" type="button" @click="isCourier ? backToCourierOverview() : backToMerchantOverview()">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6" /></svg>
            </button>
            <b>{{ active === 'all' ? t('All Orders') : tStatus(active) }}</b>
            <span>{{ counts[active] ?? 0 }}</span>
        </div>

        <div v-if="!isCourier" class="search" style="max-width: 100%; margin-bottom: 12px">
            <input v-model="query" :placeholder="t('Search')" @keyup.enter="doSearch" />
        </div>

        <div v-if="visibleOrders.length" class="mobile-order-stack">
            <article v-for="o in visibleOrders" :key="o.id" class="mobile-order-card" @click="openOrder(o)">
                <header class="mobile-order-head">
                    <div class="order-ic">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 8 12 3 3 8v8l9 5 9-5V8Z M3 8l9 5 9-5 M12 13v8" />
                    </svg>
                    </div>
                    <div class="order-mid">
                        <b>{{ customerName(o) }}</b>
                        <span>{{ customerAddress(o) }}</span>
                    </div>
                    <StatusBadge :status="o.status" />
                </header>
                <div class="mobile-order-summary">
                    <strong class="mono">{{ fmt(o.price) }} <small>{{ t('IQD') }}</small></strong>
                    <span class="mobile-order-tags">
                        <span v-if="o.order_type" class="mobile-order-type-badge">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h13A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 18.5v-13ZM8 9h8M8 12h8M8 15h5"/></svg>
                            {{ orderTypeLabel(o) }}
                        </span>
                        <span class="mobile-vehicle-badge">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 6.5h12V15H2zM14 9.5h3.6l2.6 2.6V15H14"/><circle cx="6.5" cy="16.5" r="1.7"/><circle cx="17" cy="16.5" r="1.7"/></svg>
                            {{ vehicleLabel(o) }}
                        </span>
                    </span>
                </div>
                <p v-if="o.vehicle_note" class="mobile-order-note mobile-order-vehicle-note"><b>{{ t('Vehicle Note') }}:</b> {{ o.vehicle_note }}</p>
                <footer v-if="o.status === 'approved' && pickupText(o)" class="mobile-order-timer"><i></i> {{ t('Time to reach the merchant') }}: <b class="mono">{{ pickupText(o) }}</b></footer>
            </article>
        </div>
        <button v-if="pagination.has_more" class="orders-load-more" type="button" :disabled="loadingMore" @click="loadMoreOrders">
            <span v-if="loadingMore" class="loader"></span>
            <span v-else>{{ t('See all') }}</span>
        </button>
        <div v-else class="empty-hint">{{ t('No orders found') }}</div>
        </template>

        <template #fab>
            <button v-if="!isCourier" class="fab" @click="editing = null; showForm = true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round">
                    <path d="M12 5v14M5 12h14" />
                </svg>
            </button>
        </template>

        <SheetModal :open="!!selected" @close="selected = null">
            <template v-if="selected">
                <section v-if="selected._detailLoading" class="order-detail-loading">
                    <span class="loader"></span>
                    <b>{{ t('Loading...') }}</b>
                </section>
                <section v-else-if="selected._detailError" class="order-detail-loading">
                    <b>{{ t('Retry') }}</b>
                    <button class="btn" type="button" @click="openOrder(selected)">{{ t('Retry') }}</button>
                    <button class="btn order-detail-close" type="button" @click="selected = null">{{ t('Close') }}</button>
                </section>
                <template v-else>
                <section class="order-detail-status">
                    <div class="order-detail-status-head">
                        <span class="order-detail-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8 12 3 3 8v8l9 5 9-5V8ZM3 8l9 5 9-5M12 13v8"/></svg></span>
                        <span class="order-detail-track mono">{{ selected.track_no }}</span>
                        <StatusBadge :status="selected.status" />
                    </div>
                    <div class="order-detail-steps" :style="{ '--active-step': deliveryStepIndex(selected) }">
                        <span v-for="(step, index) in deliverySteps" :key="step.status" class="order-detail-step" :class="{ active: index === deliveryStepIndex(selected), done: index < deliveryStepIndex(selected) }"><i>{{ index + 1 }}</i><b>{{ step.label }}</b></span>
                    </div>
                </section>
                <section class="order-detail-section">
                    <h3>{{ t('Order Details') }}</h3>
                <div class="detail-row">
                    <span class="text-muted">{{ t('Customer') }}</span>
                    <b>{{ customerName(selected) }}</b>
                </div>
                <div class="detail-row">
                    <span class="text-muted">{{ t('Phone') }}</span>
                    <b class="mono">{{ selected.phone }}{{ selected.phone2 ? ' / ' + selected.phone2 : '' }}</b>
                </div>
                <div class="detail-row">
                    <span class="text-muted">{{ t('Address') }}</span>
                    <b>{{ customerAddress(selected) }}</b>
                </div>
                <div class="detail-row">
                    <span class="text-muted">{{ t('Order Type') }}</span>
                    <b class="delivery-vehicle-pill">{{ orderTypeLabel(selected) }}</b>
                </div>
                <div class="detail-row">
                    <span class="text-muted">{{ t('Delivery Vehicle') }}</span>
                    <b class="delivery-vehicle-pill"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8 12 3 3 8v8l9 5 9-5V8ZM3 8l9 5 9-5M12 13v8"/></svg>{{ vehicleLabel(selected) }}</b>
                </div>
                <div v-if="selected.notes" class="detail-note-box"><b>{{ t('Order Note') }}:</b> {{ selected.notes }}</div>
                <div v-if="selected.vehicle_note" class="detail-note-box transport-note-box"><b>{{ t('Vehicle Note') }}:</b> {{ selected.vehicle_note }}</div>
                <div class="detail-row">
                    <span class="text-muted">{{ t('Price') }}</span>
                    <b class="mono">{{ fmt(selected.price) }} {{ t('IQD') }}</b>
                </div>
                <div class="detail-row">
                    <span class="text-muted">{{ t('Delivery Price') }}</span>
                    <b class="mono">{{ fmt(selected.fee) }} {{ t('IQD') }}</b>
                </div>
                </section>

                <a v-if="whatsappUrl(selected.phone)" class="customer-whatsapp" :href="whatsappUrl(selected.phone)" target="_blank" rel="noopener">{{ t('Customer WhatsApp') }}</a>
                <section v-if="isCourier && selected.merchant" class="courier-merchant-card">
                    <span class="merchant-card-label">{{ t('Merchant') }}</span>
                    <div class="merchant-card-profile"><span class="merchant-avatar">{{ selected.merchant.name?.slice(0, 1) }}</span><span><b>{{ selected.merchant.shop_name || selected.merchant.name }} <i v-if="selected.merchant.verified" class="merchant-verified" :title="t('Verified')">✓</i></b><small v-if="selected.merchant.address" class="merchant-info-row"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 10c0 5.2-8 11-8 11S4 15.2 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>{{ selected.merchant.address }}</small><small v-if="selected.merchant.phone" class="merchant-info-row mono"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7A2 2 0 0 1 22 16.9Z"/></svg>{{ selected.merchant.phone }}</small></span></div>
                    <a v-if="hasPickupLocation(selected)" class="merchant-location-row" :href="pickupNavigationHref(selected)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 10c0 5.2-8 11-8 11S4 15.2 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>{{ t('Merchant location') }} · {{ pickupLocationLabel(selected, t('Merchant pickup location')) }}</a>
                    <a
                        v-if="hasPickupLocation(selected)"
                        class="merchant-pickup-location"
                        :href="pickupNavigationHref(selected)"
                        :aria-label="`${t('Open navigation apps')}: ${pickupLocationLabel(selected, t('Merchant pickup location'))}`"
                    >
                        <span class="merchant-pickup-icon" aria-hidden="true"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 5.2-8 11-8 11S4 15.2 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg></span>
                        <span class="merchant-pickup-copy">
                            <small>{{ t('Merchant pickup location') }}</small>
                            <b>{{ pickupLocationLabel(selected, t('Merchant pickup location')) }}</b>
                            <em>{{ t('The merchant saved this pickup point with the order.') }}</em>
                        </span>
                        <span class="merchant-pickup-open"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 14-7-4 14-3-6-7-1Z"/><path d="m12 13 3-3"/></svg>{{ t('Open navigation apps') }}</span>
                    </a>
                    <div class="merchant-card-actions"><a v-if="whatsappUrl(selected.merchant.phone)" :href="whatsappUrl(selected.merchant.phone)" target="_blank" rel="noopener">{{ t('WhatsApp') }}</a><button type="button" @click="openOrderChat(selected)">{{ t('Chat') }}</button></div>
                </section>
                <section v-else-if="!isCourier && selected.assigned_courier" class="courier-merchant-card merchant-courier-card">
                    <span class="merchant-card-label">{{ t('Courier') }}</span>
                    <div class="merchant-card-profile"><span class="merchant-avatar courier-avatar">{{ selected.assigned_courier.name?.slice(0, 1) }}</span><span><b>{{ selected.assigned_courier.name }}</b><small v-if="selected.assigned_courier.vehicle" class="merchant-info-row">{{ vehicleLabel({ delivery_vehicle: selected.assigned_courier.vehicle }) }}</small><small v-if="selected.assigned_courier.phone" class="merchant-info-row mono"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7A2 2 0 0 1 22 16.9Z"/></svg>{{ selected.assigned_courier.phone }}</small></span></div>
                    <div class="merchant-card-actions"><a v-if="whatsappUrl(selected.assigned_courier.phone)" :href="whatsappUrl(selected.assigned_courier.phone)" target="_blank" rel="noopener">{{ t('WhatsApp') }}</a><button type="button" @click="openOrderChat(selected)">{{ t('Chat') }}</button></div>
                </section>
                <section v-else-if="!isCourier" class="courier-merchant-card courier-assignment-pending">
                    <span class="merchant-card-label">{{ t('Courier') }}</span>
                    <b>{{ t('Waiting for courier assignment') }}</b>
                    <small>{{ t('Courier details will appear here immediately after the order is accepted.') }}</small>
                </section>
                <section v-if="selected.status === 'approved' && pickupText(selected)" class="pickup-countdown"><b>{{ t('Time to reach the merchant') }}</b><strong class="mono">{{ pickupText(selected) }}</strong></section>

                <section v-if="returnFlow?.orderId === selected.id" class="return-flow">
                    <template v-if="returnFlow.step === 'choice'">
                        <h4>{{ t('Return Order') }}</h4>
                        <p>{{ t('Choose whether this failed delivery has a return delivery fee. The fee is recorded only after you confirm handing the parcel back to the merchant.') }}</p>
                        <button class="mini-btn danger return-flow-button" :disabled="busy === selected.id" @click="submitReturn(selected, 'none')">
                            <span v-if="busy === selected.id" class="loader"></span>
                            <span v-else>{{ t('Without Delivery Fee') }}</span>
                        </button>
                        <button class="mini-btn primary return-flow-button" :disabled="busy === selected.id" @click="returnFlow.step = 'fee'">{{ t('With Delivery Fee') }}</button>
                        <button class="return-flow-cancel" type="button" @click="cancelReturn">{{ t('Cancel') }}</button>
                    </template>
                    <template v-else>
                        <h4>{{ t('Return fee') }}</h4>
                        <p>{{ t('Enter the delivery fee for this returned order.') }}</p>
                        <div class="return-fee-field">
                            <input v-model="returnFeeInput" type="text" inputmode="numeric" dir="ltr" :placeholder="t('Amount')">
                            <span>{{ t('IQD') }}</span>
                        </div>
                        <div class="return-fee-presets"><button v-for="amount in [1000, 2000, 3000, 5000]" :key="amount" type="button" @click="returnFlow.fee = amount">{{ fmt(amount) }}</button></div>
                        <button class="mini-btn primary return-flow-button" :disabled="busy === selected.id || !returnFlow.fee" @click="submitReturn(selected, 'fee')">
                            <span v-if="busy === selected.id" class="loader"></span>
                            <span v-else>{{ t('Confirm with Fee') }}</span>
                        </button>
                        <button class="return-flow-cancel" type="button" @click="returnFlow.step = 'choice'">{{ t('Back') }}</button>
                    </template>
                </section>

                <section v-else-if="isGeneralCourier && selected.status === 'returned'" class="return-flow return-confirmation">
                    <template v-if="!selected.returned_to_merchant_at">
                        <h4>{{ t('Return to Merchant') }}</h4>
                        <p>{{ Number(selected.return_fee_applied || 0) > 0 ? t('The selected fee will be posted to the financial ledger after you confirm the parcel was handed back to the merchant.') : t('Confirm only after the parcel has physically been handed back to the merchant.') }}</p>
                        <b v-if="Number(selected.return_fee_applied || 0) > 0" class="return-fee-summary">{{ t('Return fee') }}: {{ fmt(selected.return_fee_applied) }} {{ t('IQD') }}</b>
                        <button class="mini-btn danger return-flow-button" :disabled="busy === selected.id" @click="confirmReturnToMerchant(selected)">
                            <span v-if="busy === selected.id" class="loader"></span>
                            <span v-else>{{ t('Confirm Return') }}</span>
                        </button>
                    </template>
                    <template v-else>
                        <h4>{{ t('Returned to merchant') }}</h4>
                        <p>{{ t('The handback was confirmed and has been recorded in the operational timeline.') }}</p>
                        <b v-if="Number(selected.return_fee_applied || 0) > 0" class="return-fee-summary">{{ t('Return fee') }}: {{ fmt(selected.return_fee_applied) }} {{ t('IQD') }}</b>
                    </template>
                </section>

                <div v-else-if="actionsFor(selected).length" class="deliv-actions order-detail-actions">
                    <button v-for="a in actionsFor(selected)" :key="a.next" class="mini-btn" :class="a.kind" :disabled="busy === selected.id" @click="handleAction(selected, a.next)">
                        <span v-if="busy === selected.id" class="loader"></span>
                        {{ a.label }}
                    </button>
                </div>

                <button v-if="shouldShowMerchantSupport(selected)" class="order-complaint" type="button" @click="openComplaint(selected)">
                    {{ t('Contact Support') }}
                </button>

                <button v-else-if="isCourier && ['approved', 'courier'].includes(selected.status)" class="order-complaint" type="button" @click="openSupport">
                    {{ t('Contact Support to Cancel') }}
                </button>

                <button v-if="!isCourier && selected.status === 'returned'" class="order-recreate" type="button" :disabled="busy === selected.id" @click="recreateReturnedOrder(selected)">
                    <span v-if="busy === selected.id" class="loader"></span>
                    <span v-else>{{ t('Add New Order') }}</span>
                </button>

                <button v-if="!isCourier && selected.status === 'pending' && !selected.courier_id" class="order-recreate" type="button" :disabled="busy === selected.id" @click="republishOrder(selected)">
                    <span v-if="busy === selected.id" class="loader"></span><span v-else>{{ t('Republish Order') }}</span>
                </button>

                <button v-if="canDeletePendingOrder(selected)" class="order-delete" type="button" :disabled="busy === selected.id" @click="deletePendingOrder(selected)">
                    <span v-if="busy === selected.id" class="loader"></span><span v-else>{{ t('Delete Order') }}</span>
                </button>

                <button v-if="!isCourier && selected.status === 'pending'" class="btn btn-ghost" style="width: 100%; margin-top: 10px" @click="openEdit">{{ t('Edit') }}</button>
                <button class="btn order-detail-close" type="button" @click="selected = null">{{ t('Close') }}</button>
                </template>
            </template>
        </SheetModal>

        <OrderForm :open="showForm" :order="editing" @close="showForm = false" />
    </AppShell>
</template>

<style scoped>
.orders-overview-title{margin:3px 0 18px;color:var(--ink);font-size:19px;font-weight:900}.merchant-status-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.merchant-status-card{display:grid;min-height:164px;place-items:center;align-content:center;gap:10px;padding:16px 10px;border:1.5px solid var(--border);border-radius:25px;background:var(--surface);color:var(--ink);font:inherit;text-align:center;cursor:pointer;transition:transform .15s,box-shadow .15s}.merchant-status-card:active{transform:scale(.985);box-shadow:0 3px 10px rgba(11,110,104,.08)}.merchant-status-icon{width:62px;height:62px;display:grid;place-items:center;border-radius:18px}.merchant-status-card strong{font-size:26px;font-weight:900;line-height:1}.merchant-status-card b{color:var(--ink-soft);font-size:12px;font-weight:900}.merchant-status-card.pending .merchant-status-icon{background:#FFF2C7;color:#E88400}.merchant-status-card.pending strong{color:#D97706}.merchant-status-card.approved .merchant-status-icon{background:#E0F2FE;color:#0EA5E9}.merchant-status-card.approved strong{color:#0EA5E9}.merchant-status-card.courier .merchant-status-icon{background:#DBEAFE;color:#2563EB}.merchant-status-card.courier strong{color:#2563EB}.merchant-status-card.delivered .merchant-status-icon{background:#DCFCE7;color:#16A34A}.merchant-status-card.delivered strong{color:#16A34A}.merchant-status-card.returned .merchant-status-icon{background:#FEE2E2;color:#DC2626}.merchant-status-card.returned strong{color:#DC2626}.orders-list-head{display:flex;align-items:center;gap:10px;margin-bottom:14px}.orders-back{display:grid;place-items:center;width:36px;height:36px;border:0;border-radius:10px;background:var(--surface-2);color:var(--ink);cursor:pointer}.orders-list-head>b{flex:1;font-size:14px;font-weight:900}.orders-list-head>span{padding:3px 10px;border-radius:20px;background:var(--surface-2);color:var(--ink-soft);font-size:11px;font-weight:800}
.mobile-order-stack{display:grid;gap:10px}.mobile-order-card{overflow:hidden;border:1.5px solid color-mix(in srgb,var(--primary) 35%,var(--border));border-radius:16px;background:linear-gradient(145deg,color-mix(in srgb,var(--primary-tint) 75%,var(--surface)),var(--surface));box-shadow:0 4px 13px rgba(11,110,104,.08);cursor:pointer}.mobile-order-head{display:flex;align-items:center;gap:10px;padding:12px 13px 8px}.mobile-order-head .order-mid{flex:1}.mobile-order-head .order-mid b{font-size:13px}.mobile-order-head :deep(.badge){flex:none}.mobile-order-summary{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:2px 13px 10px}.mobile-order-summary strong{color:var(--primary-strong);font-size:16px;font-weight:900}.mobile-order-summary small{color:var(--ink-faint);font-family:var(--font);font-size:10px}.mobile-order-tags{display:flex;align-items:center;justify-content:flex-end;gap:5px;min-width:0;flex-wrap:wrap}.mobile-vehicle-badge,.mobile-order-type-badge{display:inline-flex;align-items:center;gap:4px;max-width:125px;padding:5px 9px;border:1px solid color-mix(in srgb,var(--primary) 24%,var(--border));border-radius:9px;background:color-mix(in srgb,var(--primary-tint) 75%,var(--surface));color:var(--primary-strong);font-size:10px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.mobile-order-type-badge{border-color:color-mix(in srgb,var(--accent) 26%,var(--border));background:color-mix(in srgb,var(--accent-tint) 70%,var(--surface));color:var(--accent)}.mobile-order-note{margin:0 13px 10px;padding:6px 8px;border-radius:8px;background:var(--surface-2);color:var(--ink-soft);font-size:10px;font-weight:700}.mobile-order-note b{color:var(--primary-strong)}.mobile-order-timer{display:flex;align-items:center;gap:5px;padding:8px 12px;border-top:1px solid var(--border);background:var(--surface-2);color:var(--success);font-size:10px;font-weight:900}.mobile-order-timer i{width:7px;height:7px;border-radius:50%;background:var(--success);box-shadow:0 0 7px color-mix(in srgb,var(--success) 70%,transparent)}
.orders-load-more{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:42px;margin-top:12px;border:1px solid color-mix(in srgb,var(--primary) 28%,var(--border));border-radius:12px;background:var(--primary-tint);color:var(--primary-strong);font:900 12px var(--font);cursor:pointer}.orders-load-more:disabled{opacity:.65;cursor:wait}
.mobile-pickup-location-card{display:grid;gap:8px;margin:14px 0;padding:13px;border:1.5px solid color-mix(in srgb,var(--success) 42%,var(--border));border-radius:14px;background:linear-gradient(135deg,color-mix(in srgb,var(--success-tint) 72%,var(--surface)),var(--surface))}.mobile-pickup-location-head{display:flex;align-items:center;gap:9px}.mobile-pickup-location-icon{display:grid;place-items:center;width:38px;height:38px;flex:none;border-radius:11px;background:var(--success);color:#fff}.mobile-pickup-location-head span:last-child{display:grid;gap:2px;min-width:0}.mobile-pickup-location-head small{color:var(--ink-faint);font-size:9.5px;font-weight:800}.mobile-pickup-location-head b{overflow:hidden;color:var(--ink);font-size:12px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.mobile-pickup-location-card p{margin:0;color:var(--ink-soft);font-size:10px;font-weight:700;line-height:1.55}.mobile-pickup-location-action{display:flex;align-items:center;justify-content:center;gap:6px;min-height:38px;border-radius:10px;background:var(--primary);color:#fff;font-size:10.5px;font-weight:900;text-decoration:none;box-shadow:0 4px 10px rgba(11,110,104,.16)}
.order-detail-status{margin:-2px 0 18px;padding-bottom:15px;border-bottom:1px solid var(--border)}
.order-detail-loading{display:grid;justify-items:center;gap:14px;min-height:180px;padding:36px 18px;color:var(--ink-soft);text-align:center}.order-detail-loading .btn{min-width:130px}.order-detail-loading .order-detail-close{margin-top:0}
.order-detail-status-head{display:flex;align-items:center;gap:8px}.order-detail-status-head :deep(.badge){margin-inline-start:auto;flex:none}.order-detail-track{color:var(--primary-strong);font-size:16px;font-weight:900}.order-detail-icon{display:grid;place-items:center;width:32px;height:32px;border-radius:10px;background:var(--primary-tint);color:var(--primary-strong)}
.order-detail-steps{position:relative;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:2px;margin-top:19px}.order-detail-steps::before{position:absolute;top:12px;inset-inline:10%;height:2px;background:var(--border);content:""}.order-detail-step{position:relative;z-index:1;display:grid;justify-items:center;gap:5px;min-width:0;color:var(--ink-faint);font-size:8.5px;font-weight:900;line-height:1.35;text-align:center}.order-detail-step b{overflow:hidden;max-width:100%;text-overflow:ellipsis}.order-detail-step i{display:grid;place-items:center;width:25px;height:25px;border:2px solid var(--border);border-radius:50%;background:var(--surface);font-size:10px;font-style:normal;color:var(--ink-faint)}.order-detail-step.done{color:var(--success)}.order-detail-step.done i{border-color:var(--success);background:var(--success);color:#fff}.order-detail-step.active{color:var(--ink)}.order-detail-step.active i{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-tint);color:var(--accent)}
.order-detail-section{padding:3px 0 0}.order-detail-section h3{margin:0 0 10px;color:var(--ink);font-size:15px;font-weight:900}.order-detail-section .detail-row{align-items:center;padding:7px 0}.order-detail-section .detail-row>b{font-size:12px;font-weight:800;text-align:start}.delivery-vehicle-pill{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border:1px solid color-mix(in srgb,var(--primary) 25%,var(--border));border-radius:10px;background:var(--primary-tint);color:var(--primary-strong)}.detail-note-box{margin:8px 0;padding:9px 10px;border-radius:10px;background:var(--surface-2);color:var(--ink-soft);font-size:11px;font-weight:700;line-height:1.55}.detail-note-box b{color:var(--danger)}.transport-note-box b{color:var(--primary-strong)}
.customer-whatsapp{display:flex;align-items:center;justify-content:center;min-height:42px;margin:12px 0;border-radius:10px;background:#e0f0eb;color:#079050;font-size:12px;font-weight:900;text-decoration:none}.courier-merchant-card{display:grid;gap:9px;margin:16px 0;padding:14px;border:1.5px solid color-mix(in srgb,var(--primary) 35%,var(--border));border-radius:14px;background:var(--primary-tint)}.merchant-courier-card{background:linear-gradient(135deg,color-mix(in srgb,#dbeafe 68%,var(--surface)),var(--surface));border-color:color-mix(in srgb,#2563eb 32%,var(--border))}.merchant-card-label{color:var(--primary-strong);font-size:11px;font-weight:900}.merchant-card-profile{display:flex;align-items:center;gap:10px}.merchant-card-profile>span:last-child{display:grid;gap:3px;min-width:0}.merchant-card-profile b{font-size:14px;color:var(--ink)}.merchant-avatar{display:grid;place-items:center;order:-1;width:43px;height:43px;flex:none;border-radius:50%;background:var(--primary);color:#fff;font-size:18px;font-weight:900}.courier-avatar{background:#2563eb}.merchant-verified{display:inline-grid;place-items:center;width:15px;height:15px;margin-inline-start:4px;border-radius:50%;background:#1d9bf0;color:#fff;font-size:10px;font-style:normal;line-height:1;vertical-align:1px}.merchant-info-row{display:flex;align-items:center;gap:4px;overflow:hidden;color:var(--ink-soft);font-size:10px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}.merchant-info-row svg{flex:none}.merchant-location-row{display:flex;align-items:center;gap:5px;overflow:hidden;padding:9px 10px;border-radius:10px;background:color-mix(in srgb,var(--success-tint) 78%,var(--surface));color:var(--success);font-size:10.5px;font-weight:900;text-decoration:none;text-overflow:ellipsis;white-space:nowrap}.merchant-location-row svg{flex:none}.merchant-card-actions{display:grid;grid-template-columns:1fr 1fr;gap:7px}.merchant-card-actions>*{display:grid;place-items:center;min-height:38px;border:0;border-radius:10px;background:var(--primary);color:#fff;font:900 11px var(--font);text-decoration:none;cursor:pointer}.merchant-card-actions a{background:color-mix(in srgb,var(--success-tint) 72%,var(--surface));color:var(--success)}.courier-assignment-pending{background:var(--surface-2);border-color:var(--border)}.courier-assignment-pending>b{font-size:12px}.courier-assignment-pending small{color:var(--ink-soft);font-size:10px;font-weight:700;line-height:1.6}
.pickup-countdown{display:grid;justify-items:center;gap:6px;margin:14px 0;padding:13px;border:1px solid #edc980;border-radius:15px;background:#fff4de;color:var(--ink)}.pickup-countdown b{font-size:12px}.pickup-countdown strong{color:#c58315;font-size:26px;font-weight:900}.order-detail-actions{display:grid;gap:8px;margin-top:12px}.order-detail-actions .mini-btn{width:100%;min-height:48px;border-radius:13px;font-size:13px}.order-detail-close{width:100%;min-height:48px;margin-top:10px;border:1px solid var(--border);border-radius:13px;background:var(--surface-2);color:var(--ink);font-size:13px;font-weight:900}
.order-complaint{display:flex;align-items:center;justify-content:center;width:100%;margin-top:10px;padding:9px 12px;border:1px solid color-mix(in srgb,var(--danger) 24%,transparent);border-radius:10px;background:color-mix(in srgb,var(--danger-tint) 82%,transparent);color:var(--danger);font:inherit;font-size:11px;font-weight:900;cursor:pointer}
.order-chat{border-color:color-mix(in srgb,var(--primary) 28%,transparent);background:var(--primary-tint);color:var(--primary-strong)}
.customer-phone-locked{letter-spacing:1px;color:var(--ink-faint);font-size:12px}
.order-recreate{display:flex;align-items:center;justify-content:center;width:100%;min-height:39px;margin-top:10px;border:1px solid color-mix(in srgb,var(--primary) 28%,transparent);border-radius:10px;background:var(--primary-tint);color:var(--primary-strong);font:900 11px var(--font);cursor:pointer}.order-recreate:disabled{opacity:.6;cursor:wait}
.order-delete{display:flex;align-items:center;justify-content:center;width:100%;min-height:39px;margin-top:10px;border:1px solid color-mix(in srgb,var(--danger) 48%,var(--border));border-radius:10px;background:transparent;color:var(--danger);font:900 11px var(--font);cursor:pointer}.order-delete:disabled{opacity:.6;cursor:wait}
.return-flow{display:grid;gap:10px;margin-top:15px;padding:13px;border:1px solid color-mix(in srgb,var(--danger) 30%,var(--border));border-radius:14px;background:color-mix(in srgb,var(--danger-tint) 48%,var(--surface))}.return-flow h4{margin:0;color:var(--ink);font-size:13px;font-weight:900}.return-flow p{margin:0;color:var(--ink-soft);font-size:10.5px;font-weight:700;line-height:1.65}.return-flow-button{width:100%;min-height:39px}.return-flow-cancel{border:0;background:transparent;color:var(--ink-soft);font:800 11px var(--font);cursor:pointer}.return-fee-field{display:flex;align-items:center;gap:8px;border:1px solid color-mix(in srgb,var(--danger) 26%,var(--border));border-radius:10px;background:var(--surface);padding:0 10px}.return-fee-field input{width:100%;min-height:39px;border:0;outline:0;background:transparent;color:var(--ink);font:900 14px var(--font)}.return-fee-field span{color:var(--ink-faint);font-size:10px;font-weight:800}.return-fee-presets{display:flex;flex-wrap:wrap;gap:6px}.return-fee-presets button{border:0;border-radius:8px;background:var(--surface-2);color:var(--ink-soft);font:800 10px var(--font);padding:6px 9px;cursor:pointer}.return-fee-summary{padding:8px 10px;border-radius:9px;background:var(--surface);color:var(--danger);font-size:11px}.return-confirmation{border-color:color-mix(in srgb,var(--accent) 32%,var(--border));background:color-mix(in srgb,var(--accent-tint) 50%,var(--surface))}.return-confirmation .return-fee-summary{color:var(--accent)}
.courier-orders-overview .merchant-status-grid{gap:10px}
.merchant-status-card.all .merchant-status-icon{background:var(--primary-tint);color:var(--primary-strong)}.merchant-status-card.all strong{color:var(--primary-strong)}.mobile-order-note{border:1px solid color-mix(in srgb,var(--danger) 18%,transparent);background:color-mix(in srgb,var(--danger-tint) 70%,var(--surface))}.mobile-order-note b{color:var(--danger)}
.mobile-order-vehicle-note{border-color:color-mix(in srgb,var(--primary) 22%,transparent);background:color-mix(in srgb,var(--primary-tint) 68%,var(--surface))}.mobile-order-vehicle-note b{color:var(--primary-strong)}
.mobile-operational-section{margin:15px 0}.mobile-operational-section h4{margin:0 0 9px;color:var(--ink);font-size:12px;font-weight:900}.mobile-branch-route{display:grid;gap:5px;margin-top:9px;padding:11px 12px;border:1px solid color-mix(in srgb,var(--primary) 24%,var(--border));border-radius:12px;background:color-mix(in srgb,var(--primary-tint) 62%,var(--surface));color:var(--primary-strong)}.mobile-route-label{font-size:10px;font-weight:900;color:var(--ink-soft)}.mobile-branch-route>b{font-size:12px}.mobile-branch-route small{color:var(--ink-soft);font-size:10px;font-weight:700}.mobile-order-timeline{display:grid;gap:0}.mobile-timeline-event{display:grid;grid-template-columns:28px 1fr;gap:9px;min-height:57px}.mobile-timeline-rail{position:relative;display:flex;justify-content:center}.mobile-timeline-rail::after{position:absolute;top:24px;bottom:-3px;width:1px;background:var(--border);content:""}.mobile-timeline-event:last-child .mobile-timeline-rail::after{display:none}.mobile-timeline-marker{position:relative;z-index:1;display:grid;place-items:center;width:23px;height:23px;border:1px solid var(--border);border-radius:50%;background:var(--surface);color:var(--ink-soft);font-size:11px;font-weight:900}.mobile-timeline-marker.is-created{border-color:color-mix(in srgb,var(--primary) 40%,var(--border));background:var(--primary-tint);color:var(--primary-strong)}.mobile-timeline-marker.is-status{border-color:color-mix(in srgb,var(--success) 44%,var(--border));background:var(--success-tint);color:var(--success)}.mobile-timeline-marker.is-movement{border-color:color-mix(in srgb,var(--accent) 42%,var(--border));background:var(--accent-tint);color:var(--accent)}.mobile-timeline-copy{display:grid;gap:2px;padding:1px 0 14px}.mobile-timeline-copy>b{font-size:11px;color:var(--ink)}.mobile-timeline-copy p{margin:0;color:var(--ink-soft);font-size:10px;line-height:1.55}.mobile-timeline-copy time{color:var(--ink-faint);font-size:9px}
.merchant-location-row{display:none}.merchant-pickup-location{display:grid;grid-template-columns:38px minmax(0,1fr);gap:9px;padding:10px;border:1.5px solid color-mix(in srgb,var(--success) 45%,var(--border));border-radius:12px;background:linear-gradient(135deg,color-mix(in srgb,var(--success-tint) 76%,var(--surface)),var(--surface));color:inherit;text-decoration:none;box-shadow:0 3px 9px rgba(11,110,104,.06)}.merchant-pickup-icon{display:grid;place-items:center;width:38px;height:38px;border-radius:11px;background:var(--success);color:#fff}.merchant-pickup-copy{display:grid;min-width:0;gap:2px}.merchant-pickup-copy small{color:var(--ink-faint);font-size:9px;font-weight:850}.merchant-pickup-copy b{overflow:hidden;color:var(--ink);font-size:11.5px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.merchant-pickup-copy em{overflow:hidden;color:var(--ink-soft);font-size:9.5px;font-style:normal;font-weight:700;line-height:1.45;text-overflow:ellipsis;white-space:nowrap}.merchant-pickup-open{display:flex;grid-column:1/-1;align-items:center;justify-content:center;gap:6px;min-height:35px;border-radius:9px;background:var(--primary);color:#fff;font-size:10.5px;font-weight:900;box-shadow:0 3px 8px rgba(11,110,104,.16)}.merchant-pickup-open svg{flex:none}
</style>
