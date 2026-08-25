<script setup>
import { ref } from 'vue'
import { usePage } from '@inertiajs/vue3'

const props = defineProps({
    slides: { type: Array, required: true },
})

const wrap = ref(null)
const active = ref(0)
const page = usePage()

function scrollTo(i) {
    const el = wrap.value
    if (!el) return
    el.scrollTo({ left: i * el.clientWidth, behavior: 'smooth' })
}

function onScroll() {
    const el = wrap.value
    if (!el || el.clientWidth === 0) return
    active.value = Math.round(el.scrollLeft / el.clientWidth)
}

function text(slide) {
    const l = page.props.locale || 'ar'
    return {
        title: slide[`title_${l}`] || slide.title_ar,
        body: slide[`body_${l}`] || slide.body_ar,
        tag: slide[`tag_${l}`] || slide.tag_ar,
    }
}
</script>

<template>
    <div class="hero-slider-wrap">
        <div ref="wrap" class="hero-slider" @scroll.passive="onScroll">
            <div
                v-for="(s, i) in slides"
                :key="i"
                class="hero-slide"
                :style="{ background: s.accent ? 'linear-gradient(135deg, var(--accent), #B4661A)' : 'linear-gradient(135deg, var(--primary-strong), var(--primary))' }"
            >
                <div class="hero-slide-text">
                    <span class="hc-chip" style="background: rgba(255,255,255,.16); border-radius:8px; padding:4px 9px; font-size:9.5px; font-weight:800; display:inline-block; margin-bottom:10px;">
                        {{ text(s).tag }}
                    </span>
                    <h4>{{ text(s).title }}</h4>
                    <p>{{ text(s).body }}</p>
                </div>
            </div>
        </div>
        <div v-if="slides.length > 1" class="hero-slider-dots">
            <span v-for="(s, i) in slides" :key="i" class="hd" :class="{ active: active === i }" @click="scrollTo(i)"></span>
        </div>
    </div>
</template>
