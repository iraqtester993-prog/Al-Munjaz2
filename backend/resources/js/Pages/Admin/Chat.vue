<script setup>
import { computed, ref, nextTick, onBeforeUnmount, onMounted, watch } from 'vue'
import axios from 'axios'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'

const props = defineProps({
    chats: { type: Array, default: () => [] },
    supportChats: { type: Array, default: () => [] },
    merchantCourierChats: { type: Array, default: () => [] },
    chatTabs: { type: Array, default: () => [] },
    activeChat: { type: Object, default: null },
    messages: { type: Array, default: () => [] },
})

const page = usePage()
const locale = computed(() => page.props.locale || 'ar')
const copy = {
    ar: {
        title: 'الدردشة', support: 'الدعم الفني', direct: 'محادثات التاجر والمندوب',
        supportHint: 'محادثات الإدارة مع مستخدمي التطبيق', directHint: 'متابعة محادثات الطلبات بين التجار والمندوبين',
        conversations: 'محادثات', noSupport: 'لا توجد محادثات دعم فني حالياً.', noDirect: 'لا توجد محادثات بين التجار والمندوبين حالياً.',
        select: 'اختر محادثة لعرضها', merchant: 'التاجر', courier: 'المندوب', admin: 'الإدارة',
        technicalSupport: 'الدعم الفني', directReadOnly: 'هذه محادثة مباشرة بين التاجر والمندوب، وتظهر هنا للمراجعة فقط.',
        order: 'الطلب', customer: 'الزبون', phone: 'الهاتف', supportType: 'دعم فني', directType: 'تاجر ↔ مندوب',
        typeMessage: 'اكتب رسالة للدعم الفني', unable: 'تعذر إرسال الرسالة. حاول مرة أخرى.', noMessages: 'لا توجد رسائل بعد.',
        monitoring: 'متابعة فقط', unknown: 'مستخدم', orderChat: 'محادثة طلب',
    },
    en: {
        title: 'Chat', support: 'Technical support', direct: 'Merchant & courier chats',
        supportHint: 'Administration conversations with app users', directHint: 'Review order conversations between merchants and couriers',
        conversations: 'Conversations', noSupport: 'No technical-support conversations yet.', noDirect: 'No merchant-to-courier conversations yet.',
        select: 'Select a conversation to view it', merchant: 'Merchant', courier: 'Courier', admin: 'Administration',
        technicalSupport: 'Technical support', directReadOnly: 'This direct merchant-to-courier conversation is available for review only.',
        order: 'Order', customer: 'Customer', phone: 'Phone', supportType: 'Technical support', directType: 'Merchant ↔ Courier',
        typeMessage: 'Write a support reply', unable: 'Unable to send the message. Please try again.', noMessages: 'No messages yet.',
        monitoring: 'Read only', unknown: 'User', orderChat: 'Order conversation',
    },
    ku: {
        title: 'گفتوگۆ', support: 'پشتیوانی تەکنیکی', direct: 'گفتوگۆی بازرگان و گەیەنەر',
        supportHint: 'گفتوگۆکانی بەڕێوەبردن لەگەڵ بەکارهێنەرانی ئەپ', directHint: 'چاودێری گفتوگۆکانی داواکاری نێوان بازرگان و گەیەنەر',
        conversations: 'گفتوگۆکان', noSupport: 'هێشتا هیچ گفتوگۆی پشتیوانی نییە.', noDirect: 'هێشتا هیچ گفتوگۆیەکی بازرگان و گەیەنەر نییە.',
        select: 'گفتوگۆیەک هەڵبژێرە بۆ پیشاندان', merchant: 'بازرگان', courier: 'گەیەنەر', admin: 'بەڕێوەبردن',
        technicalSupport: 'پشتیوانی تەکنیکی', directReadOnly: 'ئەم گفتوگۆیە ڕاستەوخۆ لە نێوان بازرگان و گەیەنەرە و تەنها بۆ چاودێرییە.',
        order: 'داواکاری', customer: 'کڕیار', phone: 'مۆبایل', supportType: 'پشتیوانی تەکنیکی', directType: 'بازرگان ↔ گەیەنەر',
        typeMessage: 'وەڵامی پشتیوانی بنووسە', unable: 'نەتوانرا نامەکە بنێردرێت. دووبارە هەوڵ بدە.', noMessages: 'هێشتا هیچ نامەیەک نییە.',
        monitoring: 'تەنها بینین', unknown: 'بەکارهێنەر', orderChat: 'گفتوگۆی داواکاری',
    },
}
const l = (key) => copy[locale.value]?.[key] || copy.ar[key] || key

