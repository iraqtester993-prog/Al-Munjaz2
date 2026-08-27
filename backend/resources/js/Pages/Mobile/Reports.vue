<script setup>
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppShell from '../../Components/AppShell.vue'
import StatusBadge from '../../Components/StatusBadge.vue'

const props = defineProps({
    period: { type: String, default: 'all' },
    filters: { type: Object, default: () => ({ status: 'all', from: null, to: null, province_id: null }) },
    summary: { type: Object, required: true },
    statusOptions: { type: Array, default: () => [] },
    provinceOptions: { type: Array, default: () => [] },
    provinceDistribution: { type: Array, default: () => [] },
    orders: { type: Array, default: () => [] },
})

const page = usePage()
const activeStatus = ref(null)
const filtersOpen = ref(false)
const copied = ref(false)
const locale = computed(() => page.props.locale || 'ar')
const archivedStatuses = ['delivered', 'returned']
const filterForm = ref({
    period: props.period,
    from: props.filters.from || '',
    to: props.filters.to || '',
    status: archivedStatuses.includes(props.filters.status) ? props.filters.status : 'all',
    province_id: props.filters.province_id ? String(props.filters.province_id) : '',
})

const statusMeta = computed(() => ({
    pending: { title: t('Pending'), icon: '◷', color: 'var(--st-pending)', tint: 'var(--st-pending-tint)' },
    approved: { title: t('Accepted'), icon: '✓', color: 'var(--st-approved)', tint: 'var(--st-approved-tint)' },
    courier: { title: t('With Courier'), icon: '↗', color: 'var(--st-courier)', tint: 'var(--st-courier-tint)' },
    delivered: { title: t('Delivered'), icon: '✓', color: 'var(--st-delivered)', tint: 'var(--st-delivered-tint)' },
    returned: { title: t('Returned'), icon: '↩', color: 'var(--st-returned)', tint: 'var(--st-returned-tint)' },
    cancelled: { title: t('Cancelled'), icon: '×', color: 'var(--danger)', tint: 'var(--danger-tint)' },
    damaged: { title: t('Damaged'), icon: '!', color: 'var(--warning)', tint: 'var(--warning-tint)' },
}))

// The archive is intentionally a completed-work record, not a second copy
// of active queues. Only delivered and returned orders belong here.
const statusCards = computed(() => archivedStatuses
    .map((status) => ({
        status,
        meta: statusMeta.value[status] || { title: status, icon: '•', color: 'var(--ink-soft)', tint: 'var(--surface-2)' },
        count: Number(props.summary.status_counts?.[status] || 0),
        value: Number(props.summary.status_values?.[status] || 0),
    }))
)

const archivedOrders = computed(() => props.orders.filter((order) => archivedStatuses.includes(order.status)))
const archivedTotal = computed(() => archivedOrders.value.reduce((sum, order) => sum + Number(order.price || 0), 0))
const deliveredArchive = computed(() => archivedOrders.value.filter((order) => order.status === 'delivered'))
const returnedArchive = computed(() => archivedOrders.value.filter((order) => order.status === 'returned'))
const detailOrders = computed(() => archivedOrders.value.filter((order) => order.status === activeStatus.value))
const detailTotal = computed(() => detailOrders.value.reduce((sum, order) => sum + Number(order.price || 0), 0))
const activeMeta = computed(() => activeStatus.value ? statusMeta.value[activeStatus.value] : null)
const archiveProvinceDistribution = computed(() => {
    const rows = new Map()

    for (const order of archivedOrders.value) {
        const province = order.province || {}
        const key = String(province.id || order.province_id || 'not-set')
        const row = rows.get(key) || { ...province, id: province.id || order.province_id || 'not-set', orders: 0, amount: 0 }
        row.orders += 1
        row.amount += Number(order.price || 0)
        rows.set(key, row)
    }

    return [...rows.values()].sort((a, b) => Number(b.orders) - Number(a.orders))
})
const topProvinceOrders = computed(() => Math.max(...archiveProvinceDistribution.value.map((row) => Number(row.orders || 0)), 1))

function customerName(order) {
    const preferred = locale.value === 'en' ? 'en' : locale.value === 'ku' ? 'ku' : 'ar'
    return order?.[`customer_name_${preferred}`]
        || order?.customer_name_ar
        || order?.customer_name_en
        || t('Not specified')
}

function provinceName(province) {
    const preferred = locale.value === 'en' ? 'en' : locale.value === 'ku' ? 'ku' : 'ar'
    return province?.[`name_${preferred}`]
        || province?.name_ar
        || province?.name_en
        || t('Not specified')
}

