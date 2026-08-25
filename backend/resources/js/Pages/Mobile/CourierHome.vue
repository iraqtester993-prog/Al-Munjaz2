<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppShell from '../../Components/AppShell.vue'
import HeroSlider from '../../Components/HeroSlider.vue'
import SheetModal from '../../Components/SheetModal.vue'
import StatusBadge from '../../Components/StatusBadge.vue'

const props = defineProps({
    stats: { type: Object, required: true },
    recentOrders: { type: Array, default: () => [] },
    availableOrders: { type: Array, default: () => [] },
    heroSlides: { type: Array, default: () => [] },
})

const page = usePage()
const user = computed(() => page.props.auth?.user)
const selected = ref(null)
const claiming = ref(false)
const now = ref(Date.now())
let ticker

const visibleAvailableOrders = computed(() => props.availableOrders.filter((order) => remainingMs(order) > 0))

function toggleDuty() {
    router.post(route('app.duty'), { is_online: !props.stats.onDuty }, { preserveScroll: true })
}

function deadline(order) {
    if (order.pickup_deadline_at) return new Date(order.pickup_deadline_at).getTime()
    return new Date(order.created_at || Date.now()).getTime() + 30 * 60 * 1000
}

function remainingMs(order) {
    return Math.max(0, deadline(order) - now.value)
}

function remainingText(order) {
    const seconds = Math.floor(remainingMs(order) / 1000)
    const minutes = Math.floor(seconds / 60)
    return `${String(minutes).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')} د`
}

function progress(order) {
    return Math.max(0, Math.min(100, (remainingMs(order) / (30 * 60 * 1000)) * 100))
}

function countdownColor(order) {
    const ratio = progress(order)
    if (ratio <= 20) return 'var(--danger)'
    if (ratio <= 45) return 'var(--warning)'
    return 'var(--success)'
}

function vehicleLabel(order) {
    return {
        normal: 'طلب عادي',
        bike: 'دراجة نارية',
        sedan: 'سيارة صالون',
        suv: 'سيارة كبيرة',
        truck: 'سيارة نقل',
    }[order.delivery_vehicle] || 'طلب عادي'
}

function canClaim(order) {
    return props.stats.onDuty && Number(props.stats.budget || 0) >= Number(order.price || 0)
}

function openDetails(order) {
    selected.value = order
}

function claim() {
    if (!selected.value || claiming.value) return
    claiming.value = true
    router.post(route('app.orders.claim', selected.value.id), {}, {
        preserveScroll: true,
        onSuccess: () => (selected.value = null),
        onFinish: () => (claiming.value = false),
    })
}

function whatsappUrl(phone) {
    if (!phone) return null
    const digits = String(phone).replace(/\D/g, '')
    const international = digits.startsWith('0') ? `964${digits.slice(1)}` : digits

    return `https://wa.me/${international}`
}

function openOrderChat(order) {
    router.post(route('app.chats.open'), { order_id: order.id })
}

onMounted(() => {
    ticker = window.setInterval(() => { now.value = Date.now() }, 1000)
})

onUnmounted(() => window.clearInterval(ticker))
</script>

