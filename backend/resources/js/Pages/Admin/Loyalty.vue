<script setup>
import { computed, ref, watch } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'

const props = defineProps({
    couriers: { type: Array, default: () => [] },
    entries: { type: Array, default: () => [] },
    settings: { type: Object, default: () => ({ points_per_delivery: 0 }) },
    summary: { type: Object, default: () => ({}) },
})

const page = usePage()
const locale = computed(() => page.props.locale || 'ar')
const search = ref('')
const selectedCourierId = ref('')
const adjustmentOpen = ref(false)

const labels = {
    title: { ar: 'نقاط الولاء', en: 'Loyalty points', ku: 'خاڵەکانی دڵسۆزی' },
    eyebrow: { ar: 'برنامج الولاء', en: 'Loyalty programme', ku: 'بەرنامەی دڵسۆزی' },
    subtitle: { ar: 'تحكم بمكافآت إكمال الطلبات وسجل نقاط المندوبين من مكان واحد. كل حركة تحفظ كسجل غير قابل للتعديل.', en: 'Control completed-delivery rewards and courier point history in one place. Every movement is preserved as an immutable ledger entry.', ku: 'پاداشتی تەواوکردنی داواکاری و مێژووی خاڵەکانی گەیەنەر لە یەک شوێن بەڕێوەببە. هەر جوڵەیەک تۆمارێکی نەگۆڕە.' },
    refresh: { ar: 'تحديث', en: 'Refresh', ku: 'نوێکردنەوە' },
    activeCouriers: { ar: 'مندوبون نشطون', en: 'Active couriers', ku: 'گەیەنەرانی چالاک' },
    circulation: { ar: 'إجمالي النقاط', en: 'Points in circulation', ku: 'کۆی خاڵەکان' },
    rewarded: { ar: 'لديهم رصيد', en: 'With a balance', ku: 'خاوەن باڵانس' },
    ledger: { ar: 'قيود السجل', en: 'Ledger entries', ku: 'تۆمارەکانی دفتر' },
    settings: { ar: 'مكافأة إكمال الطلب', en: 'Completed delivery reward', ku: 'پاداشتی تەواوکردنی داواکاری' },
    settingsHint: { ar: 'عدد النقاط التي تضاف تلقائياً للمندوب عند تحويل الطلب إلى «تم التسليم». ضع 0 لإيقاف المكافأة دون حذف السجل السابق.', en: 'Points credited automatically when an order reaches Delivered. Set 0 to pause future awards without deleting history.', ku: 'ژمارەی خاڵەکان کە خۆکار بۆ گەیەنەر زیاد دەکرێت کاتێک داواکاری دەبێتە تەسلیمکراو. ٠ دابنێ بۆ وەستاندنی پاداشتی داهاتوو بەبێ سڕینەوەی مێژوو.' },
    perDelivery: { ar: 'نقطة لكل طلب مكتمل', en: 'Points per completed delivery', ku: 'خاڵ بۆ هەر داواکاری تەواوکراو' },
    save: { ar: 'حفظ الإعداد', en: 'Save setting', ku: 'پاشەکەوتکردنی ڕێکخستن' },
    manual: { ar: 'تعديل يدوي موثق', en: 'Audited manual adjustment', ku: 'دەستکاریکردنی دەستی بە تۆمار' },
    manualHint: { ar: 'إضافة أو خصم نقاط بمبرر واضح. لا يمكن تعديل أو حذف أي قيد بعد ترحيله.', en: 'Credit or debit points with a clear reason. Posted ledger entries cannot be edited or deleted.', ku: 'خاڵ زیاد بکە یان کەم بکە بە هۆکارێکی ڕوون. تۆمارەکان دوای تۆمارکردن ناگۆڕدرێن و ناسڕدرێن.' },
    selectCourier: { ar: 'اختر المندوب', en: 'Select courier', ku: 'گەیەنەر هەڵبژێرە' },
    chooseCourier: { ar: 'اختر حساباً نشطاً', en: 'Choose an active account', ku: 'هەژمارێکی چالاک هەڵبژێرە' },
    add: { ar: 'إضافة نقاط', en: 'Add points', ku: 'زیادکردنی خاڵ' },
    deduct: { ar: 'خصم نقاط', en: 'Deduct points', ku: 'کەمکردنەوەی خاڵ' },
    amount: { ar: 'عدد النقاط', en: 'Number of points', ku: 'ژمارەی خاڵەکان' },
    reason: { ar: 'سبب التعديل', en: 'Adjustment reason', ku: 'هۆکاری دەستکاریکردن' },
    reasonHint: { ar: 'مثال: مكافأة خدمة استثنائية أو تصحيح قيد', en: 'Example: exceptional service reward or ledger correction', ku: 'نموونە: پاداشتی خزمەتگوزاریی نایاب یان ڕاستکردنەوەی تۆمار' },
    balance: { ar: 'الرصيد الحالي', en: 'Current balance', ku: 'باڵانسی ئێستا' },
    submitAdd: { ar: 'ترحيل الإضافة', en: 'Post credit', ku: 'تۆمارکردنی زیادکردن' },
    submitDeduct: { ar: 'ترحيل الخصم', en: 'Post debit', ku: 'تۆمارکردنی کەمکردنەوە' },
    cancel: { ar: 'إلغاء', en: 'Cancel', ku: 'هەڵوەشاندنەوە' },
    couriers: { ar: 'أرصدة المندوبين', en: 'Courier balances', ku: 'باڵانسی گەیەنەران' },
    couriersHint: { ar: 'اضغط على أي بطاقة لإجراء تعديل موثق على رصيدها.', en: 'Choose any card to make an auditable adjustment.', ku: 'هەر کارتەک هەڵبژێرە بۆ دەستکاریکردنێکی تۆمارکراو.' },
    search: { ar: 'ابحث بالاسم أو الهاتف', en: 'Search name or phone', ku: 'بە ناو یان ژمارەی مۆبایل بگەڕێ' },
    noCouriers: { ar: 'لا توجد حسابات مندوبين نشطة مطابقة للبحث.', en: 'No active courier accounts match this search.', ku: 'هیچ هەژماری گەیەنەری چالاک لەم گەڕانە ناگونجێت.' },
    adjust: { ar: 'تعديل الرصيد', en: 'Adjust balance', ku: 'دەستکاریکردنی باڵانس' },
    history: { ar: 'آخر حركات نقاط الولاء', en: 'Latest loyalty movements', ku: 'دوایین جوڵەکانی خاڵی دڵسۆزی' },
    historyHint: { ar: 'هذا سجل تدقيقي للقراءة فقط؛ التصحيح يتم بقيد جديد معاكس.', en: 'This is a read-only audit trail; corrections are made with a new compensating entry.', ku: 'ئەمە تۆمارێکی تەنها بۆ خوێندنەوەیە؛ ڕاستکردنەوە بە تۆمارێکی نوێی پێچەوانە ئەنجام دەدرێت.' },
    date: { ar: 'التاريخ', en: 'Date', ku: 'بەروار' },
    courier: { ar: 'المندوب', en: 'Courier', ku: 'گەیەنەر' },
    movement: { ar: 'الحركة', en: 'Movement', ku: 'جوڵە' },
    source: { ar: 'المصدر', en: 'Source', ku: 'سەرچاوە' },
    note: { ar: 'الملاحظة', en: 'Note', ku: 'تێبینی' },
    after: { ar: 'الرصيد بعد الحركة', en: 'Balance after', ku: 'باڵانس دوای جوڵە' },
    noEntries: { ar: 'لا توجد حركات نقاط مسجلة بعد.', en: 'No loyalty movements have been recorded yet.', ku: 'هێشتا هیچ جوڵەی خاڵێک تۆمار نەکراوە.' },
    deliveryReward: { ar: 'مكافأة إتمام طلب', en: 'Delivery reward', ku: 'پاداشتی تەواوکردنی داواکاری' },
    adminCredit: { ar: 'إضافة إدارية', en: 'Administrative credit', ku: 'زیادکردنی بەڕێوەبردن' },
    adminDebit: { ar: 'خصم إداري', en: 'Administrative debit', ku: 'کەمکردنەوەی بەڕێوەبردن' },
    other: { ar: 'حركة نقاط', en: 'Points movement', ku: 'جوڵەی خاڵ' },
    roles: { ar: { courier: 'مندوب', pickup_courier: 'مندوب استلام', delivery_courier: 'مندوب توصيل', transporter: 'مندوب نقل' }, en: { courier: 'Courier', pickup_courier: 'Pickup courier', delivery_courier: 'Delivery courier', transporter: 'Transporter' }, ku: { courier: 'گەیەنەر', pickup_courier: 'گەیەنەری وەرگرتن', delivery_courier: 'گەیەنەری گەیاندن', transporter: 'گەیەنەری گواستنەوە' } },
    debitConfirm: { ar: 'سيتم خصم النقاط من الرصيد الحالي. هل تريد المتابعة؟', en: 'These points will be deducted from the current balance. Continue?', ku: 'ئەم خاڵانە لە باڵانسی ئێستا کەم دەکرێن. بەردەوام بیت؟' },
}

