<script setup>
import { computed, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'
import BranchFilter from '../../Components/BranchFilter.vue'
import CourierLocationsMap from '../../Components/CourierLocationsMap.vue'

const props = defineProps({
    couriers: { type: Array, default: () => [] },
    branchFilter: { type: Object, default: () => ({}) },
})

const page = usePage()
const query = ref('')
const selectedCourierId = ref(null)
const refreshing = ref(false)
const showAllLocationsMap = ref(false)
const locale = computed(() => page.props.locale || 'ar')
const mapTheme = computed(() => {
    if (typeof document === 'undefined') return 'light'
    return document.documentElement.dataset.theme || document.body.dataset.theme || 'light'
})

const visibleCouriers = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase()

    if (!needle) return props.couriers

    return props.couriers.filter((courier) => [courier.name, courier.phone, courier.role]
        .filter(Boolean)
        .join(' ')
        .toLocaleLowerCase()
        .includes(needle))
})

const selectedCourier = computed(() => visibleCouriers.value.find((courier) => courier.id === selectedCourierId.value)
    || visibleCouriers.value[0]
    || null)
const mappedCourierCount = computed(() => props.couriers.filter((courier) => courier.location).length)

function courierRoleLabel(role) {
    return {
        courier: t('Courier'),
        pickup_courier: t('Pickup courier'),
        delivery_courier: t('Delivery courier'),
        transporter: t('Transporter'),
    }[role] || role
}

function locationUpdatedAt(location) {
    if (!location?.updated_at) return '—'

    try {
        const language = { ar: 'ar-IQ-u-nu-latn', en: 'en-US', ku: 'ku-IQ-u-nu-latn' }[locale.value] || 'en-US'
        return new Intl.DateTimeFormat(language, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(location.updated_at))
    } catch (_) {
        return location.updated_at
    }
}

function locationAccuracy(location) {
    if (location?.accuracy_meters === null || location?.accuracy_meters === undefined) return t('Not specified')
    return `${fmt(location.accuracy_meters)} ${t('meters')}`
}

function mapEmbedUrl(location) {
    if (!location) return ''

    const latitude = Number(location.latitude)
    const longitude = Number(location.longitude)
    const spread = 0.009
    const bbox = [longitude - spread, latitude - spread, longitude + spread, latitude + spread].join(',')
    const marker = `${latitude},${longitude}`

    return `https://www.openstreetmap.org/export/embed.html?bbox=${encodeURIComponent(bbox)}&layer=mapnik&marker=${encodeURIComponent(marker)}`
}

function mapPageUrl(location) {
    if (!location) return '#'

    const latitude = Number(location.latitude)
    const longitude = Number(location.longitude)
    return `https://www.openstreetmap.org/?mlat=${encodeURIComponent(latitude)}&mlon=${encodeURIComponent(longitude)}#map=16/${latitude}/${longitude}`
}

function locationLabel(location) {
    return location?.address_label || t('Current location')
}

function refresh() {
    refreshing.value = true
    router.reload({
        only: ['couriers'],
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { refreshing.value = false },
    })
}

function changeBranchFilter(branchId) {
    selectedCourierId.value = null
    router.get(route('admin.couriers.locations'), branchId ? { branch_id: branchId } : {}, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    })
}

watch(visibleCouriers, (couriers) => {
    if (!couriers.some((courier) => courier.id === selectedCourierId.value)) {
        selectedCourierId.value = couriers[0]?.id || null
    }
}, { immediate: true })
</script>

