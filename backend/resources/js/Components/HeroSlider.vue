<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'

const props = defineProps({
    slides: { type: Array, required: true },
})

const wrap = ref(null)
const active = ref(0)
const activatedImages = ref(new Set())
const page = usePage()
let autoplay

function activateImage(index) {
    if (!props.slides[index]?.image_url || activatedImages.value.has(index)) return

    // A CSS background downloads as soon as it is rendered. Only add the URL
    // for the slide a user is viewing, so a long slider does not eagerly fetch
    // every remote image during the first page paint.
    const next = new Set(activatedImages.value)
    next.add(index)
    activatedImages.value = next
}

function activateSlide(index) {
    const safeIndex = Math.max(0, Math.min(index, props.slides.length - 1))
    active.value = safeIndex
    activateImage(safeIndex)
}

function scrollTo(i) {
    const el = wrap.value
    if (!el) return
    activateSlide(i)
    const direction = document.documentElement.dir === 'rtl' ? -1 : 1
    el.scrollTo({ left: direction * i * el.clientWidth, behavior: 'smooth' })
}

function onScroll() {
    const el = wrap.value
    if (!el || el.clientWidth === 0) return
    activateSlide(Math.round(Math.abs(el.scrollLeft) / el.clientWidth))
}

function backgroundStyle(slide, index) {
    const fallback = slide.accent
        ? 'linear-gradient(135deg, var(--accent), #B4661A)'
        : 'linear-gradient(135deg, var(--primary-strong), var(--primary))'
    const image = slide.image_url && activatedImages.value.has(index)

    return {
        backgroundImage: image
            ? `linear-gradient(270deg, rgba(7, 35, 32, .78), rgba(7, 35, 32, .22)), url(${slide.image_url})`
            : fallback,
        backgroundSize: 'cover',
        backgroundPosition: 'center',
    }
}

function text(slide) {
    const l = page.props.locale || 'ar'
    return {
        // Content that has not yet been translated to Kurdish should fall back
        // to English rather than unexpectedly switching the user back to Arabic.
        title: slide[`title_${l}`] || slide.title_en || slide.title_ar,
        body: slide[`body_${l}`] || slide.body_en || slide.body_ar,
    }
}

onMounted(() => {
    activateSlide(0)
    if (props.slides.length < 2) return
    autoplay = window.setInterval(() => {
        const next = (active.value + 1) % props.slides.length
        scrollTo(next)
    }, 2000)
})

onUnmounted(() => window.clearInterval(autoplay))

watch(
    () => props.slides.map((slide) => slide.image_url || '').join('|'),
    () => {
        activatedImages.value = new Set()
        activateSlide(Math.min(active.value, Math.max(props.slides.length - 1, 0)))
    },
)
</script>

<template>
    <div class="hero-slider-wrap">
        <div ref="wrap" class="hero-slider" @scroll.passive="onScroll">
            <div
                v-for="(s, i) in slides"
                :key="i"
                class="hero-slide"
                :style="backgroundStyle(s, i)"
            >
                <div class="hero-slide-text">
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

<style scoped>
.hero-slide-text{display:grid;align-content:center;gap:5px;min-width:0}.hero-slide-text h4,.hero-slide-text p{margin:0}
</style>
