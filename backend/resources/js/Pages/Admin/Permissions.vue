<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'
import BranchFilter from '../../Components/BranchFilter.vue'

const props = defineProps({
    profiles: { type: Array, default: () => [] },
    modules: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    // The backend is the authority for this flag. Keeping a permissive
    // fallback makes the page compatible while older deployments roll out.
    canManageProfiles: { type: Boolean, default: false },
    branchAudit: { type: Boolean, default: false },
    branchFilter: { type: Object, default: () => ({}) },
})

const page = usePage()
const locale = computed(() => page.props.locale || 'ar')
const isBranchScoped = computed(() => Boolean(page.props.branchDashboard?.active))
const editorMode = ref(null)
const selectedProfileId = ref(null)
const submitting = ref(false)
const assignmentValues = ref({})
const assigning = ref({})
const assignmentErrors = ref({})

const labels = {
    title: { ar: 'الصلاحيات', en: 'Permissions', ku: 'دەسەڵاتەکان' },
    eyebrow: { ar: 'إدارة النظام', en: 'System management', ku: 'بەڕێوەبردنی سیستەم' },
    subtitle: { ar: 'أنشئ صلاحية باسم واضح، ثم حدّد بدقة ما يمكن للمستخدمين الإداريين عرضه أو إنشاؤه أو تعديله أو حذفه.', en: 'Create named permission profiles and precisely control what administrative users can view, create, edit, or delete.', ku: 'پڕۆفایلی دەسەڵات بە ناوی ڕوون دروست بکە و دەستگەیشتنی بەکارهێنەرانی بەڕێوەبەر بە وردی دیاری بکە.' },
    add: { ar: 'إضافة صلاحية', en: 'Add permission profile', ku: 'زیادکردنی پڕۆفایلی دەسەڵات' },
    profiles: { ar: 'ملفات الصلاحيات', en: 'Permission profiles', ku: 'پڕۆفایلەکانی دەسەڵات' },
    profilesHelp: { ar: 'اختر صلاحية لتعديلها أو أنشئ صلاحية جديدة.', en: 'Choose a profile to edit it, or create a new one.', ku: 'پڕۆفایلێک هەڵبژێرە بۆ دەستکاریکردن یان نوێیەک دروست بکە.' },
    noProfiles: { ar: 'لا توجد صلاحيات مخصصة حتى الآن.', en: 'There are no custom permission profiles yet.', ku: 'هێشتا هیچ پڕۆفایلێکی دەسەڵاتی تایبەت نییە.' },
    createTitle: { ar: 'إضافة صلاحية جديدة', en: 'Add a new permission profile', ku: 'زیادکردنی پڕۆفایلی دەسەڵاتی نوێ' },
    editTitle: { ar: 'تعديل الصلاحية', en: 'Edit permission profile', ku: 'دەستکاریکردنی پڕۆفایلی دەسەڵات' },
    editorHelp: { ar: 'اختر الصلاحيات التي يحصل عليها كل مستخدم يُسند إليه هذا الملف.', en: 'Choose the permissions received by every user assigned this profile.', ku: 'ئەو دەسەڵاتانە هەڵبژێرە کە هەر بەکارهێنەرێک کە ئەم پڕۆفایلەی پێ دەدرێت وەریدەگرێت.' },
    name: { ar: 'اسم الصلاحية', en: 'Permission profile name', ku: 'ناوی پڕۆفایلی دەسەڵات' },
    namePlaceholder: { ar: 'مثال: مدير العمليات', en: 'Example: Operations manager', ku: 'نموونە: بەڕێوەبەری کارپێکردن' },
    nameRequired: { ar: 'اكتب اسمًا للصلاحية أولًا.', en: 'Enter a permission profile name first.', ku: 'سەرەتا ناوی پڕۆفایلی دەسەڵات بنووسە.' },
    fullAccess: { ar: 'صلاحية كاملة', en: 'Full access', ku: 'دەسەڵاتی تەواو' },
    fullAccessHelp: { ar: 'منح جميع الإجراءات في جميع أقسام الداشبورد.', en: 'Grant every action in every dashboard area.', ku: 'هەموو کردارەکانی هەموو بەشەکانی داشبۆرد بدە.' },
    selectAll: { ar: 'اختر الكل', en: 'Select all', ku: 'هەموو هەڵبژێرە' },
    clearAll: { ar: 'إلغاء تحديد الكل', en: 'Clear all', ku: 'هەموو لاببە' },
    module: { ar: 'الصلاحيات', en: 'Modules', ku: 'بەشەکان' },
    moduleActions: { ar: 'إجراءات هذا القسم', en: 'Actions in this area', ku: 'کردارەکانی ئەم بەشە' },
    selectModule: { ar: 'تحديد كل إجراءات القسم', en: 'Select all actions in this area', ku: 'هەموو کردارەکانی ئەم بەشە هەڵبژێرە' },
    selected: { ar: 'محددة', en: 'selected', ku: 'هەڵبژێردراو' },
    notSelected: { ar: 'غير محددة', en: 'not selected', ku: 'هەڵنەبژێردراو' },
    save: { ar: 'حفظ', en: 'Save', ku: 'پاشەکەوتکردن' },
    create: { ar: 'إنشاء الصلاحية', en: 'Create profile', ku: 'دروستکردنی پڕۆفایل' },
    cancel: { ar: 'إلغاء', en: 'Cancel', ku: 'هەڵوەشاندنەوە' },
    delete: { ar: 'حذف الصلاحية', en: 'Delete profile', ku: 'سڕینەوەی پڕۆفایل' },
    deleteConfirm: { ar: 'هل تريد حذف هذه الصلاحية؟ سيتم إلغاء إسنادها من المستخدمين المرتبطين بها.', en: 'Delete this permission profile? It will be unassigned from its linked users.', ku: 'دڵنیایت لە سڕینەوەی ئەم پڕۆفایلەی دەسەڵات؟ لە بەکارهێنەرە پەیوەندیدارەکان لادەبرێت.' },
    protectedProfile: { ar: 'صلاحية محمية', en: 'Protected profile', ku: 'پڕۆفایلی پارێزراو' },
    members: { ar: 'مستخدمون', en: 'users', ku: 'بەکارهێنەر' },
    granted: { ar: 'صلاحية ممنوحة', en: 'granted permissions', ku: 'دەسەڵاتی پێدراو' },
    emptyEditor: { ar: 'اختر صلاحية من القائمة أو أضف صلاحية جديدة للبدء.', en: 'Select a profile from the list or add a new one to begin.', ku: 'پڕۆفایلێک لە لیستەکە هەڵبژێرە یان نوێیەک زیاد بکە بۆ دەستپێکردن.' },
    assignTitle: { ar: 'إسناد الصلاحيات للمستخدمين', en: 'Assign profiles to users', ku: 'دانانی پڕۆفایل بۆ بەکارهێنەران' },
    assignHelp: { ar: 'اختر ملف الصلاحية لكل مستخدم إداري. يُحفظ التغيير مباشرة.', en: 'Choose a permission profile for each administrative user. Changes save immediately.', ku: 'بۆ هەر بەکارهێنەرێکی بەڕێوەبەر پڕۆفایلێکی دەسەڵات هەڵبژێرە. گۆڕانکارییەکان ڕاستەوخۆ پاشەکەوت دەکرێن.' },
    noUsers: { ar: 'لا يوجد مستخدمون إداريون يمكن إسناد صلاحيات لهم.', en: 'There are no administrative users available for assignment.', ku: 'هیچ بەکارهێنەرێکی بەڕێوەبەر بۆ دانانی دەسەڵات بەردەست نییە.' },
    noProfile: { ar: 'بدون صلاحية مخصصة', en: 'No custom profile', ku: 'بێ پڕۆفایلی تایبەت' },
    profile: { ar: 'الصلاحية', en: 'Profile', ku: 'پڕۆفایل' },
    assigned: { ar: 'تم الإسناد', en: 'Assigned', ku: 'دانرا' },
    saving: { ar: 'جارٍ الحفظ…', en: 'Saving…', ku: 'پاشەکەوت دەکرێت…' },
    assignmentFailed: { ar: 'تعذر حفظ إسناد الصلاحية. حاول مرة أخرى.', en: 'The permission assignment could not be saved. Please try again.', ku: 'نەتوانرا دانانی دەسەڵات پاشەکەوت بکرێت. تکایە دووبارە هەوڵبدە.' },
    statusActive: { ar: 'نشط', en: 'Active', ku: 'چالاک' },
    statusInactive: { ar: 'موقوف', en: 'Inactive', ku: 'ناچالاک' },
    branchAudit: { ar: 'هذا عرض مراجعة لموظفي الفرع وملفات صلاحياته فقط. تبقى إدارة الصلاحيات من لوحة الفرع نفسها.', en: 'This is a read-only review of the branch staff and permission profiles. Manage them from the branch dashboard.', ku: 'ئەمە تەنها پیشاندانی پێداچوونەوەی کارمەند و پڕۆفایلەکانی دەسەڵاتی لقە. بەڕێوەبردنیان لە داشبۆردی هەمان لق ئەنجام بدە.' },
}