function l(key) {
    return labels[key]?.[locale.value] || labels[key]?.ar || key
}

function roleLabel(role) {
    return labels.roles?.[locale.value]?.[role] || labels.roles?.ar?.[role] || role
}

function formatted(value) {
    return new Intl.NumberFormat(locale.value === 'ku' ? 'ku' : locale.value === 'en' ? 'en-US' : 'ar-IQ').format(Number(value) || 0)
}

function dateLabel(value) {
    if (!value) return '—'
    try {
        return new Intl.DateTimeFormat(locale.value === 'en' ? 'en-GB' : locale.value === 'ku' ? 'ku' : 'ar-IQ', {
            dateStyle: 'medium', timeStyle: 'short',
        }).format(new Date(value))
    } catch {
        return value
    }
}

function entryLabel(type) {
    if (type === 'delivery_reward') return l('deliveryReward')
    if (type === 'admin_credit') return l('adminCredit')
    if (type === 'admin_debit') return l('adminDebit')
    return l('other')
}

function sourceLabel(entry) {
    if (entry.source_type === 'order_delivery' && entry.source_id) return `#${entry.source_id}`
    if (!entry.source_type) return '—'
    return entry.source_id ? `${entry.source_type} #${entry.source_id}` : entry.source_type
}

const filteredCouriers = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase()
    if (!needle) return props.couriers

    return props.couriers.filter((courier) => `${courier.name || ''} ${courier.phone || ''} ${courier.role || ''}`.toLocaleLowerCase().includes(needle))
})

