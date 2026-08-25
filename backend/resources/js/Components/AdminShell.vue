<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Flash from './Flash.vue'

defineProps({
    title: { type: String, default: '' },
})

const page = usePage()
const currentPath = computed(() => new URL(page.url, window.location.origin).pathname)

const nav = computed(() => {
    const base = [
        { label: t('Dashboard'), icon: 'grid', route: 'admin.dashboard' },
        { label: t('Orders'), icon: 'box', route: 'admin.orders' },
        { label: 'الفروع', icon: 'building', route: 'admin.branches' },
        { label: t('Merchants'), icon: 'shop', route: 'admin.merchants' },
        { label: t('Couriers'), icon: 'bike', route: 'admin.couriers' },
        { label: t('Finance'), icon: 'card', route: 'admin.finance' },
        { label: t('Chat'), icon: 'chat', route: 'admin.chat' },
        { label: t('Notifications'), icon: 'bell', route: 'admin.notifications' },
    ]
    return base.map((x) => ({ ...x, url: route(x.route) }))
})

const user = computed(() => page.props.auth?.user)
const tenant = computed(() => page.props.auth?.tenant)

function active(item) {
    return currentPath.value === item.url
}

function icon(name) {
    const paths = {
        grid: 'M4 4h6v6H4z M14 4h6v6h-6z M4 14h6v6H4z M14 14h6v6h-6z',
        box: 'M21 8 12 3 3 8v8l9 5 9-5V8Z M3 8l9 5 9-5 M12 13v8',
        building: 'M4 21V4a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v17 M8 7h.01 M12 7h.01 M16 7h.01 M8 11h.01 M12 11h.01 M16 11h.01 M10 21v-5h4v5',
        shop: 'M4 10v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V10 M2 7l1-3h18l1 3a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0Z',
        bike: 'M5 18a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm14-8a4 4 0 1 1 0 8 4 4 0 0 1 0-8Z M5 10h14 M12 10l-2-4h5',
        card: 'M3 6h18v12H3z M3 10h18 M7 15h4',
        chat: 'M21 12a8 8 0 0 1-8 8H4l1.5-3.5A8 8 0 1 1 21 12Z',
        bell: 'M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6Zm5 11a2 2 0 0 0 4 0',
        logout: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4 M16 17l5-5-5-5 M21 12H9',
    }
    return paths[name] || paths.grid
}
</script>

<template>
    <div class="admin-shell">
        <Flash />
        <aside class="sidebar">
            <div class="side-brand">
                <div class="sb-ico">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 19 12 4l8 15 M8 13h8" />
                    </svg>
                </div>
                <div>
                    <b>{{ t('Merchant App') }}</b>
                    <span>{{ t('Admin Dashboard') }}</span>
                </div>
            </div>
            <nav class="side-nav">
                <button v-for="item in nav" :key="item.route" class="nav-item" :class="{ active: active(item) }" @click="$inertia.visit(item.url)">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="icon(item.icon)" />
                    </svg>
                    {{ item.label }}
                </button>
            </nav>
            <div class="side-foot">
                <button @click="$inertia.post(route('logout'))">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4 M16 17l5-5-5-5 M21 12H9" />
                    </svg>
                    {{ t('Logout') }}
                </button>
            </div>
        </aside>
        <main class="main">
            <header class="topbar">
                <h2>{{ $attrs.title ?? title }}</h2>
                <div class="spacer"></div>
                <slot name="topbar-actions" />
                <div class="user-cell">
                    <div class="avatar" style="width:34px;height:34px;font-size:13px;">{{ user?.name?.charAt(0) }}</div>
                    <div style="display:none">
                        {{ tenant?.name }}
                    </div>
                </div>
            </header>
            <div class="content">
                <slot />
            </div>
        </main>
    </div>
</template>
