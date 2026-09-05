<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import Flash from '../../Components/Flash.vue'
import { bytesToMegabytes, CourierDocumentError, prepareCourierDocument } from '../../Utils/courierDocuments'
import { isIraqiMobilePhone, normalizeIraqiMobilePhone } from '../../Utils/iraqiPhone'

const props = defineProps({
    role: { type: String, required: true },
    vehicles: { type: Object, required: true },
    provinces: { type: Array, required: true },
    registrationAvailable: { type: Boolean, default: true },
    courierUploadLimits: { type: Object, default: () => ({}) },
})

const isCourier = props.role === 'courier'
const page = usePage()
const locale = computed(() => page.props.locale || 'ar')
const branding = computed(() => page.props.branding || {
    name: t('Al-Munjaz Al-Saree'),
    logo_url: '/logo.png',
})
const direction = computed(() => locale.value === 'en' ? 'ltr' : 'rtl')
const sending = ref(false)
const showPassword = ref(false)
const showConfirmation = ref(false)
const uploadErrors = ref({})
const uploadInfo = ref({})
const preparingDocuments = ref({})
const documentSummaryError = ref('')
const documentPreviews = ref({})

const courierDocumentKeys = [
    'residence_document',
    'id_front_document',
    'id_back_document',
    'license_front_document',
    'license_back_document',
]

const courierUploadLimits = computed(() => {
    const maxFileKilobytes = Number(props.courierUploadLimits.maxFileKilobytes || 1024)
    const maxTotalKilobytes = Number(props.courierUploadLimits.maxTotalKilobytes || 4096)
    const targetImageKilobytes = Number(props.courierUploadLimits.targetImageKilobytes || 700)

    return {
        maxFileBytes: Math.max(256, Math.min(maxFileKilobytes, 2048)) * 1024,
        maxTotalBytes: Math.max(1024, Math.min(maxTotalKilobytes, 8192)) * 1024,
        targetImageBytes: Math.max(256, Math.min(targetImageKilobytes, maxFileKilobytes)) * 1024,
    }
})

const isPreparingDocuments = computed(() => Object.values(preparingDocuments.value).some(Boolean))

const form = useForm({
    role: props.role,
    name: '',
    phone: '',
    shop: '',
    address: '',
    vehicle: isCourier ? '' : 'bike',
    province_id: '',
    password: '',
    password_confirmation: '',
    residence_document: null,
    id_front_document: null,
    id_back_document: null,
    license_front_document: null,
    license_back_document: null,
})

function localizedVehicle(labels, fallback) {
    return labels?.[locale.value]
        || labels?.ar
        || labels?.en
        || labels?.ku
        || fallback
}

function localizedProvince(province) {
    return province?.[`name_${locale.value}`]
        || province?.name_ar
        || province?.name_en
        || province?.name_ku
        || ''
}

const vehicleDisplayOrder = ['sedan', 'suv', 'truck', 'bike']

const vehicleOptions = computed(() => Object.entries(props.vehicles)
    .map(([key, labels]) => ({
        key,
        label: localizedVehicle(labels, key),
    }))
    .sort((first, second) => {
        const firstPosition = vehicleDisplayOrder.indexOf(first.key)
        const secondPosition = vehicleDisplayOrder.indexOf(second.key)

        return (firstPosition === -1 ? Number.MAX_SAFE_INTEGER : firstPosition)
            - (secondPosition === -1 ? Number.MAX_SAFE_INTEGER : secondPosition)
    }))
function submitForm() {
    if (!validatePhone()) return
    if (isCourier && !validateCourierFields()) return
    if (isCourier && !validateCourierDocuments()) return

    sending.value = true
    form.post('/register', {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => (sending.value = false),
    })
}

function normalizePhone() {
    form.phone = normalizeIraqiMobilePhone(form.phone)
}

function validatePhone() {
    normalizePhone()
    form.clearErrors('phone')

    if (isIraqiMobilePhone(form.phone)) return true

    form.setError('phone', t('The phone number must be exactly 11 digits and start with 077 or 078.'))
    return false
}

