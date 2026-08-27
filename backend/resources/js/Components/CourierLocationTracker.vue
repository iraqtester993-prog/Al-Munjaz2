<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { startNativeBackgroundLocation, stopNativeBackgroundLocation } from '../Utils/nativeBackgroundLocation'

const page = usePage()
const user = computed(() => page.props.auth?.user || {})
const isCourier = computed(() => ['courier', 'pickup_courier', 'delivery_courier', 'transporter'].includes(user.value?.role))
const preferenceKey = computed(() => `almunjaz:location-sharing-enabled:${user.value?.id || 'guest'}`)

let watchId = null
let sentAt = 0
let lastPosition = null
let sending = false
let sessionPermissionGranted = false
let trackingAttempt = 0
let nativeTracking = false

function sharingEnabled() {
    try {
        return window.localStorage.getItem(preferenceKey.value) === 'true'
    } catch (_) {
        return false
    }
}

function stopTracking() {
    trackingAttempt += 1

    if (watchId !== null && navigator.geolocation) {
        navigator.geolocation.clearWatch(watchId)
    }

    watchId = null
    nativeTracking = false
    stopNativeBackgroundLocation()
}

function distanceMeters(from, to) {
    const radians = (value) => value * (Math.PI / 180)
    const earthRadius = 6_371_000
    const latDelta = radians(to.latitude - from.latitude)
    const longDelta = radians(to.longitude - from.longitude)
    const a = Math.sin(latDelta / 2) ** 2
        + Math.cos(radians(from.latitude)) * Math.cos(radians(to.latitude)) * Math.sin(longDelta / 2) ** 2

    return 2 * earthRadius * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a))
}

function shouldShare(next) {
    if (!lastPosition) return true
    if (Date.now() - sentAt >= 45_000) return true

    return distanceMeters(lastPosition, next) >= 25
}

async function shareCoordinates(position) {
    const latitude = Number(position?.latitude)
    const longitude = Number(position?.longitude)
    const accuracy = Math.round(Number(position?.accuracy || position?.accuracy_meters || 0))

    if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || sending) return

    const next = { latitude, longitude }
    if (!shouldShare(next)) return

    sending = true
    try {
        await window.axios.post(route('app.location.update'), {
            latitude,
            longitude,
            accuracy_meters: Math.max(0, Math.min(accuracy, 50_000)),
        })
        lastPosition = next
        sentAt = Date.now()
        window.dispatchEvent(new CustomEvent('almunjaz:location-shared', {
            detail: { ...next, accuracy, updatedAt: new Date().toISOString() },
        }))
    } catch (_) {
        // A temporary offline/authenticated request error must never cause a
        // browser permission prompt or turn GPS into a noisy UI failure.
    } finally {
        sending = false
    }
}

function share(position) {
    return shareCoordinates({
        latitude: position.coords?.latitude,
        longitude: position.coords?.longitude,
        accuracy: position.coords?.accuracy,
    })
}

async function hasGrantedLocationPermission() {
    // Modern browsers let us verify this without touching the location API.
    // That ensures a stale local preference can never make the app prompt on
    // launch after the account owner revoked access in device settings.
    try {
        if (navigator.permissions?.query) {
            const permission = await navigator.permissions.query({ name: 'geolocation' })
            return permission.state === 'granted'
        }
    } catch (_) {
        // Fall through to the explicit same-session grant below.
    }

    // Browsers without the Permissions API only track after a successful
    // profile-button request in this page session. On a fresh launch they do
    // nothing rather than triggering a surprise permission prompt.
    return sessionPermissionGranted
}

async function startTracking() {
    stopTracking()
    const attempt = trackingAttempt

    // Location is never requested implicitly.  The account owner enables it
    // from Profile first; until then this component is completely inert.
    if (!isCourier.value || !sharingEnabled() || !navigator.geolocation) return
    if (!(await hasGrantedLocationPermission()) || attempt !== trackingAttempt) return

    // The asynchronous permission check above may complete after a role,
    // preference, or visibility change. Verify the inert conditions again
    // immediately before starting the native watcher.
    if (!isCourier.value || !sharingEnabled() || attempt !== trackingAttempt) return

    // The future Capacitor shell supplies this bridge and keeps the native
    // location service alive after the web view is closed. Until then, the
    // PWA uses the browser watcher; mobile browsers may throttle it while the
    // app is backgrounded and can stop it after full termination.
    nativeTracking = await startNativeBackgroundLocation(shareCoordinates)
    if (attempt !== trackingAttempt) {
        if (nativeTracking) stopNativeBackgroundLocation()
        nativeTracking = false
        return
    }
    if (nativeTracking) return

    watchId = navigator.geolocation.watchPosition(
        share,
        () => {
            // Permission state is represented in Profile. Do not retry or
            // surface an intrusive prompt while the courier is working.
        },
        {
            enableHighAccuracy: true,
            maximumAge: 20_000,
            timeout: 16_000,
        },
    )
}

function onLocationPreferenceChanged(event) {
    if (event.detail?.enabled && event.detail?.userInitiated) sessionPermissionGranted = true
    if (!event.detail?.enabled) sessionPermissionGranted = false
    startTracking()
}

function onVisibilityChanged() {
    // A browser may resume a throttled PWA location watcher when it becomes
    // visible again. Do not stop it merely because the app was minimized.
    if (!nativeTracking && document.visibilityState === 'visible') startTracking()
}

onMounted(() => {
    window.addEventListener('almunjaz:location-sharing-changed', onLocationPreferenceChanged)
    document.addEventListener('visibilitychange', onVisibilityChanged)
    startTracking()
})

onBeforeUnmount(() => {
    stopTracking()
    window.removeEventListener('almunjaz:location-sharing-changed', onLocationPreferenceChanged)
    document.removeEventListener('visibilitychange', onVisibilityChanged)
})
</script>

<template>
    <!-- Intentionally visual-free: location sharing is controlled in Profile. -->
    <span hidden aria-hidden="true" />
</template>
