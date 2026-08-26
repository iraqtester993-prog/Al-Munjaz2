<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'

/**
 * A self-contained OpenStreetMap coordinate picker.
 *
 * It uses Leaflet from its public CDN only while the picker is open so no
 * package dependency is added to the application. If that script or map tiles
 * are unavailable, the user can still select the location with the coordinate
 * fields and see an OpenStreetMap embed as a fallback.
 */
const props = defineProps({
    latitude: { type: [String, Number], default: null },
    longitude: { type: [String, Number], default: null },
    label: { type: String, default: '' },
    locale: { type: String, default: 'ar' },
    disabled: { type: Boolean, default: false },
    defaultZoom: { type: Number, default: 15 },
})

const emit = defineEmits(['selected', 'cleared', 'opened', 'closed'])

const COPY = {
    ar: {
        select: 'تحديد الموقع على الخريطة',
        selected: 'تم تحديد موقع الطلب',
        missing: 'لم يتم اختيار موقع بعد',
        edit: 'تعديل الموقع',
        title: 'تحديد موقع الطلب',
        help: 'اضغط على الخريطة أو اسحب العلامة إلى موقع الطلب. هذا الموقع خاص بهذا الطلب فقط.',
        mapLoading: 'جارٍ تحميل الخريطة…',
        mapFallback: 'تعذّر تحميل الخريطة التفاعلية. أدخل الإحداثيات يدوياً أو افتح الموقع في OpenStreetMap.',
        useCurrent: 'استخدام موقعي الحالي',
        locating: 'جارٍ تحديد موقعك…',
        latitude: 'خط العرض',
        longitude: 'خط الطول',
        locationLabel: 'وصف الموقع',
        locationLabelHint: 'مثال: مخزن الكرادة — المدخل الخلفي',
        openOsm: 'فتح في OpenStreetMap',
        clear: 'مسح الموقع',
        cancel: 'إلغاء',
        save: 'حفظ موقع الطلب',
        required: 'حدّد إحداثيات صحيحة للموقع أولاً.',
        geolocationUnavailable: 'لا يمكن الوصول إلى الموقع على هذا الجهاز.',
        geolocationSecure: 'الوصول إلى الموقع يحتاج اتصالاً آمناً عبر HTTPS.',
        geolocationDenied: 'اسمح بالوصول إلى الموقع من إعدادات الجهاز ثم حاول مجدداً.',
        geolocationTimeout: 'استغرق تحديد الموقع وقتاً طويلاً. جرّب مرة أخرى في مكان مفتوح.',
        geolocationFailed: 'تعذر تحديد موقعك حالياً. يمكنك اختيار النقطة يدوياً من الخريطة.',
        coordinates: 'الإحداثيات',
    },
    en: {
        select: 'Select location on map',
        selected: 'Order location selected',
        missing: 'No location selected yet',
        edit: 'Edit location',
        title: 'Select order location',
        help: 'Tap the map or drag the pin to the order location. This location is saved for this order only.',
        mapLoading: 'Loading map…',
        mapFallback: 'Interactive map could not load. Enter coordinates manually or open the point in OpenStreetMap.',
        useCurrent: 'Use my current location',
        locating: 'Getting your location…',
        latitude: 'Latitude',
        longitude: 'Longitude',
        locationLabel: 'Location label',
        locationLabelHint: 'Example: Karrada store — rear entrance',
        openOsm: 'Open in OpenStreetMap',
        clear: 'Clear location',
        cancel: 'Cancel',
        save: 'Save order location',
        required: 'Choose valid location coordinates first.',
        geolocationUnavailable: 'Location access is not available on this device.',
        geolocationSecure: 'Location access requires a secure HTTPS connection.',
        geolocationDenied: 'Allow location access from device settings, then try again.',
        geolocationTimeout: 'Location lookup took too long. Try again in an open area.',
        geolocationFailed: 'Your location could not be found. You can select the pin manually on the map.',
        coordinates: 'Coordinates',
    },
    ku: {
        select: 'دیاریکردنی شوێن لە نەخشە',
        selected: 'شوێنی داواکاری دیاری کرا',
        missing: 'هێشتا شوێن دیاری نەکراوە',
        edit: 'دەستکاریکردنی شوێن',
        title: 'دیاریکردنی شوێنی داواکاری',
        help: 'لەسەر نەخشە کرتە بکە یان نیشانەکە بکێشە بۆ شوێنی داواکاری. ئەم شوێنە تەنها بۆ ئەم داواکارییە پاشەکەوت دەکرێت.',
        mapLoading: 'نەخشە بار دەکرێت…',
        mapFallback: 'نەخشەی کارلێککار بار نەبوو. کۆئۆردیناتەکان بەدەستی بنووسە یان لە OpenStreetMap بکەرەوە.',
        useCurrent: 'شوێنی ئێستام بەکاربهێنە',
        locating: 'شوێنت دەدۆزرێتەوە…',
        latitude: 'پانیی هێڵ',
        longitude: 'درێژیی هێڵ',
        locationLabel: 'ناونیشانی شوێن',
        locationLabelHint: 'نموونە: کۆگای کەڕادە — دەرگای دواوە',
        openOsm: 'کردنەوە لە OpenStreetMap',
        clear: 'سڕینەوەی شوێن',
        cancel: 'هەڵوەشاندنەوە',
        save: 'پاشەکەوتکردنی شوێنی داواکاری',
        required: 'سەرەتا کۆئۆردیناتە دروستەکانی شوێن دیاری بکە.',
        geolocationUnavailable: 'دەستگەیشتن بە شوێن لەم ئامێرەدا بەردەست نییە.',
        geolocationSecure: 'دەستگەیشتن بە شوێن پێویستی بە پەیوەندی HTTPS هەیە.',
        geolocationDenied: 'لە ڕێکخستنەکانی ئامێرەوە مۆڵەتی شوێن بدە و دووبارە هەوڵ بدە.',
        geolocationTimeout: 'دۆزینەوەی شوێن درێژخایەن بوو. لە شوێنێکی کراوەدا دووبارە هەوڵ بدە.',
        geolocationFailed: 'نەتوانرا شوێنت بدۆزرێتەوە. دەتوانیت نیشانەکە بەدەستی لەسەر نەخشە دیاری بکەیت.',
        coordinates: 'کۆئۆردیناتەکان',
    },
}

