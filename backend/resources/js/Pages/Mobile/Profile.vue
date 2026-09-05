<script setup>
import { computed, ref, watch } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppShell from '../../Components/AppShell.vue'
import SheetModal from '../../Components/SheetModal.vue'
import PushNotificationSettings from '../../Components/PushNotificationSettings.vue'
import DeveloperInfoSheet from '../../Components/DeveloperInfoSheet.vue'
import LegalInfoSheet from '../../Components/LegalInfoSheet.vue'
import OrderMapPicker from '../../Components/OrderMapPicker.vue'
import { bytesToMegabytes, CourierDocumentError, prepareCourierDocument } from '../../Utils/courierDocuments'
import { isIraqiMobilePhone, normalizeIraqiMobilePhone } from '../../Utils/iraqiPhone'

const props = defineProps({
    vehicles: { type: Object, default: () => ({}) },
    walletBalance: { type: Number, default: 0 },
    walletBudget: { type: Number, default: 0 },
    walletBudgetBalance: { type: Number, default: null },
    courierUploadLimits: { type: Object, default: () => ({}) },
    merchantUploadLimits: { type: Object, default: () => ({}) },
    profile: { type: Object, default: () => ({ documents: [] }) },
    legalContent: { type: Object, default: () => ({}) },
})

const page = usePage()
const user = computed(() => page.props.auth?.user || {})
const locale = computed(() => page.props.locale || 'ar')
const isCourier = computed(() => ['courier', 'pickup_courier', 'delivery_courier', 'transporter'].includes(user.value.role))
const isMerchant = computed(() => user.value.role === 'merchant')
const showEdit = ref(false)
const showVerification = ref(Boolean(page.props.errors?.documents))
const showDeveloperInfo = ref(false)
const showLegalInfo = ref(false)
const showDeleteAccountNotice = ref(false)
const replacingDocumentId = ref(null)
const documentUploadError = ref('')
const verificationPreparingDocuments = ref({})
const verificationUploadError = ref(page.props.errors?.documents || '')
const activeTheme = ref(user.value.theme || document.body?.dataset.theme || 'light')

// Profile receives the complete point as a nested object, while the shared
// shell also carries the three values.  Supporting both keeps the edit sheet
// stable during an Inertia partial reload.
const savedMerchantPickup = computed(() => {
    const location = props.profile?.merchant_pickup_location || {}

    return {
        latitude: location.latitude ?? user.value.merchant_pickup_latitude ?? '',
        longitude: location.longitude ?? user.value.merchant_pickup_longitude ?? '',
        label: location.label ?? user.value.merchant_pickup_location_label ?? '',
    }
})

const merchantPickupCopy = computed(() => ({
    ar: {
        title: 'موقع متجر الاستلام',
        help: 'حدّد موقع متجرك مرة واحدة. سيعتمده كل طلب جديد حتى يعرف المندوب نقطة الاستلام ويصل إليها.',
        saved: 'الموقع المعتمد للطلبات الجديدة',
    },
    en: {
        title: 'Shop pickup location',
        help: 'Set your shop location once. Every new order will use it so the courier knows where to collect it.',
        saved: 'Default location for new orders',
    },
    ku: {
        title: 'شوێنی وەرگرتنی فرۆشگا',
        help: 'شوێنی فرۆشگاکەت جارێک دیاری بکە. هەر داواکارییەکی نوێ ئەم شوێنە بەکاردهێنێت بۆ ئەوەی گەیەنەر شوێنی وەرگرتن بزانێت.',
        saved: 'شوێنی بنەڕەتی بۆ داواکارییە نوێکان',
    },
}[locale.value] || {
    title: 'موقع متجر الاستلام',
    help: 'حدّد موقع متجرك مرة واحدة. سيعتمده كل طلب جديد حتى يعرف المندوب نقطة الاستلام ويصل إليها.',
    saved: 'الموقع المعتمد للطلبات الجديدة',
}))

