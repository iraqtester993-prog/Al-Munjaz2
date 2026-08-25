<script setup>
import { computed, ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import Flash from '../../Components/Flash.vue'
import PwaInstallBanner from '../../Components/PwaInstallBanner.vue'

const page = usePage()
const errors = computed(() => page.props.errors || {})
const view = ref('start')
const dark = ref(false)
const showPassword = ref(false)

const roles = [
    { key: 'merchant', label: 'تطبيق التاجر', desc: 'طلباتي، كشف حسابي، محفظتي', icon: 'shop' },
    { key: 'courier', label: 'تطبيق المندوب', desc: 'توصيلاتي، تحصيلاتي، محفظتي', icon: 'bike' },
]

const form = useForm({ username: '', password: '', role: 'merchant' })

function chooseRole(role) {
    form.role = role
    view.value = 'login'
}

function toggleTheme() {
    dark.value = !dark.value
    document.documentElement.dataset.theme = dark.value ? 'dark' : 'light'
}

function submit() {
    form.post('/login', { preserveScroll: true })
}

function icon(name) {
    const paths = {
        shop: 'M3 9 4.5 4h15L21 9M3 9a2.4 2.4 0 0 0 4.6 1.1A2.4 2.4 0 0 0 12 9a2.4 2.4 0 0 0 4.4 1.1A2.4 2.4 0 0 0 21 9M5 9v10h14V9M9.5 19v-5h5v5',
        bike: 'M5.5 18a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm13 0a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7ZM5.5 14.5h5l2.5-5h3l2.5 5M11 9.5h4M14.5 6.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z',
        arrow: 'M15 18l-6-6 6-6',
        user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-8 8a8 8 0 0 1 16 0',
        lock: 'M6.5 10V7.5a5.5 5.5 0 0 1 11 0V10M5 10h14v11H5z',
        sun: 'M12 3v2M12 19v2M5.6 5.6 7 7M17 17l1.4 1.4M3 12h2M19 12h2M5.6 18.4 7 17M17 7l1.4-1.4M16.5 12a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z',
        moon: 'M20 15.2A8.5 8.5 0 0 1 8.8 4 8.5 8.5 0 1 0 20 15.2Z',
    }
    return paths[name]
}
</script>

<template>
    <main class="reference-auth" :class="{ dark }" dir="rtl">
        <Flash />
        <header class="reference-auth-header">
            <button class="auth-icon" type="button" :aria-label="dark ? 'الوضع الفاتح' : 'الوضع الداكن'" @click="toggleTheme">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="icon(dark ? 'moon' : 'sun')" /></svg>
            </button>
            <div class="reference-brand">
                <span class="brand-mark"><img src="/logo.png" alt="شعار المنجز السريع"></span>
                <b>المنجز السريع</b>
            </div>
            <button v-if="view === 'login'" class="auth-icon" type="button" aria-label="رجوع" @click="view = 'start'">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6" /></svg>
            </button>
            <span v-else class="auth-icon-placeholder"></span>
        </header>

        <section v-if="view === 'start'" class="start-pane">
            <div class="role-cards">
                <button v-for="role in roles" :key="role.key" class="reference-role-card" type="button" @click="chooseRole(role.key)">
                    <span class="role-art">
                        <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="icon(role.icon)" /></svg>
                    </span>
                    <span class="role-copy"><b>{{ role.label }}</b><small>{{ role.desc }}</small></span>
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path :d="icon('arrow')" /></svg>
                </button>
            </div>

            <PwaInstallBanner />
        </section>

        <section v-else class="login-pane">
            <div class="login-hero">
                <span class="login-role-icon">
                    <svg width="29" height="29" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="icon(form.role === 'courier' ? 'bike' : 'shop')" /></svg>
                </span>
                <span class="login-chip">{{ form.role === 'courier' ? 'تطبيق المندوب' : 'تطبيق التاجر' }}</span>
                <h1>تسجيل الدخول</h1>
                <p>أدخل بياناتك للدخول إلى حسابك</p>
            </div>

            <form class="login-card-ref" @submit.prevent="submit">
                <label>
                    <span>اسم المستخدم أو رقم الهاتف</span>
                    <div class="auth-input-wrap">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path :d="icon('user')" /></svg>
                        <input v-model="form.username" dir="rtl" autocomplete="username" placeholder="أدخل اسم المستخدم أو رقم الهاتف" required>
                    </div>
                    <small v-if="errors.username" class="login-error">{{ errors.username }}</small>
                </label>
                <label>
                    <span>كلمة المرور</span>
                    <div class="auth-input-wrap">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="icon('lock')" /></svg>
                        <input v-model="form.password" :type="showPassword ? 'text' : 'password'" autocomplete="current-password" placeholder="أدخل كلمة المرور" required>
                        <button class="show-password" type="button" @click="showPassword = !showPassword">{{ showPassword ? 'إخفاء' : 'إظهار' }}</button>
                    </div>
                    <small v-if="errors.password" class="login-error">{{ errors.password }}</small>
                </label>
                <button class="login-submit" type="submit" :disabled="form.processing">
                    <span v-if="form.processing" class="loader"></span>
                    <span v-else>تسجيل الدخول</span>
                </button>
            </form>

            <p class="register-link-ref">ليس لديك حساب؟ <a @click="$inertia.visit(route('register', form.role))">إنشاء حساب جديد</a></p>
        </section>
    </main>
</template>

<style scoped>
.reference-auth { min-height:100vh; max-width:460px; margin:0 auto; color:#fff; background:linear-gradient(175deg, var(--primary-strong), var(--primary) 59%, var(--accent)); display:flex; flex-direction:column; overflow:hidden; }
.reference-auth.dark { background:linear-gradient(175deg, #08312e, #0f5450 59%, #7a4a17); }
.reference-auth-header { display:grid; grid-template-columns:38px 1fr 38px; align-items:center; padding:18px 18px 8px; }
.reference-brand { display:flex; align-items:center; justify-content:center; gap:8px; font-size:14px; font-weight:900; }
.brand-mark { width:34px; height:34px; border-radius:10px; background:#fff; display:grid; place-items:center; overflow:hidden; box-shadow:0 4px 14px rgba(0,0,0,.16); }
.brand-mark img { width:100%; height:100%; object-fit:contain; }
.auth-icon, .auth-icon-placeholder { width:34px; height:34px; border:1px solid rgba(255,255,255,.27); border-radius:10px; background:rgba(255,255,255,.14); color:#fff; display:grid; place-items:center; }
.auth-icon-placeholder { visibility:hidden; }
.start-pane, .login-pane { flex:1; display:flex; flex-direction:column; padding:18px 22px 28px; }
.start-pane { justify-content:center; }
.role-cards { display:grid; gap:12px; }
.reference-role-card { width:100%; display:flex; align-items:center; gap:13px; padding:15px; text-align:right; color:#fff; background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.32); border-radius:18px; font:inherit; backdrop-filter:blur(7px); }
.reference-role-card:active { transform:scale(.985); }
.role-art { width:43px; height:43px; flex:none; border-radius:13px; background:rgba(255,255,255,.2); color:#fff; display:grid; place-items:center; }
.role-copy { flex:1; min-width:0; }
.role-copy b, .role-copy small { display:block; }
.role-copy b { font-size:14px; font-weight:900; }
.role-copy small { font-size:10px; line-height:1.6; margin-top:2px; opacity:.84; font-weight:600; }
.login-hero { text-align:center; margin:18px 0 21px; }
.login-role-icon { width:63px; height:63px; border-radius:50%; display:grid; place-items:center; margin:0 auto 12px; background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25); }
.login-chip { display:inline-flex; border:1px solid rgba(255,255,255,.24); border-radius:20px; background:rgba(255,255,255,.14); padding:5px 13px; font-size:10.5px; font-weight:800; }
.login-hero h1 { font-size:20px; font-weight:900; margin:10px 0 2px; }
.login-hero p { margin:0; opacity:.82; font-size:11px; font-weight:600; }
.login-card-ref { padding:19px 15px; border:1px solid rgba(255,255,255,.26); border-radius:18px; background:rgba(255,255,255,.12); backdrop-filter:blur(8px); }
.login-card-ref label { display:block; margin-bottom:13px; }
.login-card-ref label > span { display:block; margin-bottom:6px; font-size:11.5px; font-weight:800; color:rgba(255,255,255,.88); }
.auth-input-wrap { position:relative; display:flex; align-items:center; gap:9px; padding:0 12px; min-height:46px; border:1.5px solid rgba(255,255,255,.25); border-radius:11px; background:rgba(255,255,255,.11); color:rgba(255,255,255,.74); }
.auth-input-wrap:focus-within { border-color:rgba(255,255,255,.72); background:rgba(255,255,255,.17); }
.auth-input-wrap input { min-width:0; flex:1; width:100%; color:#fff; border:0; outline:0; font:inherit; font-size:12px; background:transparent; }
.auth-input-wrap input::placeholder { color:rgba(255,255,255,.48); }
.show-password { padding:2px; color:rgba(255,255,255,.88); font:inherit; font-size:9.5px; font-weight:800; white-space:nowrap; }
.login-error { display:block; padding-top:5px; color:#ffd0cb; font-size:10px; font-weight:800; }
.login-submit { width:100%; min-height:46px; margin-top:3px; border-radius:12px; background:#fff; color:var(--primary-strong); font:inherit; font-size:13px; font-weight:900; box-shadow:0 8px 20px -6px rgba(0,0,0,.28); }
.register-link-ref { margin:17px 0 0; text-align:center; color:rgba(255,255,255,.76); font-size:11.5px; font-weight:600; }
.register-link-ref a { color:#fff; font-weight:900; text-decoration:underline; cursor:pointer; }
@media (min-width:480px) { .reference-auth { min-height:94vh; margin:3vh auto; border:1px solid rgba(255,255,255,.18); border-radius:26px; box-shadow:0 30px 60px -20px rgba(15,27,26,.4); } }
</style>
