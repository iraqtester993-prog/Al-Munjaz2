<script setup>
import { ref, nextTick } from 'vue'
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
const sending = ref(false)
const msgs = ref([...props.messages])
const threadEl = ref(null)

async function send() {
    const value = text.value.trim()
    if (!value || sending.value) return
    sending.value = true
    try {
        const { data } = await axios.post(route('app.chats.send', props.chat.id), { text: value })
        msgs.value.push({ ...data, sender_id: null })
        text.value = ''
    } catch (e) {
        // keep message for retry
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
    <div class="app-stage">
        <div class="app-shell">
            <header class="app-topbar">
                <button class="tb-icon-btn" @click="$inertia.visit(route('app.chats'))">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" :style="{ transform: locale() === 'ar' ? 'rotate(180deg)' : '' }">
                        <path d="M19 12H5m0 0 6-6m-6 6 6 6" />
                    </svg>
                </button>
                <div class="tb-title">
                    {{ chat.title_ar }}
                    <span class="tb-sub">{{ t('Support') }}</span>
                </div>
                <div class="chat-avatar" style="width: 34px; height: 34px; font-size: 13px">{{ chat.title_ar?.charAt(0) }}</div>
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
