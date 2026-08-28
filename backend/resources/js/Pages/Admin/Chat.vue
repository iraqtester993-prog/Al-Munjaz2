<script setup>
import { ref, nextTick, onBeforeUnmount, onMounted, watch } from 'vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'

const props = defineProps({
    chats: { type: Array, default: () => [] },
    activeChat: { type: Object, default: null },
    messages: { type: Array, default: () => [] },
})

const text = ref('')
const sending = ref(false)
const sendError = ref('')
const msgs = ref([...(props.messages || [])])
const threadEl = ref(null)
const composerEl = ref(null)
const lastMessageId = ref(Math.max(0, ...msgs.value.map((message) => Number(message?.id) || 0)))
let pollTimer = null
let refreshing = false

function mergeMessages(messages, { replace = false } = {}) {
    const byId = new Map(replace ? [] : msgs.value.map((message) => [message.id, message]))
    for (const message of messages || []) {
        if (message?.id) byId.set(message.id, message)
    }
    msgs.value = [...byId.values()].sort((a, b) => Number(a.id) - Number(b.id))
    lastMessageId.value = Math.max(lastMessageId.value, ...msgs.value.map((message) => Number(message?.id) || 0))
    scrollDown()
}

function replaceMessages(messages) {
    msgs.value = []
    lastMessageId.value = 0
    mergeMessages(messages, { replace: true })
}

watch(() => props.messages, (messages) => mergeMessages(messages), { deep: true })

function openChat(c) {
    router.visit(route('admin.chat.show', c.id))
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
        mergeMessages([data])
        text.value = ''
    } catch (e) {
        sendError.value = t('Unable to send the message. Please try again.')
    } finally {
        sending.value = false
        scrollDown()
        await nextTick()
        composerEl.value?.focus({ preventScroll: true })
    }
}

async function refreshMessages() {
    if (!props.activeChat || refreshing || document.hidden) return
    refreshing = true
    try {
        const { data } = await axios.get(route('admin.chat.messages', props.activeChat.id), {
            params: { after_id: lastMessageId.value },
        })
        mergeMessages(data.messages)
        lastMessageId.value = Math.max(lastMessageId.value, Number(data.last_id || 0))
    } catch (_) {
        // Keep the thread usable while a short-lived request fails.
    } finally {
        refreshing = false
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

watch(() => props.activeChat?.id, () => {
    replaceMessages(props.messages)
    refreshMessages()
})

onMounted(() => {
    scrollDown()
    pollTimer = window.setInterval(refreshMessages, 2000)
    document.addEventListener('visibilitychange', refreshMessages)
})

onBeforeUnmount(() => {
    if (pollTimer) window.clearInterval(pollTimer)
    document.removeEventListener('visibilitychange', refreshMessages)
})
</script>

<template>
    <AdminShell title="Chat">
        <div class="chat-layout">
            <div class="chat-list">
                <div v-for="c in chats" :key="c.id" class="chat-item" :class="{ active: activeChat?.id === c.id }" @click="openChat(c)">
                    <div class="avatar">{{ initials(c.display_title || c.title_ar) }}</div>
                    <div style="flex: 1; min-width: 0">
                        <b style="display: block; font-size: 13px">{{ c.display_title || c.title_ar }}</b>
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
                    <div class="avatar">{{ initials(activeChat.display_title || activeChat.title_ar) }}</div>
                    <div>
                        <b>{{ activeChat.display_title || activeChat.title_ar }}</b>
                        <div class="text-muted" style="font-size: 10.5px">{{ activeChat.user?.phone }}</div>
                        <div v-if="activeChat.order" class="text-muted" style="font-size: 9.5px">
                            {{ activeChat.order.track_no }} · {{ activeChat.order.customer_name }} · {{ activeChat.order.phone }}
                        </div>
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
                    <input ref="composerEl" v-model="text" :placeholder="t('Type a message')" @keydown="onEnter" />
                    <button type="button" class="send-btn" :disabled="sending || !text.trim()" @pointerdown.prevent @click="send">
                        <span v-if="sending" class="loader"></span>
                        <svg v-else width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m22 2-7 20-4-9-9-4Z M22 2 11 13" />
                        </svg>
                    </button>
                </div>
                <p v-if="sendError" class="chat-send-error">{{ sendError }}</p>
            </div>
            <div v-else class="chat-thread chat-empty">{{ t('Select a conversation') }}</div>
        </div>
    </AdminShell>
</template>