const DIRECT_TYPE = 'merchant_courier'
const SUPPORT_TYPE = 'support'
const courierRoles = new Set(['courier', 'pickup_courier', 'delivery_courier', 'transporter'])

function channelOf(chat) {
    if (chat?.channel) return chat.channel
    return chat?.counterparty_type === 'order_chat' ? DIRECT_TYPE : SUPPORT_TYPE
}

function isDirectChat(chat) {
    return channelOf(chat) === DIRECT_TYPE
}

function merchantOf(chat) {
    return chat?.merchant || (isDirectChat(chat) ? chat?.user : null)
}

function courierOf(chat) {
    return chat?.courier || (isDirectChat(chat) ? chat?.counterparty : null)
}

function supportPerson(chat) {
    return chat?.support_user || chat?.user || null
}

const fallbackChats = computed(() => props.chats || [])
const supportChats = computed(() => props.supportChats?.length || props.merchantCourierChats?.length
    ? props.supportChats || []
    : fallbackChats.value.filter((chat) => channelOf(chat) === SUPPORT_TYPE))
const merchantCourierChats = computed(() => props.supportChats?.length || props.merchantCourierChats?.length
    ? props.merchantCourierChats || []
    : fallbackChats.value.filter((chat) => channelOf(chat) === DIRECT_TYPE))
const selectedTab = ref(channelOf(props.activeChat))
const tabMeta = computed(() => Object.fromEntries(
    (props.chatTabs || [])
        .filter((tab) => tab?.key)
        .map((tab) => [tab.key, tab]),
))
const tabs = computed(() => [
    { id: SUPPORT_TYPE, label: l('support'), hint: l('supportHint'), count: Number(tabMeta.value[SUPPORT_TYPE]?.count ?? supportChats.value.length) },
    { id: DIRECT_TYPE, label: l('direct'), hint: l('directHint'), count: Number(tabMeta.value[DIRECT_TYPE]?.count ?? merchantCourierChats.value.length) },
])
const chats = computed(() => selectedTab.value === DIRECT_TYPE ? merchantCourierChats.value : supportChats.value)
const activeChat = computed(() => props.activeChat && channelOf(props.activeChat) === selectedTab.value ? props.activeChat : null)
const isViewingDirectChat = computed(() => isDirectChat(activeChat.value))
const canReply = computed(() => Boolean(activeChat.value?.can_reply) && !isViewingDirectChat.value)

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

function selectTab(tab) {
    selectedTab.value = tab
    sendError.value = ''
}

function openChat(chat) {
    selectedTab.value = channelOf(chat)
    router.visit(route('admin.chat.show', chat.id))
}

function initials(name) {
    return (name || '؟').trim().charAt(0)
}

function titleOf(chat) {
    if (isDirectChat(chat)) {
        return `${merchantOf(chat)?.name || l('merchant')} ↔ ${courierOf(chat)?.name || l('courier')}`
    }

    return chat?.display_title || chat?.title_ar || supportPerson(chat)?.name || l('technicalSupport')
}

function detailOf(chat) {
    if (isDirectChat(chat)) {
        const order = chat?.order
        return [order?.track_no || chat?.track_no || chat?.tracking_no, order?.customer_name || order?.customer_name_ar].filter(Boolean).join(' · ') || l('orderChat')
    }

    return supportPerson(chat)?.phone || l('technicalSupport')
}

function roleLabel(role) {
    if (courierRoles.has(role)) return l('courier')
    if (role === 'merchant' || role === 'owner') return l('merchant')
    if (role === 'admin') return l('admin')
    return l('unknown')
}

function messageRole(message) {
    if (message?.sender_role) return message.sender_role
    const direct = activeChat.value
    if (direct && Number(message?.sender_id) === Number(courierOf(direct)?.id)) return 'courier'
    if (direct && Number(message?.sender_id) === Number(merchantOf(direct)?.id)) return 'merchant'
    return null
}