<template>
    <AdminShell title="Courier locations">
        <section class="locations-heading">
            <div>
                <p class="eyebrow">{{ t('Operations') }}</p>
                <h2>{{ t('Courier locations') }}</h2>
                <p>{{ t('Last known positions only — no route history is recorded.') }}</p>
            </div>
            <div class="locations-actions">
                <BranchFilter :filter="branchFilter" @change="changeBranchFilter" />
                <button
                    class="all-locations-button"
                    type="button"
                    :disabled="!mappedCourierCount"
                    @click="showAllLocationsMap = true"
                >
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s7-5.2 7-12A7 7 0 1 0 5 9c0 6.8 7 12 7 12Z" /><circle cx="12" cy="9" r="2.2" /><path d="M4 19h4M16 19h4" /></svg>
                    {{ t('Show all locations on map') }}
                    <span class="all-locations-count">{{ fmt(mappedCourierCount) }}</span>
                </button>
                <button class="refresh-button" type="button" :disabled="refreshing" @click="refresh">
                    <span aria-hidden="true">↻</span>{{ refreshing ? '…' : t('Refresh') }}
                </button>
            </div>
        </section>

        <section class="locations-panel panel">
            <aside class="courier-directory" :aria-label="t('Couriers')">
                <label class="search-field">
                    <span class="sr-only">{{ t('Search') }}</span>
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6" /><path d="m20 20-4.2-4.2" /></svg>
                    <input v-model="query" type="search" :placeholder="t('Search')" />
                </label>

                <div v-if="visibleCouriers.length" class="courier-list">
                    <button
                        v-for="courier in visibleCouriers"
                        :key="courier.id"
                        type="button"
                        class="courier-row"
                        :class="{ active: selectedCourier?.id === courier.id }"
                        @click="selectedCourierId = courier.id"
                    >
                        <span class="courier-avatar">{{ courier.name?.slice(0, 1) }}</span>
                        <span class="courier-copy">
                            <b>{{ courier.name }}</b>
                            <small>{{ courierRoleLabel(courier.role) }} · {{ courier.location ? locationUpdatedAt(courier.location) : t('Location unavailable') }}</small>
                        </span>
                        <span v-if="courier.location" class="location-dot" :class="{ online: courier.is_online }" :title="courier.is_online ? t('Online') : t('Offline')" />
                    </button>
                </div>

                <div v-else class="directory-empty">
                    <span aria-hidden="true">⌕</span>
                    <p>{{ query ? t('No couriers match your search.') : t('No courier location is available yet.') }}</p>
                </div>
            </aside>

            <main v-if="selectedCourier" class="location-detail">
                <header class="location-detail-head">
                    <div class="detail-avatar">{{ selectedCourier.name?.slice(0, 1) }}</div>
                    <div class="detail-copy">
                        <h3>{{ selectedCourier.name }}</h3>
                        <p>{{ courierRoleLabel(selectedCourier.role) }}<template v-if="selectedCourier.phone"> · <span dir="ltr">{{ selectedCourier.phone }}</span></template></p>
                    </div>
                    <span class="presence-chip" :class="{ online: selectedCourier.is_online }">{{ selectedCourier.is_online ? t('Online') : t('Offline') }}</span>
                </header>

                <template v-if="selectedCourier.location">
                    <div class="map-wrap">
                        <iframe
                            class="location-map"
                            :src="mapEmbedUrl(selectedCourier.location)"
                            :title="`${t('Courier locations')} — ${selectedCourier.name}`"
                            loading="lazy"
                            referrerpolicy="no-referrer"
                        />
                        <div class="map-caption">
                            <div>
                                <b>{{ locationLabel(selectedCourier.location) }}</b>
                                <small>{{ t('Last update') }}: {{ locationUpdatedAt(selectedCourier.location) }} · {{ t('Accuracy') }}: {{ locationAccuracy(selectedCourier.location) }}</small>
                            </div>
                            <a :href="mapPageUrl(selectedCourier.location)" target="_blank" rel="noopener noreferrer">{{ t('Open map') }}</a>
                        </div>
                    </div>
                </template>

                <section v-else class="location-unavailable">
                    <div aria-hidden="true">⌖</div>
                    <h3>{{ t('Location unavailable') }}</h3>
                    <p>{{ t('This courier has not shared a recent location from their account.') }}</p>
                </section>
            </main>

            <main v-else class="location-unavailable location-unavailable-empty">
                <div aria-hidden="true">⌖</div>
                <h3>{{ t('No courier location is available yet.') }}</h3>
                <p>{{ t('A courier appears here only after they enable location sharing from their account.') }}</p>
            </main>
        </section>

        <CourierLocationsMap
            v-if="showAllLocationsMap"
            :couriers="couriers"
            :locale="locale"
            :theme="mapTheme"
            @close="showAllLocationsMap = false"
            @select="selectedCourierId = $event.id"
        />
    </AdminShell>
</template>

