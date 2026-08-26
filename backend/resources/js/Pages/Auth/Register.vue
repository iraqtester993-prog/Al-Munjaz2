<script setup>
import { computed, ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import Flash from '../../Components/Flash.vue'
import { bytesToMegabytes, CourierDocumentError, prepareCourierDocument } from '../../Utils/courierDocuments'

const props = defineProps({
    role: { type: String, required: true },
    vehicles: { type: Object, required: true },
    provinces: { type: Array, required: true },
    courierUploadLimits: { type: Object, default: () => ({}) },
})

const isCourier = props.role === 'courier'
const page = usePage()
const locale = computed(() => page.props.locale || 'ar')
const direction = computed(() => locale.value === 'en' ? 'ltr' : 'rtl')
const sending = ref(false)
const showPassword = ref(false)
const showConfirmation = ref(false)
const showVehicles = ref(false)
const uploadErrors = ref({})
const uploadInfo = ref({})
const preparingDocuments = ref({})
const documentSummaryError = ref('')

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
    vehicle: 'bike',
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

const vehicleOptions = computed(() => Object.entries(props.vehicles).map(([key, labels]) => ({
    key,
    label: localizedVehicle(labels, key),
})))
const selectedVehicle = computed(() => vehicleOptions.value.find((vehicle) => vehicle.key === form.vehicle))

function submitForm() {
    if (isCourier && !validateCourierDocuments()) return

    sending.value = true
    form.post('/register', {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => (sending.value = false),
    })
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
        uploadInfo.value[key] = {
            optimized: prepared.optimized,
            size: prepared.file.size,
        }
    } catch (error) {
        form[key] = null
        delete uploadInfo.value[key]
        uploadErrors.value[key] = documentErrorMessage(error)
        if (error instanceof CourierDocumentError && error.code === 'total_too_large') {
            documentSummaryError.value = uploadErrors.value[key]
        }
    } finally {
        preparingDocuments.value[key] = false
    }
}

