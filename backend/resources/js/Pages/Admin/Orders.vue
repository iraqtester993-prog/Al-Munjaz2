<script setup>
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'
import StatusBadge from '../../Components/StatusBadge.vue'
import SheetModal from '../../Components/SheetModal.vue'

const props = defineProps({
    orders: { type: Object, required: true },
    counts: { type: Object, required: true },
    filter: { type: String, default: 'all' },
    q: { type: String, default: '' },
    courierId: { type: [String, Number], default: '' },
    couriers: { type: Array, default: () => [] },
    courierFilters: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    canUpdateOrders: { type: Boolean, default: false },
})

const query = ref(props.q)
const active = ref(props.filter)
const courierFilter = ref(normalizeCourierId(props.courierId))
const assignFor = ref(null)
const assignCourier = ref('')
const assignmentRole = ref('courier')
const branchFor = ref(null)
const originBranch = ref('')
const destinationBranch = ref('')
const detailsFor = ref(null)
const busyId = ref(null)

const eligibleCouriers = computed(() => {
    if (!assignFor.value?.province_id) return []

    return props.couriers.filter((courier) =>
        (courier.assignment_roles || []).includes(assignmentRole.value)
        && (courier.provinces || []).some((province) => Number(province.id) === Number(assignFor.value.province_id))
    )
})

const assignmentModes = computed(() => {
    const order = assignFor.value
    const modes = [
        { key: 'courier', label: t('Courier') },
        { key: 'pickup_courier', label: t('Pickup courier') },
        { key: 'delivery_courier', label: t('Delivery courier') },
    ]

    if (!order) return modes

    return modes.filter((mode) => mode.key !== 'courier' || (order.status === 'pending' && !order.courier_id))
})

const eligibleBranches = computed(() => {
    if (!branchFor.value) return []

    return props.branches.filter((branch) =>
        Boolean(branch.is_platform_managed)
        || Number(branch.tenant_id) === Number(branchFor.value.tenant_id)
    )
})

const filters = computed(() => {
    const list = [{ key: 'all', label: t('All') }]
    // Keep the dashboard focused on the live delivery flow.  Cancellation,
    // damage, and rejection remain valid historical/order states, but are not
    // promoted as top-level dashboard filters.
    for (const status of ['pending', 'approved', 'courier', 'delivered', 'returned', 'late']) {
        list.push({ key: status, label: tStatus(status) })
    }
    list.push({ key: 'deleted', label: t('Deleted') })
    return list
})

const sortedCouriers = computed(() => [...(props.courierFilters.length ? props.courierFilters : props.couriers)]
    .sort((first, second) => String(first.name || '').localeCompare(String(second.name || ''), document.documentElement.lang || 'ar')))

const hasActiveQuery = computed(() => (
    active.value !== 'all'
    || Boolean(String(query.value || '').trim())
    || Boolean(courierFilter.value)
))

const statusOptions = ['pending', 'approved', 'courier', 'delivered', 'returned', 'cancelled', 'damaged', 'rejected']

function tStatus(status) {
    const labels = {
        pending: 'Pending',
        approved: 'Approved',
        courier: 'With Courier',
        delivered: 'Delivered',
        returned: 'Returned',
        cancelled: 'Cancelled',
        damaged: 'Damaged',
        rejected: 'Rejected',
        late: 'Late',
        deleted: 'Deleted',
    }

    return t(labels[status] || status)
}

function tStage(stage) {
    const labels = {
        created: 'Created',
        awaiting_pickup: 'Awaiting pickup',
        pickup_assigned: 'Pickup assigned',
        picked_up: 'Picked up',
        at_origin_branch: 'At origin branch',
        sorting: 'Sorting',
        awaiting_transfer: 'Awaiting transfer',
        in_transfer: 'In transfer',
        at_destination_branch: 'At destination branch',
        delivery_assigned: 'Delivery assigned',
        out_for_delivery: 'Out for delivery',
        delivered: 'Delivered',
        returned: 'Returned',
        return_pending_merchant: 'Return pending merchant confirmation',
        returned_to_merchant: 'Returned to merchant',
        cancelled: 'Cancelled',
        damaged: 'Damaged',
        rejected: 'Rejected',
        financially_closed: 'Financially closed',
    }

    return t(labels[stage] || stage || 'Not specified')
}

function hasPickupPoint(order) {
    const latitude = Number(order?.pickup_latitude)
    const longitude = Number(order?.pickup_longitude)

    return Number.isFinite(latitude) && Number.isFinite(longitude)
        && latitude >= -90 && latitude <= 90
        && longitude >= -180 && longitude <= 180
}

