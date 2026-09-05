<script setup>
import { computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'
import BranchFilter from '../../Components/BranchFilter.vue'

const props = defineProps({
    cashboxes: { type: Array, default: () => [] },
    vouchers: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    canViewCashboxBalances: { type: Boolean, default: false },
    canViewCashboxLedger: { type: Boolean, default: false },
    branchFilter: { type: Object, default: () => ({}) },
})

const page = usePage()
const locale = computed(() => page.props.locale || 'ar')

// Cashboxes are deliberately not a second wallet or a generic accounting
// ledger. A branch cashbox represents delivery revenue physically handed over
// by couriers. Qi credits, courier budgets, and ordinary wallet activity stay
// on the Finance page and never appear in this operational view.
const labels = {
    title: { ar: 'صناديق تحصيل إيرادات التوصيل', en: 'Delivery Revenue Cashboxes', ku: 'سندووقەکانی داهاتی گەیاندن' },
    eyebrow: { ar: 'تحصيلات الفروع', en: 'Branch collections', ku: 'کۆکردنەوەی لقەکان' },
    subtitle: { ar: 'تُعرض هنا تحصيلات إيرادات التوصيل التي سلّمها المندوبون إلى الفروع فقط. شحن Qi والميزانية وحركات المحفظة لا تدخل ضمن الصناديق.', en: 'Only delivery-revenue collections handed over by couriers to branches appear here. Qi top-ups, budgets, and wallet activity are excluded.', ku: 'تەنها داهاتی گەیاندنی وەرگیراو لە لایەن پێگەیەنەران و رادەستی لق کراو لێرە پیشان دەدرێت. بارکردنی Qi و بودجە و جوڵەی جزدان لێرە نییە.' },
    total: { ar: 'إجمالي تحصيلات التوصيل', en: 'Total delivery collections', ku: 'کۆی کۆکردنەوەی گەیاندن' },
    branches: { ar: 'صناديق الفروع', en: 'Branch cashboxes', ku: 'سندووقەکانی لقەکان' },
    active: { ar: 'الصناديق النشطة', en: 'Active cashboxes', ku: 'سندووقە چالاکەکان' },
    entries: { ar: 'سندات التحصيل المعروضة', en: 'Displayed collection vouchers', ku: 'بەڵگەکانی کۆکردنەوەی پیشاندراو' },
    branch: { ar: 'الفرع', en: 'Branch', ku: 'لق' },
    vault: { ar: 'الخزنة المركزية', en: 'Central vault', ku: 'خەزنەی ناوەندی' },
    bank: { ar: 'صندوق البنك', en: 'Bank cashbox', ku: 'سندووقی بانک' },
    balance: { ar: 'رصيد تحصيلات التوصيل', en: 'Delivery collection balance', ku: 'باڵانسی کۆکردنەوەی گەیاندن' },
    scope: { ar: 'تحصيلات التوصيل فقط', en: 'Delivery collections only', ku: 'تەنها کۆکردنەوەی گەیاندن' },
    ledger: { ar: 'سجل تحصيلات التوصيل', en: 'Delivery collections ledger', ku: 'تۆماری کۆکردنەوەی گەیاندن' },
    ledgerSubtitle: { ar: 'السجل أدناه مخصّص لتسليمات المندوبين المعتمدة للفروع، ولا يعرض أي شحن أو ميزانية أو حركة محفظة عامة.', en: 'This ledger contains only approved courier handovers to branches; it excludes top-ups, budgets, and general wallet activity.', ku: 'ئەم تۆمارە تەنها دەستبەدەستکردنی پێگەیەنەران بۆ لقەکان پیشان دەدات؛ بارکردن، بودجە و جوڵەی گشتی جزدان لێی نیشان نادرێت.' },
    date: { ar: 'التاريخ', en: 'Date', ku: 'بەروار' },
    reference: { ar: 'المرجع', en: 'Reference', ku: 'سەرچاوە' },
    movement: { ar: 'الحركة', en: 'Movement', ku: 'جوڵە' },
    cashbox: { ar: 'صندوق التحصيل', en: 'Collection cashbox', ku: 'سندووقی کۆکردنەوە' },
    actor: { ar: 'المسؤول', en: 'Operator', ku: 'بەرپرسیار' },
    amount: { ar: 'مبلغ التحصيل', en: 'Collection amount', ku: 'بڕی کۆکردنەوە' },
    note: { ar: 'ملاحظة', en: 'Note', ku: 'تێبینی' },
    noCollections: { ar: 'لا توجد تحصيلات توصيل مسلّمة بعد.', en: 'No delivery collections have been handed over yet.', ku: 'هێشتا هیچ کۆکردنەوەی گەیاندنێک رادەست نەکراوە.' },
    currency: { ar: 'د.ع', en: 'IQD', ku: 'د.ع' },
    handover: { ar: 'تسليم تحصيلات المندوب', en: 'Courier collection handover', ku: 'رادەستکردنی کۆکردنەوەی پێگەیەنەر' },
    transferIn: { ar: 'تحويل تحصيلات إلى الصندوق', en: 'Collections transferred in', ku: 'گواستنەوەی کۆکردنەوە بۆ سندووق' },
    transferOut: { ar: 'تحويل تحصيلات من الصندوق', en: 'Collections transferred out', ku: 'گواستنەوەی کۆکردنەوە لە سندووق' },
}

function l(key) {
    return labels[key]?.[locale.value] || labels[key]?.ar || key
}

function boxName(box) {
    return box?.[`name_${locale.value}`] || box?.name_ar || box?.name_en || '—'
}

function kindLabel(kind) {
    return kind === 'branch' ? l('branch') : kind === 'bank' ? l('bank') : l('vault')
}

function movementLabel(type) {
    return type === 'transfer_in' ? l('transferIn') : type === 'transfer_out' ? l('transferOut') : l('handover')
}

function fmt(value) {
    return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'ar-IQ').format(Number(value || 0))
}