const accountForm = useForm({
    name: user.value.name || '', phone: user.value.phone || '',
    shop_name: user.value.shop_name || props.profile.shop_name || '',
    address: user.value.address || props.profile.address || '',
    vehicle: user.value.vehicle || props.profile.vehicle || '',
    merchant_pickup_latitude: savedMerchantPickup.value.latitude,
    merchant_pickup_longitude: savedMerchantPickup.value.longitude,
    merchant_pickup_location_label: savedMerchantPickup.value.label,
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
const courierDocumentLabels = computed(() => ({
    residence: `${t('Residence Card')} — ${t('Front')}`,
    residence_back: `${t('Residence Card')} — ${t('Back')}`,
    id_front: `${t('National ID Card')} — ${t('Front')}`,
    id_back: `${t('National ID Card')} — ${t('Back')}`,
    license_front: `${t('Driving License')} — ${t('Front')}`,
    license_back: `${t('Driving License')} — ${t('Back')}`,
    driving_license: t('Driving License'),
}))
const courierDocumentLimits = computed(() => {
    const maxFileKilobytes = Math.max(256, Math.min(Number(props.courierUploadLimits?.maxFileKilobytes) || 480, 2048))
    const targetImageKilobytes = Math.max(128, Math.min(Number(props.courierUploadLimits?.targetImageKilobytes) || 300, maxFileKilobytes))

    return {
        maxFileBytes: maxFileKilobytes * 1024,
        targetImageBytes: targetImageKilobytes * 1024,
    }
})
const merchantDocumentLimits = computed(() => {
    const maxFileKilobytes = Math.max(256, Math.min(Number(props.merchantUploadLimits?.maxFileKilobytes) || 480, 2048))
    const maxTotalKilobytes = Math.max(maxFileKilobytes, Math.min(Number(props.merchantUploadLimits?.maxTotalKilobytes) || 1600, 4096))
    const targetImageKilobytes = Math.max(128, Math.min(Number(props.merchantUploadLimits?.targetImageKilobytes) || 300, maxFileKilobytes))

    return {
        maxFileBytes: maxFileKilobytes * 1024,
        maxTotalBytes: maxTotalKilobytes * 1024,
        targetImageBytes: targetImageKilobytes * 1024,
    }
})
const provinceName = computed(() => props.profile.province?.[`name_${locale.value}`] || props.profile.province?.name_ar || t('Not set'))
const roleName = computed(() => isCourier.value ? t('Courier') : t('Merchant'))
const selectedLocale = computed(() => locales.find((item) => item.key === locale.value)?.label || 'عربي')
const profileSubtitle = computed(() => user.value.phone || (isCourier.value ? t('Courier') : (user.value.shop_name || t('Merchant'))))
const notificationUnread = computed(() => Math.max(0, Number(page.props.notificationUnread || 0)))
const verificationState = computed(() => props.profile.verification?.status || 'unsubmitted')
const courierAccountVerified = computed(() => Boolean(props.profile?.verification?.verified))
const verificationIsPreparing = computed(() => Object.keys(verificationPreparingDocuments.value).length > 0)

watch(() => user.value.theme, (theme) => { if (theme) applyTheme(theme) })
watch(() => page.props.errors?.documents, (error) => {
    if (!error) return

    verificationUploadError.value = error
    showVerification.value = true
})

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
        archive: 'M4 5h16v4H4zM6 9v10h12V9M10 13h4',
        trash: 'M4 7h16M10 11v5m4-5v5M9 7l1-3h4l1 3m-9 0 1 13h10l1-13',
    }[name] || ''
}
function applyTheme(theme) { activeTheme.value = theme; document.documentElement.dataset.theme = theme; document.body.dataset.theme = theme }
function setTheme(theme) {
    if (theme === activeTheme.value) return
    const previous = activeTheme.value
    applyTheme(theme)
    // Keep the settings page mounted; a preference save should never trigger
    // a new Inertia page response or reset the user's scroll position.
    window.axios.post(route('profile.theme'), { theme }).catch(() => {
        if (activeTheme.value === theme) applyTheme(previous)
    })
}
function setLocale(value) {
    if (value === locale.value) return
    router.post(route('profile.locale'), { locale: value }, { preserveScroll: true, onSuccess: () => window.location.reload() })
}
function openEdit() {
    accountForm.name = user.value.name || ''; accountForm.phone = user.value.phone || ''
    accountForm.shop_name = user.value.shop_name || props.profile.shop_name || ''; accountForm.address = user.value.address || props.profile.address || ''
    accountForm.vehicle = user.value.vehicle || props.profile.vehicle || ''
    accountForm.merchant_pickup_latitude = savedMerchantPickup.value.latitude
    accountForm.merchant_pickup_longitude = savedMerchantPickup.value.longitude
    accountForm.merchant_pickup_location_label = savedMerchantPickup.value.label
    accountForm.clearErrors('merchant_pickup_latitude', 'merchant_pickup_longitude', 'merchant_pickup_location_label')
    documentUploadError.value = ''
    showEdit.value = true
}
function normalizeAccountPhone() { accountForm.phone = normalizeIraqiMobilePhone(accountForm.phone) }
function normalizeVerificationPhone() { verificationForm.phone = normalizeIraqiMobilePhone(verificationForm.phone) }
function validatePhone(form) {
    form.phone = normalizeIraqiMobilePhone(form.phone)
    form.clearErrors('phone')

    if (isIraqiMobilePhone(form.phone)) return true

    form.setError('phone', t('The phone number must be exactly 11 digits and start with 077 or 078.'))
    return false
}
function validMerchantLatitude(value) {
    if (value === null || value === undefined || String(value).trim() === '') return false
    const latitude = Number(value)

    return Number.isFinite(latitude) && latitude >= -90 && latitude <= 90
}

function validMerchantLongitude(value) {
    if (value === null || value === undefined || String(value).trim() === '') return false
    const longitude = Number(value)

    return Number.isFinite(longitude) && longitude >= -180 && longitude <= 180
}

const hasMerchantPickup = computed(() => validMerchantLatitude(accountForm.merchant_pickup_latitude)
    && validMerchantLongitude(accountForm.merchant_pickup_longitude)
    && Boolean(String(accountForm.merchant_pickup_location_label || '').trim()))

