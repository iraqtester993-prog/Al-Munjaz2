<script setup>
import { computed, ref, watch } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppShell from '../../Components/AppShell.vue'
import SheetModal from '../../Components/SheetModal.vue'

const props = defineProps({
    vehicles: { type: Object, default: () => ({}) },
    walletBalance: { type: Number, default: 0 },
    walletBudget: { type: Number, default: 0 },
    profile: { type: Object, default: () => ({ documents: [] }) },
})

const page = usePage()
const user = computed(() => page.props.auth?.user || {})
const locale = computed(() => page.props.locale || 'ar')
const isCourier = computed(() => user.value.role === 'courier')
const showEdit = ref(false)
const showDocuments = ref(false)
const showVerification = ref(false)
const activeTheme = ref(user.value.theme || document.body?.dataset.theme || 'light')

const accountForm = useForm({
    name: user.value.name || '', phone: user.value.phone || '',
    shop_name: user.value.shop_name || props.profile.shop_name || '',
    address: user.value.address || props.profile.address || '',
})
const verificationForm = useForm({
    name: user.value.name || '', address: user.value.address || props.profile.address || '',
    phone: user.value.phone || '', identity_number: '',
    id_front_document: null, id_back_document: null, residence_document: null, residence_back_document: null,
})

const locales = [
    { key: 'ar', label: 'عربي' }, { key: 'ku', label: 'کوردی' }, { key: 'en', label: 'English' },
]
const documentFields = computed(() => [
    { key: 'id_front_document', existing: 'id_front', label: `${t('National ID Card')} — ${t('Front')}` },
    { key: 'id_back_document', existing: 'id_back', label: `${t('National ID Card')} — ${t('Back')}` },
    { key: 'residence_document', existing: 'residence', label: `${t('Residence Card')} — ${t('Front')}` },
    { key: 'residence_back_document', existing: 'residence_back', label: `${t('Residence Card')} — ${t('Back')}` },
])
const provinceName = computed(() => props.profile.province?.[`name_${locale.value}`] || props.profile.province?.name_ar || t('Not set'))
const vehicleName = computed(() => props.vehicles[props.profile.vehicle]?.[locale.value] || props.vehicles[props.profile.vehicle]?.ar || t('Not set'))
const roleName = computed(() => isCourier.value ? t('Courier') : t('Merchant'))
const selectedLocale = computed(() => locales.find((item) => item.key === locale.value)?.label || 'عربي')

watch(() => user.value.theme, (theme) => { if (theme) applyTheme(theme) })

function icon(name) {
    return {
        globe: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm-9-9h18M12 3c2.2 2.45 3.3 5.45 3.3 9S14.2 18.55 12 21c-2.2-2.45-3.3-5.45-3.3-9S9.8 5.45 12 3Z',
        sun: 'M12 3v2m0 14v2M5.64 5.64l1.42 1.42m9.88 9.88 1.42 1.42M3 12h2m14 0h2M5.64 18.36l1.42-1.42m9.88-9.88 1.42-1.42M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
        bell: 'M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6Zm5 11a2 2 0 0 0 4 0',
        shield: 'M12 3 20 6v5c0 5-3.2 8.3-8 10-4.8-1.7-8-5-8-10V6l8-3Z M9.5 12l1.7 1.7 3.6-3.6',
        chat: 'M21 12a8 8 0 0 1-8 8H4l1.5-3.5A8 8 0 1 1 21 12Z',
        info: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0-10v5m0-8v.01',
        logout: 'M10 17l5-5-5-5m5 5H3m12-7h3a3 3 0 0 1 3 3v8a3 3 0 0 1-3 3h-3',
        user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 9a7 7 0 0 1 14 0',
        wallet: 'M20 7H6a2 2 0 0 1-2-2 2 2 0 0 1 2-2h13v3 M20 7a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6 M16 14h.01',
    }[name] || ''
}
function applyTheme(theme) { activeTheme.value = theme; document.documentElement.dataset.theme = theme; document.body.dataset.theme = theme }
function setTheme(theme) {
    if (theme === activeTheme.value) return
    const previous = activeTheme.value
    applyTheme(theme)
    router.post(route('profile.theme'), { theme }, { preserveScroll: true, preserveState: true, onError: () => applyTheme(previous) })
}
function setLocale(value) {
    if (value === locale.value) return
    router.post(route('profile.locale'), { locale: value }, { preserveScroll: true, onSuccess: () => window.location.reload() })
}
function openEdit() {
    accountForm.name = user.value.name || ''; accountForm.phone = user.value.phone || ''
    accountForm.shop_name = user.value.shop_name || props.profile.shop_name || ''; accountForm.address = user.value.address || props.profile.address || ''
    showEdit.value = true
}
function saveAccount() { accountForm.post(route('profile.update'), { preserveScroll: true, onSuccess: () => (showEdit.value = false) }) }
function openVerification() {
    verificationForm.name = user.value.name || ''; verificationForm.address = user.value.address || props.profile.address || ''
    verificationForm.phone = user.value.phone || ''; verificationForm.identity_number = ''
    for (const item of documentFields.value) verificationForm[item.key] = null
    verificationForm.clearErrors(); showVerification.value = true
}
function selectVerificationFile(event, key) { verificationForm[key] = event.target.files?.[0] || null }
function existingDocument(type) { return props.profile.documents?.find((item) => item.type === type) }
function documentStatus(status) { return status === 'approved' ? t('Verified') : status === 'rejected' ? t('Rejected') : t('Pending') }
function submitVerification() {
    verificationForm.post(route('profile.verification'), {
        forceFormData: true, preserveScroll: true,
        onSuccess: () => { showVerification.value = false; for (const item of documentFields.value) verificationForm[item.key] = null },
    })
}
function openSupport() { router.post(route('app.chats.open')) }
function formatMoney(value) { return new Intl.NumberFormat(locale.value === 'ar' ? 'ar-IQ' : locale.value === 'ku' ? 'ku-IQ' : 'en-US').format(Number(value || 0)) }
function logout() { router.post(route('logout')) }
</script>

