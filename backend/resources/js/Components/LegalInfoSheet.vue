<script setup>
import { computed, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import SheetModal from './SheetModal.vue'

const props = defineProps({ open: { type: Boolean, default: false }, legalContent: { type: Object, default: () => ({}) } })
const emit = defineEmits(['close'])
const page = usePage()
const locale = computed(() => page.props.locale || 'ar')
const tab = ref('privacy')

function content(key, fallback) {
    const values = props.legalContent?.[key] || {}
    return values[locale.value]?.trim() || values.ar?.trim() || values.en?.trim() || fallback
}

const privacy = computed(() => content('privacy_policy', t('Privacy policy introduction')))
const terms = computed(() => content('terms_of_use', t('Terms of use introduction')))
const activeTitle = computed(() => tab.value === 'privacy' ? t('Privacy Policy') : t('Terms of Use'))
const activeBody = computed(() => tab.value === 'privacy' ? privacy.value : terms.value)
const paragraphs = computed(() => activeBody.value.split(/\n\s*\n/).map((item) => item.trim()).filter(Boolean))

watch(() => props.open, (value) => { if (value) tab.value = 'privacy' })
</script>

<template>
    <SheetModal :open="open" :title="t('Privacy Policy') + ' · ' + t('Terms of Use')" fullscreen @close="emit('close')">
        <section class="legal-info" :dir="locale === 'en' ? 'ltr' : 'rtl'">
            <div class="legal-info-mark" aria-hidden="true">§</div>
            <h2>{{ activeTitle }}</h2>
            <div class="legal-info-tabs" role="tablist">
                <button type="button" :class="{ active: tab === 'privacy' }" @click="tab = 'privacy'">{{ t('Privacy Policy') }}</button>
                <button type="button" :class="{ active: tab === 'terms' }" @click="tab = 'terms'">{{ t('Terms of Use') }}</button>
            </div>
            <article v-for="(paragraph, index) in paragraphs" :key="`${tab}-${index}`" class="legal-info-section">
                <span>{{ String(index + 1).padStart(2, '0') }}</span><p>{{ paragraph }}</p>
            </article>
        </section>
    </SheetModal>
</template>

<style scoped>
.legal-info{width:min(100%,680px);margin:0 auto;padding-bottom:16px}.legal-info-mark{display:grid;width:54px;height:54px;place-items:center;margin:4px 0 12px;border-radius:18px;background:var(--primary-tint);color:var(--primary-strong);font-size:27px;font-weight:900}.legal-info h2{margin:0 0 15px;color:var(--ink);font-size:21px;font-weight:950}.legal-info-tabs{position:sticky;z-index:2;top:-1px;display:grid;grid-template-columns:1fr 1fr;gap:5px;margin:0 -2px 15px;padding:5px;background:color-mix(in srgb,var(--surface-2) 94%,var(--surface));border-radius:13px;box-shadow:0 4px 12px rgba(10,40,38,.06)}.legal-info-tabs button{min-height:42px;border:0;border-radius:10px;background:transparent;color:var(--ink-faint);font:850 11px var(--font)}.legal-info-tabs button.active{background:var(--primary);color:#fff;box-shadow:0 4px 11px color-mix(in srgb,var(--primary) 28%,transparent)}.legal-info-section{display:grid;grid-template-columns:32px minmax(0,1fr);gap:11px;padding:16px 0;border-top:1px solid var(--border)}.legal-info-section>span{display:grid;width:28px;height:28px;place-items:center;border-radius:9px;background:var(--surface-2);color:var(--primary-strong);font:900 9px var(--font)}.legal-info-section p{margin:3px 0 0;color:var(--ink-soft);font-size:12px;font-weight:700;line-height:1.9;white-space:pre-line}
</style>