function merchantPickupHasAnyValue() {
    return [
        accountForm.merchant_pickup_latitude,
        accountForm.merchant_pickup_longitude,
        accountForm.merchant_pickup_location_label,
    ].some((value) => String(value ?? '').trim() !== '')
}

function selectMerchantPickupLocation(location) {
    accountForm.merchant_pickup_latitude = Number(Number(location.latitude).toFixed(7))
    accountForm.merchant_pickup_longitude = Number(Number(location.longitude).toFixed(7))
    accountForm.merchant_pickup_location_label = String(location.label || '').trim()
    accountForm.clearErrors('merchant_pickup_latitude', 'merchant_pickup_longitude', 'merchant_pickup_location_label')
}

function validateMerchantPickup() {
    if (!isMerchant.value || !merchantPickupHasAnyValue()) return true
    if (hasMerchantPickup.value) return true

    accountForm.setError({
        merchant_pickup_latitude: validMerchantLatitude(accountForm.merchant_pickup_latitude) ? '' : 'حدّد موقع المتجر بدقة على الخريطة.',
        merchant_pickup_longitude: validMerchantLongitude(accountForm.merchant_pickup_longitude) ? '' : 'حدّد موقع المتجر بدقة على الخريطة.',
        merchant_pickup_location_label: String(accountForm.merchant_pickup_location_label || '').trim()
            ? ''
            : 'اكتب وصفاً واضحاً لموقع المتجر.',
    })
    return false
}

function saveAccount() {
    if (!validatePhone(accountForm) || !validateMerchantPickup()) return

    accountForm
        .transform((data) => {
            const payload = { ...data }

            // Backend deliberately treats submitted pickup fields as one
            // required tuple. Do not submit blank fields for a merchant that
            // has not chosen a permanent shop point yet.
            if (!isMerchant.value || !hasMerchantPickup.value) {
                delete payload.merchant_pickup_latitude
                delete payload.merchant_pickup_longitude
                delete payload.merchant_pickup_location_label
            }

            return payload
        })
        .post(route('profile.update'), { preserveScroll: true, onSuccess: () => (showEdit.value = false) })
}
function openVerification() {
    verificationForm.name = user.value.name || ''; verificationForm.address = user.value.address || props.profile.address || ''
    verificationForm.phone = user.value.phone || ''; verificationForm.identity_number = user.value.identity_number || props.profile.identity_number || ''
    for (const item of documentFields.value) verificationForm[item.key] = null
    verificationPreparingDocuments.value = {}
    verificationUploadError.value = ''
    verificationForm.clearErrors(); showVerification.value = true
}
function verificationDocumentTotalBytes(replacementKey = null, replacementFile = null) {
    return documentFields.value.reduce((total, item) => {
        const file = item.key === replacementKey ? replacementFile : verificationForm[item.key]
        return total + (file?.size || 0)
    }, 0)
}
function merchantUploadError(error) {
    if (error?.code === 'total_too_large') {
        return t('The verification documents together must not exceed :max MB.', {
            max: bytesToMegabytes(merchantDocumentLimits.value.maxTotalBytes),
        })
    }

    return documentPreparationError(error)
}
async function selectVerificationFile(event, key) {
    const sourceFile = event.target.files?.[0]
    event.target.value = ''
    if (!sourceFile || verificationPreparingDocuments.value[key]) return

    verificationUploadError.value = ''
    verificationPreparingDocuments.value = { ...verificationPreparingDocuments.value, [key]: true }
    try {
        const prepared = await prepareCourierDocument(sourceFile, merchantDocumentLimits.value)
        if (verificationDocumentTotalBytes(key, prepared.file) > merchantDocumentLimits.value.maxTotalBytes) {
            throw new CourierDocumentError('total_too_large')
        }

        verificationForm[key] = prepared.file
        verificationForm.clearErrors(key, 'documents')
    } catch (error) {
        verificationUploadError.value = merchantUploadError(error)
    } finally {
        const { [key]: ignored, ...remaining } = verificationPreparingDocuments.value
        verificationPreparingDocuments.value = remaining
    }
}
function existingDocument(type) { return props.profile.documents?.find((item) => item.type === type) }
function verificationDocumentDisplay(item) {
    const selectedFile = verificationForm[item.key]
    if (selectedFile?.name) return selectedFile.name

    const uploaded = existingDocument(item.existing)

    return uploaded
        ? `${t('Uploaded')} · ${documentStatus(uploaded.status)}`
        : t('Tap to upload')
}
function documentLabel(document) { return courierDocumentLabels.value[document.type] || document.type }
function documentPreparationError(error) {
    if (error?.code === 'unsupported_type') return t('Only images or PDF files are allowed.')
    if (['pdf_too_large', 'source_too_large', 'cannot_compress'].includes(error?.code)) return t('Document exceeds the allowed size.')

    return t('This file cannot be prepared for upload.')
}
async function replaceDocument(event, document) {
    const sourceFile = event.target.files?.[0]
    event.target.value = ''
    if (!sourceFile || replacingDocumentId.value) return

    documentUploadError.value = ''
    replacingDocumentId.value = document.id
    try {
        const prepared = await prepareCourierDocument(sourceFile, courierDocumentLimits.value)
        router.post(route('profile.documents.replace', document.id), { file: prepared.file }, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => (documentUploadError.value = ''),
            onError: (errors) => (documentUploadError.value = errors.file || t('Document upload failed.')),
            onFinish: () => (replacingDocumentId.value = null),
        })
    } catch (error) {
        documentUploadError.value = error instanceof CourierDocumentError
            ? documentPreparationError(error)
            : t('This file cannot be prepared for upload.')
        replacingDocumentId.value = null
    }
}
function documentStatus(status) { return status === 'approved' ? t('Verified') : status === 'rejected' ? t('Rejected') : t('Pending') }
function verificationStatusLabel(status) {
    return {
        verified: t('Verified'),
        pending: t('Verification pending'),
        rejected: t('Rejected'),
        unsubmitted: t('Not submitted'),
    }[status] || t('Not submitted')
}
function submitVerification() {
    if (verificationIsPreparing.value) {
        verificationUploadError.value = t('Please wait until image preparation is complete.')
        return
    }
    if (!validatePhone(verificationForm)) return
    if (verificationDocumentTotalBytes() > merchantDocumentLimits.value.maxTotalBytes) {
        verificationUploadError.value = merchantUploadError(new CourierDocumentError('total_too_large'))
        return
    }

    verificationUploadError.value = ''
    verificationForm.post(route('profile.verification'), {
        forceFormData: true, preserveScroll: true,
        onSuccess: () => {
            showVerification.value = false
            for (const item of documentFields.value) verificationForm[item.key] = null
        },
        onError: (errors) => {
            verificationUploadError.value = errors.documents || t('Document upload failed.')
        },
    })
}
function openSupport() { router.post(route('app.chats.open')) }
function formatMoney(value) { return fmt(value) }
function logout() { router.post(route('logout')) }
function warmMobileRoute(name, params = {}) {
    const url = route(name, params)
    const pages = {
        'app.wallet': 'Mobile/Wallet',
        'app.reports': 'Mobile/Reports',
        'app.notifications': 'Mobile/Notifications',
    }

    window.__almunjazPreloadPage?.(pages[name])
    router.prefetch(url, { viewTransition: false }, { cacheFor: '10s' })
}
function visitMobileRoute(name, params = {}) {
    warmMobileRoute(name, params)
    router.visit(route(name, params), {
        preserveScroll: false,
        preserveState: false,
        viewTransition: false,
    })
}
</script>

