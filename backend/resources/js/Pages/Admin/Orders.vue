<script setup>
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'
import BranchFilter from '../../Components/BranchFilter.vue'
import StatusBadge from '../../Components/StatusBadge.vue'
import SheetModal from '../../Components/SheetModal.vue'
import { isIraqiMobilePhone, normalizeIraqiMobilePhone } from '../../Utils/iraqiPhone'

const props = defineProps({
    orders: { type: Object, required: true },
    counts: { type: Object, required: true },
    // This is deliberately separate from the paginated rows. It represents
    // every accessible order; monetary totals are present only for the
    // dedicated orders financial-read action.
    summary: { type: Object, default: () => ({}) },
    filter: { type: String, default: 'all' },
    q: { type: String, default: '' },
    courierId: { type: [String, Number], default: '' },
    fromDate: { type: String, default: '' },
    toDate: { type: String, default: '' },
    // The server normalizes this to 25, 50, 100, or "all". Keeping a
    // default here lets older cached dashboard responses continue to work.
    perPage: { type: [String, Number], default: 25 },
    couriers: { type: Array, default: () => [] },
    courierFilters: { type: Array, default: () => [] },
    branchFilter: { type: Object, default: () => ({}) },
    canUpdateOrders: { type: Boolean, default: false },
    canViewOrderFinancialDetails: { type: Boolean, default: false },
    canViewOrderFinancialSummary: { type: Boolean, default: false },
    canEditOrders: { type: Boolean, default: false },
    canChangeOrderStatus: { type: Boolean, default: false },
    canAssignCourier: { type: Boolean, default: false },
    canReofferOverduePickup: { type: Boolean, default: false },
    canRestoreOrders: { type: Boolean, default: false },
    canDeleteOrders: { type: Boolean, default: false },
})

const query = ref(props.q)
const active = ref(props.filter)
const courierFilter = ref(normalizeCourierId(props.courierId))
const fromDate = ref(normalizeDate(props.fromDate))
const toDate = ref(normalizeDate(props.toDate))
const displayCount = ref(normalizePerPage(props.perPage))
const assignFor = ref(null)
const assignCourier = ref('')
const detailsFor = ref(null)
const detailsLoading = ref(false)
const detailsError = ref('')
const busyId = ref(null)
const editFor = ref(null)
const deleteFor = ref(null)
const editError = ref('')
const deleteError = ref('')
const editData = ref({})
const courierDirectory = ref([...(props.courierFilters || [])])
const assignmentCouriers = ref([...(props.couriers || [])])
const courierDirectoryLoading = ref(false)
const assignmentDirectoryLoading = ref(false)
const assignmentDirectoryError = ref('')
let detailRequestId = 0
let assignmentRequestId = 0

const eligibleCouriers = computed(() => {
    if (!assignFor.value?.province_id) return []

    return assignmentCouriers.value.filter((courier) =>
        courier.role === 'courier'
        && (courier.provinces || []).some((province) => Number(province.id) === Number(assignFor.value.province_id))
    )
})

const filters = computed(() => {
    const list = [{ key: 'all', label: t('Total Orders') }]
    // Keep historical exception states readable in order details and audit
    // data, but do not surface rejected, cancelled, or damaged work as
    // dashboard cards. The cards stay focused on the live flow.
    for (const status of ['pending', 'approved', 'courier', 'delivered', 'returned', 'late']) {
        list.push({ key: status, label: tStatus(status) })
    }
    list.push({ key: 'deleted', label: t('Deleted') })
    return list
})

const summaryCards = computed(() => filters.value.map((filterOption) => ({
    ...filterOption,
    ...summaryFor(filterOption.key),
})))

const sortedCouriers = computed(() => [...courierDirectory.value]
    .sort((first, second) => String(first.name || '').localeCompare(String(second.name || ''), document.documentElement.lang || 'ar')))

const hasActiveQuery = computed(() => (
    active.value !== 'all'
    || Boolean(String(query.value || '').trim())
    || Boolean(courierFilter.value)
    || Boolean(fromDate.value)
    || Boolean(toDate.value)
    || Boolean(selectedBranchFilterId())
))

// The active operational flow is deliberately limited to the five states
// shown in the mobile application. Legacy cancellation/damage/rejection
// records remain readable in the audit history, but cannot be created again
// from the dashboard.
const statusOptions = ['pending', 'approved', 'courier', 'delivered', 'returned']

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

function normalizePerPage(value) {
    const normalized = String(value ?? '').trim().toLowerCase()

    if (normalized === 'all') return 'all'
    return ['25', '50', '100'].includes(normalized) ? normalized : '25'
}

function normalizeDate(value) {
    const date = String(value || '').trim()
    return /^\d{4}-\d{2}-\d{2}$/.test(date) ? date : ''
}

function selectedBranchFilterId() {
    const value = String(props.branchFilter?.selected_id ?? '').trim()
    return /^\d+$/.test(value) ? value : ''
}

function branchFilterParams() {
    const branchId = selectedBranchFilterId()
    return props.branchFilter?.enabled && branchId ? { branch_id: branchId } : {}
}

function orderQuery(page = null) {
    const params = {
        filter: active.value,
        per_page: normalizePerPage(displayCount.value),
        ...branchFilterParams(),
    }
    const search = String(query.value || '').trim()
    const courierId = normalizeCourierId(courierFilter.value)

    if (search) params.q = search
    if (courierId) params.courier_id = courierId
    if (fromDate.value) params.from = fromDate.value
    if (toDate.value) params.to = toDate.value
    if (page) params.page = page

    return params
}

function numberOrNull(value) {
    if (value === null || value === undefined || value === '') return null

    const number = Number(value)
    return Number.isFinite(number) ? number : null
}

