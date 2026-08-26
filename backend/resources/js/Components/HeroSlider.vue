<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'

const props = defineProps({
    slides: { type: Array, required: true },
})

const wrap = ref(null)
const active = ref(0)
const page = usePage()
let autoplay

function scrollTo(i) {
    const el = wrap.value
    if (!el) return
    const direction = document.documentElement.dir === 'rtl' ? -1 : 1
    el.scrollTo({ left: direction * i * el.clientWidth, behavior: 'smooth' })
}

function onScroll() {
    const el = wrap.value
    if (!el || el.clientWidth === 0) return
    active.value = Math.round(Math.abs(el.scrollLeft) / el.clientWidth)
}

function text(slide) {
    const l = page.props.locale || 'ar'
    return {
        // Content that has not yet been translated to Kurdish should fall back
        // to English rather than unexpectedly switching the user back to Arabic.
        title: slide[`title_${l}`] || slide.title_en || slide.title_ar,
        body: slide[`body_${l}`] || slide.body_en || slide.body_ar,
        tag: slide[`tag_${l}`] || slide.tag_en || slide.tag_ar,
        cta: slide[`cta_${l}`] || slide.cta_en || slide.cta_ar,
    }
}

onMounted(() => {
    if (props.slides.length < 2) return
    autoplay = window.setInterval(() => {
        const next = (active.value + 1) % props.slides.length
        scrollTo(next)
    }, 2000)
})

onUnmounted(() => window.clearInterval(autoplay))
</script>

<template>
    <div class="hero-slider-wrap">
        <div ref="wrap" class="hero-slider" @scroll.passive="onScroll">
            <div
                v-for="(s, i) in slides"
                :key="i"
                class="hero-slide"
                :style="{ backgroundImage: s.image_url ? `linear-gradient(270deg, rgba(7, 35, 32, .78), rgba(7, 35, 32, .22)), url(${s.image_url})` : (s.accent ? 'linear-gradient(135deg, var(--accent), #B4661A)' : 'linear-gradient(135deg, var(--primary-strong), var(--primary))'), backgroundSize: 'cover', backgroundPosition: 'center' }"
            >
                <div class="hero-slide-text">
                    <small v-if="text(s).tag" class="hero-slide-tag">{{ text(s).tag }}</small>
                    <h4>{{ text(s).title }}</h4>
                    <p>{{ text(s).body }}</p>
                    <a v-if="s.action_url && text(s).cta" class="hero-slide-cta" :href="s.action_url">{{ text(s).cta }}</a>
                </div>
            </div>
        </div>
        <div v-if="slides.length > 1" class="hero-slider-dots">
            <span v-for="(s, i) in slides" :key="i" class="hd" :class="{ active: active === i }" @click="scrollTo(i)"></span>
        </div>
    </div>
</template>

<style scoped>
.hero-slide-text{display:grid;align-content:center;gap:5px;min-width:0}.hero-slide-tag{width:max-content;max-width:100%;padding:3px 7px;border-radius:999px;color:#fff;background:rgba(0,0,0,.24);font-size:8.5px;font-weight:850}.hero-slide-text h4,.hero-slide-text p{margin:0}.hero-slide-cta{width:max-content;margin-top:3px;padding:6px 9px;border-radius:8px;color:#053431;background:#fff;font-size:9.5px;font-weight:900;text-decoration:none;box-shadow:0 4px 10px rgba(0,0,0,.14)}
</style>
