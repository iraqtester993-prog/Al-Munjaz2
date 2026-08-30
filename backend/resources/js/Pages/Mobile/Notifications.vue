<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppShell from '../../Components/AppShell.vue'
import PushNotificationSettings from '../../Components/PushNotificationSettings.vue'
import SheetModal from '../../Components/SheetModal.vue'

const props = defineProps({
    notifications: { type: Array, default: () => [] },
    unread: { type: Number, default: 0 },
})

let refreshTimer
const selectedNotification = ref(null)
const readNotificationIds = ref(new Set())
const deletedNotificationIds = ref(new Set())
const readingNotificationIds = ref(new Set())
const deletingNotificationId = ref(null)

const visibleNotifications = computed(() => props.notifications.filter(
    (notification) => !deletedNotificationIds.value.has(notification.id),
))

const unreadCount = computed(() => visibleNotifications.value.filter(
    (notification) => !isRead(notification),
).length)

const manageableUnreadCount = computed(() => visibleNotifications.value.filter(
    (notification) => canManage(notification) && !isRead(notification),
).length)

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

function isRead(notification) {
    return notification.read || readNotificationIds.value.has(notification.id)
}

function canManage(notification) {
    return notification.can_manage === true
}

function addToSet(state, id) {
    state.value = new Set([...state.value, id])
}

function removeFromSet(state, id) {
    const next = new Set(state.value)
    next.delete(id)
    state.value = next
}

function openNotification(notification) {
    selectedNotification.value = notification
    markRead(notification)
}

function closeNotification() {
    selectedNotification.value = null
}

function markRead(notification) {
    if (!canManage(notification) || isRead(notification) || readingNotificationIds.value.has(notification.id)) return

    addToSet(readNotificationIds, notification.id)
    addToSet(readingNotificationIds, notification.id)

    router.patch(route('app.notifications.read', notification.id), {}, {
        preserveScroll: true,
        preserveState: true,
        onError: () => removeFromSet(readNotificationIds, notification.id),
        onFinish: () => removeFromSet(readingNotificationIds, notification.id),
    })
}

function deleteNotification(notification) {
    if (!notification || !canManage(notification) || deletingNotificationId.value) return

    deletingNotificationId.value = notification.id

    router.delete(route('app.notifications.destroy', notification.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            addToSet(deletedNotificationIds, notification.id)
            if (selectedNotification.value?.id === notification.id) {
                closeNotification()
            }
        },
        onFinish: () => {
            deletingNotificationId.value = null
        },
    })
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
        onSuccess: () => {
            readNotificationIds.value = new Set([
                ...readNotificationIds.value,
                ...visibleNotifications.value
                    .filter((notification) => canManage(notification))
                    .map((notification) => notification.id),
            ])
            refresh()
        },
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
        :notification-badge="unreadCount"
        :show-notif="false"
    >
        <PushNotificationSettings />

        <section class="notifications-head">
            <div>
                <h2>{{ t('All Notifications') }}</h2>
                <p>{{ visibleNotifications.length }} {{ t('Notifications') }}</p>
            </div>
            <button v-if="manageableUnreadCount > 0" class="mark-read" type="button" @click="markAll">
                {{ t('Mark all as read') }}
            </button>
        </section>

        <section v-if="visibleNotifications.length" class="notification-list" :aria-label="t('Notifications')">
            <article v-for="n in visibleNotifications" :key="n.id" class="notif-item" :class="{ unread: !isRead(n) }">
                <button class="notif-open" type="button" @click="openNotification(n)">
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
                    <i v-if="!isRead(n)" class="unread-dot" :aria-label="t('Notifications')" />
                </button>
                <button
                    v-if="canManage(n)"
                    class="notif-delete"
                    type="button"
                    :aria-label="t('Delete')"
                    :disabled="deletingNotificationId === n.id"
                    @click.stop="deleteNotification(n)"
                >
                    <svg v-if="deletingNotificationId !== n.id" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2m-7 4v7m4-7v7m4-7v7M5 6l1 15h12l1-15" /></svg>
                    <span v-else class="loader notif-delete-loader" aria-hidden="true"></span>
                </button>
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

        <SheetModal
            :open="!!selectedNotification"
            :title="selectedNotification?.title || ''"
            :subtitle="selectedNotification?.time || ''"
            @close="closeNotification"
        >
            <article v-if="selectedNotification" class="notification-sheet">
                <div class="notification-sheet-icon" :style="{ background: meta(selectedNotification.type).tint, color: meta(selectedNotification.type).color }" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="meta(selectedNotification.type).icon" />
                    </svg>
                </div>
                <p class="notification-sheet-body">{{ selectedNotification.body }}</p>
                <button class="btn btn-primary notification-sheet-close" type="button" @click="closeNotification">
                    {{ t('OK') }}
                </button>
                <button
                    v-if="canManage(selectedNotification)"
                    class="notification-sheet-delete"
                    type="button"
                    :disabled="deletingNotificationId === selectedNotification.id"
                    @click="deleteNotification(selectedNotification)"
                >
                    <span v-if="deletingNotificationId === selectedNotification.id" class="loader" aria-hidden="true"></span>
                    <span v-else>{{ t('Delete') }}</span>
                </button>
            </article>
        </SheetModal>
    </AppShell>
