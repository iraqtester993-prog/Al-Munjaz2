<script setup>
import { computed, ref, watch } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'
import BranchFilter from '../../Components/BranchFilter.vue'

const props = defineProps({
    branches: { type: Array, default: () => [] },
    accessUsers: { type: Array, default: () => [] },
    provinces: { type: Array, default: () => [] },
    dashboardPermissions: { type: Array, default: () => [] },
    canCreateBranches: { type: Boolean, default: false },
    canUpdateBranches: { type: Boolean, default: false },
    canManageBranches: { type: Boolean, default: false },
    canEditBranches: { type: Boolean, default: false },
    canChangeBranchStatus: { type: Boolean, default: false },
    canManageBranchAccess: { type: Boolean, default: false },
    canDeleteBranches: { type: Boolean, default: false },
    canViewBranchCashBalance: { type: Boolean, default: false },
    branchFilter: { type: Object, default: () => ({}) },
})
const page = usePage()
const modalOpen = ref(false)
const editing = ref(null)
const changingBranchId = ref(null)
const deletingBranchId = ref(null)
const actionError = ref('')
const accessModalBranch = ref(null)
const editingAccessAccount = ref(null)
const deleteBranch = ref(null)
const detailsBranch = ref(null)
const credentials = ref(page.props.flash?.branch_credentials || null)

const blankBranch = () => ({
    code: '',
    name_ar: '',
    name_en: '',
    name_ku: '',
    province_id: '',
    phone: '',
    email: '',
    address: '',
    access_username: '',
    access_email: '',
    access_email: '',
    access_password: '',
})

const form = useForm(blankBranch())
const accessForm = useForm({
    existing_user_id: '',
    access_name: '',
    access_phone: '',
    access_username: '',
    access_password: '',
    access_role: 'branch_manager',
    access_permissions: ['overview', 'orders', 'merchants', 'couriers', 'courier_locations', 'notifications'],
})
const formError = computed(() => Object.values(form.errors)[0] || '')
const accessError = computed(() => Object.values(accessForm.errors)[0] || '')
const selectedExistingAccessUser = computed(() => props.accessUsers.find((user) => String(user.id) === String(accessForm.existing_user_id)) || null)
const activeBranches = computed(() => props.branches.filter((branch) => branch.is_active).length)
const routeTotals = computed(() => props.branches.reduce((total, branch) => total + Number(branch.inbound_orders_count || 0) + Number(branch.outbound_orders_count || 0), 0))
const selectableProvinces = computed(() => props.provinces.filter((province) => {
    const isCurrentProvince = editing.value && String(province.id) === String(form.province_id)
    const isActive = province.is_active !== false && province.is_active !== 0 && province.is_active !== '0'

    return isActive || isCurrentProvince
}))
const editingInactiveProvince = computed(() => {
    if (!editing.value?.province_id) return null
    if (props.provinces.some((province) => String(province.id) === String(editing.value.province_id))) return null

    return editing.value.province || {
        id: editing.value.province_id,
        name_ar: editing.value.city || t('Inactive Governorate'),
    }
})

function locale() {
    return page.props.locale || 'ar'
}

function branchName(branch) {
    return branch[`name_${locale()}`] || branch.name_ar || branch.name_en || branch.name_ku || branch.code
}

function changeBranchFilter(branchId) {
    router.get(route('admin.branches'), branchId ? { branch_id: branchId } : {}, {
        preserveScroll: true,
        preserveState: false,
    })
}

function provinceName(province) {
    return province?.[`name_${locale()}`] || province?.name_ar || province?.name_en || province?.name_ku || ''
}

function credentialProvinceName(credentials) {
    if (typeof credentials?.province_name === 'string') return credentials.province_name
    if (typeof credentials?.province === 'string') return credentials.province
    if (credentials?.province && typeof credentials.province === 'object') return provinceName(credentials.province)

    return ''
}

function secondaryNames(branch) {
    const primary = branchName(branch)
    return [branch.name_ar, branch.name_en, branch.name_ku]
        .filter((name, index, names) => name && name !== primary && names.indexOf(name) === index)
        .join(' · ')
}

function openCreate() {
    if (!props.canCreateBranches) return
    editing.value = null
    actionError.value = ''
    form.clearErrors()
    Object.assign(form, blankBranch())
    modalOpen.value = true
}

function openEdit(branch) {
    if (!props.canEditBranches) return
    editing.value = branch
    actionError.value = ''
    form.clearErrors()
    Object.assign(form, {
        name_ar: branch.name_ar || '',
        name_en: branch.name_en || '',
        name_ku: branch.name_ku || '',
        code: branch.code || '',
        province_id: branch.province_id ? String(branch.province_id) : '',
        phone: branch.phone || '',
        email: branch.email || '',
        address: branch.address || '',
        access_name: '',
        access_phone: '',
        access_username: '',
        access_email: '',
        access_password: '',
        access_role: 'branch_manager',
        access_permissions: ['overview', 'orders', 'merchants', 'couriers', 'courier_locations', 'notifications'],
    })
    modalOpen.value = true
}

