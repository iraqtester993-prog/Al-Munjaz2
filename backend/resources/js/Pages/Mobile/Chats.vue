<script setup>
import { computed, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import AppShell from '../../Components/AppShell.vue'

const props = defineProps({
    chats: { type: Array, default: () => [] },
})

const page = usePage()
const channel = ref('support')
const unread = computed(() => props.chats.reduce((s, c) => s + (c.unread || 0), 0))
const locale = computed(() => page.props.locale || 'ar')
const isOrderChat = (chat) => chat?.counterparty_type === 'order_chat'
const supportChats = computed(() => props.chats.filter((chat) => !isOrderChat(chat)))
const orderChats = computed(() => props.chats.filter(isOrderChat))
const activeChats = computed(() => channel.value === 'support' ? supportChats.value : orderChats.value)
const channels = computed(() => {
    const support = {
        key: 'support',
        label: t('Administration'),
        count: supportChats.value.length,
        unread: supportChats.value.reduce((sum, chat) => sum + Number(chat.unread || 0), 0),
    }
    const orders = {
        key: 'orders',
        label: t('Order conversations'),
        count: orderChats.value.length,
        unread: orderChats.value.reduce((sum, chat) => sum + Number(chat.unread || 0), 0),
    }

    // The support tab must remain available so a user can open the first
    // support chat; order conversations only appear after an actual order
    // conversation exists.
    return [support, ...(orders.count ? [orders] : [])]
})

watch(channels, (nextChannels) => {
    if (!nextChannels.some((item) => item.key === channel.value)) {
        channel.value = nextChannels[0]?.key || 'support'
    }
}, { immediate: true })

function initials(name) {
    return (name || '؟').trim().charAt(0)
}

function title(chat) {
    return chat?.[`title_${locale.value}`] || chat?.title_ar || t('Support')
}
</script>

<template>
    <AppShell :title="t('Chat')" :notif-badge="unread" :show-notif="false">
        <div class="chat-channel-tabs" role="tablist" :aria-label="t('Chat')" :style="{ gridTemplateColumns: `repeat(${channels.length}, minmax(0, 1fr))` }">
            <button v-for="item in channels" :key="item.key" type="button" :class="{ active: channel === item.key }" role="tab" :aria-selected="channel === item.key" @click="channel = item.key">
                <span>{{ item.label }}</span>
                <small v-if="item.unread || item.count" class="chat-channel-count">{{ item.unread || item.count }}</small>
            </button>
        </div>

        <button v-if="channel === 'support'" class="btn btn-primary chat-support-button" @click="$inertia.post(route('app.chats.open'))">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 12a8 8 0 0 1-8 8H4l1.5-3.5A8 8 0 1 1 21 12Z M12 8v8M8 12h8" />
            </svg>
            {{ t('Contact Support') }}
        </button>

        <div v-if="activeChats.length" class="list-card">
            <div v-for="c in activeChats" :key="c.id" class="chat-row" @click="$inertia.visit(route('app.chats.show', c.id))">
                <div class="chat-avatar">{{ initials(title(c)) }}</div>
                <div class="chat-mid">
                    <b>{{ title(c) }}</b>
                    <span>{{ c.last_message || t('No messages yet') }}</span>
                </div>
                <div class="chat-end">
                    <time>{{ c.last_at }}</time>
                    <span v-if="c.unread > 0" class="unread-badge">{{ c.unread }}</span>
                </div>
            </div>
        </div>
        <div v-else class="empty-hint">{{ channel === 'orders' ? t('No order conversations yet.') : t('No conversations yet') }}</div>
    </AppShell>
</template>

<style scoped>
.chat-channel-tabs{display:grid;gap:6px;margin-bottom:12px;padding:4px;border:1px solid var(--border);border-radius:13px;background:var(--surface-2);}.chat-channel-tabs button{display:flex;align-items:center;justify-content:center;gap:5px;min-height:34px;border-radius:9px;color:var(--ink-soft);font:800 10.5px var(--font);}
.chat-channel-tabs button.active{background:var(--surface);color:var(--primary-strong);box-shadow:0 2px 8px rgba(15,27,26,.08);}
.chat-channel-count{display:grid;min-width:16px;height:16px;place-items:center;border-radius:999px;background:var(--primary-tint);color:var(--primary-strong);font-size:8.5px;font-weight:900}.chat-channel-tabs button.active .chat-channel-count{background:var(--primary);color:#fff}
.chat-support-button{width:100%;margin-bottom:12px;}
</style>
