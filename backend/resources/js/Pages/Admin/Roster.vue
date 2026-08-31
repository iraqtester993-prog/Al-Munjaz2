<script setup>
import { ref, computed, nextTick, onBeforeUnmount } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'

const props = defineProps({
    role: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
    roleFilters: { type: Object, default: () => ({}) },
    selectedRole: { type: String, default: 'all' },
    query: { type: Object, default: () => ({}) },
    pagination: { type: Object, default: () => ({ currentPage: 1, lastPage: 1, total: 0, from: null, to: null }) },
    canUpdateUsers: { type: Boolean, default: false },
    canDeleteUsers: { type: Boolean, default: false },
})

const active = ref(props.query?.status || 'all')
const activeRole = ref(props.selectedRole)
const search = ref(props.query?.search || '')
const detailsRow = ref(null)
const editingRow = ref(null)
const actionError = ref('')
let searchTimer = null
const editForm = useForm({
    name: '',
    username: '',
    email: '',
    phone: '',
    shop_name: '',
    address: '',
    vehicle: '',
    admin_deduction_per_order: 0,
})

const filterList = computed(() => [
    { key: 'all', label: t('All') },
    { key: 'active', label: t('Active') },
    { key: 'pending', label: t('Pending') },
    { key: 'suspended', label: t('Suspended') },
])

const visibleRows = computed(() => props.rows)

function statusClass(status) {
    return {
        active: 'b-success',
        pending: 'b-warning',
        suspended: 'b-danger',
        rejected: 'b-danger',
    }[status] || 'b-neutral'
}

function statusLabel(status) {
    return {
        active: t('Active'),
        pending: t('Pending'),
        suspended: t('Suspended'),
        rejected: t('Rejected'),
    }[status] || status
}

function changeStatus(row, status) {
    if (!props.canUpdateUsers || !row.user) return
    if (!confirm(t('Change status to') + ' ' + statusLabel(status) + '?')) return
    router.post(route('admin.users.status', row.user.id), { status }, {
        preserveScroll: true,
        onSuccess: () => syncOpenDetails(row),
    })
}

function reviewDoc(row, verdict, requestedDocumentId = null) {
    if (!props.canUpdateUsers || !row.docs || row.docs === 0) return
    const docId = requestedDocumentId || row.pendingDocs?.[0]
    if (!docId) return
    if (!confirm(t('Review this document?') + ' (' + (verdict === 'approved' ? t('Approve') : t('Reject')) + ')')) return
    router.post(route('admin.users.documents.review', [row.user.id, docId]), { status: verdict }, {
        preserveScroll: true,
        onSuccess: () => syncOpenDetails(row),
    })
}

function setMerchantVerification(row, verified) {
    if (!props.canUpdateUsers || !row.user || isCourier.value) return

    if (verified && !row.verification?.ready_to_verify) {
        openDetails(row)
        actionError.value = t('Complete and approve all four verification documents before granting the merchant badge.')
        return
    }

    actionError.value = ''
    const action = verified ? t('Grant verification') : t('Remove verification')
    if (!confirm(`${action}: ${row.user.name}?`)) return
    router.post(route('admin.users.merchant-verification', row.user.id), { verified }, {
        preserveScroll: true,
        onSuccess: () => syncOpenDetails(row),
        onError: (errors) => {
            openDetails(row)
            actionError.value = errors.verification || Object.values(errors)[0] || t('Unable to update account verification.')
        },
    })
}

function deleteAccount(row) {
    if (!props.canDeleteUsers || !row.user) return
    const message = `${t('Delete')} ${row.user.name}? ${t('This action is recoverable but is blocked while the account has open orders.')}`
    if (!confirm(message)) return
    router.delete(route('admin.users.destroy', row.user.id), {
        preserveScroll: true,
        onSuccess: () => {
            if (detailsRow.value?.id === row.id) closeDetails()
        },
    })
}

function openDocument(doc) {
    window.open(doc.url, '_blank', 'noopener')
}

function documentLabel(type) {
    const labels = {
        residence: t('Residence Card'),
        id_front: `${t('National ID Card')} · ${t('Front')}`,
        id_back: `${t('National ID Card')} · ${t('Back')}`,
        license_front: `${t('Driving License')} · ${t('Front')}`,
        license_back: `${t('Driving License')} · ${t('Back')}`,
        driving_license: t('Driving License'),
    }

    return labels[type] || type
}

function documentStatus(status) {
    return status === 'approved'
        ? t('Approved')
        : status === 'rejected'
            ? t('Rejected')
            : t('Pending')
}