const actionLabels = {
    show: { ar: 'إظهار', en: 'View', ku: 'پیشاندان' },
    view: { ar: 'إظهار', en: 'View', ku: 'پیشاندان' },
    index: { ar: 'إظهار', en: 'View', ku: 'پیشاندان' },
    create: { ar: 'إنشاء', en: 'Create', ku: 'دروستکردن' },
    store: { ar: 'إنشاء', en: 'Create', ku: 'دروستکردن' },
    edit: { ar: 'تعديل', en: 'Edit', ku: 'دەستکاریکردن' },
    update: { ar: 'تعديل', en: 'Edit', ku: 'دەستکاریکردن' },
    delete: { ar: 'حذف', en: 'Delete', ku: 'سڕینەوە' },
    destroy: { ar: 'حذف', en: 'Delete', ku: 'سڕینەوە' },
}

function l(key) {
    return labels[key]?.[locale.value] || labels[key]?.ar || key
}

function localized(value) {
    if (typeof value === 'string') return value
    if (value && typeof value === 'object') {
        return value[locale.value] || value.ar || value.en || value.ku || value.label || value.name || ''
    }
    return ''
}

function actionKey(action) {
    return String(typeof action === 'object' ? (action.key || action.action || action.value || '') : action)
}

function actionsFor(module) {
    const actions = Array.isArray(module?.actions)
        ? module.actions
        : Object.entries(module?.actions || {}).map(([key, action]) => {
            if (action && typeof action === 'object' && !Array.isArray(action)) {
                return { ...action, key: action.key || key }
            }

            return { key, label: action }
        })

    return actions.map((action) => ({ raw: action, key: actionKey(action) })).filter((action) => action.key)
}

