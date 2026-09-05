<script setup>
import { computed, reactive, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'
import BranchFilter from '../../Components/BranchFilter.vue'

const props = defineProps({
    employees: { type: Array, default: () => [] },
    profiles: { type: Array, default: () => [] },
    permissionModules: { type: Array, default: () => [] },
    canManageEmployees: { type: Boolean, default: false },
    branchAudit: { type: Boolean, default: false },
    branchFilter: { type: Object, default: () => ({}) },
})

const page = usePage()
const locale = computed(() => page.props.locale || 'ar')
const currentUser = computed(() => page.props.auth?.user || {})
const query = ref('')
const modal = ref(null)
const selected = ref(null)
const busy = ref(false)
const formErrors = ref({})
const canManage = computed(() => props.canManageEmployees)

const createForm = reactive({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    permission_profile_id: '',
})

const employeeForm = reactive({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    permission_profile_id: '',
})

const copy = {
    ar: {
        title: 'موظفو النظام', eyebrow: 'إدارة الوصول للنظام', subtitle: 'أنشئ حسابات موظفي لوحة التحكم وحدد لكل موظف ملف الصلاحيات المناسب له. لن يرى الموظف أو يدير إلا الأقسام والإجراءات الممنوحة له.',
        add: 'إضافة موظف نظام', search: 'ابحث بالاسم أو البريد الإلكتروني', employees: 'موظفو النظام', noEmployees: 'لا توجد حسابات لموظفي النظام حتى الآن.',
        loginHint: 'يدخل الموظف من رابط لوحة التحكم نفسه باستخدام بريده الإلكتروني وكلمة المرور، ثم يُوجّه تلقائياً إلى الأقسام المسموح بها فقط.',
        branchAudit: 'هذا عرض مراجعة لموظفي الفرع وصلاحياتهم فقط. لإدارة موظفي الفرع، استخدم لوحة ذلك الفرع.',
        profile: 'ملف الصلاحيات', noProfile: 'بدون صلاحية — لا يمكنه الدخول إلى الداش', fullAccess: 'صاحب المنصة', active: 'نشط', suspended: 'معطّل', lastActive: 'آخر نشاط',
        edit: 'تعديل', disable: 'تعطيل', activate: 'تفعيل', delete: 'حذف', deleteConfirm: 'هل تريد حذف هذا الموظف؟ لن يستطيع الدخول بعد الحذف.', accountProtected: 'هذا الحساب محمي.',
        createTitle: 'إضافة موظف نظام', createHelp: 'اختر ملف الصلاحيات وكلمة مرور آمنة. يتم إنشاء الحساب مباشرة وجاهز للدخول.',
        editTitle: 'تعديل موظف النظام', editHelp: 'اترك حقلي كلمة المرور فارغين إذا لم ترغب بتغييرها.',
        name: 'اسم الموظف', email: 'البريد الإلكتروني', password: 'كلمة المرور', passwordConfirmation: 'تأكيد كلمة المرور', passwordHelp: 'ثمانية أحرف على الأقل.',
        saveCreate: 'إنشاء الموظف', save: 'حفظ التعديل', cancel: 'إلغاء', chooseProfile: 'اختر ملف الصلاحيات', createProfileFirst: 'أنشئ ملف صلاحيات أولًا', permissions: 'الصلاحيات الممنوحة', permissionCount: 'إجراء ممنوح', statusConfirm: 'تأكيد تغيير حالة الموظف؟', required: 'أكمل الحقول المطلوبة.', passwordMismatch: 'كلمتا المرور غير متطابقتين.', deleteSelf: 'لا يمكن حذف الحساب الذي تستخدمه الآن.',
    },
    en: {
        title: 'System Employees', eyebrow: 'System access management', subtitle: 'Create dashboard employee accounts and assign the appropriate permission profile to each employee. Employees can see and manage only the granted areas and actions.',
        add: 'Add system employee', search: 'Search by name or email', employees: 'System employees', noEmployees: 'There are no system employee accounts yet.',
        loginHint: 'Employees use the same dashboard sign-in link with their email and password, then are sent only to the sections they are allowed to use.',
        branchAudit: 'This is a read-only review of the branch staff and their permissions. Manage branch staff from that branch dashboard.',
        profile: 'Permission profile', noProfile: 'No profile — dashboard access is denied', fullAccess: 'Platform owner', active: 'Active', suspended: 'Disabled', lastActive: 'Last active',
        edit: 'Edit', disable: 'Disable', activate: 'Activate', delete: 'Delete', deleteConfirm: 'Delete this employee? They will no longer be able to sign in.', accountProtected: 'This account is protected.',
        createTitle: 'Add system employee', createHelp: 'Choose a permission profile and a secure password. The account is created immediately and ready to sign in.',
        editTitle: 'Edit system employee', editHelp: 'Leave the password fields blank to keep the current password.',
        name: 'Employee name', email: 'Email', password: 'Password', passwordConfirmation: 'Confirm password', passwordHelp: 'At least eight characters.',
        saveCreate: 'Create employee', save: 'Save changes', cancel: 'Cancel', chooseProfile: 'Choose permission profile', createProfileFirst: 'Create a permission profile first', permissions: 'Granted permissions', permissionCount: 'Granted actions', statusConfirm: 'Confirm employee status change?', required: 'Complete the required fields.', passwordMismatch: 'The passwords do not match.', deleteSelf: 'You cannot delete the account currently in use.',
    },
    ku: {
        title: 'کارمەندانی سیستەم', eyebrow: 'بەڕێوەبردنی دەستگەیشتنی سیستەم', subtitle: 'هەژماری کارمەندانی داشبۆرد دروست بکە و بۆ هەر کارمەندێک پڕۆفایلی دەسەڵاتی گونجاو دیاری بکە. تەنها بەش و کردارە پێدراوەکان دەبینن و بەڕێوە دەبەن.',
        add: 'زیادکردنی کارمەندی سیستەم', search: 'بە ناو یان ئیمەیڵ بگەڕێ', employees: 'کارمەندانی سیستەم', noEmployees: 'هێشتا هیچ هەژماری کارمەندی سیستەم نییە.',
        loginHint: 'کارمەند بە هەمان بەستەری چوونەژووری داشبۆرد، بە ئیمەیڵ و وشەی نهێنی دەچێتەژوورەوە و تەنها بۆ بەشە ڕێگەپێدراوەکان ئاراستە دەکرێت.',
        branchAudit: 'ئەمە تەنها پیشاندانی پێداچوونەوەی کارمەند و دەسەڵاتەکانی لقە. بۆ بەڕێوەبردنی کارمەندانی لق، داشبۆردی هەمان لق بەکاربهێنە.',
        profile: 'پڕۆفایلی دەسەڵات', noProfile: 'بێ پڕۆفایل — چوونەژووری داشبۆرد ڕەتدەکرێتەوە', fullAccess: 'خاوەنی پلاتفۆرم', active: 'چالاک', suspended: 'ناچالاک', lastActive: 'دوا چالاکی',
        edit: 'دەستکاریکردن', disable: 'ناچالاککردن', activate: 'چالاککردن', delete: 'سڕینەوە', deleteConfirm: 'ئەم کارمەندە بسڕیتەوە؟ ناتوانێت بچێتەژوورەوە.', accountProtected: 'ئەم هەژمارە پارێزراوە.',
        createTitle: 'زیادکردنی کارمەندی سیستەم', createHelp: 'پڕۆفایلی دەسەڵات و وشەی نهێنییەکی پارێزراو هەڵبژێرە. هەژمارەکە یەکسەر ئامادەی چوونەژوورەوە دەبێت.',
        editTitle: 'دەستکاریکردنی کارمەندی سیستەم', editHelp: 'ئەگەر ناتەوێت وشەی نهێنی بگۆڕیت، خانەکانی بەتاڵ بهێڵەوە.',
        name: 'ناوی کارمەند', email: 'ئیمەیڵ', password: 'وشەی نهێنی', passwordConfirmation: 'دووپاتکردنەوەی وشەی نهێنی', passwordHelp: 'لانیکەم هەشت پیت.',
        saveCreate: 'دروستکردنی کارمەند', save: 'پاشەکەوتکردنی گۆڕانکاری', cancel: 'هەڵوەشاندنەوە', chooseProfile: 'پڕۆفایلی دەسەڵات هەڵبژێرە', createProfileFirst: 'سەرەتا پڕۆفایلی دەسەڵات دروست بکە', permissions: 'دەسەڵاتە پێدراوەکان', permissionCount: 'کرداری پێدراو', statusConfirm: 'دڵنیایت لە گۆڕینی دۆخی کارمەند؟', required: 'خانە پێویستەکان پڕ بکە.', passwordMismatch: 'وشە نهێنییەکان یەکسان نین.', deleteSelf: 'ناتوانیت هەژماری ئێستا بەکارهاتوو بسڕیتەوە.',
    },
}

function l(key) {
    return copy[locale.value]?.[key] || copy.ar[key] || key
}

function formatDate(value) {
    if (!value) return '—'
    try {
        return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-IQ' : locale.value === 'ku' ? 'ku-IQ' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
    } catch {
        return value
    }
}

function statusLabel(status) {
    return status === 'active' ? l('active') : l('suspended')
}

function permissionCount(profile) {
    return Object.values(profile?.permissions || {}).reduce((total, actions) => total + (Array.isArray(actions) ? actions.length : 0), 0)
}

function employeeProfile(employee) {
    return employee.permission_profile || props.profiles.find((profile) => Number(profile.id) === Number(employee.permission_profile_id)) || null
}

const modulesByKey = computed(() => Object.fromEntries(props.permissionModules.map((module) => [module.key, module])))

function permissionItems(employee) {
    if (employee.is_super_admin) return []

    return Object.entries(employee.effective_permissions || employeeProfile(employee)?.permissions || {})
        .filter(([, actions]) => Array.isArray(actions) && actions.length)
        .map(([key, actions]) => {
            const module = modulesByKey.value[key] || {}
            const moduleName = module[`name_${locale.value}`] || module.name || key
            const actionNames = actions
                .map((action) => module.action_labels?.[action]?.[locale.value] || module.action_labels?.[action]?.ar || action)
                .join('، ')

            return { key, moduleName, actionNames }
        })
}

const filteredEmployees = computed(() => {
    const needle = query.value.trim().toLowerCase()
    if (!needle) return props.employees

    return props.employees.filter((employee) => [employee.name, employee.email]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(needle)))
})