<template>
    <AppShell :title="t('My Profile')">
        <section class="profile-head">
            <div class="profile-avatar">{{ user.name?.charAt(0) || '؟' }}</div>
            <b>{{ user.name }}</b><span class="mono">{{ user.phone }}</span><small>{{ roleName }} · {{ provinceName }}</small>
        </section>

        <section class="list-card profile-settings">
            <div class="settings-row"><div class="sri"><svg viewBox="0 0 24 24"><path :d="icon('globe')" /></svg></div><div class="srt">{{ t('Language') }}</div><div class="seg" :aria-label="t('Language')"><button v-for="item in locales" :key="item.key" type="button" :class="{ active: locale === item.key }" @click="setLocale(item.key)">{{ item.label }}</button></div></div>
            <div class="settings-row"><div class="sri"><svg viewBox="0 0 24 24"><path :d="icon('sun')" /></svg></div><div class="srt">{{ t('Appearance') }}</div><div class="seg" :aria-label="t('Appearance')"><button type="button" :class="{ active: activeTheme !== 'dark' }" @click="setTheme('light')">{{ t('Light') }}</button><button type="button" :class="{ active: activeTheme === 'dark' }" @click="setTheme('dark')">{{ t('Dark') }}</button></div></div>
        </section>

        <section v-if="isCourier" class="list-card profile-wallet">
            <button class="settings-row clickable" type="button" @click="$inertia.visit(route('app.wallet'))"><div class="sri"><svg viewBox="0 0 24 24"><path :d="icon('shield')" /></svg></div><div class="srt">{{ t('Budget') }}</div><b class="wallet-value mono">{{ formatMoney(walletBudget) }} {{ t('IQD') }}</b></button>
            <button class="settings-row clickable" type="button" @click="$inertia.visit(route('app.wallet'))"><div class="sri"><svg viewBox="0 0 24 24"><path :d="icon('wallet')" /></svg></div><div class="srt">{{ t('Recharge Balance') }}</div><b class="wallet-value mono primary-value">{{ formatMoney(walletBalance) }} {{ t('IQD') }}</b></button>
        </section>

        <section class="list-card profile-actions">
            <button class="settings-row clickable" type="button" @click="openEdit"><div class="sri"><svg viewBox="0 0 24 24"><path :d="icon('user')" /></svg></div><div class="srt">{{ t('Account Details') }}</div><span class="srv">{{ selectedLocale }} · {{ t('Edit') }}</span></button>
            <a class="settings-row clickable" :href="route('app.notifications')"><div class="sri"><svg viewBox="0 0 24 24"><path :d="icon('bell')" /></svg></div><div class="srt">{{ t('Notifications') }}</div><span class="srv">›</span></a>
            <button v-if="!isCourier" class="settings-row clickable" type="button" @click="openVerification"><div class="sri"><svg viewBox="0 0 24 24"><path :d="icon('shield')" /></svg></div><div class="srt">{{ t('Account Verification') }}</div><span class="srv">›</span></button>
            <button v-else class="settings-row clickable" type="button" @click="showDocuments = true"><div class="sri"><svg viewBox="0 0 24 24"><path :d="icon('shield')" /></svg></div><div class="srt">{{ t('Account Verification') }}</div><span class="srv">›</span></button>
            <button class="settings-row clickable" type="button" @click="openSupport"><div class="sri"><svg viewBox="0 0 24 24"><path :d="icon('chat')" /></svg></div><div class="srt">{{ t('Help & Support') }}</div><span class="srv">›</span></button>
            <div class="settings-row"><div class="sri"><svg viewBox="0 0 24 24"><path :d="icon('info')" /></svg></div><div class="srt">{{ t('About the app') }}</div><span class="srv">v1.0</span></div>
        </section>

        <button class="profile-logout" type="button" @click="logout"><svg viewBox="0 0 24 24"><path :d="icon('logout')" /></svg>{{ t('Logout') }}</button>

        <SheetModal :open="showEdit" :title="t('Account Details')" :subtitle="roleName" @close="showEdit = false">
            <div class="field"><label>{{ t('Full Name') }}</label><input v-model="accountForm.name" :placeholder="t('Full Name')" /><span v-if="accountForm.errors.name" class="field-error">{{ accountForm.errors.name }}</span></div>
            <div v-if="!isCourier" class="field"><label>{{ t('Shop Name') }}</label><input v-model="accountForm.shop_name" :placeholder="t('Shop Name')" /><span v-if="accountForm.errors.shop_name" class="field-error">{{ accountForm.errors.shop_name }}</span></div>
            <div class="field"><label>{{ isCourier ? t('Address') : t('Shop Address') }}</label><input v-model="accountForm.address" :placeholder="t('Address')" /><span v-if="accountForm.errors.address" class="field-error">{{ accountForm.errors.address }}</span></div>
            <div class="field"><label>{{ t('Governorate') }}</label><input :value="provinceName" disabled /></div><div v-if="isCourier" class="field"><label>{{ t('Vehicle') }}</label><input :value="vehicleName" disabled /></div>
            <div class="field"><label>{{ t('Phone Number') }}</label><input v-model="accountForm.phone" dir="ltr" :placeholder="t('Phone Number')" /><span v-if="accountForm.errors.phone" class="field-error">{{ accountForm.errors.phone }}</span></div>
            <button class="btn btn-primary save-button" :disabled="accountForm.processing" @click="saveAccount"><span v-if="accountForm.processing" class="loader" /><span v-else>{{ t('Save') }}</span></button>
        </SheetModal>

        <SheetModal :open="showDocuments" :title="t('Account Verification')" @close="showDocuments = false">
            <div v-if="profile.documents?.length" class="document-status-list"><div v-for="document in profile.documents" :key="document.id" class="document-status-row"><span>{{ documentFields.find((item) => item.existing === document.type)?.label || document.type }}</span><b :class="document.status">{{ documentStatus(document.status) }}</b></div></div>
            <div v-else class="empty-hint">{{ t('No documents uploaded') }}</div>
        </SheetModal>

        <SheetModal :open="showVerification" :title="t('Account Verification')" @close="showVerification = false">
            <p class="verification-copy">{{ t('Submit your details and documents. Your account remains active while the documents are reviewed.') }}</p>
            <div class="field"><label>{{ t('Full Name') }}</label><input v-model="verificationForm.name" :placeholder="t('Full Name')" /><span v-if="verificationForm.errors.name" class="field-error">{{ verificationForm.errors.name }}</span></div>
            <div class="field"><label>{{ t('Address') }}</label><input v-model="verificationForm.address" :placeholder="t('City — Area')" /><span v-if="verificationForm.errors.address" class="field-error">{{ verificationForm.errors.address }}</span></div>
            <div class="field"><label>{{ t('Phone Number') }}</label><input v-model="verificationForm.phone" dir="ltr" :placeholder="t('Phone Number')" /><span v-if="verificationForm.errors.phone" class="field-error">{{ verificationForm.errors.phone }}</span></div>
            <div class="field"><label>{{ t('National ID Number') }}</label><input v-model="verificationForm.identity_number" dir="ltr" :placeholder="t('National ID Number')" /><span v-if="verificationForm.errors.identity_number" class="field-error">{{ verificationForm.errors.identity_number }}</span></div>
            <div v-for="item in documentFields" :key="item.key" class="verification-file"><label>{{ item.label }}</label><label class="upload-zone" :class="{ uploaded: verificationForm[item.key] || existingDocument(item.existing) }"><input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" @change="selectVerificationFile($event, item.key)" /><span>{{ verificationForm[item.key]?.name || (existingDocument(item.existing) ? `${t('Uploaded')} · ${documentStatus(existingDocument(item.existing).status)}` : t('Tap to upload')) }}</span></label><small v-if="verificationForm.errors[item.key]" class="field-error">{{ verificationForm.errors[item.key] }}</small></div>
            <button class="btn btn-primary save-button" :disabled="verificationForm.processing" @click="submitVerification"><span v-if="verificationForm.processing" class="loader" /><span v-else>{{ t('Submit Verification') }}</span></button>
        </SheetModal>
    </AppShell>
