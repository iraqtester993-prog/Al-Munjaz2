<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppShell from '../../Components/AppShell.vue'
import StatusBadge from '../../Components/StatusBadge.vue'

const props = defineProps({
    period: { type: String, default: 'all' },
    summary: { type: Object, required: true },
    orders: { type: Array, default: () => [] },
})

const activeStatus = ref(null)
const copied = ref(false)

const statusMeta = {
    delivered: { title: 'تم التسليم', icon: '✓', color: 'var(--st-delivered)', tint: 'var(--st-delivered-tint)' },
    returned: { title: 'راجع', icon: '↩', color: 'var(--st-returned)', tint: 'var(--st-returned-tint)' },
}

const detailOrders = computed(() => props.orders.filter((order) => order.status === activeStatus.value))
const detailTotal = computed(() => detailOrders.value.reduce((sum, order) => sum + Number(order.price || 0), 0))
const activeMeta = computed(() => activeStatus.value ? statusMeta[activeStatus.value] : null)

function setPeriod(period) {
    if (period === props.period) return
    router.get(route('app.reports'), { period }, { preserveScroll: true, replace: true })
}

function formatDate(date) {
    if (!date) return ''
    return new Intl.DateTimeFormat('ar-IQ', { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(`${date}T12:00:00`))
}

async function copyReport() {
    const range = props.period === 'today' ? 'طلبات اليوم' : 'كل الطلبات'
    const text = [
        `المنجز السريع — ${range}`,
        `عدد الطلبات: ${props.summary.orders_count}`,
        `إجمالي المبالغ: ${fmt(props.summary.orders_value)} د.ع`,
        `تم التسليم: ${props.summary.delivered_count} — ${fmt(props.summary.delivered_value)} د.ع`,
        `راجع: ${props.summary.returned_count} — ${fmt(props.summary.returned_value)} د.ع`,
    ].join('\n')

    try {
        await navigator.clipboard.writeText(text)
        copied.value = true
        window.setTimeout(() => { copied.value = false }, 1800)
    } catch {
        // Browsers that deny clipboard access still keep the report visible.
    }
}
</script>

<template>
    <AppShell title="الأرشيف">
        <template v-if="!activeStatus">
            <div class="report-tabs">
                <button :class="{ active: period === 'all' }" @click="setPeriod('all')">كل الطلبات</button>
                <button :class="{ active: period === 'today' }" @click="setPeriod('today')">طلبات اليوم</button>
                <button class="copy-report" type="button" @click="copyReport">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    {{ copied ? 'تم النسخ' : 'نسخ' }}
                </button>
            </div>

            <section class="report-total">
                <div>
                    <span>إجمالي المبالغ</span>
                    <strong class="mono">{{ fmt(summary.orders_value) }} <small>د.ع</small></strong>
                </div>
                <div class="report-total-count">
                    <span>عدد الطلبات</span>
                    <strong class="mono">{{ summary.orders_count }}</strong>
                </div>
            </section>

            <button class="report-status delivered" type="button" @click="activeStatus = 'delivered'">
                <span class="report-status-icon">✓</span>
                <span class="report-status-copy"><b>تم التسليم</b><small>{{ summary.delivered_count }} طلبات</small></span>
                <span class="report-status-value mono">{{ fmt(summary.delivered_value) }}<small>د.ع</small></span>
                <svg class="report-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6" /></svg>
            </button>

            <button class="report-status returned" type="button" @click="activeStatus = 'returned'">
                <span class="report-status-icon">↩</span>
                <span class="report-status-copy"><b>راجع</b><small>{{ summary.returned_count }} طلبات</small></span>
                <span class="report-status-value mono">{{ fmt(summary.returned_value) }}<small>د.ع</small></span>
                <svg class="report-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6" /></svg>
            </button>

            <div v-if="!orders.length" class="empty-hint">لا توجد طلبات في هذه الفترة.</div>
        </template>

        <template v-else>
            <div class="report-detail-head">
                <button class="report-back" type="button" @click="activeStatus = null">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6" /></svg>
                </button>
                <span class="report-detail-icon" :style="{ color: activeMeta.color, background: activeMeta.tint }">{{ activeMeta.icon }}</span>
                <b>{{ activeMeta.title }}</b>
                <span class="report-detail-total"><strong class="mono" :style="{ color: activeMeta.color }">{{ fmt(detailTotal) }} د.ع</strong><small>{{ detailOrders.length }} طلبات</small></span>
            </div>

            <div v-if="detailOrders.length" class="list-card report-order-list">
                <article v-for="order in detailOrders" :key="order.id" class="report-order-row">
                    <span class="report-order-icon" :style="{ color: activeMeta.color, background: activeMeta.tint }">{{ activeMeta.icon }}</span>
                    <span class="report-order-mid"><b>{{ order.customer_name_ar }}</b><small class="mono">{{ order.track_no }} · {{ formatDate(order.date) }}</small></span>
                    <span class="report-order-end"><b class="mono">{{ fmt(order.price) }} د.ع</b><StatusBadge :status="order.status" /></span>
                </article>
            </div>
            <div v-else class="empty-hint">لا توجد طلبات بهذه الحالة.</div>
        </template>
    </AppShell>
</template>

<style scoped>
.report-tabs{display:flex;gap:8px;margin-bottom:14px}.report-tabs button{flex:1;min-height:42px;border:1.5px solid var(--border);border-radius:12px;background:var(--surface);color:var(--ink);font:inherit;font-size:11.5px;font-weight:800;cursor:pointer}.report-tabs button.active{border-color:var(--primary);background:var(--primary);color:#fff}.report-tabs .copy-report{flex:0 0 auto;display:flex;align-items:center;gap:4px;padding:0 13px}.report-total{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:14px;padding:14px 16px;border-radius:14px;background:var(--primary);color:#fff}.report-total span{display:block;font-size:10.5px;font-weight:700;opacity:.8}.report-total strong{display:block;margin-top:3px;font-size:22px;font-weight:900;line-height:1}.report-total strong small{font-family:var(--font);font-size:11px;opacity:.78}.report-total-count{text-align:end}.report-status{width:100%;display:flex;align-items:center;gap:12px;margin-bottom:10px;padding:14px;border:1px solid var(--border);border-radius:14px;background:var(--surface);font:inherit;text-align:right;cursor:pointer}.report-status-icon,.report-detail-icon,.report-order-icon{display:grid;place-items:center;flex:none;border-radius:12px;font-weight:900}.report-status-icon{width:42px;height:42px}.delivered .report-status-icon{background:var(--st-delivered-tint);color:var(--st-delivered)}.returned .report-status-icon{background:var(--st-returned-tint);color:var(--st-returned)}.report-status-copy{flex:1;min-width:0}.report-status-copy b,.report-status-copy small{display:block}.report-status-copy b{font-size:13px;font-weight:900}.report-status-copy small{margin-top:3px;color:var(--ink-faint);font-size:10.5px;font-weight:700}.report-status-value{color:var(--primary-strong);font-size:14px;font-weight:900;text-align:end}.returned .report-status-value{color:var(--st-returned)}.report-status-value small{display:block;margin-top:2px;color:var(--ink-faint);font-family:var(--font);font-size:9.5px;font-weight:700}.report-chevron{color:var(--ink-faint);flex:none}.report-detail-head{display:flex;align-items:center;gap:9px;margin-bottom:14px}.report-back{display:grid;place-items:center;width:36px;height:36px;border:0;border-radius:10px;background:var(--surface-2);color:var(--ink);cursor:pointer}.report-detail-icon{width:32px;height:32px;border-radius:10px}.report-detail-head>b{flex:1;font-size:14px;font-weight:900}.report-detail-total{text-align:end}.report-detail-total strong,.report-detail-total small{display:block}.report-detail-total strong{font-size:13px;font-weight:900}.report-detail-total small{margin-top:2px;color:var(--ink-faint);font-size:9.5px;font-weight:700}.report-order-row{display:flex;align-items:center;gap:10px;padding:12px 13px;border-bottom:1px solid var(--border)}.report-order-row:last-child{border-bottom:0}.report-order-icon{width:37px;height:37px;border-radius:11px}.report-order-mid{flex:1;min-width:0}.report-order-mid b,.report-order-mid small{display:block}.report-order-mid b{font-size:12px;font-weight:900}.report-order-mid small{margin-top:2px;color:var(--ink-faint);font-size:9.5px;font-weight:700}.report-order-end{text-align:end}.report-order-end>b{display:block;font-size:11px;font-weight:900}.report-order-end :deep(.badge){margin-top:4px}
</style>
