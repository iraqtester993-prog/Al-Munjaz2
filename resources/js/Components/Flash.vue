<script setup>
import { watch, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'

const page = usePage()
const toasts = ref([])
let uid = 0

watch(
    () => [page.props.flash?.success, page.props.flash?.error],
    ([success, error]) => {
        if (success) push('success', success)
        if (error) push('error', error)
    }
)

function push(type, text) {
    const id = ++uid
    toasts.value.push({ id, type, text })
    setTimeout(() => dismiss(id), 3200)
}

function dismiss(id) {
    toasts.value = toasts.value.filter((t) => t.id !== id)
}
</script>

<template>
    <div class="toast-wrap">
        <TransitionGroup name="fade">
            <div v-for="t in toasts" :key="t.id" class="toast" :class="t.type">
                <span v-if="t.type === 'success'">✓</span>
                <span v-else>!</span>
                {{ t.text }}
            </div>
        </TransitionGroup>
    </div>
</template>