function pickupMapUrl(order) {
    if (!hasPickupPoint(order)) return '#'

    const latitude = Number(order.pickup_latitude).toFixed(6)
    const longitude = Number(order.pickup_longitude).toFixed(6)

    return `https://www.openstreetmap.org/?mlat=${latitude}&mlon=${longitude}#map=16/${latitude}/${longitude}`
}

function normalizeCourierId(value) {
    const id = String(value ?? '').trim()
    return /^\d+$/.test(id) ? id : ''
}

function orderQuery(page = null) {
    const params = { filter: active.value }
    const search = String(query.value || '').trim()
    const courierId = normalizeCourierId(courierFilter.value)

    if (search) params.q = search
    if (courierId) params.courier_id = courierId
    if (page) params.page = page

    return params
}

function apply() {
    router.get(route('admin.orders'), orderQuery(), { preserveState: true, replace: true })
}

function clearFilters() {
    active.value = 'all'
    query.value = ''
    courierFilter.value = ''
    apply()
}

function goToPage(page) {
    if (!page) return

    router.get(route('admin.orders'), orderQuery(page), { preserveState: true, replace: true })
}

watch(() => props.filter, (value) => {
    active.value = value || 'all'
})

watch(() => props.q, (value) => {
    query.value = value || ''
})

watch(() => props.courierId, (value) => {
    courierFilter.value = normalizeCourierId(value)
})

function setStatus(order, status) {
    if (!props.canUpdateOrders || busyId.value) return

    // Normal lifecycle moves do not need an explanation.  A correction (for
    // example, changing a delivered order back to pending) is deliberately
    // auditable on the server, so ask the operator for the required note.
    const normalMoves = {
        pending: ['approved', 'cancelled', 'rejected'],
        approved: ['courier', 'cancelled', 'rejected'],
        courier: ['delivered', 'cancelled', 'damaged', 'rejected'],
    }
    const isCorrection = !normalMoves[order.status]?.includes(status)
    const note = isCorrection
        ? window.prompt(t('Enter an administrative reason for this status correction.'))
        : null

    if (isCorrection && !String(note || '').trim()) return

    busyId.value = order.id
    router.post(
        route('admin.orders.status', order.id),
        { status, note: isCorrection ? String(note).trim() : null },
        {
            preserveScroll: true,
            onSuccess: () => {
                if (detailsFor.value?.id === order.id) detailsFor.value = null
            },
            onFinish: () => (busyId.value = null),
        }
    )
}

function isPickupOverdue(order) {
    if (order?.status !== 'approved' || !order?.courier_id || !order?.pickup_deadline_at) return false

    const deadline = new Date(order.pickup_deadline_at).getTime()
    return Number.isFinite(deadline) && deadline <= Date.now()
}

function reofferOverduePickup(order) {
    if (!props.canUpdateOrders || busyId.value || !isPickupOverdue(order)) return

    const message = `${t('The courier has not reached the merchant by the agreed deadline. Re-offering returns the reserved budget and opens the order to eligible couriers.')}\n\n${t('Continue?')}`
    if (!window.confirm(message)) return

    const note = window.prompt(t('Optional note for the re-offer record.'))
    if (note === null) return

    busyId.value = order.id
    router.post(route('admin.orders.reoffer-overdue-pickup', order.id), {
        note: String(note).trim() || null,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            if (detailsFor.value?.id === order.id) detailsFor.value = null
        },
        onFinish: () => (busyId.value = null),
    })
}

function openAssign(order) {
    if (!props.canUpdateOrders) return
    assignFor.value = order
    assignmentRole.value = order.status === 'pending' && !order.courier_id ? 'courier' : 'pickup_courier'
    assignCourier.value = ''
}

function doAssign() {
    if (!props.canUpdateOrders || !assignCourier.value || !assignFor.value) return

    busyId.value = assignFor.value.id
    router.post(
        route('admin.orders.courier', assignFor.value.id),
        { courier_id: assignCourier.value, assignment_role: assignmentRole.value },
        {
            preserveScroll: true,
            onSuccess: () => (assignFor.value = null),
            onFinish: () => (busyId.value = null),
        }
    )
}

function openBranches(order) {
    if (!props.canUpdateOrders) return
    branchFor.value = order
    originBranch.value = order.origin_branch_id || ''
    destinationBranch.value = order.destination_branch_id || ''
}

function saveBranches() {
    if (!props.canUpdateOrders || !branchFor.value || (!originBranch.value && !destinationBranch.value)) return

    busyId.value = branchFor.value.id
    router.post(route('admin.orders.branches', branchFor.value.id), {
        origin_branch_id: originBranch.value || null,
        destination_branch_id: destinationBranch.value || null,
    }, {
        preserveScroll: true,
        onSuccess: () => (branchFor.value = null),
        onFinish: () => (busyId.value = null),
    })
}

