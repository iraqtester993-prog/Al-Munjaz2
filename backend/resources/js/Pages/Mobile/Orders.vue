<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
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
    isCourier: { type: Boolean, default: false },
    wallet: { type: Object, default: () => ({ balance: 0, budget: 0 }) },
})

const page = usePage()
const locale = computed(() => page.props.locale || 'ar')
const currentRole = computed(() => page.props.auth?.user?.role || '')
const isGeneralCourier = computed(() => currentRole.value === 'courier')

const query = ref(props.q)
const active = ref(props.filter)
const merchantOverview = ref(!props.isCourier && props.filter === 'all' && !props.q)
const courierOverview = ref(props.isCourier && props.filter === 'all' && !props.q)
const selected = ref(null)
const showForm = ref(false)
const editing = ref(null)
const busy = ref(null)
const returnFlow = ref(null)
const now = ref(Date.now())
let ticker

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
    { key: 'all', label: t('All Orders'), tone: 'all', count: props.counts.all ?? props.orders.length ?? 0 },
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
            selected.value = {
                ...selected.value,
                pickup_deadline_at: new Date(Date.now() + 30 * 60 * 1000).toISOString(),
            }
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

        <div v-if="orders.length" class="mobile-order-stack">
            <article v-for="o in orders" :key="o.id" class="mobile-order-card" @click="openOrder(o)">
                <header class="mobile-order-head">
                    <div class="order-ic">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 8 12 3 3 8v8l9 5 9-5V8Z M3 8l9 5 9-5 M12 13v8" />
                    </svg>
                    </div>
                    <div class="order-mid">
                        <b>{{ customerName(o) }}</b>
                        <span class="mono">{{ o.track_no }} · {{ customerAddress(o) }}</span>
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
                <p v-if="o.vehicle_note || o.notes" class="mobile-order-note"><b>{{ t('Order Note') }}:</b> {{ o.vehicle_note || o.notes }}</p>
                <footer v-if="o.status === 'approved' && pickupText(o)" class="mobile-order-timer"><i></i> {{ t('Time to reach the merchant') }}: <b class="mono">{{ pickupText(o) }}</b></footer>
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

        <SheetModal :open="!!selected" @close="selected = null">
            <template v-if="selected">
                <section class="order-detail-status">
                    <div class="order-detail-status-head">
                        <StatusBadge :status="selected.status" />
                        <span class="order-detail-track mono">{{ selected.track_no }}</span>
                        <span class="order-detail-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8 12 3 3 8v8l9 5 9-5V8ZM3 8l9 5 9-5M12 13v8"/></svg></span>
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
                    <b>{{ orderTypeLabel(selected) }}</b>
                </div>
                <div class="detail-row">
                    <span class="text-muted">{{ t('Delivery Vehicle') }}</span>
                    <b>{{ vehicleLabel(selected) }}</b>
                </div>
                <div class="detail-row">
                    <span class="text-muted">{{ t('Price') }}</span>
                    <b class="mono">{{ fmt(selected.price) }} {{ t('IQD') }}</b>
                </div>
                </section>

                <a v-if="whatsappUrl(selected.phone)" class="customer-whatsapp" :href="whatsappUrl(selected.phone)" target="_blank" rel="noopener">{{ t('Customer WhatsApp') }}</a>
                <section v-if="selected.merchant" class="courier-merchant-card">
                    <span class="merchant-card-label">{{ t('Merchant') }}</span>
                    <div class="merchant-card-profile"><span class="merchant-avatar">{{ selected.merchant.name?.slice(0, 1) }}</span><span><b>{{ selected.merchant.name }}</b><small v-if="selected.merchant.address" class="merchant-info-row">{{ selected.merchant.address }}</small><small v-if="selected.merchant.phone" class="merchant-info-row mono">{{ selected.merchant.phone }}</small></span></div>
                    <a v-if="hasPickupLocation(selected)" class="merchant-location-row" :href="pickupNavigationHref(selected)">{{ t('Merchant location') }} · {{ pickupLocationLabel(selected, t('Merchant pickup location')) }}</a>
                    <div class="merchant-card-actions"><a v-if="whatsappUrl(selected.merchant.phone)" :href="whatsappUrl(selected.merchant.phone)" target="_blank" rel="noopener">{{ t('WhatsApp') }}</a><button type="button" @click="openOrderChat(selected)">{{ t('Chat') }}</button></div>
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

                <button v-if="selected.courier" class="order-complaint order-chat" type="button" @click="openOrderChat(selected)">
                    {{ isCourier ? t('Chat with merchant') : t('Chat with courier') }}
                </button>

                <button v-if="!isCourier && ['approved', 'courier'].includes(selected.status)" class="order-complaint" type="button" @click="openComplaint(selected)">
                    {{ t('Contact Support') }}
                </button>

                <button v-else-if="['approved', 'courier'].includes(selected.status)" class="order-complaint" type="button" @click="openSupport">
                    {{ t('Contact Support') }}
                </button>

                <button v-if="!isCourier && selected.status === 'returned'" class="order-recreate" type="button" :disabled="busy === selected.id" @click="recreateReturnedOrder(selected)">
                    <span v-if="busy === selected.id" class="loader"></span>
                    <span v-else>{{ t('Add New Order') }}</span>
                </button>

                <button v-if="!isCourier && selected.status === 'pending' && !selected.courier_id" class="order-recreate" type="button" :disabled="busy === selected.id" @click="republishOrder(selected)">
                    <span v-if="busy === selected.id" class="loader"></span><span v-else>{{ t('Republish Order') }}</span>
                </button>

                <button v-if="!isCourier && selected.status === 'pending'" class="btn btn-ghost" style="width: 100%; margin-top: 10px" @click="openEdit">{{ t('Edit') }}</button>
            </template>
        </SheetModal>

        <OrderForm :open="showForm" :order="editing" @close="showForm = false" />
    </AppShell>