function closeModal() {
    modalOpen.value = false
    editing.value = null
    form.clearErrors()
}

function openDetails(branch) {
    detailsBranch.value = branch
}

function openAccess(branch) {
    if (!props.canManageBranchAccess) return
    accessModalBranch.value = branch
    editingAccessAccount.value = null
    accessForm.clearErrors()
    accessForm.reset()
    accessForm.existing_user_id = ''
    accessForm.access_role = 'branch_manager'
    accessForm.access_permissions = ['overview', 'orders', 'merchants', 'couriers', 'courier_locations', 'notifications']
}

function openEditAccess(branch, account) {
    if (!props.canManageBranchAccess) return
    accessModalBranch.value = branch
    editingAccessAccount.value = account
    accessForm.clearErrors()
    Object.assign(accessForm, {
        existing_user_id: '',
        access_name: account.name || '',
        access_phone: account.phone || '',
        access_username: account.username || '',
        access_email: account.email || '',
        access_password: '',
        access_role: account.role || 'branch_manager',
        access_permissions: account.permissions || [],
    })
}

function closeAccess() {
    accessModalBranch.value = null
    editingAccessAccount.value = null
    accessForm.reset()
    accessForm.clearErrors()
}

function syncExistingAccessRole() {
    if (!props.canManageBranchAccess) return
    if (selectedExistingAccessUser.value) {
        accessForm.access_role = selectedExistingAccessUser.value.role
        accessForm.access_permissions = selectedExistingAccessUser.value.permissions || []
    }
}

const permissionLabel = (permission) => ({
    overview: t('Overview'),
    orders: t('Orders'),
    merchants: t('Merchants'),
    couriers: t('Couriers'),
    courier_locations: t('Courier Locations'),
    notifications: t('Notifications'),
    finance: t('Finance'),
    settings: t('Settings'),
}[permission] || permission)

function enforceOwnerPermissions(target) {
    if (target.access_role === 'owner') target.access_permissions = [...props.dashboardPermissions]
}

function submitAccess() {
    if (!props.canManageBranchAccess || !accessModalBranch.value) return

    if (editingAccessAccount.value) {
        accessForm.put(route('admin.branches.access.update', [accessModalBranch.value.id, editingAccessAccount.value.id]), {
            preserveScroll: true,
            onSuccess: closeAccess,
        })
        return
    }

    accessForm.post(route('admin.branches.access.store', accessModalBranch.value.id), {
        preserveScroll: true,
        onSuccess: closeAccess,
    })
}

function copyCredential(value) {
    if (!value) return
    navigator.clipboard?.writeText(String(value)).catch(() => {})
}

watch(() => page.props.flash?.branch_credentials, (value) => {
    if ((value?.email || value?.username) && value?.password) credentials.value = value
})

function submit() {
    if (editing.value ? !props.canEditBranches : !props.canCreateBranches) return
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            closeModal()
            Object.assign(form, blankBranch())
        },
    }

    if (editing.value) {
        form.put(route('admin.branches.update', editing.value.id), options)
        return
    }

    form.post(route('admin.branches.store'), options)
}

function toggleStatus(branch) {
    if (!props.canChangeBranchStatus) return
    actionError.value = ''
    changingBranchId.value = branch.id

    router.patch(route('admin.branches.status', branch.id), {
        is_active: !branch.is_active,
    }, {
        preserveScroll: true,
        onError: (errors) => {
            actionError.value = errors.is_active || errors.branch || t('Unable to update branch status.')
        },
        onFinish: () => {
            changingBranchId.value = null
        },
    })
}

function requestDelete(branch) {
    if (!props.canDeleteBranches) return
    actionError.value = ''
    deleteBranch.value = branch
}

function destroyBranch() {
    if (!props.canDeleteBranches || !deleteBranch.value || deletingBranchId.value) return

    const branch = deleteBranch.value
    deletingBranchId.value = branch.id
    router.delete(route('admin.branches.destroy', branch.id), {
        preserveScroll: true,
        onSuccess: () => (deleteBranch.value = null),
        onError: (errors) => {
            actionError.value = errors.branch || t('Unable to delete branch.')
        },
        onFinish: () => (deletingBranchId.value = null),
    })
}
</script>