<template>
    <AppShell title="مساء الله بالخير" :subtitle="user?.name">
        <template #title>
            مساء الله بالخير
            <span class="tb-sub">{{ user?.name || 'مندوب توصيل' }}</span>
        </template>

        <HeroSlider :slides="heroSlides" />

        <section class="courier-collection" :class="{ offline: !stats.onDuty }">
            <span class="collection-orb"></span>
            <div class="collection-copy">
                <span>المتحصل اليوم</span>
                <strong class="mono">{{ fmt(stats.collectedToday) }} <small>د.ع</small></strong>
                <div class="collection-chips">
                    <span class="collection-chip">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17l4-8h4l3 8M10 9h4M15 6a1.4 1.4 0 1 0 0-2.8A1.4 1.4 0 0 0 15 6Z"/></svg>
                        توصيلاتي اليوم: {{ stats.deliveredToday }}
                    </span>
                    <button class="collection-chip duty-chip" type="button" @click="toggleDuty">
                        <i :class="{ off: !stats.onDuty }"></i>{{ stats.onDuty ? 'أنا متاح للعمل' : 'غير متاح الآن' }}
                    </button>
                </div>
            </div>
        </section>

        <div class="available-heading">
            <h3>طلبات جديدة متاحة</h3>
            <span>الوقت المتاح: 30 دقيقة</span>
        </div>

        <div v-if="visibleAvailableOrders.length" class="available-list">
            <article v-for="order in visibleAvailableOrders" :key="order.id" class="available-order-card" @click="openDetails(order)">
                <div class="available-order-main">
                    <div class="available-order-head">
                        <div>
                            <h4>{{ order.customer_name_ar }}</h4>
                            <p><span class="mono">{{ order.track_no }}</span><b>•</b>{{ order.address_ar }}</p>
                        </div>
                        <span class="new-order-chip">طلب جديد متاح</span>
                    </div>

                    <div class="available-summary">
                        <strong class="mono">{{ fmt(order.price) }} <small>د.ع</small></strong>
                        <span class="vehicle-badge">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8 12 3 3 8v8l9 5 9-5V8ZM3 8l9 5 9-5M12 13v8"/></svg>
                            {{ vehicleLabel(order) }}
                        </span>
                    </div>

                    <p v-if="order.vehicle_note || order.notes" class="order-note"><b>ملاحظة الطلب:</b> {{ order.vehicle_note || order.notes }}</p>
                    <p v-if="!canClaim(order)" class="availability-warning">
                        {{ stats.onDuty ? 'لا يمكن أخذ الطلب — الميزانية أقل من قيمته.' : 'فعّل حالة التوفر أولاً لاستلام الطلب.' }}
                    </p>
                </div>

                <footer class="available-order-footer">
                    <div class="pickup-clock" :style="{ color: countdownColor(order) }">
                        <i :style="{ background: countdownColor(order), boxShadow: `0 0 7px ${countdownColor(order)}` }"></i>
                        الوقت المتاح للاستلام: <b class="mono">{{ remainingText(order) }}</b>
                    </div>
                    <button v-if="canClaim(order)" type="button" class="view-order" @click.stop="openDetails(order)">عرض التفاصيل</button>
                </footer>
                <div class="expiry-track"><i :style="{ width: `${progress(order)}%`, background: countdownColor(order) }"></i></div>
            </article>
        </div>
        <div v-else class="availability-empty">
            <span>✓</span>
            <b>لا توجد طلبات جديدة حالياً</b>
            <p>ستظهر الطلبات المطابقة لمحافظتك هنا.</p>
        </div>

        <section v-if="recentOrders.length" class="assigned-section">
            <div class="section-title">
                <h3>توصيلاتي الحالية</h3>
                <a @click="$inertia.visit(route('app.orders'))">عرض الكل</a>
            </div>
            <div class="list-card">
                <button v-for="order in recentOrders" :key="order.id" class="courier-assigned-row" type="button" @click="$inertia.visit(route('app.orders'))">
                    <span class="assigned-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17l4-8h4l3 8M10 9h4M15 6a1.4 1.4 0 1 0 0-2.8A1.4 1.4 0 0 0 15 6Z"/></svg>
                    </span>
                    <span class="order-mid"><b>{{ order.customer_name_ar }}</b><small class="mono">{{ order.track_no }}</small></span>
                    <span class="order-end"><b class="mono">{{ fmt(order.price) }}</b><StatusBadge :status="order.status" /></span>
                </button>
            </div>
        </section>

        <SheetModal :open="!!selected" :title="selected?.track_no" :subtitle="selected?.customer_name_ar" @close="selected = null">
            <template v-if="selected">
                <div class="detail-row"><span class="text-muted">العميل</span><b>{{ selected.customer_name_ar }}</b></div>
                <div class="detail-row"><span class="text-muted">الهاتف</span><b class="mono">{{ selected.phone }}</b></div>
                <div class="detail-row"><span class="text-muted">العنوان</span><b>{{ selected.address_ar }}</b></div>
                <div class="detail-row"><span class="text-muted">قيمة الطلب</span><b class="mono">{{ fmt(selected.price) }} د.ع</b></div>
                <div class="detail-row"><span class="text-muted">نوع الطلب</span><b>{{ vehicleLabel(selected) }}</b></div>
                <div v-if="selected.vehicle_note || selected.notes" class="detail-row detail-note"><span class="text-muted">ملاحظة الطلب</span><b>{{ selected.vehicle_note || selected.notes }}</b></div>
                <div class="detail-row"><span class="text-muted">ميزانيتك المتاحة</span><b class="mono">{{ fmt(stats.budget) }} د.ع</b></div>
                <div v-if="selected.pickup_deadline_at" class="detail-row"><span class="text-muted">الوقت المتاح للاستلام</span><b class="mono" :style="{ color: countdownColor(selected) }">{{ remainingText(selected) }}</b></div>

                <section v-if="selected.merchant" class="courier-merchant-card">
                    <span class="merchant-card-label">التاجر</span>
                    <div class="merchant-card-profile">
                        <span class="merchant-avatar">{{ selected.merchant.name?.slice(0, 1) }}</span>
                        <span><b>{{ selected.merchant.name }}</b><small v-if="selected.merchant.address">{{ selected.merchant.address }}</small><small v-if="selected.merchant.phone" class="mono">{{ selected.merchant.phone }}</small></span>
                    </div>
                    <div class="merchant-card-actions">
                        <a v-if="whatsappUrl(selected.merchant.phone)" :href="whatsappUrl(selected.merchant.phone)" target="_blank" rel="noopener">واتساب</a>
                        <button type="button" @click="openOrderChat(selected)">دردشة</button>
                    </div>
                </section>

                <a v-if="selected.status !== 'pending' && whatsappUrl(selected.phone)" class="customer-whatsapp" :href="whatsappUrl(selected.phone)" target="_blank" rel="noopener">واتساب الزبون</a>
                <p v-if="!canClaim(selected)" class="claim-explain">{{ stats.onDuty ? 'لا يمكنك أخذ الطلب لأن ميزانيتك الحالية أقل من قيمة الطلب.' : 'فعّل حالة «أنا متاح للعمل» ثم أعد المحاولة.' }}</p>
                <button class="btn btn-primary claim-order" type="button" :disabled="!canClaim(selected) || claiming" @click="claim">
                    <span v-if="claiming" class="loader"></span><span v-else>قبول الطلب</span>
                </button>
            </template>
        </SheetModal>
    </AppShell>
