<script setup>
import { computed, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import SheetModal from './SheetModal.vue'

const props = defineProps({
    open: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])
const page = usePage()
const tab = ref('app')
const locale = computed(() => page.props.locale || 'ar')
const branding = computed(() => page.props.branding || {
    name: t('Al-Munjaz Al-Saree'),
    logo_url: '/logo.png',
})
const developer = computed(() => page.props.developer || {})

function localizedContent(key, fallback) {
    const values = developer.value?.[key] || {}
    const candidates = [locale.value, 'ar', 'en', 'ku']

    for (const candidate of candidates) {
        const value = values[candidate]
        if (typeof value === 'string' && value.trim() !== '') return value.trim()
    }

    return fallback
}

const appDescription = computed(() => localizedContent('about_app', t('About application description')))
const developerName = computed(() => localizedContent('developer_name', t('Iraq Techno Information Technology')))
const developerDescription = computed(() => localizedContent('developer_description', t('About developer company description')))

watch(() => props.open, (open) => {
    if (open) tab.value = 'app'
})
</script>

<template>
    <SheetModal :open="open" :title="t('About the developer')" :subtitle="developerName" @close="emit('close')">
        <div class="developer-tabs" role="tablist" :aria-label="t('About the developer')">
            <button type="button" role="tab" :aria-selected="tab === 'app'" :class="{ active: tab === 'app' }" @click="tab = 'app'">{{ t('About the app') }}</button>
            <button type="button" role="tab" :aria-selected="tab === 'company'" :class="{ active: tab === 'company' }" @click="tab = 'company'">{{ t('Developer company') }}</button>
        </div>

        <section v-if="tab === 'app'" class="developer-panel">
            <div class="developer-brand">
                <span><img :src="branding.logo_url" :alt="branding.name"></span>
                <div><b>{{ branding.name }}</b><small>{{ t('Digital delivery platform') }}</small></div>
            </div>
            <p>{{ appDescription }}</p>
        </section>
        <section v-else class="developer-panel">
            <div class="developer-company-mark">IT</div>
            <div class="developer-company-copy"><b>{{ developerName }}</b><small>{{ t('Developer company') }}</small></div>
            <p>{{ developerDescription }}</p>
        </section>

        <div class="developer-legal-links">
            <a :href="route('legal.privacy')">{{ t('Privacy Policy') }}</a>
            <a :href="route('legal.terms')">{{ t('Terms of Use') }}</a>
        </div>
    </SheetModal>
</template>

<style scoped>
.developer-tabs{display:grid;grid-template-columns:1fr 1fr;gap:5px;margin-bottom:14px;padding:4px;border-radius:12px;background:var(--surface-2)}.developer-tabs button{min-height:38px;border-radius:9px;color:var(--ink-faint);font:800 10.5px var(--font)}.developer-tabs button.active{background:var(--surface);color:var(--primary-strong);box-shadow:0 2px 8px rgba(15,27,26,.08)}.developer-panel{display:grid;gap:10px;padding:15px;border:1px solid var(--border);border-radius:14px;background:var(--surface-2)}.developer-brand{display:flex;align-items:center;gap:10px}.developer-brand>span{display:grid;width:43px;height:43px;place-items:center;overflow:hidden;border-radius:12px;background:#fff;box-shadow:0 3px 10px rgba(15,27,26,.1)}.developer-brand img{width:100%;height:100%;object-fit:contain}.developer-brand b,.developer-brand small,.developer-company-copy b,.developer-company-copy small{display:block}.developer-brand b,.developer-company-copy b{font-size:12px;font-weight:900}.developer-brand small,.developer-company-copy small{margin-top:2px;color:var(--ink-faint);font-size:9.5px;font-weight:750}.developer-panel>p{margin:0;color:var(--ink-soft);font-size:10.5px;font-weight:700;line-height:1.8}.developer-company-mark{display:grid;width:43px;height:43px;place-items:center;border-radius:12px;background:var(--primary);color:#fff;font:900 14px Arial,sans-serif;letter-spacing:-1px}.developer-company-copy{margin-top:-53px;margin-inline-start:53px;min-height:43px;display:flex;justify-content:center;flex-direction:column}.developer-legal-links{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:13px}.developer-legal-links a{display:grid;min-height:39px;place-items:center;border:1px solid var(--border);border-radius:10px;background:var(--surface);color:var(--primary-strong);font-size:10px;font-weight:850;text-align:center}
</style>