const BAGHDAD = { latitude: 33.3152, longitude: 44.3661 }
const mapElement = ref(null)
const isOpen = ref(false)
const mapLoading = ref(false)
const mapFallback = ref(false)
const locationBusy = ref(false)
const error = ref('')
const draftLatitude = ref('')
const draftLongitude = ref('')
const draftLabel = ref('')

let leafletMap = null
let leafletMarker = null
let leafletClickHandler = null
let leafletDragHandler = null

const copy = computed(() => COPY[props.locale] || COPY.ar)
const hasValidCoordinates = computed(() => coordinatesAreValid(draftLatitude.value, draftLongitude.value))
const isSelected = computed(() => coordinatesAreValid(props.latitude, props.longitude))
const coordinateText = computed(() => isSelected.value
    ? `${Number(props.latitude).toFixed(6)}, ${Number(props.longitude).toFixed(6)}`
    : '')
const osmUrl = computed(() => {
    const latitude = toLatitude(draftLatitude.value) ?? BAGHDAD.latitude
    const longitude = toLongitude(draftLongitude.value) ?? BAGHDAD.longitude

    return `https://www.openstreetmap.org/?mlat=${latitude}&mlon=${longitude}#map=${Math.min(Math.max(props.defaultZoom, 3), 19)}/${latitude}/${longitude}`
})
const osmEmbedUrl = computed(() => {
    const latitude = toLatitude(draftLatitude.value) ?? BAGHDAD.latitude
    const longitude = toLongitude(draftLongitude.value) ?? BAGHDAD.longitude
    const delta = 0.015
    const bbox = [longitude - delta, latitude - delta, longitude + delta, latitude + delta]
        .map((value) => value.toFixed(6))
        .join('%2C')

    return `https://www.openstreetmap.org/export/embed.html?bbox=${bbox}&layer=mapnik&marker=${latitude.toFixed(6)}%2C${longitude.toFixed(6)}`
})

function toLatitude(value) {
    if (value === null || value === undefined || (typeof value === 'string' && value.trim() === '')) return null
    const parsed = Number(value)

    return Number.isFinite(parsed) && parsed >= -90 && parsed <= 90 ? parsed : null
}

function toLongitude(value) {
    if (value === null || value === undefined || (typeof value === 'string' && value.trim() === '')) return null
    const parsed = Number(value)

    return Number.isFinite(parsed) && parsed >= -180 && parsed <= 180 ? parsed : null
}

