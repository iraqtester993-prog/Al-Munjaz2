<script setup>
import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Flash from './Flash.vue'

defineProps({ title: { type: String, default: '' } })

const page = usePage()
const isMenuOpen = ref(false)
const currentPath = computed(() => new URL(page.url, window.location.origin).pathname.replace(/\/$/, ''))
const user = computed(() => page.props.auth?.user)
const nav = computed(() => [
    { label: 'نظرة عامة', icon: 'grid', route: 'admin.dashboard' },
    { label: 'الطلبات', icon: 'box', route: 'admin.orders' },
    { label: 'الفروع والصناديق', icon: 'building', route: 'admin.branches' },
    { label: 'التجار', icon: 'shop', route: 'admin.merchants' },
    { label: 'المندوبون', icon: 'bike', route: 'admin.couriers' },
    { label: 'المالية والتسويات', icon: 'card', route: 'admin.finance' },
    { label: 'المحادثات', icon: 'chat', route: 'admin.chat' },
    { label: 'الإشعارات', icon: 'bell', route: 'admin.notifications' },
].map((item) => ({ ...item, url: route(item.route) })))
function active(item) { return currentPath.value === item.url.replace(/\/$/, '') }
function navigate(url) { isMenuOpen.value = false; window.location.href = url }
function icon(name) {
    const paths = {
        grid: 'M4 4h6v6H4z M14 4h6v6h-6z M4 14h6v6H4z M14 14h6v6h-6z',
        box: 'm21 8-9 5-9-5 9-5 9 5ZM3 8v8l9 5 9-5V8M12 13v8',
        building: 'M4 21V4a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v17M8 7h.01M12 7h.01M16 7h.01M8 11h.01M12 11h.01M16 11h.01M10 21v-5h4v5',
        shop: 'M4 10v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V10M2 7l1-3h18l1 3a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0Z',
        bike: 'M5 18a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm14-8a4 4 0 1 1 0 8 4 4 0 0 1 0-8ZM5 10h14m-7 0-2-4h5',
        card: 'M3 6h18v12H3zM3 10h18M7 15h4',
        chat: 'M21 12a8 8 0 0 1-8 8H4l1.5-3.5A8 8 0 1 1 21 12Z',
        bell: 'M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6Zm5 11a2 2 0 0 0 4 0',
        menu: 'M4 7h16M4 12h16M4 17h16',
        logout: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4m7 14 5-5-5-5m5 5H9',
    }
    return paths[name]
}
</script>

<template>
    <div class="admin-shell" :class="{ 'admin-menu-open': isMenuOpen }">
        <Flash />
        <button class="admin-backdrop" aria-label="إغلاق القائمة" @click="isMenuOpen = false" />
        <aside class="sidebar">
            <div class="side-brand"><img src="/logo.png" alt="شعار المنجز السريع" class="brand-logo" /><div><b>المنجز السريع</b><span>منصة إدارة التوصيل</span></div></div>
            <div class="side-context"><span class="live-dot"><i /> النظام متصل</span><small>إدارة مركزية</small></div>
            <nav class="side-nav" aria-label="التنقل الرئيسي"><p class="nav-caption">الإدارة والتشغيل</p><button v-for="item in nav" :key="item.route" class="nav-item" :class="{ active: active(item) }" @click="navigate(item.url)"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="icon(item.icon)" /></svg>{{ item.label }}</button></nav>
            <div class="side-foot"><div class="operator"><div class="avatar">{{ user?.name?.charAt(0) || 'إ' }}</div><div><b>{{ user?.name || 'الإدارة' }}</b><span>مدير النظام</span></div></div><button @click="$inertia.post(route('logout'))"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="icon('logout')" /></svg>تسجيل الخروج</button></div>
        </aside>
        <main class="main"><header class="topbar"><button class="mobile-menu" aria-label="فتح القائمة" @click="isMenuOpen = true"><svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="icon('menu')" /></svg></button><div><p class="crumb">لوحة الإدارة</p><h1>{{ title }}</h1></div><div class="spacer" /><slot name="topbar-actions" /><div class="top-user"><div class="avatar">{{ user?.name?.charAt(0) || 'إ' }}</div><span>{{ user?.name || 'الإدارة' }}</span></div></header><div class="content"><slot /></div></main>
    </div>
</template>
