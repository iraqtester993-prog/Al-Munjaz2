import Pusher from 'pusher-js'

let client = null

function config() {
    return window.__almunjazRealtime || null
}

function pusher() {
    const settings = config()
    if (!settings?.key || !settings?.cluster) return null

    if (!client) {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        client = new Pusher(settings.key, {
            cluster: settings.cluster,
            forceTLS: true,
            authEndpoint: '/chat/pusher-auth',
            auth: { headers: csrf ? { 'X-CSRF-TOKEN': csrf } : {} },
        })
    }

    return client
}

// Subscribe only while a chat is actually visible. The REST endpoint remains
// the source of truth; a Pusher event merely wakes the current thread so the
// client can refresh immediately instead of waiting for its polling timer.
export function subscribeToChat(chatId, onMessage) {
    const instance = pusher()
    if (!instance || !chatId) return () => {}

    const channel = instance.subscribe(`private-chat.${chatId}`)
    const listener = (event) => {
        if (Number(event?.chat_id) === Number(chatId)) onMessage(event)
    }

    channel.bind('chat.message', listener)

    return () => {
        channel.unbind('chat.message', listener)
        instance.unsubscribe(`private-chat.${chatId}`)
    }
}

// A private user channel stays connected throughout the app. It updates the
// chat badge and notification inbox without polling or a full page refresh.
export function subscribeToUserRealtime(userId, onEvent) {
    const instance = pusher()
    if (!instance || !userId) return () => {}

    const name = `private-user.${userId}`
    const channel = instance.subscribe(name)
    const listener = (event) => onEvent('chat.message', event)
    const notificationListener = (event) => onEvent('app.notification', event)
    channel.bind('chat.message', listener)
    channel.bind('app.notification', notificationListener)

    return () => {
        channel.unbind('chat.message', listener)
        channel.unbind('app.notification', notificationListener)
        instance.unsubscribe(name)
    }
}