function validateCourierFields() {
    const errors = {}
    const requiredFields = ['name', 'address', 'phone', 'password', 'password_confirmation', 'vehicle']
    if (props.provinces.length) requiredFields.push('province_id')

    form.clearErrors(...requiredFields)
    for (const key of requiredFields) {
        if (!String(form[key] || '').trim()) errors[key] = t('This field is required')
    }

    if (form.password && form.password_confirmation && form.password !== form.password_confirmation) {
        errors.password_confirmation = t('Passwords do not match')
    }

    if (Object.keys(errors).length) {
        form.setError(errors)
        documentSummaryError.value = t('This field is required')
        return false
    }

    documentSummaryError.value = ''
    return true
}

async function chooseFile(event, key) {
    const source = event.target.files?.[0]
    event.target.value = ''
    if (!source) return

    clearUploadError(key)
    documentSummaryError.value = ''
    preparingDocuments.value[key] = true

    try {
        const prepared = await prepareCourierDocument(source, courierUploadLimits.value)
        const nextTotal = courierDocumentTotalBytes(key, prepared.file)

        if (nextTotal > courierUploadLimits.value.maxTotalBytes) {
            throw new CourierDocumentError('total_too_large')
        }

        form[key] = prepared.file
        setDocumentPreview(key, prepared.file)
        uploadInfo.value[key] = {
            optimized: prepared.optimized,
            size: prepared.file.size,
        }
    } catch (error) {
        form[key] = null
        clearDocumentPreview(key)
        delete uploadInfo.value[key]
        uploadErrors.value[key] = documentErrorMessage(error)
        if (error instanceof CourierDocumentError && error.code === 'total_too_large') {
            documentSummaryError.value = uploadErrors.value[key]
        }
    } finally {
        preparingDocuments.value[key] = false
    }
}

function setDocumentPreview(key, file) {
    clearDocumentPreview(key)

    if (!file?.type?.startsWith('image/') || typeof URL === 'undefined') return
    documentPreviews.value[key] = URL.createObjectURL(file)
}

function clearDocumentPreview(key) {
    const preview = documentPreviews.value[key]
    if (preview && typeof URL !== 'undefined') URL.revokeObjectURL(preview)
    delete documentPreviews.value[key]
}

function documentPreview(key) {
    return documentPreviews.value[key] || ''
}

function fileInfo(key) {
    const info = uploadInfo.value[key]
    if (!info) return ''

    const suffix = info.optimized ? ` · ${t('Optimized')}` : ''
    return `${bytesToMegabytes(info.size)} MB${suffix}`
}

function uploadError(key) {
    return uploadErrors.value[key] || form.errors[key]
}

function clearUploadError(key) {
    delete uploadErrors.value[key]
    form.clearErrors(key)
}

function courierDocumentTotalBytes(replacementKey = null, replacementFile = null) {
    return courierDocumentKeys.reduce((total, key) => {
        const file = key === replacementKey ? replacementFile : form[key]
        return total + (file?.size || 0)
    }, 0)
}

function validateCourierDocuments() {
    if (isPreparingDocuments.value) {
        documentSummaryError.value = t('Please wait until image preparation is complete.')
        return false
    }

    const missing = courierDocumentKeys.filter((key) => !form[key])
    if (missing.length) {
        for (const key of missing) uploadErrors.value[key] = t('This document is required.')
        documentSummaryError.value = t('All five courier documents are required before creating the account.')
        return false
    }

    if (Object.keys(uploadErrors.value).length) {
        documentSummaryError.value = t('Fix the document errors before creating the account.')
        return false
    }

    if (courierDocumentTotalBytes() > courierUploadLimits.value.maxTotalBytes) {
        documentSummaryError.value = documentErrorMessage(new CourierDocumentError('total_too_large'))
        return false
    }

    return true
}

function documentErrorMessage(error) {
    const code = error instanceof CourierDocumentError ? error.code : 'cannot_process'
    const maxFile = bytesToMegabytes(courierUploadLimits.value.maxFileBytes)
    const maxTotal = bytesToMegabytes(courierUploadLimits.value.maxTotalBytes)

    return {
        unsupported_type: t('Use JPG, PNG, WebP, or PDF files only.'),
        source_too_large: t('The original image is too large. Choose an image smaller than :max MB.', { max: 20 }),
        pdf_too_large: t('A PDF document must not exceed :max MB.', { max: maxFile }),
        cannot_compress: t('This image could not be optimized enough. Choose a clearer, smaller image.'),
        total_too_large: t('The five courier documents together must not exceed :max MB.', { max: maxTotal }),
        cannot_process: t('Unable to process this image. Please choose a JPG, PNG, or WebP image.'),
    }[code] || t('Unable to process this document. Please choose another file.')
}

