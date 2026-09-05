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
    openOrderId: { type: Number, default: null },
    archive: { type: Boolean, default: false },
    pagination: { type: Object, default: () => ({ next_cursor: null, has_more: false }) },
    isCourier: { type: Boolean, default: false },
    onDuty: { type: Boolean, default: true },
    canAcceptOrders: { type: Boolean, default: true },
    wallet: { type: Object, default: () => ({ balance: 0, budget: 0, budget_balance: 0 }) },
})

const page = usePage()
const locale = computed(() => page.props.locale || 'ar')
const isCourier = computed(() => props.isCourier)
const currentRole = computed(() => page.props.auth?.user?.role || '')
const isGeneralCourier = computed(() => currentRole.value === 'courier')
// Home-screen rows can open a detail sheet directly. The server sends the
// requested id as a prop so Inertia visits work even when this component is
// retained instead of being recreated.
const showCourierOrderList = computed(() => props.list || Number(props.openOrderId || 0) > 0)

const query = ref(props.q)
const active = ref(props.filter)
const searchOpen = ref(Boolean(props.q))
const merchantOverview = ref(!props.isCourier && props.filter === 'all' && !props.q && !props.list)
const courierOverview = ref(props.isCourier && props.filter === 'all' && !props.q && !props.list && !showCourierOrderList.value)
function newestFirst(orders) {
    return [...(orders || [])].sort((left, right) => {
        const leftTime = Date.parse(left?.created_at || '') || 0
        const rightTime = Date.parse(right?.created_at || '') || 0

        if (rightTime !== leftTime) return rightTime - leftTime

        return Number(right?.id || 0) - Number(left?.id || 0)
    })
}

const loadedOrders = ref(newestFirst(props.orders))
const pagination = ref({ ...props.pagination })
const selected = ref(null)
const showForm = ref(false)
const editing = ref(null)
const busy = ref(null)
const pickupConfirmation = ref(null)
const statusConfirmation = ref(null)
const availabilityConfirmation = ref(null)
const claimConfirmation = ref(null)
const archiveConfirmation = ref(null)
const returnConfirmation = ref(null)
const returnToMerchantConfirmation = ref(null)
const confirmationError = ref('')
const confirmationNeedsLocation = ref(false)
const detailLoading = ref(false)
const loadingMore = ref(false)
const now = ref(Date.now())
let ticker

watch(() => props.orders, (orders) => {
    loadedOrders.value = newestFirst(orders)
})

