<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

/**
 * A focused operational map for the latest courier positions visible to the
 * current dashboard actor. The parent already receives a server-filtered
 * roster, so this component never fetches or derives a wider location scope.
 *
 * Leaflet is loaded only after opening the map. This keeps the ordinary
 * dashboard navigation light and avoids bundling an additional map package.
 */
const props = defineProps({
    couriers: { type: Array, default: () => [] },
    locale: { type: String, default: 'ar' },
    theme: { type: String, default: 'light' },
})

const emit = defineEmits(['close', 'select'])

const mapElement = ref(null)
const mapLoading = ref(false)
const mapFallback = ref(false)
const query = ref('')
const selectedCourierId = ref(null)

let leafletMap = null
let leafletMarkers = new Map()
let leafletMarkerLayer = null

const locatedCouriers = computed(() => props.couriers.filter((courier) => validLocation(courier?.location)))
const filteredCouriers = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase()

    if (!needle) return locatedCouriers.value

    return locatedCouriers.value.filter((courier) => [courier.name, courier.phone, courier.role, courier.location?.address_label]
        .filter(Boolean)
        .join(' ')
        .toLocaleLowerCase()
        .includes(needle))
})
const selectedCourier = computed(() => filteredCouriers.value.find((courier) => courier.id === selectedCourierId.value)
    || filteredCouriers.value[0]
    || null)
const locationCountLabel = computed(() => t(':count current locations', { count: formatNumber(filteredCouriers.value.length) }))

function validLocation(location) {
    const latitude = Number(location?.latitude)
    const longitude = Number(location?.longitude)

    return Number.isFinite(latitude)
        && Number.isFinite(longitude)
        && latitude >= -90
        && latitude <= 90
        && longitude >= -180
        && longitude <= 180
}

function formatNumber(value) {
    return new Intl.NumberFormat('en-US', {
        numberingSystem: 'latn',
        maximumFractionDigits: 0,
    }).format(Number(value || 0))
}

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
        const language = { ar: 'ar-IQ-u-nu-latn', en: 'en-US', ku: 'ku-IQ-u-nu-latn' }[props.locale] || 'en-US'
        return new Intl.DateTimeFormat(language, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(location.updated_at))
    } catch (_) {
        return location.updated_at
    }
}

function locationAccuracy(location) {
    if (location?.accuracy_meters === null || location?.accuracy_meters === undefined) return t('Not specified')
    return `${formatNumber(location.accuracy_meters)} ${t('meters')}`
}

function locationLabel(courier) {
    return courier?.location?.address_label || t('Current location')
}

function mapPageUrl(courier) {
    if (!validLocation(courier?.location)) return '#'

    const latitude = Number(courier.location.latitude)
    const longitude = Number(courier.location.longitude)
    return `https://www.openstreetmap.org/?mlat=${encodeURIComponent(latitude)}&mlon=${encodeURIComponent(longitude)}#map=16/${latitude}/${longitude}`
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    }[character]))
}

function courierInitial(courier) {
    return String(courier?.name || '?').trim().slice(0, 1) || '?'
}

function popupContent(courier) {
    const wrapper = document.createElement('div')
    wrapper.className = 'courier-all-map-popup'
    wrapper.dir = props.locale === 'en' ? 'ltr' : 'rtl'

    const name = document.createElement('strong')
    name.textContent = courier.name || t('Courier')
    wrapper.appendChild(name)

    const role = document.createElement('small')
    role.textContent = courierRoleLabel(courier.role)
    wrapper.appendChild(role)

    const address = document.createElement('span')
    address.textContent = locationLabel(courier)
    wrapper.appendChild(address)

    const meta = document.createElement('small')
    meta.textContent = `${t('Last update')}: ${locationUpdatedAt(courier.location)} · ${t('Accuracy')}: ${locationAccuracy(courier.location)}`
    wrapper.appendChild(meta)

    return wrapper
}

function markerIcon(courier) {
    const status = courier.is_online ? 'online' : 'offline'
    const initial = escapeHtml(courierInitial(courier))

    return window.L.divIcon({
        className: 'courier-all-map-marker-shell',
        html: `<span class="courier-all-map-marker ${status}" aria-hidden="true"><i>${initial}</i></span>`,
        iconSize: [38, 46],
        iconAnchor: [19, 43],
        popupAnchor: [0, -40],
    })
}

