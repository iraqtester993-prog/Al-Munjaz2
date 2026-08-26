<script setup>
import { ref } from 'vue'

defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    wide: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])
</script>

<template>
    <Teleport to="body">
        <div class="overlay sheet-overlay" :class="{ open }" @click.self="emit('close')">
            <section class="sheet-modal" :style="wide ? { maxWidth: '640px' } : {}" role="dialog" aria-modal="true" :aria-label="title || subtitle">
                <header class="sheet-header">
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
.sheet-header{display:flex;align-items:center;gap:8px;padding:0 0 12px;border-bottom:1px solid var(--border);margin-bottom:14px}.sheet-handle{display:block;flex:1;height:4px;margin:0;border-radius:20px;background:var(--border)}.sheet-close{display:grid;width:38px;height:38px;place-items:center;flex:none;border:0;border-radius:12px;background:var(--danger);color:#fff;box-shadow:0 2px 8px rgba(239,68,68,.3);cursor:pointer}.sheet-close:active{transform:scale(.96)}.sheet-body{flex:1;min-height:0;overflow-y:auto;padding-inline-end:1px;scrollbar-width:none}.sheet-body::-webkit-scrollbar{display:none}
.sheet-title h3 {
    font-size: 15px;
    font-weight: 900;
    margin-bottom: 2px;
}
.sheet-title {
    margin-bottom: 16px;
}
</style>
