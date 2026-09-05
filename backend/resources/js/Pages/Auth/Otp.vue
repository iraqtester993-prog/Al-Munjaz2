<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import Flash from '../../Components/Flash.vue'

const props = defineProps({
    phone: { type: String, required: true },
    role: { type: String, required: true },
    expiresAt: { type: Number, required: true },
    temporaryCodeHint: { type: String, default: null },
})

const page = usePage()
const locale = computed(() => page.props.locale || 'ar')
const direction = computed(() => locale.value === 'en' ? 'ltr' : 'rtl')
const form = useForm({ code: '' })
const digits = ref(['', '', '', '', '', ''])
const digitRefs = ref([])
const resending = ref(false)
const otpCode = computed(() => digits.value.join(''))
const maskedPhone = computed(() => {
    const value = props.phone || ''
    return value.length > 4 ? `${value.slice(0, 4)}••••${value.slice(-3)}` : value
})

function verify() {
    form.code = otpCode.value
    form.clearErrors('code')
    form.transform((data) => ({ ...data, code: String(data.code).replace(/\D/g, '') }))
        .post('/verify-otp', { preserveScroll: true })
}

function focusDigit(index) {
    nextTick(() => digitRefs.value[index]?.focus())
}

function setDigits(value, from = 0) {
    const incoming = String(value || '').replace(/\D/g, '').slice(0, digits.value.length - from)
    if (!incoming) return

    incoming.split('').forEach((digit, offset) => {
        digits.value[from + offset] = digit
    })
    form.code = otpCode.value
    form.clearErrors('code')
    focusDigit(Math.min(from + incoming.length, digits.value.length - 1))
}

function onDigitInput(event, index) {
    const value = event.target.value
    if (value.length > 1) {
        setDigits(value, index)
        return
    }

    digits.value[index] = String(value || '').replace(/\D/g, '').slice(-1)
    form.code = otpCode.value
    form.clearErrors('code')
    if (digits.value[index] && index < digits.value.length - 1) focusDigit(index + 1)
}

function onDigitKeydown(event, index) {
    if (event.key === 'Backspace' && !digits.value[index] && index > 0) {
        digits.value[index - 1] = ''
        focusDigit(index - 1)
    }

    if (event.key === 'ArrowLeft' && index > 0) focusDigit(index - 1)
    if (event.key === 'ArrowRight' && index < digits.value.length - 1) focusDigit(index + 1)
}

function onPaste(event) {
    const pasted = event.clipboardData?.getData('text') || ''
    const clean = pasted.replace(/\D/g, '')
    if (!clean) return

    event.preventDefault()
    digits.value = ['', '', '', '', '', '']
    setDigits(clean)
}

function resend() {
    if (resending.value) return
    resending.value = true
    router.post('/resend-otp', {}, {
        preserveScroll: true,
        onFinish: () => (resending.value = false),
    })
}

onMounted(() => focusDigit(0))
</script>

<template>
    <main class="otp-reference" :dir="direction" :lang="locale">
        <Flash />
        <section class="otp-card">
            <span class="otp-icon">
                <svg width="31" height="31" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11V7a4 4 0 0 0-8 0v4" /><rect x="4" y="11" width="16" height="10" rx="2" /><path d="M12 15v2" /></svg>
            </span>
            <span class="otp-role">{{ t(role === 'courier' ? 'Courier Account' : 'Merchant Account') }}</span>
            <h1>{{ t('Phone Verification') }}</h1>
            <p>{{ t('Enter the 6-digit code sent to') }}</p>
            <b class="otp-phone" dir="ltr">{{ maskedPhone }}</b>

            <form @submit.prevent="verify">
                <label for="otp-code-0">{{ t('Verify Code') }}</label>
                <div class="otp-inputs" dir="ltr" @paste="onPaste">
                    <input
                        v-for="(_, index) in digits"
                        :id="`otp-code-${index}`"
                        :key="index"
                        :ref="(element) => (digitRefs[index] = element)"
                        :value="digits[index]"
                        inputmode="numeric"
                        :autocomplete="index === 0 ? 'one-time-code' : 'off'"
                        maxlength="6"
                        pattern="[0-9]*"
                        :aria-label="`${t('Verify Code')} ${index + 1}`"
                        @input="onDigitInput($event, index)"
                        @keydown="onDigitKeydown($event, index)"
                    />
                </div>
                <small v-if="form.errors.code" class="otp-error">{{ form.errors.code }}</small>
                <p v-if="temporaryCodeHint" class="otp-hint">{{ t('Dev code') }}: <b dir="ltr">{{ temporaryCodeHint }}</b></p>
                <button class="otp-submit" type="submit" :disabled="form.processing || otpCode.length !== 6">{{ form.processing ? t('Loading...') : t('Verify Code') }}</button>
            </form>

            <button class="otp-resend" type="button" :disabled="resending" @click="resend">{{ resending ? t('Loading...') : t('Resend code') }}</button>
        </section>
    </main>
</template>

<style scoped>
.otp-reference{min-height:100dvh;display:grid;place-items:center;padding:24px 18px;color:#fff;background:linear-gradient(175deg,var(--primary-strong),var(--primary) 59%,var(--accent));writing-mode:horizontal-tb;text-orientation:mixed;rotate:none;scale:1;transform:none!important;isolation:isolate;}
.otp-card{width:min(100%,390px);padding:28px 22px;text-align:center;border:1px solid rgba(255,255,255,.28);border-radius:22px;background:rgba(255,255,255,.12);box-shadow:0 24px 46px -24px rgba(0,0,0,.45);backdrop-filter:blur(9px);}
.otp-icon{width:64px;height:64px;display:grid;place-items:center;margin:0 auto 13px;border:1px solid rgba(255,255,255,.3);border-radius:50%;background:rgba(255,255,255,.14);}
.otp-role{display:inline-block;padding:4px 11px;border:1px solid rgba(255,255,255,.25);border-radius:20px;background:rgba(255,255,255,.12);font-size:10px;font-weight:800;}
h1{margin:12px 0 4px;font-size:21px;font-weight:900;}p{margin:0;color:rgba(255,255,255,.85);font-size:11.5px;line-height:1.9;font-weight:600;}.otp-phone{display:block;margin:9px 0 20px;font-size:13px;letter-spacing:.7px;}
label{display:block;margin-bottom:7px;text-align:right;font-size:11px;font-weight:800;color:rgba(255,255,255,.9);}.otp-inputs{display:flex;gap:8px;justify-content:center;direction:ltr;}.otp-inputs input{width:42px;min-width:0;height:52px;border:1.5px solid rgba(255,255,255,.35);border-radius:12px;background:rgba(255,255,255,.12);color:#fff;font:800 22px/1 var(--font);text-align:center;outline:0;caret-color:#fff;}.otp-inputs input:focus{border-color:#fff;background:rgba(255,255,255,.18);box-shadow:0 0 0 3px rgba(255,255,255,.13);}.otp-error{display:block;margin-top:6px;text-align:right;color:#ffd0cb;font-size:10px;font-weight:800;}.otp-hint{margin:13px 0 0;padding:8px 10px;border-radius:10px;background:rgba(255,255,255,.11);font-size:10.5px;}.otp-submit,.otp-resend{width:100%;min-height:46px;border-radius:12px;font:800 12.5px var(--font);}.otp-submit{margin-top:18px;background:#fff;color:var(--primary-strong);}.otp-submit:disabled,.otp-resend:disabled{opacity:.58;cursor:not-allowed;}.otp-resend{margin-top:10px;border:1px solid rgba(255,255,255,.3);background:rgba(255,255,255,.08);color:#fff;}
</style>
