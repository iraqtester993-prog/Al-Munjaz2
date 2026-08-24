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
        <div class="overlay" :class="{ open }" @click.self="emit('close')">
            <div class="sheet-modal" :style="wide ? { maxWidth: '640px' } : {}">
                <div class="sheet-handle" @click="emit('close')"></div>
                <div v-if="title" class="sheet-title">
                    <h3>{{ title }}</h3>
                    <p v-if="subtitle" class="text-muted">{{ subtitle }}</p>
                </div>
                <slot />
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.sheet-title h3 {
    font-size: 15px;
    font-weight: 900;
    margin-bottom: 2px;
}
.sheet-title {
    margin-bottom: 16px;
}
</style>