function summaryFor(key) {
    const summary = props.summary && typeof props.summary === 'object' ? props.summary : {}
    const byStatus = summary.statuses || summary.by_status || {}
    const entry = key === 'all'
        ? (summary.all || summary.total || summary)
        : (summary[key] || byStatus[key] || {})

    // `summary.*.amount` is the gross total (order price + delivery fee) for
    // the dedicated financial-reader capability. Count-only summaries remain
    // valid for operational roles.
    const count = numberOrNull(
        entry?.count
        ?? entry?.orders
        ?? summary[`${key}_count`]
        ?? props.counts?.[key]
    )
    const amount = numberOrNull(
        entry?.amount
        ?? entry?.gross_amount
        ?? entry?.total_amount
        ?? summary[`${key}_amount`]
        ?? summary[`${key}_gross_amount`]
    )

    return {
        count: count ?? Number(props.counts?.[key] || 0),
        amount,
    }
}

function apply() {
    router.get(route('admin.orders'), orderQuery(), { preserveState: true, replace: true })
}

function changeBranchFilter(branchId) {
    const params = orderQuery()
    if (branchId) params.branch_id = branchId
    else delete params.branch_id

    router.get(route('admin.orders'), params, { preserveState: true, replace: true })
}

function filterByStatus(status) {
    active.value = status
    apply()
}

function clearFilters() {
    active.value = 'all'
    query.value = ''
    courierFilter.value = ''
    fromDate.value = ''
    toDate.value = ''
    const params = orderQuery()
    delete params.branch_id
    router.get(route('admin.orders'), params, { preserveState: true, replace: true })
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

watch(() => props.fromDate, (value) => {
    fromDate.value = normalizeDate(value)
})

watch(() => props.toDate, (value) => {
    toDate.value = normalizeDate(value)
})

watch(() => props.perPage, (value) => {
    displayCount.value = normalizePerPage(value)
})

watch(() => props.courierFilters, (value) => {
    if (Array.isArray(value) && value.length) courierDirectory.value = [...value]
})

watch(() => props.couriers, (value) => {
    assignmentCouriers.value = Array.isArray(value) ? [...value] : []
})

function ordersRequestUrl(parameters = {}) {
    const url = new URL(route('admin.orders'), window.location.origin)

    Object.entries({ ...branchFilterParams(), ...parameters }).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') url.searchParams.set(key, String(value))
    })

    return url.toString()
}

async function readOrdersJson(parameters = {}) {
    const response = await fetch(ordersRequestUrl(parameters), {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    })

    if (!response.ok) throw new Error(`Order request failed with ${response.status}`)

    return response.json()
}

async function loadCourierDirectory() {
    if (!props.canAssignCourier || courierDirectoryLoading.value || courierDirectory.value.length) return

    courierDirectoryLoading.value = true
    try {
        const payload = await readOrdersJson({ directory: 'courier_filters' })
        courierDirectory.value = Array.isArray(payload.courierFilters) ? payload.courierFilters : []
    } finally {
        courierDirectoryLoading.value = false
    }
}

async function loadAssignmentDirectory(order) {
    if (!props.canAssignCourier || !order?.id || !canOperateOrder(order)) return

    const requestId = ++assignmentRequestId
    assignmentDirectoryLoading.value = true
    assignmentDirectoryError.value = ''
    assignmentCouriers.value = []

    try {
        const payload = await readOrdersJson({ directory: 'assignment', assignment_for: order.id })
        if (requestId !== assignmentRequestId) return

        assignmentCouriers.value = props.canAssignCourier && Array.isArray(payload.couriers)
            ? payload.couriers
            : []
    } catch {
        if (requestId === assignmentRequestId) assignmentDirectoryError.value = t('Unable to load assignment options. Please retry.')
    } finally {
        if (requestId === assignmentRequestId) assignmentDirectoryLoading.value = false
    }
}

