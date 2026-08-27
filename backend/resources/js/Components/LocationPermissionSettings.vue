<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { route } from 'ziggy-js'

const STATUS_KEY = 'almunjaz:location-permission'
const props = defineProps({
    userId: { type: [String, Number], default: null },
    shareCourierLocation: { type: Boolean, default: false },
})

const status = ref('loading')
const message = ref('')
const sharingEnabled = ref(false)
let permissionStatus

const supported = computed(() => typeof window !== 'undefined' && 'geolocation' in navigator)
const isEnabled = computed(() => status.value === 'granted')
const isBusy = computed(() => status.value === 'loading' || status.value === 'requesting')
const sharingKey = computed(() => `almunjaz:location-sharing-enabled:${props.userId || 'guest'}`)

function saveStatus(value) {
    try {
        window.localStorage.setItem(STATUS_KEY, JSON.stringify({ value, updatedAt: new Date().toISOString() }))
    } catch (_) {
        // The browser permission remains the source of truth. Storage is only
        // a fallback for browsers without the Permissions API.
    }
}

function readSharing() {
    if (!props.shareCourierLocation) return false

    try {
        return window.localStorage.getItem(sharingKey.value) === 'true'
    } catch (_) {
        return false
    }
}

function setSharing(enabled, { userInitiated = false } = {}) {
    if (!props.shareCourierLocation) return

    const wasSharing = sharingEnabled.value
    sharingEnabled.value = Boolean(enabled)
    try {
        window.localStorage.setItem(sharingKey.value, String(sharingEnabled.value))
    } catch (_) {
        // A tracker can still react for this session via the event below.
    }

    window.dispatchEvent(new CustomEvent('almunjaz:location-sharing-changed', {
        detail: { enabled: sharingEnabled.value, userInitiated },
    }))

    // Revoking sharing removes the only stored point immediately. The
    // request is intentionally best-effort: the dashboard freshness window
    // also prevents an old marker from remaining visible if the device is
    // temporarily offline at the moment the switch is turned off.
    if (wasSharing && !sharingEnabled.value) {
        window.axios.delete(route('app.location.clear')).catch(() => {})
    }
}

function savedStatus() {
    try {
        const saved = JSON.parse(window.localStorage.getItem(STATUS_KEY) || 'null')
        return ['granted', 'denied'].includes(saved?.value) ? saved.value : 'prompt'
    } catch (_) {
        return 'prompt'
    }
}

function applyPermission(value) {
    status.value = ['granted', 'denied', 'prompt'].includes(value) ? value : 'prompt'
    if (status.value !== 'prompt') saveStatus(status.value)
    if (status.value !== 'granted' && sharingEnabled.value) setSharing(false)
}

async function refresh() {
    message.value = ''

    if (!supported.value) {
        status.value = 'unsupported'
        return
    }

    // HTTPS (or localhost in development) is required by the device API.
    if (!window.isSecureContext) {
        status.value = 'unavailable'
        return
    }

    try {
        if ('permissions' in navigator && navigator.permissions?.query) {
            permissionStatus?.removeEventListener?.('change', onPermissionChange)
            permissionStatus = await navigator.permissions.query({ name: 'geolocation' })
            applyPermission(permissionStatus.state)
            permissionStatus.addEventListener?.('change', onPermissionChange)
            return
        }
    } catch (_) {
        // Some mobile browsers do not expose geolocation in Permissions API.
    }

    applyPermission(savedStatus())
}

function onPermissionChange() {
    applyPermission(permissionStatus?.state || 'prompt')
}

function requestLocation() {
    message.value = ''
    if (!supported.value) {
        status.value = 'unsupported'
        return
    }

    if (!window.isSecureContext) {
        status.value = 'unavailable'
        return
    }

    status.value = 'requesting'
    // This call is deliberately one-time: it asks for access without storing,
    // transmitting, or continuously tracking the user's coordinates.
    navigator.geolocation.getCurrentPosition(
        () => {
            applyPermission('granted')
            setSharing(true, { userInitiated: true })
        },
        (error) => {
            if (error?.code === error?.PERMISSION_DENIED || error?.code === 1) {
                applyPermission('denied')
                return
            }

            status.value = 'error'
            message.value = window.t?.('Could not access your location. Check your device settings and try again.')
                || 'Could not access your location. Check your device settings and try again.'
        },
        { enableHighAccuracy: false, timeout: 15000, maximumAge: 600000 },
    )
}