function selectCourier(courier, { pan = true, openPopup = true } = {}) {
    if (!courier) return

    selectedCourierId.value = courier.id
    emit('select', courier)

    const marker = leafletMarkers.get(courier.id)
    if (!marker || !leafletMap) return

    if (pan) leafletMap.flyTo(marker.getLatLng(), Math.max(leafletMap.getZoom(), 15), { animate: true, duration: 0.35 })
    if (openPopup) marker.openPopup()
}

function drawMarkers({ fit = true } = {}) {
    if (!leafletMap || !window.L) return

    if (leafletMarkerLayer) leafletMarkerLayer.remove()
    leafletMarkerLayer = window.L.layerGroup().addTo(leafletMap)
    leafletMarkers = new Map()

    const bounds = []
    filteredCouriers.value.forEach((courier) => {
        const latitude = Number(courier.location.latitude)
        const longitude = Number(courier.location.longitude)
        const marker = window.L.marker([latitude, longitude], { icon: markerIcon(courier) })
            .bindPopup(popupContent(courier), { maxWidth: 260, autoPanPadding: [24, 24] })
            .on('click', () => selectCourier(courier, { pan: false, openPopup: false }))
            .addTo(leafletMarkerLayer)

        leafletMarkers.set(courier.id, marker)
        bounds.push([latitude, longitude])
    })

    if (!bounds.length) return

    if (fit) {
        if (bounds.length === 1) {
            leafletMap.setView(bounds[0], 15)
        } else {
            leafletMap.fitBounds(bounds, { padding: [38, 38], maxZoom: 14, animate: false })
        }
    }

    if (!selectedCourier.value || !leafletMarkers.has(selectedCourier.value.id)) {
        selectedCourierId.value = filteredCouriers.value[0]?.id || null
    }
}

async function initializeMap() {
    if (!mapElement.value || leafletMap || !filteredCouriers.value.length) return

    mapLoading.value = true
    mapFallback.value = false

    try {
        const L = await loadLeaflet()
        if (!mapElement.value) return

        leafletMap = L.map(mapElement.value, {
            zoomControl: true,
            attributionControl: true,
            scrollWheelZoom: true,
        })

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors',
        }).addTo(leafletMap)

        drawMarkers()
        requestAnimationFrame(() => leafletMap?.invalidateSize())
    } catch (_) {
        // The visible roster remains usable if a restrictive network or CSP
        // blocks the optional map library or its tile provider.
        mapFallback.value = true
    } finally {
        mapLoading.value = false
    }
}

function destroyMap() {
    if (leafletMap) leafletMap.remove()
    leafletMap = null
    leafletMarkerLayer = null
    leafletMarkers = new Map()
}

function close() {
    emit('close')
}

function onKeydown(event) {
    if (event.key === 'Escape') close()
}

watch(filteredCouriers, (couriers) => {
    if (!couriers.some((courier) => courier.id === selectedCourierId.value)) {
        selectedCourierId.value = couriers[0]?.id || null
    }

    if (leafletMap) drawMarkers()
}, { immediate: true })

onMounted(() => {
    document.addEventListener('keydown', onKeydown)
    nextTick(() => initializeMap())
})

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown)
    destroyMap()
})

let leafletLoader

function loadLeaflet() {
    if (typeof window === 'undefined') return Promise.reject(new Error('Window is unavailable'))
    if (window.L?.map) return Promise.resolve(window.L)
    if (leafletLoader) return leafletLoader

    leafletLoader = new Promise((resolve, reject) => {
        const stylesheetId = 'almunjaz-leaflet-stylesheet'
        if (!document.getElementById(stylesheetId)) {
            const stylesheet = document.createElement('link')
            stylesheet.id = stylesheetId
            stylesheet.rel = 'stylesheet'
            stylesheet.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'
            stylesheet.integrity = 'sha256-p4NxAoJBhIINfQHzt98hYfNfBihKHwhp1Z3KpFx3D9k='
            stylesheet.crossOrigin = ''
            document.head.appendChild(stylesheet)
        }

        const resolveWhenAvailable = () => {
            if (window.L?.map) {
                resolve(window.L)
                return true
            }

            return false
        }

        const scriptId = 'almunjaz-leaflet-script'
        const existingScript = document.getElementById(scriptId)
        if (existingScript) {
            if (resolveWhenAvailable()) return

            const timeout = window.setTimeout(() => reject(new Error('Leaflet unavailable')), 12000)
            existingScript.addEventListener('load', () => {
                window.clearTimeout(timeout)
                resolveWhenAvailable() || reject(new Error('Leaflet unavailable'))
            }, { once: true })
            existingScript.addEventListener('error', () => {
                window.clearTimeout(timeout)
                reject(new Error('Leaflet failed to load'))
            }, { once: true })
            return
        }

        const script = document.createElement('script')
        script.id = scriptId
        script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'
        script.integrity = 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo='
        script.crossOrigin = ''
        script.async = true
        script.onload = () => resolveWhenAvailable() || reject(new Error('Leaflet unavailable'))
        script.onerror = () => reject(new Error('Leaflet failed to load'))
        document.head.appendChild(script)
    })

    return leafletLoader
}
</script>