watch(() => props.pagination, (nextPagination) => {
    pagination.value = { ...(nextPagination || {}) }
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
    { key: 'courier', label: t('With Courier'), tone: 'courier', count: props.counts.courier ?? 0 },
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

function toggleSearch() {
    searchOpen.value = !searchOpen.value
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
        const payload = await readOrderJson({
            detail: orderId,
            archive: props.archive ? 1 : null,
            // A courier's pending queue is the unassigned-offer queue rather
            // than their assigned history. Preserve that scope for direct
            // opens from the home page as well, where the compact row only
            // supplies an id.
            pending: isAvailablePendingOffer(order) ? 1 : null,
        })
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

        loadedOrders.value = newestFirst([...loadedOrders.value, ...nextOrders])
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

// Validation errors arrive through `onError`, while an unexpected response
// (for example a 500) is emitted as an Inertia invalid/exception event.
// Keeping the active dialog open in both cases prevents a courier action from
// appearing to do nothing when the server rejects it.
function activeConfirmationOrder() {
    return pickupConfirmation.value
        || statusConfirmation.value?.order
        || availabilityConfirmation.value
        || claimConfirmation.value
        || archiveConfirmation.value
        || returnConfirmation.value?.order
        || returnToMerchantConfirmation.value
}

function resetConfirmationFeedback() {
    confirmationError.value = ''
    confirmationNeedsLocation.value = false
}

function clearActionConfirmations() {
    pickupConfirmation.value = null
    statusConfirmation.value = null
    availabilityConfirmation.value = null
    claimConfirmation.value = null
    archiveConfirmation.value = null
    returnConfirmation.value = null
    returnToMerchantConfirmation.value = null
    resetConfirmationFeedback()
}

function firstServerError(errors) {
    const messages = Object.values(errors || {})
        .flatMap((value) => Array.isArray(value) ? value : [value])
        .filter((value) => typeof value === 'string' && value.trim())

    return messages[0] || t('Something went wrong')
}

function showConfirmationError(errors) {
    confirmationNeedsLocation.value = Boolean(errors?.location)
    confirmationError.value = firstServerError(errors)
    reopenLocationGateIfRequired(errors)
}

function openLocationSharing() {
    const needsLocation = confirmationNeedsLocation.value
    clearActionConfirmations()
    if (needsLocation) window.dispatchEvent(new CustomEvent('almunjaz:location-required'))
}

function handleInvalidOrderAction(event) {
    if (!busy.value || !activeConfirmationOrder()) return

    // Avoid Inertia's generic response popup: the same dialog can display a
    // retryable error without hiding the order details underneath it.
    event.preventDefault()
    confirmationError.value = t('Something went wrong')
}

function handleOrderActionException(event) {
    if (!busy.value || !activeConfirmationOrder()) return

    event.preventDefault()
    confirmationError.value = t('Something went wrong')
}

function setStatus(order, status) {
    if (busy.value) return
    busy.value = order.id
    resetConfirmationFeedback()
    router.post(
        route('app.orders.status', order.id),
        { status },
        {
            preserveScroll: true,
            onSuccess: () => {
                clearActionConfirmations()
                selected.value = null
            },
            onError: showConfirmationError,
            onFinish: () => (busy.value = null),
        }
    )
}

function confirmReturn(feeMode) {
    const confirmation = returnConfirmation.value
    if (!confirmation || busy.value) return

    const { order } = confirmation
    const returnReason = String(confirmation.reason || '').trim()
    if (!returnReason) {
        confirmationError.value = t('Return reason is required.')
        return
    }

    busy.value = order.id
    resetConfirmationFeedback()
    router.post(
        route('app.orders.return', order.id),
        { fee_mode: feeMode, return_reason: returnReason },
        {
            preserveScroll: true,
            onSuccess: () => {
                clearActionConfirmations()
                selected.value = null
            },
            onError: showConfirmationError,
            onFinish: () => (busy.value = null),
        }
    )
}

function requestReturnToMerchant(order) {
    if (busy.value) return

    resetConfirmationFeedback()
    returnToMerchantConfirmation.value = order
}

function confirmReturnToMerchant() {
    const order = returnToMerchantConfirmation.value
    if (busy.value) return
    if (!order) return

    busy.value = order.id
    resetConfirmationFeedback()
    router.post(
        route('app.orders.return-to-merchant', order.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                clearActionConfirmations()
                selected.value = null
            },
            onError: showConfirmationError,
            onFinish: () => (busy.value = null),
        }
    )
}

function handleAction(order, status) {
    resetConfirmationFeedback()
    if (status === 'courier') {
        pickupConfirmation.value = order
        return
    }

    statusConfirmation.value = { order, status }
}

function confirmPickup() {
    const order = pickupConfirmation.value
    if (!order) return
    setStatus(order, 'courier')
}

function confirmStatusChange() {
    const confirmation = statusConfirmation.value
    if (!confirmation) return

    if (confirmation.status === 'returned') {
        statusConfirmation.value = null
        resetConfirmationFeedback()
        returnConfirmation.value = { order: confirmation.order, reason: '' }
        return
    }

    setStatus(confirmation.order, confirmation.status)
}

function requestArchive(order) {
    if (!canManuallyArchive(order) || busy.value) return
    resetConfirmationFeedback()
    archiveConfirmation.value = order
}

// The server remains authoritative for archive permission.  The client adds
// the final-status guard so the manual action is never presented for work
// that is still in progress, even if a stale payload is displayed.
function canManuallyArchive(order) {
    return Boolean(order?.can_archive)
        && ['delivered', 'returned'].includes(order?.status)
}

function archiveConfirmationLabel(order) {
    const status = order?.status

    return ['delivered', 'returned'].includes(status)
        ? `${t('Archive Order')} · ${tStatus(status)}`
        : t('Archive Order')
}

function confirmArchive() {
    const order = archiveConfirmation.value
    if (!order || busy.value) return

    busy.value = order.id
    resetConfirmationFeedback()
    router.post(route('app.orders.archive', order.id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            clearActionConfirmations()
            selected.value = null
        },
        onError: showConfirmationError,
        onFinish: () => (busy.value = null),
    })
}

function canAct(order) {
    if (!props.isCourier) return []

    // One courier receives the order, completes delivery, and can start the
    // physical return flow. This mirrors the server lifecycle exactly.
    const allowedByStatus = {
        approved: ['courier'],
        courier: ['delivered', 'returned'],
    }

    return isGeneralCourier.value ? (allowedByStatus[order.status] || []) : []
}

function isAvailablePendingOffer(order) {
    return props.canAcceptOrders
        && isGeneralCourier.value
        && (order?.status === 'pending' || (!order?.status && active.value === 'pending'))
}

function canClaimPendingOrder(order) {
    return isAvailablePendingOffer(order)
        && !order?.courier_id
}

function requestClaimPendingOrder(order) {
    if (!canClaimPendingOrder(order) || busy.value) return

    resetConfirmationFeedback()
    if (!props.onDuty) {
        availabilityConfirmation.value = order
        return
    }

    claimConfirmation.value = order
}

function enableAvailabilityForClaim() {
    const order = availabilityConfirmation.value
    if (!props.canAcceptOrders || !order || busy.value) return

    busy.value = order.id
    resetConfirmationFeedback()
    router.post(route('app.duty'), { is_online: true }, {
        preserveScroll: true,
        onSuccess: () => {
            // Continue with the normal, explicit order confirmation after
            // the courier has deliberately enabled availability.
            availabilityConfirmation.value = null
            claimConfirmation.value = order
        },
        onError: showConfirmationError,
        onFinish: () => (busy.value = null),
    })
}

function claimPendingOrder() {
    const order = claimConfirmation.value
    if (!order || !canClaimPendingOrder(order) || busy.value) return

    busy.value = order.id
    resetConfirmationFeedback()

    // A previous pin must never be enough to accept a new order. Request a
    // position at the moment of confirmation, save it server-side, then let
    // the existing server guard make the final decision.
    if (!navigator.geolocation) {
        confirmationNeedsLocation.value = true
        confirmationError.value = t('Enable location sharing to accept this order.')
        busy.value = null
        return
    }

    navigator.geolocation.getCurrentPosition(
        async (position) => {
            try {
                await window.axios.post(route('app.location.update'), {
                    latitude: Number(position.coords.latitude),
                    longitude: Number(position.coords.longitude),
                    accuracy_meters: Math.max(0, Math.min(Math.round(Number(position.coords.accuracy || 0)), 50_000)),
                })
            } catch (_) {
                confirmationNeedsLocation.value = true
                confirmationError.value = t('Unable to save your current location. Check your connection and try again.')
                busy.value = null
                return
            }

            router.post(route('app.orders.claim', order.id), {}, {
                preserveScroll: true,
                onSuccess: () => {
                    clearActionConfirmations()
                    selected.value = null
                },
                onError: showConfirmationError,
                onFinish: () => (busy.value = null),
            })
        },
        () => {
            confirmationNeedsLocation.value = true
            confirmationError.value = t('Enable location sharing to accept this order.')
            busy.value = null
        },
        { enableHighAccuracy: true, maximumAge: 0, timeout: 15_000 },
    )
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

function openSupport(order) {
    router.post(route('app.chats.open'), { order_id: order.id, complaint: true }, { preserveScroll: true })
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

// The customer's WhatsApp action deliberately uses only the primary order
// phone. `phone2` is an optional alternate contact and must never silently
// become the destination for a customer conversation.
function customerWhatsappUrl(order) {
    return whatsappUrl(order?.phone)
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

function orderTotal(order) {
    return Number(order?.price || 0) + Number(order?.fee || 0)
}

function canRepublishExpiredOrder(order) {
    if (isCourier.value || order?.status !== 'pending' || order?.courier_id || !order?.pickup_deadline_at) {
        return false
    }

    return new Date(order.pickup_deadline_at).getTime() <= now.value
}

onMounted(() => {
    ticker = window.setInterval(() => { now.value = Date.now() }, 1000)
    document.addEventListener('inertia:invalid', handleInvalidOrderAction)
    document.addEventListener('inertia:exception', handleOrderActionException)

    if (Number(props.openOrderId || 0) > 0) openOrder({ id: props.openOrderId })
})

watch(() => props.openOrderId, (orderId, previousOrderId) => {
    const id = Number(orderId || 0)
    if (!id || id === Number(previousOrderId || 0)) return

    courierOverview.value = false
    merchantOverview.value = false
    openOrder({ id })
})

onUnmounted(() => {
    window.clearInterval(ticker)
    document.removeEventListener('inertia:invalid', handleInvalidOrderAction)
    document.removeEventListener('inertia:exception', handleOrderActionException)
})
</script>

<template>
    <AppShell :title="isCourier ? t('My Deliveries') : t('My Orders')">
        <template #title>
            {{ isCourier ? t('My Deliveries') : t('My Orders') }}
        </template>

        <section v-if="isGeneralCourier && !canAcceptOrders" class="courier-verification-notice" role="status">
            <span class="courier-verification-icon" aria-hidden="true">!</span>
            <div>
                <b>{{ t('Courier account under review') }}</b>
                <p>{{ t('Your account cannot accept orders until administration approves your documents and verifies it.') }}</p>
            </div>
        </section>

        <section v-if="!isCourier && merchantOverview" class="merchant-orders-overview">
            <h2 class="orders-overview-title">{{ t('My Orders') }}</h2>
            <div class="merchant-status-grid">
                <button v-for="card in merchantStatusCards" :key="card.key" class="merchant-status-card" :class="card.tone" type="button" @click="showMerchantOrders(card.key)">
                    <span class="merchant-status-top"><span class="merchant-status-icon"><svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path v-for="(path, index) in statusIcon(card.key).paths" :key="`path-${index}`" :d="path" /><circle v-for="(circle, index) in statusIcon(card.key).circles" :key="`circle-${index}`" :cx="circle.cx" :cy="circle.cy" :r="circle.r" /></svg></span><span class="merchant-status-arrow" aria-hidden="true">‹</span></span>
                    <span class="merchant-status-copy"><b>{{ card.label }}</b><small>{{ t('orders') }}</small></span>
                    <strong class="mono">{{ card.count }}</strong>
                </button>
            </div>
        </section>

        <section v-else-if="isCourier && courierOverview" class="merchant-orders-overview courier-orders-overview">
            <h2 class="orders-overview-title">{{ t('My Deliveries') }}</h2>
            <div class="merchant-status-grid">
                <button v-for="card in courierStatusCards" :key="card.key" class="merchant-status-card" :class="card.tone" type="button" @click="changeFilter(card.key)">
                    <span class="merchant-status-top"><span class="merchant-status-icon"><svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path v-for="(path, index) in statusIcon(card.key).paths" :key="`path-${index}`" :d="path" /><circle v-for="(circle, index) in statusIcon(card.key).circles" :key="`circle-${index}`" :cx="circle.cx" :cy="circle.cy" :r="circle.r" /></svg></span><span class="merchant-status-arrow" aria-hidden="true">‹</span></span>
                    <span class="merchant-status-copy"><b>{{ card.label }}</b><small>{{ t('orders') }}</small></span>
                    <strong class="mono">{{ card.count }}</strong>
                </button>
            </div>
        </section>

        <template v-else>
        <div class="orders-sticky-tools">
            <div class="orders-list-head">
                <button class="orders-back" type="button" @click="isCourier ? backToCourierOverview() : backToMerchantOverview()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6" /></svg>
                </button>
                <b>{{ active === 'all' ? t('All Orders') : tStatus(active) }}</b>
                <button class="orders-search-toggle" type="button" :class="{ active: searchOpen }" :aria-label="t('Search')" @click="toggleSearch">
                    <svg v-if="!searchOpen" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="6"/><path d="m20 20-4.2-4.2"/></svg>
                    <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18"/></svg>
                </button>
                <span>{{ counts[active] ?? 0 }}</span>
            </div>

            <form v-if="searchOpen" class="search orders-search" @submit.prevent="doSearch">
                <input
                    v-model="query"
                    :placeholder="`${t('Search')} — ${t('Customer')} / ${t('Phone')}`"
                />
                <button type="submit">{{ t('Search') }}</button>
            </form>
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
                    <strong class="mono order-product-price">{{ fmt(o.price) }} <small>{{ t('IQD') }}</small></strong>
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
                <p v-if="o.vehicle_note && o.status !== 'approved'" class="mobile-order-note mobile-order-vehicle-note"><b>{{ t('Vehicle Note') }}:</b> {{ o.vehicle_note }}</p>
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

        <SheetModal :open="!!selected" @close="selected = null; clearActionConfirmations()">
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
                <section class="customer-details-card">
                    <div class="customer-details-head"><span class="customer-details-avatar">{{ customerName(selected).slice(0, 1) }}</span><span><small>{{ t('Customer') }}</small><b>{{ customerName(selected) }}</b></span></div>
                    <div class="customer-details-contacts"><span><small>{{ t('Phone') }}</small><b class="mono">{{ selected.phone }}</b></span><span v-if="selected.phone2"><small>{{ t('Phone 2') }}</small><b class="mono">{{ selected.phone2 }}</b></span></div>
                    <div class="customer-details-address"><small>{{ t('Address') }}</small><b>{{ customerAddress(selected) }}</b></div>
                    <a v-if="customerWhatsappUrl(selected)" class="customer-whatsapp" :href="customerWhatsappUrl(selected)" target="_blank" rel="noopener">{{ t('Customer WhatsApp') }}</a>
                </section>
                <div class="detail-row">
                    <span class="text-muted">{{ t('Order Type') }}</span>
                    <b class="delivery-vehicle-pill">{{ orderTypeLabel(selected) }}</b>
                </div>
                <div class="detail-row">
                    <span class="text-muted">{{ t('Delivery Vehicle') }}</span>
                    <b class="delivery-vehicle-pill">{{ vehicleLabel(selected) }}</b>
                </div>
                <div v-if="selected.vehicle_note" class="detail-note-box"><b>{{ t('Vehicle Note') }}:</b> {{ selected.vehicle_note }}</div>
                <div v-if="selected.notes" class="detail-note-box"><b>{{ t('Order Note') }}:</b> {{ selected.notes }}</div>
                <div v-if="selected.return_reason" class="detail-note-box return-reason-box"><b>{{ t('Return Reason') }}:</b> {{ selected.return_reason }}</div>
                <div class="order-financial-card">
                    <div class="order-financial-split">
                        <div class="order-financial-item product">
                            <span>{{ t('Price') }}</span>
                            <b class="mono">{{ fmt(selected.price) }} <small>{{ t('IQD') }}</small></b>
                        </div>
                        <div class="order-financial-item fee">
                            <span>{{ t('Delivery Price') }}</span>
                            <b class="mono">{{ fmt(selected.fee) }} <small>{{ t('IQD') }}</small></b>
                        </div>
                    </div>
                    <div class="detail-total-card">
                        <span>{{ t('Total') }}</span>
                        <strong class="mono">{{ fmt(orderTotal(selected)) }} <small>{{ t('IQD') }}</small></strong>
                    </div>
                </div>
                </section>

                <section v-if="isCourier && selected.merchant" class="courier-merchant-card">
                    <span class="merchant-card-label">{{ t('Merchant') }}</span>
                    <div class="merchant-card-profile"><span class="merchant-avatar">{{ selected.merchant.name?.slice(0, 1) }}</span><span><b>{{ selected.merchant.shop_name || selected.merchant.name }} <i v-if="selected.merchant.verified" class="merchant-verified" :title="t('Verified')">✓</i></b><small v-if="selected.merchant.address" class="merchant-info-row"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 10c0 5.2-8 11-8 11S4 15.2 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>{{ selected.merchant.address }}</small><small v-if="selected.merchant.phone" class="merchant-info-row mono"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7A2 2 0 0 1 22 16.9Z"/></svg>{{ selected.merchant.phone }}</small></span></div>
                    <a v-if="hasPickupLocation(selected)" class="merchant-location-inline" :href="pickupNavigationHref(selected)" :aria-label="`${t('Open navigation apps')}: ${pickupLocationLabel(selected, t('Merchant pickup location'))}`">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 5.2-8 11-8 11S4 15.2 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                        <span><small>{{ t('Merchant pickup location') }}</small><b>{{ pickupLocationLabel(selected, t('Merchant pickup location')) }}</b></span>
                        <i>{{ t('Open navigation apps') }}</i>
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

                <section v-if="isGeneralCourier && selected.status === 'returned'" class="return-flow return-confirmation">
                    <template v-if="!selected.returned_to_merchant_at">
                        <h4>{{ t('Return to Merchant') }}</h4>
                        <p>{{ selected.return_fee_mode === 'fee' ? t('The quoted delivery fee is kept for this returned order.') : t('The administration deduction was returned because this order was returned without a delivery fee.') }}</p>
                        <b v-if="selected.return_fee_mode === 'fee'" class="return-fee-summary">{{ t('Return fee') }}: {{ fmt(selected.return_fee_applied) }} {{ t('IQD') }}</b>
                        <b v-if="selected.return_reason" class="return-reason-summary">{{ t('Return Reason') }}: {{ selected.return_reason }}</b>
                        <button class="mini-btn danger return-flow-button" :disabled="busy === selected.id" @click="requestReturnToMerchant(selected)">
                            <span v-if="busy === selected.id" class="loader"></span>
                            <span v-else>{{ t('Confirm Return') }}</span>
                        </button>
                    </template>
                    <template v-else>
                        <h4>{{ t('Returned to merchant') }}</h4>
                        <p>{{ t('The handback was confirmed and has been recorded in the operational timeline.') }}</p>
                        <b v-if="selected.return_fee_mode === 'fee'" class="return-fee-summary">{{ t('Return fee') }}: {{ fmt(selected.return_fee_applied) }} {{ t('IQD') }}</b>
                        <b v-if="selected.return_reason" class="return-reason-summary">{{ t('Return Reason') }}: {{ selected.return_reason }}</b>
                    </template>
                </section>

                <div v-if="canClaimPendingOrder(selected)" class="deliv-actions order-detail-actions">
                    <button class="mini-btn primary" type="button" :disabled="busy === selected.id" @click="requestClaimPendingOrder(selected)">
                        <span v-if="busy === selected.id" class="loader"></span>
                        <span v-else>{{ t('Accept Order') }}</span>
                    </button>
                </div>

                <div v-else-if="actionsFor(selected).length" class="deliv-actions order-detail-actions">
                    <button v-for="a in actionsFor(selected)" :key="a.next" class="mini-btn" :class="a.kind" :disabled="busy === selected.id" @click="handleAction(selected, a.next)">
                        <span v-if="busy === selected.id" class="loader"></span>
                        {{ a.label }}
                    </button>
                </div>

                <button v-if="shouldShowMerchantSupport(selected)" class="order-complaint" type="button" @click="openComplaint(selected)">
                    {{ t('Contact Support') }}
                </button>

                <button v-else-if="isCourier && ['approved', 'courier'].includes(selected.status)" class="order-complaint" type="button" @click="openSupport(selected)">
                    {{ t('Contact Support to Cancel') }}
                </button>

                <button v-if="canManuallyArchive(selected)" class="order-archive" type="button" :disabled="busy === selected.id" @click="requestArchive(selected)">
                    {{ t('Archive Order') }}
                </button>

                <section v-if="canRepublishExpiredOrder(selected)" class="republish-expired" role="status">
                    <b>{{ t('Time expired') }}</b>
                    <span>{{ t('Republish Order') }}</span>
                </section>

                <button v-if="canRepublishExpiredOrder(selected)" class="order-recreate" type="button" :disabled="busy === selected.id" @click="republishOrder(selected)">
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

        <!-- Confirmation dialogs are teleported so they always appear above the order-detail sheet. -->
        <Teleport to="body">
        <div v-if="pickupConfirmation" class="pickup-confirm-backdrop" role="presentation" @click.self="!busy && clearActionConfirmations()">
            <section class="pickup-confirm-dialog" role="dialog" aria-modal="true" :aria-label="t('Confirm Order Pickup')">
                <span class="pickup-confirm-icon">✓</span>
                <h3>{{ t('Confirm Order Pickup') }}</h3>
                <p>{{ t('Confirm that you received the order from the merchant. The order will move to With Courier.') }}</p>
                <p v-if="confirmationError" class="confirmation-error">{{ confirmationError }}</p>
                <button v-if="confirmationNeedsLocation" type="button" class="confirmation-location" @click="openLocationSharing">{{ t('Enable location sharing') }}</button>
                <div>
                    <button type="button" class="pickup-confirm-cancel" :disabled="busy === pickupConfirmation.id" @click="clearActionConfirmations">{{ t('Cancel') }}</button>
                    <button type="button" class="pickup-confirm-submit" :disabled="busy === pickupConfirmation.id" @click="confirmPickup"><span v-if="busy === pickupConfirmation.id" class="loader"></span><span v-else>{{ t('Confirm Pickup') }}</span></button>
                </div>
            </section>
        </div>

        <div v-if="statusConfirmation" class="pickup-confirm-backdrop" role="presentation" @click.self="!busy && clearActionConfirmations()">
            <section class="pickup-confirm-dialog" role="dialog" aria-modal="true" :aria-label="t('Confirm')">
                <span class="pickup-confirm-icon">✓</span>
                <h3>{{ t('Confirm') }}</h3>
                <p>{{ statusConfirmation.status === 'delivered' ? t('Mark Delivered') : t('Mark Returned') }}</p>
                <p v-if="confirmationError" class="confirmation-error">{{ confirmationError }}</p>
                <button v-if="confirmationNeedsLocation" type="button" class="confirmation-location" @click="openLocationSharing">{{ t('Enable location sharing') }}</button>
                <div>
                    <button type="button" class="pickup-confirm-cancel" :disabled="busy === statusConfirmation.order.id" @click="clearActionConfirmations">{{ t('Cancel') }}</button>
                    <button type="button" class="pickup-confirm-submit" :disabled="busy === statusConfirmation.order.id" @click="confirmStatusChange"><span v-if="busy === statusConfirmation.order.id" class="loader"></span><span v-else>{{ t('Confirm') }}</span></button>
                </div>
            </section>
        </div>

        <div v-if="availabilityConfirmation" class="pickup-confirm-backdrop" role="presentation" @click.self="!busy && clearActionConfirmations()">
            <section class="pickup-confirm-dialog availability-confirm-dialog" role="dialog" aria-modal="true" :aria-label="t('You are unavailable for new orders')">
                <span class="pickup-confirm-icon availability-confirm-icon">!</span>
                <h3>{{ t('You are unavailable for new orders') }}</h3>
                <p>{{ t('Turn on “Available for Work” to accept this order.') }}</p>
                <p v-if="confirmationError" class="confirmation-error" role="alert">{{ confirmationError }}</p>
                <div>
                    <button type="button" class="pickup-confirm-cancel" :disabled="busy === availabilityConfirmation.id" @click="clearActionConfirmations">{{ t('Cancel') }}</button>
                    <button type="button" class="pickup-confirm-submit availability-confirm-activate" :disabled="busy === availabilityConfirmation.id" @click="enableAvailabilityForClaim">
                        <span v-if="busy === availabilityConfirmation.id" class="loader"></span>
                        <span v-else>{{ t('Turn on Available for Work') }}</span>
                    </button>
                </div>
            </section>
        </div>

        <div v-if="claimConfirmation" class="pickup-confirm-backdrop" role="presentation" @click.self="!busy && clearActionConfirmations()">
            <section class="pickup-confirm-dialog" role="dialog" aria-modal="true" :aria-label="t('Accept Order')">
                <span class="pickup-confirm-icon">✓</span>
                <h3>{{ t('Accept Order') }}</h3>
                <p>{{ t('Confirm') }} · {{ customerName(claimConfirmation) }}</p>
                <p v-if="confirmationError" class="confirmation-error" role="alert">{{ confirmationError }}</p>
                <button v-if="confirmationNeedsLocation" type="button" class="confirmation-location" @click="openLocationSharing">{{ t('Enable location sharing') }}</button>
                <div>
                    <button type="button" class="pickup-confirm-cancel" :disabled="busy === claimConfirmation.id" @click="clearActionConfirmations">{{ t('Cancel') }}</button>
                    <button type="button" class="pickup-confirm-submit" :disabled="busy === claimConfirmation.id" @click="claimPendingOrder">
                        <span v-if="busy === claimConfirmation.id" class="loader"></span>
                        <span v-else>{{ t('Confirm') }}</span>
                    </button>
                </div>
            </section>
        </div>

        <div v-if="archiveConfirmation" class="pickup-confirm-backdrop" role="presentation" @click.self="!busy && clearActionConfirmations()">
            <section class="pickup-confirm-dialog archive-confirm-dialog" role="dialog" aria-modal="true" :aria-label="archiveConfirmationLabel(archiveConfirmation)">
                <span class="pickup-confirm-icon archive-confirm-icon">▣</span>
                <h3>{{ archiveConfirmationLabel(archiveConfirmation) }}</h3>
                <p>{{ t('Archive confirmation message') }}</p>
                <p v-if="confirmationError" class="confirmation-error">{{ confirmationError }}</p>
                <div>
                    <button type="button" class="pickup-confirm-cancel" :disabled="busy === archiveConfirmation.id" @click="clearActionConfirmations">{{ t('Cancel') }}</button>
                    <button type="button" class="pickup-confirm-submit" :disabled="busy === archiveConfirmation.id" @click="confirmArchive"><span v-if="busy === archiveConfirmation.id" class="loader"></span><span v-else>{{ t('Confirm') }}</span></button>
                </div>
            </section>
        </div>

        <div v-if="returnConfirmation" class="pickup-confirm-backdrop" role="presentation" @click.self="!busy && clearActionConfirmations()">
            <section class="pickup-confirm-dialog return-choice-dialog" role="dialog" aria-modal="true" :aria-label="t('Choose Return Option')">
                <span class="pickup-confirm-icon">✓</span>
                <h3>{{ t('Choose Return Option') }}</h3>
                <p>{{ t('Choose whether this return keeps the quoted delivery fee. No amount is entered manually.') }}</p>
                <b v-if="Number(returnConfirmation.order.return_fee || 0) > 0" class="return-fee-summary">{{ t('Quoted Return Fee') }}: {{ fmt(returnConfirmation.order.return_fee) }} {{ t('IQD') }}</b>
                <label class="return-reason-field">
                    <span>{{ t('Return Reason') }}</span>
                    <textarea v-model="returnConfirmation.reason" rows="3" :placeholder="t('Write the reason for returning the order')"></textarea>
                </label>
                <p v-if="confirmationError" class="confirmation-error">{{ confirmationError }}</p>
                <button v-if="confirmationNeedsLocation" type="button" class="confirmation-location" @click="openLocationSharing">{{ t('Enable location sharing') }}</button>
                <div class="return-choice-actions">
                    <button type="button" class="pickup-confirm-submit" :disabled="busy === returnConfirmation.order.id" @click="confirmReturn('fee')"><span v-if="busy === returnConfirmation.order.id" class="loader"></span><span v-else>{{ t('With Delivery Fee') }}</span></button>
                    <button type="button" class="return-without-fee" :disabled="busy === returnConfirmation.order.id" @click="confirmReturn('none')"><span v-if="busy === returnConfirmation.order.id" class="loader"></span><span v-else>{{ t('Without Delivery Fee') }}</span></button>
                </div>
                <button type="button" class="return-choice-cancel" :disabled="busy === returnConfirmation.order.id" @click="clearActionConfirmations">{{ t('Cancel') }}</button>
            </section>
        </div>

        <div v-if="returnToMerchantConfirmation" class="pickup-confirm-backdrop" role="presentation" @click.self="!busy && clearActionConfirmations()">
            <section class="pickup-confirm-dialog" role="dialog" aria-modal="true" :aria-label="t('Confirm Return')">
                <span class="pickup-confirm-icon">✓</span>
                <h3>{{ t('Confirm Return') }}</h3>
                <p>{{ t('Return to Merchant') }}</p>
                <p v-if="confirmationError" class="confirmation-error">{{ confirmationError }}</p>
                <button v-if="confirmationNeedsLocation" type="button" class="confirmation-location" @click="openLocationSharing">{{ t('Enable location sharing') }}</button>
                <div>
                    <button type="button" class="pickup-confirm-cancel" :disabled="busy === returnToMerchantConfirmation.id" @click="clearActionConfirmations">{{ t('Cancel') }}</button>
                    <button type="button" class="pickup-confirm-submit" :disabled="busy === returnToMerchantConfirmation.id" @click="confirmReturnToMerchant"><span v-if="busy === returnToMerchantConfirmation.id" class="loader"></span><span v-else>{{ t('Confirm') }}</span></button>
                </div>
            </section>
        </div>
        </Teleport>
    </AppShell>
</template>

<style scoped>
.orders-overview-title{margin:3px 0 16px;color:var(--ink);font-size:19px;font-weight:900}.merchant-status-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}.merchant-status-card{--status:#0b766f;--status-tint:var(--primary-tint);position:relative;display:grid;grid-template-columns:1fr auto;gap:7px;min-height:126px;overflow:hidden;padding:13px 13px 12px;border:1px solid color-mix(in srgb,var(--status) 22%,var(--border));border-radius:20px;background:linear-gradient(145deg,color-mix(in srgb,var(--status-tint) 78%,var(--surface)),var(--surface));color:var(--ink);font:inherit;text-align:start;cursor:pointer;box-shadow:0 8px 18px rgba(11,110,104,.055);transition:transform .15s,box-shadow .15s,border-color .15s}.merchant-status-card::after{position:absolute;inset:auto -22px -30px auto;width:98px;height:98px;border-radius:50%;background:color-mix(in srgb,var(--status) 11%,transparent);content:'';pointer-events:none}.merchant-status-card:active{transform:scale(.98);box-shadow:0 3px 10px rgba(11,110,104,.10)}.merchant-status-card:focus-visible{outline:3px solid color-mix(in srgb,var(--status) 30%,transparent);outline-offset:2px}.merchant-status-top{display:flex;grid-column:1/-1;align-items:center;justify-content:space-between;position:relative;z-index:1}.merchant-status-icon{width:41px;height:41px;display:grid;place-items:center;border-radius:13px;background:var(--status-tint);color:var(--status);box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--status) 8%,transparent)}.merchant-status-arrow{display:grid;place-items:center;width:25px;height:25px;border-radius:50%;background:color-mix(in srgb,var(--status) 10%,transparent);color:var(--status);font:900 23px/1 Arial;transform:rotate(180deg)}.merchant-status-copy{position:relative;z-index:1;display:grid;align-content:end;min-width:0}.merchant-status-copy b{overflow:hidden;color:var(--ink);font-size:11.5px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.merchant-status-copy small{margin-top:3px;color:var(--ink-faint);font-size:9px;font-weight:800}.merchant-status-card strong{position:relative;z-index:1;align-self:end;color:var(--status);font-size:29px;font-weight:950;line-height:.95;letter-spacing:-.04em}.merchant-status-card.pending{--status:#e18400;--status-tint:#fff2c7}.merchant-status-card.approved{--status:#069be5;--status-tint:#e0f2fe}.merchant-status-card.courier{--status:#2864dd;--status-tint:#dbeafe}.merchant-status-card.delivered{--status:#159b58;--status-tint:#dcfce7}.merchant-status-card.returned{--status:#dc3e38;--status-tint:#fee2e2}.merchant-status-card.all{--status:var(--primary-strong);--status-tint:var(--primary-tint)}.orders-sticky-tools{position:sticky;top:-16px;z-index:18;margin:0 -2px 12px;padding:10px 2px 9px;background:linear-gradient(var(--bg) 84%,color-mix(in srgb,var(--bg) 0%,transparent));box-shadow:0 9px 13px -15px rgba(7,35,33,.55)}.orders-list-head{display:flex;align-items:center;gap:8px}.orders-back,.orders-search-toggle{display:grid;place-items:center;width:36px;height:36px;border:0;border-radius:11px;background:var(--surface-2);color:var(--ink);cursor:pointer}.orders-search-toggle{color:var(--primary-strong)}.orders-search-toggle.active{color:#fff;background:var(--primary)}.orders-list-head>b{flex:1;overflow:hidden;font-size:14px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.orders-list-head>span{min-width:24px;padding:4px 8px;border-radius:20px;background:var(--surface-2);color:var(--ink-soft);font-size:11px;font-weight:800;text-align:center}
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
.order-financial-card{display:grid;gap:9px;margin-top:11px;padding:10px;border:1px solid color-mix(in srgb,var(--primary) 24%,var(--border));border-radius:15px;background:linear-gradient(145deg,color-mix(in srgb,var(--primary-tint) 45%,var(--surface)),var(--surface))}.order-financial-split{display:grid;grid-template-columns:1fr 1fr;gap:8px}.order-financial-item{display:grid;gap:4px;min-width:0;padding:10px;border-radius:11px;background:var(--surface);box-shadow:inset 0 0 0 1px var(--border)}.order-financial-item span{color:var(--ink-faint);font-size:9.5px;font-weight:850}.order-financial-item b{overflow:hidden;font-size:14px;font-weight:950;text-overflow:ellipsis;white-space:nowrap}.order-financial-item b small{font-family:var(--font);font-size:8.5px;font-weight:800}.order-financial-item.product b{color:var(--primary-strong)}.order-financial-item.fee b{color:#b87510}.detail-total-card{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0;padding:13px 14px;border-radius:11px;background:var(--primary);color:#fff;box-shadow:0 7px 16px rgba(7,123,115,.18)}.detail-total-card span{font-size:11px;font-weight:850;opacity:.85}.detail-total-card strong{font-size:20px;font-weight:950;line-height:1}.detail-total-card small{font-family:var(--font);font-size:10px;opacity:.8}.order-archive{display:flex;align-items:center;justify-content:center;width:100%;min-height:43px;margin-top:10px;border:1px solid var(--primary);border-radius:11px;background:var(--surface);color:var(--primary);font:900 12px var(--font);text-decoration:none;cursor:pointer}.order-archive:disabled{opacity:.6;cursor:wait}.order-product-price{display:inline-flex;align-items:baseline;gap:3px;padding:6px 9px;border-radius:9px;background:#e3f4ee;color:#087b75!important;box-shadow:inset 0 0 0 1px rgba(8,123,117,.14)}.order-product-price small{color:inherit!important;opacity:.8}html[data-theme="dark"] .order-financial-card{background:#102b28;border-color:rgba(105,219,208,.32)}html[data-theme="dark"] .order-financial-item{background:#14322f;border-color:#244a45}html[data-theme="dark"] .order-financial-item.fee{background:#342b18}
.order-complaint{display:flex;align-items:center;justify-content:center;width:100%;margin-top:10px;padding:9px 12px;border:1px solid color-mix(in srgb,var(--danger) 24%,transparent);border-radius:10px;background:color-mix(in srgb,var(--danger-tint) 82%,transparent);color:var(--danger);font:inherit;font-size:11px;font-weight:900;cursor:pointer}
.republish-expired{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:12px;padding:11px 12px;border:1px solid color-mix(in srgb,var(--accent) 42%,var(--border));border-radius:11px;background:var(--accent-tint);color:var(--accent);font-size:11px;font-weight:900}.republish-expired span{color:var(--ink-soft);font-size:10px}
.order-chat{border-color:color-mix(in srgb,var(--primary) 28%,transparent);background:var(--primary-tint);color:var(--primary-strong)}
.customer-phone-locked{letter-spacing:1px;color:var(--ink-faint);font-size:12px}
.order-recreate{display:flex;align-items:center;justify-content:center;width:100%;min-height:39px;margin-top:10px;border:1px solid color-mix(in srgb,var(--primary) 28%,transparent);border-radius:10px;background:var(--primary-tint);color:var(--primary-strong);font:900 11px var(--font);cursor:pointer}.order-recreate:disabled{opacity:.6;cursor:wait}
.order-delete{display:flex;align-items:center;justify-content:center;width:100%;min-height:39px;margin-top:10px;border:1px solid color-mix(in srgb,var(--danger) 48%,var(--border));border-radius:10px;background:transparent;color:var(--danger);font:900 11px var(--font);cursor:pointer}.order-delete:disabled{opacity:.6;cursor:wait}
.return-flow{display:grid;gap:10px;margin-top:15px;padding:13px;border:1px solid color-mix(in srgb,var(--danger) 30%,var(--border));border-radius:14px;background:color-mix(in srgb,var(--danger-tint) 48%,var(--surface))}.return-flow h4{margin:0;color:var(--ink);font-size:13px;font-weight:900}.return-flow p{margin:0;color:var(--ink-soft);font-size:10.5px;font-weight:700;line-height:1.65}.return-flow-button{width:100%;min-height:39px}.return-flow-cancel{border:0;background:transparent;color:var(--ink-soft);font:800 11px var(--font);cursor:pointer}.return-fee-field{display:flex;align-items:center;gap:8px;border:1px solid color-mix(in srgb,var(--danger) 26%,var(--border));border-radius:10px;background:var(--surface);padding:0 10px}.return-fee-field input{width:100%;min-height:39px;border:0;outline:0;background:transparent;color:var(--ink);font:900 14px var(--font)}.return-fee-field span{color:var(--ink-faint);font-size:10px;font-weight:800}.return-fee-presets{display:flex;flex-wrap:wrap;gap:6px}.return-fee-presets button{border:0;border-radius:8px;background:var(--surface-2);color:var(--ink-soft);font:800 10px var(--font);padding:6px 9px;cursor:pointer}.return-fee-summary{padding:8px 10px;border-radius:9px;background:var(--surface);color:var(--danger);font-size:11px}.return-confirmation{border-color:color-mix(in srgb,var(--accent) 32%,var(--border));background:color-mix(in srgb,var(--accent-tint) 50%,var(--surface))}.return-confirmation .return-fee-summary{color:var(--accent)}
.return-reason-box,.return-reason-summary{overflow-wrap:anywhere}.return-reason-summary{padding:8px 10px;border-radius:9px;background:var(--surface);color:var(--ink-soft);font-size:10.5px;line-height:1.55}.return-choice-dialog{text-align:start}.return-choice-dialog>h3,.return-choice-dialog>p{text-align:center}.return-reason-field{display:grid;gap:6px;margin:0 0 14px;text-align:start}.return-reason-field span{color:var(--ink-soft);font-size:10.5px;font-weight:850}.return-reason-field textarea{box-sizing:border-box;width:100%;resize:vertical;border:1px solid var(--border);border-radius:11px;background:var(--surface-2);color:var(--ink);font:750 12px/1.55 var(--font);outline:0;padding:10px}.return-reason-field textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-tint)}.return-choice-actions{margin-top:0}.return-choice-actions .return-without-fee{background:var(--surface-2);color:var(--ink-soft)}.return-choice-cancel{display:block;width:100%;margin-top:9px;background:transparent;color:var(--ink-soft)}
.courier-orders-overview .merchant-status-grid{gap:10px}
.orders-search{max-width:100%;margin-top:9px;padding:0 1px}.orders-search input{min-height:38px}.orders-search button{min-height:35px;padding:0 12px;border:0;border-radius:9px;background:var(--primary);color:#fff;font:850 10.5px var(--font);cursor:pointer}
.merchant-status-card.all .merchant-status-icon{background:var(--primary-tint);color:var(--primary-strong)}.merchant-status-card.all strong{color:var(--primary-strong)}.mobile-order-note{border:1px solid color-mix(in srgb,var(--danger) 18%,transparent);background:color-mix(in srgb,var(--danger-tint) 70%,var(--surface))}.mobile-order-note b{color:var(--danger)}

/*
 * Order queues deliberately share one calm surface. Status is expressed by
 * the slim accent, icon and badge only, so every queue stays close to the
 * approved-order reference instead of switching to a white/red/blue card.
 *
 * Keep the dark selectors as normal scoped selectors. `:global([data-theme])`
 * followed by a local selector is compiled by Vue as only `[data-theme]`,
 * which was applying the card declarations to the whole document instead of
 * to the cards themselves.
 */
.merchant-status-grid{gap:10px}
.merchant-status-card{
    min-height:118px;
    padding:12px;
    border:1px solid #b2ddd7;
    border-radius:17px;
    background:#f1faf8;
    box-shadow:0 8px 18px rgba(7,91,83,.065);
    color:var(--ink);
}
.merchant-status-card::before{display:none}
.merchant-status-card::after{width:84px;height:84px;inset:auto -22px -28px auto;background:rgba(14,125,116,.075)}
.merchant-status-icon{width:38px;height:38px;border:0;border-radius:11px;background:#e2f3f0;color:var(--status)}
.merchant-status-arrow{width:26px;height:26px;border:0;background:#e7f5f2;color:var(--status);font-size:22px}
.merchant-status-copy b{color:var(--ink);font-size:11px}.merchant-status-copy small{color:var(--ink-faint);font-size:9px}.merchant-status-card strong{color:var(--status);font-size:27px;line-height:.95}
.merchant-status-card.pending{--status:#bd7a16}.merchant-status-card.approved{--status:#087f79}.merchant-status-card.courier{--status:#197f8b}.merchant-status-card.delivered{--status:#168957}.merchant-status-card.returned{--status:#c65046}.merchant-status-card.all{--status:#087f79}

.mobile-order-stack{gap:10px}
.mobile-order-card{
    --order-accent:#087f79;
    --order-surface:#f1faf8;
    position:relative;
    isolation:isolate;
    overflow:hidden;
    border:1px solid #a9d6d0;
    border-radius:17px;
    background:var(--order-surface);
    box-shadow:0 9px 20px rgba(7,91,83,.075);
}
.mobile-order-card::before{
    position:absolute;
    z-index:1;
    inset:0 auto 0 0;
    display:block;
    width:3px;
    border-radius:0 3px 3px 0;
    background:var(--order-accent);
    content:'';
}
.mobile-order-card.status-pending{--order-accent:#bd7a16}.mobile-order-card.status-approved{--order-accent:#087f79}.mobile-order-card.status-courier{--order-accent:#197f8b}.mobile-order-card.status-delivered{--order-accent:#168957}.mobile-order-card.status-returned{--order-accent:#c65046}
.mobile-order-head{position:relative;z-index:2;align-items:center;gap:10px;padding:12px 13px 7px}
.mobile-order-card .order-ic{width:38px;height:38px;border-radius:11px;background:#e1f2ef;color:#08756f}
.mobile-order-head .order-mid{padding-top:0}.mobile-order-head .order-mid b{color:var(--ink);font-size:13px;font-weight:900;line-height:1.35}.mobile-order-head .order-mid span{margin-top:2px;color:var(--ink-soft);font-size:10px;font-weight:750}.mobile-order-head :deep(.badge){margin-top:0;box-shadow:0 1px 0 rgba(7,91,83,.05)}
.mobile-order-summary{position:relative;z-index:2;min-height:39px;align-items:center;gap:8px;padding:1px 13px 9px}
.mobile-order-card .mobile-order-summary strong{color:var(--order-accent);font-size:16px;font-weight:950;letter-spacing:-.02em}.mobile-order-card .mobile-order-summary small{color:var(--ink-soft);font-size:10px;font-weight:800}.mobile-order-tags{gap:6px;justify-content:flex-end}
.mobile-order-card .mobile-vehicle-badge,.mobile-order-card .mobile-order-type-badge{min-height:30px;max-width:136px;padding:5px 9px;border:1px solid #acd6d1;border-radius:9px;background:#e9f6f3;color:#087b75;font-size:10px;font-weight:900}.mobile-order-card .mobile-order-type-badge{border-color:#ecd1ad;background:#fff4e5;color:#c67a17}
.mobile-order-note{position:relative;z-index:2;overflow-wrap:anywhere;word-break:break-word;white-space:normal;margin:0 13px 10px;padding:8px 9px;border:1px solid #acd6d1;border-radius:9px;background:#edf8f6;color:var(--ink-soft);font-size:10px;font-weight:750;line-height:1.65}.mobile-order-note b{color:#08756f;font-weight:900}
.mobile-order-timer{position:relative;z-index:2;min-height:42px;justify-content:flex-start;gap:6px;padding:8px 13px;border-top:1px solid #c8e5e0;background:#f5fbfa;color:#087f79;font-size:10px;font-weight:900}.mobile-order-timer i{width:7px;height:7px;background:#0b8c84;box-shadow:0 0 7px rgba(11,140,132,.5)}

/* The exact same hierarchy is retained in the dark mode, on a single deep
   teal surface and without the washed-out light gradient. */
html[data-theme="dark"] .merchant-status-card{border-color:rgba(119,208,198,.27);background:#0d2926;box-shadow:0 10px 22px rgba(0,0,0,.23);color:#effaf8}
html[data-theme="dark"] .merchant-status-card::after{background:rgba(78,205,194,.09)}
html[data-theme="dark"] .merchant-status-icon,html[data-theme="dark"] .merchant-status-arrow{background:rgba(84,205,195,.11)}
html[data-theme="dark"] .merchant-status-copy b{color:#effaf8}html[data-theme="dark"] .merchant-status-copy small{color:rgba(219,244,240,.64)}
html[data-theme="dark"] .mobile-order-card{--order-surface:#0d2926;border-color:rgba(115,210,200,.3);background:var(--order-surface);box-shadow:0 10px 23px rgba(0,0,0,.23)}
html[data-theme="dark"] .mobile-order-card .order-ic{background:#123d38;color:#69d9d0}html[data-theme="dark"] .mobile-order-head .order-mid b{color:#effaf8}html[data-theme="dark"] .mobile-order-head .order-mid span,html[data-theme="dark"] .mobile-order-card .mobile-order-summary small{color:rgba(219,244,240,.64)}
html[data-theme="dark"] .mobile-order-card .mobile-vehicle-badge{border-color:rgba(104,215,204,.26);background:#123732;color:#78dbd3}html[data-theme="dark"] .mobile-order-card .mobile-order-type-badge{border-color:rgba(244,184,92,.28);background:#352918;color:#f5bf62}
html[data-theme="dark"] .mobile-order-note{border-color:rgba(102,209,198,.24);background:#112f2c;color:rgba(220,244,240,.75)}html[data-theme="dark"] .mobile-order-note b{color:#71d9d0}
html[data-theme="dark"] .mobile-order-timer{border-top-color:rgba(132,222,213,.17);background:#0a211f;color:#65d8a0}html[data-theme="dark"] .mobile-order-timer i{background:#62d8a0;box-shadow:0 0 8px rgba(98,216,160,.46)}

/* Cards stay neutral; the status badge alone communicates their state. */
.mobile-order-card{border-color:var(--border);background:var(--surface);box-shadow:0 5px 14px rgba(7,91,83,.045)}
.mobile-order-card::before{display:none}.mobile-order-card .mobile-order-summary strong{color:var(--ink)}
html[data-theme="dark"] .mobile-order-card{border-color:rgba(220,244,240,.13);background:var(--surface);box-shadow:0 6px 16px rgba(0,0,0,.16)}

/* Make the pickup deadline legible at a glance in dark mode. */
html[data-theme="dark"] .pickup-countdown{border-color:rgba(245,190,82,.48);background:#2a2417;color:#f8e8bd}html[data-theme="dark"] .pickup-countdown strong{padding:3px 8px;border-radius:7px;background:rgba(255,196,91,.16);color:#ffd37a;text-shadow:0 1px 0 rgba(0,0,0,.3)}

/* Notes must remain readable on narrow phones and never overflow a card. */
.detail-note-box{overflow-wrap:anywhere;word-break:break-word;white-space:normal;line-height:1.7}.detail-note-box b{display:inline}

/* The courier's merchant card separates the map destination from contact data. */
.courier-merchant-card{border-color:color-mix(in srgb,var(--primary) 42%,var(--border));background:linear-gradient(145deg,color-mix(in srgb,var(--primary-tint) 82%,var(--surface)),var(--surface))}
.merchant-pickup-location{display:grid;grid-template-columns:auto minmax(0,1fr);gap:10px;align-items:center;margin:1px 0;padding:10px 0 2px;border-top:1px solid color-mix(in srgb,var(--primary) 18%,var(--border));color:inherit;text-decoration:none}.merchant-pickup-icon{display:grid;place-items:center;width:38px;height:38px;border-radius:11px;background:var(--primary-tint);color:var(--primary-strong)}.merchant-pickup-copy{display:grid;gap:2px;min-width:0}.merchant-pickup-copy small{color:var(--primary-strong);font-size:10px;font-weight:900}.merchant-pickup-copy b{overflow:hidden;color:var(--ink);font-size:12.5px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.merchant-pickup-copy em{overflow-wrap:anywhere;word-break:break-word;white-space:normal;color:var(--ink-soft);font-size:9.5px;font-style:normal;font-weight:700;line-height:1.55}.merchant-pickup-open{grid-column:1/-1;display:flex;align-items:center;justify-content:flex-start;gap:6px;min-height:28px;padding-inline-start:48px;color:var(--primary-strong);font-size:10.5px;font-weight:900}
.merchant-location-inline{display:grid;grid-template-columns:42px minmax(0,1fr) auto;align-items:center;gap:10px;min-width:0;min-height:64px;margin-top:3px;padding:10px 11px;border:1.5px solid color-mix(in srgb,var(--success) 48%,var(--border));border-radius:14px;background:linear-gradient(135deg,color-mix(in srgb,var(--success-tint) 88%,var(--surface)),color-mix(in srgb,var(--primary-tint) 38%,var(--surface)));color:var(--primary-strong);box-shadow:0 5px 13px rgba(11,110,104,.11);text-decoration:none;touch-action:manipulation}.merchant-location-inline>svg{display:grid;place-self:center;width:42px;height:42px;padding:10px;border-radius:12px;background:var(--success);color:#fff}.merchant-location-inline>span{display:grid;min-width:0;gap:2px}.merchant-location-inline small{color:var(--primary-strong);font-size:9.5px;font-weight:900}.merchant-location-inline b{overflow:hidden;color:var(--ink);font-size:12px;font-weight:950;text-overflow:ellipsis;white-space:nowrap}.merchant-location-inline i{display:flex;align-items:center;justify-content:center;min-height:32px;padding:0 9px;border-radius:9px;background:var(--primary);color:#fff;font-size:9px;font-style:normal;font-weight:900;white-space:nowrap;box-shadow:0 3px 8px rgba(7,84,80,.18)}.merchant-location-inline:active{transform:scale(.98);filter:brightness(.98)}
html[data-theme="dark"] .courier-merchant-card{border-color:rgba(105,219,208,.34);background:#102b28!important}html[data-theme="dark"] .merchant-pickup-location{border-top-color:rgba(101,220,176,.28)}html[data-theme="dark"] .merchant-pickup-copy small,html[data-theme="dark"] .merchant-pickup-open{color:#8ce3d7}html[data-theme="dark"] .merchant-pickup-copy b{color:#f1fffc}html[data-theme="dark"] .merchant-pickup-copy em{color:#bad9d4}

/* A sheet modal creates its own stacking context. Keep every confirmation
   above it and make the action buttons available on touch devices. */
.pickup-confirm-backdrop{position:fixed;z-index:2147483000;inset:0;display:grid;place-items:center;padding:22px;background:rgba(4,24,27,.56);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px)}
.pickup-confirm-dialog{width:min(100%,390px);box-sizing:border-box;padding:24px 20px 19px;border:1px solid color-mix(in srgb,var(--primary) 32%,#fff);border-radius:22px;background:var(--surface);color:var(--ink);box-shadow:0 24px 70px rgba(0,0,0,.28);text-align:center;animation:pickup-confirm-in .18s ease-out}.pickup-confirm-icon{display:grid;place-items:center;width:43px;height:43px;margin:0 auto 12px;border-radius:50%;background:var(--success-tint);color:var(--success);font-size:24px;font-weight:950}.pickup-confirm-dialog h3{margin:0;color:var(--ink);font-size:18px;font-weight:950}.pickup-confirm-dialog p{margin:9px 0 18px;color:var(--ink-soft);font-size:13px;font-weight:750;line-height:1.75}.pickup-confirm-dialog>div{display:grid;grid-template-columns:1fr 1fr;gap:9px}.pickup-confirm-dialog button{min-height:44px;border:0;border-radius:12px;font:900 12px var(--font);cursor:pointer}.pickup-confirm-cancel{background:var(--surface-2);color:var(--ink-soft)}.pickup-confirm-submit{background:var(--primary);color:#fff}.pickup-confirm-dialog button:disabled{cursor:wait;opacity:.65}.archive-confirm-icon{background:var(--primary-tint);color:var(--primary-strong)}
.confirmation-error{margin:-5px 0 14px!important;padding:8px 10px;border-radius:10px;background:var(--danger-tint);color:var(--danger)!important;font-size:10.5px!important;font-weight:850!important;line-height:1.6!important}.confirmation-location{width:100%;min-height:39px;margin:-7px 0 14px;border:1px solid color-mix(in srgb,var(--primary) 28%,var(--border));border-radius:10px;background:var(--primary-tint);color:var(--primary-strong);font:850 11px var(--font);cursor:pointer}
.availability-confirm-icon{background:var(--warning-tint);color:var(--warning)}.availability-confirm-activate{background:var(--success)}
html[data-theme="dark"] .pickup-confirm-dialog{border-color:rgba(110,220,210,.28);background:#102b28;color:#effaf8}html[data-theme="dark"] .pickup-confirm-dialog h3{color:#effaf8}html[data-theme="dark"] .pickup-confirm-dialog p{color:rgba(220,244,240,.76)}html[data-theme="dark"] .pickup-confirm-cancel{background:#173b37;color:#bfe2dd}@keyframes pickup-confirm-in{from{opacity:0;transform:translateY(10px) scale(.98)}to{opacity:1;transform:translateY(0) scale(1)}}
/* Customer contact card and centered financial figures. */
.customer-details-card{display:grid;gap:10px;margin:3px 0 12px;padding:12px;border:1.5px solid color-mix(in srgb,var(--primary) 30%,var(--border));border-radius:15px;background:linear-gradient(145deg,color-mix(in srgb,var(--primary-tint) 62%,var(--surface)),var(--surface))}.customer-details-head{display:flex;align-items:center;gap:9px}.customer-details-avatar{display:grid;place-items:center;width:40px;height:40px;flex:none;border-radius:13px;background:var(--primary);color:#fff;font-size:17px;font-weight:950}.customer-details-head>span:last-child{display:grid;gap:1px;min-width:0}.customer-details-card small{display:block;color:var(--ink-faint);font-size:9px;font-weight:850}.customer-details-head b{overflow:hidden;color:var(--ink);font-size:13px;font-weight:950;text-overflow:ellipsis;white-space:nowrap}.customer-details-contacts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.customer-details-contacts>span,.customer-details-address{display:grid;gap:3px;min-width:0;padding:8px 9px;border-radius:10px;background:var(--surface);box-shadow:inset 0 0 0 1px var(--border)}.customer-details-contacts b{overflow:hidden;color:var(--primary-strong);font-size:11px;font-weight:900;text-overflow:ellipsis;white-space:nowrap;direction:ltr;text-align:right}.customer-details-address b{overflow-wrap:anywhere;color:var(--ink-soft);font-size:10.5px;font-weight:750;line-height:1.6}.customer-details-card .customer-whatsapp{margin:0;background:#149b63;color:#fff;box-shadow:0 5px 12px rgba(20,155,99,.19)}.order-financial-item{justify-items:center!important;text-align:center}.order-financial-item b{display:block;width:100%}
.courier-verification-notice{display:flex;align-items:flex-start;gap:10px;margin:0 0 14px;padding:12px;border:1px solid color-mix(in srgb,var(--warning) 45%,var(--border));border-radius:14px;background:var(--warning-tint);color:var(--ink)}.courier-verification-icon{display:grid;width:21px;height:21px;place-items:center;flex:none;border-radius:50%;background:var(--warning);color:#fff;font-size:13px;font-weight:950;line-height:1}.courier-verification-notice>div{display:grid;gap:2px;min-width:0}.courier-verification-notice b{color:var(--ink);font-size:11.5px;font-weight:900}.courier-verification-notice p{margin:0;color:var(--ink-soft);font-size:10px;font-weight:750;line-height:1.65}
</style>