<style scoped>
.locations-heading{display:flex;align-items:end;justify-content:space-between;gap:18px;margin:0 0 21px}.eyebrow{margin:0 0 3px;color:var(--primary);font-size:10px;font-weight:900;letter-spacing:.1em;text-transform:uppercase}.locations-heading h2{margin:0;color:var(--ink);font-size:24px;font-weight:950}.locations-heading p{max-width:640px;margin:5px 0 0;color:var(--ink-faint);font-size:11.5px;font-weight:700;line-height:1.75}.locations-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px}.locations-actions :deep(.branch-filter){flex:0 1 245px}.refresh-button,.all-locations-button{min-height:39px;display:inline-flex;align-items:center;justify-content:center;gap:7px;flex:none;padding:8px 12px;border:1px solid var(--border);border-radius:10px;font:850 11px var(--font);cursor:pointer}.refresh-button{color:var(--primary-strong);background:var(--surface)}.refresh-button span{font-size:16px;line-height:1}.refresh-button:disabled{cursor:wait;opacity:.62}.all-locations-button{border-color:color-mix(in srgb,var(--primary) 38%,var(--border));color:#fff;background:var(--primary)}.all-locations-button:disabled{cursor:not-allowed;opacity:.52}.all-locations-count{display:grid;min-width:18px;min-height:18px;place-items:center;padding:0 3px;border-radius:999px;color:var(--primary-strong);background:#fff;font-size:9px}.locations-panel{min-height:590px;display:grid;grid-template-columns:minmax(245px,.43fr) minmax(0,1.57fr);overflow:hidden}.courier-directory{min-width:0;display:flex;flex-direction:column;border-inline-end:1px solid var(--border);background:var(--surface)}.search-field{display:flex;align-items:center;gap:8px;margin:13px;padding:8px 10px;border:1px solid var(--border);border-radius:10px;color:var(--ink-faint);background:var(--surface-2)}.search-field input{width:100%;min-width:0;border:0;outline:0;color:var(--ink);background:transparent;font:700 11px var(--font)}.search-field input::placeholder{color:var(--ink-faint)}.courier-list{overflow:auto}.courier-row{width:100%;display:flex;align-items:center;gap:10px;padding:12px 14px;border:0;border-top:1px solid var(--border);color:var(--ink);background:transparent;font:inherit;text-align:start;cursor:pointer;transition:background .15s}.courier-row:hover,.courier-row.active{background:var(--primary-tint)}.courier-avatar,.detail-avatar{display:grid;place-items:center;flex:none;border-radius:12px;color:var(--primary-strong);background:var(--primary-tint);font-weight:950}.courier-avatar{width:35px;height:35px;font-size:13px}.courier-row.active .courier-avatar{color:#fff;background:var(--primary)}.courier-copy{display:grid;min-width:0;flex:1;gap:2px}.courier-copy b,.courier-copy small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.courier-copy b{font-size:11.5px;font-weight:900}.courier-copy small{color:var(--ink-faint);font-size:9.5px;font-weight:700}.location-dot{width:9px;height:9px;flex:none;border-radius:50%;background:#f59e0b;box-shadow:0 0 0 4px var(--warning-tint)}.location-dot.online{background:var(--success);box-shadow:0 0 0 4px var(--success-tint)}.directory-empty{display:grid;place-items:center;gap:8px;min-height:210px;padding:24px;color:var(--ink-faint);text-align:center}.directory-empty span{font-size:28px}.directory-empty p{margin:0;font-size:11px;font-weight:750;line-height:1.7}.location-detail{min-width:0;display:grid;grid-template-rows:auto minmax(0,1fr);background:var(--surface-2)}.location-detail-head{display:flex;align-items:center;gap:11px;padding:15px 17px;border-bottom:1px solid var(--border);background:var(--surface)}.detail-avatar{width:42px;height:42px;font-size:16px}.detail-copy{display:grid;min-width:0;flex:1;gap:2px}.detail-copy h3,.detail-copy p{margin:0}.detail-copy h3{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--ink);font-size:14px;font-weight:950}.detail-copy p{overflow:hidden;color:var(--ink-faint);font-size:10px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}.presence-chip{flex:none;padding:5px 8px;border-radius:999px;color:#a16207;background:var(--warning-tint);font-size:9px;font-weight:900}.presence-chip.online{color:#047857;background:var(--success-tint)}.map-wrap{position:relative;min-height:0}.location-map{display:block;width:100%;height:100%;min-height:500px;border:0;background:var(--surface-2)}.map-caption{position:absolute;z-index:2;right:14px;bottom:14px;left:14px;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 12px;border:1px solid color-mix(in srgb,var(--border) 88%,transparent);border-radius:12px;background:color-mix(in srgb,var(--surface) 93%,transparent);box-shadow:0 10px 26px rgba(0,0,0,.17);backdrop-filter:blur(8px)}.map-caption>div{display:grid;min-width:0;gap:3px}.map-caption b,.map-caption small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.map-caption b{color:var(--ink);font-size:11px;font-weight:950}.map-caption small{color:var(--ink-soft);font-size:9px;font-weight:700}.map-caption a{flex:none;padding:7px 9px;border-radius:8px;color:#fff;background:var(--primary);font-size:9.5px;font-weight:900;text-decoration:none}.location-unavailable{display:grid;place-content:center;justify-items:center;gap:9px;padding:28px;color:var(--ink-soft);text-align:center}.location-unavailable>div{display:grid;place-items:center;width:48px;height:48px;border-radius:15px;color:var(--primary-strong);background:var(--primary-tint);font-size:25px}.location-unavailable h3,.location-unavailable p{margin:0}.location-unavailable h3{color:var(--ink);font-size:14px;font-weight:950}.location-unavailable p{max-width:330px;color:var(--ink-faint);font-size:10.5px;font-weight:700;line-height:1.75}.location-unavailable-empty{min-height:530px;background:var(--surface-2)}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}@media(max-width:820px){.locations-panel{min-height:0;grid-template-columns:1fr}.courier-directory{border-inline-end:0;border-bottom:1px solid var(--border)}.courier-list{display:flex;overflow:auto}.courier-row{min-width:210px;border-top:0;border-inline-end:1px solid var(--border)}.location-map{min-height:390px}.location-detail{min-height:455px}.location-unavailable-empty{min-height:350px}}@media(max-width:620px){.locations-heading{align-items:start;flex-direction:column}.locations-actions{width:100%;justify-content:stretch}.locations-actions :deep(.branch-filter){flex:1 1 100%}.refresh-button,.all-locations-button{flex:1}.locations-heading h2{font-size:21px}.search-field{margin:11px}.courier-row{min-width:190px;padding:11px}.location-detail-head{padding:13px}.map-caption{right:9px;bottom:9px;left:9px}.map-caption small{max-width:190px}.location-map{min-height:370px}}
</style>