</template>

<style scoped>
.profile-head{display:flex;align-items:center;flex-direction:column;padding:8px 0 21px;text-align:center}.profile-avatar{display:grid;place-items:center;width:74px;height:74px;margin-bottom:9px;border:1px solid color-mix(in srgb,var(--primary) 14%,var(--border));border-radius:50%;background:var(--primary-tint);color:var(--primary-strong);font-size:28px;font-weight:950;box-shadow:0 10px 22px -17px var(--primary)}.profile-head>b{font-size:16px;font-weight:950;line-height:1.4}.profile-head>span{margin-top:1px;color:var(--ink-faint);font-size:11px;font-weight:700}.profile-head>small{margin-top:2px;color:var(--primary);font-size:9.5px;font-weight:800}.list-card{margin-bottom:14px;border:1px solid var(--border);border-radius:16px;background:var(--surface);overflow:hidden}.settings-row{display:flex;width:100%;align-items:center;gap:10px;min-height:53px;padding:10px 13px;border-bottom:1px solid var(--border);color:var(--ink);font:inherit;text-align:start}.settings-row:last-child{border-bottom:0}.clickable{transition:background .15s}.clickable:active{background:var(--surface-2)}.sri{display:grid;place-items:center;width:28px;height:28px;border-radius:9px;background:var(--surface-2);color:var(--ink-soft);flex:none}.sri svg,.profile-logout svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.srt{flex:1;min-width:0;font-size:11.5px;font-weight:850}.srv{color:var(--ink-faint);font-size:10px;font-weight:750;white-space:nowrap}.seg{display:flex;padding:2px;border-radius:10px;background:var(--surface-2)}.seg button{padding:4px 7px;border-radius:8px;color:var(--ink-faint);font:inherit;font-size:9px;font-weight:850;white-space:nowrap}.seg button.active{background:var(--surface);color:var(--primary-strong);box-shadow:0 1px 5px rgba(0,0,0,.12)}.wallet-value{color:var(--accent);font-size:11px;font-weight:900;white-space:nowrap}.primary-value{color:var(--primary)}.profile-logout{display:flex;width:100%;align-items:center;justify-content:center;gap:8px;padding:11px;border:1px solid color-mix(in srgb,var(--danger) 35%,transparent);border-radius:12px;background:var(--danger-tint);color:var(--danger);font:inherit;font-size:11.5px;font-weight:900}.field{margin-bottom:13px}.field label,.verification-file>label:first-child{display:block;margin-bottom:6px;color:var(--ink-soft);font-size:10.5px;font-weight:800}.field input{width:100%;padding:10px 11px;border:1px solid var(--border);border-radius:10px;outline:none;background:var(--surface);color:var(--ink);font:inherit;font-size:12px}.field input:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-tint)}.field input:disabled{color:var(--ink-faint);background:var(--surface-2)}.field-error{display:block;margin-top:4px;color:var(--danger);font-size:9px;font-weight:750}.save-button{width:100%;margin-top:3px}.document-status-list{border:1px solid var(--border);border-radius:12px;overflow:hidden}.document-status-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px;border-bottom:1px solid var(--border);font-size:10.5px;font-weight:750}.document-status-row:last-child{border-bottom:0}.document-status-row b{font-size:9px}.document-status-row b.approved{color:var(--success)}.document-status-row b.rejected{color:var(--danger)}.document-status-row b.pending{color:var(--warning)}.verification-copy{margin:-2px 0 15px;color:var(--ink-soft);font-size:10.5px;font-weight:700;line-height:1.75}.verification-file{margin:14px 0}.upload-zone{display:flex;min-height:54px;align-items:center;justify-content:center;padding:10px;border:1.5px dashed var(--border);border-radius:11px;background:var(--surface-2);color:var(--ink-soft);cursor:pointer;font-size:10px;font-weight:800;text-align:center}.upload-zone.uploaded{border-color:var(--success);background:var(--success-tint);color:var(--success)}.upload-zone input{display:none}@media(max-width:350px){.settings-row{gap:7px;padding-inline:9px}.seg button{padding:4px 5px;font-size:8px}.sri{width:25px;height:25px}}
</style>
