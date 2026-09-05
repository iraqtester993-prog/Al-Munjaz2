<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { route } from 'ziggy-js'
import SheetModal from './SheetModal.vue'
import {
    browserNotificationPermission,
    enableBrowserPushNotifications,
} from '../Utils/browserPushNotifications'

const props = defineProps({
    userId: { type: [String, Number], default: null },
})

// Courier location is an operational permission. The browser/device owns the
// actual permission sheet, while this component gives the courier a clear,
// branded explanation and one deliberate button that opens it. In particular,
// no unrelated tap anywhere in the app can request GPS access.
const active = ref(false)
const showGate = ref(false)
const dismissedForSession = ref(false)
const locationState = ref('checking')
const failureKind = ref('')
const requesting = ref(false)
const completed = ref(false)
const freshLocationRequired = ref(false)
const notificationState = ref('idle')
const notificationRequesting = ref(false)

const supported = computed(() => typeof window !== 'undefined' && 'geolocation' in navigator)
const secureContext = computed(() => typeof window !== 'undefined' && window.isSecureContext)
const sharingKey = computed(() => `almunjaz:location-sharing-enabled:${props.userId || 'guest'}`)
const sharingConfirmedKey = computed(() => `almunjaz:location-sharing-confirmed:${props.userId || 'guest'}`)
const dismissedKey = computed(() => `almunjaz:location-install-gate-dismissed:${props.userId || 'guest'}`)
const isReady = computed(() => locationState.value === 'granted' && completed.value && !freshLocationRequired.value)
const canRequestLocation = computed(() => !requesting.value
    && !isReady.value
    && ['prompt', 'granted', 'temporary'].includes(locationState.value))
// Notifications should be offered from the first onboarding sheet as well.
// Location remains a separate, mandatory requirement only for order actions.
const canOfferNotifications = computed(() => ['default', 'idle', 'error'].includes(notificationState.value))
const notificationsNeedAttention = computed(() => ['default', 'idle', 'error'].includes(notificationState.value))
const notificationsEnabled = computed(() => ['enabled', 'permission-granted', 'granted'].includes(notificationState.value))
const locationSwitchEnabled = computed(() => isReady.value)

let permissionStatus = null

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

function setSharing(enabled, confirmed = false) {
    try {
        window.localStorage.setItem(sharingKey.value, String(Boolean(enabled)))
        if (enabled && confirmed) window.localStorage.setItem(sharingConfirmedKey.value, 'true')
        if (!enabled) window.localStorage.removeItem(sharingConfirmedKey.value)
    } catch (_) {
        // Storage is a convenience only. The tracker still receives the
        // current-session update through this event.
    }

    window.dispatchEvent(new CustomEvent('almunjaz:location-sharing-changed', {
        detail: { enabled: Boolean(enabled), confirmed: Boolean(enabled && confirmed), userInitiated: true },
    }))
}

function isDismissed() {
    try {
        return window.sessionStorage.getItem(dismissedKey.value) === 'true'
    } catch (_) {
        return false
    }
}

function rememberDismissal() {
    try {
        window.sessionStorage.setItem(dismissedKey.value, 'true')
    } catch (_) {
        // Keeping the in-memory state still prevents repeat prompts while the
        // current component remains mounted.
    }
}

function clearDismissal() {
    try {
        window.sessionStorage.removeItem(dismissedKey.value)
    } catch (_) {
        // The next in-memory reveal remains sufficient for this session.
    }
}

function isAttentionRequired() {
    return freshLocationRequired.value
        || locationState.value !== 'granted'
        || !sharingEnabled()
        || !sharingConfirmed()
        || notificationsNeedAttention.value
}

function revealGate({ force = false } = {}) {
    if (force) {
        dismissedForSession.value = false
        clearDismissal()
    }
    if (!dismissedForSession.value) showGate.value = true
}

function dismissGate() {
    dismissedForSession.value = true
    rememberDismissal()
    showGate.value = false
}

function updateGateVisibility({ reveal = true } = {}) {
    if (isAttentionRequired()) {
        if (reveal) revealGate()
        return
    }

    showGate.value = false
}

function applyPermissionState(value, { reveal = true } = {}) {
    const next = ['granted', 'denied', 'prompt'].includes(value) ? value : 'prompt'
    locationState.value = next

    if (next !== 'granted') {
        completed.value = false
        setSharing(false)
    } else if (!freshLocationRequired.value && sharingEnabled() && sharingConfirmed()) {
        completed.value = true
    }

    updateGateVisibility({ reveal })
}

