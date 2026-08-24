<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import axios from 'axios'
import { router, useForm, usePage } from '@inertiajs/vue3'
import Flash from '../../Components/Flash.vue'

const props = defineProps({
    role: { type: String, required: true },
    vehicles: { type: Object, required: true },
    provinces: { type: Array, required: true },
})

const page = usePage()
const errors = computed(() => page.props.errors || {})

const isCourier = props.role === 'courier'
const step = ref('form')
const otp = ref('')
const sending = ref(false)
const countdown = ref(0)
const otpInputs = ref([])

const form = useForm({
    role: props.role,
    name: '',
    phone: '',
    shop: '',
    address: '',
    vehicle: 'bike',
    province_id: '',
    password: '',
})

const vehicleList = computed(() =>
    Object.entries(props.vehicles).map(([key, v]) => ({ key, label: v[window.__locale] || v.ar }))
)

let timer = null
function startCountdown() {
    countdown.value = 60
    clearInterval(timer)
    timer = setInterval(() => {
        countdown.value--
        if (countdown.value <= 0) clearInterval(timer)
    }, 1000)
}

async function submitForm() {
    sending.value = true
    try {
        await axios.post('/register', form.data())
        step.value = 'otp'
        startCountdown()
        await nextTick()
        otpInputs.value[0]?.focus()
    } catch (e) {
        const data = e.response?.data
        if (data?.errors) form.setError(data.errors)
    } finally {
        sending.value = false
    }
}

async function resend() {
    await axios.post('/resend-otp')
    startCountdown()
}

function verify() {
    if (otp.value.length !== 6) return
    sending.value = true
    router.post('/verify-otp', { code: otp.value }, {
        preserveScroll: true,
        onFinish: () => (sending.value = false),
    })
}

function onOtp(i, e) {
    const val = e.target.value.replace(/\D/g, '').slice(-1)
    const arr = otp.value.split('')
    arr[i] = val
    otp.value = arr.join('')
    if (val && i < 5) otpInputs.value[i + 1]?.focus()
}

function onBack(i) {
    if (!otp.value[i] && i > 0) otpInputs.value[i - 1]?.focus()
}
</script>