const selectedCourier = computed(() => props.couriers.find((courier) => Number(courier.id) === Number(selectedCourierId.value)) || null)

const settingForm = useForm({
    points_per_delivery: Number(props.settings?.points_per_delivery || 0),
})

const adjustmentForm = useForm({
    courier_id: '',
    operation: 'credit',
    points: '',
    reason: '',
})

watch(() => props.settings?.points_per_delivery, (value) => {
    if (!settingForm.isDirty) settingForm.points_per_delivery = Number(value || 0)
})

function refresh() {
    router.get(route('admin.loyalty'), {}, { preserveScroll: true, replace: true })
}

function saveSettings() {
    settingForm.post(route('admin.loyalty.settings'), { preserveScroll: true })
}

function openAdjustment(courier) {
    selectedCourierId.value = courier?.id || selectedCourierId.value
    adjustmentForm.clearErrors()
    adjustmentForm.courier_id = selectedCourierId.value
    adjustmentForm.operation = 'credit'
    adjustmentForm.points = ''
    adjustmentForm.reason = ''
    adjustmentOpen.value = true
}

function closeAdjustment() {
    adjustmentOpen.value = false
    adjustmentForm.clearErrors()
}

function submitAdjustment() {
    const courier = selectedCourier.value
    const points = Number.parseInt(adjustmentForm.points, 10)
    if (!courier || !points || points < 1 || adjustmentForm.processing) return
    if (adjustmentForm.operation === 'debit' && !window.confirm(l('debitConfirm'))) return

    adjustmentForm.courier_id = courier.id
    adjustmentForm.points = points
    adjustmentForm.post(route('admin.loyalty.adjust'), {
        preserveScroll: true,
        onSuccess: closeAdjustment,
    })
}
</script>