function coordinatesAreValid(latitude, longitude) {
    return toLatitude(latitude) !== null && toLongitude(longitude) !== null
}

function setDraftPoint(latitude, longitude, { recenter = true } = {}) {
    const validLatitude = toLatitude(latitude)
    const validLongitude = toLongitude(longitude)
    if (validLatitude === null || validLongitude === null) return

    draftLatitude.value = validLatitude.toFixed(7)
    draftLongitude.value = validLongitude.toFixed(7)
    error.value = ''

    if (leafletMarker) {
        leafletMarker.setLatLng([validLatitude, validLongitude])
    }

    if (recenter && leafletMap) {
        leafletMap.panTo([validLatitude, validLongitude], { animate: true })
    }
}

function hydrateDraft() {
    const latitude = toLatitude(props.latitude) ?? BAGHDAD.latitude
    const longitude = toLongitude(props.longitude) ?? BAGHDAD.longitude
    setDraftPoint(latitude, longitude, { recenter: false })
    draftLabel.value = props.label || ''
    error.value = ''
}

function openPicker() {
    if (props.disabled) return

    hydrateDraft()
    mapFallback.value = false
    isOpen.value = true
    emit('opened')

    nextTick(() => initializeMap())
}

function closePicker() {
    if (!isOpen.value) return

    isOpen.value = false
    emit('closed')
    destroyMap()
}

function clearLocation() {
    draftLatitude.value = ''
    draftLongitude.value = ''
    draftLabel.value = ''
    error.value = ''
    emit('cleared')
    closePicker()
}

function saveLocation() {
    if (!hasValidCoordinates.value) {
        error.value = copy.value.required
        return
    }

    emit('selected', {
        latitude: Number(Number(draftLatitude.value).toFixed(7)),
        longitude: Number(Number(draftLongitude.value).toFixed(7)),
        label: draftLabel.value.trim(),
    })
    closePicker()
}

function onCoordinateInput() {
    if (!hasValidCoordinates.value) return

    const latitude = toLatitude(draftLatitude.value)
    const longitude = toLongitude(draftLongitude.value)
    error.value = ''

    // Do not rewrite the fields while the user is typing. Reformatting here
    // would turn a partial value such as "33." into a complete coordinate and
    // make manual entry frustrating on mobile keyboards.
    if (leafletMarker) leafletMarker.setLatLng([latitude, longitude])
    if (leafletMap) leafletMap.panTo([latitude, longitude], { animate: false })
}

function requestCurrentLocation() {
    error.value = ''

    if (typeof window === 'undefined' || !('geolocation' in navigator)) {
        error.value = copy.value.geolocationUnavailable
        return
    }

    if (!window.isSecureContext) {
        error.value = copy.value.geolocationSecure
        return
    }

    locationBusy.value = true
    navigator.geolocation.getCurrentPosition(
        (position) => {
            setDraftPoint(position.coords.latitude, position.coords.longitude)
            locationBusy.value = false
        },
        (geolocationError) => {
            if (geolocationError?.code === geolocationError?.PERMISSION_DENIED || geolocationError?.code === 1) {
                error.value = copy.value.geolocationDenied
            } else if (geolocationError?.code === geolocationError?.TIMEOUT || geolocationError?.code === 3) {
                error.value = copy.value.geolocationTimeout
            } else {
                error.value = copy.value.geolocationFailed
            }
            locationBusy.value = false
        },
        { enableHighAccuracy: true, timeout: 18000, maximumAge: 60000 },
    )
}