<template>
    <div class="app-stage" style="padding: 24px 16px">
        <div class="app-shell" style="justify-content: flex-start; overflow-y: auto">
            <Flash />
            <div style="padding: 32px 22px 20px">
                <button class="tb-icon-btn" style="margin-bottom: 18px" @click="$inertia.visit(route('login'))">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" :style="{ transform: window.__locale === 'ar' ? 'rotate(180deg)' : '' }">
                        <path d="M19 12H5m0 0 6-6m-6 6 6 6" />
                    </svg>
                </button>
                <div class="role-ico-lg">
                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="isCourier ? 'M5 18a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm14-8a4 4 0 1 1 0 8 4 4 0 0 1 0-8Z M5 10h14 M12 10l-2-4h5' : 'M4 10v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V10 M2 7l1-3h18l1 3a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0Z'" />
                    </svg>
                </div>
                <h1 style="font-size: 19px; font-weight: 900; margin-top: 14px">
                    {{ isCourier ? t('Courier App') : t('Merchant App') }}
                </h1>
                <p class="text-muted" style="margin-top: 4px">{{ t('Register') }}</p>
            </div>

            <form v-if="step === 'form'" @submit.prevent="submitForm" style="padding: 0 22px 30px">
                <div class="field" :class="{ 'has-error': form.errors.name }">
                    <label>{{ t('Full Name') }}</label>
                    <input v-model="form.name" :placeholder="t('Full Name')" />
                    <span v-if="form.errors.name" class="field-error">{{ form.errors.name }}</span>
                </div>
                <div class="field" :class="{ 'has-error': form.errors.phone }">
                    <label>{{ t('Phone') }}</label>
                    <input v-model="form.phone" dir="ltr" :placeholder="t('Phone')" />
                    <span v-if="form.errors.phone" class="field-error">{{ form.errors.phone }}</span>
                </div>
                <div v-if="!isCourier" class="field" :class="{ 'has-error': form.errors.shop }">
                    <label>{{ t('Shop Name') }}</label>
                    <input v-model="form.shop" :placeholder="t('Shop Name')" />
                    <span v-if="form.errors.shop" class="field-error">{{ form.errors.shop }}</span>
                </div>
                <div v-if="!isCourier" class="field" :class="{ 'has-error': form.errors.address }">
                    <label>{{ t('Shop Address') }}</label>
                    <input v-model="form.address" :placeholder="t('Shop Address')" />
                    <span v-if="form.errors.address" class="field-error">{{ form.errors.address }}</span>
                </div>
                <div v-if="isCourier" class="field">
                    <label>{{ t('Vehicle') }}</label>
                    <select v-model="form.vehicle">
                        <option v-for="v in vehicleList" :key="v.key" :value="v.key">{{ v.label }}</option>
                    </select>
                </div>
                <div class="field" :class="{ 'has-error': form.errors.province_id }">
                    <label>المحافظة</label>
                    <select v-model="form.province_id" required>
                        <option disabled value="">اختر المحافظة</option>
                        <option v-for="province in props.provinces" :key="province.id" :value="province.id">{{ province.name_ar }}</option>
                    </select>
                    <span v-if="form.errors.province_id" class="field-error">{{ form.errors.province_id }}</span>
                </div>
                <div class="field" :class="{ 'has-error': form.errors.password }">
                    <label>{{ t('Password') }}</label>
                    <input v-model="form.password" type="password" :placeholder="t('Password')" autocomplete="new-password" />
                    <span v-if="form.errors.password" class="field-error">{{ form.errors.password }}</span>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%" :disabled="sending">
                    <span v-if="sending" class="loader"></span>
                    {{ t('Continue') }}
                </button>
            </form>

            <div v-else style="padding: 0 22px 30px; text-align: center">
                <p class="text-muted" style="margin-bottom: 16px">{{ t('We sent a verification code to') }} <b style="direction: ltr; display: inline-block">{{ form.phone }}</b></p>
                <div class="otp-row">
                    <input
                        v-for="(d, i) in 6"
                        :key="i"
                        ref="otpInputs"
                        :value="otp[i] || ''"
                        maxlength="1"
                        inputmode="numeric"
                        class="otp-input"
                        @input="onOtp(i, $event)"
                        @keydown.backspace="onBack(i)"
                    />
                </div>
                <span v-if="errors.code" class="field-error" style="display: block; text-align: center; margin-top: 8px">{{ errors.code }}</span>
                <button class="btn btn-primary" style="width: 100%; margin-top: 18px" :disabled="otp.length !== 6 || sending" @click="verify">
                    <span v-if="sending" class="loader"></span>
                    {{ t('Confirm') }}
                </button>
                <p class="text-muted" style="margin-top: 16px">
                    <template v-if="countdown > 0">{{ t('Resend code in') }} {{ countdown }}s</template>
                    <a v-else class="link" style="font-weight: 800" @click="resend">{{ t('Resend code') }}</a>
                </p>
                <div class="dev-hint">{{ t('Dev code') }}: 123456</div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.role-ico-lg {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    background: var(--primary-tint);
    color: var(--primary-strong);
    display: flex;
    align-items: center;
    justify-content: center;
}
.otp-row {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-bottom: 6px;
}
.otp-input {
    width: 44px;
    height: 52px;
    border-radius: 12px;
    border: 1.5px solid var(--border);
    background: var(--surface);
    text-align: center;
    font-size: 19px;
    font-weight: 800;
    color: var(--ink);
    outline: none;
}
.otp-input:focus {
    border-color: var(--primary);
}
.dev-hint {
    margin-top: 14px;
    padding: 8px 12px;
    border-radius: 10px;
    background: var(--accent-tint);
    color: var(--accent);
    font-size: 11px;
    font-weight: 800;
    display: inline-block;
}
</style>