function senderLabel(message) {
    const role = messageRole(message)
    const name = message?.sender_name

    if (name && role) return `${roleLabel(role)} · ${name}`
    if (name) return name
    return role ? roleLabel(role) : (message?.from_me ? l('admin') : l('unknown'))
}

function messageClass(message) {
    if (message?.from_me) return 'bubble-me'
    const role = messageRole(message)
    if (courierRoles.has(role)) return 'bubble-courier'
    if (role === 'merchant' || role === 'owner') return 'bubble-merchant'
    return 'bubble-them'
}

async function send() {
    const value = text.value.trim()
    if (!value || !activeChat.value || !canReply.value || sending.value) return

    sending.value = true
    sendError.value = ''
    try {
        const { data } = await axios.post(route('admin.chat.send', activeChat.value.id), { text: value })
        mergeMessages([data])
        text.value = ''
    } catch (_) {
        sendError.value = l('unable')
    } finally {
        sending.value = false
        scrollDown()
        await nextTick()
        composerEl.value?.focus({ preventScroll: true })
    }
}

async function refreshMessages() {
    if (!activeChat.value || refreshing || document.hidden) return
    refreshing = true
    try {
        const { data } = await axios.get(route('admin.chat.messages', activeChat.value.id), {
            params: { after_id: lastMessageId.value },
        })
        mergeMessages(data.messages)
        lastMessageId.value = Math.max(lastMessageId.value, Number(data.last_id || 0))
    } catch (_) {
        // A momentary request failure must not make the open thread unusable.
    } finally {
        refreshing = false
    }
}

function scrollDown() {
    nextTick(() => {
        if (threadEl.value) threadEl.value.scrollTop = threadEl.value.scrollHeight
    })
}

function onEnter(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault()
        send()
    }
}

watch(() => props.messages, (messages) => mergeMessages(messages), { deep: true })
watch(() => props.activeChat, (chat) => {
    if (chat) selectedTab.value = channelOf(chat)
    replaceMessages(props.messages)
    refreshMessages()
}, { deep: true })

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
    <AdminShell :title="l('title')">
        <div class="chat-tabs" role="tablist" :aria-label="l('title')">
            <button v-for="tab in tabs" :key="tab.id" type="button" class="chat-tab" :class="{ active: selectedTab === tab.id }" role="tab" :aria-selected="selectedTab === tab.id" @click="selectTab(tab.id)">
                <span class="chat-tab-icon" aria-hidden="true">
                    <svg v-if="tab.id === 'support'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 13a8 8 0 0 1 16 0v4a3 3 0 0 1-3 3h-2v-6h4M9 20H7a3 3 0 0 1-3-3v-4h4v7Zm5 0c0 1.1-.9 2-2 2h-2"/></svg>
                    <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 18a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm10 0a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM7 10l3-5h4l3 5M7 14h10"/></svg>
                </span>
                <span><b>{{ tab.label }}</b><small>{{ tab.hint }}</small></span>
                <em v-if="tab.count">{{ tab.count }}</em>
            </button>
        </div>

        <div class="chat-layout chat-layout-tabs">
            <aside class="chat-list">
                <div class="chat-list-head"><div><span>{{ selectedTab === DIRECT_TYPE ? l('direct') : l('support') }}</span><b>{{ l('conversations') }}</b></div><small>{{ chats.length }}</small></div>
                <button v-for="chat in chats" :key="chat.id" type="button" class="chat-item" :class="{ active: activeChat?.id === chat.id }" @click="openChat(chat)">
                    <div class="avatar">{{ initials(titleOf(chat)) }}</div>
                    <div class="chat-item-main"><div class="chat-item-title"><b>{{ titleOf(chat) }}</b><span v-if="isDirectChat(chat)" class="channel-chip direct">{{ l('directType') }}</span></div><span class="chat-item-detail">{{ detailOf(chat) }}</span><span class="chat-preview">{{ chat.last_message || l('noMessages') }}</span></div>
                    <div class="chat-item-meta"><time>{{ chat.last_at }}</time><span v-if="chat.unread > 0" class="unread-badge">{{ chat.unread }}</span></div>
                </button>
                <div v-if="!chats.length" class="empty">{{ selectedTab === DIRECT_TYPE ? l('noDirect') : l('noSupport') }}</div>
            </aside>

            <section v-if="activeChat" class="chat-thread">
                <header class="thread-head chat-thread-head">
                    <div class="avatar">{{ initials(titleOf(activeChat)) }}</div>
                    <div class="thread-head-main">
                        <div class="thread-title-row"><b>{{ titleOf(activeChat) }}</b><span v-if="isViewingDirectChat" class="channel-chip direct">{{ l('monitoring') }}</span></div>
                        <template v-if="isViewingDirectChat"><div class="participant-line"><span class="participant merchant"><i>{{ initials(merchantOf(activeChat)?.name) }}</i>{{ l('merchant') }}: <b>{{ merchantOf(activeChat)?.name || l('unknown') }}</b></span><span class="participant courier"><i>{{ initials(courierOf(activeChat)?.name) }}</i>{{ l('courier') }}: <b>{{ courierOf(activeChat)?.name || l('unknown') }}</b></span></div></template>
                        <template v-else><div class="thread-phone">{{ supportPerson(activeChat)?.phone }}</div></template>
                        <div v-if="activeChat.order" class="order-context"><span><b>{{ l('order') }}:</b> {{ activeChat.order.track_no }}</span><span v-if="activeChat.order.customer_name || activeChat.order.customer_name_ar"><b>{{ l('customer') }}:</b> {{ activeChat.order.customer_name || activeChat.order.customer_name_ar }}</span><span v-if="activeChat.order.phone"><b>{{ l('phone') }}:</b> {{ activeChat.order.phone }}</span></div>
                    </div>
                </header>
                <div v-if="isViewingDirectChat" class="direct-readonly-notice"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="M9 12h6"/></svg>{{ l('directReadOnly') }}</div>
                <div ref="threadEl" class="thread chat-thread-body" style="height: 0; flex: 1; min-height: 200px">
                    <div v-for="message in msgs" :key="message.id" class="message-wrap" :class="{ own: message.from_me }"><span class="message-sender" :class="messageRole(message)">{{ senderLabel(message) }}</span><div class="bubble" :class="messageClass(message)">{{ message.text }}<span class="bubble-time">{{ message.time }}</span></div></div>
                    <div v-if="!msgs.length" class="empty-hint">{{ l('noMessages') }}</div>
                </div>
                <div v-if="canReply" class="chat-input-bar"><input ref="composerEl" v-model="text" :placeholder="l('typeMessage')" @keydown="onEnter" /><button type="button" class="send-btn" :disabled="sending || !text.trim()" @pointerdown.prevent @click="send"><span v-if="sending" class="loader"></span><svg v-else width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z M22 2 11 13" /></svg></button></div>
                <p v-if="sendError" class="chat-send-error">{{ sendError }}</p>
            </section>
            <section v-else class="chat-thread chat-empty">{{ l('select') }}</section>
        </div>
    </AdminShell>
