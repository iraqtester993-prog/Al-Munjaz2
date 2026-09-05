import { route } from 'ziggy-js'

const SERVICE_WORKER_READY_TIMEOUT_MS = 10_000

function isNativeApp() {
    return typeof window !== 'undefined' && typeof window.NativeApp?.postMessage === 'function'
}

/**
 * Browser push is deliberately kept behind a user gesture by callers.  This
 * module only talks to the device/browser APIs and the existing application
 * subscription endpoints; it does not render any application UI.
 */
export function supportsNotificationPermission() {
    return typeof window !== 'undefined' && 'Notification' in window
}

export function supportsBrowserPush() {
    return supportsNotificationPermission()
        && 'serviceWorker' in navigator
        && 'PushManager' in window
}

export function browserNotificationPermission() {
    return supportsNotificationPermission() ? Notification.permission : 'unsupported'
}

function base64UrlToUint8Array(value) {
    const padding = '='.repeat((4 - (value.length % 4)) % 4)
    const normalized = (value + padding).replace(/-/g, '+').replace(/_/g, '/')
    const raw = atob(normalized)

    return Uint8Array.from(raw, (character) => character.charCodeAt(0))
}

async function loadPushConfig() {
    const response = await window.axios.get(route('app.push.config'))

    return response.data
}

function waitForServiceWorkerReady(timeoutMs = SERVICE_WORKER_READY_TIMEOUT_MS) {
    return new Promise((resolve, reject) => {
        const timeout = setTimeout(() => {
            reject(new Error('Timed out waiting for the service worker to become ready.'))
        }, timeoutMs)

        navigator.serviceWorker.ready.then(
            (registration) => {
                clearTimeout(timeout)
                resolve(registration)
            },
            (error) => {
                clearTimeout(timeout)
                reject(error)
            },
        )
    })
}

/**
 * Read the current device and application subscription state without
 * requesting a browser permission prompt.
 */
export async function loadBrowserPushNotificationStatus() {
    if (isNativeApp()) {
        return { status: 'enabled', config: null }
    }

    if (!supportsNotificationPermission()) {
        return { status: 'unsupported', config: null }
    }

    const permission = browserNotificationPermission()
    if (permission === 'denied') {
        return { status: 'denied', config: null }
    }

    // Device permission can be granted even before the optional web-push
    // delivery service is available for this deployment.
    if (!supportsBrowserPush()) {
        return { status: permission === 'granted' ? 'permission-granted' : 'idle', config: null }
    }

    try {
        const config = await loadPushConfig()
        if (!config?.enabled || !config.publicKey) {
            return { status: permission === 'granted' ? 'permission-granted' : 'idle', config }
        }

        const registration = await waitForServiceWorkerReady()
        const subscription = await registration.pushManager.getSubscription()

        return {
            status: subscription && permission === 'granted' ? 'enabled' : (permission === 'granted' ? 'permission-granted' : 'idle'),
            config,
        }
    } catch (error) {
        // A temporary push-service failure must not make the device permission
        // look denied. The profile UI can still accurately show its state.
        return {
            status: permission === 'granted' ? 'permission-granted' : 'idle',
            config: null,
            error,
        }
    }
}

/**
 * Request the native browser notification permission and, when web push is
 * configured, register this device with the application's existing endpoint.
 * Call this from a genuine user interaction so mobile browsers may display
 * their native permission sheet.
 */
export async function enableBrowserPushNotifications({ config = null } = {}) {
    if (isNativeApp()) {
        window.NativeApp.postMessage('notifications:enable')
        return { status: 'enabled', config: null }
    }

    if (!supportsNotificationPermission()) {
        return { status: 'unsupported', config: null }
    }

    try {
        const permission = await Notification.requestPermission()
        if (permission !== 'granted') {
            return { status: 'denied', config }
        }

        if (!supportsBrowserPush()) {
            return { status: 'permission-granted', config }
        }

        let activeConfig = config
        if (!activeConfig?.enabled || !activeConfig.publicKey) {
            try {
                activeConfig = await loadPushConfig()
            } catch (error) {
                // Match the settings UI's previous behavior: a device can
                // still have allowed notifications while the optional push
                // service configuration is temporarily unavailable.
                return { status: 'permission-granted', config: null, error }
            }
        }

        if (!activeConfig?.enabled || !activeConfig.publicKey) {
            return { status: 'permission-granted', config: activeConfig }
        }

        let registration
        try {
            registration = await waitForServiceWorkerReady()
        } catch (error) {
            // The device has granted notifications, even when a broken or
            // delayed service worker cannot yet finish the push subscription.
            return { status: 'permission-granted', config: activeConfig, error }
        }
        let subscription = await registration.pushManager.getSubscription()

        if (!subscription) {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: base64UrlToUint8Array(activeConfig.publicKey),
            })
        }

        const serialized = subscription.toJSON()
        await window.axios.post(route('app.push.subscribe'), {
            endpoint: serialized.endpoint,
            keys: serialized.keys,
            locale: document.documentElement.lang || 'ar',
        })

        return { status: 'enabled', config: activeConfig }
    } catch (error) {
        return { status: 'error', config, error }
    }
}

/**
 * Remove this browser's server-side subscription and unsubscribe the device.
 */
export async function disableBrowserPushNotifications() {
    if (isNativeApp()) {
        window.NativeApp.postMessage('notifications:disable')
        return { status: 'idle' }
    }

    try {
        const registration = await waitForServiceWorkerReady()
        const subscription = await registration.pushManager.getSubscription()

        if (subscription) {
            await window.axios.delete(route('app.push.unsubscribe'), { data: { endpoint: subscription.endpoint } })
            await subscription.unsubscribe()
        }

        return { status: 'idle' }
    } catch (error) {
        return { status: 'error', error }
    }
}