function fileLabel(key, fallback) {
    return form[key]?.name || fallback
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

function selectVehicle(vehicle) {
    form.vehicle = vehicle
    showVehicles.value = false
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
</script>

<template>
    <main class="register-reference" :dir="direction" :lang="locale">
        <Flash />
        <header class="reg-header">
            <button class="reg-back" type="button" :aria-label="t('Back')" @click="$inertia.visit(route('login'))">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path :d="icon('back')" /></svg>
            </button>
            <div class="reg-brand"><span><img src="/logo.png" :alt="t('Al-Munjaz Al-Saree')"></span><b>{{ t('Al-Munjaz Al-Saree') }}</b></div>
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
                    <div><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path :d="icon('user')" /></svg><input v-model="form.name" :placeholder="t('Full Name')" autocomplete="name"></div>
                    <small v-if="form.errors.name">{{ form.errors.name }}</small>
                </div>
                <div v-if="!isCourier" class="reg-field" :class="{ error: form.errors.shop }">
                    <label>{{ t('Shop Name') }}</label>
                    <div><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path :d="icon('shop')" /></svg><input v-model="form.shop" :placeholder="t('Shop Name')"></div>
                    <small v-if="form.errors.shop">{{ form.errors.shop }}</small>
                </div>
                <div class="reg-field" :class="{ error: form.errors.address }">
                    <label>{{ t(isCourier ? 'Address' : 'Shop Address') }}</label>
                    <div><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path :d="icon('pin')" /></svg><input v-model="form.address" :placeholder="t(isCourier ? 'Address' : 'Shop Address')"></div>
                    <small v-if="form.errors.address">{{ form.errors.address }}</small>
                </div>
                <div class="reg-field" :class="{ error: form.errors.province_id }">
                    <label>{{ t('Governorate') }}</label>
                    <div><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path :d="icon('pin')" /></svg><select v-model="form.province_id" required><option disabled value="">{{ t('Governorate') }}</option><option v-for="province in provinces" :key="province.id" :value="province.id">{{ localizedProvince(province) }}</option></select></div>
                    <small v-if="form.errors.province_id">{{ form.errors.province_id }}</small>
                </div>
                <div class="reg-field" :class="{ error: form.errors.phone }">
                    <label>{{ t('Phone Number') }}</label>
                    <div><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path :d="icon('phone')" /></svg><input v-model="form.phone" dir="ltr" inputmode="tel" placeholder="07xx xxx xxxx" autocomplete="tel"></div>
                    <small v-if="form.errors.phone">{{ form.errors.phone }}</small>
                </div>
                <div class="reg-field" :class="{ error: form.errors.password }">
                    <label>{{ t('Password') }}</label>
                    <div><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path :d="icon('lock')" /></svg><input v-model="form.password" :type="showPassword ? 'text' : 'password'" :placeholder="t('Password')" autocomplete="new-password"><button type="button" @click="showPassword = !showPassword">{{ t(showPassword ? 'Hide' : 'Show') }}</button></div>
                    <small v-if="form.errors.password">{{ form.errors.password }}</small>
                </div>
                <div class="reg-field">
                    <label>{{ t('Confirm Password') }}</label>
                    <div><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path :d="icon('lock')" /></svg><input v-model="form.password_confirmation" :type="showConfirmation ? 'text' : 'password'" :placeholder="t('Confirm Password')" autocomplete="new-password"><button type="button" @click="showConfirmation = !showConfirmation">{{ t(showConfirmation ? 'Hide' : 'Show') }}</button></div>
                </div>

                <template v-if="isCourier">
                    <div class="courier-divider"><span>{{ t('Vehicle Type') }} · {{ t('Documents') }}</span></div>
                    <div class="reg-field vehicle-field" :class="{ error: form.errors.vehicle }">
                        <label>{{ t('Vehicle Type') }}</label>
                        <button class="vehicle-trigger" type="button" @click="showVehicles = !showVehicles">
                            <span class="vehicle-trigger-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="vehicleIcon(form.vehicle)" /></svg></span>
                            <span>{{ selectedVehicle?.label || t('Choose Vehicle') }}</span>
                            <svg class="vehicle-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6" /></svg>
                        </button>
                        <div v-if="showVehicles" class="vehicle-dropdown">
                            <button v-for="vehicle in vehicleOptions" :key="vehicle.key" type="button" class="vehicle-card" :class="{ selected: form.vehicle === vehicle.key }" @click="selectVehicle(vehicle.key)">
                                <span class="vehicle-card-check">✓</span>
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path :d="vehicleIcon(vehicle.key)" /></svg>
                                <b>{{ vehicle.label }}</b>
                            </button>
                        </div>
                        <small v-if="form.errors.vehicle">{{ form.errors.vehicle }}</small>
                    </div>

                    <div class="documents">
                        <p class="document-helper">{{ t('Images are optimized automatically before upload. Use JPG, PNG, WebP, or PDF. Each file and the full request have a safe size limit.') }}</p>
                        <small v-if="documentSummaryError || form.errors.documents" class="document-error document-summary-error">{{ documentSummaryError || form.errors.documents }}</small>
                        <p>{{ t('Residence Card') }}</p>
                        <label class="upload-zone" :class="{ uploaded: !!form.residence_document, error: !!uploadError('residence_document'), preparing: preparingDocuments.residence_document }">
                            <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" :disabled="preparingDocuments.residence_document" @change="chooseFile($event, 'residence_document')">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="form.residence_document ? icon('check') : icon('upload')" /></svg>
                            <span>{{ preparingDocuments.residence_document ? t('Preparing image…') : fileLabel('residence_document', t('Tap to upload')) }}</span>
                        </label>
                        <small v-if="fileInfo('residence_document')" class="document-info">{{ fileInfo('residence_document') }}</small>
                        <small v-if="uploadError('residence_document')" class="document-error">{{ uploadError('residence_document') }}</small>

                        <p>{{ t('National ID Card') }}</p>
                        <div class="upload-pair">
                            <label v-for="doc in [['id_front_document', t('Front')], ['id_back_document', t('Back')]]" :key="doc[0]" class="upload-zone" :class="{ uploaded: !!form[doc[0]], error: !!uploadError(doc[0]), preparing: preparingDocuments[doc[0]] }">
                                <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" :disabled="preparingDocuments[doc[0]]" @change="chooseFile($event, doc[0])">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="form[doc[0]] ? icon('check') : icon('upload')" /></svg>
                                <span>{{ preparingDocuments[doc[0]] ? t('Preparing image…') : fileLabel(doc[0], doc[1]) }}</span>
                            </label>
                        </div>
                        <div class="document-file-details"><small v-for="key in ['id_front_document', 'id_back_document']" :key="key"><template v-if="fileInfo(key)">{{ fileInfo(key) }}</template><template v-else-if="uploadError(key)"><span class="document-error">{{ uploadError(key) }}</span></template></small></div>

                        <p>{{ t('Driving License') }}</p>
                        <div class="upload-pair">
                            <label v-for="doc in [['license_front_document', t('Front')], ['license_back_document', t('Back')]]" :key="doc[0]" class="upload-zone" :class="{ uploaded: !!form[doc[0]], error: !!uploadError(doc[0]), preparing: preparingDocuments[doc[0]] }">
                            <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" :disabled="preparingDocuments[doc[0]]" @change="chooseFile($event, doc[0])">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="form[doc[0]] ? icon('check') : icon('upload')" /></svg>
                            <span>{{ preparingDocuments[doc[0]] ? t('Preparing image…') : fileLabel(doc[0], doc[1]) }}</span>
                            </label>
                        </div>
                        <div class="document-file-details"><small v-for="key in ['license_front_document', 'license_back_document']" :key="key"><template v-if="fileInfo(key)">{{ fileInfo(key) }}</template><template v-else-if="uploadError(key)"><span class="document-error">{{ uploadError(key) }}</span></template></small></div>
                    </div>
                </template>

                <button type="submit" class="reg-submit" :disabled="sending || isPreparingDocuments">
                    <span v-if="sending || isPreparingDocuments" class="loader"></span><span v-else>{{ t('Create Account') }}</span>
                </button>
            </form>
            <p class="reg-login">{{ t('Already have an account?') }} <a @click="$inertia.visit(route('login'))">{{ t('Sign In') }}</a></p>
        </section>
    </main>
</template>

<style scoped>
.register-reference { min-height:100vh; max-width:460px; margin:0 auto; color:#fff; background:linear-gradient(175deg, var(--primary-strong), var(--primary) 59%, var(--accent)); overflow-y:auto; }
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
.reg-field > div { display:flex; align-items:center; gap:8px; min-height:43px; padding:0 11px; color:rgba(255,255,255,.68); border:1.5px solid rgba(255,255,255,.25); border-radius:11px; background:rgba(255,255,255,.1); }
.reg-field > div:focus-within { border-color:rgba(255,255,255,.7); background:rgba(255,255,255,.16); }
.reg-field input, .reg-field select { min-width:0; width:100%; flex:1; color:#fff; border:0; outline:0; background:transparent; font:inherit; font-size:12px; }
.reg-field select option { color:var(--ink); background:var(--surface); }
.reg-field input::placeholder { color:rgba(255,255,255,.48); }
.reg-field button { color:rgba(255,255,255,.9); font:inherit; font-size:9.5px; font-weight:800; white-space:nowrap; }
.reg-field small { display:block; margin-top:4px; color:#ffd0cb; font-size:10px; font-weight:800; }
.courier-divider { display:flex; align-items:center; gap:8px; margin:18px 0 10px; color:rgba(255,255,255,.86); font-size:11px; font-weight:900; }
.courier-divider::before, .courier-divider::after { content:''; height:1px; flex:1; background:rgba(255,255,255,.25); }
.vehicle-field { position:relative; }
.vehicle-trigger { width:100%; min-height:44px; display:flex; align-items:center; gap:9px; padding:0 11px; color:rgba(255,255,255,.9); border:1.5px solid rgba(255,255,255,.25); border-radius:11px; background:rgba(255,255,255,.1); font:inherit; font-size:12px; font-weight:700; text-align:right; }
.vehicle-trigger-icon { width:27px; height:27px; display:grid; place-items:center; border-radius:8px; background:rgba(255,255,255,.16); }
.vehicle-chevron { margin-inline-start:auto; opacity:.75; }
.vehicle-dropdown { display:grid; grid-template-columns:repeat(2, 1fr); gap:8px; margin-top:8px; padding:8px; border:1px solid rgba(255,255,255,.24); border-radius:13px; background:rgba(5,56,52,.54); backdrop-filter:blur(9px); }
.vehicle-card { position:relative; min-height:68px; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:4px; color:#fff; border:1px solid rgba(255,255,255,.23); border-radius:11px; background:rgba(255,255,255,.1); font:inherit; }
.vehicle-card.selected { border:2px solid #fff; background:rgba(255,255,255,.21); }
.vehicle-card b { font-size:10px; font-weight:800; }
.vehicle-card-check { position:absolute; top:5px; inset-inline-end:5px; width:16px; height:16px; display:grid; place-items:center; border-radius:50%; background:#fff; color:var(--primary-strong); opacity:0; font-size:9px; font-weight:900; }
.vehicle-card.selected .vehicle-card-check { opacity:1; }
.documents { margin-top:16px; }
.documents > p { margin:14px 0 7px; color:rgba(255,255,255,.86); font-size:11px; font-weight:900; }
.documents > p:first-child { margin-top:0; }
.documents > .document-helper { margin:0 0 10px; padding:9px 10px; border:1px solid rgba(255,255,255,.16); border-radius:10px; background:rgba(2,48,45,.2); color:rgba(255,255,255,.76); font-size:9.5px; line-height:1.65; font-weight:650; }
.upload-zone { min-height:46px; display:flex; align-items:center; gap:9px; margin-top:7px; padding:8px 10px; color:rgba(255,255,255,.83); border:1px dashed rgba(255,255,255,.38); border-radius:11px; background:rgba(255,255,255,.07); font-size:10px; font-weight:700; cursor:pointer; }
.upload-zone input { display:none; }
.upload-zone.uploaded { border-style:solid; border-color:#a3f2ca; background:rgba(86, 212, 139, .16); color:#fff; }
.upload-zone.error { border-color:#ffd0cb; }
.upload-zone.preparing { opacity:.72; cursor:progress; }
.upload-pair { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.upload-pair .upload-zone { justify-content:center; flex-direction:column; min-height:63px; margin-top:0; text-align:center; }
.document-error { display:block; margin-top:4px; color:#ffd0cb; font-size:10px; font-weight:800; }
.document-summary-error { margin-bottom:3px; padding:8px 10px; border:1px solid rgba(255,208,203,.45); border-radius:9px; background:rgba(156,44,37,.16); line-height:1.5; }
.document-info { display:block; margin-top:4px; color:#b9f7d3; font-size:9px; font-weight:800; }
.document-file-details { display:grid; grid-template-columns:1fr 1fr; gap:8px; min-height:0; }
.document-file-details > small { min-width:0; color:#b9f7d3; font-size:9px; font-weight:800; line-height:1.4; overflow-wrap:anywhere; }
.reg-submit:disabled { opacity:.72; cursor:not-allowed; }
.reg-submit { width:100%; min-height:46px; margin-top:19px; border-radius:12px; background:#fff; color:var(--primary-strong); font:inherit; font-size:13px; font-weight:900; box-shadow:0 8px 20px -6px rgba(0,0,0,.28); }
.reg-login { margin:15px 0 0; text-align:center; color:rgba(255,255,255,.76); font-size:11px; font-weight:600; }
.reg-login a { color:#fff; font-weight:900; text-decoration:underline; cursor:pointer; }
@media (min-width:480px) { .register-reference { min-height:94vh; margin:3vh auto; border:1px solid rgba(255,255,255,.18); border-radius:26px; box-shadow:0 30px 60px -20px rgba(15,27,26,.4); } }
</style>
