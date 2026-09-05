<script setup>
import { computed, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
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
const developerDescription = computed(() => localizedContent('developer_description', 'شركة عراق تكنو لتكنولوجيا المعلومات شركة عراقية متخصصة في بناء أنظمة قواعد البيانات، وتقديم الحلول البرمجية الجاهزة، وبناء الأنظمة حسب الطلب.'))

watch(() => props.open, (open) => {
    if (open) tab.value = 'app'
})
</script>

<template>
    <SheetModal :open="open" :title="t('About the app')" :subtitle="branding.name" fullscreen preserve-nav show-back @close="emit('close')">
        <div class="developer-tabs" role="tablist" :aria-label="t('About the developer')">
            <button type="button" role="tab" :aria-selected="tab === 'app'" :class="{ active: tab === 'app' }" @click="tab = 'app'">{{ t('About the app') }}</button>
            <button type="button" role="tab" :aria-selected="tab === 'company'" :class="{ active: tab === 'company' }" @click="tab = 'company'">{{ t('Developer company') }}</button>
        </div>

        <section v-if="tab === 'app'" class="developer-panel">
            <div class="developer-app-hero">
                <span><img :src="branding.logo_url" :alt="branding.name"></span>
                <div><em>{{ t('About the app') }}</em><b>{{ branding.name }}</b><small>{{ t('Digital delivery platform') }}</small></div>
            </div>
            <div class="developer-copy-card"><b>{{ t('About the app') }}</b><p>{{ appDescription }}</p></div>
            <div class="developer-note"><span>✦</span><p>{{ t('All platform content and services are managed from the dashboard.') }}</p></div>
        </section>
        <section v-else class="developer-panel developer-company-panel">
            <div class="developer-company-hero"><span><img src="/images/iraq-techno-logo.jpeg" alt="Iraq Techno"></span></div>
            <div class="developer-company-title"><em>👨‍💻 {{ t('Developer company') }}</em><b>{{ developerName }}</b><small>تم التأسيس سنة 2015، وهذه النافذة تعرض معلومات المطور وروابط التواصل الرسمية.</small></div>
            <div class="developer-about"><b>🧾 من نحن</b><p>{{ developerDescription }}</p></div>
            <div class="developer-contact"><b>📬 تواصل معنا</b><a href="mailto:info@iraqtechno.com"><span>✉</span><span><strong dir="ltr">info@iraqtechno.com</strong><small>راسلنا مباشرة</small></span><i>‹</i></a><a href="tel:+9647711132368"><span>☎</span><span><strong dir="ltr">+9647711132368</strong><small>اتصال مباشر</small></span><i>‹</i></a><a href="https://www.facebook.com/499685387089245" target="_blank" rel="noopener"><span>◉</span><span><strong>صفحة عراق تكنو</strong><small>تابعنا على فيسبوك</small></span><i>‹</i></a><a href="https://www.youtube.com/@iraqtechno" target="_blank" rel="noopener"><span>▷</span><span><strong>قناة عراق تكنو</strong><small>شاهد شروحاتنا</small></span><i>‹</i></a><a href="https://www.iraqtechno.com/" target="_blank" rel="noopener"><span>▣</span><span><strong>أعمالنا</strong><small>استعرض مشاريعنا</small></span><i>‹</i></a></div>
        </section>
    </SheetModal>
</template>

<style scoped>
.developer-tabs{position:sticky;top:-1px;z-index:3;display:grid;grid-template-columns:1fr 1fr;gap:5px;margin-bottom:16px;padding:4px;border-radius:13px;background:color-mix(in srgb,var(--surface-2) 94%,var(--surface));box-shadow:0 4px 12px rgba(10,40,38,.06)}.developer-tabs button{min-height:40px;border-radius:10px;color:var(--ink-faint);font:850 10.5px var(--font)}.developer-tabs button.active{background:var(--primary);color:#fff;box-shadow:0 4px 11px color-mix(in srgb,var(--primary) 28%,transparent)}.developer-panel{display:grid;gap:14px;padding-bottom:16px}.developer-app-hero,.developer-company-hero{display:grid;justify-items:center;gap:11px;padding:27px 18px;border:1px solid color-mix(in srgb,var(--primary) 28%,var(--border));border-radius:20px;background:radial-gradient(circle at 50% 10%,color-mix(in srgb,var(--primary) 22%,transparent),transparent 44%),linear-gradient(145deg,var(--surface-2),var(--surface))}.developer-app-hero>span,.developer-company-hero>span{display:grid;width:92px;height:92px;place-items:center;overflow:hidden;border-radius:50%;background:#fff;box-shadow:0 9px 24px rgba(6,32,51,.16)}.developer-app-hero img,.developer-company-hero img{width:100%;height:100%;object-fit:contain}.developer-app-hero>div{display:grid;justify-items:center;gap:3px;text-align:center}.developer-app-hero em,.developer-company-title em{width:max-content;padding:5px 10px;border-radius:999px;background:var(--primary-tint);color:var(--primary-strong);font-size:9px;font-style:normal;font-weight:900}.developer-app-hero b,.developer-company-title b{font-size:18px;font-weight:950}.developer-app-hero small,.developer-company-title small{color:var(--ink-faint);font-size:10px;font-weight:750;line-height:1.7;text-align:center}.developer-copy-card,.developer-about{display:grid;gap:8px;padding:16px;border:1px solid var(--border);border-radius:16px;background:var(--surface)}.developer-copy-card>b,.developer-about>b,.developer-contact>b{color:var(--primary-strong);font-size:13px;font-weight:950}.developer-copy-card p,.developer-about p{margin:0;color:var(--ink-soft);font-size:11px;font-weight:700;line-height:2;white-space:pre-line}.developer-note{display:flex;align-items:flex-start;gap:8px;padding:12px;border-radius:13px;background:var(--primary-tint);color:var(--primary-strong)}.developer-note span{font-size:16px}.developer-note p{margin:0;font-size:10px;font-weight:850;line-height:1.7}.developer-company-panel{padding:0}.developer-company-hero{min-height:145px;border-color:color-mix(in srgb,var(--primary) 32%,var(--border));background:radial-gradient(circle at 70% 0%,#e79d631f,transparent 35%),linear-gradient(135deg,color-mix(in srgb,var(--primary) 32%,#172f47),#102942)}.developer-company-hero>span{width:125px;height:125px}.developer-company-title{display:grid;justify-items:center;gap:8px;padding:0 18px 4px;text-align:center}.developer-company-title em{margin-top:-18px;background:var(--surface);box-shadow:0 4px 14px rgba(9,37,52,.12)}.developer-company-title b{font-size:19px}.developer-company-title small{max-width:390px}.developer-company-panel>.developer-about,.developer-company-panel>.developer-contact{margin-inline:2px}.developer-contact{display:grid;gap:9px;padding:4px 2px}.developer-contact>a{display:grid;grid-template-columns:39px minmax(0,1fr) auto;align-items:center;gap:10px;min-height:47px;padding:10px 12px;border:1px solid var(--border);border-radius:14px;background:var(--surface);color:var(--ink);text-decoration:none;transition:transform .15s,border-color .15s}.developer-contact>a:active{transform:scale(.985)}.developer-contact>a>span:first-child{display:grid;width:39px;height:39px;place-items:center;border-radius:11px;background:color-mix(in srgb,var(--accent) 20%,var(--surface-2));color:var(--accent);font-size:19px;font-weight:900}.developer-contact>a>span:nth-child(2){display:grid;gap:3px;min-width:0}.developer-contact strong{overflow:hidden;color:var(--ink);font-size:11px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.developer-contact small{color:var(--ink-faint);font-size:9.5px;font-weight:750}.developer-contact i{color:var(--ink-faint);font-size:26px;font-style:normal;line-height:1}:global([dir="rtl"]) .developer-contact i{transform:rotate(180deg)}
</style>