function documentReviewLabel(row) {
    const review = row.document_review
    if (!review) return t('Not submitted')
    if (review.status === 'approved') return t('Documents Complete')
    if (review.status === 'rejected') return t('Documents Rejected')
    if (review.status === 'unsubmitted') return t('Required documents missing')

    return t('Documents Pending Review')
}

function documentReviewClass(row) {
    const status = row.document_review?.status
    if (status === 'approved') return 'approved'
    if (status === 'rejected' || status === 'unsubmitted') return 'rejected'

    return 'pending'
}

const isCourier = computed(() => props.role === 'courier')

const operationalRoleFilters = computed(() => [
    { key: 'all', label: t('All Couriers') },
    { key: 'courier', label: t('Couriers') },
    { key: 'pickup_courier', label: t('Pickup Couriers') },
    { key: 'delivery_courier', label: t('Delivery Couriers') },
    { key: 'transporter', label: t('Transporters') },
])

function courierRoleLabel(role) {
    const labels = {
        courier: 'Courier',
        pickup_courier: 'Pickup courier',
        delivery_courier: 'Delivery courier',
        transporter: 'Transporter',
    }

    return t(labels[role] || role)
}

function changeCourierRole(role) {
    activeRole.value = role
    visitRoster({ role, page: 1 })
}

function changeStatusFilter(status) {
    active.value = status
    visitRoster({ status, page: 1 })
}

function scheduleSearch() {
    if (searchTimer) clearTimeout(searchTimer)
    searchTimer = setTimeout(() => visitRoster({ page: 1 }), 350)
}

function visitRoster(overrides = {}) {
    if (searchTimer) {
        clearTimeout(searchTimer)
        searchTimer = null
    }

    const role = isCourier.value ? (overrides.role ?? activeRole.value) : undefined
    const status = overrides.status ?? active.value
    const page = overrides.page ?? props.pagination?.currentPage ?? 1
    const params = {
        search: search.value.trim() || undefined,
        status: status !== 'all' ? status : undefined,
        page: page > 1 ? page : undefined,
    }

    if (isCourier.value && role !== 'all') params.role = role

    router.get(isCourier.value ? route('admin.couriers') : route('admin.merchants'), params, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        only: ['rows', 'filters', 'roleFilters', 'selectedRole', 'query', 'pagination'],
    })
}

onBeforeUnmount(() => {
    if (searchTimer) clearTimeout(searchTimer)
})

function provinceNames(row) {
    return (row.user?.provinces || [])
        .map((province) => province.name_ar || province.name_en || province.name_ku)
        .filter(Boolean)
        .join(' · ')
}

function openDetails(row) {
    actionError.value = ''
    detailsRow.value = row
}

function closeDetails() {
    actionError.value = ''
    detailsRow.value = null
}

function syncOpenDetails(row) {
    nextTick(() => {
        if (detailsRow.value?.id !== row.id) return
        detailsRow.value = props.rows.find((item) => item.id === row.id) || null
    })
}

function openEdit(row) {
    if (!props.canUpdateUsers || !row.user) return
    detailsRow.value = null
    editingRow.value = row
    editForm.clearErrors()
    editForm.defaults({
        name: row.user.name || '',
        username: row.user.username || '',
        email: row.user.email || '',
        phone: row.user.phone || '',
        shop_name: row.user.shop_name || '',
        address: row.user.address || '',
        vehicle: row.user.vehicle || '',
        admin_deduction_per_order: Number(row.user.admin_deduction_per_order || 0),
    })
    editForm.reset()
}

function closeEdit(force = false) {
    if (editForm.processing && !force) return
    editingRow.value = null
    editForm.clearErrors()
}

function saveAccount() {
    if (!editingRow.value?.user || editForm.processing) return

    // This intentionally uses the same dashboard origin as the logged-in
    // admin. It avoids a second hard-coded host and keeps the operation
    // inside the authenticated dashboard session.
    editForm.put(`/dashboard/users/${editingRow.value.user.id}`, {
        preserveScroll: true,
        onSuccess: () => closeEdit(true),
    })
}

function vehicleLabel(value) {
    return {
        bike: t('Motorcycle'),
        car: t('Car'),
        sedan: t('Car'),
        suv: t('SUV'),
        truck: t('Truck'),
    }[value] || value || '—'
}
</script>