function resetCreate() {
    createForm.name = ''
    createForm.email = ''
    createForm.password = ''
    createForm.password_confirmation = ''
    createForm.permission_profile_id = ''
    formErrors.value = {}
}

function openCreate() {
    if (!canManage.value) return
    resetCreate()
    modal.value = 'create'
}

function openEdit(employee) {
    if (!canManage.value || employee.is_protected_manager) return
    selected.value = employee
    employeeForm.name = employee.name || ''
    employeeForm.email = employee.email || ''
    employeeForm.password = ''
    employeeForm.password_confirmation = ''
    employeeForm.permission_profile_id = employee.permission_profile_id || ''
    formErrors.value = {}
    modal.value = 'edit'
}

function closeModal() {
    modal.value = null
    selected.value = null
    formErrors.value = {}
}

function firstError() {
    for (const value of Object.values(formErrors.value || {})) {
        if (Array.isArray(value) && value[0]) return value[0]
        if (typeof value === 'string' && value) return value
    }

    return ''
}

function passwordsMatch(form) {
    if (form.password !== form.password_confirmation) {
        formErrors.value = { form: l('passwordMismatch') }
        return false
    }

    return true
}

function submitCreate() {
    if (!canManage.value || busy.value) return
    if (!createForm.name.trim() || !createForm.email.trim() || !createForm.password || !createForm.password_confirmation || !createForm.permission_profile_id) {
        formErrors.value = { form: l('required') }
        return
    }
    if (!passwordsMatch(createForm)) return

    busy.value = true
    formErrors.value = {}
    router.post(route('admin.employees.store'), {
        name: createForm.name.trim(),
        email: createForm.email.trim(),
        password: createForm.password,
        password_confirmation: createForm.password_confirmation,
        permission_profile_id: Number(createForm.permission_profile_id),
    }, {
        preserveScroll: true,
        onSuccess: closeModal,
        onError: (errors) => { formErrors.value = errors || {} },
        onFinish: () => { busy.value = false },
    })
}