const permissionModules = computed(() => props.modules
    .map((module) => ({ ...module, key: String(module.key || module.slug || module.name || ''), actions: actionsFor(module) }))
    .filter((module) => module.key && module.actions.length))

function moduleLabel(module) {
    return module[`name_${locale.value}`]
        || module.name_ar
        || localized(module.label || module.labels || module.title || module.name)
        || module.key
}

function actionLabel(action) {
    const custom = typeof action.raw === 'object' ? localized(action.raw.label || action.raw.labels || action.raw.name || action.raw) : ''
    return custom || actionLabels[action.key]?.[locale.value] || actionLabels[action.key]?.ar || action.key
}

function actionDescription(action) {
    if (typeof action.raw !== 'object' || !action.raw) return ''
    return localized(action.raw.description || action.raw.help || action.raw.hint)
}

function emptyPermissions() {
    return Object.fromEntries(permissionModules.value.map((module) => [module.key, []]))
}

function actionsFromSource(source, module) {
    if (!source) return []

    if (Array.isArray(source)) {
        return source
            .map((item) => String(item))
            .filter((item) => item.startsWith(`${module.key}.`))
            .map((item) => item.slice(module.key.length + 1))
    }

    const value = source[module.key]
    if (Array.isArray(value)) return value.map((item) => String(item))
    if (value && typeof value === 'object') {
        if (Array.isArray(value.actions)) return value.actions.map((item) => String(item))
        return Object.entries(value).filter(([, enabled]) => Boolean(enabled)).map(([key]) => key)
    }

    return []
}

function normalizePermissions(source) {
    const normalized = emptyPermissions()
    permissionModules.value.forEach((module) => {
        const allowed = new Set(actionsFromSource(source, module))
        normalized[module.key] = module.actions.filter((action) => allowed.has(action.key)).map((action) => action.key)
    })
    return normalized
}

const form = reactive({
    name: '',
    permissions: {},
    errors: {},
})

const selectedProfile = computed(() => props.profiles.find((profile) => String(profile.id) === String(selectedProfileId.value)) || null)
const isEditing = computed(() => editorMode.value === 'edit' && selectedProfile.value)
const canManage = computed(() => props.canManageProfiles)
const totalAvailableActions = computed(() => permissionModules.value.reduce((total, module) => total + module.actions.length, 0))
const selectedActionCount = computed(() => Object.values(form.permissions).reduce((total, actions) => total + (Array.isArray(actions) ? actions.length : 0), 0))
const hasFullAccess = computed(() => totalAvailableActions.value > 0 && selectedActionCount.value === totalAvailableActions.value)

function resetForm() {
    form.name = ''
    form.permissions = emptyPermissions()
    form.errors = {}
}

function openCreate() {
    if (!canManage.value) return
    selectedProfileId.value = null
    editorMode.value = 'create'
    resetForm()
}

function openEdit(profile) {
    selectedProfileId.value = profile.id
    editorMode.value = 'edit'
    form.name = profile.name || ''
    form.permissions = normalizePermissions(profile.permissions)
    form.errors = {}
}

function closeEditor() {
    editorMode.value = null
    selectedProfileId.value = null
    resetForm()
}

function hasAction(module, action) {
    return (form.permissions[module.key] || []).includes(action.key)
}

function toggleAction(module, action) {
    if (!canManage.value) return
    const current = new Set(form.permissions[module.key] || [])
    if (current.has(action.key)) current.delete(action.key)
    else current.add(action.key)

    form.permissions = {
        ...form.permissions,
        [module.key]: module.actions.filter((item) => current.has(item.key)).map((item) => item.key),
    }
}

function moduleIsComplete(module) {
    return module.actions.every((action) => hasAction(module, action))
}

function selectedModuleActionCount(module) {
    return module.actions.filter((action) => hasAction(module, action)).length
}

function toggleModule(module) {
    if (!canManage.value) return
    const grant = !moduleIsComplete(module)
    form.permissions = {
        ...form.permissions,
        [module.key]: grant ? module.actions.map((action) => action.key) : [],
    }
}

function setAllPermissions(grant) {
    if (!canManage.value) return
    form.permissions = Object.fromEntries(permissionModules.value.map((module) => [
        module.key,
        grant ? module.actions.map((action) => action.key) : [],
    ]))
}

function submitProfile() {
    if (!canManage.value) return
    const name = form.name.trim()
    if (!name || submitting.value) {
        form.errors = !name ? { name: l('nameRequired') } : form.errors
        return
    }

    submitting.value = true
    form.errors = {}
    const payload = { name, permissions: normalizePermissions(form.permissions) }
    const options = {
        preserveScroll: true,
        onSuccess: closeEditor,
        onError: (errors) => { form.errors = errors || {} },
        onFinish: () => { submitting.value = false },
    }

    if (isEditing.value) router.put(route('admin.permissions.update', selectedProfile.value.id), payload, options)
    else router.post(route('admin.permissions.store'), payload, options)
}