<template>
    <AdminShell :title="t('Branches')">
        <div class="section-head">
            <div>
                <div class="eyebrow">{{ activeBranches }} / {{ branches.length }} {{ t('Active') }}</div>
                <h2>{{ t('Branches') }}</h2>
                <p>{{ t('Manage branches and cashbox balances') }}</p>
            </div>
            <div class="head-actions">
                <BranchFilter v-if="branchFilter?.enabled" :filter="branchFilter" @change="changeBranchFilter" />
                <span class="route-total"><b>{{ routeTotals }}</b> {{ t('Branch route records') }}</span>
                <button v-if="canCreateBranches" class="btn primary" type="button" @click="openCreate">+ {{ t('New Branch') }}</button>
            </div>
        </div>

        <p v-if="actionError" class="page-error" role="alert">{{ actionError }}</p>

        <div class="branch-grid">
            <article v-for="branch in branches" :key="branch.id" class="branch-card" :class="{ inactive: !branch.is_active }">
                <div class="branch-head">
                    <div class="branch-title-row">
                        <span class="branch-icon" aria-hidden="true">⌂</span>
                        <div>
                            <h3>{{ branchName(branch) }}</h3>
                            <p class="branch-place">{{ branch.province?.[`name_${locale()}`] || branch.province?.name_ar || branch.city }} <span v-if="branch.code">· {{ branch.code }}</span></p>
                        </div>
                    </div>
                    <span class="state" :class="{ off: !branch.is_active }">{{ branch.is_active ? t('Active') : t('Inactive') }}</span>
                </div>

                <p v-if="secondaryNames(branch)" class="alternate-names">{{ secondaryNames(branch) }}</p>
                <div class="branch-contact">
                    <span v-if="branch.phone">{{ branch.phone }}</span>
                    <span v-if="branch.email" dir="ltr">{{ branch.email }}</span>
                    <span v-if="branch.address">{{ branch.address }}</span>
                </div>

                <div class="branch-flow" :aria-label="t('Branch route records')">
                    <div><span>{{ t('Outgoing Orders') }}</span><b>{{ branch.outbound_orders_count }}</b></div>
                    <div><span>{{ t('Incoming Orders') }}</span><b>{{ branch.inbound_orders_count }}</b></div>
                    <div><span>{{ t('Users') }}</span><b>{{ branch.users_count }}</b></div>
                </div>

                <div v-if="canViewBranchCashBalance" class="cash">
                    <span>{{ t('Cashbox Balance') }}</span>
                    <b class="mono">{{ fmt(branch.cash_balance) }} {{ t('IQD') }}</b>
                </div>

                <div v-if="canManageBranchAccess" class="access-summary">
                    <div>
                        <span>{{ t('Dashboard Accounts') }}</span>
                        <b>{{ branch.access_accounts?.length || 0 }}</b>
                    </div>
                    <small v-if="branch.access_accounts?.length">
                        {{ branch.access_accounts.map((account) => account.username).join(' · ') }}
                    </small>
                    <small v-else>{{ t('No scoped dashboard account yet') }}</small>
                </div>

                <div class="branch-actions">
                    <button class="btn ghost" type="button" @click="openDetails(branch)">{{ t('View Details') }}</button>
                    <button v-if="canEditBranches" class="btn ghost" type="button" @click="openEdit(branch)">{{ t('Edit') }}</button>
                    <button
                        v-if="canChangeBranchStatus"
                        class="btn status-action"
                        :class="branch.is_active ? 'danger' : 'primary'"
                        type="button"
                        :disabled="changingBranchId === branch.id"
                        @click="toggleStatus(branch)"
                    >
                        {{ branch.is_active ? t('Deactivate Branch') : t('Activate Branch') }}
                    </button>
                    <button v-if="canDeleteBranches" class="btn danger delete-action" type="button" @click="requestDelete(branch)">{{ t('Delete') }}</button>
                </div>
            </article>
            <div v-if="!branches.length" class="panel empty">{{ t('No branches yet. Add your first branch to start operations.') }}</div>
        </div>

        <div v-if="modalOpen && (editing ? canEditBranches : canCreateBranches)" class="modal-backdrop" @click.self="closeModal">
            <form class="branch-modal" @submit.prevent="submit">
                <header>
                    <div>
                        <span class="modal-kicker">{{ t('Platform Operations') }}</span>
                        <h3>{{ editing ? t('Edit Branch') : t('New Branch') }}</h3>
                    </div>
                    <button type="button" :aria-label="t('Close')" @click="closeModal">×</button>
                </header>

                <div class="form-grid">
                    <label>{{ t('Branch Name') }}<input v-model="form.name_ar" required maxlength="120" :placeholder="t('Main Baghdad Branch')" /></label>
                    <label>{{ t('Operating Governorate') }}
                    <PopupSelect v-model="form.province_id" required>
                        <option value="" disabled>{{ t('Choose governorate') }}</option>
                        <option v-if="editingInactiveProvince" :value="String(editingInactiveProvince.id)">{{ provinceName(editingInactiveProvince) }} · {{ t('Inactive') }}</option>
                        <option v-for="province in selectableProvinces" :key="province.id" :value="String(province.id)">{{ provinceName(province) }}</option>
                    </PopupSelect>
                    </label>
                </div>

                <div class="form-grid">
                    <label>{{ t('Branch Code') }}<input v-model="form.code" maxlength="20" dir="ltr" :placeholder="t('Generated if blank')" /></label>
                    <label>{{ t('Branch Email') }}<input v-model="form.email" type="email" maxlength="191" dir="ltr" /></label>
                </div>

                <div class="form-grid">
                    <label>{{ t('English Branch Name') }}<input v-model="form.name_en" maxlength="120" dir="ltr" /></label>
                    <label>{{ t('Kurdish Branch Name') }}<input v-model="form.name_ku" maxlength="120" /></label>
                </div>

                <div class="form-grid">
                    <label>{{ t('Phone') }}<input v-model="form.phone" required maxlength="30" inputmode="tel" placeholder="07xxxxxxxxx" /></label>
                    <label>{{ t('Address') }}<textarea v-model="form.address" required rows="2" maxlength="255" /></label>
                </div>

                <fieldset v-if="!editing" class="initial-access-fieldset">
                    <legend>{{ t('Branch Login Account') }}</legend>
                    <p class="access-note">{{ t('This account enters the branch-only dashboard and cannot access the main platform dashboard.') }} {{ t('After saving, a sign-in email and a temporary password are generated for this governorate. Save them when they appear; the password is shown once only.') }}</p>
                    <div class="form-grid">
                        <label>{{ t('Email (generated if blank)') }}<input v-model="form.access_email" type="email" maxlength="190" autocomplete="off" /></label>
                        <label>{{ t('Username (generated if blank)') }}<input v-model="form.access_username" maxlength="60" autocomplete="off" /></label>
                    </div>
                    <label>{{ t('Temporary Password (generated if blank)') }}<input v-model="form.access_password" type="password" minlength="8" maxlength="120" autocomplete="new-password" /></label>
                </fieldset>

                <p v-if="formError" class="error" role="alert">{{ formError }}</p>
                <footer>
                    <button class="btn ghost" type="button" @click="closeModal">{{ t('Cancel') }}</button>
                    <button class="btn primary" type="submit" :disabled="form.processing">{{ editing ? t('Update Branch') : t('Save Branch') }}</button>
                </footer>
            </form>
        </div>

        <div v-if="accessModalBranch && canManageBranchAccess" class="modal-backdrop" @click.self="closeAccess">
            <form class="branch-modal access-modal" @submit.prevent="submitAccess">
                <header>
                    <div>
                        <span class="modal-kicker">{{ t('Branch Dashboard Access') }}</span>
                        <h3>{{ branchName(accessModalBranch) }}</h3>
                    </div>
                    <button type="button" :aria-label="t('Close')" @click="closeAccess">×</button>
                </header>
                <p class="access-note">{{ t('The new account is restricted to this branch and has no access to platform-wide data.') }}</p>
                <label>{{ t('Link an existing dashboard account') }}
                    <PopupSelect v-model="accessForm.existing_user_id" @change="syncExistingAccessRole">
                        <option value="">{{ t('Create a new dashboard account') }}</option>
                        <option v-for="user in accessUsers" :key="user.id" :value="String(user.id)">
                            {{ user.name }} · {{ user.username }} · {{ user.role === 'owner' ? t('Branch Owner') : t('Branch Manager') }}
                        </option>
                    </PopupSelect>
                </label>
                <p v-if="selectedExistingAccessUser" class="access-note">
                    {{ t('The existing account keeps its role and gains access only to this selected branch.') }}
                </p>
                <template v-if="!selectedExistingAccessUser">
                    <div class="form-grid">
                        <label>{{ t('Account Holder Name') }}<input v-model="accessForm.access_name" required maxlength="120" /></label>
                        <label>{{ t('Phone') }}<input v-model="accessForm.access_phone" required maxlength="30" inputmode="tel" /></label>
                    </div>
                    <div class="form-grid">
                        <label>{{ editingAccessAccount ? t('Username') : t('Username (generated if blank)') }}<input v-model="accessForm.access_username" :required="Boolean(editingAccessAccount)" maxlength="60" autocomplete="off" /></label>
                        <label>{{ editingAccessAccount ? t('Login Email') : t('Email (generated if blank)') }}<input v-model="accessForm.access_email" :required="Boolean(editingAccessAccount)" type="email" maxlength="190" autocomplete="off" /></label>
                    </div>
                    <label>{{ editingAccessAccount ? t('New Password (leave blank to keep current password)') : t('Temporary Password (generated if blank)') }}<input v-model="accessForm.access_password" type="text" :minlength="editingAccessAccount ? 10 : undefined" maxlength="120" autocomplete="new-password" /></label>
                    <label>{{ t('Access Role') }}
                        <PopupSelect v-model="accessForm.access_role" @change="enforceOwnerPermissions(accessForm)">
                            <option value="branch_manager">{{ t('Branch Manager') }}</option>
                            <option value="owner">{{ t('Branch Owner') }}</option>
                        </PopupSelect>
                    </label>
                </template>
                <fieldset class="permissions-fieldset">
                    <legend>{{ t('Dashboard permissions') }}</legend>
                    <p class="access-note">{{ t('Owner accounts receive all permissions. Manager access is controlled by these switches.') }}</p>
                    <label v-for="permission in dashboardPermissions" :key="permission" class="permission-switch">
                        <input v-model="accessForm.access_permissions" type="checkbox" :value="permission" :disabled="accessForm.access_role === 'owner'" />
                        <span>{{ permissionLabel(permission) }}</span>
                    </label>
                </fieldset>
                <p v-if="accessError" class="error" role="alert">{{ accessError }}</p>
                <footer>
                    <button class="btn ghost" type="button" @click="closeAccess">{{ t('Cancel') }}</button>
                    <button class="btn primary" type="submit" :disabled="accessForm.processing">{{ editingAccessAccount ? t('Save Login Account') : selectedExistingAccessUser ? t('Grant Branch Access') : t('Create Account') }}</button>
                </footer>
            </form>
        </div>

        <div v-if="deleteBranch && canDeleteBranches" class="modal-backdrop" @click.self="deleteBranch = null">
            <section class="branch-modal delete-modal" role="dialog" aria-modal="true" :aria-label="t('Delete Branch')">
                <header>
                    <div>
                        <span class="modal-kicker">{{ t('Delete Branch') }}</span>
                        <h3>{{ branchName(deleteBranch) }}</h3>
                    </div>
                    <button type="button" :aria-label="t('Close')" @click="deleteBranch = null">×</button>
                </header>
                <p class="delete-copy">{{ t('This removes the branch from the dashboard and deactivates its cashboxes and branch access. Active orders must be moved or completed first.') }}</p>
                <footer>
                    <button class="btn ghost" type="button" @click="deleteBranch = null">{{ t('Cancel') }}</button>
                    <button class="btn danger" type="button" :disabled="deletingBranchId === deleteBranch.id" @click="destroyBranch">{{ t('Delete') }}</button>
                </footer>
            </section>
        </div>

        <div v-if="detailsBranch" class="modal-backdrop" @click.self="detailsBranch = null">
            <section class="branch-modal branch-detail-modal" role="dialog" aria-modal="true" :aria-label="t('View Details')">
                <header>
                    <div>
                        <span class="modal-kicker">{{ t('Branch Details') }}</span>
                        <h3>{{ branchName(detailsBranch) }}</h3>
                    </div>
                    <button type="button" :aria-label="t('Close')" @click="detailsBranch = null">×</button>
                </header>

                <dl class="branch-detail-grid">
                    <div><dt>{{ t('Operating Governorate') }}</dt><dd>{{ detailsBranch.province?.[`name_${locale()}`] || detailsBranch.province?.name_ar || detailsBranch.city || '—' }}</dd></div>
                    <div><dt>{{ t('Branch Code') }}</dt><dd dir="ltr">{{ detailsBranch.code || '—' }}</dd></div>
                    <div><dt>{{ t('Phone') }}</dt><dd dir="ltr">{{ detailsBranch.phone || '—' }}</dd></div>
                    <div><dt>{{ t('Status') }}</dt><dd :class="detailsBranch.is_active ? 'active-value' : 'inactive-value'">{{ detailsBranch.is_active ? t('Active') : t('Inactive') }}</dd></div>
                    <div class="wide"><dt>{{ t('Address') }}</dt><dd>{{ detailsBranch.address || '—' }}</dd></div>
                    <div><dt>{{ t('Outgoing Orders') }}</dt><dd>{{ detailsBranch.outbound_orders_count || 0 }}</dd></div>
                    <div><dt>{{ t('Incoming Orders') }}</dt><dd>{{ detailsBranch.inbound_orders_count || 0 }}</dd></div>
                    <div><dt>{{ t('Users') }}</dt><dd>{{ detailsBranch.users_count || 0 }}</dd></div>
                    <div v-if="canViewBranchCashBalance"><dt>{{ t('Cashbox Balance') }}</dt><dd class="money mono">{{ fmt(detailsBranch.cash_balance) }} {{ t('IQD') }}</dd></div>
                </dl>

                <section v-if="canManageBranchAccess" class="branch-access-list">
                    <div class="detail-section-head">
                        <div>
                            <span>{{ t('Dashboard Accounts') }}</span>
                            <small>{{ t('These accounts can enter only this branch dashboard.') }}</small>
                        </div>
                        <button v-if="detailsBranch.dashboard_login_url" class="copy-link" type="button" @click="copyCredential(detailsBranch.dashboard_login_url)">{{ t('Copy') }}</button>
                    </div>
                    <p v-if="detailsBranch.dashboard_login_url" class="login-url" dir="ltr">{{ detailsBranch.dashboard_login_url }}</p>
                    <div v-if="detailsBranch.access_accounts?.length" class="access-account-list">
                        <article v-for="account in detailsBranch.access_accounts" :key="account.id">
                            <b>{{ account.name }}</b>
                            <span dir="ltr">{{ account.username }}</span>
                            <span dir="ltr">{{ account.email || '—' }}</span>
                            <small>{{ account.role === 'owner' ? t('Branch Owner') : t('Branch Manager') }} · {{ account.status === 'active' ? t('Active') : t('Inactive') }}</small>
                            <button type="button" class="copy-link account-edit" @click="openEditAccess(detailsBranch, account)">{{ t('Edit Login') }}</button>
                        </article>
                    </div>
                    <p v-else class="access-note">{{ t('No scoped dashboard account yet') }}</p>
                </section>

                <footer>
                    <button class="btn primary" type="button" @click="detailsBranch = null">{{ t('Close') }}</button>
                </footer>
            </section>
        </div>

        <div v-if="credentials && (canCreateBranches || canManageBranchAccess)" class="credential-backdrop" @click.self="credentials = null">
            <section class="credentials-card" role="dialog" aria-modal="true" :aria-label="t('New branch account credentials')">
                <span class="credential-kicker">{{ t('Save these credentials now') }}</span>
                <h3>{{ t('Branch dashboard access created') }}</h3>
                <p>{{ credentials.branch_name }}<template v-if="credentialProvinceName(credentials)"> · {{ credentialProvinceName(credentials) }}</template> · {{ credentials.role === 'owner' ? t('Branch Owner') : t('Branch Manager') }}</p>
                <div class="credential-row"><span>{{ t('Dashboard URL') }}</span><b dir="ltr">{{ credentials.login_url }}</b><button type="button" @click="copyCredential(credentials.login_url)">{{ t('Copy') }}</button></div>
                <div v-if="credentials.email" class="credential-row"><span>{{ t('Email') }}</span><b dir="ltr">{{ credentials.email }}</b><button type="button" @click="copyCredential(credentials.email)">{{ t('Copy') }}</button></div>
                <div v-if="credentials.username" class="credential-row"><span>{{ t('Username') }}</span><b dir="ltr">{{ credentials.username }}</b><button type="button" @click="copyCredential(credentials.username)">{{ t('Copy') }}</button></div>
                <div class="credential-row"><span>{{ t('Temporary Password') }}</span><b dir="ltr">{{ credentials.password }}</b><button type="button" @click="copyCredential(credentials.password)">{{ t('Copy') }}</button></div>
                <p class="credentials-warning">{{ t('For security, the password is shown once only. Ask the account holder to change it after first sign-in.') }}</p>
                <button class="btn primary credentials-close" type="button" @click="credentials = null">{{ t('I saved the credentials') }}</button>
            </section>
        </div>
    </AdminShell>
