<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppShell from '../../Components/AppShell.vue'
import HeroSlider from '../../Components/HeroSlider.vue'
import StatusBadge from '../../Components/StatusBadge.vue'

const props = defineProps({
    stats: { type: Object, required: true },
    recentOrders: { type: Array, default: () => [] },
    availableOrders: { type: Array, default: () => [] },
    availablePagination: { type: Object, default: () => ({ next_cursor: null, has_more: false }) },
    orderExpiryMinutes: { type: Number, default: 30 },
    heroSlides: { type: Array, default: () => [] },
    canAcceptOrders: { type: Boolean, default: true },
})

const page = usePage()
const user = computed(() => page.props.auth?.user)
const locale = computed(() => page.props.locale || 'ar')
const greeting = computed(() => `${t('Welcome')}، ${user.value?.name || t('Courier')}`)
const loadingMoreAvailable = ref(false)
const loadedAvailableOrders = ref([...props.availableOrders])
const availablePagination = ref({ ...props.availablePagination })
const orderExpiryMinutes = ref(normalizeOrderExpiryMinutes(props.orderExpiryMinutes))
const now = ref(Date.now())
let ticker

const visibleAvailableOrders = computed(() => props.canAcceptOrders
    ? loadedAvailableOrders.value.filter((order) => acceptanceRemainingMs(order) > 0)
    : [])

watch(() => props.availableOrders, (orders) => {
    loadedAvailableOrders.value = [...(orders || [])]
})

watch(() => props.availablePagination, (nextPagination) => {
    availablePagination.value = { ...(nextPagination || {}) }
})

watch(() => props.orderExpiryMinutes, (minutes) => {
    orderExpiryMinutes.value = normalizeOrderExpiryMinutes(minutes)
})

function toggleDuty() {
    if (!props.canAcceptOrders) return

    router.post(route('app.duty'), { is_online: !props.stats.onDuty }, { preserveScroll: true })
}

// Before a courier accepts an order, pickup_deadline_at is the offer's acceptance
// deadline. It becomes the merchant-arrival deadline only after assignment.
function normalizeOrderExpiryMinutes(value) {
    const minutes = Number(value)
    if (!Number.isFinite(minutes)) return 30

    return Math.max(1, Math.min(Math.round(minutes), 1440))
}

const configuredAcceptanceWindowMs = computed(() => orderExpiryMinutes.value * 60 * 1000)

function validTimestamp(value) {
    if (value === null || value === undefined || value === '') return null

    const timestamp = new Date(value).getTime()
    return Number.isFinite(timestamp) ? timestamp : null
}

function acceptanceWindowMs(order) {
    // Prefer the immutable offer window stored with this order.  This keeps
    // a live offer visually accurate even if the dashboard setting changes
    // after the order was published.
    const deadline = validTimestamp(order?.pickup_deadline_at)
    const offerOpenedAt = validTimestamp(order?.offer_opened_at)
    if (deadline !== null && offerOpenedAt !== null && deadline > offerOpenedAt) {
        return deadline - offerOpenedAt
    }

    return configuredAcceptanceWindowMs.value
}

function acceptanceDeadline(order) {
    const deadline = validTimestamp(order?.pickup_deadline_at)
    if (deadline !== null) return deadline

    return (validTimestamp(order?.created_at) ?? Date.now()) + configuredAcceptanceWindowMs.value
}

function acceptanceRemainingMs(order) {
    return Math.max(0, acceptanceDeadline(order) - now.value)
}

function acceptanceRemainingText(order) {
    const seconds = Math.floor(acceptanceRemainingMs(order) / 1000)
    const minutes = Math.floor(seconds / 60)
    return `${String(minutes).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')} ${t('Minutes abbreviation')}`
}

function acceptanceProgress(order) {
    return Math.max(0, Math.min(100, (acceptanceRemainingMs(order) / acceptanceWindowMs(order)) * 100))
}

function acceptanceCountdownColor(order) {
    const ratio = acceptanceProgress(order)
    if (ratio <= 20) return 'var(--danger)'
    if (ratio <= 45) return 'var(--warning)'
    return 'var(--success)'
}

