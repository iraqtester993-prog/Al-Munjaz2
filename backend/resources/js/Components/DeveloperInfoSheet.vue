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

watch(() => props.open, (open) => {
    if (open) tab.value = 'app'
})
</script>

<template>
    <SheetModal :open="open" :title="t('About the developer')" :subtitle="developerName" fullscreen @close="emit('close')">
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
        <section v-else class="developer-panel developer-company-panel">
            <div class="developer-company-mark">👨‍💻</div>
            <div class="developer-company-copy"><b>شركة عراق تكنو - لتكنولوجيا المعلومات</b><small>تم التأسيس سنة 2015، وهذه النافذة تعرض معلومات المطور وروابط التواصل الرسمية.</small></div>
            <div class="developer-about"><b>🧾 من نحن</b><p>شركة عراق تكنو لتكنولوجيا المعلومات شركة عراقية متخصصة في بناء أنظمة قواعد البيانات، وتقديم الحلول البرمجية الجاهزة، وبناء الأنظمة حسب الطلب. أسست الشركة من قبل عدد من المبرمجين ذوي الخبرة والاختصاص والحاصلين على تدريب في مجالات متعددة من تكنولوجيا المعلومات مثل تصميم وإدارة قواعد البيانات، وإدارة الخوادم، وتطوير برامجيات الويب باستخدام تقنيات حديثة، وبناء تطبيقات الهواتف. يتركز عمل الشركة على تطوير وتحسين أنظمة معالجة المعلومات ومساعدة الزبائن في تبني التكنولوجيا الحديثة، وهدفها توفير أنظمة مرنة وسهلة الاستخدام.</p></div>
            <div class="developer-contact"><b>📬 تواصل معنا</b><a href="mailto:info@iraqtechno.com"><span>📧</span><span><strong>info@iraqtechno.com</strong><small>راسلنا مباشرة</small></span></a><a href="tel:+9647711132368"><span>📞</span><span><strong dir="ltr">+9647711132368</strong><small>اتصال مباشر</small></span></a><a href="https://www.facebook.com/499685387089245" target="_blank" rel="noopener"><span>📘</span><span><strong>صفحة عراق تكنو</strong><small>تابعنا على فيسبوك</small></span></a><a href="https://www.youtube.com/@iraqtechno" target="_blank" rel="noopener"><span>▶️</span><span><strong>قناة عراق تكنو</strong><small>شاهد شروحاتنا</small></span></a><a href="https://www.iraqtechno.com/" target="_blank" rel="noopener"><span>🧩</span><span><strong>أعمالنا</strong><small>استعرض مشاريعنا</small></span></a></div>
        </section>
    </SheetModal>
</template>

<style scoped>
.developer-tabs{display:grid;grid-template-columns:1fr 1fr;gap:5px;margin-bottom:14px;padding:4px;border-radius:12px;background:var(--surface-2)}.developer-tabs button{min-height:38px;border-radius:9px;color:var(--ink-faint);font:800 10.5px var(--font)}.developer-tabs button.active{background:var(--surface);color:var(--primary-strong);box-shadow:0 2px 8px rgba(15,27,26,.08)}.developer-panel{display:grid;gap:10px;padding:15px;border:1px solid var(--border);border-radius:14px;background:var(--surface-2)}.developer-brand{display:flex;align-items:center;gap:10px}.developer-brand>span{display:grid;width:43px;height:43px;place-items:center;overflow:hidden;border-radius:12px;background:#fff;box-shadow:0 3px 10px rgba(15,27,26,.1)}.developer-brand img{width:100%;height:100%;object-fit:contain}.developer-brand b,.developer-brand small,.developer-company-copy b,.developer-company-copy small{display:block}.developer-brand b,.developer-company-copy b{font-size:12px;font-weight:900}.developer-brand small,.developer-company-copy small{margin-top:2px;color:var(--ink-faint);font-size:9.5px;font-weight:750}.developer-panel>p{margin:0;color:var(--ink-soft);font-size:10.5px;font-weight:700;line-height:1.8}.developer-company-panel{background:linear-gradient(150deg,var(--primary-tint),var(--surface-2))}.developer-company-mark{display:grid;width:48px;height:48px;place-items:center;border-radius:15px;background:var(--primary);font-size:22px}.developer-company-copy{margin-top:-58px;margin-inline-start:58px;min-height:48px;display:flex;justify-content:center;flex-direction:column}.developer-company-copy small{line-height:1.55}.developer-about{display:grid;gap:6px;margin-top:9px;padding-top:13px;border-top:1px solid var(--border)}.developer-about>b,.developer-contact>b{color:var(--primary-strong);font-size:12px;font-weight:950}.developer-about p{margin:0;color:var(--ink-soft);font-size:11px;font-weight:700;line-height:1.9}.developer-contact{display:grid;gap:7px;margin-top:5px}.developer-contact>a{display:flex;align-items:center;gap:10px;padding:10px;border:1px solid var(--border);border-radius:11px;background:var(--surface);color:var(--ink);text-decoration:none}.developer-contact>a>span:first-child{display:grid;width:31px;height:31px;place-items:center;border-radius:9px;background:var(--primary-tint);font-size:15px}.developer-contact>a>span:last-child{display:grid;gap:2px;min-width:0}.developer-contact strong{color:var(--ink);font-size:11px;font-weight:900}.developer-contact small{color:var(--ink-faint);font-size:9.5px;font-weight:750}
</style>