</template>

<style scoped>
.orders-overview-title{margin:3px 0 18px;color:var(--ink);font-size:19px;font-weight:950}.merchant-status-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.merchant-status-card{display:grid;min-height:164px;place-items:center;align-content:center;gap:10px;padding:16px 10px;border:1.5px solid var(--border);border-radius:25px;background:var(--surface);color:var(--ink);font:inherit;text-align:center;cursor:pointer;transition:transform .15s,box-shadow .15s}.merchant-status-card:active{transform:scale(.985);box-shadow:0 3px 10px rgba(11,110,104,.08)}.merchant-status-icon{width:62px;height:62px;display:grid;place-items:center;border-radius:18px}.merchant-status-card strong{font-size:26px;font-weight:950;line-height:1}.merchant-status-card b{color:var(--ink-soft);font-size:12px;font-weight:900}.merchant-status-card.pending .merchant-status-icon{background:#FFF2C7;color:#E88400}.merchant-status-card.pending strong{color:#D97706}.merchant-status-card.approved .merchant-status-icon{background:#E0F2FE;color:#0EA5E9}.merchant-status-card.approved strong{color:#0EA5E9}.merchant-status-card.courier .merchant-status-icon{background:#DBEAFE;color:#2563EB}.merchant-status-card.courier strong{color:#2563EB}.merchant-status-card.delivered .merchant-status-icon{background:#DCFCE7;color:#16A34A}.merchant-status-card.delivered strong{color:#16A34A}.merchant-status-card.returned .merchant-status-icon{background:#FEE2E2;color:#DC2626}.merchant-status-card.returned strong{color:#DC2626}.orders-list-head{display:flex;align-items:center;gap:10px;margin-bottom:14px}.orders-back{display:grid;place-items:center;width:36px;height:36px;border:0;border-radius:10px;background:var(--surface-2);color:var(--ink);cursor:pointer}.orders-list-head>b{flex:1;font-size:14px;font-weight:900}.orders-list-head>span{padding:3px 10px;border-radius:20px;background:var(--surface-2);color:var(--ink-soft);font-size:11px;font-weight:800}
.mobile-order-stack{display:grid;gap:10px}.mobile-order-card{overflow:hidden;border:1.5px solid color-mix(in srgb,var(--primary) 35%,var(--border));border-radius:16px;background:linear-gradient(145deg,color-mix(in srgb,var(--primary-tint) 75%,var(--surface)),var(--surface));box-shadow:0 4px 13px rgba(11,110,104,.08);cursor:pointer}.mobile-order-head{display:flex;align-items:center;gap:10px;padding:12px 13px 8px}.mobile-order-head .order-mid{flex:1}.mobile-order-head .order-mid b{font-size:13px}.mobile-order-head :deep(.badge){flex:none}.mobile-order-summary{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:2px 13px 10px}.mobile-order-summary strong{color:var(--primary-strong);font-size:16px;font-weight:900}.mobile-order-summary small{color:var(--ink-faint);font-family:var(--font);font-size:10px}.mobile-order-tags{display:flex;align-items:center;justify-content:flex-end;gap:5px;min-width:0;flex-wrap:wrap}.mobile-vehicle-badge,.mobile-order-type-badge{display:inline-flex;align-items:center;gap:4px;max-width:125px;padding:5px 9px;border:1px solid color-mix(in srgb,var(--primary) 24%,var(--border));border-radius:9px;background:color-mix(in srgb,var(--primary-tint) 75%,var(--surface));color:var(--primary-strong);font-size:10px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.mobile-order-type-badge{border-color:color-mix(in srgb,var(--accent) 26%,var(--border));background:color-mix(in srgb,var(--accent-tint) 70%,var(--surface));color:var(--accent)}.mobile-order-note{margin:0 13px 10px;padding:6px 8px;border-radius:8px;background:var(--surface-2);color:var(--ink-soft);font-size:10px;font-weight:700}.mobile-order-note b{color:var(--primary-strong)}.mobile-order-timer{display:flex;align-items:center;gap:5px;padding:8px 12px;border-top:1px solid var(--border);background:var(--surface-2);color:var(--success);font-size:10px;font-weight:900}.mobile-order-timer i{width:7px;height:7px;border-radius:50%;background:var(--success);box-shadow:0 0 7px color-mix(in srgb,var(--success) 70%,transparent)}
.mobile-pickup-location-card{display:grid;gap:8px;margin:14px 0;padding:13px;border:1.5px solid color-mix(in srgb,var(--success) 42%,var(--border));border-radius:14px;background:linear-gradient(135deg,color-mix(in srgb,var(--success-tint) 72%,var(--surface)),var(--surface))}.mobile-pickup-location-head{display:flex;align-items:center;gap:9px}.mobile-pickup-location-icon{display:grid;place-items:center;width:38px;height:38px;flex:none;border-radius:11px;background:var(--success);color:#fff}.mobile-pickup-location-head span:last-child{display:grid;gap:2px;min-width:0}.mobile-pickup-location-head small{color:var(--ink-faint);font-size:9.5px;font-weight:800}.mobile-pickup-location-head b{overflow:hidden;color:var(--ink);font-size:12px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.mobile-pickup-location-card p{margin:0;color:var(--ink-soft);font-size:10px;font-weight:700;line-height:1.55}.mobile-pickup-location-action{display:flex;align-items:center;justify-content:center;gap:6px;min-height:38px;border-radius:10px;background:var(--primary);color:#fff;font-size:10.5px;font-weight:900;text-decoration:none;box-shadow:0 4px 10px rgba(11,110,104,.16)}
.order-detail-status{display:grid;gap:16px;padding:4px 0 18px;border-bottom:1px solid var(--border)}.order-detail-status-head{display:flex;align-items:center;gap:9px}.order-detail-status-head :deep(.badge){flex:none}.order-detail-track{margin-inline-start:auto;color:var(--primary-strong);font-size:15px;font-weight:950}.order-detail-icon{display:grid;place-items:center;width:35px;height:35px;border-radius:11px;background:var(--primary-tint);color:var(--primary-strong)}.order-detail-steps{position:relative;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:2px}.order-detail-steps::before{position:absolute;top:17px;inset-inline:8%;height:2px;background:var(--border);content:""}.order-detail-step{position:relative;z-index:1;display:grid;justify-items:center;gap:6px;color:var(--ink-faint);font-size:8.5px;font-weight:900;text-align:center}.order-detail-step i{display:grid;place-items:center;width:33px;height:33px;border:2px solid var(--border);border-radius:50%;background:var(--surface);font-style:normal;color:var(--ink-soft)}.order-detail-step.done i{border-color:var(--primary);background:var(--primary);color:#fff}.order-detail-step.active i{border-color:var(--accent);box-shadow:0 0 0 4px color-mix(in srgb,var(--accent) 18%,transparent);color:var(--accent)}.order-detail-step.active{color:var(--ink)}.order-detail-section{padding:16px 0 2px}.order-detail-section h3{margin:0 0 13px;color:var(--ink);font-size:15px;font-weight:950}.order-detail-section .detail-row{padding:7px 0}.customer-whatsapp{display:flex;align-items:center;justify-content:center;min-height:42px;margin:12px 0;border-radius:11px;background:#e0f0eb;color:#079050;font-size:12px;font-weight:900;text-decoration:none}.courier-merchant-card{display:grid;gap:10px;margin:14px 0;padding:13px;border:1.5px solid color-mix(in srgb,var(--primary) 34%,var(--border));border-radius:17px;background:color-mix(in srgb,var(--primary-tint) 66%,var(--surface))}.merchant-card-label{color:var(--primary-strong);font-size:11px;font-weight:950}.merchant-card-profile{display:flex;align-items:center;gap:10px}.merchant-card-profile>span:last-child{display:grid;gap:3px;min-width:0}.merchant-card-profile b{font-size:14px;color:var(--ink)}.merchant-avatar{display:grid;place-items:center;order:-1;width:43px;height:43px;flex:none;border-radius:50%;background:var(--primary);color:#fff;font-size:18px;font-weight:950}.merchant-info-row{overflow:hidden;color:var(--ink-soft);font-size:10px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}.merchant-location-row{display:block;overflow:hidden;padding:9px;border-radius:10px;background:color-mix(in srgb,var(--success-tint) 74%,var(--surface));color:var(--success);font-size:10.5px;font-weight:900;text-decoration:none;text-overflow:ellipsis;white-space:nowrap}.merchant-card-actions{display:grid;grid-template-columns:1fr 1fr;gap:7px}.merchant-card-actions>*{display:grid;place-items:center;min-height:38px;border:0;border-radius:10px;background:var(--primary);color:#fff;font:900 11px var(--font);text-decoration:none;cursor:pointer}.merchant-card-actions a{background:color-mix(in srgb,var(--success-tint) 72%,var(--surface));color:var(--success)}.pickup-countdown{display:grid;justify-items:center;gap:6px;margin:14px 0;padding:13px;border:1px solid #edc980;border-radius:15px;background:#fff4de;color:var(--ink)}.pickup-countdown b{font-size:12px}.pickup-countdown strong{color:#c58315;font-size:26px;font-weight:950}.order-detail-actions{display:grid;gap:8px;margin-top:12px}.order-detail-actions .mini-btn{width:100%;min-height:48px;border-radius:13px;font-size:13px}
.order-complaint{display:flex;align-items:center;justify-content:center;width:100%;margin-top:10px;padding:9px 12px;border:1px solid color-mix(in srgb,var(--danger) 24%,transparent);border-radius:10px;background:color-mix(in srgb,var(--danger-tint) 82%,transparent);color:var(--danger);font:inherit;font-size:11px;font-weight:900;cursor:pointer}
.order-chat{border-color:color-mix(in srgb,var(--primary) 28%,transparent);background:var(--primary-tint);color:var(--primary-strong)}
.customer-phone-locked{letter-spacing:1px;color:var(--ink-faint);font-size:12px}
.order-recreate{display:flex;align-items:center;justify-content:center;width:100%;min-height:39px;margin-top:10px;border:1px solid color-mix(in srgb,var(--primary) 28%,transparent);border-radius:10px;background:var(--primary-tint);color:var(--primary-strong);font:900 11px var(--font);cursor:pointer}.order-recreate:disabled{opacity:.6;cursor:wait}
.return-flow{display:grid;gap:10px;margin-top:15px;padding:13px;border:1px solid color-mix(in srgb,var(--danger) 30%,var(--border));border-radius:14px;background:color-mix(in srgb,var(--danger-tint) 48%,var(--surface))}.return-flow h4{margin:0;color:var(--ink);font-size:13px;font-weight:900}.return-flow p{margin:0;color:var(--ink-soft);font-size:10.5px;font-weight:700;line-height:1.65}.return-flow-button{width:100%;min-height:39px}.return-flow-cancel{border:0;background:transparent;color:var(--ink-soft);font:800 11px var(--font);cursor:pointer}.return-fee-field{display:flex;align-items:center;gap:8px;border:1px solid color-mix(in srgb,var(--danger) 26%,var(--border));border-radius:10px;background:var(--surface);padding:0 10px}.return-fee-field input{width:100%;min-height:39px;border:0;outline:0;background:transparent;color:var(--ink);font:900 14px var(--font)}.return-fee-field span{color:var(--ink-faint);font-size:10px;font-weight:800}.return-fee-presets{display:flex;flex-wrap:wrap;gap:6px}.return-fee-presets button{border:0;border-radius:8px;background:var(--surface-2);color:var(--ink-soft);font:800 10px var(--font);padding:6px 9px;cursor:pointer}.return-fee-summary{padding:8px 10px;border-radius:9px;background:var(--surface);color:var(--danger);font-size:11px}.return-confirmation{border-color:color-mix(in srgb,var(--accent) 32%,var(--border));background:color-mix(in srgb,var(--accent-tint) 50%,var(--surface))}.return-confirmation .return-fee-summary{color:var(--accent)}
.courier-orders-overview .merchant-status-grid{gap:10px}
.merchant-status-card.all .merchant-status-icon{background:var(--primary-tint);color:var(--primary-strong)}.merchant-status-card.all strong{color:var(--primary-strong)}.mobile-order-note{border:1px solid color-mix(in srgb,var(--danger) 18%,transparent);background:color-mix(in srgb,var(--danger-tint) 70%,var(--surface))}.mobile-order-note b{color:var(--danger)}
.mobile-operational-section{margin:15px 0}.mobile-operational-section h4{margin:0 0 9px;color:var(--ink);font-size:12px;font-weight:900}.mobile-branch-route{display:grid;gap:5px;margin-top:9px;padding:11px 12px;border:1px solid color-mix(in srgb,var(--primary) 24%,var(--border));border-radius:12px;background:color-mix(in srgb,var(--primary-tint) 62%,var(--surface));color:var(--primary-strong)}.mobile-route-label{font-size:10px;font-weight:900;color:var(--ink-soft)}.mobile-branch-route>b{font-size:12px}.mobile-branch-route small{color:var(--ink-soft);font-size:10px;font-weight:700}.mobile-order-timeline{display:grid;gap:0}.mobile-timeline-event{display:grid;grid-template-columns:28px 1fr;gap:9px;min-height:57px}.mobile-timeline-rail{position:relative;display:flex;justify-content:center}.mobile-timeline-rail::after{position:absolute;top:24px;bottom:-3px;width:1px;background:var(--border);content:""}.mobile-timeline-event:last-child .mobile-timeline-rail::after{display:none}.mobile-timeline-marker{position:relative;z-index:1;display:grid;place-items:center;width:23px;height:23px;border:1px solid var(--border);border-radius:50%;background:var(--surface);color:var(--ink-soft);font-size:11px;font-weight:900}.mobile-timeline-marker.is-created{border-color:color-mix(in srgb,var(--primary) 40%,var(--border));background:var(--primary-tint);color:var(--primary-strong)}.mobile-timeline-marker.is-status{border-color:color-mix(in srgb,var(--success) 44%,var(--border));background:var(--success-tint);color:var(--success)}.mobile-timeline-marker.is-movement{border-color:color-mix(in srgb,var(--accent) 42%,var(--border));background:var(--accent-tint);color:var(--accent)}.mobile-timeline-copy{display:grid;gap:2px;padding:1px 0 14px}.mobile-timeline-copy>b{font-size:11px;color:var(--ink)}.mobile-timeline-copy p{margin:0;color:var(--ink-soft);font-size:10px;line-height:1.55}.mobile-timeline-copy time{color:var(--ink-faint);font-size:9px}
</style>