async function refreshPermission({ reveal = true } = {}) {
    failureKind.value = ''

    if (!supported.value) {
        locationState.value = 'unsupported'
        completed.value = false
        updateGateVisibility({ reveal })
        return
    }

    if (!secureContext.value) {
        locationState.value = 'insecure'
        completed.value = false
        updateGateVisibility({ reveal })
        return
    }

    try {
        if (navigator.permissions?.query) {
            const nextPermissionStatus = await navigator.permissions.query({ name: 'geolocation' })
            if (!active.value) return

            permissionStatus?.removeEventListener?.('change', onPermissionChange)
            permissionStatus = nextPermissionStatus
            permissionStatus.addEventListener?.('change', onPermissionChange)
            applyPermissionState(permissionStatus.state, { reveal })
            return
        }
    } catch (_) {
        // iOS Safari and some installed web views do not expose the
        // Permissions API. Leave the state at prompt and wait for the
        // courier's explicit action instead of guessing or auto-requesting.
    }

    locationState.value = 'prompt'
    completed.value = false
    updateGateVisibility({ reveal })
}

function onPermissionChange() {
    if (!active.value) return

    applyPermissionState(permissionStatus?.state || 'prompt')
}

async function saveCurrentPosition(position) {
    if (!active.value) return false

    const latitude = Number(position?.coords?.latitude)
    const longitude = Number(position?.coords?.longitude)
    const rawAccuracy = Number(position?.coords?.accuracy)
    const accuracy = Number.isFinite(rawAccuracy) ? Math.round(rawAccuracy) : 0

    if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return false

    try {
        // Saving this first position is what makes the location available to
        // the fresh-location guards and to the background tracker.
        await window.axios.post(route('app.location.update'), {
            latitude,
            longitude,
            accuracy_meters: Math.max(0, Math.min(accuracy, 50_000)),
        })
    } catch (_) {
        return false
    }

    if (!active.value) return false

    completed.value = true
    freshLocationRequired.value = false
    failureKind.value = ''
    locationState.value = 'granted'
    setSharing(true, true)
    notificationState.value = typeof window.NativeApp?.postMessage === 'function'
        ? 'enabled'
        : browserNotificationPermission()
    // Keep the success state visible only when the courier did not dismiss
    // the sheet while the native browser dialog was open.
    if (!dismissedForSession.value) showGate.value = true
    return true
}

function handlePositionFailure(error) {
    if (!active.value) return

    if (error?.code === error?.PERMISSION_DENIED || error?.code === 1) {
        completed.value = false
        freshLocationRequired.value = true
        locationState.value = 'denied'
        setSharing(false)
        revealGate()
        return
    }

    // A temporary GPS failure must not be represented as a denial. The
    // courier can retry from this same app sheet after moving to a clearer
    // area or restoring their connection.
    completed.value = false
    failureKind.value = error?.code === error?.TIMEOUT || error?.code === 3 ? 'timeout' : 'position'
    locationState.value = 'temporary'
    revealGate()
}

function requestCurrentPosition() {
    if (requesting.value) return false

    if (!supported.value) {
        locationState.value = 'unsupported'
        revealGate()
        return false
    }

    if (!secureContext.value) {
        locationState.value = 'insecure'
        revealGate()
        return false
    }

    // This is intentionally synchronous and is called only by the primary
    // button's click handler. Do not place an await, permission query, or
    // interaction fallback before it: mobile browsers require this trusted
    // click to show their native location permission dialog.
    requesting.value = true
    failureKind.value = ''
    locationState.value = 'requesting'
    navigator.geolocation.getCurrentPosition(
        async (position) => {
            if (!active.value) {
                requesting.value = false
                return
            }

            try {
                const saved = await saveCurrentPosition(position)
                if (!saved && active.value) {
                    failureKind.value = 'save'
                    locationState.value = 'temporary'
                    revealGate()
                }
            } finally {
                requesting.value = false
            }
        },
        (error) => {
            requesting.value = false
            handlePositionFailure(error)
        },
        { enableHighAccuracy: true, timeout: 15_000, maximumAge: 0 },
    )

    return true
}

function retryPermissionCheck() {
    dismissedForSession.value = false
    clearDismissal()
    void refreshPermission({ reveal: true })
}

