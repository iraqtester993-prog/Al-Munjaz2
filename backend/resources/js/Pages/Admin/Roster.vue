<script setup>
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
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
const detailsRow = ref(null)
const editingRow = ref(null)
const editForm = useForm({
    name: '',
    username: '',
    email: '',
    phone: '',
    shop_name: '',
    address: '',
    vehicle: '',
})

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

function reviewDoc(row, verdict, requestedDocumentId = null) {
    if (!row.docs || row.docs === 0) return
    const docId = requestedDocumentId || row.pendingDocs?.[0]
    if (!docId) return
    if (!confirm(t('Review this document?') + ' (' + (verdict === 'approved' ? t('Approve') : t('Reject')) + ')')) return
    router.post(route('admin.users.documents.review', [row.user.id, docId]), { status: verdict }, { preserveScroll: true })
}

function setMerchantVerification(row, verified) {
    if (!row.user || isCourier.value) return
    const action = verified ? t('Grant verification') : t('Remove verification')
    if (!confirm(`${action}: ${row.user.name}?`)) return
    router.post(route('admin.users.merchant-verification', row.user.id), { verified }, { preserveScroll: true })
}

function deleteAccount(row) {
    if (!row.user) return
    const message = `${t('Delete')} ${row.user.name}? ${t('This action is recoverable but is blocked while the account has open orders.')}`
    if (!confirm(message)) return
    router.delete(route('admin.users.destroy', row.user.id), { preserveScroll: true })
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

function provinceNames(row) {
    return (row.user?.provinces || [])
        .map((province) => province.name_ar || province.name_en || province.name_ku)
        .filter(Boolean)
        .join(' · ')
}

function openDetails(row) {
    detailsRow.value = row
}

function closeDetails() {
    detailsRow.value = null
}

function openEdit(row) {
    if (!row.user) return
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

                <div v-if="row.documents?.length" style="display: flex; gap: 8px; flex-wrap: wrap">
                    <button v-for="doc in row.documents" :key="doc.id" class="fbtn mini" style="border: none" @click="openDocument(doc)">{{ t('View Document') }} · {{ documentLabel(doc.type) }}</button>
                </div>
                <div v-if="row.docs > 0" style="display: flex; gap: 8px; margin-top: 8px">
                    <button class="fbtn mini" style="background: var(--success-tint); color: var(--success); border: none" @click="reviewDoc(row, 'approved')">{{ t('Approve Docs') }}</button>
                    <button class="fbtn mini" style="background: var(--danger-tint); color: var(--danger); border: none" @click="reviewDoc(row, 'rejected')">{{ t('Reject Docs') }}</button>
                </div>

                <div style="display: flex; gap: 8px; flex-wrap: wrap">
                    <button class="fbtn mini account-action" type="button" @click="openDetails(row)">{{ t('View Details') }}</button>
                    <button class="fbtn mini account-action" type="button" @click="openEdit(row)">{{ t('Edit') }}</button>
                    <button v-if="!isCourier && !row.verification?.verified" class="fbtn mini verification-action" type="button" @click="setMerchantVerification(row, true)">{{ t('Grant verification') }}</button>
                    <button v-if="!isCourier && row.verification?.verified" class="fbtn mini verification-remove" type="button" @click="setMerchantVerification(row, false)">{{ t('Remove verification') }}</button>
                    <button v-if="row.status !== 'active'" class="fbtn mini" style="background: var(--success-tint); color: var(--success); border: none" @click="changeStatus(row, 'active')">{{ t('Activate') }}</button>
                    <button v-if="row.status === 'active'" class="fbtn mini" style="background: var(--warning-tint); color: var(--warning); border: none" @click="changeStatus(row, 'suspended')">{{ t('Suspend') }}</button>
                    <button v-if="row.status === 'pending'" class="fbtn mini" @click="changeStatus(row, 'rejected')">{{ t('Reject') }}</button>
                    <button class="fbtn mini delete-account" type="button" @click="deleteAccount(row)">{{ t('Delete') }}</button>
                </div>
            </div>
        </div>
        <div v-else class="panel"><div class="empty">{{ t('No users found') }}</div></div>

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
                    <button class="fbtn mini account-action" type="button" @click="openEdit(detailsRow)">{{ t('Edit') }}</button>
                </div>

                <dl class="account-data">
                    <div><dt>{{ t('Phone') }}</dt><dd dir="ltr">{{ detailsRow.user?.phone || '—' }}</dd></div>
                    <div><dt>{{ t('Email') }}</dt><dd dir="ltr">{{ detailsRow.user?.email || '—' }}</dd></div>
                    <div><dt>{{ t('Governorate') }}</dt><dd>{{ provinceNames(detailsRow) || '—' }}</dd></div>
                    <div><dt>{{ t('Created at') }}</dt><dd dir="ltr">{{ detailsRow.user?.created_at || '—' }}</dd></div>
                    <div v-if="!isCourier"><dt>{{ t('Shop Name') }}</dt><dd>{{ detailsRow.user?.shop_name || '—' }}</dd></div>
                    <div v-if="isCourier"><dt>{{ t('Vehicle') }}</dt><dd>{{ vehicleLabel(detailsRow.user?.vehicle) }}</dd></div>
                    <div class="wide"><dt>{{ t('Address') }}</dt><dd>{{ detailsRow.user?.address || '—' }}</dd></div>
                    <div v-if="detailsRow.user?.identity_number" class="wide"><dt>{{ t('Identity Number') }}</dt><dd dir="ltr">{{ detailsRow.user.identity_number }}</dd></div>
                    <div v-if="!isCourier" class="wide"><dt>{{ t('Account Verification') }}</dt><dd><b class="detail-verification" :class="detailsRow.verification?.status">{{ detailsRow.verification?.verified ? t('Verified') : detailsRow.verification?.status === 'rejected' ? t('Rejected') : detailsRow.verification?.status === 'pending' ? t('Verification pending') : t('Not submitted') }}</b><small v-if="detailsRow.verification?.verified_by">{{ t('Verified by') }} · {{ detailsRow.verification.verified_by }}</small></dd></div>
                </dl>

                <section v-if="detailsRow.documents?.length" class="account-documents">
                    <h4>{{ t('Documents') }}</h4>
                    <div v-for="document in detailsRow.documents" :key="document.id" class="account-document-row">
                        <button type="button" @click="openDocument(document)">{{ documentLabel(document.type) }}</button>
                        <span :class="document.status">{{ documentStatus(document.status) }}</span>
                        <div v-if="document.status === 'pending'" class="account-document-actions"><button type="button" @click="reviewDoc(detailsRow, 'approved', document.id)">{{ t('Approve') }}</button><button type="button" @click="reviewDoc(detailsRow, 'rejected', document.id)">{{ t('Reject') }}</button></div>
                    </div>
                </section>

                <footer class="dialog-footer">
                    <button v-if="!isCourier && !detailsRow.verification?.verified" class="fbtn mini verification-action" type="button" @click="setMerchantVerification(detailsRow, true)">{{ t('Grant verification') }}</button>
                    <button v-if="!isCourier && detailsRow.verification?.verified" class="fbtn mini verification-remove" type="button" @click="setMerchantVerification(detailsRow, false)">{{ t('Remove verification') }}</button>
                    <button class="fbtn mini delete-account" type="button" @click="deleteAccount(detailsRow)">{{ t('Delete') }}</button>
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
.roster-role-filter { margin-bottom: 10px; }
.courier-role-label { display: block; margin-top: 4px; color: var(--primary-strong); font-size: 9px; font-weight: 900; }
.account-action { color: var(--primary-strong); background: var(--primary-tint); border: 0; }
.merchant-verified-badge { display: grid; width: 21px; height: 21px; place-items: center; flex: none; border-radius: 50%; color: #fff; background: #1d9bf0; font-size: 12px; font-weight: 900; }
.verification-action { border: 0; color: #075450; background: #d8f5e9; }.verification-remove { border: 0; color: var(--warning); background: var(--warning-tint); }.delete-account { border: 0; color: var(--danger); background: var(--danger-tint); }
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
.account-documents { margin: 0 18px 2px; padding: 14px 0; border-top: 1px solid var(--border); }.account-documents h4 { margin: 0 0 9px; font-size: 11px; }.account-document-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 7px 10px; align-items: center; padding: 9px 0; border-top: 1px solid var(--border); }.account-document-row > button { min-width: 0; overflow: hidden; padding: 0; border: 0; color: var(--primary-strong); background: transparent; font: 800 10px var(--font); text-align: start; text-overflow: ellipsis; white-space: nowrap; cursor: pointer; }.account-document-row > span { font-size: 9px; font-weight: 850; }.account-document-row > span.approved { color: var(--success); }.account-document-row > span.rejected { color: var(--danger); }.account-document-row > span.pending { color: var(--warning); }.account-document-actions { grid-column: 1 / -1; display: flex; gap: 7px; }.account-document-actions button { padding: 5px 8px; border: 0; border-radius: 7px; background: var(--surface-2); color: var(--primary-strong); font: 800 9px var(--font); cursor: pointer; }.account-document-actions button:last-child { color: var(--danger); }
.edit-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 12px; padding: 18px; }
.edit-grid label { display: grid; gap: 6px; color: var(--ink-soft); font-size: 10px; font-weight: 850; }
.edit-grid .wide { grid-column: 1 / -1; }
.edit-grid input,.edit-grid select,.edit-grid textarea { width: 100%; box-sizing: border-box; border: 1px solid var(--border); border-radius: 10px; outline: 0; padding: 10px; color: var(--ink); background: var(--surface-2); font: 700 12px var(--font); }
.edit-grid input:focus,.edit-grid select:focus,.edit-grid textarea:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-tint); }
.edit-grid textarea { resize: vertical; }
.form-error { margin: -4px 18px 0; color: var(--danger); font-size: 10px; font-weight: 800; }
.dialog-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 18px; border-top: 1px solid var(--border); }
.save-account { color: #062033; background: linear-gradient(135deg,var(--primary),#0ea5e9); border: 0; }
@media (max-width: 580px) { .dialog-backdrop { align-items: end; padding: 0; } .account-dialog { width: 100%; max-height: 92dvh; overflow-y: auto; border-radius: 18px 18px 0 0; } .account-data,.edit-grid { grid-template-columns: 1fr; } .account-data > div:nth-child(odd),.account-data > div:nth-child(even) { padding-inline: 0; } .dialog-footer { padding-bottom: max(14px, env(safe-area-inset-bottom)); } }
</style>
