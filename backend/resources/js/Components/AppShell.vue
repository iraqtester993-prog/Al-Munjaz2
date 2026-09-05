<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Flash from './Flash.vue'
import LiveNotificationBridge from './LiveNotificationBridge.vue'
import CourierLocationTracker from './CourierLocationTracker.vue'
import CourierLocationInstallGate from './CourierLocationInstallGate.vue'
import PwaInstallBanner from './PwaInstallBanner.vue'
import { subscribeToUserRealtime } from '../Utils/realtimeChat'

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
const contentEl = ref(null)
const pullDistance = ref(0)
const isPulling = ref(false)
const isRefreshing = ref(false)
const liveNotificationUnread = ref(Number(page.props.notificationUnread || 0))
const liveChatUnread = ref(Number(props.notifBadge || 0))
let unsubscribeUserRealtime = () => {}
const notificationUnread = computed(() => {
    const override = props.notificationBadge
    if (Number.isFinite(override)) return Math.max(0, override)

    return Math.max(0, Number(liveNotificationUnread.value || 0))
})

const tabs = computed(() => {
    const base = [
        { label: t('Home'), icon: 'home', route: 'app', page: isCourier.value ? 'Mobile/CourierHome' : 'Mobile/MerchantHome' },
        { label: isCourier.value ? t('My Deliveries') : t('My Orders'), icon: 'box', route: 'app.orders', page: 'Mobile/Orders' },
        isCourier.value
            ? { label: t('Wallet'), icon: 'wallet', route: 'app.wallet', page: 'Mobile/Wallet' }
            : { label: t('Archive'), icon: 'archive', route: 'app.reports', page: 'Mobile/Reports' },
        { label: t('Chat'), icon: 'chat', route: 'app.chats', page: 'Mobile/Chats' },
        { label: t('Profile'), icon: 'user', route: 'app.profile', page: 'Mobile/Profile' },
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
    const previous = theme.value
    const next = previous === 'dark' ? 'light' : 'dark'

    // Theme is a visual preference, not a page action.  Saving it through an
    // Inertia visit caused the active screen to be requested and rendered
    // again, which looked like a page reload on mobile.  Persist it quietly
    // while the already-rendered shell updates immediately.
    theme.value = next
    applyTheme(next)

    window.axios.post(route('profile.theme'), { theme: next }).catch(() => {
        // Do not let an older failed request undo a newer user selection.
        if (theme.value !== next) return

        theme.value = previous
        applyTheme(previous)
    })
}

function goBack() {
    if (props.backUrl) {
        visitMobileRoute(props.backUrl)
        return
    }

    visitMobileRoute(route('app'), isCourier.value ? 'Mobile/CourierHome' : 'Mobile/MerchantHome')
}

// Mobile browsers do not provide a reliable native refresh gesture inside an
// app-like, nested scrolling shell. Keep the gesture local to the current
// page and reload through Inertia so a pull never leaves the user's tab.
let pullStartY = 0
let canPull = false
const refreshThreshold = 72

function onContentTouchStart(event) {
    if (isRefreshing.value || event.touches.length !== 1) return

    const target = contentEl.value
    canPull = Boolean(target && target.scrollTop <= 0)
    pullStartY = event.touches[0].clientY
    pullDistance.value = 0
}

function onContentTouchMove(event) {
    if (!canPull || isRefreshing.value || event.touches.length !== 1) return

    const delta = event.touches[0].clientY - pullStartY
    if (delta <= 0) {
        pullDistance.value = 0
        isPulling.value = false
        return
    }

    // A small resistance makes the gesture feel native while avoiding a
    // large content shift when someone simply scrolls at the top.
    pullDistance.value = Math.min(96, delta * 0.42)
    isPulling.value = pullDistance.value > 4
}

function onContentTouchEnd() {
    const shouldRefresh = canPull && pullDistance.value >= refreshThreshold
    canPull = false
    isPulling.value = false
    pullDistance.value = 0

    if (!shouldRefresh || isRefreshing.value) return

    isRefreshing.value = true
    router.reload({
        preserveScroll: true,
        preserveState: true,
        viewTransition: false,
        onFinish: () => {
            isRefreshing.value = false
        },
    })
}

const warmedRoutes = new Set()

function warmRoute(url, pageName = '') {
    if (!url || warmedRoutes.has(url)) return

    warmedRoutes.add(url)
    window.__almunjazPreloadPage?.(pageName)
    router.prefetch(url, { viewTransition: false }, { cacheFor: '10s' })
}

function visitMobileRoute(url, pageName = '') {
    warmRoute(url, pageName)
    router.visit(url, {
        preserveScroll: false,
        preserveState: false,
        viewTransition: false,
    })
}

function visitTab(tab) {
    if (active(tab)) return

    warmRoute(tab.url, tab.page)
    router.visit(tab.url, {
        // Each tab is a fresh operational screen. Keeping the previous
        // component state made some phones replay an old screen while the
        // next one arrived, which looked like a slow side-slide transition.
        preserveScroll: false,
        preserveState: false,
        replace: true,
        viewTransition: false,
    })
}

function syncLiveNotificationCount(event) {
    const unread = Number(event.detail?.unread)
    if (Number.isFinite(unread)) liveNotificationUnread.value = Math.max(0, unread)
}

async function refreshChatBadge() {
    try {
        const { data } = await window.axios.get(route('app.chats.unread'))
        liveChatUnread.value = Math.max(0, Number(data?.unread || 0))
    } catch (_) {}
}

function handleRealtime(kind) {
    if (kind === 'chat.message') {
        refreshChatBadge()
        return
    }
    window.dispatchEvent(new Event('almunjaz:notification-realtime'))
}

// Android Chrome and installed PWAs do not always resize `100dvh` while the
// software keyboard is open.  Use the visual viewport as the single source
// of truth, so a focused field stays inside the visible application frame
// rather than being moved under the keyboard by the document viewport.
function syncVisualViewport() {
    const viewport = window.visualViewport
    if (!viewport) return

    const height = Math.max(0, Math.round(viewport.height))
    document.documentElement.style.setProperty('--app-viewport-height', `${height}px`)
    window.dispatchEvent(new CustomEvent('almunjaz:viewport-change', {
        detail: { height, offsetTop: Math.round(viewport.offsetTop || 0) },
    }))
}

onMounted(() => {
    applyTheme(theme.value)
    window.addEventListener('almunjaz:notification-count', syncLiveNotificationCount)
    if (user.value?.id) unsubscribeUserRealtime = subscribeToUserRealtime(user.value.id, handleRealtime)
    refreshChatBadge()
    syncVisualViewport()
    window.visualViewport?.addEventListener('resize', syncVisualViewport)
    window.visualViewport?.addEventListener('scroll', syncVisualViewport)
})

onBeforeUnmount(() => {
    window.removeEventListener('almunjaz:notification-count', syncLiveNotificationCount)
    unsubscribeUserRealtime()
    window.visualViewport?.removeEventListener('resize', syncVisualViewport)
    window.visualViewport?.removeEventListener('scroll', syncVisualViewport)
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
        home: 'M3 11l9-8 9 8M5 10v10h14V10M9 20v-6h6v6',
        box: 'M21 8 12 3 3 8v8l9 5 9-5V8Z M3 8l9 5 9-5 M12 13v8',
        wallet: 'M2.5 6h19v13h-19zM2.5 10h19M17 14h.01',
        archive: 'M4 5.5A1.5 1.5 0 0 1 5.5 4h13A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 18.5v-13ZM8 9h8M8 12h8M8 15h5',
        chat: 'M4 4h16v12H8l-4 4V4z',
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
            <CourierLocationTracker />
            <CourierLocationInstallGate v-if="isCourier" :user-id="user?.id" />
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
                    <button v-if="showNotif" class="tb-icon-btn" type="button" :aria-label="t('Notifications')" @pointerdown="warmRoute(route('app.notifications'), 'Mobile/Notifications')" @mouseenter="warmRoute(route('app.notifications'), 'Mobile/Notifications')" @click="visitMobileRoute(route('app.notifications'), 'Mobile/Notifications')">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path :d="icon('bell')" />
                        </svg>
                        <span v-if="notificationUnread > 0" class="notif-badge">{{ notificationUnread > 99 ? '99+' : notificationUnread }}</span>
                    </button>
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

            <div
                class="app-pull-refresh"
                :class="{ visible: isPulling || isRefreshing, ready: pullDistance >= refreshThreshold, refreshing: isRefreshing }"
                :style="{ transform: `translate(-50%, ${Math.min(60, pullDistance)}px)` }"
                role="status"
                :aria-live="isRefreshing ? 'polite' : 'off'"
            >
                <svg class="pull-refresh-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 11a8 8 0 1 0 2 5.3" /><path d="M20 4v7h-7" />
                </svg>
                <span>{{ isRefreshing ? t('Refreshing…') : t('Pull to refresh') }}</span>
            </div>

            <main
                ref="contentEl"
                class="app-content"
                :class="[{ 'without-tabs': hideTabs }, contentClass]"
                @touchstart.passive="onContentTouchStart"
                @touchmove.passive="onContentTouchMove"
                @touchend="onContentTouchEnd"
                @touchcancel="onContentTouchEnd"
            >
                <PwaInstallBanner surface />
                <slot />
            </main>

            <slot name="fab" />

            <nav v-if="!hideTabs" class="bottom-tabs">
                <button v-for="tab in tabs" :key="tab.route" class="tab-btn" :class="{ active: active(tab) }" @pointerdown="warmRoute(tab.url, tab.page)" @mouseenter="warmRoute(tab.url, tab.page)" @click="visitTab(tab)">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="icon(tab.icon)" />
                    </svg>
                    <span class="tlabel">{{ tab.label }}</span>
                    <span v-if="tab.route === 'app.chats' && Math.max(notifBadge, liveChatUnread) > 0" class="tdot">{{ Math.max(notifBadge, liveChatUnread) }}</span>
                </button>
            </nav>
        </div>
    </div>
</template>

<style scoped>
.app-pull-refresh {
    position: absolute;
    top: 66px;
    left: 50%;
    z-index: 31;
    display: flex;
    align-items: center;
    gap: 6px;
    min-height: 30px;
    padding: 6px 10px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: color-mix(in srgb, var(--surface) 96%, transparent);
    box-shadow: 0 6px 18px rgba(15, 27, 26, .12);
    color: var(--ink-soft);
    font-size: 9.5px;
    font-weight: 850;
    opacity: 0;
    pointer-events: none;
    transition: opacity .16s ease, transform .18s ease, color .16s ease;
}

.app-pull-refresh.visible { opacity: 1; }
.app-pull-refresh.ready { color: var(--primary-strong); }
.app-pull-refresh.refreshing { color: var(--primary-strong); }
.pull-refresh-icon { width: 14px; height: 14px; }
.app-pull-refresh.ready .pull-refresh-icon { transform: rotate(180deg); }
.app-pull-refresh.refreshing .pull-refresh-icon { animation: pull-spin .75s linear infinite; }

.tab-btn { transition: color .16s ease, transform .16s ease; }
.tab-btn:active { transform: scale(.92); }
.tab-btn.active svg { filter: drop-shadow(0 2px 4px color-mix(in srgb, var(--primary) 26%, transparent)); }

@keyframes pull-spin { to { transform: rotate(360deg); } }
</style>
