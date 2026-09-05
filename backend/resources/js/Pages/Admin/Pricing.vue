<script setup>
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'
import BranchFilter from '../../Components/BranchFilter.vue'

const props = defineProps({
    rules: { type: Array, default: () => [] },
    merchants: { type: Array, default: () => [] },
    provinces: { type: Array, default: () => [] },
    vehicles: { type: Array, default: () => [] },
    canManagePricing: { type: Boolean, default: false },
    canCreatePricing: { type: Boolean, default: false },
    canUpdatePricing: { type: Boolean, default: false },
    canEditPricing: { type: Boolean, default: false },
    canChangePricingStatus: { type: Boolean, default: false },
    branchFilter: { type: Object, default: () => ({}) },
})

const page = usePage()
const locale = computed(() => page.props.locale || 'ar')
const editor = ref(null)
const busy = ref(false)
const form = ref(blank())

const labels = {
    title: { ar: 'الباقات والتسعير', en: 'Pricing engine', ku: 'سیستەمی نرخدانان' },
    eyebrow: { ar: 'تشغيل المنصة', en: 'Platform operations', ku: 'بەڕێوەبردنی پلاتفۆرم' },
    subtitle: { ar: 'قواعد فعلية لحساب أجرة التوصيل والإرجاع حسب المحافظة والخدمة والمركبة والوزن.', en: 'Live delivery and return-fee rules by province, service, vehicle, and weight.', ku: 'یاساکانی کرێی گەیاندن و گەڕاندن بەپێی پارێزگا، خزمەتگوزاری، ئۆتۆمبێل و کێش.' },
    create: { ar: 'قاعدة تسعير جديدة', en: 'New pricing rule', ku: 'یاسای نرخدانانی نوێ' },
    edit: { ar: 'تعديل القاعدة', en: 'Edit rule', ku: 'دەستکاریکردنی یاسا' },
    nameAr: { ar: 'اسم القاعدة بالعربية', en: 'Arabic rule name', ku: 'ناوی یاسا بە عەرەبی' },
    nameEn: { ar: 'اسم القاعدة بالإنجليزية', en: 'English rule name', ku: 'ناوی یاسا بە ئینگلیزی' },
    nameKu: { ar: 'اسم القاعدة بالكردية', en: 'Kurdish rule name', ku: 'ناوی یاسا بە کوردی' },
    merchant: { ar: 'تاجر محدد', en: 'Specific merchant', ku: 'بازرگانی دیاریکراو' },
    allMerchants: { ar: 'كل التجار', en: 'All merchants', ku: 'هەموو بازرگانان' },
    origin: { ar: 'محافظة المصدر', en: 'Origin province', ku: 'پارێزگای سەرچاوە' },
    destination: { ar: 'محافظة الوجهة', en: 'Destination province', ku: 'پارێزگای مەبەست' },
    allProvinces: { ar: 'كل المحافظات', en: 'All provinces', ku: 'هەموو پارێزگاکان' },
    service: { ar: 'نوع الخدمة', en: 'Service type', ku: 'جۆری خزمەتگوزاری' },
    allServices: { ar: 'كل الخدمات', en: 'All services', ku: 'هەموو خزمەتگوزارییەکان' },
    vehicle: { ar: 'المركبة', en: 'Vehicle', ku: 'ئۆتۆمبێل' },
    allVehicles: { ar: 'كل المركبات', en: 'All vehicles', ku: 'هەموو ئۆتۆمبێلەکان' },
    minWeight: { ar: 'الوزن الأدنى (غرام)', en: 'Minimum weight (g)', ku: 'کێشی کەمترین (گرام)' },
    maxWeight: { ar: 'الوزن الأعلى (غرام)', en: 'Maximum weight (g)', ku: 'کێشی زۆرترین (گرام)' },
    fee: { ar: 'أجرة التوصيل', en: 'Delivery fee', ku: 'کرێی گەیاندن' },
    returnFee: { ar: 'أجرة الإرجاع', en: 'Return fee', ku: 'کرێی گەڕاندن' },
    priority: { ar: 'الأولوية', en: 'Priority', ku: 'پێشینە' },
    save: { ar: 'حفظ القاعدة', en: 'Save rule', ku: 'پاشەکەوتکردنی یاسا' },
    cancel: { ar: 'إلغاء', en: 'Cancel', ku: 'هەڵوەشاندنەوە' },
    active: { ar: 'فعالة', en: 'Active', ku: 'چالاک' },
    inactive: { ar: 'موقوفة', en: 'Inactive', ku: 'ناچالاک' },
    scope: { ar: 'نطاق التطبيق', en: 'Rule scope', ku: 'مەودای یاسا' },
    noRules: { ar: 'لا توجد قواعد تسعير بعد.', en: 'No pricing rules yet.', ku: 'هێشتا هیچ یاسای نرخدانانێک نییە.' },
    automatic: { ar: 'يُطبق تلقائياً على الطلبات الجديدة.', en: 'Applied automatically to new orders.', ku: 'بە خۆکار لەسەر داواکارییە نوێکان جێبەجێدەکرێت.' },
    vehicleNames: { ar: { normal: 'عادي', bike: 'دراجة', sedan: 'سيدان', suv: 'دفع رباعي', truck: 'شاحنة' }, en: { normal: 'Normal', bike: 'Bike', sedan: 'Sedan', suv: 'SUV', truck: 'Truck' }, ku: { normal: 'ئاسایی', bike: 'بایسکل', sedan: 'سیدان', suv: 'SUV', truck: 'باربەر' } },
}