function vehicleIcon(vehicle) {
    return vehicle === 'bike' ? 'M5.5 18a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm13 0a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7ZM5.5 14.5h5l2.5-5h3l2.5 5M11 9.5h4M14.5 6.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z'
        : vehicle === 'truck' ? 'M2 6.5h12v8.5H2zM14 9.5h3.6l2.6 2.6V15H14M6.5 18.2a1.7 1.7 0 1 0 0-3.4 1.7 1.7 0 0 0 0 3.4Zm10.5 0a1.7 1.7 0 1 0 0-3.4 1.7 1.7 0 0 0 0 3.4Z'
        : vehicle === 'suv' ? 'M2.5 12.5 6 7.4a2 2 0 0 1 1.8-1.1h8.4A2 2 0 0 1 18 7.4l2.5 5.1M2.5 12.5h19A1.5 1.5 0 0 1 23 14v1.5H21M3 15h1v1a1 1 0 0 0 1 1h.5M7 19.1a1.6 1.6 0 1 0 0-3.2 1.6 1.6 0 0 0 0 3.2Zm10 0a1.6 1.6 0 1 0 0-3.2 1.6 1.6 0 0 0 0 3.2Z'
        : 'M5 14l1.9-4.6a2 2 0 0 1 1.9-1.2h6.4a2 2 0 0 1 1.9 1.2L19 14M3.5 14h17A1.5 1.5 0 0 1 22 15.5V17h-2M2 16.5h1V17a1 1 0 0 0 1 1h.8M7 19.1a1.6 1.6 0 1 0 0-3.2 1.6 1.6 0 0 0 0 3.2Zm10 0a1.6 1.6 0 1 0 0-3.2 1.6 1.6 0 0 0 0 3.2Z'
}

function icon(name) {
    const paths = {
        back: 'M15 18l-6-6 6-6',
        shop: 'M3 9 4.5 4h15L21 9M3 9a2.4 2.4 0 0 0 4.6 1.1A2.4 2.4 0 0 0 12 9a2.4 2.4 0 0 0 4.4 1.1A2.4 2.4 0 0 0 21 9M5 9v10h14V9M9.5 19v-5h5v5',
        bike: 'M5.5 18a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm13 0a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7ZM5.5 14.5h5l2.5-5h3l2.5 5M11 9.5h4M14.5 6.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z',
        user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-8 8a8 8 0 0 1 16 0',
        phone: 'M5 3h4l2 5-2.5 1.7a11.5 11.5 0 0 0 5.8 5.8L16 13l5 2v4a2 2 0 0 1-2.2 2A20 20 0 0 1 3 5.2 2 2 0 0 1 5 3Z',
        pin: 'M12 21s-7-5.8-7-11.5a7 7 0 0 1 14 0C19 15.2 12 21 12 21Z M12 12a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z',
        lock: 'M6.5 10V7.5a5.5 5.5 0 0 1 11 0V10M5 10h14v11H5z',
        upload: 'M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5M4 20h16',
        check: 'M20 6 9 17l-5-5',
    }
    return paths[name]
}

onBeforeUnmount(() => {
    for (const key of Object.keys(documentPreviews.value)) clearDocumentPreview(key)
})
</script>

