<script setup>
import { onMounted, onUnmounted, ref } from 'vue'

defineProps({
    surface: { type: Boolean, default: false },
})

const emit = defineEmits(['installed'])

const deferredPrompt = ref(null)
const isIos = ref(false)
const installed = ref(false)
const installing = ref(false)
const showInstructions = ref(false)
const dismissed = ref(false)

function isStandalone() {
    const nativeBridge = window.AlMunjazNativeLocation
    const nativeShell = nativeBridge && typeof nativeBridge.start === 'function'

    return window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true || nativeShell
}

function onBeforeInstall(event) {
    event.preventDefault()
    deferredPrompt.value = event
}

function onInstalled() {
    installed.value = true
    deferredPrompt.value = null
    emit('installed')
}

async function install() {
    if (!deferredPrompt.value) {
        if (isIos.value || showInstructions.value) {
            dismissed.value = true
            return
        }

        showInstructions.value = true
        return
    }

    if (installing.value) return
    installing.value = true
    deferredPrompt.value.prompt()
    await deferredPrompt.value.userChoice
    deferredPrompt.value = null
    installing.value = false
}

onMounted(() => {
    const agent = window.navigator.userAgent
    const standalone = isStandalone()
    isIos.value = /iPad|iPhone|iPod/.test(agent) && !standalone
    installed.value = Boolean(standalone)
    if (installed.value) emit('installed')
    window.addEventListener('beforeinstallprompt', onBeforeInstall)
    window.addEventListener('appinstalled', onInstalled)
})

onUnmounted(() => {
    window.removeEventListener('beforeinstallprompt', onBeforeInstall)
    window.removeEventListener('appinstalled', onInstalled)
})
</script>

<template>
    <aside v-if="!installed && !dismissed" class="pwa-install-banner" :class="{ 'pwa-install-banner--surface': surface }">
        <span class="install-brand" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 16.5 9.2 11l3 3L20 6.2" />
                <path d="M16 6.2H20v4" />
                <path d="M5 5.5h5M5 9h2.5M5 19h14" />
            </svg>
        </span>
        <div class="install-copy">
            <b>{{ t('Install Al-Munjaz on your phone') }}</b>
            <p v-if="isIos || showInstructions">{{ isIos ? t('Install iOS instructions') : t('Install browser instructions') }}</p>
            <p v-else>{{ t('Install the app to enable the full delivery experience.') }}</p>
        </div>
        <button type="button" :disabled="installing" @click="install">
            {{ installing ? t('Installing…') : (deferredPrompt ? t('Install') : (showInstructions || isIos ? t('Understood') : t('Install the app'))) }}
        </button>
    </aside>
</template>

<style scoped>
.pwa-install-banner{display:flex;align-items:center;gap:10px;margin-top:18px;padding:11px;border:1px solid rgba(255,255,255,.3);border-radius:16px;background:linear-gradient(135deg,rgba(255,255,255,.2),rgba(255,255,255,.08));color:#fff;box-shadow:0 12px 28px rgba(0,0,0,.11)}.install-brand{display:grid;width:39px;height:39px;place-items:center;flex:none;border-radius:12px;background:linear-gradient(135deg,#f59e0b,#f97316);box-shadow:0 5px 13px rgba(249,115,22,.28)}.install-brand svg{width:21px;height:21px}.install-copy{display:grid;min-width:0;flex:1;gap:2px}.install-copy b{font-size:10.5px;font-weight:900}.install-copy p{margin:0;font-size:9px;font-weight:650;line-height:1.55;opacity:.88}.pwa-install-banner button{min-height:34px;padding:7px 10px;border:0;border-radius:10px;background:#fff;color:var(--primary-strong);font:850 9.5px var(--font);white-space:nowrap;cursor:pointer}.pwa-install-banner button:disabled{opacity:.7;cursor:wait}@media(max-width:340px){.pwa-install-banner{align-items:flex-start}.pwa-install-banner button{padding-inline:8px;font-size:8.5px}}
.pwa-install-banner--surface{margin:0 0 12px;border-color:var(--border);background:var(--surface);color:var(--ink);box-shadow:0 8px 20px rgba(15,27,26,.07)}.pwa-install-banner--surface .install-copy p{color:var(--ink-soft);opacity:1}.pwa-install-banner--surface button{background:var(--primary);color:#fff;box-shadow:0 6px 14px color-mix(in srgb,var(--primary) 20%,transparent)}
</style>