function l(key) { return labels[key]?.[locale.value] || labels[key]?.ar || key }
function localized(row) { return row?.[`name_${locale.value}`] || row?.name_ar || row?.name_en || '—' }
function provinceName(province) { return province?.[`name_${locale.value}`] || province?.name_ar || province?.name_en || '—' }
function blank() { return { name_ar: '', name_en: '', name_ku: '', merchant_id: '', origin_province_id: '', destination_province_id: '', service: '', vehicle: '', min_weight_grams: 0, max_weight_grams: '', base_fee: '', return_fee: 0, priority: 100 } }

function openCreate() {
    if (!props.canCreatePricing) return
    editor.value = 'create'
    form.value = blank()
}
function openEdit(rule) {
    if (!props.canEditPricing) return
    editor.value = rule.id
    form.value = {
        name_ar: rule.name_ar || '', name_en: rule.name_en || '', name_ku: rule.name_ku || '', merchant_id: rule.merchant_id || '',
        origin_province_id: rule.origin_province_id || '', destination_province_id: rule.destination_province_id || '', service: rule.service || '', vehicle: rule.vehicle || '',
        min_weight_grams: rule.min_weight_grams || 0, max_weight_grams: rule.max_weight_grams ?? '', base_fee: rule.base_fee, return_fee: rule.return_fee || 0, priority: rule.priority || 100,
    }
}
function submit() {
    if (editor.value === 'create' ? !props.canCreatePricing : !props.canEditPricing) return
    if (busy.value || !form.value.name_ar || form.value.base_fee === '') return
    busy.value = true
    const target = editor.value === 'create' ? route('admin.pricing.store') : route('admin.pricing.update', editor.value)
    const callback = { preserveScroll: true, onSuccess: () => { editor.value = null }, onFinish: () => { busy.value = false } }
    const payload = { ...form.value, merchant_id: form.value.merchant_id || null, origin_province_id: form.value.origin_province_id || null, destination_province_id: form.value.destination_province_id || null, service: form.value.service || null, vehicle: form.value.vehicle || null, max_weight_grams: form.value.max_weight_grams === '' ? null : Number(form.value.max_weight_grams), min_weight_grams: Number(form.value.min_weight_grams || 0), base_fee: Number(form.value.base_fee), return_fee: Number(form.value.return_fee || 0), priority: Number(form.value.priority || 100) }
    if (editor.value === 'create') router.post(target, payload, callback)
    else router.put(target, payload, callback)
}
function toggle(rule) {
    if (!props.canChangePricingStatus) return
    router.patch(route('admin.pricing.status', rule.id), { is_active: !rule.is_active }, { preserveScroll: true })
}
function scope(rule) {
    const bits = []
    if (rule.merchant) bits.push(rule.merchant.shop_name || rule.merchant.name)
    if (rule.destination_province) bits.push(provinceName(rule.destination_province))
    if (rule.service) bits.push(rule.service)
    if (rule.vehicle) bits.push(labels.vehicleNames[locale.value]?.[rule.vehicle] || rule.vehicle)
    return bits.length ? bits.join(' · ') : l('allMerchants')
}
function changeBranchFilter(branchId) {
    const query = Object.fromEntries(new URLSearchParams(window.location.search).entries())
    if (branchId) query.branch_id = String(branchId)
    else delete query.branch_id

    router.get(route('admin.pricing'), query, { preserveScroll: true, preserveState: false, replace: true })
}
</script>

