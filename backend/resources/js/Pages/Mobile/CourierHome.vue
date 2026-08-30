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
        car: t('Sedan'),
        sedan: t('Sedan'),
        suv: t('SUV'),
        truck: t('Van / Truck'),
    }[order.delivery_vehicle] || t('Regular Delivery')
}

function orderTypeLabel(order) {
    return order?.order_type || t('Not specified')
}

const deliverySteps = computed(() => ([
    { status: 'pending', label: t('Pending') },
    { status: 'approved', label: t('Approved') },
    { status: 'courier', label: t('With Courier') },
    { status: 'delivered', label: t('Delivered') },
    { status: 'returned', label: t('Returned') },
]))

function deliveryStepIndex(order) {
    const index = deliverySteps.value.findIndex((step) => step.status === order?.status)

    return index < 0 ? 0 : index
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

function canViewCustomerPhone(order) {
    return Boolean(order?.phone_revealed || order?.phone)
}

function openDetails(order) {
    selected.value = order
}

function reopenLocationGateIfRequired(errors) {
    if (!errors?.location) return

    window.dispatchEvent(new CustomEvent('almunjaz:location-required'))
}

function claim() {
    if (!selected.value || claiming.value) return
    claiming.value = true
    router.post(route('app.orders.claim', selected.value.id), {}, {
        preserveScroll: true,
        onSuccess: () => (selected.value = null),
        onError: reopenLocationGateIfRequired,
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
                    <p v-if="order.vehicle_note" class="available-order-note available-vehicle-note"><b>{{ t('Vehicle Note') }}:</b> {{ order.vehicle_note }}</p>
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
                <a @click="$inertia.visit(route('app.orders', { list: 1 }))">{{ t('See all') }}</a>
            </div>
            <div class="list-card">
                <button v-for="order in recentOrders" :key="order.id" class="courier-assigned-row" type="button" @click="$inertia.visit(route('app.orders', { list: 1, open: order.id }))">
                    <span class="assigned-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17l4-8h4l3 8M10 9h4M15 6a1.4 1.4 0 1 0 0-2.8A1.4 1.4 0 0 0 15 6Z"/></svg>
                    </span>
                    <span class="order-mid"><b>{{ customerName(order) }}</b></span>
                    <span class="order-end"><b class="mono">{{ fmt(order.price) }}</b><StatusBadge :status="order.status" /></span>
                </button>
            </div>
        </section>

        <SheetModal :open="!!selected" @close="selected = null">
            <template v-if="selected">
                <section class="order-detail-status">
                    <div class="order-detail-status-head">
                        <span class="order-detail-icon" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8 12 3 3 8v8l9 5 9-5V8ZM3 8l9 5 9-5M12 13v8"/></svg>
                        </span>
                        <span class="order-detail-track mono">{{ selected.track_no }}</span>
                        <StatusBadge :status="selected.status" />
                    </div>
                    <div class="order-detail-steps" :style="{ '--active-step': deliveryStepIndex(selected) }">
                        <span v-for="(step, index) in deliverySteps" :key="step.status" class="order-detail-step" :class="{ active: index === deliveryStepIndex(selected), done: index < deliveryStepIndex(selected) }">
                            <i>{{ index + 1 }}</i><b>{{ step.label }}</b>
                        </span>
                    </div>
                </section>

                <section class="order-detail-section">
                    <h3>{{ t('Order Details') }}</h3>
                    <div class="detail-row"><span class="text-muted">{{ t('Customer') }}</span><b>{{ customerName(selected) }}</b></div>
                    <div class="detail-row"><span class="text-muted">{{ t('Phone') }}</span><b v-if="canViewCustomerPhone(selected)" class="mono">{{ selected.phone }}</b><b v-else class="customer-phone-locked" :aria-label="t('Phone')">•••••••••••</b></div>
                    <div class="detail-row"><span class="text-muted">{{ t('Address') }}</span><b>{{ customerAddress(selected) }}</b></div>
                    <div class="detail-row"><span class="text-muted">{{ t('Order Type') }}</span><b class="delivery-vehicle-pill">{{ orderTypeLabel(selected) }}</b></div>
                    <div class="detail-row"><span class="text-muted">{{ t('Delivery Vehicle') }}</span><b class="delivery-vehicle-pill">{{ vehicleLabel(selected) }}</b></div>
                    <div v-if="selected.notes" class="detail-note-box"><b>{{ t('Order Note') }}:</b> {{ selected.notes }}</div>
                    <div v-if="selected.vehicle_note" class="detail-note-box vehicle-note-box"><b>{{ t('Vehicle Note') }}:</b> {{ selected.vehicle_note }}</div>
                    <div class="detail-row detail-price"><span class="text-muted">{{ t('Order amount') }}</span><b class="mono">{{ fmt(selected.price) }} {{ t('IQD') }}</b></div>
                    <div class="detail-row"><span class="text-muted">{{ t('Available Budget') }}</span><b class="mono">{{ fmt(stats.budget) }} {{ t('IQD') }}</b></div>
                    <div v-if="selected.pickup_deadline_at" class="detail-row"><span class="text-muted">{{ t('Time to reach the merchant') }}</span><b class="mono" :style="{ color: countdownColor(selected) }">{{ remainingText(selected) }}</b></div>
                </section>

                <section v-if="selected.merchant" class="courier-merchant-card">
                    <span class="merchant-card-label">{{ t('Merchant') }}</span>
                    <div class="merchant-card-profile">
                        <span class="merchant-avatar">{{ selected.merchant.name?.slice(0, 1) }}</span>
                        <span>
                            <b>{{ selected.merchant.shop_name || selected.merchant.name }} <i v-if="selected.merchant.verified" class="merchant-verified" :title="t('Verified')">✓</i></b>
                            <small v-if="selected.merchant.address" class="merchant-info-row"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 10c0 5.2-8 11-8 11S4 15.2 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>{{ selected.merchant.address }}</small>
                            <small v-if="selected.merchant.phone" class="merchant-info-row mono"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7A2 2 0 0 1 22 16.9Z"/></svg>{{ selected.merchant.phone }}</small>
                        </span>
                    </div>
                    <div v-if="hasPickupLocation(selected)" class="merchant-location-row">
                        <span><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 10c0 5.2-8 11-8 11S4 15.2 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>{{ pickupLocationLabel(selected, t('Merchant pickup location')) }}</span>
                        <a :href="pickupNavigationHref(selected)">{{ t('Merchant location') }}</a>
                    </div>
                    <a
                        v-if="hasPickupLocation(selected)"
                        class="merchant-pickup-location"
                        :href="pickupNavigationHref(selected)"
                        :aria-label="`${t('Open navigation apps')}: ${pickupLocationLabel(selected, t('Merchant pickup location'))}`"
                    >
                        <span class="merchant-pickup-icon" aria-hidden="true"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 5.2-8 11-8 11S4 15.2 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg></span>
                        <span class="merchant-pickup-copy">
                            <small>{{ t('Merchant pickup location') }}</small>
                            <b>{{ pickupLocationLabel(selected, t('Merchant pickup location')) }}</b>
                            <em>{{ t('The merchant saved this pickup point with the order.') }}</em>
                        </span>
                        <span class="merchant-pickup-open"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 14-7-4 14-3-6-7-1Z"/><path d="m12 13 3-3"/></svg>{{ t('Open navigation apps') }}</span>
                    </a>
                    <div class="merchant-card-actions">
                        <a v-if="whatsappUrl(selected.merchant.phone)" :href="whatsappUrl(selected.merchant.phone)" target="_blank" rel="noopener">{{ t('WhatsApp') }}</a>
                        <button type="button" @click="openOrderChat(selected)">{{ t('Chat') }}</button>
                    </div>
                </section>

                <a v-if="selected.status !== 'pending' && whatsappUrl(selected.phone)" class="customer-whatsapp" :href="whatsappUrl(selected.phone)" target="_blank" rel="noopener">{{ t('Customer WhatsApp') }}</a>
                <p v-if="!canClaim(selected)" class="claim-explain">{{ stats.onDuty ? t('Budget is lower than the order value.') : t('Enable availability before accepting the order.') }}</p>
                <button class="btn btn-primary claim-order" type="button" :disabled="!canClaim(selected) || claiming" @click="claim">
                    <span v-if="claiming" class="loader"></span><span v-else>{{ t('Accept Order') }}</span>
                </button>
                <button class="btn order-detail-close" type="button" @click="selected = null">{{ t('Close') }}</button>
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
.pickup-clock { display:flex; align-items:center; gap:5px; min-width:0; font-size:11px; font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pickup-clock i { width:8px; height:8px; flex:none; border-radius:50%; animation:new-order-pulse 1.35s ease-in-out infinite; }
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
.customer-phone-locked{letter-spacing:1px;color:var(--ink-faint);font-size:12px}
.merchant-location-row{display:none}.merchant-pickup-location{display:grid;grid-template-columns:38px minmax(0,1fr);gap:9px;padding:10px;border:1.5px solid color-mix(in srgb,var(--success) 45%,var(--border));border-radius:12px;background:linear-gradient(135deg,color-mix(in srgb,var(--success-tint) 76%,var(--surface)),var(--surface));color:inherit;text-decoration:none;box-shadow:0 3px 9px rgba(11,110,104,.06)}.merchant-pickup-icon{display:grid;place-items:center;width:38px;height:38px;border-radius:11px;background:var(--success);color:#fff}.merchant-pickup-copy{display:grid;min-width:0;gap:2px}.merchant-pickup-copy small{color:var(--ink-faint);font-size:9px;font-weight:850}.merchant-pickup-copy b{overflow:hidden;color:var(--ink);font-size:11.5px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.merchant-pickup-copy em{overflow:hidden;color:var(--ink-soft);font-size:9.5px;font-style:normal;font-weight:700;line-height:1.45;text-overflow:ellipsis;white-space:nowrap}.merchant-pickup-open{display:flex;grid-column:1/-1;align-items:center;justify-content:center;gap:6px;min-height:35px;border-radius:9px;background:var(--primary);color:#fff;font-size:10.5px;font-weight:900;box-shadow:0 3px 8px rgba(11,110,104,.16)}.merchant-pickup-open svg{flex:none}
</style>