<template>
    <AdminShell :title="isCourier ? t('Couriers') : t('Merchants')">
        <section v-if="isCourier" class="roster-heading">
            <div>
                <p class="roster-eyebrow">{{ t('Platform Operations') }}</p>
                <h2>{{ t('Couriers') }}</h2>
                <p>{{ t('Manage courier accounts, operational access, and all submitted documents from one place.') }}</p>
            </div>
            <label class="roster-search">
                <span>⌕</span>
                <input v-model="search" type="search" :placeholder="t('Search by name, phone, username, or governorate')" :aria-label="t('Search Couriers')" @input="scheduleSearch" />
            </label>
        </section>
        <section v-else class="roster-heading">
            <div>
                <p class="roster-eyebrow">{{ t('Platform Operations') }}</p>
                <h2>{{ t('Merchants') }}</h2>
                <p>{{ t('Manage merchant accounts, verification, and submitted documents from one place.') }}</p>
            </div>
            <label class="roster-search">
                <span>⌕</span>
                <input v-model="search" type="search" :placeholder="t('Search by name, phone, username, or governorate')" :aria-label="t('Search Merchants')" @input="scheduleSearch" />
            </label>
        </section>
        <div v-if="isCourier" class="filter-bar roster-role-filter">
            <button
                v-for="filter in operationalRoleFilters"
                :key="filter.key"
                class="fbtn"
                :class="{ active: activeRole === filter.key }"
                @click="changeCourierRole(filter.key)"
            >
                {{ filter.label }} <span class="cnt">{{ roleFilters[filter.key] ?? 0 }}</span>
            </button>
        </div>
        <div class="filter-bar">
            <button v-for="f in filterList" :key="f.key" class="fbtn" :class="{ active: active === f.key }" @click="changeStatusFilter(f.key)">
                {{ f.label }} <span class="cnt">{{ filters[f.key] ?? 0 }}</span>
            </button>
        </div>

        <div v-if="visibleRows.length" class="roster-grid">
            <div v-for="row in visibleRows" :key="row.id" class="user-card">
                <div class="uc-top user-card-header" role="button" tabindex="0" @click="openDetails(row)" @keydown.enter="openDetails(row)" @keydown.space.prevent="openDetails(row)">
                    <div class="avatar avatar-lg" style="width: 46px; height: 46px; font-size: 16px">{{ row.name?.charAt(0) }}</div>
                    <div class="uc-id">
                        <b>{{ row.name }}</b>
                        <span class="vtag" style="margin-top: 4px; display: inline-block">{{ row.user?.phone }}</span>
                        <small v-if="isCourier" class="courier-role-label">{{ courierRoleLabel(row.user?.role || row.role) }}</small>
                    </div>
                    <span class="uc-flag" :class="{ active: row.status === 'active' }" :style="{ background: statusClass(row.status) === 'b-warning' ? 'var(--warning-tint)' : statusClass(row.status) === 'b-danger' ? 'var(--danger-tint)' : '' }">
                        <i :style="{ width: '7px', height: '7px', borderRadius: '50%', background: 'currentColor', display: 'inline-block' }"></i>
                        {{ statusLabel(row.status) }}
                    </span>
                    <span v-if="!isCourier && row.verification?.verified" class="merchant-verified-badge" :title="t('Verified')">✓</span>
                </div>

                <div class="uc-meta">
                    <span>
                        {{ t('Username') }}
                        <label dir="ltr">{{ row.user?.username || '—' }}</label>
                    </span>
                    <span>
                        {{ t('Balance') }}
                        <label class="mono">{{ fmt(row.wallet_balance) }} {{ t('IQD') }}</label>
                    </span>
                    <span v-if="isCourier">
                        {{ t('Budget') }}
                        <label class="mono">{{ fmt(row.cash_budget) }} {{ t('IQD') }}</label>
                    </span>
                    <span v-if="isCourier">
                        {{ t('Points Balance') }}
                        <label class="mono">{{ fmt(row.points_balance) }}</label>
                    </span>
                    <span v-if="isCourier">
                        استقطاع الإدارة لكل طلب
                        <label class="mono">{{ fmt(row.admin_deduction_per_order) }} {{ t('IQD') }}</label>
                    </span>
                    <span v-else>
                        {{ t('Shop Name') }}
                        <label>{{ row.user?.shop_name || '—' }}</label>
                    </span>
                    <span v-if="provinceNames(row)">
                        {{ t('Governorate') }}
                        <label>{{ provinceNames(row) }}</label>
                    </span>
                </div>

                <div v-if="isCourier" class="uc-stats">
                    <div class="uc-stat"><b>{{ row.assigned }}</b><span>{{ t('Assigned') }}</span></div>
                    <div class="uc-stat"><b>{{ row.delivered }}</b><span>{{ t('Delivered') }}</span></div>
                    <div class="uc-stat"><b>{{ row.returned }}</b><span>{{ t('Returned') }}</span></div>
                    <div class="uc-stat"><b class="mono" style="font-size: 11px">{{ fmt(row.collected) }}</b><span>{{ t('Collected') }}</span></div>
                </div>
                <div v-else class="uc-stats">
                    <div class="uc-stat"><b>{{ row.orders }}</b><span>{{ t('Orders') }}</span></div>
                    <div class="uc-stat"><b>{{ row.delivered }}</b><span>{{ t('Delivered') }}</span></div>
                    <div class="uc-stat"><b>{{ row.returned }}</b><span>{{ t('Returned') }}</span></div>
                    <div class="uc-stat"><b class="mono" style="font-size: 11px">{{ fmt(row.collected) }}</b><span>{{ t('Collected') }}</span></div>
                </div>

                <div v-if="isCourier" class="courier-document-summary">
                    <div>
                        <span>{{ t('Document Review') }}</span>
                        <b :class="documentReviewClass(row)">{{ documentReviewLabel(row) }}</b>
                        <small>{{ row.document_review?.approved || 0 }}/{{ row.document_review?.total || 0 }} {{ t('Approved Documents') }}</small>
                    </div>
                    <button class="fbtn mini account-action" type="button" @click="openDetails(row)">{{ t('Review Documents') }}</button>
                </div>
                <div v-else class="courier-document-summary">
                    <div>
                        <span>{{ t('Account Verification') }}</span>
                        <b :class="documentReviewClass(row)">{{ row.verification?.verified ? t('Verified') : (row.verification?.ready_to_verify ? t('Verification ready') : t('Review Documents')) }}</b>
                        <small>{{ row.document_review?.approved || 0 }}/4 {{ t('Approved Documents') }}</small>
                    </div>
                    <button class="fbtn mini account-action" type="button" @click="openDetails(row)">{{ t('Review Documents') }}</button>
                </div>

                <div style="display: flex; gap: 8px; flex-wrap: wrap">
                    <button class="fbtn mini account-action" type="button" @click="openDetails(row)">{{ t('View Details') }}</button>
                    <button v-if="canUpdateUsers" class="fbtn mini account-action" type="button" @click="openEdit(row)">{{ t('Edit') }}</button>
                    <button v-if="canUpdateUsers && !isCourier && !row.verification?.verified" class="fbtn mini verification-action" type="button" @click="setMerchantVerification(row, true)">{{ row.verification?.ready_to_verify ? t('Grant verification') : t('Review Documents') }}</button>
                    <button v-if="canUpdateUsers && !isCourier && row.verification?.verified" class="fbtn mini verification-remove" type="button" @click="setMerchantVerification(row, false)">{{ t('Remove verification') }}</button>
                    <button v-if="canUpdateUsers && row.status !== 'active'" class="fbtn mini" style="background: var(--success-tint); color: var(--success); border: none" @click="changeStatus(row, 'active')">{{ t('Activate') }}</button>
                    <button v-if="canUpdateUsers && row.status === 'active'" class="fbtn mini" style="background: var(--warning-tint); color: var(--warning); border: none" @click="changeStatus(row, 'suspended')">{{ t('Suspend') }}</button>
                    <button v-if="canUpdateUsers && row.status === 'pending'" class="fbtn mini" @click="changeStatus(row, 'rejected')">{{ t('Reject') }}</button>
                    <button v-if="canDeleteUsers" class="fbtn mini delete-account" type="button" @click="deleteAccount(row)">{{ t('Delete') }}</button>
                </div>
            </div>
        </div>
        <div v-else class="panel"><div class="empty">{{ isCourier && search.trim() ? t('No couriers match your search.') : t('No users found') }}</div></div>

        <nav v-if="pagination?.lastPage > 1" class="roster-pagination" :aria-label="t('Pagination')">
            <button type="button" :disabled="pagination.currentPage <= 1" :aria-label="t('Previous')" @click="visitRoster({ page: pagination.currentPage - 1 })">‹</button>
            <span class="mono">{{ pagination.from }}–{{ pagination.to }} / {{ pagination.total }}</span>
            <button type="button" :disabled="pagination.currentPage >= pagination.lastPage" :aria-label="t('Next')" @click="visitRoster({ page: pagination.currentPage + 1 })">›</button>
        </nav>

        <div v-if="detailsRow" class="dialog-backdrop" @click.self="closeDetails">
            <section class="account-dialog" role="dialog" aria-modal="true" :aria-label="t('Account Details')">
                <header class="dialog-header">
                    <div>
                        <small>{{ isCourier ? t('Courier') : t('Merchant') }}</small>
                        <h3>{{ detailsRow.user?.name }}</h3>
                    </div>
                    <button type="button" :aria-label="t('Close')" @click="closeDetails">×</button>
                </header>

                <div class="account-summary">
                    <span class="account-avatar">{{ detailsRow.user?.name?.charAt(0) }}</span>
                    <div>
                        <b>{{ detailsRow.user?.username }}</b>
                        <small>{{ statusLabel(detailsRow.status) }} · {{ detailsRow.user?.is_online ? t('Online') : t('Offline') }}</small>
                    </div>
                    <button v-if="canUpdateUsers" class="fbtn mini account-action" type="button" @click="openEdit(detailsRow)">{{ t('Edit') }}</button>
                </div>

                <dl class="account-data">
                    <div><dt>{{ t('Phone') }}</dt><dd dir="ltr">{{ detailsRow.user?.phone || '—' }}</dd></div>
                    <div><dt>{{ t('Email') }}</dt><dd dir="ltr">{{ detailsRow.user?.email || '—' }}</dd></div>
                    <div><dt>{{ t('Governorate') }}</dt><dd>{{ provinceNames(detailsRow) || '—' }}</dd></div>
                    <div><dt>{{ t('Created at') }}</dt><dd dir="ltr">{{ detailsRow.user?.created_at || '—' }}</dd></div>
                    <div v-if="isCourier" class="wide"><dt>{{ t('Document Review') }}</dt><dd><b class="detail-verification" :class="documentReviewClass(detailsRow)">{{ documentReviewLabel(detailsRow) }}</b><small>{{ t('Approved Documents') }}: {{ detailsRow.document_review?.approved || 0 }} / {{ detailsRow.document_review?.total || 0 }} · {{ t('Pending Documents') }}: {{ detailsRow.document_review?.pending || 0 }} · {{ t('Rejected Documents') }}: {{ detailsRow.document_review?.rejected || 0 }}</small></dd></div>
                    <div v-if="!isCourier"><dt>{{ t('Shop Name') }}</dt><dd>{{ detailsRow.user?.shop_name || '—' }}</dd></div>
                    <div v-if="isCourier"><dt>{{ t('Vehicle') }}</dt><dd>{{ vehicleLabel(detailsRow.user?.vehicle) }}</dd></div>
                    <div v-if="isCourier"><dt>{{ t('Points Balance') }}</dt><dd class="mono">{{ fmt(detailsRow.points_balance) }}</dd></div>
                    <div v-if="isCourier"><dt>استقطاع الإدارة لكل طلب</dt><dd class="mono">{{ fmt(detailsRow.admin_deduction_per_order) }} {{ t('IQD') }}</dd></div>
                    <div class="wide"><dt>{{ t('Address') }}</dt><dd>{{ detailsRow.user?.address || '—' }}</dd></div>
                    <div v-if="detailsRow.user?.identity_number" class="wide"><dt>{{ t('Identity Number') }}</dt><dd dir="ltr">{{ detailsRow.user.identity_number }}</dd></div>
                    <div v-if="!isCourier" class="wide"><dt>{{ t('Account Verification') }}</dt><dd><b class="detail-verification" :class="detailsRow.verification?.status">{{ detailsRow.verification?.verified ? t('Verified') : detailsRow.verification?.status === 'rejected' ? t('Rejected') : detailsRow.verification?.status === 'pending' ? t('Verification pending') : t('Not submitted') }}</b><small v-if="detailsRow.verification?.verified_by">{{ t('Verified by') }} · {{ detailsRow.verification.verified_by }}</small><small v-else>{{ t('Approved Documents') }}: {{ detailsRow.document_review?.approved || 0 }} / 4 · {{ t('Pending Documents') }}: {{ detailsRow.document_review?.pending || 0 }} · {{ t('Rejected Documents') }}: {{ detailsRow.document_review?.rejected || 0 }} · {{ t('Missing Documents') }}: {{ detailsRow.document_review?.missing || 0 }}</small></dd></div>
                </dl>

                <p v-if="actionError" class="verification-error" role="alert">{{ actionError }}</p>

                <section class="account-documents">
                    <h4>{{ t('Documents') }}</h4>
                    <p v-if="isCourier" class="documents-review-hint">{{ t('Review each document individually. A rejected document must be uploaded again before it can be approved.') }}</p>
                    <p v-else-if="detailsRow.verification?.ready_to_verify" class="documents-review-hint verification-ready-copy">{{ t('All required verification documents have been approved. You can now grant the merchant badge.') }}</p>
                    <p v-else class="documents-review-hint">{{ t('Complete and approve all four verification documents before granting the merchant badge.') }}</p>
                    <p v-if="!detailsRow.documents?.length" class="documents-review-hint">{{ t('No documents have been uploaded yet.') }}</p>
                    <div v-for="document in detailsRow.documents" :key="document.id" class="account-document-row">
                        <button type="button" @click="openDocument(document)">{{ documentLabel(document.type) }}</button>
                        <span :class="document.status">{{ documentStatus(document.status) }}</span>
                        <div v-if="canUpdateUsers && document.status === 'pending'" class="account-document-actions"><button type="button" @click="reviewDoc(detailsRow, 'approved', document.id)">{{ t('Approve') }}</button><button type="button" @click="reviewDoc(detailsRow, 'rejected', document.id)">{{ t('Reject') }}</button></div>
                    </div>
                </section>

                <footer class="dialog-footer">
                    <button v-if="canUpdateUsers && isCourier && detailsRow.status === 'active'" class="fbtn mini suspension-action" type="button" @click="changeStatus(detailsRow, 'suspended')">{{ t('Suspend') }}</button>
                    <button v-if="canUpdateUsers && isCourier && detailsRow.status !== 'active'" class="fbtn mini verification-action" type="button" @click="changeStatus(detailsRow, 'active')">{{ t('Activate') }}</button>
                    <button v-if="canUpdateUsers && !isCourier && !detailsRow.verification?.verified" class="fbtn mini verification-action" type="button" :disabled="!detailsRow.verification?.ready_to_verify" :title="!detailsRow.verification?.ready_to_verify ? t('Complete and approve all four verification documents before granting the merchant badge.') : ''" @click="setMerchantVerification(detailsRow, true)">{{ t('Grant verification') }}</button>
                    <button v-if="canUpdateUsers && !isCourier && detailsRow.verification?.verified" class="fbtn mini verification-remove" type="button" @click="setMerchantVerification(detailsRow, false)">{{ t('Remove verification') }}</button>
                    <button v-if="canDeleteUsers" class="fbtn mini delete-account" type="button" @click="deleteAccount(detailsRow)">{{ t('Delete') }}</button>
                    <button class="fbtn mini" type="button" @click="closeDetails">{{ t('Close') }}</button>
                </footer>
            </section>
        </div>

        <div v-if="editingRow" class="dialog-backdrop" @click.self="closeEdit">
            <form class="account-dialog" @submit.prevent="saveAccount">
                <header class="dialog-header">
                    <div>
                        <small>{{ t('Account Details') }}</small>
                        <h3>{{ t('Edit') }} · {{ editingRow.user?.name }}</h3>
                    </div>
                    <button type="button" :aria-label="t('Close')" @click="closeEdit">×</button>
                </header>

                <div class="edit-grid">
                    <label>{{ t('Name') }}<input v-model="editForm.name" required maxlength="120" /></label>
                    <label>{{ t('Username') }}<input v-model="editForm.username" required maxlength="60" dir="ltr" autocomplete="off" /></label>
                    <label>{{ t('Phone') }}<input v-model="editForm.phone" required maxlength="30" dir="ltr" inputmode="tel" /></label>
                    <label>{{ t('Email') }}<input v-model="editForm.email" type="email" maxlength="255" dir="ltr" /></label>
                    <label v-if="!isCourier">{{ t('Shop Name') }}<input v-model="editForm.shop_name" maxlength="120" /></label>
                    <label v-if="isCourier">{{ t('Vehicle') }}
                        <select v-model="editForm.vehicle">
                            <option value="">—</option>
                            <option value="bike">{{ t('Motorcycle') }}</option>
                            <option value="sedan">{{ t('Car') }}</option>
                            <option value="suv">{{ t('SUV') }}</option>
                            <option value="truck">{{ t('Truck') }}</option>
                        </select>
                    </label>
                    <label v-if="isCourier">استقطاع الإدارة لكل طلب ({{ t('IQD') }})<input v-model.number="editForm.admin_deduction_per_order" type="number" min="0" max="1000000" inputmode="numeric" /></label>
                    <label class="wide">{{ t('Address') }}<textarea v-model="editForm.address" rows="3" maxlength="255" /></label>
                </div>
                <p v-if="Object.values(editForm.errors)[0]" class="form-error">{{ Object.values(editForm.errors)[0] }}</p>
                <footer class="dialog-footer">
                    <button class="fbtn mini" type="button" :disabled="editForm.processing" @click="closeEdit">{{ t('Cancel') }}</button>
                    <button class="fbtn mini save-account" type="submit" :disabled="editForm.processing">{{ editForm.processing ? t('Saving…') : t('Save') }}</button>
                </footer>
            </form>
        </div>
    </AdminShell>