function vehicleLabel(order) {
    return {
        normal: t('Regular Delivery'),
        bike: t('Motorcycle'),
        car: t('Sedan'),
        sedan: t('Sedan'),
        suv: t('SUV'),
        truck: t('Van / Truck'),
    }[order.delivery_vehicle] || t('Regular Delivery')
}

function localizedOrderValue(order, key) {
    const preferred = locale.value === 'en' ? 'en' : locale.value === 'ku' ? 'ku' : 'ar'

    return order?.[`${key}_${preferred}`]
        || order?.[`${key}_en`]
        || order?.[`${key}_ar`]
        || ''
}

function customerName(order) {
    return localizedOrderValue(order, 'customer_name')
}

function customerAddress(order) {
    return localizedOrderValue(order, 'address')
}

function canClaim(order) {
    // Accepting a job reserves its product price from the available budget;
    // delivery pricing is not part of that cash reservation.  The prepaid Qi
    // balance must separately cover this courier's administration deduction.
    return props.canAcceptOrders
        && props.stats.onDuty
        && Number(props.stats.budgetBalance ?? props.stats.budget ?? 0) >= Number(order?.price || 0)
        && Number(props.stats.walletBalance || 0) >= Number(props.stats.adminDeduction || 0)
}

function openDetails(order) {
    if (!order?.id) return

    // Available orders must use the same complete detail sheet as the
    // courier's Pending queue.  The home payload stays intentionally compact;
    // Orders.vue then fetches the authorised full detail (customer card,
    // second phone, financial card, merchant and map) before opening it.
    router.visit(route('app.orders', {
        filter: 'pending',
        list: 1,
        open: order.id,
    }), {
        preserveScroll: true,
        viewTransition: false,
    })
}

