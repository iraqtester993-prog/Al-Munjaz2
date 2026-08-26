<script setup>
import { computed } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'

const props = defineProps({
    token: { type: String, required: true },
    invitation: { type: Object, required: true },
})

const page = usePage()
const locale = computed(() => page.props.locale || 'ar')
const labels = {
    ar: {
        title: 'تفعيل دعوة إدارة المنصة', subtitle: 'أكمل بياناتك لإنشاء حساب مدير لوحة التحكم.', name: 'الاسم الكامل', username: 'اسم المستخدم', phone: 'رقم الهاتف', password: 'كلمة المرور', confirm: 'تأكيد كلمة المرور', submit: 'تفعيل الحساب والدخول', secure: 'هذه الدعوة شخصية ومحددة المدة.', expires: 'تنتهي الدعوة في',
    },
    en: {
        title: 'Activate dashboard invitation', subtitle: 'Complete your details to create a platform-dashboard operator account.', name: 'Full name', username: 'Username', phone: 'Phone number', password: 'Password', confirm: 'Confirm password', submit: 'Activate account and sign in', secure: 'This invitation is personal and time limited.', expires: 'Invitation expires',
    },
    ku: {
        title: 'چالاککردنی بانگهێشتی داشبۆرد', subtitle: 'زانیارییەکانت تەواو بکە بۆ دروستکردنی هەژماری بەڕێوەبەری داشبۆردی پلاتفۆرم.', name: 'ناوی تەواو', username: 'ناوی بەکارهێنەر', phone: 'ژمارەی تەلەفۆن', password: 'وشەی نهێنی', confirm: 'دڵنیابوونەوەی وشەی نهێنی', submit: 'چالاککردنی هەژمار و چوونەژوورەوە', secure: 'ئەم بانگهێشتە تایبەتییە و ماوەی دیاریکراوی هەیە.', expires: 'بانگهێشتەکە کۆتایی دێت لە',
    },
}
function l(key) { return labels[locale.value]?.[key] || labels.ar[key] || key }

const form = useForm({
    name: props.invitation.name || '',
    username: '',
    phone: '',
    password: '',
    password_confirmation: '',
})

function submit() {
    form.post(route('admin.invitations.accept.store', props.token))
}
</script>

<template>
    <main class="invite-page">
        <section class="invite-card">
            <div class="mark" aria-hidden="true">↗</div>
            <p class="eyebrow">AL-MUNJAZ · PLATFORM</p>
            <h1>{{ l('title') }}</h1>
            <p class="subtitle">{{ l('subtitle') }}</p>

            <div class="invite-summary">
                <b>{{ invitation.email }}</b>
                <span>{{ l('expires') }}: {{ invitation.expires_at }}</span>
            </div>

            <form @submit.prevent="submit">
                <label><span>{{ l('name') }}</span><input v-model.trim="form.name" required autocomplete="name"></label>
                <label><span>{{ l('username') }}</span><input v-model.trim="form.username" dir="ltr" required autocomplete="username"></label>
                <label><span>{{ l('phone') }}</span><input v-model.trim="form.phone" dir="ltr" required autocomplete="tel"></label>
                <label><span>{{ l('password') }}</span><input v-model="form.password" dir="ltr" type="password" required autocomplete="new-password"></label>
                <label><span>{{ l('confirm') }}</span><input v-model="form.password_confirmation" dir="ltr" type="password" required autocomplete="new-password"></label>
                <p v-if="Object.keys(form.errors).length" class="error">{{ Object.values(form.errors)[0] }}</p>
                <button :disabled="form.processing" type="submit">{{ l('submit') }}</button>
            </form>

            <p class="secure">🔒 {{ l('secure') }}</p>
        </section>
    </main>
</template>

<style scoped>
.invite-page{min-height:100dvh;display:grid;place-items:center;padding:24px;background:radial-gradient(circle at 10% 5%,rgba(34,211,238,.22),transparent 31%),#0b1220;color:#e6edf7;font-family:var(--font)}.invite-card{width:min(100%,470px);padding:30px;border:1px solid rgba(255,255,255,.11);border-radius:22px;background:rgba(22,33,58,.94);box-shadow:0 26px 65px rgba(0,0,0,.34)}.mark{width:42px;height:42px;display:grid;place-items:center;border-radius:13px;color:#062033;background:#22d3ee;font-size:22px;font-weight:950}.eyebrow{margin:18px 0 5px;color:#22d3ee;font-size:10px;font-weight:900;letter-spacing:.13em}.invite-card h1{margin:0;color:#fff;font-size:23px;line-height:1.45}.subtitle{margin:7px 0 18px;color:#9aa8bf;font-size:11px;font-weight:650;line-height:1.85}.invite-summary{display:grid;gap:3px;margin-bottom:17px;padding:11px 12px;border:1px solid rgba(34,211,238,.22);border-radius:12px;background:rgba(34,211,238,.08)}.invite-summary b{direction:ltr;color:#e6edf7;font-size:11px}.invite-summary span{color:#9aa8bf;font-size:9.5px;font-weight:700}form{display:grid;gap:11px}label{display:grid;gap:5px;color:#aebcd1;font-size:10px;font-weight:850}input{width:100%;min-height:42px;padding:9px 10px;border:1px solid rgba(255,255,255,.12);border-radius:10px;outline:0;color:#edf7ff;background:#101b2e;font:700 12px var(--font)}input:focus{border-color:#22d3ee;box-shadow:0 0 0 3px rgba(34,211,238,.12)}button{min-height:43px;margin-top:5px;border:0;border-radius:11px;color:#062033;background:linear-gradient(135deg,#22d3ee,#0ea5e9);font:900 12px var(--font);cursor:pointer}button:disabled{opacity:.58;cursor:wait}.error{margin:0;color:#fda4af;font-size:10px;font-weight:800}.secure{margin:17px 0 0;color:#75849d;font-size:9.5px;font-weight:700;text-align:center}
</style>