async function initializeMap() {
    if (!isOpen.value || !mapElement.value || leafletMap) return

    mapLoading.value = true
    try {
        const L = await loadLeaflet()
        if (!isOpen.value || !mapElement.value) return

        const latitude = toLatitude(draftLatitude.value) ?? BAGHDAD.latitude
        const longitude = toLongitude(draftLongitude.value) ?? BAGHDAD.longitude

        leafletMap = L.map(mapElement.value, {
            zoomControl: true,
            attributionControl: true,
            scrollWheelZoom: true,
        }).setView([latitude, longitude], Math.min(Math.max(props.defaultZoom, 3), 19))

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors',
        }).addTo(leafletMap)

        leafletMarker = L.marker([latitude, longitude], {
            draggable: true,
            icon: L.divIcon({
                className: 'almunjaz-osm-pin-shell',
                html: '<span class="almunjaz-osm-pin" aria-hidden="true"><span></span></span>',
                iconSize: [34, 42],
                iconAnchor: [17, 40],
            }),
        }).addTo(leafletMap)

        leafletClickHandler = (event) => setDraftPoint(event.latlng.lat, event.latlng.lng, { recenter: false })
        leafletDragHandler = (event) => setDraftPoint(event.target.getLatLng().lat, event.target.getLatLng().lng, { recenter: false })
        leafletMap.on('click', leafletClickHandler)
        leafletMarker.on('dragend', leafletDragHandler)

        requestAnimationFrame(() => leafletMap?.invalidateSize())
    } catch (_) {
        // The fallback keeps the picker usable when a mobile network, CSP, or
        // offline state blocks the optional map library.
        mapFallback.value = true
    } finally {
        mapLoading.value = false
    }
}

function destroyMap() {
    if (leafletMarker && leafletDragHandler) leafletMarker.off('dragend', leafletDragHandler)
    if (leafletMap && leafletClickHandler) leafletMap.off('click', leafletClickHandler)
    if (leafletMap) leafletMap.remove()

    leafletMap = null
    leafletMarker = null
    leafletClickHandler = null
    leafletDragHandler = null
}

function onKeydown(event) {
    if (event.key === 'Escape' && isOpen.value) closePicker()
}

watch(
    () => [props.latitude, props.longitude, props.label],
    () => {
        if (!isOpen.value) return
        hydrateDraft()
    },
)

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown)
    destroyMap()
})

if (typeof document !== 'undefined') {
    document.addEventListener('keydown', onKeydown)
}

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

        const scriptId = 'almunjaz-leaflet-script'
        const existingScript = document.getElementById(scriptId)
        if (existingScript) {
            existingScript.addEventListener('load', () => window.L?.map ? resolve(window.L) : reject(new Error('Leaflet unavailable')), { once: true })
            existingScript.addEventListener('error', () => reject(new Error('Leaflet failed to load')), { once: true })
            return
        }

        const script = document.createElement('script')
        script.id = scriptId
        script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'
        script.integrity = 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo='
        script.crossOrigin = ''
        script.async = true
        script.onload = () => window.L?.map ? resolve(window.L) : reject(new Error('Leaflet unavailable'))
        script.onerror = () => reject(new Error('Leaflet failed to load'))
        document.head.appendChild(script)
    })

    return leafletLoader
}
</script>

<template>
    <section class="order-map-picker" :class="{ selected: isSelected, disabled }">
        <div class="order-map-picker-copy">
            <span class="order-map-picker-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 5.2-8 11-8 11S4 15.2 4 10a8 8 0 1 1 16 0Z" /><circle cx="12" cy="10" r="2.5" /></svg>
            </span>
            <span>
                <b>{{ isSelected ? copy.selected : copy.missing }}</b>
                <small v-if="isSelected" dir="ltr">{{ coordinateText }}</small>
                <small v-else>{{ copy.help }}</small>
            </span>
        </div>
        <button type="button" class="order-map-picker-trigger" :disabled="disabled" @click="openPicker">
            {{ isSelected ? copy.edit : copy.select }}
        </button>
    </section>

    <Teleport to="body">
        <div v-if="isOpen" class="order-map-overlay" role="presentation" @click.self="closePicker">
            <section class="order-map-dialog" role="dialog" aria-modal="true" :aria-label="copy.title">
                <header class="order-map-dialog-header">
                    <div>
                        <h3>{{ copy.title }}</h3>
                        <p>{{ copy.help }}</p>
                    </div>
                    <button type="button" class="order-map-close" :aria-label="copy.cancel" @click="closePicker">
                        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
                    </button>
                </header>

                <div class="order-map-canvas" :class="{ fallback: mapFallback }">
                    <div v-show="!mapFallback" ref="mapElement" class="order-map-leaflet" aria-label="OpenStreetMap"></div>
                    <div v-if="mapLoading" class="order-map-loading"><span class="order-map-spinner"></span>{{ copy.mapLoading }}</div>
                    <iframe
                        v-if="mapFallback"
                        class="order-map-iframe"
                        :src="osmEmbedUrl"
                        :title="copy.title"
                        loading="lazy"
                        referrerpolicy="no-referrer"
                    ></iframe>
                </div>
                <p v-if="mapFallback" class="order-map-fallback-copy">{{ copy.mapFallback }}</p>

                <div class="order-map-actions">
                    <button type="button" class="order-map-secondary" :disabled="locationBusy" @click="requestCurrentLocation">
                        <span v-if="locationBusy" class="order-map-spinner small"></span>
                        <span v-else>{{ copy.useCurrent }}</span>
                    </button>
                    <a class="order-map-secondary" :href="osmUrl" target="_blank" rel="noopener noreferrer">{{ copy.openOsm }}</a>
                </div>

                <div class="order-map-coordinate-grid">
                    <label>
                        <span>{{ copy.latitude }}</span>
                        <input v-model="draftLatitude" inputmode="decimal" dir="ltr" autocomplete="off" @input="onCoordinateInput" />
                    </label>
                    <label>
                        <span>{{ copy.longitude }}</span>
                        <input v-model="draftLongitude" inputmode="decimal" dir="ltr" autocomplete="off" @input="onCoordinateInput" />
                    </label>
                </div>
                <label class="order-map-label">
                    <span>{{ copy.locationLabel }}</span>
                    <input v-model="draftLabel" :placeholder="copy.locationLabelHint" maxlength="255" autocomplete="off" />
                </label>
                <p v-if="error" class="order-map-error" role="alert">{{ error }}</p>

                <footer class="order-map-dialog-footer">
                    <button v-if="isSelected" type="button" class="order-map-clear" @click="clearLocation">{{ copy.clear }}</button>
                    <span v-else></span>
                    <span class="order-map-footer-actions">
                        <button type="button" class="order-map-cancel" @click="closePicker">{{ copy.cancel }}</button>
                        <button type="button" class="order-map-save" :disabled="!hasValidCoordinates" @click="saveLocation">{{ copy.save }}</button>
                    </span>
                </footer>
            </section>
        </div>
    </Teleport>
