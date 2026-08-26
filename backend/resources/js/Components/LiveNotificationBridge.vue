<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'

const page = usePage()
const notifications = ref([])
const latestId = ref(0)
const initialized = ref(false)
let pollTimer
let toneUnlocked = false

function userCanReceive() {
    return ['merchant', 'courier', 'pickup_courier', 'delivery_courier', 'transporter'].includes(page.props.auth?.user?.role)
}

function dismiss(id) {
    notifications.value = notifications.value.filter((notification) => notification.id !== id)
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
    notifications.value.unshift(notification)
    playTone()
    window.setTimeout(() => dismiss(notification.id), 7000)
}

async function poll() {
    if (!userCanReceive()) return

    try {
        const response = await window.axios.get(route('app.notifications.feed'), {
            params: { after: latestId.value },
        })
        const payload = response.data || {}
        const incoming = Array.isArray(payload.notifications) ? payload.notifications : []

        if (!initialized.value) {
            latestId.value = Number(payload.latest_id || latestId.value || 0)
            initialized.value = true
            return
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
    }
}

function refreshOnVisible() {
    if (document.visibilityState === 'visible') poll()
}

onMounted(() => {
    if (!userCanReceive()) return

    window.addEventListener('pointerdown', unlockTone, true)
    window.addEventListener('keydown', unlockTone, true)
    document.addEventListener('visibilitychange', refreshOnVisible)
    poll()
    pollTimer = window.setInterval(poll, 12_000)
})

onBeforeUnmount(() => {
    window.clearInterval(pollTimer)
    window.removeEventListener('pointerdown', unlockTone, true)
    window.removeEventListener('keydown', unlockTone, true)
    document.removeEventListener('visibilitychange', refreshOnVisible)
})
</script>

<template>
    <aside v-if="notifications.length" class="live-notification-stack" aria-live="polite" aria-atomic="false">
        <TransitionGroup name="live-notification">
            <button v-for="notification in notifications" :key="notification.id" type="button" class="live-notification" @click="dismiss(notification.id)">
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
