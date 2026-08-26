<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Flash from './Flash.vue'
import LiveNotificationBridge from './LiveNotificationBridge.vue'

const props = defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    back: { type: Boolean, default: false },
    backUrl: { type: String, default: '' },
    hideTabs: { type: Boolean, default: false },
    contentClass: { type: [String, Array, Object], default: '' },
    // `notifBadge` is retained for the chat tab.  Notification counts are
    // shared independently so the bell never accidentally shows chat data.
    notifBadge: { type: Number, default: 0 },
    notificationBadge: { type: Number, default: null },
    showNotif: { type: Boolean, default: true },
})

const page = usePage()
const user = computed(() => page.props.auth?.user)
// Pickup, delivery and transporter accounts share the courier mobile shell;
// their allowed work is still limited server-side by their operational role.
const isCourier = computed(() => ['courier', 'pickup_courier', 'delivery_courier', 'transporter'].includes(user.value?.role))
const locale = computed(() => page.props.locale || 'ar')
const theme = ref(user.value?.theme || document.body?.dataset.theme || 'light')
const liveNotificationUnread = ref(Number(page.props.notificationUnread || 0))
const notificationUnread = computed(() => {
    const override = props.notificationBadge
    if (Number.isFinite(override)) return Math.max(0, override)

    return Math.max(0, Number(liveNotificationUnread.value || 0))
})

const tabs = computed(() => {
    const base = [
        { label: t('Home'), icon: 'home', route: 'app' },
        { label: isCourier.value ? t('My Deliveries') : t('My Orders'), icon: 'box', route: 'app.orders' },
        isCourier.value
            ? { label: t('Wallet'), icon: 'wallet', route: 'app.wallet' }
            : { label: t('Archive'), icon: 'archive', route: 'app.reports' },
        { label: t('Chat'), icon: 'chat', route: 'app.chats' },
        { label: t('Profile'), icon: 'user', route: 'app.profile' },
    ]
    return base.map((x) => ({ ...x, url: route(x.route) }))
})

const currentPath = computed(() => new URL(page.url, window.location.origin).pathname)

function active(tab) {
    return currentPath.value === new URL(tab.url, window.location.origin).pathname
}

function applyTheme(value) {
    document.documentElement.dataset.theme = value
    document.body.dataset.theme = value
}

function toggleTheme() {
    theme.value = theme.value === 'dark' ? 'light' : 'dark'
    applyTheme(theme.value)
    router.post(route('profile.theme'), { theme: theme.value }, { preserveScroll: true, preserveState: true })
}

function goBack() {
    if (props.backUrl) {
        router.visit(props.backUrl)
        return
    }

    router.visit(route('app'))
}

function syncLiveNotificationCount(event) {
    const unread = Number(event.detail?.unread)
    if (Number.isFinite(unread)) liveNotificationUnread.value = Math.max(0, unread)
}

onMounted(() => {
    applyTheme(theme.value)
    window.addEventListener('almunjaz:notification-count', syncLiveNotificationCount)
})

onBeforeUnmount(() => {
    window.removeEventListener('almunjaz:notification-count', syncLiveNotificationCount)
})

// Profile settings may update the shared Inertia user object without
// recreating this shell.  Keep the visual preference in sync in that case.
watch(() => user.value?.theme, (value) => {
    if (value && value !== theme.value) {
        theme.value = value
        applyTheme(value)
    }
})

watch(() => page.props.notificationUnread, (value) => {
    const unread = Number(value)
    if (Number.isFinite(unread)) liveNotificationUnread.value = Math.max(0, unread)
})

function icon(name) {
    const paths = {
        home: 'M3 11.2 12 4l9 7.2V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1v-8.8Z',
        box: 'M21 8 12 3 3 8v8l9 5 9-5V8Z M3 8l9 5 9-5 M12 13v8',
        wallet: 'M20 7H6a2 2 0 0 1-2-2 2 2 0 0 1 2-2h13v3 M20 7a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6 M16 14h.01',
        archive: 'M4 5.5A1.5 1.5 0 0 1 5.5 4h13A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 18.5v-13ZM8 9h8M8 12h8M8 15h5',
        chat: 'M21 12a8 8 0 0 1-8 8H4l1.5-3.5A8 8 0 1 1 21 12Z',
        user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-8 8a8 8 0 0 1 16 0',
        bell: 'M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6Zm5 11a2 2 0 0 0 4 0',
        arrow: 'M19 12H5m0 0 6-6m-6 6 6 6',
    }
    return paths[name] || paths.home
}
</script>

<template>
    <div class="app-stage">
        <div class="app-shell">
            <Flash />
            <LiveNotificationBridge />
            <header class="app-topbar">
                <button v-if="back" class="tb-icon-btn" type="button" :aria-label="t('Back')" @click="goBack">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" :style="{ transform: locale === 'ar' ? 'rotate(180deg)' : '' }">
                        <path d="M19 12H5m0 0 6-6m-6 6 6 6" />
                    </svg>
                </button>
                <div class="tb-title" :class="{ 'with-back': back }">
                    <slot name="title">{{ title }}</slot>
                    <span v-if="subtitle" class="tb-sub">{{ subtitle }}</span>
                </div>
                <div class="tb-actions">
                    <a v-if="showNotif" class="tb-icon-btn" :href="route('app.notifications')" :aria-label="t('Notifications')">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path :d="icon('bell')" />
                        </svg>
                        <span v-if="notificationUnread > 0" class="notif-badge">{{ notificationUnread > 99 ? '99+' : notificationUnread }}</span>
                    </a>
                    <button class="tb-icon-btn" type="button" :aria-label="theme === 'dark' ? t('Enable light mode') : t('Enable dark mode')" @click="toggleTheme">
                        <svg v-if="theme !== 'dark'" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="4.5" /><path d="M12 1.5v2.5M12 20v2.5M4.2 4.2 6 6M18 18l1.8 1.8M1.5 12H4M20 12h2.5M4.2 19.8 6 18M18 6l1.8-1.8" />
                        </svg>
                        <svg v-else width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20.5 14.2A8.6 8.6 0 0 1 9.8 3.5 8.7 8.7 0 1 0 20.5 14.2Z" />
                        </svg>
                    </button>
                </div>
                <slot name="actions" />
            </header>

            <main class="app-content" :class="[{ 'without-tabs': hideTabs }, contentClass]">
                <slot />
            </main>

            <slot name="fab" />

            <nav v-if="!hideTabs" class="bottom-tabs">
                <button v-for="tab in tabs" :key="tab.route" class="tab-btn" :class="{ active: active(tab) }" @click="$inertia.visit(tab.url)">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="icon(tab.icon)" />
                    </svg>
                    <span class="tlabel">{{ tab.label }}</span>
                    <span v-if="tab.route === 'app.chats' && notifBadge > 0" class="tdot">{{ notifBadge }}</span>
                </button>
            </nav>
        </div>
    </div>
</template>
