<script setup>
import { computed, onMounted, ref } from 'vue'
import { route } from 'ziggy-js'

const status = ref('loading')
const message = ref('')
const config = ref(null)

const notificationSupported = computed(() => typeof window !== 'undefined' && 'Notification' in window)
const pushSupported = computed(() => notificationSupported.value
    && 'serviceWorker' in navigator
    && 'PushManager' in window)
const permission = computed(() => notificationSupported.value ? Notification.permission : 'unsupported')

function base64UrlToUint8Array(value) {
    const padding = '='.repeat((4 - (value.length % 4)) % 4)
    const normalized = (value + padding).replace(/-/g, '+').replace(/_/g, '/')
    const raw = atob(normalized)
    return Uint8Array.from(raw, (character) => character.charCodeAt(0))
}

async function load() {
    if (!notificationSupported.value) {
        status.value = 'unsupported'
        return
    }

    if (permission.value === 'denied') {
        status.value = 'denied'
        return
    }

    // Permission belongs to the device and can be granted even while the
    // optional push delivery service is still being configured.
    if (!pushSupported.value) {
        status.value = permission.value === 'granted' ? 'permission-granted' : 'idle'
        return
    }

    try {
        const response = await window.axios.get(route('app.push.config'))
        config.value = response.data

        if (!config.value?.enabled || !config.value.publicKey) {
            status.value = permission.value === 'granted' ? 'permission-granted' : 'idle'
            return
        }

        const registration = await navigator.serviceWorker.ready
        const subscription = await registration.pushManager.getSubscription()
        status.value = subscription && permission.value === 'granted' ? 'enabled' : (permission.value === 'granted' ? 'permission-granted' : 'idle')
    } catch (_) {
        status.value = permission.value === 'granted' ? 'permission-granted' : 'idle'
    }
}

async function enable() {
    message.value = ''
    if (!notificationSupported.value) {
        status.value = 'unsupported'
        return
    }

    try {
        const grant = await Notification.requestPermission()
        if (grant !== 'granted') {
            status.value = 'denied'
            return
        }

        if (!pushSupported.value) {
            status.value = 'permission-granted'
            return
        }

        status.value = 'saving'
        if (!config.value?.enabled || !config.value.publicKey) await load()
        if (!config.value?.enabled || !config.value.publicKey) {
            status.value = 'permission-granted'
            return
        }

        const registration = await navigator.serviceWorker.ready
        let subscription = await registration.pushManager.getSubscription()
        if (!subscription) {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: base64UrlToUint8Array(config.value.publicKey),
            })
        }

        const serialized = subscription.toJSON()
        await window.axios.post(route('app.push.subscribe'), {
            endpoint: serialized.endpoint,
            keys: serialized.keys,
            locale: document.documentElement.lang || 'ar',
        })
        status.value = 'enabled'
    } catch (_) {
        status.value = 'error'
        message.value = window.t?.('Could not enable notifications. Please try again.')
            || 'Could not enable notifications. Please try again.'
    }
}

async function disable() {
    try {
        const registration = await navigator.serviceWorker.ready
        const subscription = await registration.pushManager.getSubscription()
        if (subscription) {
            await window.axios.delete(route('app.push.unsubscribe'), { data: { endpoint: subscription.endpoint } })
            await subscription.unsubscribe()
        }
        status.value = 'idle'
    } catch (_) {
        status.value = 'error'
        message.value = window.t?.('Could not change notification settings. Please try again.')
            || 'Could not change notification settings. Please try again.'
    }
}

onMounted(load)
</script>

<template>
    <section class="push-setting" :class="{ enabled: status === 'enabled' }">
        <div class="push-setting-icon" aria-hidden="true">♬</div>
        <div class="push-setting-copy">
            <b>{{ t('Phone notifications') }}</b>
            <small v-if="status === 'enabled'">{{ t('Notifications are enabled on this device.') }}</small>
            <small v-else-if="status === 'permission-granted'">{{ t('Notifications are allowed on this device. Push delivery will start when it is available.') }}</small>
            <small v-else-if="status === 'denied'">{{ t('Allow notifications from the browser settings to receive alerts.') }}</small>
            <small v-else-if="status === 'unsupported'">{{ t('Notifications are not available on this device.') }}</small>
            <small v-else>{{ t('Receive a banner and the device notification sound for new updates.') }}</small>
            <small v-if="message" class="push-error">{{ message }}</small>
        </div>
        <button
            v-if="status === 'enabled'"
            type="button"
            class="push-switch is-on"
            role="switch"
            :aria-checked="true"
            :aria-label="t('Phone notifications')"
            @click="disable"
        ><span /></button>
        <span
            v-else-if="status === 'permission-granted'"
            class="push-switch is-on is-locked"
            role="switch"
            :aria-checked="true"
            :aria-label="t('Phone notifications')"
        ><span /></span>
        <button
            v-else-if="status !== 'loading' && status !== 'saving' && status !== 'unsupported'"
            type="button"
            class="push-switch"
            role="switch"
            :aria-checked="false"
            :aria-label="t('Phone notifications')"
            @click="enable"
        ><span /></button>
        <span v-else class="push-state">{{ status === 'saving' ? t('Saving…') : t('…') }}</span>
    </section>
</template>

<style scoped>
.push-setting{display:flex;align-items:center;gap:10px;margin-bottom:12px;padding:12px;border:1px solid var(--border);border-radius:13px;background:var(--surface);box-shadow:0 2px 9px rgba(15,27,26,.025)}.push-setting.enabled{border-color:color-mix(in srgb,var(--success) 38%,var(--border));background:color-mix(in srgb,var(--success-tint) 60%,var(--surface))}.push-setting-icon{display:grid;place-items:center;width:36px;height:36px;border-radius:11px;flex:none;background:var(--primary-tint);color:var(--primary-strong);font-size:18px;font-weight:900}.push-setting-copy{display:grid;min-width:0;flex:1;gap:2px}.push-setting-copy b{font-size:11.5px;font-weight:900}.push-setting-copy small{font-size:9.5px;line-height:1.55;color:var(--ink-soft);font-weight:650}.push-setting-copy .push-error{color:var(--danger)}.push-switch{position:relative;display:inline-flex;align-items:center;width:42px;height:24px;padding:3px;flex:none;border:0;border-radius:999px;background:var(--border-strong,var(--border));cursor:pointer;transition:background .2s ease,box-shadow .2s ease}.push-switch span{display:block;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 4px rgba(15,27,26,.26);transform:translateX(0);transition:transform .2s ease}.push-switch.is-on{background:var(--success)}.push-switch.is-on span{transform:translateX(-18px)}html[dir="ltr"] .push-switch.is-on span{transform:translateX(18px)}.push-switch:focus-visible{outline:3px solid color-mix(in srgb,var(--primary) 35%,transparent);outline-offset:2px}.push-switch.is-locked{cursor:default;opacity:.82}.push-state{font-size:11px;color:var(--ink-faint);font-weight:800}
</style>
