<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'

const props = defineProps({
    role: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
    roleFilters: { type: Object, default: () => ({}) },
    selectedRole: { type: String, default: 'all' },
})

const active = ref('all')
const activeRole = ref(props.selectedRole)

const filterList = computed(() => [
    { key: 'all', label: t('All') },
    { key: 'active', label: t('Active') },
    { key: 'pending', label: t('Pending') },
    { key: 'suspended', label: t('Suspended') },
])

const visibleRows = computed(() => {
    if (active.value === 'all') return props.rows
    return props.rows.filter((r) => r.status === active.value)
})

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
    if (!row.user) return
    if (!confirm(t('Change status to') + ' ' + statusLabel(status) + '?')) return
    router.post(route('admin.users.status', row.user.id), { status }, { preserveScroll: true })
}

function reviewDoc(row, verdict) {
    if (!row.docs || row.docs === 0) return
    const docId = row.pendingDocs?.[0]
    if (!docId) return
    if (!confirm(t('Review this document?') + ' (' + (verdict === 'approved' ? t('Approve') : t('Reject')) + ')')) return
    router.post(route('admin.users.documents.review', [row.user.id, docId]), { status: verdict }, { preserveScroll: true })
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
    router.get(route('admin.couriers'), { role }, { preserveScroll: true, preserveState: true, replace: true })
}
</script>

<template>
    <AdminShell :title="isCourier ? t('Couriers') : t('Merchants')">
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
            <button v-for="f in filterList" :key="f.key" class="fbtn" :class="{ active: active === f.key }" @click="active = f.key">
                {{ f.label }} <span class="cnt">{{ filters[f.key] ?? 0 }}</span>
            </button>
        </div>

        <div v-if="visibleRows.length" class="roster-grid">
            <div v-for="row in visibleRows" :key="row.id" class="user-card">
                <div class="uc-top">
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
                </div>

                <div class="uc-meta">
                    <span>
                        {{ t('Plan') }}
                        <label>{{ row.plan || '—' }}</label>
                    </span>
                    <span>
                        {{ t('Wallet') }}
                        <label class="mono">{{ fmt(row.wallet_balance) }} {{ t('IQD') }}</label>
                    </span>
                    <span v-if="isCourier">
                        {{ t('Vehicle') }}
                        <label>{{ row.user?.vehicle || '—' }}</label>
                    </span>
                    <span>
                        {{ t('Trial until') }}
                        <label>{{ row.trial_ends_at || '—' }}</label>
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

                <div v-if="row.documents?.length" style="display: flex; gap: 8px; flex-wrap: wrap">
                    <button v-for="doc in row.documents" :key="doc.id" class="fbtn mini" style="border: none" @click="openDocument(doc)">{{ t('View Document') }} · {{ documentLabel(doc.type) }}</button>
                </div>
                <div v-if="row.docs > 0" style="display: flex; gap: 8px; margin-top: 8px">
                    <button class="fbtn mini" style="background: var(--success-tint); color: var(--success); border: none" @click="reviewDoc(row, 'approved')">{{ t('Approve Docs') }}</button>
                    <button class="fbtn mini" style="background: var(--danger-tint); color: var(--danger); border: none" @click="reviewDoc(row, 'rejected')">{{ t('Reject Docs') }}</button>
                </div>

                <div style="display: flex; gap: 8px; flex-wrap: wrap">
                    <button v-if="row.status !== 'active'" class="fbtn mini" style="background: var(--success-tint); color: var(--success); border: none" @click="changeStatus(row, 'active')">{{ t('Activate') }}</button>
                    <button v-if="row.status === 'active'" class="fbtn mini" style="background: var(--warning-tint); color: var(--warning); border: none" @click="changeStatus(row, 'suspended')">{{ t('Suspend') }}</button>
                    <button v-if="row.status === 'pending'" class="fbtn mini" @click="changeStatus(row, 'rejected')">{{ t('Reject') }}</button>
                </div>
            </div>
        </div>
        <div v-else class="panel"><div class="empty">{{ t('No users found') }}</div></div>
    </AdminShell>
</template>

<style scoped>
.roster-role-filter { margin-bottom: 10px; }
.courier-role-label { display: block; margin-top: 4px; color: var(--primary-strong); font-size: 9px; font-weight: 900; }
</style>