function submitEdit() {
    if (!canManage.value || !selected.value || selected.value.is_protected_manager || busy.value) return
    if (!employeeForm.name.trim() || !employeeForm.email.trim() || (!selected.value.is_super_admin && !employeeForm.permission_profile_id)) {
        formErrors.value = { form: l('required') }
        return
    }
    if ((employeeForm.password || employeeForm.password_confirmation) && !passwordsMatch(employeeForm)) return

    const payload = {
        name: employeeForm.name.trim(),
        email: employeeForm.email.trim(),
        permission_profile_id: selected.value.is_super_admin ? null : Number(employeeForm.permission_profile_id),
    }
    if (employeeForm.password) {
        payload.password = employeeForm.password
        payload.password_confirmation = employeeForm.password_confirmation
    }

    busy.value = true
    formErrors.value = {}
    router.put(route('admin.employees.update', selected.value.id), payload, {
        preserveScroll: true,
        onSuccess: closeModal,
        onError: (errors) => { formErrors.value = errors || {} },
        onFinish: () => { busy.value = false },
    })
}

function setStatus(employee, status) {
    if (!canManage.value || employee.is_protected_manager || !employee.can_change_status || busy.value || !window.confirm(l('statusConfirm'))) return
    busy.value = true
    router.patch(route('admin.employees.status', employee.id), { status }, {
        preserveScroll: true,
        onFinish: () => { busy.value = false },
    })
}