</template>

<style scoped>
.order-map-picker{display:flex;align-items:center;gap:10px;padding:12px;border:1.5px solid color-mix(in srgb,var(--primary) 28%,var(--border));border-radius:14px;background:linear-gradient(135deg,color-mix(in srgb,var(--primary-tint) 65%,var(--surface)),var(--surface));transition:.18s ease}.order-map-picker.selected{border-color:color-mix(in srgb,var(--success) 48%,var(--border));background:linear-gradient(135deg,color-mix(in srgb,var(--success-tint) 64%,var(--surface)),var(--surface))}.order-map-picker.disabled{opacity:.62}.order-map-picker-copy{display:flex;align-items:center;min-width:0;gap:9px;flex:1}.order-map-picker-copy>span:last-child{display:grid;gap:2px;min-width:0}.order-map-picker-copy b{font-size:11.5px;font-weight:900;color:var(--ink)}.order-map-picker-copy small{font-size:9.5px;font-weight:700;line-height:1.45;color:var(--ink-soft);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.order-map-picker-icon{display:grid;place-items:center;width:38px;height:38px;border-radius:11px;flex:none;background:var(--primary);color:#fff}.order-map-picker.selected .order-map-picker-icon{background:var(--success)}.order-map-picker-icon svg{width:20px;height:20px}.order-map-picker-trigger{min-height:35px;flex:none;padding:8px 10px;border:0;border-radius:9px;background:var(--primary);color:#fff;font:inherit;font-size:9.5px;font-weight:900;cursor:pointer}.order-map-picker-trigger:disabled{cursor:not-allowed}
.order-map-overlay{position:fixed;z-index:10050;inset:0;display:grid;align-items:end;justify-items:center;padding:12px;background:rgba(8,18,17,.64);backdrop-filter:blur(3px)}.order-map-dialog{display:flex;flex-direction:column;width:min(680px,100%);max-height:calc(100dvh - 24px);padding:17px;border:1px solid color-mix(in srgb,#fff 22%,transparent);border-radius:22px;background:var(--surface);box-shadow:0 24px 62px rgba(0,0,0,.3);overflow:hidden}.order-map-dialog-header{display:flex;align-items:flex-start;gap:12px;margin-bottom:13px}.order-map-dialog-header>div{min-width:0;flex:1}.order-map-dialog-header h3{margin:0;color:var(--ink);font-size:16px;font-weight:950}.order-map-dialog-header p{margin:4px 0 0;color:var(--ink-soft);font-size:10px;font-weight:650;line-height:1.55}.order-map-close{display:grid;place-items:center;width:38px;height:38px;padding:0;border:0;border-radius:12px;background:var(--danger);color:#fff;cursor:pointer}.order-map-canvas{position:relative;min-height:255px;overflow:hidden;border:1px solid var(--border);border-radius:15px;background:var(--surface-2)}.order-map-leaflet,.order-map-iframe{width:100%;height:255px;border:0}.order-map-loading{position:absolute;inset:0;z-index:2;display:flex;align-items:center;justify-content:center;gap:8px;background:color-mix(in srgb,var(--surface) 88%,transparent);color:var(--ink-soft);font-size:11px;font-weight:850}.order-map-spinner{display:inline-block;width:16px;height:16px;border:2px solid color-mix(in srgb,var(--primary) 22%,transparent);border-top-color:var(--primary);border-radius:999px;animation:order-map-spin .75s linear infinite}.order-map-spinner.small{width:13px;height:13px;border-width:2px}.order-map-fallback-copy{margin:8px 1px 0;color:var(--ink-soft);font-size:9.5px;font-weight:700;line-height:1.5}.order-map-actions{display:flex;gap:8px;margin-top:11px}.order-map-secondary{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:35px;padding:8px 10px;border:1px solid color-mix(in srgb,var(--primary) 28%,var(--border));border-radius:9px;background:var(--primary-tint);color:var(--primary-strong);font:inherit;font-size:10px;font-weight:850;text-decoration:none;cursor:pointer}.order-map-secondary:disabled{opacity:.66;cursor:wait}.order-map-coordinate-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:12px}.order-map-coordinate-grid label,.order-map-label{display:grid;gap:5px;color:var(--ink-soft);font-size:9.5px;font-weight:850}.order-map-coordinate-grid input,.order-map-label input{min-width:0;min-height:39px;padding:8px 10px;border:1px solid var(--border);border-radius:10px;outline:none;background:var(--surface);color:var(--ink);font:inherit;font-size:11px;font-weight:750}.order-map-coordinate-grid input:focus,.order-map-label input:focus{border-color:var(--primary);box-shadow:0 0 0 3px color-mix(in srgb,var(--primary) 12%,transparent)}.order-map-label{margin-top:10px}.order-map-error{margin:8px 1px 0;color:var(--danger);font-size:10px;font-weight:800;line-height:1.5}.order-map-dialog-footer{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:15px;padding-top:13px;border-top:1px solid var(--border)}.order-map-footer-actions{display:flex;gap:8px}.order-map-clear,.order-map-cancel,.order-map-save{min-height:37px;padding:8px 12px;border-radius:10px;font:inherit;font-size:10px;font-weight:900;cursor:pointer}.order-map-clear{border:0;background:transparent;color:var(--danger)}.order-map-cancel{border:1px solid var(--border);background:var(--surface);color:var(--ink-soft)}.order-map-save{border:0;background:var(--primary);color:#fff}.order-map-save:disabled{cursor:not-allowed;opacity:.5}@keyframes order-map-spin{to{transform:rotate(360deg)}}
:global(.almunjaz-osm-pin-shell){background:transparent!important;border:0!important}:global(.almunjaz-osm-pin){position:relative;display:grid;width:30px;height:30px;place-items:center;border:3px solid #fff;border-radius:50% 50% 50% 0;background:var(--primary,#0b6e68);box-shadow:0 5px 11px rgba(0,0,0,.3);transform:rotate(-45deg)}:global(.almunjaz-osm-pin span){display:block;width:8px;height:8px;border-radius:50%;background:#fff}:global(.almunjaz-osm-pin-shell .almunjaz-osm-pin span){transform:rotate(45deg)}
@media (min-width:700px){.order-map-overlay{align-items:center}.order-map-dialog{padding:20px}.order-map-canvas,.order-map-leaflet,.order-map-iframe{min-height:310px;height:310px}}@media (max-width:390px){.order-map-picker{align-items:flex-start}.order-map-picker-trigger{max-width:112px}.order-map-actions{flex-direction:column;align-items:stretch}.order-map-dialog{padding:14px;border-radius:18px}.order-map-coordinate-grid{grid-template-columns:1fr}.order-map-dialog-footer{align-items:flex-end}.order-map-footer-actions{flex-direction:column-reverse}.order-map-clear,.order-map-cancel,.order-map-save{width:100%}}
</style>