<template>
    <main class="register-reference" :dir="direction" :lang="locale">
        <Flash />
        <header class="reg-header">
            <button class="reg-back" type="button" :aria-label="t('Back')" @click="$inertia.visit(route('login'))">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path :d="icon('back')" /></svg>
            </button>
            <div class="reg-brand"><span><img :src="branding.logo_url" :alt="branding.name"></span><b>{{ branding.name }}</b></div>
            <span class="reg-spacer"></span>
        </header>

        <section class="reg-body">
            <div class="reg-hero">
                <span class="reg-role-icon"><svg width="29" height="29" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="icon(isCourier ? 'bike' : 'shop')" /></svg></span>
                <span class="reg-role-chip">{{ t(isCourier ? 'Courier App' : 'Merchant App') }}</span>
                <h1>{{ t(isCourier ? 'Courier Account' : 'Merchant Account') }}</h1>
                <p>{{ t(isCourier ? 'Enter your personal details and vehicle type' : 'Enter your store details to create an account') }}</p>
            </div>

            <form class="reg-card" @submit.prevent="submitForm">
                <div class="reg-field" :class="{ error: form.errors.name }">
                    <label>{{ t('Full Name') }}</label>
                    <div><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path :d="icon('user')" /></svg><input v-model="form.name" :placeholder="t('Full Name')" autocomplete="name" required></div>
                    <small v-if="form.errors.name">{{ form.errors.name }}</small>
                </div>
                <div v-if="!isCourier" class="reg-field" :class="{ error: form.errors.shop }">
                    <label>{{ t('Shop Name') }}</label>
                    <div><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path :d="icon('shop')" /></svg><input v-model="form.shop" :placeholder="t('Shop Name')" required></div>
                    <small v-if="form.errors.shop">{{ form.errors.shop }}</small>
                </div>
                <div class="reg-field" :class="{ error: form.errors.address }">
                    <label>{{ t(isCourier ? 'Address' : 'Shop Address') }}</label>
                    <div><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path :d="icon('pin')" /></svg><input v-model="form.address" :placeholder="t(isCourier ? 'Address' : 'Shop Address')" required></div>
                    <small v-if="form.errors.address">{{ form.errors.address }}</small>
                </div>
                <div v-if="provinces.length" class="reg-field" :class="{ error: form.errors.province_id }">
                    <label>{{ t('Governorate') }}</label>
                    <div><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path :d="icon('pin')" /></svg><select v-model="form.province_id" :required="provinces.length > 0"><option disabled value="">{{ t('Governorate') }}</option><option v-for="province in provinces" :key="province.id" :value="province.id">{{ localizedProvince(province) }}</option></select></div>
                    <small v-if="form.errors.province_id">{{ form.errors.province_id }}</small>
                </div>
                <div class="reg-field" :class="{ error: form.errors.phone }">
                    <label>{{ t('Phone Number') }}</label>
                    <div><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path :d="icon('phone')" /></svg><input v-model="form.phone" type="tel" dir="ltr" inputmode="numeric" maxlength="11" minlength="11" pattern="(?:077|078)[0-9]{8}" placeholder="077xxxxxxxx" autocomplete="tel" required @input="normalizePhone"></div>
                    <small v-if="form.errors.phone">{{ form.errors.phone }}</small>
                </div>
                <div class="reg-field" :class="{ error: form.errors.password }">
                    <label>{{ t('Password') }}</label>
                    <div><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path :d="icon('lock')" /></svg><input v-model="form.password" :type="showPassword ? 'text' : 'password'" :placeholder="t('Password')" autocomplete="new-password" required><button type="button" @click="showPassword = !showPassword">{{ t(showPassword ? 'Hide' : 'Show') }}</button></div>
                    <small v-if="form.errors.password">{{ form.errors.password }}</small>
                </div>
                <div class="reg-field">
                    <label>{{ t('Confirm Password') }}</label>
                    <div><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path :d="icon('lock')" /></svg><input v-model="form.password_confirmation" :type="showConfirmation ? 'text' : 'password'" :placeholder="t('Confirm Password')" autocomplete="new-password" required><button type="button" @click="showConfirmation = !showConfirmation">{{ t(showConfirmation ? 'Hide' : 'Show') }}</button></div>
                </div>

                <template v-if="isCourier">
                    <div class="courier-divider"><span>{{ t('Vehicle Type') }} · {{ t('Documents') }}</span></div>
                    <div class="reg-field vehicle-field" :class="{ error: form.errors.vehicle }">
                        <label>{{ t('Vehicle Type') }}</label>
                        <div class="vehicle-choice-grid" role="radiogroup" :aria-label="t('Vehicle Type')">
                            <label v-for="vehicle in vehicleOptions" :key="vehicle.key" class="vehicle-choice" :class="{ selected: form.vehicle === vehicle.key }">
                                <input v-model="form.vehicle" type="radio" name="vehicle" :value="vehicle.key" @change="form.clearErrors('vehicle')">
                                <span class="vehicle-choice-icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="vehicleIcon(vehicle.key)" /></svg></span>
                                <span>{{ vehicle.label }}</span>
                            </label>
                        </div>
                        <small v-if="form.errors.vehicle">{{ form.errors.vehicle }}</small>
                    </div>

                    <div class="documents">
                        <small v-if="documentSummaryError || form.errors.documents" class="document-error document-summary-error">{{ documentSummaryError || form.errors.documents }}</small>
                        <p>{{ t('Residence Card') }}</p>
                        <div class="upload-zone" :class="{ uploaded: !!form.residence_document, error: !!uploadError('residence_document'), preparing: preparingDocuments.residence_document }">
                            <span class="upload-visual"><img v-if="documentPreview('residence_document')" :src="documentPreview('residence_document')" alt=""><svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="form.residence_document ? icon('check') : icon('upload')" /></svg></span>
                            <span>{{ preparingDocuments.residence_document ? t('Preparing image…') : (form.residence_document ? t('Uploaded') : t('Tap to upload')) }}</span>
                            <input class="upload-input" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" :aria-label="t('Residence Card')" :disabled="preparingDocuments.residence_document" @change="chooseFile($event, 'residence_document')">
                        </div>
                        <small v-if="fileInfo('residence_document')" class="document-info">{{ fileInfo('residence_document') }}</small>
                        <small v-if="uploadError('residence_document')" class="document-error">{{ uploadError('residence_document') }}</small>

                        <p>{{ t('National ID Card') }}</p>
                        <div class="upload-pair">
                            <div v-for="doc in [['id_front_document', t('Front')], ['id_back_document', t('Back')]]" :key="doc[0]" class="upload-zone" :class="{ uploaded: !!form[doc[0]], error: !!uploadError(doc[0]), preparing: preparingDocuments[doc[0]] }">
                                <span class="upload-visual"><img v-if="documentPreview(doc[0])" :src="documentPreview(doc[0])" alt=""><svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="form[doc[0]] ? icon('check') : icon('upload')" /></svg></span>
                                <span>{{ preparingDocuments[doc[0]] ? t('Preparing image…') : (form[doc[0]] ? t('Uploaded') : doc[1]) }}</span>
                                <input class="upload-input" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" :aria-label="doc[1]" :disabled="preparingDocuments[doc[0]]" @change="chooseFile($event, doc[0])">
                            </div>
                        </div>
                        <div class="document-file-details"><small v-for="key in ['id_front_document', 'id_back_document']" :key="key"><template v-if="fileInfo(key)">{{ fileInfo(key) }}</template><template v-else-if="uploadError(key)"><span class="document-error">{{ uploadError(key) }}</span></template></small></div>

                        <p>{{ t('Driving License') }}</p>
                        <div class="upload-pair">
                            <div v-for="doc in [['license_front_document', t('Front')], ['license_back_document', t('Back')]]" :key="doc[0]" class="upload-zone" :class="{ uploaded: !!form[doc[0]], error: !!uploadError(doc[0]), preparing: preparingDocuments[doc[0]] }">
                            <span class="upload-visual"><img v-if="documentPreview(doc[0])" :src="documentPreview(doc[0])" alt=""><svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="form[doc[0]] ? icon('check') : icon('upload')" /></svg></span>
                            <span>{{ preparingDocuments[doc[0]] ? t('Preparing image…') : (form[doc[0]] ? t('Uploaded') : doc[1]) }}</span>
                            <input class="upload-input" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" :aria-label="doc[1]" :disabled="preparingDocuments[doc[0]]" @change="chooseFile($event, doc[0])">
                            </div>
                        </div>
                        <div class="document-file-details"><small v-for="key in ['license_front_document', 'license_back_document']" :key="key"><template v-if="fileInfo(key)">{{ fileInfo(key) }}</template><template v-else-if="uploadError(key)"><span class="document-error">{{ uploadError(key) }}</span></template></small></div>
                    </div>
                </template>

                <p v-if="!registrationAvailable" class="registration-unavailable">{{ t('Registration is temporarily unavailable because no operating governorate is enabled.') }}</p>
                <button type="submit" class="reg-submit" :disabled="!registrationAvailable || sending || isPreparingDocuments">
                    <span v-if="sending || isPreparingDocuments" class="loader"></span><span v-else>{{ t('Create Account') }}</span>
                </button>
            </form>
        </section>
    </main>
