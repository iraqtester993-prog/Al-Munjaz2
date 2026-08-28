<script setup>
import { computed, ref, nextTick, onBeforeUnmount, onMounted, watch } from 'vue'
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
const isOrderConversation = computed(() => ['order_chat', 'order_support'].includes(props.chat?.counterparty_type))
const isComplaintConversation = computed(() => props.chat?.counterparty_type === 'order_support')
const chatSubtitle = computed(() => isOrderConversation.value ? t('Order conversation') : t('Support'))
const sending = ref(false)
const msgs = ref([...props.messages])
const threadEl = ref(null)
const composerEl = ref(null)
const lastMessageId = ref(Math.max(0, ...msgs.value.map((message) => Number(message?.id) || 0)))
let pollTimer = null
let refreshing = false

async function send() {
    const value = text.value.trim()
    if (!value || sending.value) return
    sending.value = true
    try {
        const { data } = await axios.post(route('app.chats.send', props.chat.id), { text: value })
        mergeMessages([data])
        text.value = ''
    } catch (e) {
        // keep message for retry
    } finally {
        sending.value = false
        scrollDown()
        // On phones a button tap normally steals focus from the input and
        // dismisses the software keyboard. Restore the composer after the
        // DOM settles so a courier or merchant can send consecutive messages.
        await nextTick()
        composerEl.value?.focus({ preventScroll: true })
    }
}

function mergeMessages(messages, { replace = false } = {}) {
    const byId = new Map(replace ? [] : msgs.value.map((message) => [message.id, message]))
    for (const message of messages || []) {
        if (message?.id) byId.set(message.id, message)
    }
    msgs.value = [...byId.values()].sort((a, b) => Number(a.id) - Number(b.id))
    lastMessageId.value = Math.max(lastMessageId.value, ...msgs.value.map((message) => Number(message?.id) || 0))
    scrollDown()
}

async function refreshMessages() {
    if (refreshing || document.hidden) return
    refreshing = true
    try {
        const { data } = await axios.get(route('app.chats.messages', props.chat.id), {
            params: { after_id: lastMessageId.value },
        })
        mergeMessages(data.messages)
        lastMessageId.value = Math.max(lastMessageId.value, Number(data.last_id || 0))
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

function replaceMessages(messages) {
    msgs.value = []
    lastMessageId.value = 0
    mergeMessages(messages, { replace: true })
}

watch(() => props.messages, (messages) => mergeMessages(messages), { deep: true })
watch(() => props.chat?.id, () => replaceMessages(props.messages))

onMounted(() => {
    scrollDown()
    // Shared hosting cannot keep a Reverb worker alive reliably. Incremental
    // 2-second polling gives an open conversation near-instant feedback while
    // downloading only messages newer than the last known ID.
    pollTimer = window.setInterval(refreshMessages, 2000)
    document.addEventListener('visibilitychange', refreshMessages)
})

onBeforeUnmount(() => {
    if (pollTimer) window.clearInterval(pollTimer)
    document.removeEventListener('visibilitychange', refreshMessages)
})
</script>

<template>
    <AppShell
        :title="chatTitle()"
        :subtitle="chatSubtitle"
        :back="true"
        :back-url="route('app.chats')"
        :hide-tabs="true"
        :show-notif="false"
        content-class="chat-thread-content"
    >
        <template #actions>
            <span class="chat-thread-avatar">{{ chatTitle()?.charAt(0) }}</span>
        </template>

        <div ref="threadEl" class="thread">
            <section v-if="isOrderConversation" class="chat-order-context" :class="{ complaint: isComplaintConversation }">
                <span class="chat-order-context-icon" aria-hidden="true">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h13A1.5 1.5 0 0 1 20 5.5v13A1.5 1.5 0 0 1 18.5 20h-13A1.5 1.5 0 0 1 4 18.5v-13ZM8 9h8M8 12h8M8 15h5" /></svg>
                </span>
                <span>
                    <b>{{ isComplaintConversation ? t('Contact Support') : t('Order conversation') }}</b>
                    <small class="mono">{{ chat.track_no || chat.title_ar }}</small>
                </span>
            </section>
            <div v-for="m in msgs" :key="m.id" class="bubble" :class="m.from_me ? 'bubble-me' : 'bubble-them'">
                {{ m.text }}
                <span class="bubble-time">{{ m.time }}</span>
            </div>
            <div v-if="!msgs.length" class="empty-hint">{{ t('Say hello to start the conversation') }}</div>
        </div>

        <div class="chat-input-bar">
            <input ref="composerEl" v-model="text" :placeholder="t('Type a message')" @keydown="onEnter" />
            <button type="button" class="send-btn" :disabled="sending || !text.trim()" @pointerdown.prevent @click="send">
                <span v-if="sending" class="loader"></span>
                <svg v-else width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :style="{ transform: locale() === 'ar' ? 'scaleX(-1)' : '' }">
                    <path d="m22 2-7 20-4-9-9-4Z M22 2 11 13" />
                </svg>
            </button>
        </div>
    </AppShell>
</template>

<style scoped>
.chat-thread-avatar{width:34px;height:34px;display:grid;place-items:center;border-radius:50%;background:var(--primary-tint);color:var(--primary-strong);font-size:13px;font-weight:900;flex:none;}
.chat-order-context{display:flex;align-items:center;gap:8px;align-self:stretch;margin:0 0 4px;padding:9px 10px;border:1px solid color-mix(in srgb,var(--primary) 24%,var(--border));border-radius:11px;background:var(--primary-tint);color:var(--primary-strong)}
.chat-order-context.complaint{border-color:color-mix(in srgb,var(--danger) 24%,var(--border));background:var(--danger-tint);color:var(--danger)}
.chat-order-context-icon{display:grid;width:30px;height:30px;place-items:center;flex:none;border-radius:9px;background:color-mix(in srgb,currentColor 13%,transparent)}
.chat-order-context span:last-child{display:grid;gap:1px;min-width:0}.chat-order-context b{font-size:10px;font-weight:900}.chat-order-context small{overflow:hidden;color:var(--ink-soft);font-size:9.5px;font-weight:800;text-overflow:ellipsis;white-space:nowrap}
</style>
