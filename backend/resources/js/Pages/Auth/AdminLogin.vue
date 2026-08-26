<script setup>
import { computed } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import Flash from '../../Components/Flash.vue'

const page = usePage()
const errors = computed(() => page.props.errors || {})
const form = useForm({ username: '', password: '' })
const branding = computed(() => page.props.branding || {
    name: t('Al-Munjaz Al-Saree'),
    tagline: t('Platform management'),
    logo_url: '/logo.png',
})

const appLoginUrl = computed(() => {
    const host = window.location.hostname.replace(/^(?:admin|dashboard)\./, 'mobile.')
    return `${window.location.protocol}//${host}/login`
})

const loginError = computed(() => errors.value.username || errors.value.password || form.errors.username || form.errors.password)

function submit() {
    form.post('/dashboard/login', {
        preserveScroll: true,
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <main class="dash-login-page" :dir="$page.props.locale === 'en' ? 'ltr' : 'rtl'">
        <section class="dash-login-card" aria-labelledby="dashboard-login-title">
            <Flash />

            <div class="dash-login-mark" aria-hidden="true"><img :src="branding.logo_url" alt="" /></div>

            <h1 id="dashboard-login-title">{{ branding.name }}</h1>
            <p class="dash-login-lead">{{ branding.tagline || t('Platform management') }}</p>
            <p class="dash-login-description">{{ t('Access platform management for orders, couriers, finance, and unified chat.') }}</p>

            <div v-if="loginError" class="dash-login-error" role="alert">
                {{ loginError }}
            </div>

            <form class="dash-login-form" @submit.prevent="submit">
                <label class="dash-field">
                    <span>{{ t('Username') }}</span>
                    <input
                        v-model="form.username"
                        :placeholder="t('Username')"
                        autocomplete="username"
                        autofocus
                        required
                    />
                </label>

                <label class="dash-field">
                    <span>{{ t('Password') }}</span>
                    <input
                        v-model="form.password"
                        type="password"
                        :placeholder="'••••••••'"
                        autocomplete="current-password"
                        required
                    />
                </label>

                <button class="dash-login-submit" type="submit" :disabled="form.processing">
                    <span v-if="form.processing" class="dash-spinner" aria-hidden="true" />
                    {{ form.processing ? t('Loading...') : t('Login') }}
                </button>
            </form>

            <p class="dash-login-footer">
                <a :href="appLoginUrl">{{ t('Merchant App') }} / {{ t('Courier App') }}</a>
            </p>
        </section>
    </main>
</template>

<style scoped>
.dash-login-page {
    --login-bg: #0f172a;
    --login-surface: #16213a;
    --login-surface-2: #1d2a47;
    --login-border: rgba(255, 255, 255, .09);
    --login-ink: #e6edf7;
    --login-soft: #9aa8bf;
    --login-faint: #64748b;
    --login-primary: #22d3ee;
    --login-primary-2: #0ea5e9;
    --login-danger: #f87171;
    min-height: 100dvh;
    display: grid;
    place-items: center;
    padding: 24px 16px;
    color: var(--login-ink);
    background:
        radial-gradient(1000px 600px at 15% 5%, rgba(34, 211, 238, .14), transparent 60%),
        radial-gradient(800px 500px at 88% 94%, rgba(167, 139, 250, .13), transparent 60%),
        var(--login-bg);
}

.dash-login-card {
    width: min(100%, 410px);
    padding: 38px 32px 27px;
    border: 1px solid var(--login-border);
    border-radius: 22px;
    background: var(--login-surface);
    box-shadow: 0 30px 60px rgba(0, 0, 0, .48);
}

.dash-login-mark {
    width: 58px;
    height: 58px;
    display: grid;
    place-items: center;
    margin: 0 auto 16px;
    border-radius: 16px;
    overflow: hidden;
    color: #062033;
    background: #fff;
    box-shadow: 0 12px 28px rgba(14, 165, 233, .22);
}

.dash-login-mark img {
    width: 100%;
    height: 100%;
    display: block;
    object-fit: contain;
    padding: 4px;
}

.dash-login-card h1 {
    color: var(--login-ink);
    font-size: 20px;
    font-weight: 900;
    line-height: 1.45;
    text-align: center;
}

.dash-login-lead {
    margin: 5px auto 4px;
    color: var(--login-soft);
    font-size: 12.5px;
    font-weight: 600;
    line-height: 1.8;
    text-align: center;
}

.dash-login-description {
    max-width: 315px;
    margin: 0 auto 27px;
    color: var(--login-soft);
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.85;
    text-align: center;
}

.dash-login-error {
    margin-bottom: 15px;
    padding: 10px 12px;
    border: 1px solid rgba(248, 113, 113, .36);
    border-radius: 11px;
    color: var(--login-danger);
    background: rgba(248, 113, 113, .12);
    font-size: 12px;
    font-weight: 800;
    line-height: 1.7;
    text-align: center;
}

.dash-login-form {
    display: grid;
    gap: 15px;
}

.dash-field {
    display: grid;
    gap: 7px;
}

.dash-field span {
    color: var(--login-soft);
    font-size: 11.5px;
    font-weight: 800;
}

.dash-field input {
    width: 100%;
    min-height: 47px;
    padding: 11px 14px;
    border: 1px solid var(--login-border);
    border-radius: 11px;
    outline: none;
    color: var(--login-ink);
    background: var(--login-surface-2);
    font: inherit;
    font-size: 13.5px;
    direction: ltr;
    text-align: start;
    transition: border-color .15s, box-shadow .15s;
}

.dash-field input::placeholder {
    color: var(--login-faint);
}

.dash-field input:focus {
    border-color: var(--login-primary);
    box-shadow: 0 0 0 3px rgba(34, 211, 238, .13);
}

.dash-login-submit {
    min-height: 49px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 4px;
    border: 0;
    border-radius: 12px;
    color: #062033;
    background: linear-gradient(135deg, var(--login-primary), var(--login-primary-2));
    font: inherit;
    font-size: 14px;
    font-weight: 900;
    cursor: pointer;
    transition: filter .15s, transform .15s, opacity .15s;
}

.dash-login-submit:hover:not(:disabled) {
    filter: brightness(1.08);
}

.dash-login-submit:active:not(:disabled) {
    transform: translateY(1px);
}

.dash-login-submit:disabled {
    cursor: wait;
    opacity: .7;
}

.dash-spinner {
    width: 16px;
    height: 16px;
    border: 2px solid rgba(6, 32, 51, .25);
    border-top-color: #062033;
    border-radius: 50%;
    animation: dash-spin .65s linear infinite;
}

.dash-login-footer {
    margin-top: 19px;
    color: var(--login-faint);
    font-size: 11px;
    font-weight: 700;
    text-align: center;
}

.dash-login-footer a {
    color: var(--login-soft);
    text-decoration: none;
    transition: color .15s;
}

.dash-login-footer a:hover {
    color: var(--login-primary);
}

@keyframes dash-spin {
    to { transform: rotate(360deg); }
}

@media (max-width: 460px) {
    .dash-login-page { padding: 16px; }
    .dash-login-card { padding: 32px 22px 24px; border-radius: 19px; }
}
</style>
