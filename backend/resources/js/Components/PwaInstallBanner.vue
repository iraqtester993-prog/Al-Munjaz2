<script setup>
import { onMounted, onUnmounted, ref } from 'vue'

const deferredPrompt = ref(null)
const isIos = ref(false)
const installed = ref(false)
const installing = ref(false)
const showInstructions = ref(false)

function onBeforeInstall(event) {
    event.preventDefault()
    deferredPrompt.value = event
}

function onInstalled() {
    installed.value = true
    deferredPrompt.value = null
}

async function install() {
    if (!deferredPrompt.value) {
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
    const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone
    isIos.value = /iPad|iPhone|iPod/.test(agent) && !standalone
    installed.value = Boolean(standalone)
    window.addEventListener('beforeinstallprompt', onBeforeInstall)
    window.addEventListener('appinstalled', onInstalled)
})

onUnmounted(() => {
    window.removeEventListener('beforeinstallprompt', onBeforeInstall)
    window.removeEventListener('appinstalled', onInstalled)
})
</script>

<template>
    <aside v-if="!installed" class="pwa-install-banner">
        <span class="install-icon">⇩</span>
        <p v-if="isIos || showInstructions">{{ isIos ? 'من Safari اضغط مشاركة ثم اختر «إضافة إلى الشاشة الرئيسية».' : 'من قائمة المتصفح اختر «تثبيت التطبيق» أو «إضافة إلى الشاشة الرئيسية».' }}</p>
        <p v-else>أضِف المنجز السريع إلى شاشتك الرئيسية للوصول السريع والعمل دون اتصال جزئياً.</p>
        <button type="button" :disabled="installing" @click="install">{{ installing ? 'جارٍ التثبيت…' : (deferredPrompt ? 'تثبيت' : 'إرشادات') }}</button>
    </aside>
</template>

<style scoped>
.pwa-install-banner { display:flex; align-items:center; gap:8px; margin-top:18px; padding:10px 11px; border:1px solid rgba(255,255,255,.26); border-radius:14px; background:rgba(255,255,255,.13); color:#fff; }
.install-icon { font-size:22px; line-height:1; }
.pwa-install-banner p { flex:1; margin:0; font-size:9.5px; line-height:1.65; font-weight:600; }
.pwa-install-banner button { padding:7px 9px; border-radius:8px; background:#fff; color:var(--primary-strong); font:inherit; font-size:10px; font-weight:900; white-space:nowrap; }
</style>