function openDetails(order) {
    detailsFor.value = order
}

function money(value) {
    if (value === null || value === undefined) return '—'
    return `${fmt(value)} ${t('IQD')}`
}

function sourceLabel(source) {
    return source === 'courier' ? t('Courier') : t('Merchant')
}

function branchName(branch) {
    return branch?.name || t('Not specified')
}

function routeText(order) {
    const origin = order.origin_branch?.name
    const destination = order.destination_branch?.name

    if (origin && destination && origin !== destination) return `${origin} → ${destination}`
    return origin || destination || t('Not specified')
}

function formatDate(value) {
    if (!value) return '—'

    try {
        const lang = document.documentElement.lang || 'ar'
        const locale = lang === 'ar' ? 'ar-IQ-u-nu-latn' : lang === 'ku' ? 'ku-IQ-u-nu-latn' : 'en-US'
        return new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(new Date(value))
    } catch {
        return value
    }
}

function formatDateTime(value) {
    if (!value) return '—'

    try {
        const lang = document.documentElement.lang || 'ar'
        const locale = lang === 'ar' ? 'ar-IQ-u-nu-latn' : lang === 'ku' ? 'ku-IQ-u-nu-latn' : 'en-US'
        return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
    } catch {
        return value
    }
}

function timelineTitle(event) {
    if (event.kind === 'created') return t('Order created')
    if (event.kind === 'assignment') return `${t('Courier assignment')} — ${courierRoleLabel(event.assignment_role)}`
    if (event.kind === 'movement') return `${t('Branch movement')} — ${tStage(event.stage)}`
    return `${t('Order status changed')} — ${tStatus(event.status)}`
}

function timelineDescription(event) {
    const details = []
    if (event.kind === 'assignment' && event.assignee?.name) {
        details.push(`${event.assignee.name} · ${courierRoleLabel(event.assignee.role || event.assignment_role)}`)
    }
    if (event.kind === 'movement') {
        const from = event.from_branch?.name
        const to = event.to_branch?.name
        if (from && to && from !== to) details.push(`${from} → ${to}`)
        else if (from || to) details.push(from || to)
    }
    if (event.note) details.push(event.note)
    if (event.actor?.name) details.push(event.actor.name)
    return details.join(' • ')
}

function courierRoleLabel(role) {
    const labels = {
        courier: 'Courier',
        pickup_courier: 'Pickup courier',
        delivery_courier: 'Delivery courier',
        transporter: 'Transporter',
    }

    return t(labels[role] || role || 'Not specified')
}

function isTerminalStatus(status) {
    return ['delivered', 'returned', 'cancelled', 'damaged', 'rejected'].includes(status)
}

function isDeleted(order) {
    return Boolean(order?.deleted_at)
}

function restoreOrder(order) {
    if (!props.canUpdateOrders || !isDeleted(order) || busyId.value) return
    if (!window.confirm(`${t('Restore Order')}?`)) return

    busyId.value = order.id
    router.post(route('admin.orders.restore', order.id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            if (detailsFor.value?.id === order.id) detailsFor.value = null
        },
        onFinish: () => (busyId.value = null),
    })
}

function provinceName(province) {
    if (!province) return t('Not specified')
    const lang = document.documentElement.lang || 'ar'
    return province[`name_${lang}`] || province.name_ar || t('Not specified')
}
</script>