function toggleLocationPermission() {
    if (locationSwitchEnabled.value || requesting.value) return

    if (locationState.value === 'denied') {
        retryPermissionCheck()
        return
    }

    requestCurrentPosition()
}

function requestNotifications({ force = false } = {}) {
    if (notificationRequesting.value || (!force && !canOfferNotifications.value)) return

    // The installed Android app owns its OneSignal subscription natively.
    // Never invoke the browser/PWA registration in this case: it has a
    // different service-worker channel and can overwrite the real native
    // result with a misleading error state.
    if (typeof window.NativeApp?.postMessage === 'function') {
        notificationRequesting.value = true
        notificationState.value = 'idle'
        window.NativeApp.postMessage('notifications:enable')
        window.setTimeout(() => {
            notificationRequesting.value = false
        }, 5000)
        return
    }

    // `enableBrowserPushNotifications` begins Notification.requestPermission
    // synchronously before its first await. Calling it from this explicit
    // button keeps the GPS and notification device prompts separate.
    notificationRequesting.value = true
    void enableBrowserPushNotifications()
        .then((result) => {
            notificationState.value = result?.status || browserNotificationPermission()
        })
        .catch(() => {
            notificationState.value = 'error'
        })
        .finally(() => {
            notificationRequesting.value = false
        })
}

function toggleNotificationPermission() {
    if (notificationsEnabled.value || notificationRequesting.value) return

    // Read the browser value again at the exact tap. Some installed PWAs keep
    // an earlier in-memory value after returning from their settings page.
    notificationState.value = typeof window.NativeApp?.postMessage === 'function'
        ? 'idle'
        : browserNotificationPermission()
    if (notificationState.value === 'denied') return
    requestNotifications({ force: true })
}

function onOperationalLocationRequired() {
    if (!active.value) return

    // An order action needs a fresh server pin. Reopen the branded sheet even
    // when the courier previously dismissed it during this session; this is a
    // new operational requirement, not an unsolicited browser prompt.
    freshLocationRequired.value = true
    completed.value = false
    dismissedForSession.value = false
    clearDismissal()
    showGate.value = true
    void refreshPermission({ reveal: false })
}

function onNativeNotificationState(event) {
    notificationRequesting.value = false
    notificationState.value = event.detail?.enabled ? 'enabled' : 'error'
}

onMounted(() => {
    active.value = true
    dismissedForSession.value = isDismissed()
    notificationState.value = typeof window.NativeApp?.postMessage === 'function'
        ? 'idle'
        : browserNotificationPermission()
    window.addEventListener('almunjaz:location-required', onOperationalLocationRequired)
    window.addEventListener('almunjaz:native-notifications', onNativeNotificationState)

    // Permission inspection is passive. The system dialog is never opened
    // during launch; the courier chooses the visible action in the sheet.
    void refreshPermission()
})

onBeforeUnmount(() => {
    active.value = false
    permissionStatus?.removeEventListener?.('change', onPermissionChange)
    window.removeEventListener('almunjaz:location-required', onOperationalLocationRequired)
    window.removeEventListener('almunjaz:native-notifications', onNativeNotificationState)
})
</script>