<template>
    <Teleport to="body">
        <div class="courier-all-map-overlay" :class="{ 'theme-dark': theme === 'dark' }" role="presentation" @click.self="close">
            <section class="courier-all-map-dialog" role="dialog" aria-modal="true" :aria-label="t('All courier locations')">
                <header class="courier-all-map-header">
                    <div>
                        <p>{{ t('Courier locations') }}</p>
                        <h2>{{ t('All courier locations') }}</h2>
                        <small>{{ locationCountLabel }}</small>
                    </div>
                    <button type="button" class="courier-all-map-close" :aria-label="t('Close')" @click="close">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
                    </button>
                </header>

                <div v-if="locatedCouriers.length" class="courier-all-map-body">
                    <aside class="courier-all-map-directory" :aria-label="t('Courier locations')">
                        <label class="courier-all-map-search">
                            <span class="sr-only">{{ t('Search') }}</span>
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6" /><path d="m20 20-4.2-4.2" /></svg>
                            <input v-model="query" type="search" :placeholder="t('Search couriers on map')" />
                        </label>

                        <div v-if="filteredCouriers.length" class="courier-all-map-list">
                            <button
                                v-for="courier in filteredCouriers"
                                :key="courier.id"
                                type="button"
                                class="courier-all-map-row"
                                :class="{ active: selectedCourier?.id === courier.id }"
                                @click="selectCourier(courier)"
                            >
                                <span class="courier-all-map-avatar" :class="{ online: courier.is_online }">{{ courierInitial(courier) }}</span>
                                <span>
                                    <b>{{ courier.name }}</b>
                                    <small>{{ courierRoleLabel(courier.role) }} · {{ locationUpdatedAt(courier.location) }}</small>
                                    <em>{{ locationLabel(courier) }}</em>
                                </span>
                            </button>
                        </div>
                        <div v-else class="courier-all-map-empty-list">
                            <span aria-hidden="true">⌕</span>
                            <p>{{ t('No fresh courier locations match your search.') }}</p>
                        </div>
                    </aside>

                    <div class="courier-all-map-stage">
                        <div ref="mapElement" v-show="!mapFallback" class="courier-all-map-canvas" :aria-label="t('All courier locations')" />
                        <div v-if="mapLoading" class="courier-all-map-loading"><i />{{ t('Loading map…') }}</div>
                        <div v-if="mapFallback" class="courier-all-map-fallback">
                            <span aria-hidden="true">⌖</span>
                            <h3>{{ t('Map could not be loaded.') }}</h3>
                            <p>{{ t('You can still select a courier and open their current location.') }}</p>
                        </div>

                        <footer v-if="selectedCourier" class="courier-all-map-caption">
                            <div>
                                <b>{{ selectedCourier.name }}</b>
                                <small>{{ locationLabel(selectedCourier) }} · {{ t('Last update') }}: {{ locationUpdatedAt(selectedCourier.location) }}</small>
                            </div>
                            <a :href="mapPageUrl(selectedCourier)" target="_blank" rel="noopener noreferrer">{{ t('Open map') }}</a>
                        </footer>
                    </div>
                </div>

                <div v-else class="courier-all-map-empty">
                    <span aria-hidden="true">⌖</span>
                    <h3>{{ t('No courier location is available yet.') }}</h3>
                    <p>{{ t('A courier appears here only after they enable location sharing from their account.') }}</p>
                </div>
            </section>
        </div>
    </Teleport>
</template>