async function loadMoreAvailableOrders() {
    const cursor = availablePagination.value?.next_cursor
    if (!props.canAcceptOrders || !cursor || loadingMoreAvailable.value) return

    loadingMoreAvailable.value = true
    try {
        const url = new URL(route('app'), window.location.origin)
        url.searchParams.set('available_cursor', cursor)
        const response = await fetch(url.toString(), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
        if (!response.ok) throw new Error(`Available orders request failed with ${response.status}`)

        const payload = await response.json()
        if (Object.prototype.hasOwnProperty.call(payload, 'orderExpiryMinutes')) {
            orderExpiryMinutes.value = normalizeOrderExpiryMinutes(payload.orderExpiryMinutes)
        }
        const known = new Set(loadedAvailableOrders.value.map((order) => Number(order.id)))
        loadedAvailableOrders.value = [
            ...loadedAvailableOrders.value,
            ...(payload.availableOrders || []).filter((order) => !known.has(Number(order.id))),
        ]
        availablePagination.value = { ...(payload.availablePagination || {}) }
    } finally {
        loadingMoreAvailable.value = false
    }
}

onMounted(() => {
    ticker = window.setInterval(() => { now.value = Date.now() }, 1000)
})

onUnmounted(() => window.clearInterval(ticker))
</script>

<template>
    <AppShell :title="greeting">

        <HeroSlider :slides="heroSlides" />

        <section v-if="!canAcceptOrders" class="courier-verification-notice" role="status">
            <span class="courier-verification-icon" aria-hidden="true">!</span>
            <div>
                <b>{{ t('Courier account under review') }}</b>
                <p>{{ t('Your account cannot accept orders until administration approves your documents and verifies it.') }}</p>
            </div>
        </section>

        <section class="courier-collection" :class="{ offline: !stats.onDuty || !canAcceptOrders }">
            <span class="collection-orb"></span>
            <div class="collection-copy">
                <div class="collection-heading">
                    <span>{{ t("Today's Delivery Collections") }}</span>
                    <small>{{ t('Net after administration deduction') }}</small>
                </div>
                <strong class="mono">{{ fmt(stats.collectedToday) }} <small>{{ t('IQD') }}</small></strong>
                <div class="collection-chips">
                    <span class="collection-chip">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17l4-8h4l3 8M10 9h4M15 6a1.4 1.4 0 1 0 0-2.8A1.4 1.4 0 0 0 15 6Z"/></svg>
                        {{ t('My Deliveries Today') }}: {{ stats.deliveredToday }}
                    </span>
                    <button v-if="canAcceptOrders" class="collection-chip duty-chip" type="button" @click="toggleDuty">
                        <i :class="{ off: !stats.onDuty }"></i>{{ stats.onDuty ? t('Available for Work') : t('Currently Unavailable') }}
                    </button>
                    <span v-else class="collection-chip duty-chip"><i class="off"></i>{{ t('Verification pending') }}</span>
                </div>
            </div>
        </section>

        <template v-if="canAcceptOrders">
            <div class="available-heading">
                <h3>{{ t('Available New Orders') }}</h3>
                <span>{{ t('Time to accept the order') }}</span>
            </div>

            <div v-if="visibleAvailableOrders.length" class="available-list">
                <article v-for="order in visibleAvailableOrders" :key="order.id" class="available-order-card" @click="openDetails(order)">
                    <div class="available-order-main">
                        <div class="available-order-head">
                            <div>
                                <h4>{{ customerName(order) }}</h4>
                                <p><span class="available-address">{{ customerAddress(order) }}</span></p>
                            </div>
                            <span class="new-order-chip">{{ t('New Order') }}</span>
                        </div>

                        <div class="available-summary">
                            <strong class="mono">{{ fmt(order.price) }} <small>{{ t('IQD') }}</small></strong>
                            <span class="vehicle-badge">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8 12 3 3 8v8l9 5 9-5V8ZM3 8l9 5 9-5M12 13v8"/></svg>
                                {{ vehicleLabel(order) }}
                            </span>
                        </div>
                        <p v-if="order.vehicle_note && order.status !== 'approved'" class="available-order-note available-vehicle-note"><b>{{ t('Vehicle Note') }}:</b> {{ order.vehicle_note }}</p>
                    </div>

                    <footer class="available-order-footer">
                        <div class="acceptance-clock" :style="{ color: acceptanceCountdownColor(order) }">
                            <i :style="{ background: acceptanceCountdownColor(order), boxShadow: `0 0 7px ${acceptanceCountdownColor(order)}` }"></i>
                            {{ t('Time to accept the order') }}: <b class="mono">{{ acceptanceRemainingText(order) }}</b>
                        </div>
                        <button v-if="canClaim(order)" type="button" class="view-order" @click.stop="openDetails(order)">{{ t('Order Details') }}</button>
                    </footer>
                    <div class="expiry-track"><i :style="{ width: `${acceptanceProgress(order)}%`, background: acceptanceCountdownColor(order) }"></i></div>
                </article>
            </div>
            <button v-if="availablePagination.has_more" class="available-load-more" type="button" :disabled="loadingMoreAvailable" @click="loadMoreAvailableOrders">
                <span v-if="loadingMoreAvailable" class="loader"></span>
                <span v-else>{{ t('See all') }}</span>
            </button>
            <div v-else class="availability-empty">
                <span class="availability-empty-icon" aria-hidden="true">
                    <svg width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="m8.5 12 2.3 2.3 4.8-5"/></svg>
                </span>
                <b>{{ t('No orders found') }}</b>
                <p>{{ t('Available New Orders') }}</p>
            </div>
        </template>

        <section v-if="recentOrders.length" class="assigned-section">
            <div class="section-title">
                <h3>{{ t('Recent Deliveries') }}</h3>
                <a @click="$inertia.visit(route('app.orders', { list: 1 }))">{{ t('See all') }}</a>
            </div>
            <div class="list-card">
                <button v-for="order in recentOrders" :key="order.id" class="courier-assigned-row" type="button" @click="$inertia.visit(route('app.orders', { list: 1, open: order.id }))">
                    <span class="assigned-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17l4-8h4l3 8M10 9h4M15 6a1.4 1.4 0 1 0 0-2.8A1.4 1.4 0 0 0 15 6Z"/></svg>
                    </span>
                    <span class="order-mid"><b>{{ customerName(order) }}</b></span>
                    <span class="order-end"><small class="order-date">{{ order.date }}</small><b class="mono">{{ fmt(order.price) }}</b><StatusBadge :status="order.status" /></span>
                </button>
            </div>
        </section>

        <!-- Available orders open the unified detail screen in Mobile/Orders. -->
    </AppShell>
</template>

<style scoped>
.courier-collection { position:relative; overflow:hidden; padding:16px; border-radius:16px; background:linear-gradient(135deg, var(--primary-strong), var(--primary)); color:#fff; margin-bottom:17px; }
.courier-verification-notice { display:flex; align-items:flex-start; gap:10px; margin:-3px 0 15px; padding:12px; border:1px solid color-mix(in srgb,var(--warning) 45%,var(--border)); border-radius:14px; background:var(--warning-tint); color:var(--ink); }
.courier-verification-icon { display:grid; width:21px; height:21px; place-items:center; flex:none; border-radius:50%; background:var(--warning); color:#fff; font-size:13px; font-weight:950; line-height:1; }
.courier-verification-notice > div { display:grid; gap:2px; min-width:0; }.courier-verification-notice b { color:var(--ink); font-size:11.5px; font-weight:900; }.courier-verification-notice p { margin:0; color:var(--ink-soft); font-size:10px; font-weight:750; line-height:1.65; }
.detail-total-card{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:8px;padding:13px 14px;border-radius:13px;background:var(--primary);color:#fff}.detail-total-card span{font-size:11px;font-weight:850;opacity:.85}.detail-total-card strong{font-size:20px;font-weight:950;line-height:1}.detail-total-card small{font-family:var(--font);font-size:10px;opacity:.8}
.courier-collection.offline { filter:saturate(.55); }
.collection-orb { position:absolute; top:-20px; inset-inline-end:-20px; width:80px; height:80px; border-radius:50%; background:rgba(255,255,255,.11); }
.collection-copy { position:relative; z-index:1; }
.collection-heading { display:grid; gap:2px; margin-bottom:5px; }
.collection-heading > span { display:block; font-size:11px; opacity:.92; font-weight:850; }
.collection-heading > small { display:block; font-family:var(--font); font-size:9.5px; opacity:.74; font-weight:750; }
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
.available-load-more{display:flex;align-items:center;justify-content:center;gap:7px;width:100%;min-height:42px;margin-top:12px;border:1px solid color-mix(in srgb,var(--primary) 28%,var(--border));border-radius:12px;background:var(--primary-tint);color:var(--primary-strong);font:800 11px var(--font);cursor:pointer}.available-load-more:disabled{opacity:.6;cursor:wait}
.available-order-card { overflow:hidden; border:1.5px solid color-mix(in srgb, var(--primary) 42%, var(--border)); border-radius:16px; background:linear-gradient(145deg, color-mix(in srgb, var(--primary-tint) 84%, var(--surface)), color-mix(in srgb, var(--primary-tint) 52%, var(--surface))); box-shadow:0 6px 16px rgba(11,110,104,.12); cursor:pointer; }
.available-order-main { padding:11px 12px 9px; }
.available-order-head { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
.available-order-head h4 { margin:0 0 2px; color:var(--ink); font-size:13px; font-weight:800; }
.available-order-head p { display:flex; align-items:center; gap:5px; min-width:0; margin:0; color:var(--ink-faint); font-size:10px; font-weight:700; }
.available-order-head p b { color:var(--ink-faint); }
.available-address{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.new-order-chip { flex:none; padding:4px 8px; border-radius:20px; background:var(--primary); color:#fff; box-shadow:0 2px 6px rgba(11,110,104,.2); font-size:9.5px; font-weight:800; }
.available-summary { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-top:7px; }
.available-summary > strong { color:var(--primary-strong); font-size:16px; font-weight:900; }
.available-summary > strong small { font-family:var(--font); color:var(--ink-faint); font-size:10px; }
.vehicle-badge { display:inline-flex; align-items:center; gap:5px; max-width:126px; padding:5px 8px; border:1px solid color-mix(in srgb, var(--primary) 26%, var(--border)); border-radius:9px; background:color-mix(in srgb, var(--primary-tint) 78%, var(--surface)); color:var(--primary-strong); font-size:9px; font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.available-order-note { margin:9px 0 0; padding:7px 9px; border:1px solid color-mix(in srgb,var(--danger) 18%,transparent); border-radius:10px; background:color-mix(in srgb,var(--danger-tint) 68%,var(--surface)); color:var(--ink-soft); font-size:10px; font-weight:750; line-height:1.55; }
.available-order-note b { color:var(--danger); }
.available-order-footer { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 14px; border-top:1px solid var(--border); background:var(--surface-2); }
.order-date{display:block;margin-bottom:2px;color:var(--ink-faint);font-size:8.5px;font-weight:700}
.acceptance-clock { display:flex; align-items:center; gap:5px; min-width:0; font-size:11px; font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.acceptance-clock i { width:8px; height:8px; flex:none; border-radius:50%; animation:new-order-pulse 1.35s ease-in-out infinite; }
.view-order { padding:7px 12px; border-radius:9px; background:var(--primary); color:#fff; box-shadow:0 3px 8px rgba(11,110,104,.2); font:inherit; font-size:10.5px; font-weight:900; white-space:nowrap; }
.expiry-track { height:4px; overflow:hidden; background:var(--surface-3); }
.expiry-track i { display:block; height:100%; border-radius:0 2px 2px 0; transition:width 1s linear, background .4s; }
.availability-empty { padding:34px 16px; border:1px dashed var(--border); border-radius:17px; text-align:center; color:var(--ink-soft); }
.availability-empty > .availability-empty-icon { width:53px; height:53px; margin:0 auto 10px; border-radius:50%; background:var(--surface-2); color:var(--success); display:grid; place-items:center; }
.availability-empty b, .availability-empty p { display:block; }
.availability-empty b { font-size:12px; font-weight:900; }
.availability-empty p { margin:3px 0 0; color:var(--ink-faint); font-size:10.5px; font-weight:700; }
.assigned-section { margin-top:18px; }
.courier-assigned-row { width:100%; display:flex; align-items:center; gap:10px; padding:12px 13px; border-bottom:1px solid var(--border); text-align:right; }
.courier-assigned-row:last-child { border-bottom:0; }
.assigned-icon { width:37px; height:37px; display:grid; place-items:center; flex:none; border-radius:11px; background:var(--st-courier-tint); color:var(--st-courier); }
.courier-assigned-row small { display:block; margin-top:1px; color:var(--ink-faint); font-size:10px; }
.order-end :deep(.badge) { margin-top:5px; }
.order-detail-status{margin:-2px 0 18px;padding-bottom:15px;border-bottom:1px solid var(--border)}.order-detail-status-head{display:flex;align-items:center;gap:8px}.order-detail-status-head :deep(.badge){margin-inline-start:auto}.order-detail-track{color:var(--primary-strong);font-size:16px;font-weight:900}.order-detail-icon{display:grid;place-items:center;width:32px;height:32px;border-radius:10px;color:var(--primary-strong);background:var(--primary-tint)}.order-detail-steps{position:relative;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:2px;margin-top:19px}.order-detail-steps::before{position:absolute;top:12px;inset-inline:10%;height:2px;background:var(--border);content:''}.order-detail-step{position:relative;z-index:1;display:grid;justify-items:center;gap:5px;min-width:0;color:var(--ink-faint);text-align:center}.order-detail-step i{display:grid;place-items:center;width:25px;height:25px;border:2px solid var(--border);border-radius:50%;background:var(--surface);font-family:var(--font-mono,var(--font));font-size:10px;font-style:normal;font-weight:900}.order-detail-step b{overflow:hidden;max-width:100%;font-size:8.5px;font-weight:800;line-height:1.35;text-overflow:ellipsis;white-space:nowrap}.order-detail-step.done{color:var(--success)}.order-detail-step.done i{border-color:var(--success);background:var(--success-tint)}.order-detail-step.active{color:var(--warning)}.order-detail-step.active i{border-color:var(--warning);box-shadow:0 0 0 4px var(--warning-tint);color:var(--warning)}.order-detail-section{display:grid;gap:0}.order-detail-section h3{margin:0 0 10px;color:var(--ink);font-size:15px;font-weight:900}.detail-row{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:7px 0}.detail-row>span{flex:none}.detail-row>b{min-width:0;color:var(--ink);font-size:12px;font-weight:800;text-align:left}.delivery-vehicle-pill{padding:5px 10px;border:1px solid color-mix(in srgb,var(--primary) 28%,var(--border));border-radius:10px;color:var(--primary-strong)!important;background:var(--primary-tint);white-space:nowrap}.detail-note-box{margin:8px 0;padding:9px 11px;border-radius:10px;color:var(--ink-soft);background:var(--surface-2);font-size:10.5px;font-weight:750;line-height:1.6}.detail-note-box b{color:var(--danger)}.detail-price{margin-top:1px;border-top:1px solid var(--border)}.detail-price>b{color:var(--primary-strong);font-size:14px}.courier-merchant-card{display:grid;gap:10px;margin-top:16px;padding:14px;border:1.5px solid color-mix(in srgb,var(--primary) 34%,var(--border));border-radius:14px;background:var(--primary-tint)}.merchant-card-label{display:block;color:var(--primary-strong);font-size:11px;font-weight:900}.merchant-card-profile{display:flex;align-items:center;gap:10px}.merchant-avatar{width:43px;height:43px;display:grid;place-items:center;flex:none;border-radius:50%;background:var(--primary);color:#fff;font-size:17px;font-weight:900}.merchant-card-profile>span:last-child{min-width:0;flex:1}.merchant-card-profile b,.merchant-card-profile small{display:block}.merchant-card-profile b{overflow:hidden;color:var(--ink);font-size:13px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.merchant-card-profile small{margin-top:3px;color:var(--ink-faint);font-size:10px;font-weight:750}.merchant-verified{display:inline-grid!important;place-items:center;width:15px;height:15px;margin-inline-start:4px;border-radius:50%;background:#1d9bf0;color:#fff;font-size:10px;font-style:normal;line-height:1;vertical-align:1px}.merchant-info-row{display:flex!important;align-items:center;gap:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.merchant-info-row svg{flex:none}.merchant-location-row{display:grid;gap:5px;padding:9px 10px;border-radius:10px;background:rgba(11,110,104,.07)}.merchant-location-row>span{display:flex;align-items:center;gap:5px;color:var(--primary-strong);font-size:10.5px;font-weight:800}.merchant-location-row>span svg{flex:none}.merchant-location-row a{color:var(--primary-strong);font-size:10px;font-weight:900;text-decoration:underline}.merchant-location-row small{color:var(--ink-faint);font-size:9.5px;font-weight:750}.merchant-card-actions{display:flex;gap:7px}.merchant-card-actions a,.merchant-card-actions button,.customer-whatsapp{flex:1;display:flex;align-items:center;justify-content:center;min-height:38px;border-radius:10px;font:inherit;font-size:11px;font-weight:900;text-decoration:none}.merchant-card-actions a,.customer-whatsapp{background:rgba(25,135,84,.12);color:#198754}.merchant-card-actions button{border:0;background:var(--primary);color:#fff}.order-detail-close{width:100%;margin-top:9px;border:1px solid var(--border);color:var(--ink);background:var(--surface-2)}
.customer-whatsapp { width:100%; margin-top:10px; }
.available-vehicle-note,.vehicle-note-box{border-color:color-mix(in srgb,var(--primary) 24%,transparent)!important;background:color-mix(in srgb,var(--primary-tint) 68%,var(--surface))!important}.available-vehicle-note b,.vehicle-note-box b{color:var(--primary-strong)!important}
.claim-explain { margin:13px 0; padding:9px 10px; border-radius:10px; background:var(--danger-tint); color:var(--danger); font-size:11px; font-weight:800; line-height:1.7; }
.claim-order { width:100%; margin-top:14px; }
.claim-confirmation{display:grid;justify-items:center;gap:10px;padding:3px 1px 4px;text-align:center}.claim-confirmation-icon{display:grid;width:54px;height:54px;place-items:center;border-radius:18px;background:var(--success-tint);color:var(--success)}.claim-confirmation h4{margin:2px 0 0;color:var(--ink);font-size:16px;font-weight:900}.claim-confirmation p{margin:0;color:var(--ink-soft);font-size:11px;font-weight:750}.claim-confirmation-total{display:flex;align-items:center;justify-content:space-between;width:100%;box-sizing:border-box;margin-top:5px;padding:11px 13px;border-radius:12px;background:var(--surface-2);color:var(--ink-soft);font-size:11px;font-weight:800}.claim-confirmation-total b{color:var(--primary-strong);font-size:15px}.claim-confirmation-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;width:100%;margin-top:4px}.claim-confirmation-actions button{min-height:43px;border-radius:11px;font:900 12px var(--font);cursor:pointer}.claim-confirm-cancel{border:1px solid var(--border);background:var(--surface);color:var(--ink-soft)}.claim-confirm-submit{border:0;background:var(--primary);color:#fff}.claim-confirmation-actions button:disabled{cursor:wait;opacity:.65}
.customer-phone-locked{letter-spacing:1px;color:var(--ink-faint);font-size:12px}
.merchant-location-row{display:none}.merchant-pickup-location{display:grid;grid-template-columns:38px minmax(0,1fr);gap:9px;padding:10px;border:2px solid color-mix(in srgb,var(--success) 48%,var(--border));border-radius:12px;background:linear-gradient(135deg,color-mix(in srgb,var(--success-tint) 76%,var(--surface)),var(--surface));color:inherit;text-decoration:none;box-shadow:0 5px 13px rgba(11,110,104,.1)}.merchant-pickup-icon{display:grid;place-items:center;width:38px;height:38px;border-radius:11px;background:var(--success);color:#fff}.merchant-pickup-copy{display:grid;min-width:0;gap:2px}.merchant-pickup-copy small{color:var(--primary-strong);font-size:10px;font-weight:900}.merchant-pickup-copy b{overflow:hidden;color:var(--ink);font-size:12.5px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.merchant-pickup-copy em{overflow:hidden;color:var(--ink-soft);font-size:9.5px;font-style:normal;font-weight:700;line-height:1.45;text-overflow:ellipsis;white-space:normal}.merchant-pickup-open{display:flex;grid-column:1/-1;align-items:center;justify-content:center;gap:6px;min-height:39px;border-radius:9px;background:var(--primary);color:#fff;font-size:11px;font-weight:900;box-shadow:0 3px 8px rgba(11,110,104,.16)}.merchant-pickup-open svg{flex:none}

/* The acceptance deadline is intentionally high contrast in dark mode. */
html[data-theme="dark"] .available-order-footer{border-top-color:rgba(132,222,213,.2);background:#102b28}html[data-theme="dark"] .acceptance-clock{color:#f3d27d!important;font-weight:900}html[data-theme="dark"] .acceptance-clock b{padding:3px 7px;border-radius:7px;background:rgba(255,198,91,.16);color:#ffe0a0;text-shadow:0 1px 0 rgba(0,0,0,.35)}
html[data-theme="dark"] .courier-merchant-card{border-color:rgba(105,219,208,.34);background:#102b28!important}html[data-theme="dark"] .merchant-pickup-location{border-color:rgba(101,220,176,.5);background:#143831!important}html[data-theme="dark"] .merchant-pickup-copy small{color:#8ce3d7}html[data-theme="dark"] .merchant-pickup-copy b{color:#f1fffc}html[data-theme="dark"] .merchant-pickup-copy em{color:#bad9d4}
</style>