function deleteEmployee(employee) {
    if (!canManage.value || employee.is_protected_manager || !employee.can_delete || busy.value) return
    if (employee.is_current_user || Number(employee.id) === Number(currentUser.value?.id)) {
        window.alert(l('deleteSelf'))
        return
    }
    if (!window.confirm(l('deleteConfirm'))) return

    busy.value = true
    router.delete(route('admin.employees.destroy', employee.id), {
        preserveScroll: true,
        onFinish: () => { busy.value = false },
    })
}

function changeBranchFilter(branchId) {
    closeModal()
    router.get(route('admin.employees'), branchId ? { branch_id: branchId } : {}, {
        preserveScroll: true,
        preserveState: false,
        replace: true,
    })
}
</script>

<template>
    <AdminShell :title="l('title')">
        <header class="employees-heading">
            <div>
                <p>{{ l('eyebrow') }}</p>
                <h2>{{ l('title') }}</h2>
                <span>{{ l('subtitle') }}</span>
            </div>
            <div class="employees-heading-actions">
                <BranchFilter :filter="branchFilter" @change="changeBranchFilter" />
                <button v-if="canManage" class="btn primary" type="button" @click="openCreate">＋ {{ l('add') }}</button>
            </div>
        </header>

        <section class="login-note" role="note">
            <span aria-hidden="true">↗</span>
            <p>{{ l('loginHint') }}</p>
        </section>

        <section v-if="branchAudit" class="branch-audit-note" role="note">
            <span aria-hidden="true">◌</span>
            <p>{{ l('branchAudit') }}</p>
        </section>

        <section class="employee-panel">
            <header class="panel-heading">
                <div><h3>{{ l('employees') }}</h3><span>{{ filteredEmployees.length }} / {{ employees.length }}</span></div>
                <label class="employee-search"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input v-model="query" :placeholder="l('search')"></label>
            </header>

            <div v-if="filteredEmployees.length" class="employees-list">
                <article v-for="employee in filteredEmployees" :key="employee.id" class="employee-row" :class="{ disabled: employee.status !== 'active' }">
                    <div class="employee-avatar">{{ employee.name?.charAt(0) || 'م' }}</div>
                    <div class="employee-main">
                        <div class="employee-name"><b>{{ employee.name }}</b><span v-if="employee.is_super_admin" class="owner-chip">{{ l('fullAccess') }}</span></div>
                        <span dir="ltr">{{ employee.email }}</span>
                        <small>{{ l('lastActive') }}: {{ formatDate(employee.last_active_at) }}</small>
                    </div>
                    <div class="employee-access">
                        <template v-if="employee.is_super_admin"><b>{{ l('fullAccess') }}</b><small>{{ l('permissions') }}: ∞</small></template>
                        <template v-else-if="employeeProfile(employee)">
                            <b>{{ employeeProfile(employee).name }}</b>
                            <small>{{ l('permissionCount') }}: {{ permissionCount(employeeProfile(employee)) }}</small>
                            <div class="permission-chips">
                                <span v-for="permission in permissionItems(employee)" :key="permission.key">{{ permission.moduleName }}: {{ permission.actionNames }}</span>
                            </div>
                        </template>
                        <template v-else><b class="no-profile">{{ l('noProfile') }}</b></template>
                    </div>
                    <span class="status" :class="employee.status === 'active' ? 'active' : 'suspended'">{{ statusLabel(employee.status) }}</span>
                    <div v-if="canManage && !employee.is_protected_manager" class="employee-actions">
                        <button type="button" @click="openEdit(employee)">{{ l('edit') }}</button>
                        <button v-if="employee.can_change_status && employee.status === 'active'" type="button" class="warning" @click="setStatus(employee, 'suspended')">{{ l('disable') }}</button>
                        <button v-else-if="employee.can_change_status" type="button" class="success" @click="setStatus(employee, 'active')">{{ l('activate') }}</button>
                        <button v-if="employee.can_delete" type="button" class="danger" @click="deleteEmployee(employee)">{{ l('delete') }}</button>
                    </div>
                </article>
            </div>
            <div v-else class="empty-state">{{ l('noEmployees') }}</div>
        </section>

        <div v-if="modal" class="modal-backdrop" @mousedown.self="closeModal">
            <form v-if="modal === 'create'" class="modal-card" @submit.prevent="submitCreate">
                <header><div><h3>{{ l('createTitle') }}</h3><p>{{ l('createHelp') }}</p></div><button type="button" @click="closeModal">×</button></header>
                <div class="form-grid">
                    <label><span>{{ l('name') }}</span><input v-model.trim="createForm.name" required autocomplete="name"></label>
                    <label><span>{{ l('email') }}</span><input v-model.trim="createForm.email" type="email" dir="ltr" required autocomplete="email"></label>
                    <label class="wide"><span>{{ l('profile') }}</span><PopupSelect v-model="createForm.permission_profile_id" required><option value="" disabled>{{ profiles.length ? l('chooseProfile') : l('createProfileFirst') }}</option><option v-for="profile in profiles" :key="profile.id" :value="profile.id">{{ profile.name }} · {{ permissionCount(profile) }}</option></PopupSelect></label>
                    <label><span>{{ l('password') }}</span><input v-model="createForm.password" type="password" dir="ltr" required autocomplete="new-password"><small>{{ l('passwordHelp') }}</small></label>
                    <label><span>{{ l('passwordConfirmation') }}</span><input v-model="createForm.password_confirmation" type="password" dir="ltr" required autocomplete="new-password"></label>
                </div>
                <p v-if="firstError()" class="form-error">{{ firstError() }}</p>
                <footer><button class="btn secondary" type="button" @click="closeModal">{{ l('cancel') }}</button><button class="btn primary" :disabled="busy || !profiles.length">{{ l('saveCreate') }}</button></footer>
            </form>

            <form v-else-if="modal === 'edit' && selected" class="modal-card" @submit.prevent="submitEdit">
                <header><div><h3>{{ l('editTitle') }}</h3><p>{{ l('editHelp') }}</p></div><button type="button" @click="closeModal">×</button></header>
                <div class="form-grid">
                    <label><span>{{ l('name') }}</span><input v-model.trim="employeeForm.name" required autocomplete="name"></label>
                    <label><span>{{ l('email') }}</span><input v-model.trim="employeeForm.email" type="email" dir="ltr" required autocomplete="email"></label>
                    <label class="wide"><span>{{ l('profile') }}</span><PopupSelect v-model="employeeForm.permission_profile_id" :disabled="selected.is_super_admin" required><option value="" disabled>{{ l('chooseProfile') }}</option><option v-for="profile in profiles" :key="profile.id" :value="profile.id">{{ profile.name }} · {{ permissionCount(profile) }}</option></PopupSelect><small v-if="selected.is_super_admin">{{ l('accountProtected') }}</small></label>
                    <label><span>{{ l('password') }}</span><input v-model="employeeForm.password" type="password" dir="ltr" autocomplete="new-password"><small>{{ l('passwordHelp') }}</small></label>
                    <label><span>{{ l('passwordConfirmation') }}</span><input v-model="employeeForm.password_confirmation" type="password" dir="ltr" autocomplete="new-password"></label>
                </div>
                <p v-if="firstError()" class="form-error">{{ firstError() }}</p>
                <footer><button class="btn secondary" type="button" @click="closeModal">{{ l('cancel') }}</button><button class="btn primary" :disabled="busy">{{ l('save') }}</button></footer>
            </form>
        </div>
    </AdminShell>