<style>
.courier-all-map-overlay{--surface:#fff;--surface-2:#f6faf9;--ink:#102a43;--ink-soft:#5b7481;--ink-faint:#8ca0a9;--border:rgba(16,42,67,.12);--primary:#087b73;--primary-strong:#05645e;--primary-tint:rgba(8,123,115,.1);--success:#059669;--success-tint:rgba(5,150,105,.12);--danger:#dc5a50;--warning-tint:rgba(201,131,22,.13);position:fixed;z-index:12000;inset:0;display:grid;place-items:center;padding:16px;background:rgba(4,20,24,.68);backdrop-filter:blur(4px)}.courier-all-map-overlay.theme-dark{--surface:#16213a;--surface-2:#1d2a47;--ink:#e6edf7;--ink-soft:#b5c3d8;--ink-faint:#8494ab;--border:rgba(211,224,242,.14);--primary:#3db6aa;--primary-strong:#7ee3d8;--primary-tint:rgba(61,182,170,.15);--success:#56d5a6;--success-tint:rgba(86,213,166,.15);--danger:#ff948d;--warning-tint:rgba(244,188,80,.16)}.courier-all-map-dialog{display:flex;flex-direction:column;width:min(1120px,100%);max-height:calc(100dvh - 32px);overflow:hidden;border:1px solid color-mix(in srgb,var(--border) 75%,#fff 25%);border-radius:22px;background:var(--surface);box-shadow:0 26px 80px rgba(0,0,0,.35);color:var(--ink);font-family:var(--font,inherit)}.courier-all-map-header{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding:18px 20px 16px;border-bottom:1px solid var(--border)}.courier-all-map-header p,.courier-all-map-header h2,.courier-all-map-header small{display:block;margin:0}.courier-all-map-header p{color:var(--primary);font-size:10px;font-weight:950;letter-spacing:.08em;text-transform:uppercase}.courier-all-map-header h2{margin-top:4px;font-size:18px;font-weight:950}.courier-all-map-header small{margin-top:4px;color:var(--ink-faint);font-size:10px;font-weight:750}.courier-all-map-close{display:grid;place-items:center;width:38px;height:38px;flex:none;padding:0;border:0;border-radius:12px;color:#fff;background:var(--danger);cursor:pointer}.courier-all-map-body{display:grid;grid-template-columns:minmax(240px,.39fr) minmax(0,1.61fr);min-height:min(640px,calc(100dvh - 170px))}.courier-all-map-directory{display:flex;min-width:0;flex-direction:column;border-inline-end:1px solid var(--border);background:var(--surface)}.courier-all-map-search{display:flex;align-items:center;gap:8px;margin:13px;padding:9px 10px;border:1px solid var(--border);border-radius:10px;color:var(--ink-faint);background:var(--surface-2)}.courier-all-map-search input{width:100%;min-width:0;border:0;outline:0;color:var(--ink);background:transparent;font:700 11px var(--font,inherit)}.courier-all-map-list{overflow:auto}.courier-all-map-row{display:flex;align-items:center;gap:10px;width:100%;padding:12px 13px;border:0;border-top:1px solid var(--border);background:transparent;color:var(--ink);font:inherit;text-align:start;cursor:pointer;transition:background .15s}.courier-all-map-row:hover,.courier-all-map-row.active{background:var(--primary-tint)}.courier-all-map-avatar{display:grid;place-items:center;width:34px;height:34px;flex:none;border-radius:11px;color:#a16207;background:var(--warning-tint);font-size:12px;font-weight:950}.courier-all-map-avatar.online{color:#047857;background:var(--success-tint)}.courier-all-map-row>span:last-child{display:grid;min-width:0;flex:1;gap:2px}.courier-all-map-row b,.courier-all-map-row small,.courier-all-map-row em{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.courier-all-map-row b{font-size:11.5px;font-weight:900}.courier-all-map-row small{color:var(--ink-faint);font-size:9.5px;font-weight:700}.courier-all-map-row em{color:var(--primary-strong);font-size:9px;font-style:normal;font-weight:750}.courier-all-map-stage{position:relative;min-width:0;min-height:0;background:var(--surface-2)}.courier-all-map-canvas{width:100%;height:100%;min-height:460px;background:var(--surface-2)}.courier-all-map-loading{position:absolute;z-index:4;inset:0;display:flex;align-items:center;justify-content:center;gap:8px;color:var(--ink-soft);background:color-mix(in srgb,var(--surface) 83%,transparent);font-size:11px;font-weight:850}.courier-all-map-loading i{width:16px;height:16px;border:2px solid color-mix(in srgb,var(--primary) 24%,transparent);border-top-color:var(--primary);border-radius:999px;animation:courier-all-map-spin .75s linear infinite}.courier-all-map-caption{position:absolute;z-index:3;right:14px;bottom:14px;left:14px;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 12px;border:1px solid color-mix(in srgb,var(--border) 84%,transparent);border-radius:12px;background:color-mix(in srgb,var(--surface) 94%,transparent);box-shadow:0 10px 24px rgba(0,0,0,.17);backdrop-filter:blur(8px)}.courier-all-map-caption>div{display:grid;min-width:0;gap:3px}.courier-all-map-caption b,.courier-all-map-caption small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.courier-all-map-caption b{font-size:11px;font-weight:950}.courier-all-map-caption small{color:var(--ink-soft);font-size:9.5px;font-weight:700}.courier-all-map-caption a{flex:none;padding:7px 9px;border-radius:8px;color:#fff;background:var(--primary);font-size:9.5px;font-weight:900;text-decoration:none}.courier-all-map-fallback,.courier-all-map-empty,.courier-all-map-empty-list{display:grid;place-content:center;justify-items:center;gap:8px;padding:28px;color:var(--ink-soft);text-align:center}.courier-all-map-fallback{position:absolute;inset:0;background:var(--surface-2)}.courier-all-map-fallback>span,.courier-all-map-empty>span,.courier-all-map-empty-list>span{display:grid;place-items:center;width:46px;height:46px;border-radius:14px;color:var(--primary-strong);background:var(--primary-tint);font-size:25px}.courier-all-map-fallback h3,.courier-all-map-fallback p,.courier-all-map-empty h3,.courier-all-map-empty p,.courier-all-map-empty-list p{margin:0}.courier-all-map-fallback h3,.courier-all-map-empty h3{color:var(--ink);font-size:14px;font-weight:950}.courier-all-map-fallback p,.courier-all-map-empty p,.courier-all-map-empty-list p{max-width:370px;color:var(--ink-faint);font-size:10.5px;font-weight:700;line-height:1.7}.courier-all-map-empty{min-height:290px}.courier-all-map-empty-list{min-height:220px}@keyframes courier-all-map-spin{to{transform:rotate(360deg)}}.courier-all-map-marker-shell{background:transparent;border:0}.courier-all-map-marker{position:relative;display:grid;place-items:center;width:34px;height:34px;border:3px solid #fff;border-radius:50% 50% 50% 0;box-shadow:0 4px 12px rgba(4,37,42,.35);transform:rotate(-45deg);color:#fff;background:#c98316;font:900 12px var(--font,Arial,sans-serif)}.courier-all-map-marker.online{background:#059669}.courier-all-map-marker::after{content:'';position:absolute;inset:5px;border:1px solid rgba(255,255,255,.35);border-radius:50%}.courier-all-map-marker i{position:relative;z-index:1;display:block;transform:rotate(45deg);font:inherit;font-style:normal;line-height:1}.courier-all-map-popup{display:grid;min-width:165px;gap:4px;color:#102a43;font-family:var(--font,Arial,sans-serif)}.courier-all-map-popup strong{font-size:12px}.courier-all-map-popup small{color:#637884;font-size:9.5px;font-weight:700}.courier-all-map-popup span{color:#05746d;font-size:10px;font-weight:800}.courier-all-map-popup small:last-child{line-height:1.45}@media(max-width:760px){.courier-all-map-overlay{align-items:end;padding:8px}.courier-all-map-dialog{max-height:calc(100dvh - 16px);border-radius:20px}.courier-all-map-header{padding:16px}.courier-all-map-body{grid-template-columns:1fr;min-height:0}.courier-all-map-directory{max-height:205px;border-inline-end:0;border-bottom:1px solid var(--border)}.courier-all-map-list{display:flex}.courier-all-map-row{min-width:205px;border-top:0;border-inline-end:1px solid var(--border)}.courier-all-map-canvas{height:400px;min-height:400px}.courier-all-map-stage{min-height:400px}.courier-all-map-caption{right:9px;bottom:9px;left:9px}.courier-all-map-caption small{max-width:205px}.courier-all-map-fallback{min-height:400px}.courier-all-map-empty{min-height:250px}}@media(max-width:390px){.courier-all-map-header h2{font-size:16px}.courier-all-map-caption{align-items:flex-start;flex-direction:column}.courier-all-map-caption a{width:100%;box-sizing:border-box;text-align:center}}
</style>