<template>
    <AppShell :title="t('My Profile')">
        <section class="profile-head">
            <div class="profile-avatar">{{ user.name?.charAt(0) || '؟' }}</div>
            <b>{{ user.name }}</b><span class="mono">{{ profileSubtitle }}</span>
        </section>

        <section class="list-card profile-settings">
            <div class="settings-row"><div class="sri"><svg viewBox="0 0 24 24"><path :d="icon('globe')" /></svg></div><div class="srt">{{ t('Language') }}</div><div class="seg" :aria-label="t('Language')"><button v-for="item in locales" :key="item.key" type="button" :class="{ active: locale === item.key }" @click="setLocale(item.key)">{{ item.label }}</button></div></div>
            <div class="settings-row"><div class="sri"><svg viewBox="0 0 24 24"><path :d="icon('sun')" /></svg></div><div class="srt">{{ t('Appearance') }}</div><div class="seg" :aria-label="t('Appearance')"><button type="button" :class="{ active: activeTheme !== 'dark' }" @click="setTheme('light')">{{ t('Light') }}</button><button type="button" :class="{ active: activeTheme === 'dark' }" @click="setTheme('dark')">{{ t('Dark') }}</button></div></div>
        </section>

        <section class="profile-permissions" :aria-label="t('Device permissions')">
            <div class="profile-permissions-head">
                <b>{{ t('Device permissions') }}</b>
                <span>{{ t('Choose only the permissions you want to enable.') }}</span>
            </div>
            <PushNotificationSettings />
        </section>

        <section v-if="isCourier" class="list-card profile-wallet">
            <button class="settings-row clickable" type="button" @pointerdown="warmMobileRoute('app.wallet', { intent: 'budget' })" @click="visitMobileRoute('app.wallet', { intent: 'budget' })"><div class="sri"><svg viewBox="0 0 24 24"><path :d="icon('shield')" /></svg></div><div class="srt"><b>{{ t('Manage Budget') }}</b><small>{{ t('Available Budget') }}: {{ formatMoney(walletBudgetBalance ?? walletBudget) }} {{ t('IQD') }}</small></div><b class="wallet-value mono">{{ formatMoney(walletBudget) }} {{ t('IQD') }}</b></button>
            <button class="settings-row clickable" type="button" @pointerdown="warmMobileRoute('app.wallet', { intent: 'qi' })" @click="visitMobileRoute('app.wallet', { intent: 'qi' })"><div class="sri"><svg viewBox="0 0 24 24"><path :d="icon('wallet')" /></svg></div><div class="srt">{{ t('Recharge Balance') }}</div><b class="wallet-value mono primary-value">{{ formatMoney(walletBalance) }} {{ t('IQD') }}</b></button>
        </section>

        <section class="list-card profile-actions">
            <button class="settings-row clickable" type="button" @click="openEdit"><div class="sri"><svg viewBox="0 0 24 24"><path :d="icon('user')" /></svg></div><div class="srt">{{ isCourier ? t('My Profile') : t('Account Details') }}</div><span class="srv" :class="{ 'profile-verification-link': isCourier, verified: isCourier && courierAccountVerified, pending: isCourier && !courierAccountVerified }">{{ isCourier ? (courierAccountVerified ? t('Verified') : t('Verification pending')) : `${selectedLocale} · ${t('Edit')}` }}</span></button>
            <button v-if="isCourier" class="settings-row clickable" type="button" @pointerdown="warmMobileRoute('app.reports')" @click="visitMobileRoute('app.reports')"><div class="sri"><svg viewBox="0 0 24 24"><path :d="icon('archive')" /></svg></div><div class="srt">{{ t('Archive') }}</div><span class="srv">›</span></button>
            <button class="settings-row clickable" type="button" @pointerdown="warmMobileRoute('app.notifications')" @click="visitMobileRoute('app.notifications')"><div class="sri"><svg viewBox="0 0 24 24"><path :d="icon('bell')" /></svg></div><div class="srt">{{ t('Notifications') }}</div><span class="notification-row-end"><b v-if="notificationUnread > 0" class="notification-count">{{ notificationUnread > 9 ? '9+' : notificationUnread }}</b><span class="srv">›</span></span></button>
            <button v-if="!isCourier" class="settings-row clickable" type="button" @click="openVerification"><div class="sri"><svg viewBox="0 0 24 24"><path :d="icon('shield')" /></svg></div><div class="srt">{{ t('Account Verification') }}</div><span class="srv profile-verification-link" :class="verificationState">{{ verificationStatusLabel(verificationState) }} ›</span></button>
            <button class="settings-row clickable" type="button" @click="openSupport"><div class="sri"><svg viewBox="0 0 24 24"><path :d="icon('chat')" /></svg></div><div class="srt">{{ t('Help & Support') }}</div><span class="srv">›</span></button>
            <button class="settings-row clickable" type="button" @click="showLegalInfo = true"><div class="sri"><svg viewBox="0 0 24 24"><path :d="icon('shield')" /></svg></div><div class="srt">{{ t('Privacy Policy') }} · {{ t('Terms of Use') }}</div><span class="srv">›</span></button>
            <button class="settings-row clickable" type="button" @click="showDeveloperInfo = true"><div class="sri"><svg viewBox="0 0 24 24"><path :d="icon('info')" /></svg></div><div class="srt">{{ t('About the app') }}</div><span class="srv">›</span></button>
        </section>

        <button class="profile-delete-account" type="button" @click="showDeleteAccountNotice = true"><svg viewBox="0 0 24 24"><path :d="icon('trash')" /></svg>{{ t('Delete Account') }}</button>
        <button class="profile-logout" type="button" @click="logout"><svg viewBox="0 0 24 24"><path :d="icon('logout')" /></svg>{{ t('Logout') }}</button>

        <SheetModal :open="showEdit" :title="isCourier ? t('My Profile') : t('Account Details')" :subtitle="roleName" @close="showEdit = false">
            <div class="field"><label>{{ t('Full Name') }}</label><input v-model="accountForm.name" :placeholder="t('Full Name')" /><span v-if="accountForm.errors.name" class="field-error">{{ accountForm.errors.name }}</span></div>
            <div v-if="!isCourier" class="field"><label>{{ t('Shop Name') }}</label><input v-model="accountForm.shop_name" :placeholder="t('Shop Name')" /><span v-if="accountForm.errors.shop_name" class="field-error">{{ accountForm.errors.shop_name }}</span></div>
            <div class="field"><label>{{ isCourier ? t('Address') : t('Shop Address') }}</label><input v-model="accountForm.address" :placeholder="t('Address')" /><span v-if="accountForm.errors.address" class="field-error">{{ accountForm.errors.address }}</span></div>
            <section v-if="isMerchant" class="merchant-pickup-location">
                <div class="merchant-pickup-location-head">
                    <b>{{ merchantPickupCopy.title }}</b>
                    <small>{{ merchantPickupCopy.help }}</small>
                </div>
                <OrderMapPicker
                    :latitude="accountForm.merchant_pickup_latitude"
                    :longitude="accountForm.merchant_pickup_longitude"
                    :label="accountForm.merchant_pickup_location_label"
                    :locale="locale"
                    purpose="merchant"
                    :allow-clear="false"
                    @selected="selectMerchantPickupLocation"
                />
                <p v-if="hasMerchantPickup" class="merchant-pickup-location-summary">
                    <b>{{ merchantPickupCopy.saved }}</b>
                    <span>{{ accountForm.merchant_pickup_location_label }}</span>
                </p>
                <span v-if="accountForm.errors.merchant_pickup_latitude || accountForm.errors.merchant_pickup_longitude || accountForm.errors.merchant_pickup_location_label" class="field-error">
                    {{ accountForm.errors.merchant_pickup_latitude || accountForm.errors.merchant_pickup_longitude || accountForm.errors.merchant_pickup_location_label }}
                </span>
            </section>
            <div class="field"><label>{{ t('Username') }}</label><input :value="profile.username || user.username || '—'" dir="ltr" disabled /></div>
            <div class="field"><label>{{ t('Governorate') }}</label><input :value="provinceName" disabled /></div>
            <div v-if="isCourier" class="field"><label>{{ t('Vehicle') }}</label><select v-model="accountForm.vehicle"><option v-for="(label, key) in vehicles" :key="key" :value="key">{{ label[locale] || label.ar || key }}</option></select><span v-if="accountForm.errors.vehicle" class="field-error">{{ accountForm.errors.vehicle }}</span></div>
            <div class="field"><label>{{ t('Phone Number') }}</label><input v-model="accountForm.phone" type="tel" dir="ltr" inputmode="numeric" maxlength="11" minlength="11" pattern="(?:077|078)[0-9]{8}" autocomplete="tel" :placeholder="t('Phone Number')" @input="normalizeAccountPhone" /><span v-if="accountForm.errors.phone" class="field-error">{{ accountForm.errors.phone }}</span></div>
            <div class="field"><label>{{ t('Joined') }}</label><input :value="profile.joined_at || '—'" dir="ltr" disabled /></div>
            <button class="btn btn-primary save-button" :disabled="accountForm.processing" @click="saveAccount"><span v-if="accountForm.processing" class="loader" /><span v-else>{{ t('Save') }}</span></button>
            <section v-if="isCourier" class="courier-profile-documents">
                <div class="courier-profile-documents-head"><b>{{ t('Documents') }}</b><span :class="{ verified: courierAccountVerified }">{{ courierAccountVerified ? t('Verified') : t('Verification pending') }}</span></div>
                <p v-if="!courierAccountVerified" class="documents-copy">{{ t('Your documents are under administrative review. You cannot accept orders until the account is verified.') }}</p>
                <p v-else-if="profile.documents?.length" class="documents-copy">{{ t('Review your uploaded documents and update any file to send it back for review.') }}</p>
                <p v-if="documentUploadError" class="field-error documents-error">{{ documentUploadError }}</p>
                <div v-if="profile.documents?.length" class="document-status-list">
                    <article v-for="document in profile.documents" :key="document.id" class="document-status-row">
                        <div class="document-status-copy"><b>{{ documentLabel(document) }}</b><small>{{ documentStatus(document.status) }}</small></div>
                        <b class="document-status-pill" :class="document.status">{{ documentStatus(document.status) }}</b>
                        <div class="document-status-actions">
                            <a :href="document.url" target="_blank" rel="noopener">{{ t('View Document') }}</a>
                            <label :class="{ busy: replacingDocumentId === document.id }"><input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" :disabled="replacingDocumentId !== null" @change="replaceDocument($event, document)" /><span>{{ replacingDocumentId === document.id ? t('Uploading…') : t('Update') }}</span></label>
                        </div>
                    </article>
                </div>
                <div v-else class="empty-hint">{{ t('No documents uploaded') }}</div>
            </section>
        </SheetModal>

        <SheetModal :open="showVerification" :title="t('Account Verification')" @close="showVerification = false">
            <p class="verification-copy">{{ t('Submit your details and documents. Your account remains active while the documents are reviewed.') }}</p>
            <p v-if="verificationUploadError || verificationForm.errors.documents" class="field-error documents-error" role="alert">{{ verificationUploadError || verificationForm.errors.documents }}</p>
            <div class="field"><label>{{ t('Full Name') }}</label><input v-model="verificationForm.name" :placeholder="t('Full Name')" /><span v-if="verificationForm.errors.name" class="field-error">{{ verificationForm.errors.name }}</span></div>
            <div class="field"><label>{{ t('Address') }}</label><input v-model="verificationForm.address" :placeholder="t('City — Area')" /><span v-if="verificationForm.errors.address" class="field-error">{{ verificationForm.errors.address }}</span></div>
            <div class="field"><label>{{ t('Phone Number') }}</label><input v-model="verificationForm.phone" type="tel" dir="ltr" inputmode="numeric" maxlength="11" minlength="11" pattern="(?:077|078)[0-9]{8}" autocomplete="tel" :placeholder="t('Phone Number')" @input="normalizeVerificationPhone" /><span v-if="verificationForm.errors.phone" class="field-error">{{ verificationForm.errors.phone }}</span></div>
            <div class="field"><label>{{ t('National ID Number') }}</label><input v-model="verificationForm.identity_number" dir="ltr" :placeholder="t('National ID Number')" /><span v-if="verificationForm.errors.identity_number" class="field-error">{{ verificationForm.errors.identity_number }}</span></div>
            <div v-for="item in documentFields" :key="item.key" class="verification-file"><label>{{ item.label }}</label><label class="upload-zone" :class="{ uploaded: verificationForm[item.key] || existingDocument(item.existing), busy: verificationPreparingDocuments[item.key] }"><input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" :disabled="verificationIsPreparing" @change="selectVerificationFile($event, item.key)" /><span>{{ verificationPreparingDocuments[item.key] ? t('Preparing image…') : verificationDocumentDisplay(item) }}</span></label><small v-if="verificationForm.errors[item.key]" class="field-error">{{ verificationForm.errors[item.key] }}</small></div>
            <button class="btn btn-primary save-button" :disabled="verificationForm.processing || verificationIsPreparing" @click="submitVerification"><span v-if="verificationForm.processing" class="loader" /><span v-else>{{ verificationIsPreparing ? t('Preparing image…') : t('Submit Verification') }}</span></button>
        </SheetModal>

        <SheetModal :open="showDeleteAccountNotice" :title="t('Delete Account')" @close="showDeleteAccountNotice = false">
            <div class="delete-account-preview">
                <span><svg viewBox="0 0 24 24"><path :d="icon('trash')" /></svg></span>
                <b>{{ t('Your deletion request will be reviewed within 24 hours.') }}</b>
                <p>{{ t('This is a preview only. No account deletion request has been sent.') }}</p>
                <!-- Preview only: the confirmation deliberately performs no
                     account action until the deletion workflow is approved. -->
                <button class="btn btn-primary save-button" type="button" @click="showDeleteAccountNotice = false">{{ t('Confirm') }}</button>
            </div>
        </SheetModal>

        <DeveloperInfoSheet :open="showDeveloperInfo" @close="showDeveloperInfo = false" />
        <LegalInfoSheet :open="showLegalInfo" :legal-content="legalContent" @close="showLegalInfo = false" />
    </AppShell>
