<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'

const page = usePage()
const notifications = ref([])
const latestId = ref(0)
const initialized = ref(false)
const NOTIFICATION_POLL_INTERVAL = 20_000
const RECENT_NOTIFICATION_INTERVAL = 8_000
const RETRY_MAX_INTERVAL = 60_000
const RECENT_ACTIVITY_WINDOW = 45_000

let pollTimer = null
let polling = false
let disposed = false
let resumeAfterPoll = false
let failureCount = 0
let recentNotificationsUntil = 0
let toneUnlocked = false

function userCanReceive() {
    return ['merchant', 'courier', 'pickup_courier', 'delivery_courier', 'transporter'].includes(page.props.auth?.user?.role)
}

function dismiss(id) {
    notifications.value = notifications.value.filter((notification) => notification.id !== id)
}

function openNotification(notification) {
    dismiss(notification.id)
    const target = notification.target_url || route('app.notifications', { open: notification.id })
    router.get(target, {}, {
        preserveScroll: false,
        preserveState: false,
    })
}

function unlockTone() {
    toneUnlocked = true
    window.removeEventListener('pointerdown', unlockTone, true)
    window.removeEventListener('keydown', unlockTone, true)
}

function playTone() {
    if (!toneUnlocked || document.visibilityState !== 'visible') return

    const AudioContextClass = window.AudioContext || window.webkitAudioContext
    if (!AudioContextClass) return

    try {
        const context = new AudioContextClass()
        const oscillator = context.createOscillator()
        const gain = context.createGain()

        oscillator.type = 'sine'
        oscillator.frequency.value = 760
        gain.gain.setValueAtTime(0.0001, context.currentTime)
        gain.gain.exponentialRampToValueAtTime(0.06, context.currentTime + 0.02)
        gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.28)
        oscillator.connect(gain).connect(context.destination)
        oscillator.start()
        oscillator.stop(context.currentTime + 0.3)
        oscillator.addEventListener('ended', () => context.close())
    } catch (_) {
        // Browsers may refuse foreground audio until the first interaction.
    }
}

function showIncoming(notification) {
    // A burst of notifications must not cover the whole mobile screen. The
    // newest notice replaces the visible toast; every item still remains in
    // the notifications inbox where it can be opened or deleted.
    notifications.value = [notification]
    playTone()
    window.setTimeout(() => dismiss(notification.id), 7000)
}

function isForeground() {
    return document.visibilityState === 'visible'
        && (typeof document.hasFocus !== 'function' || document.hasFocus())
}

function clearPollTimer() {
    if (!pollTimer) return
    window.clearTimeout(pollTimer)
    pollTimer = null
}

function nextPollDelay() {
    if (failureCount > 0) {
        return Math.min(NOTIFICATION_POLL_INTERVAL * (2 ** Math.min(failureCount, 2)), RETRY_MAX_INTERVAL)
    }

    return Date.now() < recentNotificationsUntil
        ? RECENT_NOTIFICATION_INTERVAL
        : NOTIFICATION_POLL_INTERVAL
}

function schedulePoll(delay = nextPollDelay()) {
    clearPollTimer()
    if (disposed || !userCanReceive() || !isForeground()) return

    pollTimer = window.setTimeout(() => {
        pollTimer = null
        poll()
    }, delay)
}

function requestPoll() {
    if (disposed || !userCanReceive() || !isForeground()) {
        clearPollTimer()
        return
    }

    if (polling) {
        resumeAfterPoll = true
        return
    }

    poll()
}