function deleteProfile() {
    if (!canManage.value || !selectedProfile.value || selectedProfile.value.is_protected || selectedProfile.value.protected || submitting.value) return
    if (!window.confirm(l('deleteConfirm'))) return

    submitting.value = true
    router.delete(route('admin.permissions.destroy', selectedProfile.value.id), {
        preserveScroll: true,
        onSuccess: closeEditor,
        onFinish: () => { submitting.value = false },
    })
}

function profileUsersCount(profile) {
    if (typeof profile.users_count === 'number') return profile.users_count
    if (Array.isArray(profile.users)) return profile.users.length
    return props.users.filter((user) => String(user.permission_profile_id || '') === String(profile.id)).length
}

function profilePermissionCount(profile) {
    return Object.values(normalizePermissions(profile.permissions)).reduce((total, actions) => total + actions.length, 0)
}

function initialAssignment(user) {
    return user.permission_profile_id ? String(user.permission_profile_id) : ''
}

function assignmentFor(user) {
    return assignmentValues.value[user.id] ?? initialAssignment(user)
}

function profileForUser(user) {
    const id = assignmentFor(user)
    return props.profiles.find((profile) => String(profile.id) === String(id)) || user.permission_profile || null
}

function syncAssignments(users) {
    assignmentValues.value = Object.fromEntries((users || []).map((user) => [user.id, initialAssignment(user)]))
}

watch(() => props.users, syncAssignments, { immediate: true, deep: true })

function assignProfile(user, event) {
    // The principal account owns the branch itself and is never converted
    // into a profile-bound employee from this assignment surface.
    if (!canManage.value || user.is_super_admin || user.is_protected_manager || assigning.value[user.id]) return

    const previous = assignmentFor(user)
    const next = event.target.value || ''
    if (isBranchScoped.value && !next) {
        assignmentValues.value = { ...assignmentValues.value, [user.id]: previous }
        return
    }
    assignmentValues.value = { ...assignmentValues.value, [user.id]: next }
    assigning.value = { ...assigning.value, [user.id]: true }
    assignmentErrors.value = { ...assignmentErrors.value, [user.id]: '' }

    router.put(route('admin.permissions.assignments.update', user.id), {
        permission_profile_id: next ? Number(next) : null,
    }, {
        preserveScroll: true,
        preserveState: true,
        onError: () => {
            assignmentValues.value = { ...assignmentValues.value, [user.id]: previous }
            assignmentErrors.value = { ...assignmentErrors.value, [user.id]: l('assignmentFailed') }
        },
        onFinish: () => {
            assigning.value = { ...assigning.value, [user.id]: false }
        },
    })
}

function roleName(role) {
    const names = {
        admin: { ar: 'مدير النظام', en: 'System administrator', ku: 'بەڕێوەبەری سیستەم' },
        owner: { ar: 'مالك', en: 'Owner', ku: 'خاوەن' },
        branch_manager: { ar: 'مدير فرع', en: 'Branch manager', ku: 'بەڕێوەبەری لق' },
    }
    return names[role]?.[locale.value] || names[role]?.ar || role || ''
}

function userStatus(user) {
    return user.status === 'active' ? l('statusActive') : l('statusInactive')
}

function initials(name) {
    return String(name || 'إ').trim().charAt(0).toUpperCase()
}

function changeBranchFilter(branchId) {
    closeEditor()
    router.get(route('admin.permissions'), branchId ? { branch_id: branchId } : {}, {
        preserveScroll: true,
        preserveState: false,
        replace: true,
    })
}
</script>