</template>

<style scoped>
.profile-head,.profile-settings,.profile-permissions,.profile-wallet,.profile-actions{direction:inherit;writing-mode:horizontal-tb;transform:none}
.profile-head{display:flex;align-items:center;flex-direction:column;gap:0;padding:18px 0 22px;text-align:center}.profile-avatar{display:grid;place-items:center;width:74px;height:74px;margin-bottom:10px;border:0;border-radius:50%;background:var(--primary-tint);color:var(--primary-strong);font-size:22px;font-weight:900;box-shadow:none}.profile-head>b{font-size:15px;font-weight:900;line-height:1.45}.profile-head>span{margin-top:2px;color:var(--ink-faint);font-size:11.5px;font-weight:700}.list-card{margin-bottom:14px;border:1px solid var(--border);border-radius:16px;background:var(--surface);overflow:hidden}.profile-verification-link.verified{color:var(--success)}.profile-verification-link.pending{color:var(--warning)}.profile-verification-link.rejected{color:var(--danger)}.settings-row{display:flex;width:100%;align-items:center;gap:12px;min-height:58px;padding:12px 14px;border-bottom:1px solid var(--border);color:var(--ink);font:inherit;text-align:start}.settings-row:last-child{border-bottom:0}.clickable{transition:background .15s}.clickable:active{background:var(--surface-2)}.sri{display:grid;place-items:center;width:32px;height:32px;border-radius:9px;background:var(--surface-2);color:var(--ink-soft);flex:none}.sri svg,.profile-logout svg,.profile-delete-account svg,.delete-account-preview svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.srt{flex:1;min-width:0;font-size:12.5px;font-weight:800}.srv{color:var(--ink-faint);font-size:11px;font-weight:750;white-space:nowrap}.notification-row-end{display:flex;align-items:center;gap:8px}.notification-count{display:grid;min-width:19px;height:19px;place-items:center;padding:0 5px;border-radius:999px;background:var(--danger);color:#fff;font-size:9px;font-weight:900;line-height:1}.seg{display:flex;gap:2px;padding:2px;border-radius:9px;background:var(--surface-2)}.seg button{padding:5px 8px;border-radius:7px;color:var(--ink-faint);font:inherit;font-size:10px;font-weight:800;white-space:nowrap}.seg button.active{background:var(--surface);color:var(--primary-strong);box-shadow:var(--shadow)}.profile-permissions{margin:2px 0 16px}.profile-permissions-head{display:grid;gap:2px;margin:0 2px 9px}.profile-permissions-head b{font-size:12px;font-weight:900}.profile-permissions-head span{color:var(--ink-faint);font-size:9.5px;font-weight:700}.wallet-value{color:var(--accent);font-size:12px;font-weight:900;white-space:nowrap}.primary-value{color:var(--primary)}.profile-logout,.profile-delete-account{display:flex;width:100%;align-items:center;justify-content:center;gap:8px;padding:11px;border-radius:12px;font:inherit;font-size:11.5px;font-weight:900}.profile-logout{border:1px solid var(--border);background:var(--surface-2);color:var(--danger)}.profile-delete-account{margin:2px 0 9px;border:1px solid color-mix(in srgb,var(--danger) 42%,var(--border));background:var(--surface);color:var(--danger)}.profile-delete-account:active{background:var(--danger-tint)}.delete-account-preview{display:grid;justify-items:center;gap:10px;padding:9px 2px 2px;text-align:center}.delete-account-preview>span{display:grid;width:52px;height:52px;place-items:center;border-radius:16px;background:var(--danger-tint);color:var(--danger)}.delete-account-preview>span svg{width:23px;height:23px}.delete-account-preview>b{font-size:13px;font-weight:900;line-height:1.7}.delete-account-preview p{margin:0;color:var(--ink-soft);font-size:10.5px;font-weight:700;line-height:1.75}.field{margin-bottom:13px}.field label,.verification-file>label:first-child{display:block;margin-bottom:6px;color:var(--ink-soft);font-size:10.5px;font-weight:800}.field input,.field select{width:100%;padding:10px 11px;border:1px solid var(--border);border-radius:10px;outline:none;background:var(--surface);color:var(--ink);font:inherit;font-size:12px}.field input:focus,.field select:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-tint)}.field input:disabled{color:var(--ink-faint);background:var(--surface-2)}.field-error{display:block;margin-top:4px;color:var(--danger);font-size:9px;font-weight:750}.save-button{width:100%;margin-top:3px}.courier-profile-documents{margin-top:22px;padding-top:17px;border-top:1px solid var(--border)}.courier-profile-documents-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:9px}.courier-profile-documents-head b{font-size:13px;font-weight:900}.courier-profile-documents-head span{color:var(--ink-faint);font-size:9.5px;font-weight:750}.courier-profile-documents-head span.verified{color:var(--success)}.documents-copy{margin:-2px 0 12px;color:var(--ink-soft);font-size:10.5px;font-weight:700;line-height:1.75}.documents-error{margin:-3px 0 11px}.document-status-list{display:grid;gap:10px}.document-status-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;padding:12px;border:1px solid var(--border);border-radius:12px;background:var(--surface-2)}.document-status-copy{display:grid;gap:2px;min-width:0}.document-status-copy>b{overflow:hidden;color:var(--ink);font-size:10.5px;font-weight:850;text-overflow:ellipsis;white-space:nowrap}.document-status-copy small{color:var(--ink-faint);font-size:9px;font-weight:750}.document-status-pill{align-self:start;padding:4px 7px;border-radius:999px;background:var(--surface);font-size:8.5px;font-weight:900;white-space:nowrap}.document-status-pill.approved{color:var(--success);background:var(--success-tint)}.document-status-pill.rejected{color:var(--danger);background:var(--danger-tint)}.document-status-pill.pending{color:var(--warning);background:var(--warning-tint)}.document-status-actions{grid-column:1/-1;display:grid;grid-template-columns:1fr 1fr;gap:8px}.document-status-actions>a,.document-status-actions>label{display:grid;min-height:38px;place-items:center;border-radius:9px;font-size:10px;font-weight:850;text-align:center}.document-status-actions>a{border:1px solid var(--border);background:var(--surface);color:var(--primary-strong)}.document-status-actions>label{background:var(--primary);color:#fff;cursor:pointer}.document-status-actions>label.busy{opacity:.72;cursor:wait}.document-status-actions input{display:none}.verification-copy{margin:-2px 0 15px;color:var(--ink-soft);font-size:10.5px;font-weight:700;line-height:1.75}.verification-file{margin:14px 0}.upload-zone{display:flex;min-height:54px;align-items:center;justify-content:center;padding:10px;border:1.5px dashed var(--border);border-radius:11px;background:var(--surface-2);color:var(--ink-soft);cursor:pointer;font-size:10px;font-weight:800;text-align:center}.upload-zone.uploaded{border-color:var(--success);background:var(--success-tint);color:var(--success)}.upload-zone.busy{cursor:wait;opacity:.72}.upload-zone input{display:none}@media(max-width:350px){.settings-row{gap:7px;padding-inline:9px}.seg button{padding:4px 5px;font-size:8px}.sri{width:25px;height:25px}.document-status-actions{grid-template-columns:1fr}.document-status-actions>a,.document-status-actions>label{min-height:35px}}
.profile-wallet .srt>b,.profile-wallet .srt>small{display:block}.profile-wallet .srt>small{margin-top:2px;color:var(--ink-faint);font-size:9px;font-weight:700}
.merchant-pickup-location{display:grid;gap:10px;margin:2px 0 15px;padding:12px;border:1px solid color-mix(in srgb,var(--primary) 24%,var(--border));border-radius:14px;background:linear-gradient(135deg,color-mix(in srgb,var(--primary-tint) 58%,var(--surface)),var(--surface))}.merchant-pickup-location-head{display:grid;gap:3px}.merchant-pickup-location-head b{color:var(--ink);font-size:11.5px;font-weight:900}.merchant-pickup-location-head small{color:var(--ink-soft);font-size:9.5px;font-weight:700;line-height:1.65}.merchant-pickup-location-summary{display:grid;gap:2px;margin:0;padding:8px 10px;border-radius:10px;background:var(--success-tint);color:var(--success)}.merchant-pickup-location-summary b{font-size:9px;font-weight:900}.merchant-pickup-location-summary span{color:var(--ink-soft);font-size:10px;font-weight:800;line-height:1.45;overflow-wrap:anywhere}
</style>