<template>
    <AdminShell :title="l('title')">
        <header class="page-heading">
            <div><p>{{ l('eyebrow') }}</p><h2>{{ l('title') }}</h2><span>{{ l('subtitle') }}</span></div>
            <BranchFilter v-if="branchFilter?.enabled" :filter="branchFilter" @change="changeBranchFilter" />
            <button v-if="canCreatePricing" class="primary" type="button" @click="openCreate">＋ {{ l('create') }}</button>
        </header>

        <section class="rule-grid">
            <article v-for="rule in rules" :key="rule.id" class="rule-card" :class="{ off: !rule.is_active }">
                <header><span class="rule-badge">{{ rule.priority }}</span><div><h3>{{ localized(rule) }}</h3><p>{{ scope(rule) }}</p></div><button v-if="canChangePricingStatus" class="toggle" type="button" :class="{ on: rule.is_active }" @click="toggle(rule)"><i></i><span>{{ rule.is_active ? l('active') : l('inactive') }}</span></button></header>
                <div class="rule-prices"><span><small>{{ l('fee') }}</small><b>{{ fmt(rule.base_fee) }} {{ t('IQD') }}</b></span><span><small>{{ l('returnFee') }}</small><b>{{ fmt(rule.return_fee) }} {{ t('IQD') }}</b></span></div>
                <footer><span>{{ rule.min_weight_grams }}–{{ rule.max_weight_grams ?? '∞' }} g</span><button v-if="canEditPricing" type="button" @click="openEdit(rule)">{{ l('edit') }}</button></footer>
            </article>
            <div v-if="!rules.length" class="empty">{{ l('noRules') }}</div>
        </section>

        <div v-if="editor !== null && (editor === 'create' ? canCreatePricing : canEditPricing)" class="dialog-backdrop" @click.self="editor = null">
            <form class="dialog pricing-dialog" @submit.prevent="submit">
                <header><div><h3>{{ editor === 'create' ? l('create') : l('edit') }}</h3><p>{{ l('automatic') }}</p></div><button type="button" @click="editor = null">×</button></header>
                <div class="dialog-body form-grid">
                    <label><span>{{ l('nameAr') }}</span><input v-model.trim="form.name_ar" required></label>
                    <label><span>{{ l('nameEn') }}</span><input v-model.trim="form.name_en"></label>
                    <label class="wide"><span>{{ l('nameKu') }}</span><input v-model.trim="form.name_ku"></label>
                    <label><span>{{ l('merchant') }}</span><PopupSelect v-model="form.merchant_id"><option value="">{{ l('allMerchants') }}</option><option v-for="merchant in merchants" :key="merchant.id" :value="merchant.id">{{ merchant.shop_name || merchant.name }}</option></PopupSelect></label>
                    <label><span>{{ l('origin') }}</span><PopupSelect v-model="form.origin_province_id"><option value="">{{ l('allProvinces') }}</option><option v-for="province in provinces" :key="province.id" :value="province.id">{{ provinceName(province) }}</option></PopupSelect></label>
                    <label><span>{{ l('destination') }}</span><PopupSelect v-model="form.destination_province_id"><option value="">{{ l('allProvinces') }}</option><option v-for="province in provinces" :key="province.id" :value="province.id">{{ provinceName(province) }}</option></PopupSelect></label>
                    <label><span>{{ l('service') }}</span><input v-model.trim="form.service" :placeholder="l('allServices')"></label>
                    <label><span>{{ l('vehicle') }}</span><PopupSelect v-model="form.vehicle"><option value="">{{ l('allVehicles') }}</option><option v-for="vehicle in vehicles" :key="vehicle" :value="vehicle">{{ labels.vehicleNames[locale]?.[vehicle] || vehicle }}</option></PopupSelect></label>
                    <label><span>{{ l('minWeight') }}</span><input v-model.number="form.min_weight_grams" type="number" min="0" required></label>
                    <label><span>{{ l('maxWeight') }}</span><input v-model="form.max_weight_grams" type="number" min="0" placeholder="∞"></label>
                    <label><span>{{ l('fee') }} ({{ t('IQD') }})</span><input v-model.number="form.base_fee" type="number" min="0" required></label>
                    <label><span>{{ l('returnFee') }} ({{ t('IQD') }})</span><input v-model.number="form.return_fee" type="number" min="0" required></label>
                    <label><span>{{ l('priority') }}</span><input v-model.number="form.priority" type="number" min="1" max="9999" required></label>
                </div>
                <footer><button type="button" class="secondary" @click="editor = null">{{ l('cancel') }}</button><button class="primary" :disabled="busy" type="submit">{{ l('save') }}</button></footer>
            </form>
        </div>
    </AdminShell>
</template>