</template>

<style scoped>
.roster-heading { display: flex; align-items: end; justify-content: space-between; gap: 18px; margin-bottom: 16px; }.roster-eyebrow { margin: 0; color: var(--primary); font-size: 9px; font-weight: 900; letter-spacing: .1em; text-transform: uppercase; }.roster-heading h2 { margin: 4px 0 0; color: var(--ink); font-size: 23px; font-weight: 950; }.roster-heading p:last-child { max-width:570px; margin: 5px 0 0; color: var(--ink-faint); font-size: 11px; font-weight: 700; line-height: 1.7; }.roster-search { display: flex; align-items: center; min-width: min(330px, 100%); min-height: 40px; gap: 7px; padding: 0 11px; border: 1px solid var(--border); border-radius: 11px; color: var(--primary-strong); background: var(--surface); }.roster-search span { font-size: 18px; line-height: 1; }.roster-search input { min-width: 0; flex: 1; border: 0; outline: 0; color: var(--ink); background: transparent; font: 750 11px var(--font); }.roster-pagination { display: flex; align-items: center; justify-content: center; gap: 8px; margin: 18px 0 2px; color: var(--ink-soft); font-size: 10px; font-weight: 800; }.roster-pagination button { display: grid; width: 30px; height: 30px; place-items: center; border: 1px solid var(--border); border-radius: 9px; color: var(--primary-strong); background: var(--surface); font-size: 20px; line-height: 1; cursor: pointer; }.roster-pagination button:disabled { cursor: not-allowed; opacity: .45; }.user-card-header { cursor: pointer; }.user-card-header:focus-visible { outline: 3px solid var(--primary-tint); outline-offset: 4px; border-radius: 12px; }.courier-document-summary { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px; border: 1px solid var(--border); border-radius: 11px; background: var(--surface-2); }.courier-document-summary > div { display: grid; min-width: 0; gap: 2px; }.courier-document-summary span { color: var(--ink-faint); font-size: 9px; font-weight: 850; }.courier-document-summary b { overflow: hidden; font-size: 10px; font-weight: 900; text-overflow: ellipsis; white-space: nowrap; }.courier-document-summary b.approved { color: var(--success); }.courier-document-summary b.pending { color: var(--warning); }.courier-document-summary b.rejected { color: var(--danger); }.courier-document-summary small { color: var(--ink-faint); font-size: 8.5px; font-weight: 750; }.suspension-action { border: 0; color: var(--warning); background: var(--warning-tint); }.documents-review-hint { margin: -1px 0 7px; color: var(--ink-faint); font-size: 9.5px; font-weight: 700; line-height: 1.6; }
.roster-role-filter { margin-bottom: 10px; }
.courier-role-label { display: block; margin-top: 4px; color: var(--primary-strong); font-size: 9px; font-weight: 900; }
.account-action { color: var(--primary-strong); background: var(--primary-tint); border: 0; }
.merchant-verified-badge { display: grid; width: 21px; height: 21px; place-items: center; flex: none; border-radius: 50%; color: #fff; background: #1d9bf0; font-size: 12px; font-weight: 900; }
.verification-action { border: 0; color: #075450; background: #d8f5e9; }.verification-action:disabled { cursor: not-allowed; opacity: .48; }.verification-remove { border: 0; color: var(--warning); background: var(--warning-tint); }.delete-account { border: 0; color: var(--danger); background: var(--danger-tint); }
.dialog-backdrop { position: fixed; z-index: 90; inset: 0; display: grid; place-items: center; padding: 18px; background: rgba(4, 12, 26, .62); backdrop-filter: blur(4px); }
.account-dialog { width: min(100%, 600px); overflow: hidden; border: 1px solid var(--border); border-radius: 18px; color: var(--ink); background: var(--surface); box-shadow: 0 28px 76px rgba(0,0,0,.34); }
.dialog-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 17px 18px; border-bottom: 1px solid var(--border); }
.dialog-header small { color: var(--primary); font-size: 9px; font-weight: 900; letter-spacing: .07em; text-transform: uppercase; }
.dialog-header h3 { margin: 4px 0 0; font-size: 16px; }
.dialog-header > button { display: grid; place-items: center; width: 30px; height: 30px; border: 0; border-radius: 9px; color: var(--ink-soft); background: var(--surface-2); font-size: 20px; cursor: pointer; }
.account-summary { display: flex; align-items: center; gap: 10px; padding: 15px 18px; border-bottom: 1px solid var(--border); }
.account-summary > div { display: grid; min-width: 0; gap: 2px; }
.account-summary b { overflow: hidden; font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
.account-summary small { color: var(--ink-faint); font-size: 9px; font-weight: 700; }
.account-summary .account-action { margin-inline-start: auto; }
.account-avatar { display: grid; place-items: center; width: 36px; height: 36px; flex: none; border-radius: 11px; color: #062033; background: linear-gradient(135deg,var(--primary),#0ea5e9); font-size: 14px; font-weight: 900; }
.account-data { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 0; margin: 0; padding: 0 18px; }
.account-data > div { min-width: 0; padding: 12px 0; border-bottom: 1px solid var(--border); }
.account-data > div:nth-child(odd) { padding-inline-end: 14px; }
.account-data > div:nth-child(even) { padding-inline-start: 14px; }
.account-data .wide { grid-column: 1 / -1; padding-inline: 0; }
.account-data dt { color: var(--ink-faint); font-size: 9px; font-weight: 850; }
.account-data dd { overflow: hidden; margin: 4px 0 0; color: var(--ink); font-size: 11px; font-weight: 750; line-height: 1.55; text-overflow: ellipsis; white-space: nowrap; }
.account-data .wide dd { white-space: normal; }
.account-data dd small { display: block; margin-top: 3px; color: var(--ink-faint); font-size: 9px; font-weight: 700; }.detail-verification { display: inline-flex; padding: 3px 7px; border-radius: 999px; background: var(--surface-2); font-size: 9.5px; }.detail-verification.verified { color: var(--success); }.detail-verification.pending { color: var(--warning); }.detail-verification.rejected { color: var(--danger); }
.verification-error { margin: 14px 18px 0; padding: 9px 10px; border-radius: 10px; color: var(--danger); background: var(--danger-tint); font-size: 10px; font-weight: 800; line-height: 1.6; }.account-documents { margin: 0 18px 2px; padding: 14px 0; border-top: 1px solid var(--border); }.account-documents h4 { margin: 0 0 9px; font-size: 11px; }.verification-ready-copy { color: var(--success); }.account-document-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 7px 10px; align-items: center; padding: 9px 0; border-top: 1px solid var(--border); }.account-document-row > button { min-width: 0; overflow: hidden; padding: 0; border: 0; color: var(--primary-strong); background: transparent; font: 800 10px var(--font); text-align: start; text-overflow: ellipsis; white-space: nowrap; cursor: pointer; }.account-document-row > span { font-size: 9px; font-weight: 850; }.account-document-row > span.approved { color: var(--success); }.account-document-row > span.rejected { color: var(--danger); }.account-document-row > span.pending { color: var(--warning); }.account-document-actions { grid-column: 1 / -1; display: flex; gap: 7px; }.account-document-actions button { padding: 5px 8px; border: 0; border-radius: 7px; background: var(--surface-2); color: var(--primary-strong); font: 800 9px var(--font); cursor: pointer; }.account-document-actions button:last-child { color: var(--danger); }
.edit-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 12px; padding: 18px; }
.edit-grid label { display: grid; gap: 6px; color: var(--ink-soft); font-size: 10px; font-weight: 850; }
.edit-grid .wide { grid-column: 1 / -1; }
.edit-grid input,.edit-grid select,.edit-grid textarea { width: 100%; box-sizing: border-box; border: 1px solid var(--border); border-radius: 10px; outline: 0; padding: 10px; color: var(--ink); background: var(--surface-2); font: 700 12px var(--font); }
.edit-grid input:focus,.edit-grid select:focus,.edit-grid textarea:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-tint); }
.edit-grid textarea { resize: vertical; }
.form-error { margin: -4px 18px 0; color: var(--danger); font-size: 10px; font-weight: 800; }
.dialog-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 18px; border-top: 1px solid var(--border); }
.save-account { color: #062033; background: linear-gradient(135deg,var(--primary),#0ea5e9); border: 0; }
@media (max-width: 580px) { .roster-heading { align-items: stretch; flex-direction: column; }.roster-search { min-width: 0; }.dialog-backdrop { align-items: end; padding: 0; } .account-dialog { width: 100%; max-height: 92dvh; overflow-y: auto; border-radius: 18px 18px 0 0; } .account-data,.edit-grid { grid-template-columns: 1fr; } .account-data > div:nth-child(odd),.account-data > div:nth-child(even) { padding-inline: 0; } .dialog-footer { padding-bottom: max(14px, env(safe-area-inset-bottom)); } }
</style>
