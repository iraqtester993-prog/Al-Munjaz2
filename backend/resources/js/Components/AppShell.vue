<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Flash from './Flash.vue'

const props = defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    back: { type: Boolean, default: false },
    notifBadge: { type: Number, default: 0 },
    showNotif: { type: Boolean, default: true },
})

const page = usePage()
const user = computed(() => page.props.auth?.user)
const isCourier = computed(() => user.value?.role === 'courier')

const tabs = computed(() => {
    const base = [
        { label: t('Home'), icon: 'home', route: 'app' },
        { label: isCourier.value ? t('My Deliveries') : t('My Orders'), icon: 'box', route: 'app.orders' },
        { label: t('Wallet'), icon: 'wallet', route: 'app.wallet' },
        { label: t('Chat'), icon: 'chat', route: 'app.chats' },
        { label: t('Profile'), icon: 'user', route: 'app.profile' },
    ]
    return base.map((x) => ({ ...x, url: route(x.route) }))
})

const currentPath = computed(() => new URL(page.url, window.location.origin).pathname)

function active(tab) {
    return currentPath.value === tab.url
}

function icon(name) {
    const paths = {
        home: 'M3 11.2 12 4l9 7.2V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1v-8.8Z',
        box: 'M21 8 12 3 3 8v8l9 5 9-5V8Z M3 8l9 5 9-5 M12 13v8',
        wallet: 'M20 7H6a2 2 0 0 1-2-2 2 2 0 0 1 2-2h13v3 M20 7a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6 M16 14h.01',
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
            <header class="app-topbar">
                <button v-if="back" class="tb-icon-btn" @click="$inertia.visit(route('app'))">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" :style="{ transform: window.__locale === 'ar' ? 'rotate(180deg)' : '' }">
                        <path d="M19 12H5m0 0 6-6m-6 6 6 6" />
                    </svg>
                </button>
                <div v-else style="width:34px"></div>
                <div class="tb-title">
                    <slot name="title">{{ title }}</slot>
                    <span v-if="subtitle" class="tb-sub">{{ subtitle }}</span>
                </div>
                <a v-if="showNotif" class="tb-icon-btn" :href="route('app.notifications')">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="icon('bell')" />
                    </svg>
                    <span v-if="notifBadge > 0" class="notif-badge">{{ notifBadge > 99 ? '99+' : notifBadge }}</span>
                </a>
                <slot name="actions" />
            </header>

            <main class="app-content">
                <slot />
            </main>

            <slot name="fab" />

            <nav class="bottom-tabs">
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