function setStatus(order, status) {
    if (!props.canChangeOrderStatus || !canOperateOrder(order) || busyId.value) return

    if (!window.confirm(`${t('Confirm')}: ${tStatus(status)}?`)) return

    // Normal lifecycle moves do not need an explanation.  A correction (for
    // example, changing a delivered order back to pending) is deliberately
    // auditable on the server, so ask the operator for the required note.
    const normalMoves = {
        pending: ['approved'],
        approved: ['courier'],
        courier: ['delivered', 'returned'],
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
    if (!props.canReofferOverduePickup || !canOperateOrder(order) || busyId.value || !isPickupOverdue(order)) return

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

async function openAssign(order) {
    if (!props.canAssignCourier || !canOperateOrder(order)) return
    assignFor.value = order
    assignCourier.value = ''
    await loadAssignmentDirectory(order)
}

function doAssign() {
    if (!props.canAssignCourier || !assignCourier.value || !assignFor.value || !canOperateOrder(assignFor.value)) return

    busyId.value = assignFor.value.id
    router.post(
        route('admin.orders.courier', assignFor.value.id),
        { courier_id: assignCourier.value, assignment_role: 'courier' },
        {
            preserveScroll: true,
            onSuccess: () => (assignFor.value = null),
            onFinish: () => (busyId.value = null),
        }
    )
}

async function openDetails(order) {
    const requestId = ++detailRequestId
    detailsFor.value = order
    detailsLoading.value = true
    detailsError.value = ''

    try {
        const payload = await readOrdersJson({ detail: order.id })
        if (requestId !== detailRequestId || !detailsFor.value || Number(detailsFor.value.id) !== Number(order.id)) return

        detailsFor.value = payload.order || null
        if (!detailsFor.value) detailsError.value = t('Unable to load order details. Please retry.')
    } catch {
        if (requestId === detailRequestId && detailsFor.value && Number(detailsFor.value.id) === Number(order.id)) {
            detailsError.value = t('Unable to load order details. Please retry.')
        }
    } finally {
        if (requestId === detailRequestId) detailsLoading.value = false
    }
}

function closeDetails() {
    detailRequestId += 1
    detailsFor.value = null
    detailsLoading.value = false
    detailsError.value = ''
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
        // Historic movement events can still carry either legacy role. Do
        // not surface a split operational model in the current dashboard.
        pickup_courier: 'Courier',
        delivery_courier: 'Courier',
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

// A transfer endpoint can appear in the read list, but the server marks it
// non-operational until custody reaches this branch. Keep the controls in
// sync with that server-owned boundary; the backend repeats it on every
// write, so an old cached page cannot bypass it.
function canOperateOrder(order) {
    return order?.can_operate !== false
}

function restoreOrder(order) {
    if (!props.canRestoreOrders || !canOperateOrder(order) || !isDeleted(order) || busyId.value) return
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

async function openEdit(order) {
    if (!props.canEditOrders || !canOperateOrder(order) || isDeleted(order)) return
    editError.value = ''
    let editableOrder = order

    // Table rows intentionally omit notes and vehicle data for performance.
    // Load the single full record before editing so a no-op save can never
    // clear values that were not part of the list response.
    try {
        const payload = await readOrdersJson({ detail: order.id })
        editableOrder = payload.order || order
    } catch {
        editError.value = t('Unable to load complete order data. Please retry.')
        window.alert(editError.value)
        return
    }

    editFor.value = editableOrder
    editData.value = {
        customer_name_ar: editableOrder.customer?.name || editableOrder.customer_name_ar || '',
        phone: editableOrder.customer?.phone || editableOrder.phone || '',
        phone2: editableOrder.customer?.phone2 || '',
        address_ar: editableOrder.customer?.address || editableOrder.address_ar || '',
        order_type: editableOrder.order_type || '',
        delivery_vehicle: editableOrder.delivery_vehicle || 'normal',
        vehicle_note: editableOrder.vehicle_note || '',
        price: editableOrder.financial?.order_value ?? editableOrder.price ?? '',
        notes: editableOrder.notes || '',
    }
}

function normalizeEditPhone(field) {
    editData.value = {
        ...editData.value,
        [field]: normalizeIraqiMobilePhone(editData.value[field]),
    }
    editError.value = ''
}

function saveEdit() {
    if (!props.canEditOrders || !editFor.value || !canOperateOrder(editFor.value) || busyId.value) return

    const order = editFor.value
    const phone = normalizeIraqiMobilePhone(editData.value.phone)
    const phone2 = normalizeIraqiMobilePhone(editData.value.phone2)
    editData.value = { ...editData.value, phone, phone2 }

    if (!isIraqiMobilePhone(phone) || (phone2 && !isIraqiMobilePhone(phone2))) {
        editError.value = t('The phone number must be exactly 11 digits and start with 077 or 078.')
        return
    }

    busyId.value = order.id
    editError.value = ''
    router.put(route('admin.orders.update', order.id), editData.value, {
        preserveScroll: true,
        onSuccess: () => {
            editFor.value = null
            if (detailsFor.value?.id === order.id) closeDetails()
        },
        onError: (errors) => {
            editError.value = Object.values(errors)[0] || t('Unable to update order.')
        },
        onFinish: () => (busyId.value = null),
    })
}

function requestDelete(order) {
    if (!props.canDeleteOrders || !canOperateOrder(order) || isDeleted(order)) return
    deleteError.value = ''
    deleteFor.value = order
}

function destroyOrder() {
    if (!props.canDeleteOrders || !deleteFor.value || !canOperateOrder(deleteFor.value) || busyId.value) return

    const order = deleteFor.value
    busyId.value = order.id
    router.delete(route('admin.orders.destroy', order.id), {
        preserveScroll: true,
        onSuccess: () => {
            deleteFor.value = null
            if (detailsFor.value?.id === order.id) closeDetails()
        },
        onError: (errors) => {
            deleteError.value = errors.order
                || errors.message
                || Object.values(errors)[0]
                || t('Unable to delete order.')
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
        <section class="orders-summary-grid" :aria-label="t('Orders')">
            <button
                v-for="card in summaryCards"
                :key="card.key"
                class="orders-summary-card"
                :class="[`summary-status-${card.key}`, { 'is-active': active === card.key }]"
                type="button"
                :aria-pressed="active === card.key"
                @click="filterByStatus(card.key)"
            >
                <span class="orders-summary-card-head">
                    <span>{{ card.label }}</span>
                    <b class="mono">{{ card.count }}</b>
                </span>
                <span class="orders-summary-card-count">{{ t('Orders') }}</span>
                <span v-if="canViewOrderFinancialSummary" class="orders-summary-card-amount">
                    <small>{{ t('Total Amount') }}</small>
                    <b class="mono">{{ money(card.amount) }}</b>
                </span>
            </button>
        </section>

        <form class="orders-query-bar" @submit.prevent="apply">
            <BranchFilter :filter="branchFilter" @change="changeBranchFilter" />

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
                <PopupSelect
                    v-model="courierFilter"
                    :aria-label="t('Select courier')"
                    searchable
                    search-placeholder="ابحث باسم المندوب أو رقم الهاتف"
                    @focus="loadCourierDirectory"
                    @change="apply"
                >
                    <option value="">{{ t('All Couriers') }}</option>
                    <option v-if="courierDirectoryLoading" value="" disabled>{{ t('Loading...') }}</option>
                    <option v-for="courier in sortedCouriers" :key="courier.id" :value="String(courier.id)">
                        {{ courier.name }}{{ courier.phone ? ` — ${courier.phone}` : '' }}
                    </option>
                </PopupSelect>
            </label>

            <label class="orders-date-filter">
                <span>{{ t('From') }}</span>
                <input v-model="fromDate" type="date" :max="toDate || undefined" @change="apply" />
            </label>

            <label class="orders-date-filter">
                <span>{{ t('To') }}</span>
                <input v-model="toDate" type="date" :min="fromDate || undefined" @change="apply" />
            </label>

            <label class="orders-display-count">
                <span>{{ t('Show') }}</span>
                <PopupSelect v-model="displayCount" :aria-label="`${t('Show')} ${t('Orders')}`" @change="apply">
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="all">{{ t('All') }}</option>
                </PopupSelect>
            </label>

            <button class="fbtn orders-search-submit" type="submit">{{ t('Search') }}</button>
            <button v-if="hasActiveQuery" class="fbtn orders-clear-filters" type="button" @click="clearFilters">{{ t('Clear filters') }}</button>
        </form>

        <div class="panel">
            <div class="panel-body" style="padding: 0">
                <div class="admin-orders-list">
                    <article
                        v-for="order in orders.data"
                        :key="order.id"
                        class="admin-order-card"
                        :class="`order-card-status-${isDeleted(order) ? 'deleted' : order.status}`"
                        tabindex="0"
                        @click="openDetails(order)"
                        @keydown.enter.self="openDetails(order)"
                    >
                        <header class="admin-order-card-head">
                            <div class="admin-order-card-ident">
                                <span>{{ t('Order') }}</span>
                                <b class="mono admin-order-track">{{ order.track_no }}</b>
                            </div>
                            <StatusBadge :status="isDeleted(order) ? 'deleted' : order.status" />
                        </header>

                        <div class="admin-order-card-details">
                            <div v-if="canViewOrderFinancialDetails">
                                <span>{{ t('Customer') }}</span>
                                <b>{{ order.customer?.name || order.customer_name_ar }}</b>
                            </div>
                            <div>
                                <span>{{ t('Merchant') }}</span>
                                <b>{{ order.merchant?.shop_name || order.merchant?.name || order.tenant || '—' }}</b>
                            </div>
                            <div>
                                <span>{{ t('Price') }}</span>
                                <b class="mono">{{ money(order.financial?.order_value ?? order.price) }}</b>
                            </div>
                            <div>
                                <span>{{ t('Courier') }}</span>
                                <b>{{ order.courier?.name || t('Unassigned') }}</b>
                            </div>
                            <div>
                                <span>{{ t('Date') }}</span>
                                <b class="mono">{{ formatDate(order.date) }}</b>
                            </div>
                        </div>

                        <footer class="admin-order-card-footer" @click.stop>
                            <div class="admin-order-actions-head">
                                <span class="admin-order-actions-title">إجراءات الطلب</span>
                                <PopupSelect
                                    v-if="!isDeleted(order) && canChangeOrderStatus && canOperateOrder(order)"
                                    class="fbtn mini admin-order-status-select"
                                    :model-value="order.status"
                                    :aria-label="`تحديث الحالة: ${tStatus(order.status)}`"
                                    :disabled="busyId === order.id"
                                    @change="setStatus(order, $event.target.value)"
                                >
                                    <option v-for="status in statusOptions" :key="status" :value="status">{{ tStatus(status) }}</option>
                                </PopupSelect>
                                <button v-if="isDeleted(order) && canRestoreOrders && canOperateOrder(order)" class="fbtn mini restore-order-action" type="button" :disabled="busyId === order.id" @click="restoreOrder(order)">{{ t('Restore Order') }}</button>
                                <button v-if="!isDeleted(order) && canAssignCourier && canOperateOrder(order) && !isTerminalStatus(order.status)" class="fbtn mini" type="button" :disabled="busyId === order.id" @click="openAssign(order)">{{ t('Assign Courier') }}</button>
                                <button v-if="canReofferOverduePickup && canOperateOrder(order) && isPickupOverdue(order)" class="fbtn mini pickup-overdue-action" type="button" :disabled="busyId === order.id" @click="reofferOverduePickup(order)">{{ t('Re-offer overdue order') }}</button>
                                <button v-if="!isDeleted(order) && canEditOrders && canOperateOrder(order)" class="fbtn mini" type="button" :disabled="busyId === order.id" @click="openEdit(order)">{{ t('Edit') }}</button>
                                <button v-if="!isDeleted(order) && canDeleteOrders && canOperateOrder(order)" class="fbtn mini order-delete-action" type="button" :disabled="busyId === order.id" @click="requestDelete(order)">{{ t('Delete') }}</button>
                                <button class="fbtn mini admin-view-details" type="button" @click="openDetails(order)">عرض التفاصيل</button>
                            </div>
                        </footer>
                    </article>
                </div>
                <div v-if="!orders.data.length" class="empty">{{ t('No orders found') }}</div>
            </div>
        </div>

        <div v-if="orders.last_page > 1" class="filter-bar">
            <button class="fbtn" :disabled="!orders.prev_page_url" @click="goToPage(orders.current_page - 1)">←</button>
            <span class="fbtn" style="cursor: default">{{ orders.current_page }} / {{ orders.last_page }}</span>
            <button class="fbtn" :disabled="!orders.next_page_url" @click="goToPage(orders.current_page + 1)">→</button>
        </div>

        <SheetModal :open="!!detailsFor" :title="t('Order Details')" :subtitle="detailsFor?.track_no" :wide="true" centered @close="closeDetails">
            <section v-if="detailsLoading" class="order-detail-loading">
                <span class="loader"></span>
                <b>{{ t('Loading...') }}</b>
            </section>
            <section v-else-if="detailsError" class="order-detail-loading">
                <b>{{ detailsError }}</b>
                <button class="fbtn" type="button" @click="openDetails(detailsFor)">{{ t('Retry') }}</button>
                <button class="fbtn" type="button" @click="closeDetails">{{ t('Close') }}</button>
            </section>
            <div v-else-if="detailsFor" class="order-detail-sheet">
                <div class="order-detail-hero">
                    <div>
                        <span class="order-detail-kicker">{{ t('Order') }}</span>
                        <b class="mono">{{ detailsFor.track_no }}</b>
                        <span>{{ formatDateTime(detailsFor.created_at) }}</span>
                    </div>
                    <StatusBadge :status="isDeleted(detailsFor) ? 'deleted' : detailsFor.status" />
                </div>

                <div v-if="canViewOrderFinancialDetails" class="order-detail-summary">
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

                <section class="order-detail-section order-detail-operational-section">
                    <h4>{{ t('Operational Details') }}</h4>
                    <div class="order-detail-grid">
                        <div><span>{{ t('Merchant') }}</span><b>{{ detailsFor.merchant?.shop_name || detailsFor.merchant?.name || detailsFor.tenant || '—' }}</b></div>
                        <div><span>{{ t('Courier') }}</span><b>{{ detailsFor.courier?.name || t('Unassigned') }}</b></div>
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
                    <div v-if="canReofferOverduePickup && canOperateOrder(detailsFor) && isPickupOverdue(detailsFor)" class="pickup-overdue-notice">
                        <div>
                            <b>{{ t('Pickup deadline has passed') }}</b>
                            <span>{{ t('The courier has not reached the merchant by the agreed deadline. Re-offering returns the reserved budget and opens the order to eligible couriers.') }}</span>
                        </div>
                        <button class="fbtn mini pickup-overdue-action" type="button" :disabled="busyId === detailsFor.id" @click="reofferOverduePickup(detailsFor)">{{ t('Re-offer overdue order') }}</button>
                    </div>
                </section>

                <section class="order-detail-section order-detail-timeline-section">
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

                <section v-if="detailsFor.notes || detailsFor.vehicle_note || detailsFor.return_reason" class="order-detail-section">
                    <h4>{{ t('Notes') }}</h4>
                    <p v-if="detailsFor.notes" class="order-detail-note">{{ detailsFor.notes }}</p>
                    <p v-if="detailsFor.vehicle_note" class="order-detail-note"><b>{{ t('Vehicle Note') }}:</b> {{ detailsFor.vehicle_note }}</p>
                    <p v-if="detailsFor.return_reason" class="order-detail-note"><b>{{ t('Return Reason') }}:</b> {{ detailsFor.return_reason }}</p>
                </section>

                <section v-if="canOperateOrder(detailsFor)" class="order-detail-section order-detail-actions-panel">
                    <h4>إجراءات الطلب</h4>
                    <div class="detail-order-actions">
                        <PopupSelect
                            v-if="!isDeleted(detailsFor) && canChangeOrderStatus"
                            class="fbtn mini admin-order-status-select"
                            :model-value="detailsFor.status"
                            :aria-label="`${t('Status')}: ${tStatus(detailsFor.status)}`"
                            :disabled="busyId === detailsFor.id"
                            @change="setStatus(detailsFor, $event.target.value)"
                        >
                            <option v-for="status in statusOptions" :key="status" :value="status">{{ tStatus(status) }}</option>
                        </PopupSelect>
                        <button v-if="isDeleted(detailsFor) && canRestoreOrders" class="fbtn mini restore-order-action" type="button" :disabled="busyId === detailsFor.id" @click="restoreOrder(detailsFor)">{{ t('Restore Order') }}</button>
                        <button v-if="!isDeleted(detailsFor) && canAssignCourier && !isTerminalStatus(detailsFor.status)" class="fbtn mini" type="button" @click="openAssign(detailsFor)">{{ t('Assign Courier') }}</button>
                        <button v-if="!isDeleted(detailsFor) && canEditOrders" class="fbtn mini" type="button" @click="openEdit(detailsFor)">{{ t('Edit') }}</button>
                        <button v-if="!isDeleted(detailsFor) && canDeleteOrders" class="fbtn mini order-delete-action" type="button" @click="requestDelete(detailsFor)">{{ t('Delete') }}</button>
                    </div>
                </section>

            </div>
        </SheetModal>

        <SheetModal v-if="canAssignCourier" :open="!!assignFor" :title="t('Assign Courier')" :subtitle="assignFor?.track_no" @close="assignFor = null">
            <div class="field">
                <label>{{ t('Courier') }}</label>
                <PopupSelect v-model="assignCourier" :disabled="assignmentDirectoryLoading">
                    <option value="" disabled>{{ t('Select courier') }}</option>
                    <option v-if="assignmentDirectoryLoading" value="" disabled>{{ t('Loading...') }}</option>
                    <option v-for="courier in eligibleCouriers" :key="courier.id" :value="courier.id">{{ courier.name }} — {{ courierRoleLabel(courier.role) }} — {{ courier.phone }}</option>
                </PopupSelect>
                <p v-if="!assignFor?.province_id" class="field-error">{{ t('Cannot assign before the order governorate is set.') }}</p>
                <p v-else-if="assignmentDirectoryError" class="field-error">{{ assignmentDirectoryError }}</p>
                <p v-else-if="!assignmentDirectoryLoading && !eligibleCouriers.length" class="field-error">{{ t('No active courier is available for this order governorate.') }}</p>
            </div>
            <button class="btn btn-primary" style="width: 100%" :disabled="!assignCourier || busyId || assignmentDirectoryLoading" @click="doAssign">
                {{ t('Confirm') }}
            </button>
        </SheetModal>

        <SheetModal v-if="canEditOrders" :open="!!editFor" :title="t('Edit Order')" :subtitle="editFor?.track_no" @close="editFor = null">
            <form class="order-edit-form" @submit.prevent="saveEdit">
                <div class="order-edit-grid">
                    <label>{{ t('Customer') }}<input v-model="editData.customer_name_ar" required maxlength="120" /></label>
                    <label>{{ t('Phone') }}<input v-model="editData.phone" required type="tel" inputmode="numeric" autocomplete="tel" minlength="11" maxlength="11" pattern="(?:077|078)[0-9]{8}" dir="ltr" placeholder="077xxxxxxxx" @input="normalizeEditPhone('phone')" /></label>
                    <label>{{ t('Phone 2') }}<input v-model="editData.phone2" type="tel" inputmode="numeric" autocomplete="tel" minlength="11" maxlength="11" pattern="(?:077|078)[0-9]{8}" dir="ltr" placeholder="077xxxxxxxx" @input="normalizeEditPhone('phone2')" /></label>
                    <label>{{ t('Order Type') }}<input v-model="editData.order_type" maxlength="60" /></label>
                    <label class="order-edit-wide">{{ t('Address') }}<textarea v-model="editData.address_ar" required rows="2" maxlength="255" /></label>
                    <label>{{ t('Vehicle') }}
                        <PopupSelect v-model="editData.delivery_vehicle" required>
                            <option value="normal">{{ t('Normal') }}</option><option value="bike">{{ t('Bike') }}</option><option value="sedan">{{ t('Sedan') }}</option><option value="suv">{{ t('SUV') }}</option><option value="truck">{{ t('Truck') }}</option>
                        </PopupSelect>
                    </label>
                    <label>{{ t('Price') }}<input v-model="editData.price" required type="number" min="1" inputmode="numeric" /></label>
                    <label class="order-edit-wide">{{ t('Vehicle Note') }}<input v-model="editData.vehicle_note" maxlength="255" /></label>
                    <label class="order-edit-wide">{{ t('Notes') }}<textarea v-model="editData.notes" rows="2" maxlength="255" /></label>
                </div>
                <p v-if="editError" class="field-error">{{ editError }}</p>
                <button class="btn btn-primary" style="width:100%" type="submit" :disabled="busyId === editFor?.id">{{ t('Save Changes') }}</button>
            </form>
        </SheetModal>

        <SheetModal v-if="canDeleteOrders" :open="!!deleteFor" :title="t('Delete Order')" :subtitle="deleteFor?.track_no" @close="deleteFor = null">
            <div class="order-delete-confirm">
                <p>{{ t('This will move the order to deleted orders, where it can be restored. Only pending orders with no courier or financial movement can be deleted.') }}</p>
                <p v-if="deleteError" class="field-error" role="alert">{{ deleteError }}</p>
                <div><button class="fbtn" type="button" @click="deleteFor = null">{{ t('Cancel') }}</button><button class="btn order-delete-button" type="button" :disabled="busyId === deleteFor?.id" @click="destroyOrder">{{ t('Delete') }}</button></div>
            </div>
        </SheetModal>
    </AdminShell>
</template>

<style scoped>
.orders-summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; margin: 0 0 14px; }
.orders-summary-card { --summary-color: var(--primary); --summary-tint: var(--primary-tint); display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 2px 10px; min-width: 0; padding: 12px; border: 1px solid color-mix(in srgb, var(--summary-color) 28%, var(--border)); border-radius: 13px; color: var(--ink); background: linear-gradient(135deg, color-mix(in srgb, var(--summary-tint) 74%, var(--surface)), var(--surface)); box-shadow: 0 4px 13px rgba(21, 66, 73, .035); cursor: pointer; font: inherit; text-align: start; transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease; }
.orders-summary-card:hover { border-color: color-mix(in srgb, var(--summary-color) 58%, var(--border)); box-shadow: 0 8px 18px color-mix(in srgb, var(--summary-color) 12%, transparent); transform: translateY(-1px); }
.orders-summary-card:focus-visible { outline: 2px solid var(--summary-color); outline-offset: 2px; }
.orders-summary-card.is-active { border-color: var(--summary-color); box-shadow: 0 0 0 3px color-mix(in srgb, var(--summary-color) 15%, transparent), 0 8px 18px color-mix(in srgb, var(--summary-color) 13%, transparent); }
.orders-summary-card-head { display: contents; }
.orders-summary-card-head > span { overflow: hidden; color: var(--ink-soft); font-size: 10px; font-weight: 900; line-height: 1.4; text-overflow: ellipsis; white-space: nowrap; }
.orders-summary-card-head > b { color: var(--summary-color); font-size: 19px; font-weight: 950; line-height: 1.1; }
.orders-summary-card-count { grid-column: 1 / -1; color: var(--ink-faint); font-size: 8.5px; font-weight: 800; }
.orders-summary-card-amount { grid-column: 1 / -1; display: flex; align-items: baseline; justify-content: space-between; gap: 9px; margin-top: 7px; padding-top: 8px; border-top: 1px solid color-mix(in srgb, var(--summary-color) 18%, var(--border)); }
.orders-summary-card-amount small { color: var(--ink-faint); font-size: 8.5px; font-weight: 800; }
.orders-summary-card-amount b { overflow: hidden; color: var(--ink); font-size: 10.5px; font-weight: 900; text-overflow: ellipsis; white-space: nowrap; }
.summary-status-pending { --summary-color: var(--st-pending); --summary-tint: var(--st-pending-tint); }
.summary-status-approved { --summary-color: var(--st-approved); --summary-tint: var(--st-approved-tint); }
.summary-status-courier { --summary-color: var(--st-courier); --summary-tint: var(--st-courier-tint); }
.summary-status-delivered { --summary-color: var(--st-delivered); --summary-tint: var(--st-delivered-tint); }
.summary-status-returned, .summary-status-cancelled, .summary-status-damaged, .summary-status-rejected, .summary-status-deleted { --summary-color: var(--danger); --summary-tint: var(--danger-tint); }
.summary-status-late { --summary-color: var(--warning); --summary-tint: var(--warning-tint); }
.orders-query-bar { display: flex; align-items: end; gap: 9px; margin: 0 0 14px; }
.orders-query-bar :deep(.branch-filter) { flex: 0 1 245px; }
.orders-search-field, .orders-courier-filter, .orders-date-filter, .orders-display-count { display: grid; gap: 5px; min-width: 0; }
.orders-search-field { flex: 1 1 300px; grid-template-columns: auto minmax(0, 1fr); align-items: center; gap: 7px; min-height: 39px; padding-inline: 11px; border: 1px solid var(--border); border-radius: 10px; color: var(--ink-faint); background: var(--surface); }
.orders-search-field input, .orders-courier-filter select, .orders-date-filter input, .orders-display-count select { width: 100%; min-width: 0; box-sizing: border-box; border: 0; outline: 0; color: var(--ink); background: transparent; font: 700 11px var(--font); }
.orders-search-field input { min-height: 37px; }
.orders-search-field:focus-within { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-tint); }
.orders-courier-filter { flex: 0 1 270px; }
.orders-date-filter { flex: 0 1 140px; }
.orders-display-count { flex: 0 0 82px; }
.orders-courier-filter > span, .orders-date-filter > span, .orders-display-count > span { color: var(--ink-faint); font-size: 9px; font-weight: 850; }
.orders-courier-filter select, .orders-date-filter input, .orders-display-count select { min-height: 39px; padding: 0 10px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface); }
.orders-courier-filter select:focus, .orders-date-filter input:focus, .orders-display-count select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-tint); }
.orders-search-submit, .orders-clear-filters { min-height: 39px; white-space: nowrap; }
.orders-search-submit { color: #062033; border-color: transparent; background: var(--primary); }
.admin-orders-list { display: grid; gap: 12px; padding: 12px; background: linear-gradient(180deg, var(--surface-2), var(--surface)); }
.admin-order-card { --order-card-accent: var(--primary); --order-card-tint: var(--primary-tint); position: relative; min-width: 0; padding: 9px 11px; border: 1px solid color-mix(in srgb, var(--order-card-accent) 32%, var(--border)); border-inline-start: 5px solid var(--order-card-accent); border-radius: 12px; background: linear-gradient(135deg, color-mix(in srgb, var(--order-card-tint) 62%, var(--surface)), var(--surface) 52%); box-shadow: 0 5px 14px rgba(21, 66, 73, .06); cursor: pointer; outline: none; transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease; }
.admin-order-card:hover { border-color: var(--order-card-accent); box-shadow: 0 10px 22px color-mix(in srgb, var(--order-card-accent) 15%, transparent); transform: translateY(-1px); }
.admin-order-card:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.order-card-status-pending { --order-card-accent: var(--st-pending); --order-card-tint: var(--st-pending-tint); }
.order-card-status-approved { --order-card-accent: var(--st-approved); --order-card-tint: var(--st-approved-tint); }
.order-card-status-courier { --order-card-accent: var(--st-courier); --order-card-tint: var(--st-courier-tint); }
.order-card-status-delivered { --order-card-accent: var(--st-delivered); --order-card-tint: var(--st-delivered-tint); }
.order-card-status-returned, .order-card-status-deleted, .order-card-status-cancelled, .order-card-status-damaged, .order-card-status-rejected { --order-card-accent: var(--danger); --order-card-tint: var(--danger-tint); }
.order-card-status-late { --order-card-accent: var(--warning); --order-card-tint: var(--warning-tint); }
.admin-order-card-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.admin-order-card-ident { display: flex; align-items: baseline; min-width: 0; gap: 5px; }
.admin-order-card-ident > span, .admin-order-card-details > div > span { color: var(--ink-faint); font-size: 9px; font-weight: 850; }
.admin-order-track { color: var(--primary); font-weight: 900; white-space: nowrap; }
.admin-order-card-details { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 6px; margin-top: 7px; padding: 7px 8px; border: 1px solid color-mix(in srgb, var(--order-card-accent) 18%, var(--border)); border-radius: 9px; background: color-mix(in srgb, var(--order-card-tint) 48%, var(--surface)); }
.admin-order-card-details > div { display: grid; min-width: 0; gap: 2px; padding-inline: 7px; border-inline-start: 1px solid color-mix(in srgb, var(--order-card-accent) 17%, var(--border)); }
.admin-order-card-details > div:first-child { padding-inline-start: 0; border-inline-start: 0; }
.admin-order-card-details > div:last-child { padding-inline-end: 0; }
.admin-order-card-details b { overflow: hidden; color: var(--ink); font-size: 11px; font-weight: 850; line-height: 1.4; text-overflow: ellipsis; white-space: nowrap; }
.admin-order-card-details > div:nth-child(3) b { color: var(--order-card-accent); }
.admin-order-card-footer { display: block; margin-top: 7px; padding-top: 7px; border-top: 1px dashed color-mix(in srgb, var(--order-card-accent) 36%, var(--border)); }
.admin-order-actions-head { display: flex; align-items: center; gap: 6px; min-width: 0; overflow-x: auto; padding-bottom: 2px; scrollbar-width: thin; }
.admin-order-actions-title { flex: none; margin-inline-end: 2px; color: var(--ink-faint); font-size: 9px; font-weight: 900; }
.admin-order-actions-head .fbtn, .admin-order-actions-head :deep(.popup-select) { flex: none; min-height: 29px; white-space: nowrap; }
.admin-view-details { min-height: 29px; padding-inline: 10px; color: #062033; border-color: transparent; background: var(--primary); font-size: 10px; }
.detail-order-actions { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
.admin-order-status-select { width: auto; min-width: 112px; min-height: 31px; padding: 4px 8px; font-size: 10px; }
.admin-order-status-select :deep(.popup-select-chevron) { width: 17px; height: 17px; font-size: 14px; }
.pickup-overdue-action { border-color: color-mix(in srgb, var(--warning) 55%, var(--border)); color: #9a5a00; background: var(--warning-tint); }
.restore-order-action { border-color: color-mix(in srgb, var(--success) 55%, var(--border)); color: var(--success); background: var(--success-tint); }
.order-detail-restore { width: 100%; min-height: 44px; }

.order-detail-sheet { display: grid; gap: 9px; padding-bottom: 2px; }
.order-detail-loading { display: grid; justify-items: center; gap: 13px; min-height: 190px; padding: 34px 18px; color: var(--ink-soft); text-align: center; }
.loader { display: inline-block; width: 18px; height: 18px; border: 2px solid currentColor; border-right-color: transparent; border-radius: 50%; animation: orders-spin .7s linear infinite; }
@keyframes orders-spin { to { transform: rotate(360deg); } }
.order-detail-hero { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 11px; border: 1px solid var(--border); border-radius: 12px; background: var(--surface-2); }
.order-detail-hero > div { min-width: 0; display: grid; gap: 3px; }
.order-detail-kicker, .order-detail-hero > div > span:last-child { color: var(--ink-faint); font-size: 10.5px; font-weight: 800; }
.order-detail-hero b { color: var(--primary); font-size: 15px; font-weight: 900; }
.order-detail-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 7px; }
.order-detail-summary > div { padding: 8px 9px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface); }
.order-detail-summary span, .order-detail-grid span { display: block; color: var(--ink-faint); font-size: 9px; font-weight: 800; }
.order-detail-summary b { display: block; margin-top: 3px; font-size: 12px; font-weight: 900; }
.order-detail-positive { color: var(--success); }
.order-detail-section { padding: 10px 11px; border: 1px solid var(--border); border-radius: 12px; background: var(--surface); }
.order-detail-section h4 { margin: 0 0 8px; padding-bottom: 7px; border-bottom: 1px solid var(--border); color: var(--primary-strong); font-size: 11.5px; font-weight: 900; }
.order-detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 12px; }
.order-detail-grid > div { min-width: 0; }
.order-detail-operational-section .order-detail-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.order-detail-operational-section .order-detail-grid > div { padding-inline-start: 8px; border-inline-start: 1px solid var(--border); }
.order-detail-operational-section .order-detail-grid > div:nth-child(3n + 1) { padding-inline-start: 0; border-inline-start: 0; }
.order-detail-grid b { display: block; margin-top: 2px; color: var(--ink); font-size: 10.5px; font-weight: 800; line-height: 1.45; overflow-wrap: anywhere; }
.order-detail-grid-wide { grid-column: 1 / -1; }
.pickup-overdue-notice { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 14px; padding: 11px; border: 1px solid color-mix(in srgb, var(--warning) 50%, var(--border)); border-radius: 11px; background: var(--warning-tint); }
.pickup-overdue-notice > div { display: grid; gap: 3px; }
.pickup-overdue-notice b { color: #8a5100; font-size: 11px; font-weight: 900; }
.pickup-overdue-notice span { color: var(--ink-soft); font-size: 10px; font-weight: 700; line-height: 1.55; }

.order-detail-timeline-section { padding-inline-end: 7px; }
.order-timeline { display: grid; max-height: 218px; gap: 0; overflow-y: auto; padding-inline-end: 4px; overscroll-behavior: contain; }
.order-timeline-item { display: grid; grid-template-columns: 27px minmax(0, 1fr); gap: 8px; min-height: 46px; }
.order-timeline-rail { position: relative; display: flex; justify-content: center; }
.order-timeline-item:not(:last-child) .order-timeline-rail::after { position: absolute; top: 23px; bottom: -4px; width: 1px; background: var(--border); content: ''; }
.order-timeline-marker { position: relative; z-index: 1; width: 22px; height: 22px; display: grid; place-items: center; border-radius: 50%; color: var(--primary-strong); background: var(--primary-tint); font-size: 10px; font-weight: 900; }
.order-timeline-marker.is-movement { color: var(--warning); background: var(--warning-tint); }
.order-timeline-marker.is-assignment { color: var(--primary-strong); background: var(--primary-tint); }
.order-timeline-marker.is-status { color: var(--success); background: var(--success-tint); }
.order-timeline-copy { padding: 1px 0 10px; }
.order-timeline-copy b { display: block; color: var(--ink); font-size: 10.5px; font-weight: 900; line-height: 1.35; }
.order-timeline-copy p, .order-timeline-copy time { display: block; margin: 2px 0 0; color: var(--ink-faint); font-size: 9.5px; font-weight: 700; line-height: 1.4; }
.order-detail-note { margin: 0; color: var(--ink-soft); font-size: 10.5px; font-weight: 700; line-height: 1.6; }
.order-detail-note + .order-detail-note { margin-top: 5px; }
.pickup-point-section{border-color:color-mix(in srgb,var(--primary) 32%,var(--border));background:linear-gradient(135deg,color-mix(in srgb,var(--primary-tint) 64%,var(--surface)),var(--surface))}.pickup-point-card{display:flex;align-items:center;gap:10px}.pickup-point-icon{width:35px;height:35px;display:grid;place-items:center;flex:none;border-radius:10px;color:#062033;background:var(--primary);font-size:19px;font-weight:900}.pickup-point-card>div{display:grid;min-width:0;flex:1;gap:3px}.pickup-point-card b{overflow:hidden;margin:0;text-overflow:ellipsis;white-space:nowrap}.pickup-point-card small{color:var(--ink-faint);font-size:9.5px;font-weight:750}.pickup-point-card a{display:inline-flex;align-items:center;justify-content:center;min-height:33px;padding:0 9px;border-radius:8px;color:#062033;background:var(--primary);font-size:9.5px;font-weight:900;text-decoration:none;white-space:nowrap}
.order-delete-action,.order-delete-button{color:var(--danger);border-color:color-mix(in srgb,var(--danger) 48%,var(--border));background:var(--danger-tint)}.order-edit-form{display:grid;gap:14px}.order-edit-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}.order-edit-grid label{display:grid;gap:6px;color:var(--ink-soft);font-size:10px;font-weight:850}.order-edit-grid input,.order-edit-grid textarea,.order-edit-grid select{width:100%;box-sizing:border-box;border:1px solid var(--border);border-radius:10px;padding:10px;color:var(--ink);background:var(--surface-2);font:700 12px var(--font);outline:0}.order-edit-grid input:focus,.order-edit-grid textarea:focus,.order-edit-grid select:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-tint)}.order-edit-wide{grid-column:1/-1}.order-delete-confirm{display:grid;gap:18px}.order-delete-confirm p{margin:0;color:var(--ink-soft);font-size:11px;font-weight:700;line-height:1.8}.order-delete-confirm>div{display:flex;justify-content:flex-end;gap:8px}

@media (max-width: 620px) {
    .orders-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
    .orders-summary-card { padding: 10px; }
    .orders-summary-card-head > b { font-size: 17px; }
    .orders-summary-card-amount { align-items: flex-start; flex-direction: column; gap: 3px; }
    .orders-query-bar { align-items: stretch; flex-direction: column; }
    .orders-search-field, .orders-courier-filter, .orders-date-filter, .orders-display-count { flex-basis: auto; }
    .orders-search-submit, .orders-clear-filters { width: 100%; }
    .admin-orders-list { gap: 6px; padding: 7px; }
    .admin-order-card { padding: 8px 9px; }
    .admin-order-card-details { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 7px 0; }
    .admin-order-card-details > div { padding-inline: 8px; }
    .admin-order-card-details > div:nth-child(odd) { padding-inline-start: 0; border-inline-start: 0; }
    .admin-order-card-details > div:nth-child(even) { padding-inline-end: 0; }
    .order-timeline { max-height: 170px; }
    .admin-view-details { min-height: 32px; }
    .order-detail-summary, .order-detail-grid { grid-template-columns: 1fr; }
    .order-detail-operational-section .order-detail-grid { grid-template-columns: 1fr; }
    .order-detail-operational-section .order-detail-grid > div { padding-inline-start: 0; border-inline-start: 0; }
    .order-detail-grid-wide { grid-column: auto; }
    .order-detail-hero { align-items: flex-start; flex-direction: column; }
    .pickup-overdue-notice { align-items: stretch; flex-direction: column; }
    .order-edit-grid { grid-template-columns: 1fr; }
    .order-edit-wide { grid-column: auto; }
}
</style>
