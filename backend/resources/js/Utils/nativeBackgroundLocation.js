/**
 * Contract for the future Capacitor/native shell.
 *
 * A browser PWA cannot keep JavaScript alive after the operating system has
 * terminated it.  When the same Vue app is wrapped for Android, the native
 * layer exposes `window.AlMunjazNativeLocation` with `start` and `stop`.
 * It dispatches `almunjaz:native-location` events whose detail is:
 * `{ latitude, longitude, accuracy }`.  The server endpoint and payload stay
 * identical, so moving from PWA to the installed native shell needs no API or
 * dashboard rewrite.
 */
const EVENT_NAME = 'almunjaz:native-location'
let locationListener = null

function bridge() {
    if (typeof window === 'undefined') return null

    const candidate = window.AlMunjazNativeLocation
    return candidate && typeof candidate.start === 'function' && typeof candidate.stop === 'function'
        ? candidate
        : null
}

export async function startNativeBackgroundLocation(onLocation) {
    const nativeBridge = bridge()
    if (!nativeBridge) return false

    if (locationListener) window.removeEventListener(EVENT_NAME, locationListener)
    locationListener = (event) => onLocation(event.detail || {})
    window.addEventListener(EVENT_NAME, locationListener)

    try {
        await nativeBridge.start({
            distanceFilterMeters: 25,
            intervalMilliseconds: 45_000,
        })

        return true
    } catch (_) {
        window.removeEventListener(EVENT_NAME, locationListener)
        locationListener = null
        return false
    }
}

export async function stopNativeBackgroundLocation() {
    const nativeBridge = bridge()
    if (locationListener) {
        window.removeEventListener(EVENT_NAME, locationListener)
        locationListener = null
    }
    if (!nativeBridge) return

    try {
        await nativeBridge.stop()
    } catch (_) {
        // The courier can always disable sharing. A native stop failure must
        // not prevent the server-side last pin from being removed.
    }
}