const collectionCashboxes = computed(() => props.cashboxes.filter((box) => ['branch', 'vault', 'bank'].includes(box.kind)))
const branchCashboxes = computed(() => collectionCashboxes.value.filter((box) => box.kind === 'branch'))
const collectionVouchers = computed(() => props.vouchers.filter((voucher) => ['courier_handover', 'transfer_in', 'transfer_out'].includes(voucher.type)))
const activeCashboxes = computed(() => collectionCashboxes.value.filter((box) => box.is_active).length)
const collectionBalance = computed(() => Number(props.summary.delivery_collections ?? props.summary.balance ?? 0) || 0)

function changeBranchFilter(branchId) {
    const query = Object.fromEntries(new URLSearchParams(window.location.search).entries())
    if (branchId) query.branch_id = String(branchId)
    else delete query.branch_id

    router.get(route('admin.cashboxes'), query, { preserveScroll: true, preserveState: false, replace: true })
}
</script>

<template>
    <AdminShell :title="l('title')">
        <header class="page-heading">
            <div>
                <p>{{ l('eyebrow') }}</p>
                <h2>{{ l('title') }}</h2>
                <span>{{ l('subtitle') }}</span>
            </div>
            <BranchFilter v-if="branchFilter?.enabled" :filter="branchFilter" @change="changeBranchFilter" />
        </header>

        <section class="kpi-grid">
            <article v-if="canViewCashboxBalances" class="kpi kpi-primary">
                <span class="ki">↧</span>
                <span><strong class="kval mono">{{ fmt(collectionBalance) }}</strong><small>{{ l('currency') }}</small><b class="klab">{{ l('total') }}</b></span>
            </article>
            <article class="kpi">
                <span class="ki">▣</span>
                <span><strong class="kval mono">{{ branchCashboxes.length }}</strong><b class="klab">{{ l('branches') }}</b></span>
            </article>
            <article class="kpi">
                <span class="ki">●</span>
                <span><strong class="kval mono">{{ activeCashboxes }}</strong><b class="klab">{{ l('active') }}</b></span>
            </article>
            <article v-if="canViewCashboxLedger" class="kpi">
                <span class="ki">≡</span>
                <span><strong class="kval mono">{{ collectionVouchers.length }}</strong><b class="klab">{{ l('entries') }}</b></span>
            </article>
        </section>

        <section class="cashbox-grid">
            <article v-for="box in collectionCashboxes" :key="box.id" class="box-card" :class="{ off: !box.is_active }">
                <header>
                    <span class="box-icon">▣</span>
                    <div>
                        <h3>{{ boxName(box) }}</h3>
                        <p>{{ box.branch ? `${box.branch.city} · ${l('branch')}` : kindLabel(box.kind) }}</p>
                    </div>
                </header>
                <div v-if="canViewCashboxBalances" class="box-balance">
                    <span>{{ l('balance') }}</span>
                    <b class="mono">{{ fmt(box.balance) }} <small>{{ l('currency') }}</small></b>
                </div>
                <footer>
                    <span :class="box.is_active ? 'ok' : 'off-label'">● {{ box.is_active ? l('active') : '—' }}</span>
                    <span>{{ l('scope') }}</span>
                </footer>
            </article>
            <div v-if="!collectionCashboxes.length" class="empty-card">{{ l('noCollections') }}</div>
        </section>

        <section v-if="canViewCashboxLedger" class="panel ledger">
            <header class="panel-head">
                <span><h3>{{ l('ledger') }}</h3><p>{{ l('ledgerSubtitle') }}</p></span>
            </header>
            <div class="table-scroll">
                <table v-if="collectionVouchers.length" class="tbl">
                    <thead><tr><th>{{ l('date') }}</th><th>{{ l('reference') }}</th><th>{{ l('movement') }}</th><th>{{ l('cashbox') }}</th><th>{{ l('actor') }}</th><th>{{ l('amount') }}</th><th>{{ l('note') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="row in collectionVouchers" :key="row.id">
                            <td class="mono">{{ row.occurred_at?.slice(0, 16).replace('T', ' ') }}</td>
                            <td class="mono">{{ row.reference }}</td>
                            <td><span class="movement-chip" :class="row.direction > 0 ? 'in' : 'out'">{{ movementLabel(row.type) }}</span><small v-if="row.counterparty">↔ {{ boxName(row.counterparty) }}</small></td>
                            <td><b>{{ boxName(row.cashbox) }}</b><small v-if="row.cashbox?.branch">{{ row.cashbox.branch.city }}</small></td>
                            <td>{{ row.actor?.name || '—' }}</td>
                            <td class="mono" :class="row.direction > 0 ? 'plus' : 'minus'">{{ row.direction > 0 ? '+' : '−' }}{{ fmt(row.amount) }} <small>{{ l('currency') }}</small></td>
                            <td class="note-cell">{{ row.note || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <div v-else class="empty">{{ l('noCollections') }}</div>
            </div>
        </section>
    </AdminShell>
</template>

<style scoped>
.page-heading{display:flex;align-items:end;justify-content:space-between;gap:18px;margin-bottom:18px}.page-heading p{margin:0 0 4px;color:var(--primary);font-size:10px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.page-heading h2{margin:0;color:var(--ink);font-size:24px;font-weight:900}.page-heading span{display:block;max-width:720px;margin-top:5px;color:var(--ink-faint);font-size:11px;font-weight:650;line-height:1.7}.kpi-grid{grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:17px}.kpi{min-height:86px;display:flex;align-items:center;gap:11px;padding:15px;border:1px solid var(--border);border-radius:14px;background:var(--surface)}.kpi-primary{border-color:color-mix(in srgb,var(--success) 28%,var(--border));background:linear-gradient(135deg,var(--surface),var(--success-tint))}.ki{display:grid;place-items:center;width:35px;height:35px;border-radius:10px;color:var(--primary-strong);background:var(--primary-tint);font-size:18px;font-weight:900}.kpi-primary .ki{color:var(--success);background:var(--success-tint)}.kpi>span:last-child{display:grid;gap:1px}.kval{color:var(--ink);font-size:16px}.kpi small{color:var(--ink-faint);font-size:8px;font-weight:800}.klab{color:var(--ink-faint);font-size:9px;font-weight:800}.cashbox-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:13px;margin-bottom:17px}.box-card{border:1px solid var(--border);border-radius:15px;background:var(--surface);overflow:hidden}.box-card.off{opacity:.62}.box-card header{display:flex;align-items:center;gap:9px;padding:14px}.box-icon{display:grid;place-items:center;width:35px;height:35px;border-radius:10px;color:var(--success);background:var(--success-tint);font-size:15px;font-weight:900}.box-card header>div{min-width:0;flex:1}.box-card h3{overflow:hidden;margin:0;color:var(--ink);font-size:12px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.box-card p{margin:3px 0 0;color:var(--ink-faint);font-size:9px;font-weight:700}.box-balance{display:grid;gap:4px;margin:0 14px;padding:12px;border-radius:11px;background:var(--surface-2)}.box-balance span{color:var(--ink-faint);font-size:9px;font-weight:800}.box-balance b{color:var(--ink);font-size:17px}.box-balance small{font-size:9px;color:var(--ink-faint)}.box-card footer{display:flex;justify-content:space-between;gap:8px;padding:12px 14px;color:var(--ink-faint);font-size:9px;font-weight:800}.ok,.plus{color:var(--success)}.off-label,.minus{color:var(--danger)}.ledger{margin:0}.panel-head{align-items:flex-start}.panel-head h3{margin:0;color:var(--ink);font-size:13px}.panel-head p{max-width:760px;margin:4px 0 0;color:var(--ink-faint);font-size:9.5px;font-weight:650;line-height:1.7}.table-scroll{overflow:auto}.tbl{min-width:860px}.tbl small{display:block;margin-top:2px;color:var(--ink-faint);font-size:8.5px}.movement-chip{display:inline-flex;padding:3px 7px;border-radius:20px;background:var(--surface-2);color:var(--ink-soft);font-size:9px;font-weight:850}.movement-chip.in{color:var(--success);background:var(--success-tint)}.movement-chip.out{color:var(--warning);background:var(--warning-tint)}.note-cell{max-width:270px;color:var(--ink-soft)}.empty,.empty-card{padding:28px;color:var(--ink-faint);font-size:11px;font-weight:750;text-align:center}.empty-card{display:grid;place-items:center;min-height:130px;border:1px dashed var(--border);border-radius:15px;background:var(--surface)}@media(max-width:850px){.kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.page-heading{align-items:start;flex-direction:column}}@media(max-width:520px){.kpi-grid{grid-template-columns:1fr 1fr}.box-card footer{align-items:flex-start;flex-direction:column}}
</style>