<template>
    <SheetModal
        :open="showGate"
        title="تفعيل خدمات التطبيق"
        centered
        wide
        @close="dismissGate"
    >
        <section class="courier-location-gate" :class="[`state-${locationState}`, { ready: isReady }]" aria-live="polite">
            <div class="courier-location-gate-head">
                <span class="courier-location-gate-icon" aria-hidden="true">
                    <svg v-if="isReady" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.35" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4.2 4.2L19.5 6" /></svg>
                    <svg v-else-if="locationState === 'denied'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-5.2 7-11a7 7 0 1 0-14 0c0 5.8 7 11 7 11Z" /><path d="m8.5 6.5 7 7m0-7-7 7" /></svg>
                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 5.2-8 11-8 11S4 15.2 4 10a8 8 0 1 1 16 0Z" /><circle cx="12" cy="10" r="2.5" /></svg>
                </span>
                <span>
                    <b v-if="isReady">{{ t('Location sharing is ready.') }}</b>
                    <b v-else-if="locationState === 'denied'">{{ t('Location permission is blocked') }}</b>
                    <b v-else-if="locationState === 'unsupported'">{{ t('Location sharing is unavailable on this device.') }}</b>
                    <b v-else-if="locationState === 'insecure'">{{ t('Location access requires a secure connection.') }}</b>
                    <b v-else-if="locationState === 'temporary'">{{ t('Location unavailable') }}</b>
                    <b v-else-if="locationState === 'requesting'">{{ t('Requesting…') }}</b>
                    <b v-else>{{ freshLocationRequired ? t('Update location') : t('Location access') }}</b>

                    <p v-if="isReady">{{ t('Your current location was saved securely and location updates can now continue while the installed app is active.') }}</p>
                    <p v-else-if="locationState === 'denied'">{{ t('Open your device or browser settings, allow location access, then return and refresh this screen.') }}</p>
                    <p v-else-if="locationState === 'unsupported'">{{ t('Location access is not available on this device.') }}</p>
                    <p v-else-if="locationState === 'insecure'">{{ t('Location access requires a secure connection.') }}</p>
                    <p v-else-if="locationState === 'temporary' && failureKind === 'timeout'">{{ t('Location request timed out. Move to an open area and try again.') }}</p>
                    <p v-else-if="locationState === 'temporary' && failureKind === 'save'">{{ t('Could not save your location. Check your connection and try again.') }}</p>
                    <p v-else-if="locationState === 'temporary'">{{ t('Could not access your location. Check your device settings and try again.') }}</p>
                    <p v-else-if="locationState === 'requesting'">{{ t('Requesting…') }}</p>
                    <p v-else-if="freshLocationRequired">{{ t('Your current location is required before you can continue with this order.') }}</p>
                    <p v-else>فعّل الموقع والإشعارات لتصلك الطلبات.</p>
                </span>
            </div>

            <section class="courier-permission-switch-card" :class="{ enabled: locationSwitchEnabled, blocked: locationState === 'denied' }">
                <span class="courier-permission-switch-icon location" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 5.2-8 11-8 11S4 15.2 4 10a8 8 0 1 1 16 0Z" /><circle cx="12" cy="10" r="2.5" /></svg>
                </span>
                <span class="courier-permission-switch-copy">
                    <b>الموقع</b>
                    <small v-if="locationSwitchEnabled">تم تفعيل الموقع وتحديثه للتطبيق.</small>
                    <small v-else-if="locationState === 'denied'">فعّل الموقع من إعدادات الهاتف ثم اضغط المفتاح لتحديث الحالة.</small>
                    <small v-else>مطلوب لاستلام الطلبات وإظهار موقعك الحالي للفرع.</small>
                </span>
                <button
                    type="button"
                    class="permission-switch"
                    :class="{ on: locationSwitchEnabled, loading: requesting }"
                    role="switch"
                    :aria-checked="locationSwitchEnabled"
                    aria-label="تفعيل الموقع"
                    :disabled="requesting || locationState === 'unsupported' || locationState === 'insecure'"
                    @click="toggleLocationPermission"
                ><i></i></button>
            </section>

            <section v-if="notificationState !== 'unsupported'" class="courier-permission-switch-card" :class="{ enabled: notificationsEnabled, blocked: notificationState === 'denied' }">
                <span class="courier-permission-switch-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 9a6 6 0 1 0-12 0c0 5-2 6-2 6h16s-2-1-2-6" /><path d="M10 21h4" /></svg>
                </span>
                <span class="courier-permission-switch-copy">
                    <b>الإشعارات</b>
                    <small v-if="notificationsEnabled">تم تفعيل إشعارات الطلبات والحركات الجديدة.</small>
                    <small v-else-if="notificationState === 'denied'">فعّل الإشعارات من إعدادات الهاتف أو المتصفح.</small>
                    <small v-else-if="notificationState === 'error'">تعذر تفعيل الإشعارات، اضغط المفتاح للمحاولة مرة أخرى.</small>
                    <small v-else>استلم تنبيهًا فور وصول طلب أو حركة جديدة.</small>
                </span>
                <button
                    type="button"
                    class="permission-switch"
                    :class="{ on: notificationsEnabled, loading: notificationRequesting }"
                    role="switch"
                    :aria-checked="notificationsEnabled"
                    aria-label="تفعيل الإشعارات"
                    :disabled="notificationRequesting || notificationState === 'denied'"
                    @click="toggleNotificationPermission"
                ><i></i></button>
            </section>

            <footer class="courier-location-gate-actions">
                <button type="button" class="courier-location-dismiss" @click="dismissGate">
                    {{ isReady ? t('Close') : t('Later') }}
                </button>
            </footer>
        </section>
    </SheetModal>