function formatDate(date) {
    if (!date) return t('Not specified')
    const language = { ar: 'ar-IQ-u-nu-latn', en: 'en-US', ku: 'ku-IQ-u-nu-latn' }[locale.value] || 'en-US'
    return new Intl.DateTimeFormat(language, { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(`${date}T12:00:00`))
}

function reportQuery(period = filterForm.value.period) {
    const query = { period }
    if (filterForm.value.from) query.from = filterForm.value.from
    if (filterForm.value.to) query.to = filterForm.value.to
    if (filterForm.value.status && filterForm.value.status !== 'all') query.status = filterForm.value.status
    if (filterForm.value.province_id) query.province_id = filterForm.value.province_id
    return query
}

function visitReport(period = filterForm.value.period) {
    activeStatus.value = null
    router.get(route('app.reports'), reportQuery(period), { preserveScroll: true, replace: true })
}

function setPeriod(period) {
    if (period === filterForm.value.period) return
    filterForm.value.period = period
    visitReport(period)
}

function applyFilters() {
    filtersOpen.value = false
    visitReport()
}

function clearFilters() {
    filterForm.value = { period: 'all', from: '', to: '', status: 'all', province_id: '' }
    filtersOpen.value = false
    visitReport('all')
}

function selectStatus(status) {
    activeStatus.value = status
}

async function copyReport() {
    const range = filterForm.value.period === 'today' ? t('Orders Today') : t('All Orders')
    const text = [
        `${t('Al-Munjaz Al-Saree')} — ${range}`,
        `${t('Total Orders')}: ${archivedOrders.value.length}`,
        `${t('Total Amount')}: ${fmt(archivedTotal.value)} ${t('IQD')}`,
        `${t('Delivered')}: ${deliveredArchive.value.length} — ${fmt(deliveredArchive.value.reduce((sum, order) => sum + Number(order.price || 0), 0))} ${t('IQD')}`,
        `${t('Returned')}: ${returnedArchive.value.length} — ${fmt(returnedArchive.value.reduce((sum, order) => sum + Number(order.price || 0), 0))} ${t('IQD')}`,
    ].join('\n')

    try {
        await navigator.clipboard.writeText(text)
        copied.value = true
        window.setTimeout(() => { copied.value = false }, 1800)
    } catch {
        // Some installed browsers intentionally deny clipboard permission.
    }
}
</script>

<template>
    <AppShell :title="t('Archive')">
        <template v-if="!activeStatus">
            <div class="report-tabs">
                <button type="button" :class="{ active: filterForm.period === 'all' }" @click="setPeriod('all')">{{ t('All Orders') }}</button>
                <button type="button" :class="{ active: filterForm.period === 'today' }" @click="setPeriod('today')">{{ t('Orders Today') }}</button>
                <button class="utility-tab" type="button" :class="{ active: filtersOpen }" @click="filtersOpen = !filtersOpen">
                    <svg viewBox="0 0 24 24"><path d="M4 7h16M7 12h10m-7 5h4" /></svg>
                    {{ t('Filters') }}
                </button>
                <button class="copy-report" type="button" @click="copyReport">
                    <svg viewBox="0 0 24 24"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    {{ copied ? t('Copied') : t('Copy') }}
                </button>
            </div>

            <section v-if="filtersOpen" class="report-filters list-card">
                <div class="filter-heading">
                    <b>{{ t('Filter Report') }}</b>
                    <button type="button" @click="clearFilters">{{ t('Clear filters') }}</button>
                </div>
                <div class="filter-grid">
                    <label class="filter-field"><span>{{ t('From') }}</span><input v-model="filterForm.from" type="date" /></label>
                    <label class="filter-field"><span>{{ t('To') }}</span><input v-model="filterForm.to" type="date" /></label>
                </div>
                <label class="filter-field"><span>{{ t('Status') }}</span>
                    <select v-model="filterForm.status">
                        <option value="all">{{ t('All statuses') }}</option>
                        <option v-for="status in archivedStatuses" :key="status" :value="status">{{ statusMeta[status]?.title || status }}</option>
                    </select>
                </label>
                <label class="filter-field"><span>{{ t('Governorate') }}</span>
                    <select v-model="filterForm.province_id">
                        <option value="">{{ t('All Governorates') }}</option>
                        <option v-for="province in provinceOptions" :key="province.id" :value="province.id">{{ provinceName(province) }}</option>
                    </select>
                </label>
                <button class="apply-filters" type="button" @click="applyFilters">{{ t('Apply filters') }}</button>
            </section>

            <section class="report-total">
                <div>
                    <span>{{ t('Total Amount') }}</span>
                    <strong class="mono">{{ fmt(archivedTotal) }} <small>{{ t('IQD') }}</small></strong>
                </div>
                <div class="report-total-count">
                    <span>{{ t('Total Orders') }}</span>
                    <strong class="mono">{{ archivedOrders.length }}</strong>
                </div>
            </section>

            <section class="status-summary">
                <div class="section-title"><h3>{{ t('Order Status Distribution') }}</h3><span>{{ t('Live totals') }}</span></div>
                <button v-for="card in statusCards" :key="card.status" class="report-status" type="button" @click="selectStatus(card.status)">
                    <span class="report-status-icon" :style="{ color: card.meta.color, background: card.meta.tint }">{{ card.meta.icon }}</span>
                    <span class="report-status-copy"><b>{{ card.meta.title }}</b><small>{{ card.count }} {{ t('orders') }}</small></span>
                    <span class="report-status-value mono" :style="{ color: card.meta.color }">{{ fmt(card.value) }}<small>{{ t('IQD') }}</small></span>
                    <svg class="report-chevron" viewBox="0 0 24 24"><path d="m15 18-6-6 6-6" /></svg>
                </button>
            </section>

            <section v-if="archiveProvinceDistribution.length" class="province-report list-card">
                <div class="province-heading"><div><b>{{ t('Orders by Governorate') }}</b><span>{{ t('Order Distribution by Governorate') }}</span></div></div>
                <article v-for="province in archiveProvinceDistribution" :key="province.id || 'not-set'" class="province-row">
                    <div class="province-copy"><b>{{ provinceName(province) }}</b><span>{{ province.orders }} {{ t('orders') }}</span></div>
                    <div class="province-bar"><i :style="{ width: `${Math.max(5, (Number(province.orders || 0) / topProvinceOrders) * 100)}%` }"></i></div>
                    <strong class="mono">{{ fmt(province.amount) }}</strong>
                </article>
            </section>

            <div v-if="!archivedOrders.length" class="empty-hint">{{ t('No orders found') }}</div>
        </template>

        <template v-else>
            <div class="report-detail-head">
                <button class="report-back" type="button" @click="activeStatus = null">
                    <svg viewBox="0 0 24 24"><path d="m15 18-6-6 6-6" /></svg>
                </button>
                <span class="report-detail-icon" :style="{ color: activeMeta.color, background: activeMeta.tint }">{{ activeMeta.icon }}</span>
                <b>{{ activeMeta.title }}</b>
                <span class="report-detail-total"><strong class="mono" :style="{ color: activeMeta.color }">{{ fmt(detailTotal) }} {{ t('IQD') }}</strong><small>{{ detailOrders.length }} {{ t('orders') }}</small></span>
            </div>

            <div v-if="detailOrders.length" class="list-card report-order-list">
                <article v-for="order in detailOrders" :key="order.id" class="report-order-row">
                    <span class="report-order-icon" :style="{ color: activeMeta.color, background: activeMeta.tint }">{{ activeMeta.icon }}</span>
                    <span class="report-order-mid"><b>{{ customerName(order) }}</b><small>{{ provinceName(order.province) }} · <span class="mono">{{ order.track_no }}</span> · {{ formatDate(order.date) }}</small></span>
                    <span class="report-order-end"><b class="mono">{{ fmt(order.price) }} {{ t('IQD') }}</b><StatusBadge :status="order.status" /></span>
                </article>
            </div>
            <div v-else class="empty-hint">{{ t('No data for this filter') }}</div>
        </template>
    </AppShell>
</template>

<style scoped>
.report-tabs{display:grid;grid-template-columns:1fr 1fr auto auto;gap:7px;margin-bottom:14px}.report-tabs button{display:flex;align-items:center;justify-content:center;gap:4px;min-height:42px;border:1.5px solid var(--border);border-radius:12px;background:var(--surface);color:var(--ink);font:inherit;font-size:10.5px;font-weight:800}.report-tabs button.active{border-color:var(--primary);background:var(--primary);color:#fff}.report-tabs .utility-tab,.report-tabs .copy-report{padding:0 10px}.report-tabs svg,.report-chevron,.report-back svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}.report-filters{margin:-4px 0 14px;padding:13px}.filter-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}.filter-heading b{font-size:12px;font-weight:900}.filter-heading button{border:0;color:var(--danger);font:800 10px var(--font)}.filter-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px}.filter-field{display:grid;gap:5px;margin-bottom:10px;color:var(--ink-soft);font-size:10px;font-weight:800}.filter-field input,.filter-field select{width:100%;min-height:38px;padding:8px 9px;border:1px solid var(--border);border-radius:10px;outline:none;background:var(--surface-2);color:var(--ink);font:inherit;font-size:11px;font-weight:750}.filter-field input:focus,.filter-field select:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-tint)}.apply-filters{width:100%;min-height:40px;border:0;border-radius:10px;background:var(--primary);color:#fff;font:850 11.5px var(--font)}.report-total{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:17px;padding:15px 16px;border-radius:15px;background:var(--primary);color:#fff}.report-total span{display:block;font-size:10.5px;font-weight:700;opacity:.8}.report-total strong{display:block;margin-top:3px;font-size:22px;font-weight:900;line-height:1}.report-total strong small{font-family:var(--font);font-size:11px;opacity:.78}.report-total-count{text-align:end}.status-summary{margin-bottom:17px}.section-title{margin:0 0 10px}.section-title>span{color:var(--ink-faint);font-size:9.5px;font-weight:700}.report-status{width:100%;display:flex;align-items:center;gap:12px;margin-bottom:9px;padding:13px 14px;border:1px solid var(--border);border-radius:14px;background:var(--surface);font:inherit;text-align:right}.report-status-icon,.report-detail-icon,.report-order-icon{display:grid;place-items:center;flex:none;border-radius:12px;font-weight:900}.report-status-icon{width:41px;height:41px}.report-status-copy{flex:1;min-width:0}.report-status-copy b,.report-status-copy small{display:block}.report-status-copy b{font-size:12.5px;font-weight:900}.report-status-copy small{margin-top:3px;color:var(--ink-faint);font-size:10px;font-weight:700}.report-status-value{font-size:13px;font-weight:900;text-align:end}.report-status-value small{display:block;margin-top:2px;color:var(--ink-faint);font-family:var(--font);font-size:9px;font-weight:700}.report-chevron{color:var(--ink-faint);flex:none}.province-report{margin-bottom:14px}.province-heading{padding:14px;border-bottom:1px solid var(--border)}.province-heading b,.province-heading span{display:block}.province-heading b{font-size:12.5px;font-weight:900}.province-heading span{margin-top:2px;color:var(--ink-faint);font-size:9.5px;font-weight:700}.province-row{display:grid;grid-template-columns:minmax(72px,.8fr) minmax(70px,1fr) auto;align-items:center;gap:9px;padding:11px 14px;border-bottom:1px solid var(--border)}.province-row:last-child{border-bottom:0}.province-copy{min-width:0}.province-copy b,.province-copy span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.province-copy b{font-size:10.5px;font-weight:850}.province-copy span{margin-top:2px;color:var(--ink-faint);font-size:9px;font-weight:700}.province-bar{height:6px;overflow:hidden;border-radius:99px;background:var(--surface-2)}.province-bar i{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,var(--primary),var(--accent))}.province-row>strong{font-size:10px;font-weight:900}.report-detail-head{display:flex;align-items:center;gap:9px;margin-bottom:14px}.report-back{display:grid;place-items:center;width:36px;height:36px;border:0;border-radius:10px;background:var(--surface-2);color:var(--ink)}.report-detail-icon{width:32px;height:32px;border-radius:10px}.report-detail-head>b{flex:1;font-size:14px;font-weight:900}.report-detail-total{text-align:end}.report-detail-total strong,.report-detail-total small{display:block}.report-detail-total strong{font-size:13px;font-weight:900}.report-detail-total small{margin-top:2px;color:var(--ink-faint);font-size:9.5px;font-weight:700}.report-order-row{display:flex;align-items:center;gap:10px;padding:12px 13px;border-bottom:1px solid var(--border)}.report-order-row:last-child{border-bottom:0}.report-order-icon{width:37px;height:37px;border-radius:11px}.report-order-mid{flex:1;min-width:0}.report-order-mid b,.report-order-mid small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.report-order-mid b{font-size:12px;font-weight:900}.report-order-mid small{margin-top:2px;color:var(--ink-faint);font-size:9px;font-weight:700}.report-order-end{text-align:end}.report-order-end>b{display:block;font-size:10.5px;font-weight:900}.report-order-end :deep(.badge){margin-top:4px}@media(max-width:380px){.report-tabs{grid-template-columns:1fr 1fr auto}.report-tabs .copy-report{grid-column:span 3}.province-row{grid-template-columns:minmax(65px,.8fr) minmax(45px,1fr) auto;gap:7px;padding-inline:10px}}
</style>
