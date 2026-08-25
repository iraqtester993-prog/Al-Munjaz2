<script setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'

defineProps({ branches: { type: Array, default: () => [] } })

const open = ref(false)
const form = useForm({ code: '', name_ar: '', city: '', phone: '', address: '' })

function submit() {
    form.post(route('admin.branches.store'), {
        preserveScroll: true,
        onSuccess: () => { open.value = false; form.reset() },
    })
}
</script>

<template>
    <AdminShell title="الفروع">
        <div class="section-head">
            <div><h2>الفروع</h2><p>إدارة فروع الشركة وأرصدة صناديقها</p></div>
            <button class="btn primary" @click="open = true">+ فرع جديد</button>
        </div>

        <div class="branch-grid">
            <article v-for="branch in branches" :key="branch.id" class="branch-card">
                <div class="branch-head"><span class="branch-icon">⌂</span><span class="state" :class="{ off: !branch.is_active }">{{ branch.is_active ? 'نشط' : 'موقوف' }}</span></div>
                <h3>{{ branch.name }}</h3>
                <p>{{ branch.city }} <span v-if="branch.code">· {{ branch.code }}</span></p>
                <div class="branch-stats"><span><b>{{ branch.orders_count }}</b> طلب</span><span><b>{{ branch.users_count }}</b> مستخدم</span></div>
                <div class="cash"><span>رصيد الصندوق</span><b class="mono">{{ fmt(branch.cash_balance) }} د.ع</b></div>
            </article>
            <div v-if="!branches.length" class="panel empty">لا توجد فروع بعد. أضف الفرع الأول لبدء التشغيل.</div>
        </div>

        <div v-if="open" class="modal-backdrop" @click.self="open = false">
            <form class="branch-modal" @submit.prevent="submit">
                <header><h3>فرع جديد</h3><button type="button" @click="open = false">×</button></header>
                <label>رمز الفرع<input v-model="form.code" required placeholder="BGD-HQ" /></label>
                <label>اسم الفرع<input v-model="form.name_ar" required placeholder="فرع بغداد الرئيسي" /></label>
                <label>المحافظة / المدينة<input v-model="form.city" required placeholder="بغداد" /></label>
                <label>الهاتف<input v-model="form.phone" placeholder="07xxxxxxxxx" /></label>
                <label>العنوان<textarea v-model="form.address" rows="2" /></label>
                <p v-if="form.errors.code" class="error">{{ form.errors.code }}</p>
                <button class="btn primary" type="submit" :disabled="form.processing">حفظ الفرع</button>
            </form>
        </div>
    </AdminShell>
</template>

<style scoped>
.section-head{display:flex;justify-content:space-between;align-items:end;margin-bottom:20px}.section-head h2{margin:0;font-size:21px}.section-head p{margin:4px 0 0;color:var(--ink-faint);font-size:12px}.btn{border:0;border-radius:10px;padding:10px 15px;font:inherit;font-weight:800;cursor:pointer}.primary{background:var(--primary);color:#fff}.branch-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(235px,1fr));gap:16px}.branch-card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:18px;box-shadow:var(--shadow)}.branch-head{display:flex;justify-content:space-between}.branch-icon{width:38px;height:38px;display:grid;place-items:center;border-radius:11px;background:var(--primary-tint);color:var(--primary-strong);font-weight:900}.state{font-size:11px;color:var(--success);background:var(--success-tint);padding:4px 9px;border-radius:20px;height:max-content}.state.off{color:var(--danger);background:var(--danger-tint)}h3{margin:15px 0 3px;font-size:15px}.branch-card p{color:var(--ink-faint);font-size:12px;margin:0}.branch-stats{display:flex;gap:20px;margin:18px 0 14px;font-size:12px;color:var(--ink-soft)}.branch-stats b{color:var(--ink)}.cash{border-top:1px solid var(--border);padding-top:12px;display:flex;justify-content:space-between;font-size:11px;color:var(--ink-faint)}.cash b{color:var(--primary-strong);font-size:12px}.modal-backdrop{position:fixed;inset:0;background:#0a121180;display:grid;place-items:center;z-index:99;padding:20px}.branch-modal{width:min(420px,100%);background:var(--surface);border-radius:18px;padding:20px;display:grid;gap:12px}.branch-modal header{display:flex;justify-content:space-between;align-items:center}.branch-modal header h3{margin:0}.branch-modal header button{border:0;background:transparent;font-size:25px}.branch-modal label{font-size:12px;font-weight:700;display:grid;gap:6px}.branch-modal input,.branch-modal textarea{font:inherit;border:1px solid var(--border);border-radius:9px;padding:10px;background:var(--surface-2)}.error{color:var(--danger);margin:0;font-size:12px}.empty{padding:30px}
</style>