<template>
    <AdminShell :title="t('Orders')">
        <div class="filter-bar">
            <button
                v-for="filterOption in filters"
                :key="filterOption.key"
                class="fbtn"
                :class="{ active: active === filterOption.key }"
                @click="active = filterOption.key; apply()"
            >
                {{ filterOption.label }} <span class="cnt">{{ counts[filterOption.key] ?? 0 }}</span>
            </button>
        </div>

        <form class="orders-query-bar" @submit.prevent="apply">
            <label class="orders-search-field">
                <span aria-hidden="true">⌕</span>
                <input
                    v-model="query"
                    type="search"
                    :placeholder="t('Search orders')"
                    :aria-label="t('Search orders')"
                />
            </label>

            <label class="orders-courier-filter">
                <span>{{ t('Courier') }}</span>
                <select v-model="courierFilter" :aria-label="t('Select courier')" @change="apply">
                    <option value="">{{ t('All Couriers') }}</option>
                    <option v-for="courier in sortedCouriers" :key="courier.id" :value="String(courier.id)">
                        {{ courier.name }}{{ courier.phone ? ` — ${courier.phone}` : '' }}
                    </option>
                </select>
            </label>

            <button class="fbtn orders-search-submit" type="submit">{{ t('Search') }}</button>
            <button v-if="hasActiveQuery" class="fbtn orders-clear-filters" type="button" @click="clearFilters">{{ t('Clear filters') }}</button>
        </form>

        <div class="panel">
            <div class="panel-body" style="padding: 0">
                <div class="admin-orders-table-wrap">
                    <table class="tbl admin-orders-table">
                        <thead>
                            <tr>
                                <th>{{ t('Order') }}</th>
                                <th>{{ t('Customer') }}</th>
                                <th>{{ t('Phone') }}</th>
                                <th>{{ t('Address') }}</th>
                                <th>{{ t('Merchant') }}</th>
                                <th>{{ t('Branches') }}</th>
                                <th>{{ t('Price') }}</th>
                                <th>{{ t('Source') }} / {{ t('Date') }}</th>
                                <th>{{ t('Courier') }}</th>
                                <th>{{ t('Status') }}</th>
                                <th>{{ t('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="order in orders.data"
                                :key="order.id"
                                class="admin-order-row"
                                tabindex="0"
                                @click="openDetails(order)"
                                @keydown.enter="openDetails(order)"
                            >
                                <td class="mono admin-order-track">{{ order.track_no }}</td>
                                <td><b>{{ order.customer?.name || order.customer_name_ar }}</b></td>
                                <td class="mono text-muted">{{ order.customer?.phone || order.phone }}</td>
                                <td class="admin-order-address">
                                    <span>{{ order.customer?.address || order.address_ar }}</span>
                                    <small v-if="order.province">{{ provinceName(order.province) }}</small>
                                </td>
                                <td>
                                    <b>{{ order.merchant?.shop_name || order.merchant?.name || order.tenant || '—' }}</b>
                                    <small v-if="order.merchant?.shop_name && order.merchant?.name">{{ order.merchant.name }}</small>
                                </td>
                                <td class="admin-route-cell">
                                    <b>{{ routeText(order) }}</b>
                                    <small>{{ tStage(order.workflow_stage) }}</small>
                                </td>
                                <td>
                                    <b class="mono">{{ money(order.financial?.order_value ?? order.price) }}</b>
                                    <small>{{ t('Delivery fee') }}: {{ money(order.financial?.delivery_fee ?? order.fee) }}</small>
                                </td>
                                <td>
                                    <span class="src-tag">{{ sourceLabel(order.source) }}</span>
                                    <small class="mono">{{ formatDate(order.date) }}</small>
                                </td>
                                <td>
                                    <b v-if="order.courier">{{ order.courier.name }}</b>
                                    <b v-else-if="order.delivery_courier">{{ order.delivery_courier.name }}</b>
                                    <b v-else-if="order.pickup_courier">{{ order.pickup_courier.name }}</b>
                                    <span v-else class="text-muted">{{ t('Unassigned') }}</span>
                                    <small v-if="(order.courier || order.delivery_courier || order.pickup_courier)?.phone" class="mono">{{ (order.courier || order.delivery_courier || order.pickup_courier).phone }}</small>
                                </td>
                                <td><StatusBadge :status="isDeleted(order) ? 'deleted' : order.status" /></td>
                                <td class="admin-order-actions-cell" @click.stop>
                                    <div class="admin-order-actions">
                                        <button class="fbtn mini" type="button" @click="openDetails(order)">{{ t('View Details') }}</button>
                                        <template v-if="isDeleted(order)">
                                            <button v-if="canUpdateOrders" class="fbtn mini restore-order-action" type="button" :disabled="busyId === order.id" @click="restoreOrder(order)">{{ t('Restore Order') }}</button>
                                        </template>
                                        <template v-else>
                                            <select
                                                v-if="canUpdateOrders"
                                                class="fbtn mini"
                                                :value="order.status"
                                                :disabled="busyId === order.id"
                                                style="appearance: auto"
                                                @change="setStatus(order, $event.target.value)"
                                            >
                                                <option v-for="status in statusOptions" :key="status" :value="status">{{ tStatus(status) }}</option>
                                            </select>
                                            <button v-if="canUpdateOrders && !isTerminalStatus(order.status)" class="fbtn mini" type="button" @click="openAssign(order)">{{ t('Assign') }}</button>
                                            <button v-if="canUpdateOrders && isPickupOverdue(order)" class="fbtn mini pickup-overdue-action" type="button" :disabled="busyId === order.id" @click="reofferOverduePickup(order)">{{ t('Re-offer overdue order') }}</button>
                                            <button v-if="canUpdateOrders" class="fbtn mini" type="button" @click="openBranches(order)">{{ t('Branches') }}</button>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="!orders.data.length" class="empty">{{ t('No orders found') }}</div>
            </div>
        </div>

        <div v-if="orders.last_page > 1" class="filter-bar">
            <button class="fbtn" :disabled="!orders.prev_page_url" @click="goToPage(orders.current_page - 1)">←</button>
            <span class="fbtn" style="cursor: default">{{ orders.current_page }} / {{ orders.last_page }}</span>
            <button class="fbtn" :disabled="!orders.next_page_url" @click="goToPage(orders.current_page + 1)">→</button>
        </div>

        <SheetModal :open="!!detailsFor" :title="t('Order Details')" :subtitle="detailsFor?.track_no" :wide="true" @close="detailsFor = null">
            <div v-if="detailsFor" class="order-detail-sheet">
                <div class="order-detail-hero">
                    <div>
                        <span class="order-detail-kicker">{{ t('Order') }}</span>
                        <b class="mono">{{ detailsFor.track_no }}</b>
                        <span>{{ formatDateTime(detailsFor.created_at) }}</span>
                    </div>
                    <StatusBadge :status="isDeleted(detailsFor) ? 'deleted' : detailsFor.status" />
                </div>

                <div class="order-detail-summary">
                    <div>
                        <span>{{ t('Price') }}</span>
                        <b class="mono">{{ money(detailsFor.financial?.order_value ?? detailsFor.price) }}</b>
                    </div>
                    <div>
                        <span>{{ t('Delivery fee') }}</span>
                        <b class="mono">{{ money(detailsFor.financial?.delivery_fee ?? detailsFor.fee) }}</b>
                    </div>
                    <div>
                        <span>{{ t('Net to Merchant') }}</span>
                        <b class="mono order-detail-positive">{{ money(detailsFor.financial?.net_to_merchant) }}</b>
                    </div>
                </div>

                <section class="order-detail-section">
                    <h4>{{ t('Customer Information') }}</h4>
                    <div class="order-detail-grid">
                        <div><span>{{ t('Customer') }}</span><b>{{ detailsFor.customer?.name || detailsFor.customer_name_ar }}</b></div>
                        <div><span>{{ t('Phone') }}</span><b class="mono">{{ detailsFor.customer?.phone || detailsFor.phone }}</b></div>
                        <div v-if="detailsFor.customer?.phone2"><span>{{ t('Phone 2') }}</span><b class="mono">{{ detailsFor.customer.phone2 }}</b></div>
                        <div class="order-detail-grid-wide"><span>{{ t('Address') }}</span><b>{{ detailsFor.customer?.address || detailsFor.address_ar }}</b></div>
                        <div><span>{{ t('City / Governorate') }}</span><b>{{ provinceName(detailsFor.province) }}</b></div>
                    </div>
                </section>

                <section v-if="hasPickupPoint(detailsFor)" class="order-detail-section pickup-point-section">
                    <h4>{{ t('Merchant pickup location') }}</h4>
                    <div class="pickup-point-card">
                        <span class="pickup-point-icon" aria-hidden="true">⌖</span>
                        <div><b>{{ detailsFor.pickup_location_label || t('Merchant pickup location') }}</b><small class="mono">{{ Number(detailsFor.pickup_latitude).toFixed(6) }}, {{ Number(detailsFor.pickup_longitude).toFixed(6) }}</small></div>
                        <a :href="pickupMapUrl(detailsFor)" target="_blank" rel="noopener noreferrer">{{ t('Open navigation apps') }}</a>
                    </div>
                </section>

                <section class="order-detail-section">
                    <h4>{{ t('Operational Details') }}</h4>
                    <div class="order-detail-grid">
                        <div><span>{{ t('Merchant') }}</span><b>{{ detailsFor.merchant?.shop_name || detailsFor.merchant?.name || detailsFor.tenant || '—' }}</b></div>
                        <div><span>{{ t('Courier') }}</span><b>{{ detailsFor.courier?.name || t('Unassigned') }}</b></div>
                        <div><span>{{ t('Pickup courier') }}</span><b>{{ detailsFor.pickup_courier?.name || detailsFor.courier?.name || t('Not specified') }}</b></div>
                        <div><span>{{ t('Delivery courier') }}</span><b>{{ detailsFor.delivery_courier?.name || detailsFor.courier?.name || t('Not specified') }}</b></div>
                        <div><span>{{ t('Origin / pickup branch') }}</span><b>{{ branchName(detailsFor.origin_branch) }}</b></div>
                        <div><span>{{ t('Destination / delivery branch') }}</span><b>{{ branchName(detailsFor.destination_branch) }}</b></div>
                        <div><span>{{ t('Order Type') }}</span><b>{{ detailsFor.order_type || t('Not specified') }}</b></div>
                        <div><span>{{ t('Vehicle') }}</span><b>{{ detailsFor.delivery_vehicle || t('Not specified') }}</b></div>
                        <div><span>{{ t('Source') }}</span><b>{{ sourceLabel(detailsFor.source) }}</b></div>
                        <div><span>{{ t('Status') }}</span><b>{{ isDeleted(detailsFor) ? t('Deleted') : tStatus(detailsFor.status) }}</b></div>
                        <div><span>{{ t('Created at') }}</span><b>{{ formatDateTime(detailsFor.created_at) }}</b></div>
                        <div><span>{{ t('Last updated') }}</span><b>{{ formatDateTime(detailsFor.updated_at) }}</b></div>
                        <div v-if="detailsFor.pickup_deadline_at" class="order-detail-grid-wide"><span>{{ t('Pickup deadline') }}</span><b>{{ formatDateTime(detailsFor.pickup_deadline_at) }}</b></div>
                    </div>
                    <div v-if="canUpdateOrders && isPickupOverdue(detailsFor)" class="pickup-overdue-notice">
                        <div>
                            <b>{{ t('Pickup deadline has passed') }}</b>
                            <span>{{ t('The courier has not reached the merchant by the agreed deadline. Re-offering returns the reserved budget and opens the order to eligible couriers.') }}</span>
                        </div>
                        <button class="fbtn mini pickup-overdue-action" type="button" :disabled="busyId === detailsFor.id" @click="reofferOverduePickup(detailsFor)">{{ t('Re-offer overdue order') }}</button>
                    </div>
                </section>

                <section class="order-detail-section">
                    <h4>{{ t('Operational Timeline') }}</h4>
                    <div v-if="detailsFor.timeline?.length" class="order-timeline">
                        <div v-for="(event, index) in detailsFor.timeline" :key="`${event.kind}-${event.at}-${index}`" class="order-timeline-item">
                            <div class="order-timeline-rail">
                                <span class="order-timeline-marker" :class="`is-${event.kind}`">{{ event.kind === 'movement' ? '↔' : event.kind === 'assignment' ? '◎' : event.kind === 'created' ? '+' : '✓' }}</span>
                            </div>
                            <div class="order-timeline-copy">
                                <b>{{ timelineTitle(event) }}</b>
                                <p v-if="timelineDescription(event)">{{ timelineDescription(event) }}</p>
                                <time>{{ formatDateTime(event.at) }}</time>
                            </div>
                        </div>
                    </div>
                    <div v-else class="empty-hint">{{ t('No operational activity yet.') }}</div>
                </section>

                <section v-if="detailsFor.notes || detailsFor.vehicle_note" class="order-detail-section">
                    <h4>{{ t('Notes') }}</h4>
                    <p v-if="detailsFor.notes" class="order-detail-note">{{ detailsFor.notes }}</p>
                    <p v-if="detailsFor.vehicle_note" class="order-detail-note"><b>{{ t('Vehicle Note') }}:</b> {{ detailsFor.vehicle_note }}</p>
                </section>

                <button v-if="canUpdateOrders && isDeleted(detailsFor)" class="btn restore-order-action order-detail-restore" type="button" :disabled="busyId === detailsFor.id" @click="restoreOrder(detailsFor)">{{ t('Restore Order') }}</button>
            </div>
        </SheetModal>

        <SheetModal v-if="canUpdateOrders" :open="!!assignFor" :title="t('Assign Courier')" :subtitle="assignFor?.track_no" @close="assignFor = null">
            <div class="field">
                <label>{{ t('Assignment Role') }}</label>
                <select v-model="assignmentRole" @change="assignCourier = ''">
                    <option v-for="mode in assignmentModes" :key="mode.key" :value="mode.key">{{ mode.label }}</option>
                </select>
                <p v-if="assignmentRole !== 'courier'" class="assign-help">{{ t('Specialist assignments are scheduled on the order without changing its status or wallet balance.') }}</p>
            </div>
            <div class="field">
                <label>{{ courierRoleLabel(assignmentRole) }}</label>
                <select v-model="assignCourier">
                    <option value="" disabled>{{ t('Select courier') }}</option>
                    <option v-for="courier in eligibleCouriers" :key="courier.id" :value="courier.id">{{ courier.name }} — {{ courierRoleLabel(courier.role) }} — {{ courier.phone }}</option>
                </select>
                <p v-if="!assignFor?.province_id" class="field-error">{{ t('Cannot assign before the order governorate is set.') }}</p>
                <p v-else-if="!eligibleCouriers.length" class="field-error">{{ t('No active courier is available for this order governorate.') }}</p>
            </div>
            <button class="btn btn-primary" style="width: 100%" :disabled="!assignCourier || busyId" @click="doAssign">
                {{ t('Confirm') }}
            </button>
        </SheetModal>

        <SheetModal v-if="canUpdateOrders" :open="!!branchFor" :title="t('Branch Route')" :subtitle="branchFor?.track_no" @close="branchFor = null">
            <p class="text-muted" style="margin: 0 0 14px; font-size: 11px; line-height: 1.8">{{ t('Choose the branch receiving the order and the branch responsible for delivery. Administration network branches and this merchant’s own branches are shown.') }}</p>
            <div class="field">
                <label>{{ t('Origin / pickup branch') }}</label>
                <select v-model="originBranch">
                    <option value="">{{ t('Not specified') }}</option>
                    <option v-for="branch in eligibleBranches" :key="branch.id" :value="branch.id">{{ branch.name }} — {{ branch.city }}</option>
                </select>
            </div>
            <div class="field">
                <label>{{ t('Destination / delivery branch') }}</label>
                <select v-model="destinationBranch">
                    <option value="">{{ t('Not specified') }}</option>
                    <option v-for="branch in eligibleBranches" :key="branch.id" :value="branch.id">{{ branch.name }} — {{ branch.city }}</option>
                </select>
            </div>
            <p v-if="!eligibleBranches.length" class="field-error">{{ t('No active administration or merchant branches exist for this order yet.') }}</p>
            <button class="btn btn-primary" style="width: 100%" :disabled="(!originBranch && !destinationBranch) || busyId" @click="saveBranches">{{ t('Save Branch Route') }}</button>
        </SheetModal>
    </AdminShell>
</template>

<style scoped>
.orders-query-bar { display: flex; align-items: end; gap: 9px; margin: 0 0 14px; }
.orders-search-field, .orders-courier-filter { display: grid; gap: 5px; min-width: 0; }
.orders-search-field { flex: 1 1 300px; grid-template-columns: auto minmax(0, 1fr); align-items: center; gap: 7px; min-height: 39px; padding-inline: 11px; border: 1px solid var(--border); border-radius: 10px; color: var(--ink-faint); background: var(--surface); }
.orders-search-field input, .orders-courier-filter select { width: 100%; min-width: 0; box-sizing: border-box; border: 0; outline: 0; color: var(--ink); background: transparent; font: 700 11px var(--font); }
.orders-search-field input { min-height: 37px; }
.orders-search-field:focus-within { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-tint); }
.orders-courier-filter { flex: 0 1 270px; }
.orders-courier-filter > span { color: var(--ink-faint); font-size: 9px; font-weight: 850; }
.orders-courier-filter select { min-height: 39px; padding: 0 10px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface); }
.orders-courier-filter select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-tint); }
.orders-search-submit, .orders-clear-filters { min-height: 39px; white-space: nowrap; }
.orders-search-submit { color: #062033; border-color: transparent; background: var(--primary); }
.admin-orders-table-wrap { width: 100%; overflow: auto; overscroll-behavior-inline: contain; }
.admin-orders-table { min-width: 1480px; }
.admin-order-row { cursor: pointer; outline: none; }
.admin-order-row:focus-visible { outline: 2px solid var(--primary); outline-offset: -2px; }
.admin-order-track { color: var(--primary); font-weight: 900; white-space: nowrap; }
.admin-order-address { min-width: 185px; max-width: 230px; color: var(--ink-soft); line-height: 1.55; }
.admin-order-address span, .admin-orders-table td > small { display: block; }
.admin-orders-table small { margin-top: 3px; color: var(--ink-faint); font-size: 10px; font-weight: 700; line-height: 1.45; }
.admin-route-cell { min-width: 155px; line-height: 1.4; }
.admin-route-cell b { display: block; font-size: 11.5px; }
.admin-order-actions-cell { min-width: 230px; }
.admin-order-actions { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.pickup-overdue-action { border-color: color-mix(in srgb, var(--warning) 55%, var(--border)); color: #9a5a00; background: var(--warning-tint); }
.restore-order-action { border-color: color-mix(in srgb, var(--success) 55%, var(--border)); color: var(--success); background: var(--success-tint); }
.order-detail-restore { width: 100%; min-height: 44px; }

.order-detail-sheet { display: grid; gap: 16px; padding-bottom: 4px; }
.order-detail-hero { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 15px; border: 1px solid var(--border); border-radius: 14px; background: var(--surface-2); }
.order-detail-hero > div { min-width: 0; display: grid; gap: 3px; }
.order-detail-kicker, .order-detail-hero > div > span:last-child { color: var(--ink-faint); font-size: 10.5px; font-weight: 800; }
.order-detail-hero b { color: var(--primary); font-size: 17px; font-weight: 900; }
.order-detail-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
.order-detail-summary > div { padding: 12px; border: 1px solid var(--border); border-radius: 12px; background: var(--surface); }
.order-detail-summary span, .order-detail-grid span { display: block; color: var(--ink-faint); font-size: 10px; font-weight: 800; }
.order-detail-summary b { display: block; margin-top: 5px; font-size: 13px; font-weight: 900; }
.order-detail-positive { color: var(--success); }
.order-detail-section { padding: 15px; border: 1px solid var(--border); border-radius: 14px; background: var(--surface); }
.order-detail-section h4 { margin: 0 0 13px; color: var(--ink); font-size: 12.5px; font-weight: 900; }
.order-detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 18px; }
.order-detail-grid > div { min-width: 0; }
.order-detail-grid b { display: block; margin-top: 4px; color: var(--ink); font-size: 11.5px; font-weight: 800; line-height: 1.55; overflow-wrap: anywhere; }
.order-detail-grid-wide { grid-column: 1 / -1; }
.pickup-overdue-notice { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 14px; padding: 11px; border: 1px solid color-mix(in srgb, var(--warning) 50%, var(--border)); border-radius: 11px; background: var(--warning-tint); }
.pickup-overdue-notice > div { display: grid; gap: 3px; }
.pickup-overdue-notice b { color: #8a5100; font-size: 11px; font-weight: 900; }
.pickup-overdue-notice span { color: var(--ink-soft); font-size: 10px; font-weight: 700; line-height: 1.55; }

.order-timeline { display: grid; gap: 0; }
.order-timeline-item { display: grid; grid-template-columns: 32px minmax(0, 1fr); gap: 10px; min-height: 58px; }
.order-timeline-rail { position: relative; display: flex; justify-content: center; }
.order-timeline-item:not(:last-child) .order-timeline-rail::after { position: absolute; top: 27px; bottom: -4px; width: 1px; background: var(--border); content: ''; }
.order-timeline-marker { position: relative; z-index: 1; width: 26px; height: 26px; display: grid; place-items: center; border-radius: 50%; color: var(--primary-strong); background: var(--primary-tint); font-size: 12px; font-weight: 900; }
.order-timeline-marker.is-movement { color: var(--warning); background: var(--warning-tint); }
.order-timeline-marker.is-assignment { color: var(--primary-strong); background: var(--primary-tint); }
.order-timeline-marker.is-status { color: var(--success); background: var(--success-tint); }
.order-timeline-copy { padding: 2px 0 14px; }
.order-timeline-copy b { display: block; color: var(--ink); font-size: 11.5px; font-weight: 900; line-height: 1.45; }
.order-timeline-copy p, .order-timeline-copy time { display: block; margin: 3px 0 0; color: var(--ink-faint); font-size: 10.5px; font-weight: 700; line-height: 1.5; }
.order-detail-note { margin: 0; color: var(--ink-soft); font-size: 11.5px; font-weight: 700; line-height: 1.8; }
.order-detail-note + .order-detail-note { margin-top: 8px; }
.pickup-point-section{border-color:color-mix(in srgb,var(--primary) 32%,var(--border));background:linear-gradient(135deg,color-mix(in srgb,var(--primary-tint) 64%,var(--surface)),var(--surface))}.pickup-point-card{display:flex;align-items:center;gap:10px}.pickup-point-icon{width:35px;height:35px;display:grid;place-items:center;flex:none;border-radius:10px;color:#062033;background:var(--primary);font-size:19px;font-weight:900}.pickup-point-card>div{display:grid;min-width:0;flex:1;gap:3px}.pickup-point-card b{overflow:hidden;margin:0;text-overflow:ellipsis;white-space:nowrap}.pickup-point-card small{color:var(--ink-faint);font-size:9.5px;font-weight:750}.pickup-point-card a{display:inline-flex;align-items:center;justify-content:center;min-height:33px;padding:0 9px;border-radius:8px;color:#062033;background:var(--primary);font-size:9.5px;font-weight:900;text-decoration:none;white-space:nowrap}
.assign-help { margin: 6px 0 0; color: var(--ink-faint); font-size: 10px; font-weight: 700; line-height: 1.55; }

@media (max-width: 620px) {
    .orders-query-bar { align-items: stretch; flex-direction: column; }
    .orders-search-field, .orders-courier-filter { flex-basis: auto; }
    .orders-search-submit, .orders-clear-filters { width: 100%; }
    .order-detail-summary, .order-detail-grid { grid-template-columns: 1fr; }
    .order-detail-grid-wide { grid-column: auto; }
    .order-detail-hero { align-items: flex-start; flex-direction: column; }
    .pickup-overdue-notice { align-items: stretch; flex-direction: column; }
}
</style>