</template>

<style scoped>
.notifications-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.notifications-head h2{font-size:14.5px;font-weight:900;line-height:1.35}.notifications-head p{margin-top:2px;color:var(--ink-soft);font-size:10.5px;font-weight:700}.mark-read{padding:8px 10px;border:1px solid var(--border);border-radius:10px;background:var(--surface);color:var(--primary-strong);font:inherit;font-size:10px;font-weight:850;white-space:nowrap;box-shadow:var(--shadow)}.mark-read:active{background:var(--primary-tint);transform:scale(.98)}.notification-list{display:grid;gap:9px}.notif-item{display:flex;align-items:stretch;gap:7px;padding:8px;border:1px solid var(--border);border-radius:13px;background:var(--surface);box-shadow:0 2px 9px rgba(15,27,26,.025);transition:background .16s,border-color .16s}.notif-item.unread{border-color:color-mix(in srgb,var(--primary) 28%,var(--border));background:var(--primary-tint)}.notif-open{display:flex;min-width:0;flex:1;align-items:flex-start;gap:11px;padding:5px;border:0;background:transparent;color:inherit;font:inherit;text-align:inherit;cursor:pointer}.notif-open:active{opacity:.72}.notif-ic{display:grid;place-items:center;width:38px;height:38px;border-radius:11px;flex:none}.notif-body{min-width:0;flex:1}.notif-body b{display:block;font-size:12.5px;font-weight:900;line-height:1.45}.notif-body span{display:-webkit-box;margin-top:2px;overflow:hidden;color:var(--ink-soft);font-size:11px;font-weight:700;line-height:1.6;-webkit-box-orient:vertical;-webkit-line-clamp:2}.notif-body time{display:block;margin-top:4px;color:var(--ink-faint);font-size:9.5px;font-weight:750}.unread-dot{display:block;width:8px;height:8px;margin-top:4px;border-radius:50%;background:var(--primary);box-shadow:0 0 0 3px color-mix(in srgb,var(--primary) 15%,transparent);flex:none}.notif-delete{display:grid;width:34px;min-height:34px;place-items:center;align-self:center;flex:none;border:0;border-radius:10px;background:transparent;color:var(--ink-faint);cursor:pointer}.notif-delete:active{background:var(--danger-tint,rgba(239,68,68,.12));color:var(--danger);transform:scale(.95)}.notif-delete:disabled{cursor:wait;opacity:.6}.notif-delete-loader{width:14px;height:14px;border-width:2px}.notifications-empty{display:flex;min-height:245px;align-items:center;justify-content:center;flex-direction:column;gap:10px;color:var(--ink-faint);text-align:center}.notifications-empty b{font-size:12px;font-weight:850}.empty-icon{display:grid;place-items:center;width:58px;height:58px;border-radius:18px;background:var(--surface-2);color:var(--ink-soft)}.notification-sheet{display:flex;min-height:180px;flex-direction:column;align-items:center;text-align:center}.notification-sheet-icon{display:grid;width:52px;height:52px;margin:0 auto 16px;place-items:center;border-radius:16px}.notification-sheet-body{width:100%;margin:0;color:var(--ink-soft);font-size:13px;font-weight:700;line-height:1.85;white-space:pre-line}.notification-sheet-close{width:100%;margin-top:auto}.notification-sheet-delete{width:100%;margin-top:10px;padding:8px;border:0;background:transparent;color:var(--danger);font:inherit;font-size:11px;font-weight:850;cursor:pointer}.notification-sheet-delete:disabled{cursor:wait;opacity:.6}
</style>