<template>
    <AdminShell :title="l('title')">
        <header class="page-heading">
            <div>
                <p>{{ l('eyebrow') }}</p>
                <h2>{{ l('title') }}</h2>
                <span>{{ l('subtitle') }}</span>
            </div>
            <div class="page-heading-actions">
                <BranchFilter :filter="branchFilter" @change="changeBranchFilter" />
                <button v-if="canManage" class="primary-button" type="button" @click="openCreate">＋ {{ l('add') }}</button>
            </div>
        </header>

        <section v-if="branchAudit" class="branch-audit-note" role="note">
            <span aria-hidden="true">◌</span>
            <p>{{ l('branchAudit') }}</p>
        </section>

        <div class="permission-layout">
            <section class="panel profiles-panel" aria-labelledby="permission-profiles-title">
                <header class="panel-heading">
                    <div>
                        <h3 id="permission-profiles-title">{{ l('profiles') }}</h3>
                        <p>{{ l('profilesHelp') }}</p>
                    </div>
                    <span class="profile-total">{{ profiles.length }}</span>
                </header>

                <div v-if="profiles.length" class="profile-list">
                    <button
                        v-for="profile in profiles"
                        :key="profile.id"
                        class="profile-card"
                        :class="{ active: isEditing && String(selectedProfile?.id) === String(profile.id) }"
                        type="button"
                        @click="openEdit(profile)"
                    >
                        <span class="profile-mark" aria-hidden="true">{{ initials(profile.name) }}</span>
                        <span class="profile-content">
                            <strong>{{ profile.name }}</strong>
                            <small>{{ profilePermissionCount(profile) }} {{ l('granted') }}</small>
                        </span>
                        <span class="profile-users">{{ profileUsersCount(profile) }}<small>{{ l('members') }}</small></span>
                    </button>
                </div>
                <div v-else class="empty-state">
                    <span aria-hidden="true">⌘</span>
                    <p>{{ l('noProfiles') }}</p>
                    <button v-if="canManage" type="button" @click="openCreate">{{ l('add') }}</button>
                </div>
            </section>

            <section class="panel editor-panel" aria-live="polite">
                <form v-if="editorMode" @submit.prevent="submitProfile">
                    <header class="editor-heading">
                        <div>
                            <p>{{ isEditing ? l('editTitle') : l('createTitle') }}</p>
                            <h3>{{ isEditing ? selectedProfile?.name : l('createTitle') }}</h3>
                            <span>{{ l('editorHelp') }}</span>
                        </div>
                        <button v-if="isEditing && canManage" class="delete-button" type="button" :disabled="Boolean(selectedProfile?.is_protected || selectedProfile?.protected || submitting)" @click="deleteProfile">
                            {{ selectedProfile?.is_protected || selectedProfile?.protected ? l('protectedProfile') : l('delete') }}
                        </button>
                    </header>

                    <label class="name-field">
                        <span>{{ l('name') }} <b aria-hidden="true">*</b></span>
                        <input v-model="form.name" type="text" maxlength="120" autocomplete="off" :disabled="!canManage" :placeholder="l('namePlaceholder')" :aria-invalid="Boolean(form.errors.name)" />
                        <small v-if="form.errors.name" class="field-error">{{ form.errors.name }}</small>
                    </label>

                    <div class="matrix-toolbar">
                        <label class="full-access-control">
                            <input type="checkbox" :checked="hasFullAccess" :disabled="!canManage" @change="setAllPermissions(!hasFullAccess)" />
                            <span class="custom-checkbox" aria-hidden="true">✓</span>
                            <span><b>{{ l('fullAccess') }}</b><small>{{ l('fullAccessHelp') }}</small></span>
                        </label>
                        <button v-if="canManage" class="clear-button" type="button" @click="setAllPermissions(!hasFullAccess)">
                            {{ hasFullAccess ? l('clearAll') : l('selectAll') }}
                        </button>
                    </div>

                    <div v-if="permissionModules.length" class="permission-modules">
                        <section v-for="module in permissionModules" :key="module.key" class="permission-module-card">
                            <header class="permission-module-heading">
                                <div>
                                    <p>{{ l('moduleActions') }}</p>
                                    <h4>{{ moduleLabel(module) }}</h4>
                                    <small>{{ selectedModuleActionCount(module) }} / {{ module.actions.length }} {{ l('selected') }}</small>
                                </div>
                                <label class="module-toggle" :title="l('selectModule')">
                                    <input type="checkbox" :checked="moduleIsComplete(module)" :disabled="!canManage" @change="toggleModule(module)" />
                                    <span aria-hidden="true">✓</span>
                                    <b>{{ l('selectAll') }}</b>
                                </label>
                            </header>

                            <div class="permission-actions" :aria-label="`${moduleLabel(module)} — ${l('moduleActions')}`">
                                <label
                                    v-for="action in module.actions"
                                    :key="`${module.key}-${action.key}`"
                                    class="permission-action"
                                    :class="{ selected: hasAction(module, action) }"
                                    :title="`${moduleLabel(module)} — ${actionLabel(action)}`"
                                >
                                    <input type="checkbox" :checked="hasAction(module, action)" :disabled="!canManage" @change="toggleAction(module, action)" />
                                    <span class="action-checkbox" aria-hidden="true">✓</span>
                                    <span class="action-copy">
                                        <b>{{ actionLabel(action) }}</b>
                                        <small v-if="actionDescription(action)">{{ actionDescription(action) }}</small>
                                    </span>
                                </label>
                            </div>
                        </section>
                    </div>
                    <div v-else class="matrix-empty">{{ l('noProfiles') }}</div>

                    <p v-if="form.errors.permissions" class="form-error" role="alert">{{ form.errors.permissions }}</p>
                    <footer class="editor-footer">
                        <button class="secondary-button" type="button" :disabled="submitting" @click="closeEditor">{{ l('cancel') }}</button>
                        <button v-if="canManage" class="primary-button" type="submit" :disabled="submitting || !permissionModules.length">
                            {{ submitting ? l('saving') : isEditing ? l('save') : l('create') }}
                        </button>
                    </footer>
                </form>

                <div v-else class="editor-empty">
                    <span aria-hidden="true">✦</span>
                    <p>{{ l('emptyEditor') }}</p>
                    <button v-if="canManage" class="primary-button" type="button" @click="openCreate">{{ l('add') }}</button>
                </div>
            </section>
        </div>

        <section class="panel assignments-panel" aria-labelledby="permission-assignment-title">
            <header class="panel-heading assignment-heading">
                <div>
                    <h3 id="permission-assignment-title">{{ l('assignTitle') }}</h3>
                    <p>{{ l('assignHelp') }}</p>
                </div>
                <span class="profile-total">{{ users.length }}</span>
            </header>

            <div v-if="users.length" class="assignment-list">
                <article v-for="user in users" :key="user.id" class="assignment-row">
                    <span class="user-avatar" aria-hidden="true">{{ initials(user.name) }}</span>
                    <div class="user-details">
                        <div><strong>{{ user.name }}</strong><span class="user-status" :class="{ inactive: user.status !== 'active' }">{{ userStatus(user) }}</span></div>
                        <small>{{ user.username || user.phone || user.email || roleName(user.role) }}</small>
                    </div>
                    <div class="assigned-summary">
                        <b>{{ profileForUser(user)?.name || l('noProfile') }}</b>
                        <small>{{ roleName(user.role) }}</small>
                    </div>
                    <label class="assignment-select">
                        <span class="sr-only">{{ l('profile') }}</span>
                        <PopupSelect :model-value="assignmentFor(user)" :disabled="!canManage || user.is_super_admin || user.is_protected_manager || Boolean(assigning[user.id])" @change="assignProfile(user, $event)">
                            <option v-if="!isBranchScoped" value="">{{ l('noProfile') }}</option>
                            <option v-for="profile in profiles" :key="profile.id" :value="String(profile.id)">{{ profile.name }}</option>
                        </PopupSelect>
                        <i v-if="assigning[user.id]">{{ l('saving') }}</i>
                    </label>
                    <p v-if="assignmentErrors[user.id]" class="assignment-error" role="alert">{{ assignmentErrors[user.id] }}</p>
                </article>
            </div>
            <div v-else class="empty-state assignment-empty">
                <span aria-hidden="true">◎</span>
                <p>{{ l('noUsers') }}</p>
            </div>
        </section>
    </AdminShell>
