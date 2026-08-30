<script setup>
import { computed, reactive, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'

const props = defineProps({
    employees: { type: Array, default: () => [] },
    profiles: { type: Array, default: () => [] },
    invitations: { type: Array, default: () => [] },
    canManageEmployees: { type: Boolean, default: false },
})

const page = usePage()
const locale = computed(() => page.props.locale || 'ar')
const currentUser = computed(() => page.props.auth?.user || {})
const query = ref('')
const modal = ref(null)
const selected = ref(null)
const busy = ref(false)
const formErrors = ref({})
const inviteLink = computed(() => page.props.flash?.invite_link || '')
const canManage = computed(() => props.canManageEmployees)

const inviteForm = reactive({
    name: '',
    email: '',
    expires_in_days: 7,
    permission_profile_id: '',
})

const employeeForm = reactive({
    name: '',
    username: '',
    email: '',
    phone: '',
    permission_profile_id: '',
})

const copy = {
    ar: {
        title: 'الموظفون', eyebrow: 'إدارة النظام', subtitle: 'حسابات موظفي لوحة التحكم فقط. لكل موظف ملف صلاحيات محدد يمنع الوصول إلى أي قسم غير ممنوح له.',
        add: 'إضافة موظف', search: 'ابحث بالاسم أو البريد أو اسم المستخدم', employees: 'حسابات الموظفين', pending: 'الدعوات المعلقة', noEmployees: 'لا توجد حسابات موظفين حتى الآن.', noInvitations: 'لا توجد دعوات معلقة.',
        profile: 'ملف الصلاحيات', noProfile: 'بدون صلاحية — لا يمكنه الدخول إلى الداش', fullAccess: 'صاحب المنصة', active: 'نشط', suspended: 'معطّل', lastActive: 'آخر نشاط', created: 'تاريخ الإنشاء',
        edit: 'تعديل', disable: 'تعطيل', activate: 'تفعيل', delete: 'حذف', deleteConfirm: 'هل تريد حذف هذا الموظف؟ لن يستطيع الدخول بعد الحذف.', accountProtected: 'هذا الحساب محمي.',
        inviteTitle: 'إضافة موظف جديد', inviteHelp: 'سيُنشأ رابط آمن للموظف ليكمل بيانات الدخول بنفسه. اختر ملف الصلاحيات قبل إنشاء الدعوة.', name: 'الاسم', email: 'البريد الإلكتروني', expires: 'صلاحية الرابط بالأيام', saveInvite: 'إنشاء رابط الدعوة',
        editTitle: 'تعديل بيانات الموظف', username: 'اسم المستخدم', phone: 'رقم الهاتف', save: 'حفظ التعديل', cancel: 'إلغاء', chooseProfile: 'اختر ملف الصلاحيات', createProfileFirst: 'أنشئ ملف صلاحيات أولًا', permissions: 'صلاحية', permissionCount: 'صلاحيات ممنوحة',
        linkReady: 'رابط دعوة الموظف جاهز. أرسله بشكل خاص إلى الموظف.', copyLink: 'نسخ الرابط', copied: 'تم النسخ', statusConfirm: 'تأكيد تغيير حالة الموظف؟', required: 'أكمل الحقول المطلوبة.', inviteStatePending: 'بانتظار القبول', inviteStateAccepted: 'تم القبول', inviteStateExpired: 'منتهية', invitedBy: 'بواسطة', deleteSelf: 'لا يمكن حذف الحساب الذي تستخدمه الآن.',
    },
    en: {
        title: 'Employees', eyebrow: 'System management', subtitle: 'Dashboard employee accounts only. Each employee receives a named permission profile that limits their dashboard access.',
        add: 'Add employee', search: 'Search name, email, or username', employees: 'Employee accounts', pending: 'Pending invitations', noEmployees: 'No employee accounts yet.', noInvitations: 'No pending invitations.',
        profile: 'Permission profile', noProfile: 'No profile — dashboard access is denied', fullAccess: 'Platform owner', active: 'Active', suspended: 'Disabled', lastActive: 'Last active', created: 'Created',
        edit: 'Edit', disable: 'Disable', activate: 'Activate', delete: 'Delete', deleteConfirm: 'Delete this employee? They will no longer be able to sign in.', accountProtected: 'This account is protected.',
        inviteTitle: 'Add new employee', inviteHelp: 'A secure link lets the employee set up their own credentials. Choose the permission profile before creating the invitation.', name: 'Name', email: 'Email', expires: 'Link expires in days', saveInvite: 'Create invitation link',
        editTitle: 'Edit employee details', username: 'Username', phone: 'Phone number', save: 'Save changes', cancel: 'Cancel', chooseProfile: 'Choose permission profile', createProfileFirst: 'Create a permission profile first', permissions: 'Permissions', permissionCount: 'Granted actions',
        linkReady: 'The employee invitation link is ready. Send it privately to the employee.', copyLink: 'Copy link', copied: 'Copied', statusConfirm: 'Confirm employee status change?', required: 'Complete the required fields.', inviteStatePending: 'Awaiting acceptance', inviteStateAccepted: 'Accepted', inviteStateExpired: 'Expired', invitedBy: 'Invited by', deleteSelf: 'You cannot delete the account currently in use.',
    },
    ku: {
        title: 'کارمەندان', eyebrow: 'بەڕێوەبردنی سیستەم', subtitle: 'تەنها هەژمارەکانی کارمەندانی داشبۆرد. هەر کارمەندێک پڕۆفایلێکی دیاریکراوی دەسەڵات وەردەگرێت.',
        add: 'زیادکردنی کارمەند', search: 'بە ناو، ئیمەیڵ یان ناوی بەکارهێنەر بگەڕێ', employees: 'هەژمارەکانی کارمەندان', pending: 'بانگهێشتە چاوەڕوانەکان', noEmployees: 'هێشتا هیچ هەژماری کارمەند نییە.', noInvitations: 'هیچ بانگهێشتێکی چاوەڕوان نییە.',
        profile: 'پڕۆفایلی دەسەڵات', noProfile: 'بێ پڕۆفایل — چوونەژووری داشبۆرد ڕەتدەکرێتەوە', fullAccess: 'خاوەنی پلاتفۆرم', active: 'چالاک', suspended: 'ناچالاک', lastActive: 'دوا چالاکی', created: 'دروستکراو',
        edit: 'دەستکاریکردن', disable: 'ناچالاککردن', activate: 'چالاککردن', delete: 'سڕینەوە', deleteConfirm: 'ئەم کارمەندە بسڕیتەوە؟ ناتوانێت بچێتەژوورەوە.', accountProtected: 'ئەم هەژمارە پارێزراوە.',
        inviteTitle: 'زیادکردنی کارمەندی نوێ', inviteHelp: 'بەستەرێکی پارێزراو کارمەندەکە دەهێنێت تا زانیارییەکانی چوونەژوورەوەی خۆی تەواو بکات.', name: 'ناو', email: 'ئیمەیڵ', expires: 'ماوەی بەستەر بە ڕۆژ', saveInvite: 'دروستکردنی بەستەری بانگهێشت',
        editTitle: 'دەستکاریکردنی زانیاری کارمەند', username: 'ناوی بەکارهێنەر', phone: 'ژمارەی تەلەفۆن', save: 'پاشەکەوتکردنی گۆڕانکاری', cancel: 'هەڵوەشاندنەوە', chooseProfile: 'پڕۆفایلی دەسەڵات هەڵبژێرە', createProfileFirst: 'سەرەتا پڕۆفایلی دەسەڵات دروست بکە', permissions: 'دەسەڵاتەکان', permissionCount: 'کردارە پێدراوەکان',
        linkReady: 'بەستەری بانگهێشتی کارمەند ئامادەیە. بە تایبەتی بۆی بنێرە.', copyLink: 'کۆپیکردنی بەستەر', copied: 'کۆپی کرا', statusConfirm: 'دڵنیایت لە گۆڕینی دۆخی کارمەند؟', required: 'خانە پێویستەکان پڕ بکە.', inviteStatePending: 'چاوەڕێی وەرگرتن', inviteStateAccepted: 'وەرگیراوە', inviteStateExpired: 'بەسەرچووە', invitedBy: 'بانگهێشتکراو لەلایەن', deleteSelf: 'ناتوانیت هەژماری ئێستا بەکارهاتوو بسڕیتەوە.',
    },
}

function l(key) {
    return copy[key]?.[locale.value] || copy[key]?.ar || key
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

function invitationStateLabel(state) {
    return state === 'accepted' ? l('inviteStateAccepted') : state === 'expired' ? l('inviteStateExpired') : l('inviteStatePending')
}

function permissionCount(profile) {
    return Object.values(profile?.permissions || {}).reduce((total, actions) => total + (Array.isArray(actions) ? actions.length : 0), 0)
}

function employeeProfile(employee) {
    return employee.permission_profile || props.profiles.find((profile) => Number(profile.id) === Number(employee.permission_profile_id)) || null
}

const filteredEmployees = computed(() => {
    const needle = query.value.trim().toLowerCase()
    if (!needle) return props.employees

    return props.employees.filter((employee) => [employee.name, employee.email, employee.username, employee.phone]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(needle)))
})

function resetInvite() {
    inviteForm.name = ''
    inviteForm.email = ''
    inviteForm.expires_in_days = 7
    inviteForm.permission_profile_id = ''
    formErrors.value = {}
}

function openInvite() {
    if (!canManage.value) return
    resetInvite()
    modal.value = 'invite'
}

function openEdit(employee) {
    if (!canManage.value) return
    selected.value = employee
    employeeForm.name = employee.name || ''
    employeeForm.username = employee.username || ''
    employeeForm.email = employee.email || ''
    employeeForm.phone = employee.phone || ''
    employeeForm.permission_profile_id = employee.permission_profile_id || ''
    formErrors.value = {}
    modal.value = 'edit'
}

function closeModal() {
    modal.value = null
    selected.value = null
    formErrors.value = {}
}

function submitInvite() {
    if (!canManage.value || busy.value) return
    if (!inviteForm.name.trim() || !inviteForm.email.trim() || !inviteForm.permission_profile_id) {
        formErrors.value = { form: l('required') }
        return
    }

    busy.value = true
    formErrors.value = {}
    router.post(route('admin.employees.invitations.store'), {
        name: inviteForm.name.trim(),
        email: inviteForm.email.trim(),
        expires_in_days: Number(inviteForm.expires_in_days || 7),
        permission_profile_id: Number(inviteForm.permission_profile_id),
    }, {
        preserveScroll: true,
        onSuccess: closeModal,
        onError: (errors) => { formErrors.value = errors || {} },
        onFinish: () => { busy.value = false },
    })
}

function submitEdit() {
    if (!canManage.value || !selected.value || busy.value) return
    if (!employeeForm.name.trim() || !employeeForm.username.trim() || !employeeForm.email.trim() || !employeeForm.phone.trim() || (!selected.value.is_super_admin && !employeeForm.permission_profile_id)) {
        formErrors.value = { form: l('required') }
        return
    }

    busy.value = true
    formErrors.value = {}
    router.put(route('admin.employees.update', selected.value.id), {
        name: employeeForm.name.trim(),
        username: employeeForm.username.trim(),
        email: employeeForm.email.trim(),
        phone: employeeForm.phone.trim(),
        permission_profile_id: selected.value.is_super_admin ? null : Number(employeeForm.permission_profile_id),
    }, {
        preserveScroll: true,
        onSuccess: closeModal,
        onError: (errors) => { formErrors.value = errors || {} },
        onFinish: () => { busy.value = false },
    })
}

function setStatus(employee, status) {
    if (!canManage.value || !employee.can_change_status || busy.value || !window.confirm(l('statusConfirm'))) return
    busy.value = true
    router.patch(route('admin.employees.status', employee.id), { status }, {
        preserveScroll: true,
        onFinish: () => { busy.value = false },
    })
}

function deleteEmployee(employee) {
    if (!canManage.value || !employee.can_delete || busy.value) return
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

async function copyInviteLink() {
    if (!inviteLink.value) return
    try {
        await navigator.clipboard.writeText(inviteLink.value)
        window.alert(l('copied'))
    } catch {
        window.prompt(l('copyLink'), inviteLink.value)
    }
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
            <button v-if="canManage" class="btn primary" type="button" @click="openInvite">＋ {{ l('add') }}</button>
        </header>

        <section v-if="inviteLink" class="invite-link" role="status">
            <div><b>{{ l('linkReady') }}</b><code>{{ inviteLink }}</code></div>
            <button class="btn primary" type="button" @click="copyInviteLink">{{ l('copyLink') }}</button>
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
                        <span dir="ltr">{{ employee.email || employee.username }}</span>
                        <small>{{ l('lastActive') }}: {{ formatDate(employee.last_active_at) }}</small>
                    </div>
                    <div class="employee-access">
                        <template v-if="employee.is_super_admin"><b>{{ l('fullAccess') }}</b><small>{{ l('permissions') }}: ∞</small></template>
                        <template v-else-if="employeeProfile(employee)"><b>{{ employeeProfile(employee).name }}</b><small>{{ l('permissionCount') }}: {{ permissionCount(employeeProfile(employee)) }}</small></template>
                        <template v-else><b class="no-profile">{{ l('noProfile') }}</b></template>
                    </div>
                    <span class="status" :class="employee.status === 'active' ? 'active' : 'suspended'">{{ statusLabel(employee.status) }}</span>
                    <div v-if="canManage" class="employee-actions">
                        <button type="button" @click="openEdit(employee)">{{ l('edit') }}</button>
                        <button v-if="employee.can_change_status && employee.status === 'active'" type="button" class="warning" @click="setStatus(employee, 'suspended')">{{ l('disable') }}</button>
                        <button v-else-if="employee.can_change_status" type="button" class="success" @click="setStatus(employee, 'active')">{{ l('activate') }}</button>
                        <button v-if="employee.can_delete" type="button" class="danger" @click="deleteEmployee(employee)">{{ l('delete') }}</button>
                    </div>
                </article>
            </div>
            <div v-else class="empty-state">{{ l('noEmployees') }}</div>
        </section>

        <section class="employee-panel invitations-panel">
            <header class="panel-heading"><div><h3>{{ l('pending') }}</h3><span>{{ invitations.length }}</span></div></header>
            <div v-if="invitations.length" class="invite-grid">
                <article v-for="invitation in invitations" :key="invitation.id" class="invite-card">
                    <div><b>{{ invitation.name }}</b><span dir="ltr">{{ invitation.email }}</span></div>
                    <small>{{ l('profile') }}: {{ invitation.permission_profile?.name || l('noProfile') }}</small>
                    <footer><span class="status" :class="invitation.state">{{ invitationStateLabel(invitation.state) }}</span><time>{{ formatDate(invitation.expires_at) }}</time></footer>
                </article>
            </div>
            <div v-else class="empty-state">{{ l('noInvitations') }}</div>
        </section>

        <div v-if="modal" class="modal-backdrop" @mousedown.self="closeModal">
            <form v-if="modal === 'invite'" class="modal-card" @submit.prevent="submitInvite">
                <header><div><h3>{{ l('inviteTitle') }}</h3><p>{{ l('inviteHelp') }}</p></div><button type="button" @click="closeModal">×</button></header>
                <div class="form-grid">
                    <label><span>{{ l('name') }}</span><input v-model.trim="inviteForm.name" required></label>
                    <label><span>{{ l('email') }}</span><input v-model.trim="inviteForm.email" type="email" dir="ltr" required></label>
                    <label><span>{{ l('profile') }}</span><select v-model="inviteForm.permission_profile_id" required><option value="" disabled>{{ profiles.length ? l('chooseProfile') : l('createProfileFirst') }}</option><option v-for="profile in profiles" :key="profile.id" :value="profile.id">{{ profile.name }} · {{ permissionCount(profile) }}</option></select></label>
                    <label><span>{{ l('expires') }}</span><input v-model.number="inviteForm.expires_in_days" type="number" min="1" max="30" required></label>
                </div>
                <p v-if="formErrors.form" class="form-error">{{ formErrors.form }}</p>
                <footer><button class="btn secondary" type="button" @click="closeModal">{{ l('cancel') }}</button><button class="btn primary" :disabled="busy || !profiles.length">{{ l('saveInvite') }}</button></footer>
            </form>

            <form v-else-if="modal === 'edit' && selected" class="modal-card" @submit.prevent="submitEdit">
                <header><div><h3>{{ l('editTitle') }}</h3><p>{{ selected.email }}</p></div><button type="button" @click="closeModal">×</button></header>
                <div class="form-grid">
                    <label><span>{{ l('name') }}</span><input v-model.trim="employeeForm.name" required></label>
                    <label><span>{{ l('username') }}</span><input v-model.trim="employeeForm.username" dir="ltr" required></label>
                    <label><span>{{ l('email') }}</span><input v-model.trim="employeeForm.email" type="email" dir="ltr" required></label>
                    <label><span>{{ l('phone') }}</span><input v-model.trim="employeeForm.phone" dir="ltr" required></label>
                    <label class="wide"><span>{{ l('profile') }}</span><select v-model="employeeForm.permission_profile_id" :disabled="selected.is_super_admin" required><option value="" disabled>{{ l('chooseProfile') }}</option><option v-for="profile in profiles" :key="profile.id" :value="profile.id">{{ profile.name }} · {{ permissionCount(profile) }}</option></select><small v-if="selected.is_super_admin">{{ l('accountProtected') }}</small></label>
                </div>
                <p v-if="formErrors.form" class="form-error">{{ formErrors.form }}</p>
                <footer><button class="btn secondary" type="button" @click="closeModal">{{ l('cancel') }}</button><button class="btn primary" :disabled="busy">{{ l('save') }}</button></footer>
            </form>
        </div>
    </AdminShell>
</template>

<style scoped>
.employees-heading{display:flex;align-items:start;justify-content:space-between;gap:18px;margin-bottom:20px}.employees-heading p{margin:0;color:var(--primary);font-size:10px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.employees-heading h2{margin:5px 0;color:var(--ink);font-size:25px;font-weight:950}.employees-heading span{display:block;max-width:760px;color:var(--ink-faint);font-size:11px;font-weight:700;line-height:1.75}.employee-panel{overflow:hidden;margin-top:14px;border:1px solid var(--border);border-radius:16px;background:var(--surface);box-shadow:0 8px 24px rgba(0,0,0,.05)}.panel-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid var(--border);background:var(--surface-2)}.panel-heading h3{margin:0;color:var(--ink);font-size:13px;font-weight:900}.panel-heading span{display:block;margin-top:2px;color:var(--ink-faint);font-size:9px;font-weight:750}.employee-search{display:flex;align-items:center;gap:7px;width:min(320px,100%);min-height:36px;padding:0 10px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--ink-faint)}.employee-search input{width:100%;border:0;outline:0;background:transparent;color:var(--ink);font:700 10.5px var(--font)}.employees-list{display:grid}.employee-row{display:grid;grid-template-columns:42px minmax(170px,1fr) minmax(160px,.8fr) auto auto;align-items:center;gap:12px;padding:13px 16px;border-bottom:1px solid var(--border)}.employee-row:last-child{border-bottom:0}.employee-row.disabled{opacity:.67}.employee-avatar{display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:var(--primary-tint);color:var(--primary-strong);font-size:15px;font-weight:950}.employee-main,.employee-access{display:grid;min-width:0;gap:3px}.employee-name{display:flex;align-items:center;gap:6px;min-width:0}.employee-name>b{overflow:hidden;color:var(--ink);font-size:11.5px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.employee-main>span{overflow:hidden;color:var(--ink-soft);font-size:9.5px;font-weight:750;text-overflow:ellipsis;white-space:nowrap}.employee-main small,.employee-access small{color:var(--ink-faint);font-size:8.8px;font-weight:700}.employee-access>b{overflow:hidden;color:var(--primary-strong);font-size:10px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.employee-access .no-profile{color:var(--danger);white-space:normal}.owner-chip{padding:3px 6px;border-radius:99px;background:var(--accent-tint);color:var(--accent);font-size:8px;font-weight:900;white-space:nowrap}.status{display:inline-flex;align-items:center;justify-content:center;padding:4px 8px;border-radius:99px;font-size:8.5px;font-weight:900;white-space:nowrap}.status.active,.status.accepted{background:var(--success-tint);color:var(--success)}.status.suspended,.status.expired{background:var(--danger-tint);color:var(--danger)}.status.pending{background:var(--accent-tint);color:var(--accent)}.employee-actions{display:flex;align-items:center;justify-content:end;gap:5px}.employee-actions button{border:0;border-radius:7px;background:var(--surface-2);color:var(--primary-strong);font:850 9px var(--font);padding:7px 8px;cursor:pointer}.employee-actions .warning{color:var(--accent)}.employee-actions .success{color:var(--success)}.employee-actions .danger{color:var(--danger)}.empty-state{padding:34px 18px;color:var(--ink-faint);font-size:11px;font-weight:800;text-align:center}.invite-link{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:14px;padding:13px 14px;border:1px solid color-mix(in srgb,var(--primary) 34%,var(--border));border-radius:14px;background:var(--primary-tint)}.invite-link b{display:block;color:var(--ink);font-size:11px}.invite-link code{display:block;max-width:760px;overflow:auto;margin-top:6px;color:var(--primary-strong);font:9px Consolas,monospace;direction:ltr;text-align:start}.invite-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;padding:13px}.invite-card{display:grid;gap:8px;padding:13px;border:1px solid var(--border);border-radius:12px;background:var(--surface-2)}.invite-card>div{display:grid;gap:3px;min-width:0}.invite-card b,.invite-card span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.invite-card b{color:var(--ink);font-size:11px}.invite-card>div span,.invite-card small{color:var(--ink-faint);font-size:9px;font-weight:700}.invite-card footer{display:flex;align-items:center;justify-content:space-between;gap:8px}.invite-card time{color:var(--ink-faint);font-size:8.5px;font-weight:700}.modal-backdrop{position:fixed;z-index:100;inset:0;display:grid;place-items:center;padding:18px;background:rgba(3,10,22,.68);backdrop-filter:blur(4px)}.modal-card{width:min(100%,570px);overflow:hidden;border:1px solid var(--border);border-radius:18px;background:var(--surface);box-shadow:0 28px 70px rgba(0,0,0,.35)}.modal-card header,.modal-card footer{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 18px;border-bottom:1px solid var(--border)}.modal-card footer{justify-content:flex-end;border-top:1px solid var(--border);border-bottom:0}.modal-card header h3{margin:0;color:var(--ink);font-size:14px}.modal-card header p{margin:3px 0 0;color:var(--ink-faint);font-size:9.5px;font-weight:750;line-height:1.55}.modal-card header>button{width:27px;height:27px;border:0;border-radius:8px;color:var(--ink-soft);background:var(--surface-2);font-size:19px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:17px}.form-grid label{display:grid;gap:5px;color:var(--ink-soft);font-size:10px;font-weight:850}.form-grid .wide{grid-column:1/-1}.form-grid input,.form-grid select{width:100%;min-height:39px;padding:8px 9px;border:1px solid var(--border);border-radius:9px;outline:0;color:var(--ink);background:var(--surface-2);font:700 11px var(--font)}.form-grid input:focus,.form-grid select:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-tint)}.form-grid small{color:var(--accent);font-size:9px;line-height:1.5}.form-error{margin:-3px 17px 13px;color:var(--danger);font-size:10px;font-weight:800}@media(max-width:1020px){.employee-row{grid-template-columns:42px minmax(160px,1fr) minmax(140px,.7fr) auto}.employee-actions{grid-column:2/-1;justify-content:start}}@media(max-width:720px){.employees-heading{flex-direction:column}.employees-heading .btn{width:100%}.panel-heading{align-items:stretch;flex-direction:column}.employee-search{width:100%}.employee-row{grid-template-columns:42px minmax(0,1fr) auto;gap:8px}.employee-access{grid-column:2/4;padding-top:5px}.employee-actions{grid-column:1/-1;justify-content:start;padding-top:4px}.invite-grid{grid-template-columns:1fr}.invite-link{align-items:stretch;flex-direction:column}.invite-link .btn{width:100%}.modal-backdrop{align-items:end;padding:0}.modal-card{width:100%;max-height:94dvh;overflow:auto;border-radius:18px 18px 0 0}.form-grid{grid-template-columns:1fr}.form-grid .wide{grid-column:auto}} 
</style>
