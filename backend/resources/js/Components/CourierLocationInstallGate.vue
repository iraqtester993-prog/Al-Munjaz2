<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { route } from 'ziggy-js'
import SheetModal from './SheetModal.vue'

const props = defineProps({
    userId: { type: [String, Number], default: null },
})

const open = ref(false)
const installed = ref(false)
const status = ref('checking')
const completed = ref(false)
const errorMessage = ref('')
let permissionStatus = null

const sharingKey = computed(() => `almunjaz:location-sharing-enabled:${props.userId || 'guest'}`)
const sharingConfirmedKey = computed(() => `almunjaz:location-sharing-confirmed:${props.userId || 'guest'}`)
const dismissedKey = computed(() => `almunjaz:location-install-gate-dismissed:${props.userId || 'guest'}`)
const supported = computed(() => typeof window !== 'undefined' && 'geolocation' in navigator)

function isStandalone() {
    const nativeBridge = window.AlMunjazNativeLocation
    const nativeShell = nativeBridge && typeof nativeBridge.start === 'function'

    return window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true || nativeShell
}

function sharingEnabled() {
    try {
        return window.localStorage.getItem(sharingKey.value) === 'true'
    } catch (_) {
        return false
    }
}

function sharingConfirmed() {
    try {
        return window.localStorage.getItem(sharingConfirmedKey.value) === 'true'
    } catch (_) {
        return false
    }
}

function isDismissed() {
    try {
        return window.sessionStorage.getItem(dismissedKey.value) === 'true'
    } catch (_) {
        return false
    }
}

function setDismissed() {
    try {
        window.sessionStorage.setItem(dismissedKey.value, 'true')
    } catch (_) {
        // This only limits repeat display within a session; permission and
        // sharing are still stored independently below.
    }
}

function clearDismissed() {
    try {
        window.sessionStorage.removeItem(dismissedKey.value)
    } catch (_) {
        // No-op when storage is unavailable.
    }
}

function setSharing(enabled, confirmed = false) {
    try {
        window.localStorage.setItem(sharingKey.value, String(Boolean(enabled)))
        if (enabled && confirmed) window.localStorage.setItem(sharingConfirmedKey.value, 'true')
        if (!enabled) window.localStorage.removeItem(sharingConfirmedKey.value)
    } catch (_) {
        // The running tracker still receives the event in this session.
    }

    window.dispatchEvent(new CustomEvent('almunjaz:location-sharing-changed', {
        detail: { enabled: Boolean(enabled), confirmed: Boolean(enabled && confirmed), userInitiated: true },
    }))
}

function setStatus(value) {
    status.value = ['granted', 'denied', 'prompt', 'unsupported', 'unavailable', 'error', 'requesting', 'saving'].includes(value)
        ? value
        : 'prompt'

    if (status.value !== 'error') errorMessage.value = ''
}

async function refreshPermission() {
    if (!supported.value) {
        setStatus('unsupported')
        return
    }

    if (!window.isSecureContext) {
        setStatus('unavailable')
        return
    }

    try {
        permissionStatus?.removeEventListener?.('change', onPermissionChange)
        permissionStatus = await navigator.permissions?.query?.({ name: 'geolocation' })
        if (permissionStatus) {
            setStatus(permissionStatus.state)
            permissionStatus.addEventListener?.('change', onPermissionChange)
            return
        }
    } catch (_) {
        // Safari and several installed web views intentionally omit the
        // Permissions API. The click below is the only prompt source there.
    }

    setStatus('prompt')
}

async function considerOpening({ force = false } = {}) {
    if (!installed.value) return

    await refreshPermission()
    // A legacy local preference is intentionally not enough. It must have
    // been confirmed by a successful location update to the server.
    if (sharingEnabled() && sharingConfirmed()) {
        completed.value = true
        open.value = false
        return
    }

    completed.value = false
    if (!force && isDismissed()) return
    open.value = true
}

function onPermissionChange() {
    setStatus(permissionStatus?.state || 'prompt')
    if (status.value !== 'granted' && sharingEnabled()) setSharing(false)
}

async function saveCurrentPosition(position) {
    const latitude = Number(position?.coords?.latitude)
    const longitude = Number(position?.coords?.longitude)
    const accuracy = Math.round(Number(position?.coords?.accuracy || 0))

    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
        errorMessage.value = 'Could not access your location. Check your device settings and try again.'
        setStatus('error')
        return
    }

    setStatus('saving')

    try {
        // The first explicitly approved location is persisted immediately.
        // Sharing is not enabled until this authenticated request succeeds.
        await window.axios.post(route('app.location.update'), {
            latitude,
            longitude,
            accuracy_meters: Math.max(0, Math.min(accuracy, 50_000)),
        })
    } catch (_) {
        errorMessage.value = 'Could not save your location. Check your connection and try again.'
        setStatus('error')
        return
    }

    setStatus('granted')
    setSharing(true, true)
    completed.value = true
}

