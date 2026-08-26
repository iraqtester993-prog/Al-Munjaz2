<script setup>
import { ref, nextTick, onBeforeUnmount, onMounted, watch } from 'vue'
import axios from 'axios'
import { route } from 'ziggy-js'
import { usePage } from '@inertiajs/vue3'
import AppShell from '../../Components/AppShell.vue'

const props = defineProps({
    chat: { type: Object, required: true },
    messages: { type: Array, default: () => [] },
})

const text = ref('')
const page = usePage()
const locale = () => page.props.locale || 'ar'
const chatTitle = () => props.chat?.[`title_${locale()}`] || props.chat?.title_ar || t('Support')
const sending = ref(false)
const msgs = ref([...props.messages])
const threadEl = ref(null)
let pollTimer = null
let refreshing = false

async function send() {
    const value = text.value.trim()
    if (!value || sending.value) return
    sending.value = true
    try {
        const { data } = await axios.post(route('app.chats.send', props.chat.id), { text: value })
        mergeMessages([...msgs.value, data])
        text.value = ''
    } catch (e) {
        // keep message for retry
    } finally {
        sending.value = false
        scrollDown()
    }
}

function mergeMessages(messages) {
    const byId = new Map()
    for (const message of messages || []) {
        if (message?.id) byId.set(message.id, message)
    }
    msgs.value = [...byId.values()].sort((a, b) => Number(a.id) - Number(b.id))
    scrollDown()
}

async function refreshMessages() {
    if (refreshing || document.hidden) return
    refreshing = true
    try {
        const { data } = await axios.get(route('app.chats.messages', props.chat.id))
        mergeMessages(data.messages)
    } catch (_) {
        // A temporary network failure must never clear the on-screen thread.
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

watch(() => props.messages, (messages) => mergeMessages(messages), { deep: true })

onMounted(() => {
    scrollDown()
    pollTimer = window.setInterval(refreshMessages, 4000)
    document.addEventListener('visibilitychange', refreshMessages)
})

onBeforeUnmount(() => {
    if (pollTimer) window.clearInterval(pollTimer)
    document.removeEventListener('visibilitychange', refreshMessages)
})
</script>

<template>
    <div class="app-stage">
        <div class="app-shell">
            <header class="app-topbar">
                <button class="tb-icon-btn" @click="$inertia.visit(route('app.chats'))">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" :style="{ transform: locale() === 'ar' ? 'rotate(180deg)' : '' }">
                        <path d="M19 12H5m0 0 6-6m-6 6 6 6" />
                    </svg>
                </button>
                <div class="tb-title">
                    {{ chatTitle() }}
                    <span class="tb-sub">{{ t('Support') }}</span>
                </div>
                <div class="chat-avatar" style="width: 34px; height: 34px; font-size: 13px">{{ chatTitle()?.charAt(0) }}</div>
            </header>

            <div ref="threadEl" class="thread">
                <div v-for="m in msgs" :key="m.id" class="bubble" :class="m.from_me ? 'bubble-me' : 'bubble-them'">
                    {{ m.text }}
                    <span class="bubble-time">{{ m.time }}</span>
                </div>
                <div v-if="!msgs.length" class="empty-hint">{{ t('Say hello to start the conversation') }}</div>
            </div>

            <div class="chat-input-bar">
                <input v-model="text" :placeholder="t('Type a message')" @keydown="onEnter" />
                <button class="send-btn" :disabled="sending || !text.trim()" @click="send">
                    <span v-if="sending" class="loader"></span>
                    <svg v-else width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :style="{ transform: locale() === 'ar' ? 'scaleX(-1)' : '' }">
                        <path d="m22 2-7 20-4-9-9-4Z M22 2 11 13" />
                    </svg>
                </button>
            </div>
        </div>
    </div>
</template>