</template>

<style scoped>
/* Registration is outside AppShell, so it needs the same orientation guard.
   This also neutralizes a transform retained by an older cached PWA style. */
.register-reference { min-height:100dvh; width:100%; color:#fff; background:linear-gradient(175deg, var(--primary-strong), var(--primary) 59%, var(--accent)); overflow-y:auto; writing-mode:horizontal-tb; rotate:none; scale:1; transform:none !important; }
.reg-header { display:grid; grid-template-columns:38px 1fr 38px; align-items:center; padding:16px 18px 6px; }
.reg-back { width:34px; height:34px; display:grid; place-items:center; color:#fff; border:1px solid rgba(255,255,255,.28); border-radius:10px; background:rgba(255,255,255,.14); }
.reg-brand { display:flex; align-items:center; justify-content:center; gap:8px; font-size:14px; font-weight:900; }
.reg-brand span { width:31px; height:31px; overflow:hidden; border-radius:9px; background:#fff; }
.reg-brand img { width:100%; height:100%; object-fit:contain; }
.reg-body { padding:9px 22px 28px; }
.reg-hero { margin:7px 0 20px; text-align:center; }
.reg-role-icon { width:58px; height:58px; display:grid; place-items:center; margin:0 auto 10px; border:1px solid rgba(255,255,255,.26); border-radius:50%; background:rgba(255,255,255,.15); }
.reg-role-chip { display:inline-block; padding:4px 12px; border:1px solid rgba(255,255,255,.25); border-radius:20px; background:rgba(255,255,255,.13); font-size:10px; font-weight:800; }
.reg-hero h1 { margin:9px 0 3px; font-size:20px; font-weight:900; }
.reg-hero p { margin:0 auto; max-width:310px; color:rgba(255,255,255,.82); font-size:10.5px; line-height:1.8; font-weight:600; }
.reg-card { padding:18px 15px; border:1px solid rgba(255,255,255,.26); border-radius:18px; background:rgba(255,255,255,.12); backdrop-filter:blur(8px); }
.reg-field { margin-bottom:12px; }
.reg-field > label { display:block; margin-bottom:5px; color:rgba(255,255,255,.88); font-size:11px; font-weight:800; }
.reg-field > div:not(.vehicle-dropdown):not(.vehicle-choice-grid) { display:flex; align-items:center; gap:8px; min-height:43px; padding:0 11px; color:rgba(255,255,255,.68); border:1.5px solid rgba(255,255,255,.25); border-radius:11px; background:rgba(255,255,255,.1); }
.reg-field > div:not(.vehicle-dropdown):not(.vehicle-choice-grid):focus-within { border-color:rgba(255,255,255,.7); background:rgba(255,255,255,.16); }
.reg-field input, .reg-field select { min-width:0; width:100%; flex:1; color:#fff; border:0; outline:0; background:transparent; font:inherit; font-size:12px; }
.reg-field select option { color:var(--ink); background:var(--surface); }
.reg-field input::placeholder { color:rgba(255,255,255,.48); }
.reg-field button { color:rgba(255,255,255,.9); font:inherit; font-size:9.5px; font-weight:800; white-space:nowrap; }
.reg-field small { display:block; margin-top:4px; color:#ffd0cb; font-size:10px; font-weight:800; }
.courier-divider { display:flex; align-items:center; gap:8px; margin:18px 0 10px; color:rgba(255,255,255,.86); font-size:11px; font-weight:900; }
.courier-divider::before, .courier-divider::after { content:''; height:1px; flex:1; background:rgba(255,255,255,.25); }
.vehicle-field { position:relative; display:block!important; min-height:0!important; padding:0!important; border:0!important; background:transparent!important; }
.vehicle-choice-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:8px; }
.vehicle-choice { min-height:58px; display:flex; align-items:center; gap:8px; padding:8px 9px; color:#fff; border:1.5px solid rgba(255,255,255,.38); border-radius:13px; background:rgba(4,68,64,.42); font:800 11px var(--font); cursor:pointer; touch-action:auto; -webkit-tap-highlight-color:rgba(255,255,255,.18); }
.vehicle-choice.selected { border-color:#fff; background:rgba(255,255,255,.2); box-shadow:0 0 0 2px rgba(255,255,255,.14); }
.vehicle-choice input { width:19px; height:19px; margin:0; flex:none; accent-color:#fff; cursor:pointer; pointer-events:auto; touch-action:auto; -webkit-appearance:auto; appearance:auto; }
.vehicle-choice-icon { width:29px; height:29px; display:grid; place-items:center; flex:none; border-radius:9px; background:rgba(255,255,255,.15); }
.vehicle-choice span:last-child { min-width:0; line-height:1.45; }
.vehicle-choice:focus-within { outline:3px solid rgba(255,255,255,.52); outline-offset:2px; }
.documents { margin-top:16px; }
.documents > p { margin:14px 0 7px; color:rgba(255,255,255,.86); font-size:11px; font-weight:900; }
.documents > p:first-child { margin-top:0; }
.upload-zone { min-height:46px; display:grid; grid-template-columns:28px minmax(0, 1fr); align-items:center; gap:9px; margin-top:7px; padding:8px 10px; color:rgba(255,255,255,.83); border:1px dashed rgba(255,255,255,.38); border-radius:11px; background:rgba(255,255,255,.07); font-size:10px; font-weight:700; touch-action:auto; }
.upload-zone .upload-input { position:relative; z-index:2; grid-column:1 / -1; display:block!important; width:100%; min-height:44px; margin:1px 0 0; padding:4px; color:#fff; border:1px solid rgba(255,255,255,.42); border-radius:8px; background:rgba(4,68,64,.5); font:700 11px var(--font); cursor:pointer; pointer-events:auto!important; touch-action:auto!important; -webkit-user-select:auto; user-select:auto; -webkit-appearance:auto; appearance:auto; }
.upload-zone .upload-input::file-selector-button { margin-inline-end:8px; padding:6px 9px; color:#07524e; border:0; border-radius:6px; background:#fff; font:800 10px var(--font); cursor:pointer; }
.upload-visual{display:grid;width:28px;height:28px;place-items:center;overflow:hidden;flex:none;border-radius:7px;background:rgba(255,255,255,.12)}.upload-visual img{width:100%;height:100%;object-fit:cover}.upload-pair .upload-visual{width:42px;height:38px;border-radius:8px}.upload-pair .upload-zone.uploaded{padding:6px}
.upload-zone.uploaded { border-style:solid; border-color:#a3f2ca; background:rgba(86, 212, 139, .16); color:#fff; }
.upload-zone.error { border-color:#ffd0cb; }
.upload-zone.preparing { opacity:.72; cursor:progress; }
.upload-pair { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.upload-pair .upload-zone { grid-template-columns:1fr; justify-items:center; min-height:63px; margin-top:0; text-align:center; }
.upload-pair .upload-zone .upload-input { min-height:42px; }
.document-error { display:block; margin-top:4px; color:#ffd0cb; font-size:10px; font-weight:800; }
.document-summary-error { margin-bottom:3px; padding:8px 10px; border:1px solid rgba(255,208,203,.45); border-radius:9px; background:rgba(156,44,37,.16); line-height:1.5; }
.document-info { display:block; margin-top:4px; color:#b9f7d3; font-size:9px; font-weight:800; }
.document-file-details { display:grid; grid-template-columns:1fr 1fr; gap:8px; min-height:0; }
.document-file-details > small { min-width:0; color:#b9f7d3; font-size:9px; font-weight:800; line-height:1.4; overflow-wrap:anywhere; }
.registration-unavailable { margin:16px 0 -5px; padding:10px 11px; border:1px solid rgba(255,208,203,.52); border-radius:10px; background:rgba(156,44,37,.18); color:#ffd8d2; font-size:10px; line-height:1.65; font-weight:800; }
.reg-submit:disabled { opacity:.72; cursor:not-allowed; }
.reg-submit { width:100%; min-height:46px; margin-top:19px; border-radius:12px; background:#fff; color:var(--primary-strong); font:inherit; font-size:13px; font-weight:900; box-shadow:0 8px 20px -6px rgba(0,0,0,.28); }
</style>
