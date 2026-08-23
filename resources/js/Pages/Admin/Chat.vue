<script setup>
import { ref, nextTick } from 'vue'
import axios from 'axios'
import AdminShell from '../../Components/AdminShell.vue'

const props = defineProps({
    chats: { type: Array, default: () => [] },
    activeChat: { type: Object, default: null },
    messages: { type: Array, default: () => [] },
})

const text = ref('')
const sending = ref(false)
const msgs = ref([...(props.messages || [])])
const threadEl = ref(null)

function openChat(c) {
    $inertia.visit(route('admin.chat.show', c.id))
}

function initials(name) {
    return (name || '؟').trim().charAt(0)
}

async function send() {
    const value = text.value.trim()
    if (!value || !props.activeChat || sending.value) return
    sending.value = true
    try {
        const { data } = await axios.post(route('admin.chat.send', props.activeChat.id), { text: value })
        msgs.value.push({ ...data, sender_id: null })
        text.value = ''
    } catch (e) {
        // retry on next attempt
    } finally {
        sending.value = false
        scrollDown()
    }
}

function scrollDown() {
    nextTick(() => {
        if (threadEl.value) threadEl.value.scrollTop = threadEl.value.scrollHeight
    })
}

function onEnter(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault()
        send()
    }
}
</script>

<template>
    <AdminShell title="Chat">
        <div class="chat-layout">
            <div class="chat-list">
                <div v-for="c in chats" :key="c.id" class="chat-item" :class="{ active: activeChat?.id === c.id }" @click="openChat(c)">
                    <div class="avatar">{{ initials(c.user?.name || c.title_ar) }}</div>
                    <div style="flex: 1; min-width: 0">
                        <b style="display: block; font-size: 13px">{{ c.user?.name || c.title_ar }}</b>
                        <span class="text-muted" style="font-size: 10.5px; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis">{{ c.last_message }}</span>
                    </div>
                    <div style="text-align: end">
                        <div class="text-muted" style="font-size: 9.5px">{{ c.last_at }}</div>
                        <div v-if="c.unread > 0" class="unread-badge" style="margin-top: 5px">{{ c.unread }}</div>
                    </div>
                </div>
                <div v-if="!chats.length" class="empty">{{ t('No conversations yet') }}</div>
            </div>

            <div v-if="activeChat" class="chat-thread">
                <div class="thread-head">
                    <div class="avatar">{{ initials(activeChat.user?.name || activeChat.title_ar) }}</div>
                    <div>
                        <b>{{ activeChat.user?.name || activeChat.title_ar }}</b>
                        <div class="text-muted" style="font-size: 10.5px">{{ activeChat.user?.phone }}</div>
                    </div>
                </div>
                <div ref="threadEl" class="thread" style="height: 0; flex: 1; min-height: 200px">
                    <div v-for="m in msgs" :key="m.id" class="bubble" :class="m.from_me ? 'bubble-me' : 'bubble-them'">
                        {{ m.text }}
                        <span class="bubble-time">{{ m.time }}</span>
                    </div>
                    <div v-if="!msgs.length" class="empty-hint">{{ t('No messages yet') }}</div>
                </div>
                <div class="chat-input-bar">
                    <input v-model="text" :placeholder="t('Type a message')" @keydown="onEnter" />
                    <button class="send-btn" :disabled="sending || !text.trim()" @click="send">
                        <span v-if="sending" class="loader"></span>
                        <svg v-else width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m22 2-7 20-4-9-9-4Z M22 2 11 13" />
                        </svg>
                    </button>
                </div>
            </div>
            <div v-else class="chat-thread chat-empty">{{ t('Select a conversation') }}</div>
        </div>
    </AdminShell>
</template>