</template>

<style scoped>
.employees-heading{display:flex;align-items:start;justify-content:space-between;gap:18px;margin-bottom:16px}.employees-heading p{margin:0;color:var(--primary);font-size:10px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.employees-heading h2{margin:5px 0;color:var(--ink);font-size:25px;font-weight:950}.employees-heading span{display:block;max-width:760px;color:var(--ink-faint);font-size:11px;font-weight:700;line-height:1.75}.employees-heading-actions{display:flex;align-items:center;justify-content:flex-end;flex-wrap:wrap;gap:9px}.login-note,.branch-audit-note{display:flex;align-items:flex-start;gap:9px;margin-bottom:14px;padding:12px 14px;border:1px solid color-mix(in srgb,var(--primary) 34%,var(--border));border-radius:14px;background:var(--primary-tint)}.login-note>span,.branch-audit-note>span{display:grid;place-items:center;flex:none;width:19px;height:19px;border-radius:50%;background:var(--primary);color:white;font-size:13px;font-weight:900}.login-note p,.branch-audit-note p{margin:0;color:var(--primary-strong);font-size:10px;font-weight:800;line-height:1.7}.branch-audit-note{border-color:color-mix(in srgb,var(--accent) 45%,var(--border));background:var(--accent-tint)}.branch-audit-note>span{background:var(--accent)}.employee-panel{overflow:hidden;margin-top:14px;border:1px solid var(--border);border-radius:16px;background:var(--surface);box-shadow:0 8px 24px rgba(0,0,0,.05)}.panel-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid var(--border);background:var(--surface-2)}.panel-heading h3{margin:0;color:var(--ink);font-size:13px;font-weight:900}.panel-heading span{display:block;margin-top:2px;color:var(--ink-faint);font-size:9px;font-weight:750}.employee-search{display:flex;align-items:center;gap:7px;width:min(320px,100%);min-height:36px;padding:0 10px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--ink-faint)}.employee-search input{width:100%;border:0;outline:0;background:transparent;color:var(--ink);font:700 10.5px var(--font)}.employees-list{display:grid}.employee-row{display:grid;grid-template-columns:42px minmax(175px,.8fr) minmax(240px,1.2fr) auto auto;align-items:center;gap:12px;padding:13px 16px;border-bottom:1px solid var(--border)}.employee-row:last-child{border-bottom:0}.employee-row.disabled{opacity:.67}.employee-avatar{display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:var(--primary-tint);color:var(--primary-strong);font-size:15px;font-weight:950}.employee-main,.employee-access{display:grid;min-width:0;gap:3px}.employee-name{display:flex;align-items:center;gap:6px;min-width:0}.employee-name>b{overflow:hidden;color:var(--ink);font-size:11.5px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.employee-main>span{overflow:hidden;color:var(--ink-soft);font-size:9.5px;font-weight:750;text-overflow:ellipsis;white-space:nowrap}.employee-main small,.employee-access small{color:var(--ink-faint);font-size:8.8px;font-weight:700}.employee-access>b{overflow:hidden;color:var(--primary-strong);font-size:10px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.employee-access .no-profile{color:var(--danger);white-space:normal}.permission-chips{display:flex;flex-wrap:wrap;gap:4px;margin-top:3px}.permission-chips span{padding:3px 5px;border-radius:6px;background:var(--surface-2);color:var(--ink-soft);font-size:8px;font-weight:800;line-height:1.35}.owner-chip{padding:3px 6px;border-radius:99px;background:var(--accent-tint);color:var(--accent);font-size:8px;font-weight:900;white-space:nowrap}.status{display:inline-flex;align-items:center;justify-content:center;padding:4px 8px;border-radius:99px;font-size:8.5px;font-weight:900;white-space:nowrap}.status.active{background:var(--success-tint);color:var(--success)}.status.suspended{background:var(--danger-tint);color:var(--danger)}.employee-actions{display:flex;align-items:center;justify-content:end;gap:5px}.employee-actions button{border:0;border-radius:7px;background:var(--surface-2);color:var(--primary-strong);font:850 9px var(--font);padding:7px 8px;cursor:pointer}.employee-actions .warning{color:var(--accent)}.employee-actions .success{color:var(--success)}.employee-actions .danger{color:var(--danger)}.empty-state{padding:34px 18px;color:var(--ink-faint);font-size:11px;font-weight:800;text-align:center}.modal-backdrop{position:fixed;z-index:100;inset:0;display:grid;place-items:center;padding:18px;background:rgba(3,10,22,.68);backdrop-filter:blur(4px)}.modal-card{width:min(100%,570px);overflow:hidden;border:1px solid var(--border);border-radius:18px;background:var(--surface);box-shadow:0 28px 70px rgba(0,0,0,.35)}.modal-card header,.modal-card footer{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 18px;border-bottom:1px solid var(--border)}.modal-card footer{justify-content:flex-end;border-top:1px solid var(--border);border-bottom:0}.modal-card header h3{margin:0;color:var(--ink);font-size:14px}.modal-card header p{margin:3px 0 0;color:var(--ink-faint);font-size:9.5px;font-weight:750;line-height:1.55}.modal-card header>button{width:27px;height:27px;border:0;border-radius:8px;color:var(--ink-soft);background:var(--surface-2);font-size:19px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:17px}.form-grid label{display:grid;gap:5px;color:var(--ink-soft);font-size:10px;font-weight:850}.form-grid .wide{grid-column:1/-1}.form-grid input,.form-grid select{width:100%;min-height:39px;padding:8px 9px;border:1px solid var(--border);border-radius:9px;outline:0;color:var(--ink);background:var(--surface-2);font:700 11px var(--font)}.form-grid input:focus,.form-grid select:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-tint)}.form-grid small{color:var(--accent);font-size:9px;line-height:1.5}.form-error{margin:-3px 17px 13px;color:var(--danger);font-size:10px;font-weight:800}@media(max-width:1120px){.employee-row{grid-template-columns:42px minmax(160px,1fr) minmax(180px,.9fr) auto}.employee-actions{grid-column:2/-1;justify-content:start}}@media(max-width:720px){.employees-heading{flex-direction:column}.employees-heading-actions,.employees-heading .btn{width:100%}.employees-heading-actions{align-items:stretch}.employees-heading .btn{flex:1}.panel-heading{align-items:stretch;flex-direction:column}.employee-search{width:100%}.employee-row{grid-template-columns:42px minmax(0,1fr) auto;gap:8px}.employee-access{grid-column:2/4;padding-top:5px}.employee-actions{grid-column:1/-1;justify-content:start;padding-top:4px}.modal-backdrop{align-items:end;padding:0}.modal-card{width:100%;max-height:94dvh;overflow:auto;border-radius:18px 18px 0 0}.form-grid{grid-template-columns:1fr}.form-grid .wide{grid-column:auto}}
</style>