<style scoped>
.page-heading{display:flex;align-items:end;justify-content:space-between;gap:18px;margin-bottom:20px}.page-heading p{margin:0 0 4px;color:var(--primary);font-size:10px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.page-heading h2{margin:0;color:var(--ink);font-size:24px;font-weight:900}.page-heading span{display:block;max-width:680px;margin-top:5px;color:var(--ink-faint);font-size:11.5px;font-weight:650;line-height:1.7}.primary,.secondary{min-height:38px;padding:8px 13px;border:0;border-radius:10px;font:900 11px var(--font);cursor:pointer}.primary{color:#062033;background:linear-gradient(135deg,var(--primary),#0ea5e9)}.secondary{color:var(--ink-soft);background:var(--surface-2)}.rule-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px}.rule-card{overflow:hidden;border:1px solid var(--border);border-radius:16px;background:var(--surface);box-shadow:0 10px 25px rgba(0,0,0,.05)}.rule-card.off{opacity:.62}.rule-card header{display:flex;align-items:start;gap:9px;padding:14px}.rule-badge{display:grid;place-items:center;min-width:28px;height:28px;border-radius:9px;color:#062033;background:var(--primary);font-size:11px;font-weight:900}.rule-card header>div{min-width:0;flex:1}.rule-card h3{overflow:hidden;margin:0;color:var(--ink);font-size:12.5px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.rule-card p{overflow:hidden;margin:3px 0 0;color:var(--ink-faint);font-size:9.5px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}.toggle{display:flex;align-items:center;gap:5px;border:0;color:var(--ink-faint);background:transparent;font:800 9px var(--font);cursor:pointer}.toggle i{width:17px;height:10px;position:relative;border-radius:99px;background:var(--danger-tint)}.toggle i:after{position:absolute;top:2px;inset-inline-start:2px;width:6px;height:6px;border-radius:50%;background:var(--danger);content:''}.toggle.on i{background:var(--success-tint)}.toggle.on i:after{inset-inline-start:9px;background:var(--success)}.rule-prices{display:grid;grid-template-columns:1fr 1fr;margin:0 14px;padding:11px;border-radius:11px;background:var(--surface-2)}.rule-prices span{display:grid;gap:3px}.rule-prices span+span{padding-inline-start:11px;border-inline-start:1px solid var(--border)}.rule-prices small{color:var(--ink-faint);font-size:9px;font-weight:750}.rule-prices b{color:var(--ink);font-size:12px}.rule-card footer{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;color:var(--ink-faint);font-size:9px;font-weight:800}.rule-card footer button{border:0;color:var(--primary-strong);background:transparent;font:850 10px var(--font);cursor:pointer}.empty{grid-column:1/-1;padding:35px;border:1px dashed var(--border);border-radius:14px;color:var(--ink-faint);font-size:11px;font-weight:750;text-align:center}.dialog-backdrop{position:fixed;z-index:90;inset:0;display:grid;place-items:center;padding:18px;background:rgba(8,18,17,.58);backdrop-filter:blur(4px)}.dialog{width:min(100%,720px);overflow:hidden;border:1px solid var(--border);border-radius:18px;background:var(--surface);box-shadow:0 28px 70px rgba(0,0,0,.3)}.dialog header,.dialog footer{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 18px;border-bottom:1px solid var(--border)}.dialog footer{justify-content:flex-end;border-top:1px solid var(--border);border-bottom:0}.dialog h3{margin:0;color:var(--ink);font-size:14px}.dialog p{margin:3px 0 0;color:var(--ink-faint);font-size:10px;font-weight:650}.dialog header>button{width:28px;height:28px;border:0;border-radius:8px;color:var(--ink-soft);background:var(--surface-2);font-size:20px;cursor:pointer}.dialog-body{padding:17px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.form-grid label{display:grid;gap:5px;color:var(--ink-soft);font-size:10px;font-weight:850}.form-grid .wide{grid-column:1/-1}.form-grid input,.form-grid select{width:100%;min-height:39px;padding:8px 9px;border:1px solid var(--border);border-radius:9px;outline:0;color:var(--ink);background:var(--surface-2);font:700 11px var(--font)}.form-grid input:focus,.form-grid select:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-tint)}@media(max-width:620px){.page-heading{align-items:start;flex-direction:column}.page-heading .primary{width:100%}.dialog-backdrop{align-items:end;padding:0}.dialog{width:100%;max-height:94dvh;overflow:auto;border-radius:19px 19px 0 0}.form-grid{grid-template-columns:1fr}.form-grid .wide{grid-column:auto}}
</style>
