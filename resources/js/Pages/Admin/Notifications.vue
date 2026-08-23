<script setup>
import { computed } from 'vue'
import AdminShell from '../../Components/AdminShell.vue'

const props = defineProps({
    notifications: { type: Array, default: () => [] },
})

const typeMeta = {
    order: { tint: 'var(--primary-tint)', color: 'var(--primary-strong)', icon: 'M21 8 12 3 3 8v8l9 5 9-5V8Z M3 8l9 5 9-5 M12 13v8' },
    account: { tint: 'var(--warning-tint)', color: 'var(--warning)', icon: 'M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6l-8-3Z' },
    chat: { tint: 'var(--st-approved-tint)', color: 'var(--st-approved)', icon: 'M21 12a8 8 0 0 1-8 8H4l1.5-3.5A8 8 0 1 1 21 12Z' },
    finance: { tint: 'var(--success-tint)', color: 'var(--success)', icon: 'M3 6h18v12H3z M3 10h18 M7 15h4' },
}

function meta(t) {
    return typeMeta[t] || { tint: 'var(--surface-2)', color: 'var(--ink-soft)', icon: 'M12 5v14M5 12h14' }
}
</script>

<template>
    <AdminShell title="Notifications">
        <div class="panel">
            <div class="panel-head">
                <h3>{{ t('All Notifications') }}</h3>
            </div>
            <div class="panel-body" style="padding: 0">
                <div v-for="n in notifications" :key="n.id" class="notif-item" :class="{ unread: !n.read }">
                    <div class="notif-ic" :style="{ background: meta(n.type).tint, color: meta(n.type).color }">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path :d="meta(n.type).icon" />
                        </svg>
                    </div>
                    <div class="notif-body">
                        <b>{{ n.title }}</b>
                        <span>{{ n.body }}</span>
                    </div>
                    <div class="notif-time">{{ n.time }}</div>
                </div>
                <div v-if="!notifications.length" class="empty">{{ t('No notifications yet') }}</div>
            </div>
        </div>
    </AdminShell>
</template>