</template>

<style scoped>
.section-head{display:flex;justify-content:space-between;align-items:end;gap:16px;margin-bottom:20px}.eyebrow,.modal-kicker,.credential-kicker{color:var(--primary-strong);font-size:10px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.section-head h2{margin:4px 0 0;font-size:22px}.section-head p{margin:4px 0 0;color:var(--ink-faint);font-size:12px}.head-actions{display:flex;align-items:center;gap:10px}.route-total{font-size:11px;color:var(--ink-faint);white-space:nowrap}.route-total b{color:var(--primary-strong)}.btn{border:0;border-radius:10px;padding:9px 13px;font:inherit;font-size:12px;font-weight:800;cursor:pointer;transition:transform .18s ease,opacity .18s ease}.btn:hover:not(:disabled){transform:translateY(-1px)}.btn:disabled{cursor:wait;opacity:.65}.primary{background:var(--primary);color:#fff}.ghost{background:var(--surface-2);border:1px solid var(--border);color:var(--ink)}.danger{background:var(--danger-tint);color:var(--danger)}.page-error{margin:-6px 0 16px;padding:10px 12px;border-radius:10px;background:var(--danger-tint);color:var(--danger);font-size:12px;font-weight:700}.branch-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:16px}.branch-card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:17px;box-shadow:var(--shadow);display:flex;flex-direction:column;min-height:330px;transition:border-color .18s ease,opacity .18s ease}.branch-card.inactive{opacity:.78}.branch-head{display:flex;justify-content:space-between;gap:10px}.branch-title-row{display:flex;gap:10px;min-width:0}.branch-icon{width:38px;height:38px;flex:0 0 auto;display:grid;place-items:center;border-radius:11px;background:var(--primary-tint);color:var(--primary-strong);font-weight:900;font-size:19px}.branch-card h3{margin:1px 0 3px;font-size:15px;line-height:1.3}.branch-place{margin:0;color:var(--ink-faint);font-size:11px}.state{font-size:10px;font-weight:800;color:var(--success);background:var(--success-tint);padding:5px 8px;border-radius:20px;height:max-content;white-space:nowrap}.state.off{color:var(--danger);background:var(--danger-tint)}.alternate-names{min-height:17px;margin:12px 0 0;color:var(--ink-faint);font-size:10px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.branch-contact{display:flex;flex-direction:column;gap:3px;min-height:35px;margin-top:8px;color:var(--ink-soft);font-size:11px;overflow:hidden}.branch-contact span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.branch-flow{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin:16px 0 13px}.branch-flow>div{padding:8px 7px;border-radius:10px;background:var(--surface-2);min-width:0}.branch-flow span{display:block;color:var(--ink-faint);font-size:9px;line-height:1.2}.branch-flow b{display:block;margin-top:4px;color:var(--ink);font-size:15px}.cash{border-top:1px solid var(--border);padding-top:11px;display:flex;justify-content:space-between;gap:10px;font-size:11px;color:var(--ink-faint)}.cash b{color:var(--primary-strong);font-size:12px;white-space:nowrap}.access-summary{min-height:42px;display:grid;gap:3px;margin-top:11px;padding:9px 10px;border-radius:10px;background:var(--surface-2)}.access-summary>div{display:flex;justify-content:space-between;gap:8px;color:var(--ink-faint);font-size:10px;font-weight:800}.access-summary b{color:var(--primary-strong);font-size:12px}.access-summary small{overflow:hidden;color:var(--ink-soft);font-size:9px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}.branch-actions{display:flex;gap:8px;margin-top:auto;padding-top:15px}.branch-actions .btn{flex:1}.access-button{color:var(--primary-strong)}.status-action{font-size:11px}.modal-backdrop,.credential-backdrop{position:fixed;inset:0;background:#0a121180;display:grid;place-items:center;z-index:99;padding:20px;overflow:auto}.branch-modal{width:min(560px,100%);background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:21px;display:grid;gap:14px;box-shadow:0 24px 72px #0004}.branch-modal header{display:flex;justify-content:space-between;align-items:start;gap:14px}.branch-modal header h3{margin:4px 0 0;font-size:18px}.branch-modal header button{width:32px;height:32px;border:0;border-radius:9px;background:var(--surface-2);color:var(--ink);font-size:22px;line-height:1;cursor:pointer}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.branch-modal label{font-size:11px;font-weight:800;display:grid;gap:6px;color:var(--ink-soft)}.branch-modal input,.branch-modal textarea,.branch-modal select{width:100%;box-sizing:border-box;font:inherit;font-size:13px;color:var(--ink);border:1px solid var(--border);border-radius:10px;padding:10px;background:var(--surface-2);outline:none}.branch-modal input:focus,.branch-modal textarea:focus,.branch-modal select:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-tint)}fieldset{border:1px solid var(--border);border-radius:12px;padding:12px;display:grid;gap:10px}legend{padding:0 5px;color:var(--ink-faint);font-size:10px;font-weight:900}.access-fieldset{background:var(--surface-2)}.access-toggle{display:flex!important;grid-template-columns:auto 1fr;align-items:center;gap:8px}.access-toggle input{width:16px!important;height:16px;padding:0}.permissions-fieldset{grid-template-columns:repeat(2,minmax(0,1fr));background:var(--surface-2)}.permissions-fieldset .access-note{grid-column:1/-1}.permission-switch{display:flex!important;grid-template-columns:auto 1fr;align-items:center;gap:8px;padding:7px 8px;border-radius:9px;background:var(--surface);font-size:10px!important}.permission-switch input{appearance:none;width:32px!important;height:18px;padding:0!important;margin:0;border-radius:999px!important;background:#9baead!important;position:relative;cursor:pointer}.permission-switch input::after{content:'';position:absolute;top:2px;left:2px;width:14px;height:14px;border-radius:50%;background:#fff;transition:transform .18s}.permission-switch input:checked{background:var(--primary)!important}.permission-switch input:checked::after{transform:translateX(14px)}.permission-switch input:disabled{opacity:.72;cursor:not-allowed}.access-note{margin:0;color:var(--ink-faint);font-size:10px;line-height:1.65}.error{color:var(--danger);margin:0;font-size:12px;font-weight:700}footer{display:flex;justify-content:flex-end;gap:8px}.empty{padding:30px}.credentials-card{width:min(470px,100%);padding:23px;border:1px solid var(--border);border-radius:20px;background:var(--surface);box-shadow:0 26px 78px #0005}.credentials-card h3{margin:5px 0 3px;font-size:18px}.credentials-card>p{margin:0 0 14px;color:var(--ink-faint);font-size:11px}.credential-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:4px 10px;align-items:center;margin-top:9px;padding:10px;border:1px solid var(--border);border-radius:11px;background:var(--surface-2)}.credential-row span{grid-column:1/-1;color:var(--ink-faint);font-size:9px;font-weight:800}.credential-row b{overflow:hidden;color:var(--ink);font-size:11.5px;text-overflow:ellipsis;white-space:nowrap}.credential-row button{padding:5px 8px;border:0;border-radius:7px;color:var(--primary-strong);background:var(--primary-tint);font:inherit;font-size:9px;font-weight:900}.credentials-warning{margin-top:13px!important;color:var(--warning)!important;line-height:1.65}.credentials-close{width:100%;margin-top:6px}@media(max-width:620px){.section-head{align-items:stretch;flex-direction:column}.head-actions{justify-content:space-between}.branch-grid{grid-template-columns:1fr}.form-grid,.permissions-fieldset{grid-template-columns:1fr}.branch-modal{margin:auto;padding:17px}.route-total{white-space:normal}.branch-actions{flex-wrap:wrap}.branch-actions .btn{min-width:calc(50% - 5px)}.status-action{width:100%}}
.auto-access-note{display:flex;align-items:flex-start;gap:10px;padding:12px;border:1px solid rgba(12,125,116,.22);border-radius:12px;background:var(--primary-tint)}.auto-access-note>span{display:grid;width:28px;height:28px;flex:none;place-items:center;border-radius:8px;color:var(--primary-strong);background:var(--surface);font-size:15px;font-weight:900}.auto-access-note b{display:block;color:var(--primary-strong);font-size:11px}.auto-access-note p{margin:3px 0 0;color:var(--ink-soft);font-size:10px;font-weight:700;line-height:1.65}
.delete-action{flex:0 0 auto!important}.delete-copy{margin:0;color:var(--ink-faint);font-size:11px;font-weight:700;line-height:1.75}
.branch-detail-modal{max-height:calc(100dvh - 40px);overflow:auto}.branch-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin:0}.branch-detail-grid>div{min-width:0;padding:10px;border:1px solid var(--border);border-radius:11px;background:var(--surface-2)}.branch-detail-grid .wide{grid-column:1/-1}.branch-detail-grid dt{color:var(--ink-faint);font-size:9px;font-weight:900}.branch-detail-grid dd{margin:5px 0 0;overflow-wrap:anywhere;color:var(--ink);font-size:12px;font-weight:800;line-height:1.5}.branch-detail-grid .active-value{color:var(--success)}.branch-detail-grid .inactive-value{color:var(--danger)}.branch-access-list{display:grid;gap:9px;padding:12px;border:1px solid var(--border);border-radius:13px;background:var(--surface-2)}.detail-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.detail-section-head span{display:block;color:var(--ink);font-size:12px;font-weight:900}.detail-section-head small{display:block;margin-top:3px;color:var(--ink-faint);font-size:9px;font-weight:700;line-height:1.45}.copy-link{border:0;border-radius:8px;padding:6px 8px;color:var(--primary-strong);background:var(--primary-tint);font:inherit;font-size:9px;font-weight:900;cursor:pointer}.login-url{margin:0;padding:8px;border-radius:8px;overflow:hidden;color:var(--ink-soft);background:var(--surface);font-size:10px;font-weight:800;text-overflow:ellipsis;white-space:nowrap}.access-account-list{display:grid;gap:7px}.access-account-list article{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:2px 8px;padding:9px;border:1px solid var(--border);border-radius:10px;background:var(--surface)}.access-account-list b{overflow:hidden;color:var(--ink);font-size:11px;text-overflow:ellipsis;white-space:nowrap}.access-account-list span{overflow:hidden;color:var(--ink-soft);font-size:9px;font-weight:800;text-overflow:ellipsis;white-space:nowrap}.access-account-list small{grid-column:1/-1;color:var(--ink-faint);font-size:8.5px;font-weight:800}@media(max-width:620px){.branch-detail-grid{grid-template-columns:1fr}.branch-detail-grid .wide{grid-column:auto}.access-account-list article{grid-template-columns:1fr}}
.account-edit{justify-self:start;margin-top:4px}
</style>