<template>
    <AdminShell :title="l('title')">
        <header class="loyalty-heading">
            <div>
                <p>{{ l('eyebrow') }}</p>
                <h2>{{ l('title') }}</h2>
                <span>{{ l('subtitle') }}</span>
            </div>
            <button class="secondary refresh-button" type="button" @click="refresh">↻ {{ l('refresh') }}</button>
        </header>

        <section class="kpi-grid loyalty-kpis" aria-label="Loyalty summary">
            <article class="kpi"><span class="ki active-icon">◉</span><span><strong class="kval mono">{{ formatted(summary.active_couriers) }}</strong><b class="klab">{{ l('activeCouriers') }}</b></span></article>
            <article class="kpi"><span class="ki points-icon">✦</span><span><strong class="kval mono">{{ formatted(summary.points_in_circulation) }}</strong><b class="klab">{{ l('circulation') }}</b></span></article>
            <article class="kpi"><span class="ki balance-icon">◎</span><span><strong class="kval mono">{{ formatted(summary.couriers_with_points) }}</strong><b class="klab">{{ l('rewarded') }}</b></span></article>
            <article class="kpi"><span class="ki ledger-icon">▤</span><span><strong class="kval mono">{{ formatted(summary.ledger_entries) }}</strong><b class="klab">{{ l('ledger') }}</b></span></article>
        </section>

        <section class="loyalty-controls">
            <form class="panel reward-panel" @submit.prevent="saveSettings">
                <header class="panel-head"><span class="panel-icon">✦</span><span><h3>{{ l('settings') }}</h3><p>{{ l('settingsHint') }}</p></span></header>
                <div class="reward-body">
                    <label class="reward-field">
                        <span>{{ l('perDelivery') }}</span>
                        <input v-model.number="settingForm.points_per_delivery" type="number" min="0" max="1000000" step="1" inputmode="numeric" required />
                        <small v-if="settingForm.errors.points_per_delivery" class="field-error">{{ settingForm.errors.points_per_delivery }}</small>
                    </label>
                    <button class="primary save-reward" type="submit" :disabled="settingForm.processing"><span v-if="settingForm.processing" class="loader"></span><span v-else>{{ l('save') }}</span></button>
                </div>
            </form>

            <section class="panel manual-panel">
                <header class="panel-head"><span class="panel-icon manual-icon">±</span><span><h3>{{ l('manual') }}</h3><p>{{ l('manualHint') }}</p></span></header>
                <div class="manual-body">
                    <label><span>{{ l('selectCourier') }}</span><select v-model="selectedCourierId" @change="adjustmentForm.courier_id = selectedCourierId"><option value="">{{ l('chooseCourier') }}</option><option v-for="courier in couriers" :key="courier.id" :value="courier.id">{{ courier.name }} · {{ courier.phone || roleLabel(courier.role) }}</option></select></label>
                    <div class="selected-balance"><span>{{ l('balance') }}</span><b class="mono">{{ formatted(selectedCourier?.points_balance || 0) }}</b></div>
                    <button class="secondary manual-open" type="button" :disabled="!selectedCourier" @click="openAdjustment(selectedCourier)">{{ l('adjust') }}</button>
                </div>
            </section>
        </section>

        <section class="panel courier-panel">
            <header class="panel-head courier-head"><span><h3>{{ l('couriers') }}</h3><p>{{ l('couriersHint') }}</p></span><label class="search"><span>⌕</span><input v-model="search" type="search" :placeholder="l('search')" /></label></header>
            <div v-if="filteredCouriers.length" class="courier-grid">
                <button v-for="courier in filteredCouriers" :key="courier.id" type="button" class="courier-card" :class="{ selected: Number(selectedCourierId) === Number(courier.id) }" @click="openAdjustment(courier)">
                    <span class="courier-avatar">{{ courier.name?.trim()?.charAt(0) || 'م' }}</span>
                    <span class="courier-main"><b>{{ courier.name }}</b><small>{{ roleLabel(courier.role) }} · {{ courier.phone || '—' }}</small></span>
                    <span class="courier-points"><small>{{ l('balance') }}</small><strong class="mono">{{ formatted(courier.points_balance) }}</strong></span>
                    <span class="courier-arrow">‹</span>
                </button>
            </div>
            <div v-else class="empty">{{ l('noCouriers') }}</div>
        </section>

        <section class="panel ledger-panel">
            <header class="panel-head"><span><h3>{{ l('history') }}</h3><p>{{ l('historyHint') }}</p></span></header>
            <div v-if="entries.length" class="table-scroll">
                <table class="tbl loyalty-table">
                    <thead><tr><th>{{ l('date') }}</th><th>{{ l('courier') }}</th><th>{{ l('movement') }}</th><th>{{ l('source') }}</th><th>{{ l('note') }}</th><th>{{ l('after') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="entry in entries" :key="entry.id">
                            <td class="date-cell">{{ dateLabel(entry.created_at) }}</td>
                            <td><b>{{ entry.courier?.name || '—' }}</b><small>{{ entry.courier?.phone || roleLabel(entry.courier?.role || '') }}</small></td>
                            <td><span class="movement" :class="entry.points > 0 ? 'credit' : 'debit'"><b class="mono">{{ entry.points > 0 ? '+' : '−' }}{{ formatted(Math.abs(entry.points)) }}</b><small>{{ entryLabel(entry.type) }}</small></span></td>
                            <td class="mono source-cell">{{ sourceLabel(entry) }}</td>
                            <td class="note-cell">{{ entry.note || '—' }}</td>
                            <td><b class="mono after-balance">{{ formatted(entry.balance_after) }}</b></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="empty">{{ l('noEntries') }}</div>
        </section>

        <div v-if="adjustmentOpen && selectedCourier" class="dialog-backdrop" @click.self="closeAdjustment">
            <form class="adjust-dialog" @submit.prevent="submitAdjustment">
                <header><span><small>{{ l('manual') }}</small><h3>{{ selectedCourier.name }}</h3><p>{{ roleLabel(selectedCourier.role) }} · {{ selectedCourier.phone || '—' }}</p></span><button type="button" :aria-label="l('cancel')" @click="closeAdjustment">×</button></header>
                <div class="dialog-balance"><span>{{ l('balance') }}</span><strong class="mono">{{ formatted(selectedCourier.points_balance) }}</strong></div>
                <div class="dialog-body">
                    <div class="operation-switch" role="group" :aria-label="l('manual')"><button type="button" :class="{ active: adjustmentForm.operation === 'credit' }" @click="adjustmentForm.operation = 'credit'">＋ {{ l('add') }}</button><button type="button" :class="{ active: adjustmentForm.operation === 'debit' }" @click="adjustmentForm.operation = 'debit'">− {{ l('deduct') }}</button></div>
                    <label><span>{{ l('amount') }}</span><input v-model.number="adjustmentForm.points" type="number" min="1" max="1000000" step="1" inputmode="numeric" required autofocus /><small v-if="adjustmentForm.errors.points" class="field-error">{{ adjustmentForm.errors.points }}</small></label>
                    <label><span>{{ l('reason') }}</span><textarea v-model.trim="adjustmentForm.reason" rows="3" maxlength="500" required :placeholder="l('reasonHint')"></textarea><small v-if="adjustmentForm.errors.reason" class="field-error">{{ adjustmentForm.errors.reason }}</small></label>
                </div>
                <footer><button class="secondary" type="button" @click="closeAdjustment">{{ l('cancel') }}</button><button class="primary" :class="{ debit: adjustmentForm.operation === 'debit' }" type="submit" :disabled="adjustmentForm.processing"><span v-if="adjustmentForm.processing" class="loader"></span><span v-else>{{ adjustmentForm.operation === 'credit' ? l('submitAdd') : l('submitDeduct') }}</span></button></footer>
            </form>
        </div>
    </AdminShell>
</template>

<style scoped>
.loyalty-heading{display:flex;align-items:end;justify-content:space-between;gap:18px;margin-bottom:18px}.loyalty-heading p{margin:0 0 4px;color:var(--primary);font-size:10px;font-weight:900;letter-spacing:.09em;text-transform:uppercase}.loyalty-heading h2{margin:0;color:var(--ink);font-size:24px;font-weight:900}.loyalty-heading span{display:block;max-width:760px;margin-top:5px;color:var(--ink-faint);font-size:11px;font-weight:650;line-height:1.75}.primary,.secondary{min-height:38px;padding:8px 13px;border:0;border-radius:10px;font:900 10.5px var(--font);cursor:pointer;transition:transform .18s ease,opacity .18s ease}.primary{color:#062033;background:linear-gradient(135deg,var(--primary),#0ea5e9)}.primary.debit{color:#fff;background:linear-gradient(135deg,#e05a54,#bd3039)}.secondary{border:1px solid var(--border);color:var(--ink-soft);background:var(--surface)}button:disabled{cursor:not-allowed;opacity:.55}.refresh-button{white-space:nowrap}.loyalty-kpis{grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:17px}.kpi{display:flex;align-items:center;gap:11px;min-height:86px;padding:15px;border:1px solid var(--border);border-radius:14px;background:var(--surface)}.ki{display:grid;place-items:center;width:36px;height:36px;border-radius:11px;background:var(--surface-2);font-size:17px;font-weight:900}.active-icon{color:var(--success);background:var(--success-tint)}.points-icon{color:var(--accent);background:var(--accent-tint)}.balance-icon{color:var(--primary-strong);background:var(--primary-tint)}.ledger-icon{color:var(--ink-soft)}.kpi>span:last-child{display:grid;gap:2px}.kval{color:var(--ink);font-size:17px}.klab{color:var(--ink-faint);font-size:9px;font-weight:850}.loyalty-controls{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr);gap:16px;margin-bottom:16px}.panel-head{align-items:flex-start}.panel-head h3{margin:0;color:var(--ink);font-size:13px}.panel-head p{margin:4px 0 0;color:var(--ink-faint);font-size:9.5px;font-weight:650;line-height:1.65}.panel-icon{display:grid;place-items:center;flex:none;width:32px;height:32px;border-radius:10px;color:var(--accent);background:var(--accent-tint);font-size:16px;font-weight:900}.manual-icon{color:var(--primary-strong);background:var(--primary-tint)}.reward-panel,.manual-panel{overflow:hidden}.reward-body{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:end;gap:10px;padding:0 15px 15px}.reward-field,.manual-body label,.dialog-body label{display:grid;gap:5px;color:var(--ink-soft);font-size:10px;font-weight:850}.reward-field input,.manual-body select,.dialog-body input,.dialog-body textarea,.search input{width:100%;min-height:39px;padding:8px 10px;border:1px solid var(--border);border-radius:9px;outline:0;color:var(--ink);background:var(--surface-2);font:700 11px var(--font)}.reward-field input:focus,.manual-body select:focus,.dialog-body input:focus,.dialog-body textarea:focus,.search input:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-tint)}.field-error{color:var(--danger);font-size:9px;font-weight:750}.save-reward{min-width:130px}.manual-body{display:grid;grid-template-columns:minmax(0,1fr) auto auto;align-items:end;gap:10px;padding:0 15px 15px}.selected-balance{display:grid;gap:3px;min-width:91px;padding:8px 10px;border-radius:10px;background:var(--surface-2)}.selected-balance span{color:var(--ink-faint);font-size:8.5px;font-weight:800}.selected-balance b{color:var(--primary-strong);font-size:15px}.manual-open{white-space:nowrap}.courier-panel,.ledger-panel{margin-bottom:16px;overflow:hidden}.courier-head{justify-content:space-between;gap:15px}.search{display:flex;align-items:center;gap:5px;min-width:210px;padding-inline:9px;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);color:var(--ink-faint)}.search input{min-height:34px;padding:0;border:0;box-shadow:none!important;background:transparent}.courier-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px;padding:0 15px 15px}.courier-card{display:grid;grid-template-columns:auto minmax(0,1fr) auto auto;align-items:center;gap:9px;padding:12px;border:1px solid var(--border);border-radius:13px;color:var(--ink);background:var(--surface-2);text-align:start;cursor:pointer;transition:border-color .18s ease,transform .18s ease,background .18s ease}.courier-card:hover,.courier-card.selected{border-color:var(--primary);background:var(--primary-tint);transform:translateY(-1px)}.courier-avatar{display:grid;place-items:center;width:34px;height:34px;border-radius:10px;color:#062033;background:linear-gradient(135deg,var(--primary),#0ea5e9);font-size:13px;font-weight:900}.courier-main{display:grid;min-width:0;gap:3px}.courier-main b{overflow:hidden;font-size:11px;text-overflow:ellipsis;white-space:nowrap}.courier-main small{overflow:hidden;color:var(--ink-faint);font-size:8.5px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}.courier-points{display:grid;gap:2px;text-align:end}.courier-points small{color:var(--ink-faint);font-size:8px;font-weight:800}.courier-points strong{color:var(--primary-strong);font-size:14px}.courier-arrow{color:var(--primary-strong);font-size:21px;line-height:1}.table-scroll{overflow:auto}.loyalty-table{min-width:850px}.loyalty-table small{display:block;margin-top:2px;color:var(--ink-faint);font-size:8.5px;font-weight:700}.date-cell{min-width:130px;color:var(--ink-faint);font-size:9px;font-weight:700}.movement{display:grid;gap:2px}.movement b{font-size:11px}.movement.credit b{color:var(--success)}.movement.debit b{color:var(--danger)}.source-cell{color:var(--ink-faint);font-size:9px}.note-cell{max-width:250px;color:var(--ink-soft);font-size:10px;white-space:normal}.after-balance{color:var(--ink);font-size:11px}.empty{padding:30px 16px;color:var(--ink-faint);font-size:11px;font-weight:750;text-align:center}.dialog-backdrop{position:fixed;z-index:90;inset:0;display:grid;place-items:center;padding:18px;background:rgba(8,18,17,.58);backdrop-filter:blur(4px)}.adjust-dialog{width:min(100%,470px);overflow:hidden;border:1px solid var(--border);border-radius:18px;background:var(--surface);box-shadow:0 28px 70px rgba(0,0,0,.3)}.adjust-dialog header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:16px;border-bottom:1px solid var(--border)}.adjust-dialog header small{color:var(--primary);font-size:9px;font-weight:900;letter-spacing:.06em;text-transform:uppercase}.adjust-dialog h3{margin:3px 0 0;color:var(--ink);font-size:15px}.adjust-dialog header p{margin:3px 0 0;color:var(--ink-faint);font-size:9.5px;font-weight:700}.adjust-dialog header button{display:grid;place-items:center;width:28px;height:28px;border:0;border-radius:8px;color:var(--ink-soft);background:var(--surface-2);font-size:20px;cursor:pointer}.dialog-balance{display:flex;align-items:center;justify-content:space-between;margin:14px 16px 0;padding:10px 12px;border-radius:11px;background:var(--surface-2)}.dialog-balance span{color:var(--ink-faint);font-size:9px;font-weight:800}.dialog-balance strong{color:var(--primary-strong);font-size:18px}.dialog-body{display:grid;gap:12px;padding:16px}.dialog-body textarea{min-height:72px;resize:vertical}.operation-switch{display:grid;grid-template-columns:1fr 1fr;gap:7px;padding:4px;border-radius:10px;background:var(--surface-2)}.operation-switch button{min-height:34px;border:0;border-radius:7px;color:var(--ink-faint);background:transparent;font:850 10px var(--font);cursor:pointer}.operation-switch button.active{color:var(--primary-strong);background:var(--surface);box-shadow:0 2px 8px rgba(0,0,0,.08)}.adjust-dialog footer{display:flex;justify-content:flex-end;gap:8px;padding:14px 16px;border-top:1px solid var(--border)}.loader{display:inline-block;width:13px;height:13px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;vertical-align:-2px;animation:loyalty-spin .7s linear infinite}@keyframes loyalty-spin{to{transform:rotate(360deg)}}@media(max-width:930px){.loyalty-controls{grid-template-columns:1fr}.loyalty-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:620px){.loyalty-heading{align-items:start;flex-direction:column}.refresh-button{width:100%}.loyalty-kpis{gap:8px}.kpi{min-height:76px;padding:12px}.ki{width:31px;height:31px;font-size:14px}.kval{font-size:14px}.reward-body{grid-template-columns:1fr}.save-reward{width:100%}.manual-body{grid-template-columns:1fr}.selected-balance{width:100%}.manual-open{width:100%}.courier-head{align-items:stretch;flex-direction:column}.search{min-width:0}.courier-grid{grid-template-columns:1fr;padding-inline:11px}.dialog-backdrop{align-items:end;padding:0}.adjust-dialog{width:100%;border-radius:19px 19px 0 0}.adjust-dialog footer{padding-bottom:max(14px,env(safe-area-inset-bottom))}}
</style>
