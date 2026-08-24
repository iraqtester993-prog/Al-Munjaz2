<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import AppShell from '../../Components/AppShell.vue'

const props = defineProps({
    chats: { type: Array, default: () => [] },
})

const page = usePage()
const unread = computed(() => props.chats.reduce((s, c) => s + (c.unread || 0), 0))

function initials(name) {
    return (name || '؟').trim().charAt(0)
}
</script>

<template>
    <AppShell :title="t('Chat')" :notif-badge="unread" :show-notif="false">
        <button class="btn btn-primary" style="width: 100%; margin-bottom: 12px" @click="$inertia.post(route('app.chats.open'))">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 12a8 8 0 0 1-8 8H4l1.5-3.5A8 8 0 1 1 21 12Z M12 8v8M8 12h8" />
            </svg>
            {{ t('Contact Support') }}
        </button>

        <div v-if="chats.length" class="list-card">
            <div v-for="c in chats" :key="c.id" class="chat-row" @click="$inertia.visit(route('app.chats.show', c.id))">
                <div class="chat-avatar">{{ initials(c.title_ar) }}</div>
                <div class="chat-mid">
                    <b>{{ c.title_ar }}</b>
                    <span>{{ c.last_message || t('No messages yet') }}</span>
                </div>
                <div class="chat-end">
                    <time>{{ c.last_at }}</time>
                    <span v-if="c.unread > 0" class="unread-badge">{{ c.unread }}</span>
                </div>
            </div>
        </div>
        <div v-else class="empty-hint">{{ t('No conversations yet') }}</div>
    </AppShell>
</template>