function toggleSharing() {
    if (!props.shareCourierLocation) {
        requestLocation()
        return
    }

    if (status.value === 'granted') {
        setSharing(!sharingEnabled.value, { userInitiated: true })
        return
    }

    requestLocation()
}

onMounted(() => {
    sharingEnabled.value = readSharing()
    refresh()
})

onBeforeUnmount(() => {
    permissionStatus?.removeEventListener?.('change', onPermissionChange)
})
</script>

<template>
    <section class="location-setting" :class="{ enabled: isEnabled }">
        <div class="location-setting-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 10c0 5.2-8 11-8 11S4 15.2 4 10a8 8 0 1 1 16 0Z" />
                <circle cx="12" cy="10" r="2.5" />
            </svg>
        </div>
        <div class="location-setting-copy">
            <b>{{ t('Location access') }}</b>
            <small v-if="isEnabled && shareCourierLocation && sharingEnabled">{{ t('Location sharing is enabled. You can stop sharing it here at any time.') }}</small>
            <small v-else-if="isEnabled">{{ t('Location access is enabled on this device. Your location is only used when you choose a location action.') }}</small>
            <small v-else-if="status === 'denied'">{{ t('Allow location from your device settings, then return here.') }}</small>
            <small v-else-if="status === 'unsupported'">{{ t('Location access is not available on this device.') }}</small>
            <small v-else-if="status === 'unavailable'">{{ t('Location access requires a secure connection.') }}</small>
            <small v-else>{{ t('Allow location access only when you choose a location-related action.') }}</small>
            <small v-if="message" class="location-error">{{ message }}</small>
        </div>
        <button
            v-if="isEnabled && shareCourierLocation"
            type="button"
            class="location-switch"
            :class="{ 'is-on': sharingEnabled }"
            role="switch"
            :aria-checked="sharingEnabled"
            :aria-label="t('Location access')"
            @click="toggleSharing"
        ><span /></button>
        <span
            v-else-if="isEnabled"
            class="location-switch is-on is-locked"
            role="switch"
            :aria-checked="true"
            :aria-label="t('Location access')"
        ><span /></span>
        <button
            v-else-if="!isBusy && status !== 'unsupported' && status !== 'unavailable'"
            type="button"
            class="location-switch"
            role="switch"
            :aria-checked="false"
            :aria-label="t('Location access')"
            @click="requestLocation"
        ><span /></button>
        <span v-else class="location-state">{{ status === 'requesting' ? t('Requesting…') : t('…') }}</span>
    </section>
</template>

<style scoped>
.location-setting{display:flex;align-items:center;gap:10px;margin-bottom:12px;padding:12px;border:1px solid var(--border);border-radius:13px;background:var(--surface);box-shadow:0 2px 9px rgba(15,27,26,.025)}.location-setting.enabled{border-color:color-mix(in srgb,var(--success) 38%,var(--border));background:color-mix(in srgb,var(--success-tint) 60%,var(--surface))}.location-setting-icon{display:grid;place-items:center;width:36px;height:36px;border-radius:11px;flex:none;background:var(--primary-tint);color:var(--primary-strong)}.location-setting-icon svg{width:18px;height:18px}.location-setting-copy{display:grid;min-width:0;flex:1;gap:2px}.location-setting-copy b{font-size:11.5px;font-weight:900}.location-setting-copy small{font-size:9.5px;line-height:1.55;color:var(--ink-soft);font-weight:650}.location-setting-copy .location-error{color:var(--danger)}.location-switch{position:relative;display:inline-flex;align-items:center;width:42px;height:24px;padding:3px;flex:none;border:0;border-radius:999px;background:var(--border-strong,var(--border));cursor:pointer;transition:background .2s ease,box-shadow .2s ease}.location-switch span{display:block;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 4px rgba(15,27,26,.26);transform:translateX(0);transition:transform .2s ease}.location-switch.is-on{background:var(--success)}.location-switch.is-on span{transform:translateX(-18px)}html[dir="ltr"] .location-switch.is-on span{transform:translateX(18px)}.location-switch:focus-visible{outline:3px solid color-mix(in srgb,var(--primary) 35%,transparent);outline-offset:2px}.location-switch.is-locked{cursor:default;opacity:.82}.location-state{font-size:11px;color:var(--ink-faint);font-weight:800}
</style>