</template>

<style scoped>
.courier-location-gate{display:grid;gap:12px;padding:1px 1px 3px}.courier-location-gate-head{display:flex;align-items:flex-start;gap:11px;padding:14px;border:1px solid color-mix(in srgb,var(--primary) 28%,var(--border));border-radius:16px;background:linear-gradient(135deg,color-mix(in srgb,var(--primary-tint) 78%,var(--surface)),var(--surface))}.courier-location-gate.ready .courier-location-gate-head{border-color:color-mix(in srgb,var(--success) 45%,var(--border));background:linear-gradient(135deg,color-mix(in srgb,var(--success-tint) 72%,var(--surface)),var(--surface))}.courier-location-gate.state-denied .courier-location-gate-head{border-color:color-mix(in srgb,var(--danger) 36%,var(--border));background:linear-gradient(135deg,color-mix(in srgb,var(--danger) 8%,var(--surface)),var(--surface))}.courier-location-gate-icon{display:grid;place-items:center;width:43px;height:43px;flex:none;border-radius:14px;background:var(--primary);color:#fff;box-shadow:0 7px 17px color-mix(in srgb,var(--primary) 28%,transparent)}.ready .courier-location-gate-icon{background:var(--success);box-shadow:0 7px 17px color-mix(in srgb,var(--success) 27%,transparent)}.state-denied .courier-location-gate-icon{background:var(--danger);box-shadow:0 7px 17px color-mix(in srgb,var(--danger) 22%,transparent)}.courier-location-gate-icon svg{width:23px;height:23px}.courier-location-gate-head>span:last-child{display:grid;min-width:0;gap:4px}.courier-location-gate-head b{color:var(--ink);font-size:13px;font-weight:950;line-height:1.35}.courier-location-gate-head p{margin:0;color:var(--ink-soft);font-size:10px;font-weight:700;line-height:1.65}.courier-permission-switch-card{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:10px;align-items:center;padding:12px;border:1px solid var(--border);border-radius:14px;background:var(--surface-2);transition:.18s ease}.courier-permission-switch-card.enabled{border-color:color-mix(in srgb,var(--success) 42%,var(--border));background:color-mix(in srgb,var(--success-tint) 72%,var(--surface))}.courier-permission-switch-card.blocked{border-color:color-mix(in srgb,var(--danger) 36%,var(--border))}.courier-permission-switch-icon{display:grid;place-items:center;width:34px;height:34px;border-radius:11px;background:var(--primary-tint);color:var(--primary-strong)}.courier-permission-switch-icon.location{background:color-mix(in srgb,var(--primary-tint) 82%,var(--surface))}.enabled .courier-permission-switch-icon{background:var(--success);color:#fff}.courier-permission-switch-icon svg{width:18px;height:18px}.courier-permission-switch-copy{display:grid;min-width:0;gap:3px}.courier-permission-switch-copy b{color:var(--ink);font-size:11px;font-weight:950}.courier-permission-switch-copy small{color:var(--ink-soft);font-size:9px;font-weight:650;line-height:1.45}.permission-switch{position:relative;width:47px;height:27px;flex:none;padding:0;border:0;border-radius:999px;background:#b8c5c8;cursor:pointer;transition:.18s ease}.permission-switch i{position:absolute;top:4px;right:4px;width:19px;height:19px;border-radius:50%;background:#fff;box-shadow:0 2px 5px rgba(0,0,0,.18);transition:.18s ease}.permission-switch.on{background:var(--success)}.permission-switch.on i{right:24px}.permission-switch.loading{opacity:.7;cursor:wait}.permission-switch:disabled:not(.on){cursor:not-allowed;opacity:.58}.permission-switch:focus-visible{outline:3px solid color-mix(in srgb,var(--primary) 35%,transparent);outline-offset:3px}.courier-location-gate-actions{display:flex;justify-content:flex-end}.courier-location-dismiss{min-height:38px;padding:9px 15px;border:1px solid var(--border);border-radius:11px;background:var(--surface);color:var(--ink-soft);font:850 10px var(--font);cursor:pointer}.courier-location-dismiss:active,.permission-switch:active{transform:scale(.98)}@media (max-width:360px){.courier-location-gate-head{padding:12px}.courier-permission-switch-card{gap:8px;padding:10px}.permission-switch{width:44px}.permission-switch.on i{right:21px}}
</style>
