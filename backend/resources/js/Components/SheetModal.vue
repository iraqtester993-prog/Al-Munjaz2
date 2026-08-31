<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    wide: { type: Boolean, default: false },
    fullscreen: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])

const dragY = ref(0)
const dragging = ref(false)
const sheetStyle = computed(() => ({
    ...(props.wide ? { maxWidth: '640px' } : {}),
    transform: dragY.value ? `translateY(${dragY.value}px)` : undefined,
}))

let startY = 0
let startTime = 0
let tracking = false

function startDrag(event) {
    // The close button must remain an immediate action. A drag starts only
    // from the sheet header/handle so scrolling long forms is unaffected.
    if (event.target.closest('button')) return
    const point = event.touches?.[0]
    if (!point) return

    tracking = true
    startY = point.clientY
    startTime = Date.now()
    dragY.value = 0
}

function moveDrag(event) {
    if (!tracking) return
    const point = event.touches?.[0]
    if (!point) return

    const distance = point.clientY - startY
    if (distance <= 0) return

    dragging.value = true
    dragY.value = Math.min(260, distance)
    // Stops the page behind the sheet from rubber-banding while the handle
    // is dragged down.
    event.preventDefault()
}

function endDrag() {
    if (!tracking) return

    const distance = dragY.value
    const fastSwipe = distance > 42 && Date.now() - startTime < 260
    tracking = false
    dragging.value = false

    if (distance > 96 || fastSwipe) {
        dragY.value = 0
        emit('close')
        return
    }

    dragY.value = 0
}

onBeforeUnmount(() => {
    tracking = false
})
</script>

<template>
    <Teleport to="body">
        <div class="overlay sheet-overlay" :class="{ open }" @click.self="emit('close')">
            <section class="sheet-modal" :class="{ dragging, fullscreen }" :style="sheetStyle" role="dialog" aria-modal="true" :aria-label="title || subtitle">
                <header class="sheet-header" @touchstart="startDrag" @touchmove="moveDrag" @touchend="endDrag" @touchcancel="endDrag">
                    <span class="sheet-handle" aria-hidden="true"></span>
                    <button class="sheet-close" type="button" :aria-label="t('Close')" @click="emit('close')">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
                    </button>
                </header>
                <div class="sheet-body">
                    <div v-if="title" class="sheet-title">
                        <h3>{{ title }}</h3>
                        <p v-if="subtitle" class="text-muted">{{ subtitle }}</p>
                    </div>
                    <slot />
                </div>
            </section>
        </div>
    </Teleport>
</template>

<style scoped>
.sheet-modal{transition:transform .2s cubic-bezier(.2,.8,.2,1)}.sheet-modal.dragging{transition:none}.sheet-modal.fullscreen{width:100%;max-width:none!important;height:100dvh;max-height:100dvh;border-radius:0;padding:max(18px,env(safe-area-inset-top,0px)) 18px max(18px,env(safe-area-inset-bottom,0px));box-sizing:border-box}.sheet-header{display:flex;align-items:center;gap:8px;padding:4px 0 12px;border-bottom:1px solid var(--border);margin-bottom:14px;touch-action:none}.sheet-handle{display:block;flex:1;height:4px;margin:0;border-radius:20px;background:var(--border)}.sheet-close{display:grid;width:38px;height:38px;place-items:center;flex:none;border:0;border-radius:12px;background:var(--danger);color:#fff;box-shadow:0 2px 8px rgba(239,68,68,.3);cursor:pointer}.sheet-close:active{transform:scale(.96)}.sheet-body{flex:1;min-height:0;overflow-y:auto;padding-inline-end:1px;scrollbar-width:none}.sheet-body::-webkit-scrollbar{display:none}
.sheet-title h3 {
    font-size: 15px;
    font-weight: 900;
    margin-bottom: 2px;
}
.sheet-title {
    margin-bottom: 16px;
}
</style>
