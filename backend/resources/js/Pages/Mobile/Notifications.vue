<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppShell from '../../Components/AppShell.vue'
import PushNotificationSettings from '../../Components/PushNotificationSettings.vue'

const props = defineProps({
    notifications: { type: Array, default: () => [] },
    unread: { type: Number, default: 0 },
})

let refreshTimer

const typeMeta = computed(() => ({
    order: { tint: 'var(--primary-tint)', color: 'var(--primary-strong)', icon: 'M21 8 12 3 3 8v8l9 5 9-5V8Z M3 8l9 5 9-5 M12 13v8' },
    account: { tint: 'var(--warning-tint)', color: 'var(--warning)', icon: 'M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-3.5-8-9V6l-8-3Z' },
    chat: { tint: 'var(--st-approved-tint)', color: 'var(--st-approved)', icon: 'M21 12a8 8 0 0 1-8 8H4l1.5-3.5A8 8 0 1 1 21 12Z' },
    finance: { tint: 'var(--success-tint)', color: 'var(--success)', icon: 'M3 6h18v12H3z M3 10h18 M7 15h4' },
    wallet: { tint: 'var(--success-tint)', color: 'var(--success)', icon: 'M20 7H6a2 2 0 0 1-2-2 2 2 0 0 1 2-2h13v3 M20 7a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6 M16 14h.01' },
    welcome: { tint: 'var(--primary-tint)', color: 'var(--primary-strong)', icon: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 9a7 7 0 0 1 14 0' },
}))

function meta(type) {
    return typeMeta.value[type] || {
        tint: 'var(--surface-2)', color: 'var(--ink-soft)', icon: 'M12 5v14M5 12h14',
    }
}

function refresh() {
    if (document.visibilityState !== 'visible') return

    router.reload({
        only: ['notifications', 'unread', 'notificationUnread'],
        preserveScroll: true,
        preserveState: true,
    })
}

function markAll() {
    router.post(route('app.notifications.read-all'), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: refresh,
    })
}

function onVisibilityChange() {
    refresh()
}

onMounted(() => {
    // Keep the in-app inbox current while the user is actively using it.
    // Background delivery remains the responsibility of the PWA push layer.
    refreshTimer = window.setInterval(refresh, 15000)
    document.addEventListener('visibilitychange', onVisibilityChange)
})

onBeforeUnmount(() => {
    window.clearInterval(refreshTimer)
    document.removeEventListener('visibilitychange', onVisibilityChange)
})
</script>

<template>
    <AppShell
        :title="t('Notifications')"
        :back="true"
        :back-url="route('app.profile')"
        :notification-badge="unread"
        :show-notif="false"
    >
        <PushNotificationSettings />

        <section class="notifications-head">
            <div>
                <h2>{{ t('All Notifications') }}</h2>
                <p>{{ notifications.length }} {{ t('Notifications') }}</p>
            </div>
            <button v-if="unread > 0" class="mark-read" type="button" @click="markAll">
                {{ t('Mark all as read') }}
            </button>
        </section>

        <section v-if="notifications.length" class="notification-list" :aria-label="t('Notifications')">
            <article v-for="n in notifications" :key="n.id" class="notif-item" :class="{ unread: !n.read }">
                <div class="notif-ic" :style="{ background: meta(n.type).tint, color: meta(n.type).color }">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="meta(n.type).icon" />
                    </svg>
                </div>
                <div class="notif-body">
                    <b>{{ n.title }}</b>
                    <span>{{ n.body }}</span>
                    <time>{{ n.time }}</time>
                </div>
                <i v-if="!n.read" class="unread-dot" :aria-label="t('Notifications')" />
            </article>
        </section>

        <section v-else class="notifications-empty">
            <div class="empty-icon">
                <svg width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6Zm5 11a2 2 0 0 0 4 0" />
                </svg>
            </div>
            <b>{{ t('No notifications yet') }}</b>
        </section>
    </AppShell>
</template>

<style scoped>
.notifications-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.notifications-head h2{font-size:14.5px;font-weight:900;line-height:1.35}.notifications-head p{margin-top:2px;color:var(--ink-soft);font-size:10.5px;font-weight:700}.mark-read{padding:8px 10px;border:1px solid var(--border);border-radius:10px;background:var(--surface);color:var(--primary-strong);font:inherit;font-size:10px;font-weight:850;white-space:nowrap;box-shadow:var(--shadow)}.mark-read:active{background:var(--primary-tint);transform:scale(.98)}.notification-list{display:grid;gap:9px}.notif-item{display:flex;align-items:flex-start;gap:11px;padding:13px;border:1px solid var(--border);border-radius:13px;background:var(--surface);box-shadow:0 2px 9px rgba(15,27,26,.025);transition:background .16s,border-color .16s}.notif-item.unread{border-color:color-mix(in srgb,var(--primary) 28%,var(--border));background:var(--primary-tint)}.notif-ic{display:grid;place-items:center;width:38px;height:38px;border-radius:11px;flex:none}.notif-body{min-width:0;flex:1}.notif-body b{display:block;font-size:12.5px;font-weight:900;line-height:1.45}.notif-body span{display:block;margin-top:2px;color:var(--ink-soft);font-size:11px;font-weight:700;line-height:1.6}.notif-body time{display:block;margin-top:4px;color:var(--ink-faint);font-size:9.5px;font-weight:750}.unread-dot{display:block;width:8px;height:8px;margin-top:4px;border-radius:50%;background:var(--primary);box-shadow:0 0 0 3px color-mix(in srgb,var(--primary) 15%,transparent);flex:none}.notifications-empty{display:flex;min-height:245px;align-items:center;justify-content:center;flex-direction:column;gap:10px;color:var(--ink-faint);text-align:center}.notifications-empty b{font-size:12px;font-weight:850}.empty-icon{display:grid;place-items:center;width:58px;height:58px;border-radius:18px;background:var(--surface-2);color:var(--ink-soft)}
</style>
