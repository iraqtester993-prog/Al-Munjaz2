<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppShell from '../../Components/AppShell.vue'
import HeroSlider from '../../Components/HeroSlider.vue'
import SheetModal from '../../Components/SheetModal.vue'
import StatusBadge from '../../Components/StatusBadge.vue'
import { hasPickupLocation, pickupLocationLabel, pickupNavigationHref } from '../../Utils/pickupLocation'

const props = defineProps({
    stats: { type: Object, required: true },
    recentOrders: { type: Array, default: () => [] },
    availableOrders: { type: Array, default: () => [] },
    heroSlides: { type: Array, default: () => [] },
})

const page = usePage()
const user = computed(() => page.props.auth?.user)
const locale = computed(() => page.props.locale || 'ar')
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
    return `${String(minutes).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')} ${t('Minutes abbreviation')}`
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
        normal: t('Regular Delivery'),
        bike: t('Motorcycle'),
        sedan: t('Sedan'),
        suv: t('SUV'),
        truck: t('Van / Truck'),
    }[order.delivery_vehicle] || t('Regular Delivery')
}

function orderTypeLabel(order) {
    return order?.order_type || t('Not specified')
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
    return props.stats.onDuty && Number(props.stats.budget || 0) >= Number(order.price || 0)
}

// A new job is only a pickup offer. The customer's number becomes visible
// after the courier has moved the parcel into their custody, never while an
// offer is merely being reviewed.
function canViewCustomerPhone(order) {
    return Boolean(order?.courier_id)
        && ['courier', 'delivered', 'returned'].includes(order?.status)
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
    <AppShell :title="t('Good to see you')" :subtitle="user?.name">
        <template #title>
            {{ t('Good to see you') }}
            <span class="tb-sub">{{ user?.name || t('Courier') }}</span>
        </template>

        <HeroSlider :slides="heroSlides" />

        <section class="courier-collection" :class="{ offline: !stats.onDuty }">
            <span class="collection-orb"></span>
            <div class="collection-copy">
                <span>{{ t("Today's Collection") }}</span>
                <strong class="mono">{{ fmt(stats.collectedToday) }} <small>{{ t('IQD') }}</small></strong>
                <div class="collection-chips">
                    <span class="collection-chip">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17l4-8h4l3 8M10 9h4M15 6a1.4 1.4 0 1 0 0-2.8A1.4 1.4 0 0 0 15 6Z"/></svg>
                        {{ t('My Deliveries Today') }}: {{ stats.deliveredToday }}
                    </span>
                    <button class="collection-chip duty-chip" type="button" @click="toggleDuty">
                        <i :class="{ off: !stats.onDuty }"></i>{{ stats.onDuty ? t('Available for Work') : t('Currently Unavailable') }}
                    </button>
                </div>
            </div>
        </section>

        <div class="available-heading">
            <h3>{{ t('Available New Orders') }}</h3>
            <span>{{ t('Time left') }}: 30 {{ t('Minutes') }}</span>
        </div>

        <div v-if="visibleAvailableOrders.length" class="available-list">
            <article v-for="order in visibleAvailableOrders" :key="order.id" class="available-order-card" @click="openDetails(order)">
                <div class="available-order-main">
                    <div class="available-order-head">
                        <div>
                            <h4>{{ customerName(order) }}</h4>
                            <p><span class="mono">{{ order.track_no }}</span><b>•</b><span class="available-address">{{ customerAddress(order) }}</span></p>
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
                    <p v-if="order.vehicle_note || order.notes" class="available-order-note">
                        <b>{{ t('Order Note') }}:</b> {{ order.vehicle_note || order.notes }}
                    </p>
                </div>

                <footer class="available-order-footer">
                    <div class="pickup-clock" :style="{ color: countdownColor(order) }">
                        <i :style="{ background: countdownColor(order), boxShadow: `0 0 7px ${countdownColor(order)}` }"></i>
                        {{ t('Time to reach the merchant') }}: <b class="mono">{{ remainingText(order) }}</b>
                    </div>
                    <button v-if="canClaim(order)" type="button" class="view-order" @click.stop="openDetails(order)">{{ t('Order Details') }}</button>
                </footer>
                <div class="expiry-track"><i :style="{ width: `${progress(order)}%`, background: countdownColor(order) }"></i></div>
            </article>
        </div>
        <div v-else class="availability-empty">
            <span class="availability-empty-icon" aria-hidden="true">
                <svg width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="m8.5 12 2.3 2.3 4.8-5"/></svg>
            </span>
            <b>{{ t('No orders found') }}</b>
            <p>{{ t('Available New Orders') }}</p>
        </div>

        <section v-if="recentOrders.length" class="assigned-section">
            <div class="section-title">
                <h3>{{ t('Recent Deliveries') }}</h3>
                <a @click="$inertia.visit(route('app.orders'))">{{ t('See all') }}</a>
            </div>
            <div class="list-card">
                <button v-for="order in recentOrders" :key="order.id" class="courier-assigned-row" type="button" @click="$inertia.visit(route('app.orders'))">
                    <span class="assigned-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17l4-8h4l3 8M10 9h4M15 6a1.4 1.4 0 1 0 0-2.8A1.4 1.4 0 0 0 15 6Z"/></svg>
                    </span>
                    <span class="order-mid"><b>{{ customerName(order) }}</b><small class="mono">{{ order.track_no }}</small></span>
                    <span class="order-end"><b class="mono">{{ fmt(order.price) }}</b><StatusBadge :status="order.status" /></span>
                </button>
            </div>
        </section>

        <SheetModal :open="!!selected" :title="selected?.track_no" :subtitle="customerName(selected)" @close="selected = null">
            <template v-if="selected">
                <div class="detail-row"><span class="text-muted">{{ t('Customer') }}</span><b>{{ customerName(selected) }}</b></div>
                <div class="detail-row"><span class="text-muted">{{ t('Phone') }}</span><b v-if="canViewCustomerPhone(selected)" class="mono">{{ selected.phone }}</b><b v-else class="customer-phone-locked" :aria-label="t('Phone')">•••••••••••</b></div>
                <div class="detail-row"><span class="text-muted">{{ t('Address') }}</span><b>{{ customerAddress(selected) }}</b></div>
                <div class="detail-row"><span class="text-muted">{{ t('Order amount') }}</span><b class="mono">{{ fmt(selected.price) }} {{ t('IQD') }}</b></div>
                <div class="detail-row"><span class="text-muted">{{ t('Order Type') }}</span><b>{{ orderTypeLabel(selected) }}</b></div>
                <div class="detail-row"><span class="text-muted">{{ t('Delivery Vehicle') }}</span><b>{{ vehicleLabel(selected) }}</b></div>
                <div v-if="selected.vehicle_note || selected.notes" class="detail-row detail-note"><span class="text-muted">{{ t('Notes') }}</span><b>{{ selected.vehicle_note || selected.notes }}</b></div>
                <div class="detail-row"><span class="text-muted">{{ t('Available Budget') }}</span><b class="mono">{{ fmt(stats.budget) }} {{ t('IQD') }}</b></div>
                <div v-if="selected.pickup_deadline_at" class="detail-row"><span class="text-muted">{{ t('Time to reach the merchant') }}</span><b class="mono" :style="{ color: countdownColor(selected) }">{{ remainingText(selected) }}</b></div>

                <section v-if="hasPickupLocation(selected)" class="courier-pickup-location-card">
                    <div class="courier-pickup-location-head">
                        <span class="courier-pickup-location-icon" aria-hidden="true">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 5.2-8 11-8 11S4 15.2 4 10a8 8 0 1 1 16 0Z" /><circle cx="12" cy="10" r="2.5" /></svg>
                        </span>
                        <span><small>{{ t('Merchant pickup location') }}</small><b>{{ pickupLocationLabel(selected, t('Merchant pickup location')) }}</b></span>
                    </div>
                    <p>{{ t('The merchant saved this pickup point with the order.') }}</p>
                    <a v-if="selected.courier_id" class="courier-pickup-location-action" :href="pickupNavigationHref(selected)">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21 3-7.5 18-3.7-7.8L2 9.5 21 3Z" /><path d="m9.8 13.2 4.4-4.4" /></svg>
                        {{ t('Open navigation apps') }}
                    </a>
                    <span v-else class="courier-pickup-location-locked">{{ t('Accept the order to open navigation.') }}</span>
                </section>

                <section v-if="selected.merchant" class="courier-merchant-card">
                    <span class="merchant-card-label">{{ t('Merchant') }}</span>
                    <div class="merchant-card-profile">
                        <span class="merchant-avatar">{{ selected.merchant.name?.slice(0, 1) }}</span>
                        <span><b>{{ selected.merchant.name }}</b><small v-if="selected.merchant.address">{{ selected.merchant.address }}</small><small v-if="selected.merchant.phone" class="mono">{{ selected.merchant.phone }}</small></span>
                    </div>
                    <div class="merchant-card-actions">
                        <a v-if="whatsappUrl(selected.merchant.phone)" :href="whatsappUrl(selected.merchant.phone)" target="_blank" rel="noopener">{{ t('WhatsApp') }}</a>
                        <button v-if="selected.courier_id" type="button" @click="openOrderChat(selected)">{{ t('Chat') }}</button>
                    </div>
                </section>

                <a v-if="selected.status !== 'pending' && whatsappUrl(selected.phone)" class="customer-whatsapp" :href="whatsappUrl(selected.phone)" target="_blank" rel="noopener">{{ t('Customer WhatsApp') }}</a>
                <p v-if="!canClaim(selected)" class="claim-explain">{{ stats.onDuty ? t('Budget is lower than the order value.') : t('Enable availability before accepting the order.') }}</p>
                <button class="btn btn-primary claim-order" type="button" :disabled="!canClaim(selected) || claiming" @click="claim">
                    <span v-if="claiming" class="loader"></span><span v-else>{{ t('Accept Order') }}</span>
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
.available-order-main { padding:10px 12px 8px; }
.available-order-head { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
.available-order-head h4 { margin:0 0 2px; color:var(--ink); font-size:13px; font-weight:900; }
.available-order-head p { display:flex; align-items:center; gap:5px; min-width:0; margin:0; color:var(--ink-faint); font-size:9.5px; font-weight:700; }
.available-order-head p b { color:var(--ink-faint); }
.available-address{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.new-order-chip { flex:none; padding:3px 7px; border-radius:20px; background:var(--primary); color:#fff; box-shadow:0 2px 6px rgba(11,110,104,.2); font-size:8.5px; font-weight:900; }
.available-summary { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-top:7px; }
.available-summary > strong { color:var(--primary-strong); font-size:16px; font-weight:900; }
.available-summary > strong small { font-family:var(--font); color:var(--ink-faint); font-size:10px; }
.vehicle-badge { display:inline-flex; align-items:center; gap:5px; max-width:126px; padding:5px 8px; border:1px solid color-mix(in srgb, var(--primary) 26%, var(--border)); border-radius:9px; background:color-mix(in srgb, var(--primary-tint) 78%, var(--surface)); color:var(--primary-strong); font-size:9px; font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.available-order-note { margin:9px 0 0; padding:7px 9px; border:1px solid color-mix(in srgb,var(--danger) 18%,transparent); border-radius:10px; background:color-mix(in srgb,var(--danger-tint) 68%,var(--surface)); color:var(--ink-soft); font-size:10px; font-weight:750; line-height:1.55; }
.available-order-note b { color:var(--danger); }
.available-order-footer { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:7px 11px; border-top:1px solid var(--border); background:var(--surface-2); }
.pickup-clock { display:flex; align-items:center; gap:5px; min-width:0; font-size:9.5px; font-weight:900; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pickup-clock i { width:8px; height:8px; flex:none; border-radius:50%; animation:new-order-pulse 1.35s ease-in-out infinite; }
.view-order { padding:6px 9px; border-radius:8px; background:var(--primary); color:#fff; box-shadow:0 3px 8px rgba(11,110,104,.2); font:inherit; font-size:9px; font-weight:900; white-space:nowrap; }
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
.detail-note { align-items:flex-start; }
.courier-pickup-location-card{display:grid;gap:8px;margin-top:14px;padding:13px;border:1.5px solid color-mix(in srgb,var(--success) 42%,var(--border));border-radius:14px;background:linear-gradient(135deg,color-mix(in srgb,var(--success-tint) 74%,var(--surface)),var(--surface))}.courier-pickup-location-head{display:flex;align-items:center;gap:9px}.courier-pickup-location-icon{display:grid;place-items:center;width:37px;height:37px;flex:none;border-radius:11px;color:#fff;background:var(--success)}.courier-pickup-location-head span:last-child{display:grid;gap:2px;min-width:0}.courier-pickup-location-head small{color:var(--ink-faint);font-size:9.5px;font-weight:800}.courier-pickup-location-head b{overflow:hidden;color:var(--ink);font-size:12px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.courier-pickup-location-card p{margin:0;color:var(--ink-soft);font-size:10px;font-weight:700;line-height:1.55}.courier-pickup-location-action{display:flex;align-items:center;justify-content:center;gap:6px;min-height:37px;border-radius:10px;color:#fff;background:var(--primary);font-size:10.5px;font-weight:900;text-decoration:none;box-shadow:0 4px 10px rgba(11,110,104,.16)}.courier-pickup-location-locked{display:block;padding:8px 9px;border-radius:9px;color:var(--ink-soft);background:var(--surface-2);font-size:9.5px;font-weight:800;text-align:center}
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
.customer-phone-locked{letter-spacing:1px;color:var(--ink-faint);font-size:12px}
</style>
