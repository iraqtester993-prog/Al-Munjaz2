<script setup>
import { computed } from 'vue'

const props = defineProps({
    items: { type: Array, required: true }, // [{label, value, color}]
})

const colors = ['var(--st-pending)', 'var(--st-approved)', 'var(--st-courier)', 'var(--st-delivered)', 'var(--st-returned)']

const total = computed(() => props.items.reduce((s, i) => s + (Number(i.value) || 0), 0))
const max = computed(() => Math.max(1, ...props.items.map((i) => Number(i.value) || 0)))

const segments = computed(() => {
    let acc = 0
    return props.items.map((i, idx) => {
        const v = Number(i.value) || 0
        const color = i.color || colors[idx % colors.length]
        const seg = { ...i, color }
        if (total.value === 0) return { ...seg, from: 0, to: 0, visible: false }
        const from = (acc / total.value) * 360
        acc += v
        return { ...seg, from, to: (acc / total.value) * 360, visible: v > 0 }
    })
})

const gradient = computed(() => {
    const stops = []
    for (const s of segments.value) {
        if (!s.visible) continue
        stops.push(`${s.color} ${s.from}deg`, `${s.color} ${s.to}deg`)
    }
    if (!stops.length) return 'conic-gradient(var(--surface-3) 0 360deg)'
    return `conic-gradient(${stops.join(', ')})`
})
</script>

<template>
    <div class="donut-row">
        <div class="donut" :style="{ background: gradient }">
            <div class="donut-hole">
                <b>{{ total }}</b>
                <span>{{ t('Orders') }}</span>
            </div>
        </div>
        <div class="legend-col">
            <div v-for="s in segments" :key="s.label" class="legend-item">
                <span class="ldot" :style="{ background: s.color }"></span>
                {{ s.label }}
                <b>{{ s.value }}</b>
            </div>
        </div>
    </div>
</template>