</template>

<style scoped>
.chat-tabs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:16px}.chat-tab{display:flex;align-items:center;gap:10px;min-width:0;padding:12px 14px;border:1px solid var(--border);border-radius:13px;background:var(--surface);color:var(--ink-soft);text-align:start;cursor:pointer;transition:.18s ease}.chat-tab:hover{border-color:color-mix(in srgb,var(--primary) 45%,var(--border));background:var(--surface-2)}.chat-tab.active{border-color:var(--primary);background:var(--primary-tint);color:var(--primary-strong);box-shadow:0 6px 18px color-mix(in srgb,var(--primary) 12%,transparent)}.chat-tab-icon{display:grid;place-items:center;width:34px;height:34px;flex:none;border-radius:10px;background:var(--surface-2)}.chat-tab.active .chat-tab-icon{background:var(--surface);color:var(--primary)}.chat-tab span:not(.chat-tab-icon){display:grid;gap:2px;min-width:0;flex:1}.chat-tab b{font-size:11px;font-weight:900}.chat-tab small{overflow:hidden;color:var(--ink-faint);font-size:8.7px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}.chat-tab em{display:grid;place-items:center;min-width:20px;height:20px;padding:0 5px;border-radius:99px;background:var(--surface-2);font-size:9px;font-style:normal;font-weight:900}.chat-tab.active em{background:var(--primary);color:white}.chat-layout-tabs{height:calc(100vh - 225px);min-height:460px}.chat-list{border-radius:16px}.chat-list-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:13px 15px;border-bottom:1px solid var(--border);background:var(--surface-2)}.chat-list-head>div{display:grid;gap:2px}.chat-list-head span{color:var(--ink-faint);font-size:8.5px;font-weight:800}.chat-list-head b{color:var(--ink);font-size:12px;font-weight:950}.chat-list-head small{display:grid;place-items:center;min-width:24px;height:24px;border-radius:8px;background:var(--surface);color:var(--primary-strong);font-size:10px;font-weight:900}.chat-item{border:0;border-bottom:1px solid var(--border);font-family:inherit}.chat-item:last-of-type{border-bottom:0}.chat-item-main{display:grid;gap:3px;flex:1;min-width:0}.chat-item-title{display:flex;align-items:center;gap:5px;min-width:0}.chat-item-title>b{overflow:hidden;color:var(--ink);font-size:10.8px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.chat-item-detail{overflow:hidden;color:var(--primary-strong);font-size:8.8px;font-weight:800;text-overflow:ellipsis;white-space:nowrap}.chat-preview{overflow:hidden;color:var(--ink-faint);font-size:8.8px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}.chat-item-meta{display:grid;justify-items:end;align-content:center;gap:5px;flex:none}.chat-item-meta time{color:var(--ink-faint);font-size:8px;font-weight:700;white-space:nowrap}.channel-chip{display:inline-flex;align-items:center;width:max-content;padding:3px 6px;border-radius:99px;font-size:7.6px;font-weight:900;white-space:nowrap}.channel-chip.direct{background:color-mix(in srgb,var(--accent) 14%,var(--surface));color:var(--accent)}.chat-thread-head{align-items:flex-start}.thread-head-main{display:grid;gap:4px;min-width:0;flex:1}.thread-title-row{display:flex;align-items:center;gap:6px;min-width:0}.thread-title-row>b{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.thread-phone{color:var(--ink-faint);font-size:9.5px;font-weight:700}.participant-line{display:flex;flex-wrap:wrap;gap:6px 10px}.participant{display:inline-flex;align-items:center;gap:4px;color:var(--ink-soft);font-size:9px;font-weight:750}.participant i{display:grid;place-items:center;width:17px;height:17px;border-radius:50%;font-size:8px;font-style:normal;font-weight:900}.participant b{color:var(--ink);font-size:9.5px}.participant.merchant i{background:#daf2ea;color:#087662}.participant.courier i{background:#ddecff;color:#1671c4}.order-context{display:flex;flex-wrap:wrap;gap:3px 8px;color:var(--ink-faint);font-size:8.5px;font-weight:700}.order-context b{color:var(--ink-soft)}.direct-readonly-notice{display:flex;align-items:center;gap:7px;margin:10px 12px 0;padding:8px 10px;border:1px solid color-mix(in srgb,var(--accent) 36%,var(--border));border-radius:10px;background:color-mix(in srgb,var(--accent) 10%,var(--surface));color:var(--accent);font-size:9.2px;font-weight:800}.chat-thread-body{gap:12px}.message-wrap{display:grid;justify-items:start;gap:3px;max-width:100%}.message-wrap.own{justify-items:end}.message-sender{padding-inline:4px;color:var(--ink-faint);font-size:8.5px;font-weight:850}.message-sender.courier{color:#1671c4}.message-sender.merchant,.message-sender.owner{color:#087662}.message-wrap .bubble{max-width:min(78%,580px)}.bubble-courier{align-self:flex-start;border:1px solid #b7d9fb;background:#edf7ff;color:#145f9d;border-start-start-radius:4px}.bubble-merchant{align-self:flex-start;border:1px solid #b8e0d4;background:#edf9f4;color:#076d5a;border-start-start-radius:4px}.chat-empty{min-height:260px}.chat-send-error{margin:0;padding:0 14px 11px;color:var(--danger);font-size:10px;font-weight:800}@media(max-width:860px){.chat-layout-tabs{height:auto;min-height:0;grid-template-columns:1fr}.chat-list{max-height:340px}.chat-thread{min-height:510px}.chat-tabs{grid-template-columns:1fr}.chat-tab small{white-space:normal}.chat-tab{align-items:flex-start}}@media(max-width:520px){.participant-line{display:grid;gap:4px}.message-wrap .bubble{max-width:90%}.order-context{display:grid;gap:2px}.chat-layout-tabs{margin-inline:-2px}.chat-tab{padding:10px}.chat-tab-icon{width:30px;height:30px}}
</style>