</template>

<style scoped>
.page-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;margin-bottom:18px}.page-heading p{margin:0 0 4px;color:var(--primary);font-size:10px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.page-heading h2{margin:0;color:var(--ink);font-size:24px;font-weight:900}.page-heading span{display:block;max-width:730px;margin-top:5px;color:var(--ink-faint);font-size:11px;font-weight:650;line-height:1.75}.primary-button,.secondary-button,.clear-button,.delete-button{border:0;border-radius:10px;font:800 11px var(--font);cursor:pointer;transition:transform .16s ease,opacity .16s ease,background .16s ease}.primary-button{min-height:39px;padding:9px 14px;color:#062033;background:linear-gradient(135deg,var(--primary),#0ea5e9)}.primary-button:hover:not(:disabled),.secondary-button:hover:not(:disabled),.clear-button:hover:not(:disabled),.delete-button:hover:not(:disabled){transform:translateY(-1px)}.primary-button:disabled,.secondary-button:disabled,.delete-button:disabled{cursor:wait;opacity:.62}.permission-layout{display:grid;grid-template-columns:minmax(250px,.73fr) minmax(0,1.9fr);gap:16px;align-items:start}.panel{border:1px solid var(--border);border-radius:17px;background:var(--surface);box-shadow:var(--shadow)}.profiles-panel{overflow:hidden}.panel-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:16px;border-bottom:1px solid var(--border)}.panel-heading h3{margin:0;color:var(--ink);font-size:13px;font-weight:900}.panel-heading p{max-width:600px;margin:4px 0 0;color:var(--ink-faint);font-size:9.5px;font-weight:650;line-height:1.65}.profile-total{display:grid;min-width:25px;height:25px;place-items:center;border-radius:8px;color:var(--primary-strong);background:var(--primary-tint);font-size:10px;font-weight:900}.profile-list{display:grid;padding:9px;gap:6px;max-height:560px;overflow:auto}.profile-card{width:100%;display:flex;align-items:center;gap:9px;padding:10px;border:1px solid transparent;border-radius:12px;color:var(--ink);background:transparent;font:inherit;text-align:start;cursor:pointer;transition:border-color .16s ease,background .16s ease}.profile-card:hover{background:var(--surface-2)}.profile-card.active{border-color:color-mix(in srgb,var(--primary) 54%,var(--border));background:var(--primary-tint)}.profile-mark,.user-avatar{display:grid;place-items:center;flex:none;border-radius:10px;color:#062033;background:linear-gradient(135deg,var(--primary),#0ea5e9);font-size:11px;font-weight:900}.profile-mark{width:34px;height:34px}.profile-content{display:grid;min-width:0;flex:1;gap:2px}.profile-content strong{overflow:hidden;font-size:11px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.profile-content small{overflow:hidden;color:var(--ink-faint);font-size:8.5px;font-weight:750;text-overflow:ellipsis;white-space:nowrap}.profile-users{display:grid;justify-items:center;gap:1px;min-width:33px;color:var(--ink);font-size:12px;font-weight:900}.profile-users small{color:var(--ink-faint);font-size:7.5px;font-weight:750}.empty-state{display:grid;justify-items:center;gap:7px;padding:30px 17px;color:var(--ink-faint);text-align:center}.empty-state>span{display:grid;width:35px;height:35px;place-items:center;border-radius:11px;color:var(--primary);background:var(--primary-tint);font-size:17px;font-weight:900}.empty-state p{max-width:245px;margin:0;font-size:10px;font-weight:700;line-height:1.75}.empty-state button{border:0;border-radius:8px;padding:6px 9px;color:var(--primary-strong);background:var(--primary-tint);font:800 9.5px var(--font);cursor:pointer}.editor-panel{min-height:360px;overflow:hidden}.editor-panel form{display:grid;gap:15px;padding:19px}.editor-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:13px}.editor-heading p{margin:0;color:var(--primary);font-size:10px;font-weight:900}.editor-heading h3{margin:3px 0 0;color:var(--ink);font-size:18px;font-weight:900}.editor-heading span{display:block;max-width:600px;margin-top:5px;color:var(--ink-faint);font-size:10px;font-weight:650;line-height:1.7}.delete-button{min-height:31px;flex:none;padding:7px 9px;color:var(--danger);background:var(--danger-tint);font-size:9.5px}.delete-button:disabled{cursor:not-allowed;color:var(--ink-faint);background:var(--surface-2)}.name-field{display:grid;gap:6px}.name-field>span{color:var(--ink-soft);font-size:10.5px;font-weight:850}.name-field b{color:var(--danger)}.name-field input,.assignment-select select{width:100%;box-sizing:border-box;border:1px solid var(--border);border-radius:10px;outline:none;color:var(--ink);background:var(--surface-2);font:700 11px var(--font)}.name-field input{min-height:42px;padding:9px 10px}.name-field input:focus,.assignment-select select:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-tint)}.field-error,.form-error{color:var(--danger);font-size:9.5px;font-weight:750}.matrix-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 11px;border:1px solid var(--border);border-radius:12px;background:var(--surface-2)}.full-access-control{display:flex;align-items:center;gap:8px;cursor:pointer}.full-access-control input,.permission-control input{position:absolute;width:1px;height:1px;overflow:hidden;opacity:0;pointer-events:none}.full-access-control .custom-checkbox,.permission-control>span{display:grid;place-items:center;width:18px;height:18px;flex:none;border:1px solid var(--border);border-radius:5px;color:transparent;background:var(--surface);font-size:12px;font-weight:900;line-height:1;transition:color .15s,background .15s,border-color .15s}.full-access-control input:checked + .custom-checkbox,.permission-control input:checked + span{border-color:var(--primary);color:#062033;background:var(--primary)}.full-access-control>span:last-child{display:grid;gap:1px}.full-access-control b{color:var(--ink);font-size:10.5px}.full-access-control small{color:var(--ink-faint);font-size:8.5px;font-weight:700}.clear-button{padding:6px 8px;color:var(--primary-strong);background:var(--primary-tint);font-size:9px}.permission-table-wrap{overflow:auto;border:1px solid var(--border);border-radius:12px}.permission-table{min-width:620px}.permission-row{display:grid;grid-template-columns:minmax(170px,1fr) repeat(var(--permission-columns),minmax(80px,1fr));min-height:52px;border-top:1px solid var(--border)}.permission-row:first-child{border-top:0}.permission-table-head{min-height:37px;color:var(--ink-faint);background:var(--surface-2);font-size:9.5px;font-weight:900}.permission-table-head>span{display:grid;place-items:center;padding:6px;text-align:center}.permission-table-head>span:first-child{justify-content:start;padding-inline:13px}.module-label{display:grid;align-content:center;justify-items:start;gap:2px;border:0;border-inline-end:1px solid var(--border);padding:9px 13px;color:var(--ink);background:transparent;font:inherit;text-align:start;cursor:pointer;transition:background .15s}.module-label:hover{background:var(--primary-tint)}.module-label b{font-size:10.5px;font-weight:900}.module-label small{color:var(--ink-faint);font-size:8px;font-weight:700}.permission-control,.permission-empty{display:grid;place-items:center;border-inline-end:1px solid var(--border)}.permission-control:last-child,.permission-empty:last-child{border-inline-end:0}.permission-control{cursor:pointer}.permission-empty{color:var(--ink-faint);font-size:11px}.matrix-empty{padding:20px;border:1px dashed var(--border);border-radius:12px;color:var(--ink-faint);font-size:10px;font-weight:700;text-align:center}.editor-footer{display:flex;justify-content:flex-end;gap:8px;padding-top:2px}.secondary-button{min-height:39px;padding:9px 13px;border:1px solid var(--border);color:var(--ink-soft);background:var(--surface-2)}.editor-empty{min-height:360px;display:grid;place-content:center;justify-items:center;gap:10px;padding:26px;color:var(--ink-faint);text-align:center}.editor-empty>span{display:grid;width:44px;height:44px;place-items:center;border-radius:14px;color:var(--primary);background:var(--primary-tint);font-size:20px}.editor-empty p{max-width:290px;margin:0;font-size:11px;font-weight:700;line-height:1.75}.assignments-panel{margin-top:16px;overflow:hidden}.assignment-heading{border-bottom:1px solid var(--border)}.assignment-list{display:grid}.assignment-row{position:relative;display:grid;grid-template-columns:auto minmax(150px,1fr) minmax(140px,.7fr) minmax(180px,.8fr);align-items:center;gap:12px;padding:12px 16px;border-top:1px solid var(--border)}.assignment-row:first-child{border-top:0}.user-avatar{width:35px;height:35px;border-radius:50%}.user-details{display:grid;min-width:0;gap:3px}.user-details>div{display:flex;align-items:center;gap:6px;min-width:0}.user-details strong{overflow:hidden;color:var(--ink);font-size:11px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.user-details>small{overflow:hidden;color:var(--ink-faint);font-size:8.5px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}.user-status{flex:none;padding:2px 5px;border-radius:999px;color:var(--success);background:var(--success-tint);font-size:7.5px;font-weight:850}.user-status.inactive{color:var(--danger);background:var(--danger-tint)}.assigned-summary{display:grid;min-width:0;gap:2px}.assigned-summary b{overflow:hidden;color:var(--ink-soft);font-size:10px;font-weight:850;text-overflow:ellipsis;white-space:nowrap}.assigned-summary small{color:var(--ink-faint);font-size:8px;font-weight:700}.assignment-select{position:relative;min-width:0}.assignment-select select{min-height:37px;padding:7px 27px 7px 9px;font-size:10px;cursor:pointer}.assignment-select select:disabled{cursor:wait;opacity:.65}.assignment-select i{position:absolute;inset-inline-end:8px;top:50%;max-width:65px;overflow:hidden;color:var(--primary-strong);font-size:7.5px;font-weight:900;text-overflow:ellipsis;transform:translateY(-50%);white-space:nowrap;pointer-events:none}.assignment-error{grid-column:2/-1;margin:0;color:var(--danger);font-size:8.5px;font-weight:750}.assignment-empty{padding:28px}.sr-only{position:absolute;width:1px;height:1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}@media(max-width:1000px){.permission-layout{grid-template-columns:1fr}.profiles-panel{max-height:none}.profile-list{max-height:250px}.assignment-row{grid-template-columns:auto minmax(140px,1fr) minmax(180px,.9fr)}.assigned-summary{display:none}}@media(max-width:680px){.page-heading{align-items:stretch;flex-direction:column}.page-heading .primary-button{align-self:flex-start}.editor-panel form{padding:15px}.editor-heading{align-items:stretch;flex-direction:column}.delete-button{align-self:flex-start}.matrix-toolbar{align-items:flex-start;flex-direction:column}.permission-row{grid-template-columns:minmax(142px,1fr) repeat(var(--permission-columns),minmax(67px,1fr))}.permission-table{min-width:520px}.assignment-row{grid-template-columns:auto minmax(0,1fr);gap:8px 10px;padding:12px}.assignment-select{grid-column:2}.assignment-error{grid-column:2}.user-details>div{flex-wrap:wrap}.assignment-heading{align-items:flex-start}.panel-heading{padding:14px}.primary-button{font-size:10px}}@media(max-width:430px){.editor-footer{display:grid;grid-template-columns:1fr 1fr}.editor-footer button{width:100%}.page-heading h2{font-size:21px}}
.permission-modules{display:grid;grid-template-columns:repeat(auto-fit,minmax(255px,1fr));gap:10px}.permission-module-card{overflow:hidden;border:1px solid var(--border);border-radius:12px;background:var(--surface-2)}.permission-module-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 12px;border-bottom:1px solid var(--border);background:var(--surface)}.permission-module-heading p{margin:0 0 2px;color:var(--primary);font-size:7.5px;font-weight:900;letter-spacing:.05em;text-transform:uppercase}.permission-module-heading h4{margin:0;color:var(--ink);font-size:11px;font-weight:900}.permission-module-heading small{display:block;margin-top:3px;color:var(--ink-faint);font-size:8px;font-weight:750}.module-toggle{display:flex;align-items:center;flex:none;gap:5px;color:var(--primary-strong);font-size:8.5px;font-weight:850;cursor:pointer}.module-toggle input,.permission-action input{position:absolute;width:1px;height:1px;overflow:hidden;opacity:0;pointer-events:none}.module-toggle>span,.action-checkbox{display:grid;place-items:center;width:17px;height:17px;flex:none;border:1px solid var(--border);border-radius:5px;color:transparent;background:var(--surface);font-size:11px;font-weight:900;line-height:1;transition:color .15s,background .15s,border-color .15s}.module-toggle input:checked + span,.permission-action input:checked + .action-checkbox{border-color:var(--primary);color:#062033;background:var(--primary)}.module-toggle input:disabled + span,.permission-action input:disabled + .action-checkbox{opacity:.58}.permission-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(142px,1fr));gap:6px;padding:9px}.permission-action{display:flex;align-items:flex-start;gap:7px;min-width:0;padding:8px;border:1px solid var(--border);border-radius:9px;color:var(--ink);background:var(--surface);cursor:pointer;transition:border-color .15s,background .15s,transform .15s}.permission-action:hover{border-color:color-mix(in srgb,var(--primary) 52%,var(--border));background:var(--primary-tint);transform:translateY(-1px)}.permission-action.selected{border-color:color-mix(in srgb,var(--primary) 60%,var(--border));background:var(--primary-tint)}.action-copy{display:grid;min-width:0;gap:2px}.action-copy b{overflow:hidden;font-size:9.5px;font-weight:850;line-height:1.35;text-overflow:ellipsis}.action-copy small{color:var(--ink-faint);font-size:7.8px;font-weight:650;line-height:1.45}@media(max-width:680px){.permission-modules{grid-template-columns:1fr}.permission-actions{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:430px){.permission-actions{grid-template-columns:1fr}.module-toggle b{display:none}}
.page-heading-actions{display:flex;align-items:center;justify-content:flex-end;flex-wrap:wrap;gap:9px}.branch-audit-note{display:flex;align-items:flex-start;gap:9px;margin:-4px 0 16px;padding:12px 14px;border:1px solid color-mix(in srgb,var(--accent) 45%,var(--border));border-radius:14px;background:var(--accent-tint)}.branch-audit-note>span{display:grid;place-items:center;flex:none;width:19px;height:19px;border-radius:50%;color:#fff;background:var(--accent);font-size:13px;font-weight:900}.branch-audit-note p{margin:0;color:var(--ink-soft);font-size:10px;font-weight:800;line-height:1.7}@media(max-width:680px){.page-heading-actions{align-items:stretch;width:100%}.page-heading-actions .primary-button{flex:1}}
</style>