function requestSharing() {
    if (!supported.value || !window.isSecureContext) {
        refreshPermission()
        return
    }

    if (status.value === 'denied') {
        refreshPermission()
        return
    }

    completed.value = false
    setStatus('requesting')
    // This only runs after an explicit tap. No installed-app launch or page
    // navigation can invoke the device permission prompt by itself. Even a
    // previously granted permission still gets a fresh position here before
    // sharing is switched on.
    navigator.geolocation.getCurrentPosition(
        (position) => saveCurrentPosition(position),
        (error) => {
            if (error?.code === error?.PERMISSION_DENIED || error?.code === 1) {
                setStatus('denied')
                return
            }

            errorMessage.value = 'Could not access your location. Check your device settings and try again.'
            setStatus('error')
        },
        { enableHighAccuracy: true, timeout: 15_000, maximumAge: 0 },
    )
}

function close() {
    setDismissed()
    open.value = false
}

function finish() {
    setDismissed()
    open.value = false
}

function onInstalled() {
    installed.value = true
    clearDismissed()
    considerOpening({ force: true })
}

// A courier can close the post-install sheet to browse the application, but
// a server-side order rejection must always give them a direct way back to
// the consent flow. This event is emitted only by courier order actions that
// receive the dedicated `errors.location` response.
function onOperationalLocationRequired() {
    completed.value = false
    clearDismissed()
    installed.value = isStandalone()
    considerOpening({ force: true })
}

onMounted(() => {
    installed.value = isStandalone()
    window.addEventListener('appinstalled', onInstalled)
    window.addEventListener('almunjaz:location-required', onOperationalLocationRequired)
    if (installed.value) considerOpening()
})

onBeforeUnmount(() => {
    permissionStatus?.removeEventListener?.('change', onPermissionChange)
    window.removeEventListener('appinstalled', onInstalled)
    window.removeEventListener('almunjaz:location-required', onOperationalLocationRequired)
})
</script>

<template>
    <SheetModal
        :open="open"
        :title="t('Location sharing for couriers')"
        :subtitle="t('After installing the app, allow location sharing to let your branch see your current position while you are working.')"
        @close="close"
    >
        <section class="location-gate" :class="{ ready: completed, denied: status === 'denied' }">
            <span class="location-gate-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 10c0 5.2-8 11-8 11S4 15.2 4 10a8 8 0 1 1 16 0Z" />
                    <circle cx="12" cy="10" r="2.5" />
                </svg>
            </span>

            <template v-if="completed">
                <b>{{ t('Location sharing is ready.') }}</b>
                <p>{{ t('Your current location was saved securely and location updates can now continue while the installed app is active.') }}</p>
                <button class="gate-primary" type="button" @click="finish">{{ t('Understood') }}</button>
            </template>

            <template v-else-if="status === 'denied'">
                <b>{{ t('Location permission is blocked') }}</b>
                <p>{{ t('Open your device or browser settings, allow location access, then return and refresh this screen.') }}</p>
                <button class="gate-secondary" type="button" @click="refreshPermission">{{ t('Refresh') }}</button>
            </template>

            <template v-else-if="status === 'unsupported' || status === 'unavailable'">
                <b>{{ t('Location sharing is unavailable on this device.') }}</b>
                <p>{{ status === 'unavailable' ? t('Location access requires a secure connection.') : t('Location access is not available on this device.') }}</p>
                <button class="gate-secondary" type="button" @click="finish">{{ t('Close') }}</button>
            </template>

            <template v-else>
                <b>{{ status === 'granted' ? t('Location access') : t('Enable location sharing') }}</b>
                <p v-if="status === 'error'">{{ t(errorMessage || 'Could not access your location. Check your device settings and try again.') }}</p>
                <p v-else>{{ t('After installing the app, allow location sharing to let your branch see your current position while you are working.') }}</p>
                <button class="gate-primary" type="button" :disabled="status === 'requesting' || status === 'saving'" @click="requestSharing">
                    {{ ['requesting', 'saving'].includes(status) ? t('Requesting…') : (status === 'granted' ? t('Start sharing location') : t('Enable location sharing')) }}
                </button>
                <button v-if="status === 'error'" class="gate-text" type="button" @click="refreshPermission">{{ t('Refresh') }}</button>
            </template>
        </section>
    </SheetModal>
</template>

<style scoped>
.location-gate{display:grid;justify-items:center;gap:11px;padding:3px 3px 2px;text-align:center}.location-gate-icon{display:grid;width:58px;height:58px;place-items:center;border-radius:18px;background:var(--primary-tint);color:var(--primary-strong)}.location-gate-icon svg{width:27px;height:27px}.location-gate.ready .location-gate-icon{background:var(--success-tint);color:var(--success)}.location-gate.denied .location-gate-icon{background:var(--danger-tint);color:var(--danger)}.location-gate>b{font-size:13px;font-weight:900}.location-gate p{max-width:310px;margin:0;color:var(--ink-soft);font-size:10.5px;font-weight:700;line-height:1.75}.gate-primary,.gate-secondary,.gate-text{width:100%;min-height:44px;margin-top:5px;border-radius:12px;font:850 11.5px var(--font);cursor:pointer}.gate-primary{border:0;background:var(--primary);color:#fff;box-shadow:0 8px 18px color-mix(in srgb,var(--primary) 22%,transparent)}.gate-primary:disabled{opacity:.68;cursor:wait}.gate-secondary{border:1px solid var(--border);background:var(--surface-2);color:var(--ink)}.gate-text{min-height:30px;margin-top:-4px;border:0;background:transparent;color:var(--primary-strong);font-size:10px}
</style>