async function poll() {
    if (disposed || !userCanReceive() || !isForeground()) return
    if (polling) {
        resumeAfterPoll = true
        return
    }

    polling = true
    try {
        const response = await window.axios.get(route('app.notifications.feed'), {
            params: { after: latestId.value },
        })
        const payload = response.data || {}
        const incoming = Array.isArray(payload.notifications) ? payload.notifications : []

        failureCount = 0

        if (!initialized.value) {
            latestId.value = Number(payload.latest_id || latestId.value || 0)
            initialized.value = true
            // Keep the notification badge correct on the first, silent sync.
            // Existing inbox entries are not replayed as disruptive toasts.
            window.dispatchEvent(new CustomEvent('almunjaz:notification-count', {
                detail: { unread: Number(payload.unread || 0) },
            }))
            return
        }

        if (incoming.length) {
            recentNotificationsUntil = Date.now() + RECENT_ACTIVITY_WINDOW
        }

        for (const notification of incoming) {
            showIncoming(notification)
        }

        latestId.value = Number(payload.latest_id || latestId.value || 0)
        window.dispatchEvent(new CustomEvent('almunjaz:notification-count', {
            detail: { unread: Number(payload.unread || 0) },
        }))
    } catch (_) {
        // A transient request error should not interrupt the active app.
        failureCount = Math.min(failureCount + 1, 3)
    } finally {
        polling = false
        if (disposed || !userCanReceive() || !isForeground()) return

        const shouldResumeImmediately = resumeAfterPoll
        resumeAfterPoll = false
        schedulePoll(shouldResumeImmediately ? 0 : nextPollDelay())
    }
}

function handleVisibilityChange() {
    if (!isForeground()) {
        clearPollTimer()
        return
    }

    requestPoll()
}

function handleWindowBlur() {
    clearPollTimer()
}

function handleWindowFocus() {
    requestPoll()
}

onMounted(() => {
    if (!userCanReceive()) return

    disposed = false
    window.addEventListener('pointerdown', unlockTone, true)
    window.addEventListener('keydown', unlockTone, true)
    document.addEventListener('visibilitychange', handleVisibilityChange)
    window.addEventListener('blur', handleWindowBlur)
    window.addEventListener('focus', handleWindowFocus)
    window.addEventListener('almunjaz:notification-realtime', requestPoll)
    // The feed is incremental (`after`). Keep it responsive while the app is
    // open, but avoid a permanent five-second request loop on shared hosting.
    requestPoll()
})

onBeforeUnmount(() => {
    disposed = true
    clearPollTimer()
    window.removeEventListener('pointerdown', unlockTone, true)
    window.removeEventListener('keydown', unlockTone, true)
    document.removeEventListener('visibilitychange', handleVisibilityChange)
    window.removeEventListener('blur', handleWindowBlur)
    window.removeEventListener('focus', handleWindowFocus)
    window.removeEventListener('almunjaz:notification-realtime', requestPoll)
})
</script>

<template>
    <aside v-if="notifications.length" class="live-notification-stack" aria-live="polite" aria-atomic="false">
        <TransitionGroup name="live-notification">
            <button v-for="notification in notifications" :key="notification.id" type="button" class="live-notification" @click="openNotification(notification)">
                <span class="live-notification-dot" />
                <span class="live-notification-copy">
                    <b>{{ notification.title }}</b>
                    <small>{{ notification.body }}</small>
                </span>
                <span class="live-notification-close" aria-hidden="true">×</span>
            </button>
        </TransitionGroup>
    </aside>
</template>

<style scoped>
.live-notification-stack{position:fixed;z-index:150;top:calc(env(safe-area-inset-top,0px) + 69px);inset-inline:12px;display:grid;gap:8px;pointer-events:none}.live-notification{display:flex;align-items:flex-start;gap:9px;width:100%;padding:11px 12px;border:1px solid color-mix(in srgb,var(--primary) 34%,var(--border));border-radius:13px;background:color-mix(in srgb,var(--surface) 95%,var(--primary-tint));box-shadow:0 12px 30px rgba(11,43,41,.2);color:var(--ink);text-align:start;pointer-events:auto;font:inherit;cursor:pointer}.live-notification-dot{width:9px;height:9px;border-radius:999px;margin-top:4px;flex:none;background:var(--primary);box-shadow:0 0 0 4px color-mix(in srgb,var(--primary) 16%,transparent)}.live-notification-copy{display:grid;min-width:0;flex:1;gap:2px}.live-notification-copy b,.live-notification-copy small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.live-notification-copy b{font-size:12px;font-weight:950}.live-notification-copy small{font-size:10.5px;color:var(--ink-soft);font-weight:700}.live-notification-close{font-size:19px;line-height:15px;color:var(--ink-faint);font-weight:400}.live-notification-enter-active,.live-notification-leave-active{transition:opacity .2s ease,transform .2s ease}.live-notification-enter-from,.live-notification-leave-to{opacity:0;transform:translateY(-8px)}
</style>