</template>

<style scoped>
.courier-collection { position:relative; overflow:hidden; padding:16px; border-radius:16px; background:linear-gradient(135deg, var(--primary-strong), var(--primary)); color:#fff; margin-bottom:17px; }
.courier-collection.offline { filter:saturate(.55); }
.collection-orb { position:absolute; top:-20px; inset-inline-end:-20px; width:80px; height:80px; border-radius:50%; background:rgba(255,255,255,.11); }
.collection-copy { position:relative; z-index:1; }
.collection-copy > span { display:block; margin-bottom:4px; font-size:11px; opacity:.82; font-weight:700; }
.collection-copy > strong { display:block; font-size:27px; font-weight:900; line-height:1; }
.collection-copy > strong small { font-family:var(--font); font-size:13px; opacity:.82; }
.collection-chips { display:flex; align-items:center; gap:8px; margin-top:11px; flex-wrap:wrap; }
.collection-chip { display:inline-flex; align-items:center; gap:5px; padding:6px 9px; border-radius:8px; background:rgba(255,255,255,.15); color:#fff; font:inherit; font-size:10.5px; font-weight:800; }
.duty-chip i { width:8px; height:8px; border-radius:50%; background:#72e7ae; box-shadow:0 0 7px #72e7ae; animation:pulse 1.5s infinite; }
.duty-chip i.off { background:#d9e1df; box-shadow:none; animation:none; }
.available-heading { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:12px; }
.available-heading h3 { margin:0; color:var(--ink); font-size:13px; font-weight:900; }
.available-heading span { padding:3px 10px; border-radius:20px; background:var(--surface-2); color:var(--ink-faint); font-size:10.5px; font-weight:800; }
.available-list { display:grid; gap:10px; }
.available-order-card { overflow:hidden; border:1.5px solid color-mix(in srgb, var(--primary) 42%, var(--border)); border-radius:18px; background:linear-gradient(145deg, color-mix(in srgb, var(--primary-tint) 84%, var(--surface)), color-mix(in srgb, var(--primary-tint) 52%, var(--surface))); box-shadow:0 6px 16px rgba(11,110,104,.12); cursor:pointer; }
.available-order-main { padding:12px 13px 10px; }
.available-order-head { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
.available-order-head h4 { margin:0 0 3px; color:var(--ink); font-size:14px; font-weight:900; }
.available-order-head p { display:flex; flex-wrap:wrap; align-items:center; gap:5px; margin:0; color:var(--ink-faint); font-size:10px; font-weight:700; }
.available-order-head p b { color:var(--ink-faint); }
.new-order-chip { flex:none; padding:4px 8px; border-radius:20px; background:var(--primary); color:#fff; box-shadow:0 2px 6px rgba(11,110,104,.2); font-size:9.5px; font-weight:900; }
.available-summary { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-top:11px; }
.available-summary > strong { color:var(--primary-strong); font-size:17px; font-weight:900; }
.available-summary > strong small { font-family:var(--font); color:var(--ink-faint); font-size:10px; }
.vehicle-badge { display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border:1px solid color-mix(in srgb, var(--primary) 26%, var(--border)); border-radius:10px; background:color-mix(in srgb, var(--primary-tint) 78%, var(--surface)); color:var(--primary-strong); font-size:11px; font-weight:800; }
.order-note { margin:9px 0 0; padding:6px 8px; border-radius:8px; background:var(--surface-2); color:var(--ink-soft); font-size:10.5px; font-weight:700; line-height:1.45; }
.order-note b { color:var(--primary-strong); }
.availability-warning { margin:8px 0 0; color:var(--danger); font-size:10px; font-weight:800; }
.available-order-footer { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 12px; border-top:1px solid var(--border); background:var(--surface-2); }
.pickup-clock { display:flex; align-items:center; gap:6px; font-size:10.5px; font-weight:900; }
.pickup-clock i { width:8px; height:8px; flex:none; border-radius:50%; animation:new-order-pulse 1.35s ease-in-out infinite; }
.view-order { padding:7px 11px; border-radius:9px; background:var(--primary); color:#fff; box-shadow:0 3px 8px rgba(11,110,104,.2); font:inherit; font-size:10px; font-weight:900; }
.expiry-track { height:4px; overflow:hidden; background:var(--surface-3); }
.expiry-track i { display:block; height:100%; border-radius:0 2px 2px 0; transition:width 1s linear, background .4s; }
.availability-empty { padding:34px 16px; border:1px dashed var(--border); border-radius:17px; text-align:center; color:var(--ink-soft); }
.availability-empty > span { width:53px; height:53px; margin:0 auto 10px; border-radius:50%; background:var(--surface-2); color:var(--success); display:grid; place-items:center; font-size:26px; font-weight:900; }
.availability-empty b, .availability-empty p { display:block; }
.availability-empty b { font-size:12px; font-weight:900; }
.availability-empty p { margin:3px 0 0; color:var(--ink-faint); font-size:10.5px; font-weight:700; }
.assigned-section { margin-top:18px; }
.courier-assigned-row { width:100%; display:flex; align-items:center; gap:10px; padding:12px 13px; border-bottom:1px solid var(--border); text-align:right; }
.courier-assigned-row:last-child { border-bottom:0; }
.assigned-icon { width:37px; height:37px; display:grid; place-items:center; flex:none; border-radius:11px; background:var(--st-courier-tint); color:var(--st-courier); }
.courier-assigned-row small { display:block; margin-top:1px; color:var(--ink-faint); font-size:10px; }
.order-end :deep(.badge) { margin-top:5px; }
.detail-note { align-items:flex-start; }
.courier-merchant-card { margin-top:14px; padding:13px; border:1.5px solid color-mix(in srgb, var(--primary) 25%, transparent); border-radius:14px; background:var(--primary-tint); }
.merchant-card-label { display:block; margin-bottom:9px; color:var(--primary-strong); font-size:11px; font-weight:900; }
.merchant-card-profile { display:flex; align-items:center; gap:9px; }
.merchant-avatar { width:39px; height:39px; display:grid; place-items:center; flex:none; border-radius:50%; background:var(--primary); color:#fff; font-size:15px; font-weight:900; }
.merchant-card-profile b, .merchant-card-profile small { display:block; }
.merchant-card-profile b { color:var(--ink); font-size:12.5px; font-weight:900; }
.merchant-card-profile small { margin-top:2px; color:var(--ink-faint); font-size:10px; font-weight:700; }
.merchant-card-actions { display:flex; gap:6px; margin-top:10px; }
.merchant-card-actions a, .merchant-card-actions button, .customer-whatsapp { flex:1; display:flex; align-items:center; justify-content:center; min-height:34px; border-radius:8px; font:inherit; font-size:10.5px; font-weight:900; text-decoration:none; }
.merchant-card-actions a, .customer-whatsapp { background:rgba(25,135,84,.12); color:#198754; }
.merchant-card-actions button { border:0; background:var(--primary); color:#fff; }
.customer-whatsapp { width:100%; margin-top:10px; }
.claim-explain { margin:13px 0; padding:9px 10px; border-radius:10px; background:var(--danger-tint); color:var(--danger); font-size:11px; font-weight:800; line-height:1.7; }
.claim-order { width:100%; margin-top:14px; }
</style>
