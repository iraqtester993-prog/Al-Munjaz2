<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'

const props = defineProps({
    documentType: { type: String, default: 'privacy' },
    legalContent: { type: Object, default: () => ({}) },
})

const page = usePage()
const locale = computed(() => page.props.locale || 'ar')
const branding = computed(() => page.props.branding || {
    name: t('Al-Munjaz Al-Saree'),
    logo_url: '/logo.png',
})
const developer = computed(() => page.props.developer || {})
const isPrivacy = computed(() => props.documentType === 'privacy')
const title = computed(() => isPrivacy.value ? t('Privacy Policy') : t('Terms of Use'))

function localizedContent(values, fallback = '') {
    const candidates = [locale.value, 'ar', 'en', 'ku']

    for (const candidate of candidates) {
        const value = values?.[candidate]
        if (typeof value === 'string' && value.trim() !== '') return value.trim()
    }

    return fallback
}

const documentKey = computed(() => isPrivacy.value ? 'privacy_policy' : 'terms_of_use')
const customDocument = computed(() => localizedContent(props.legalContent?.[documentKey.value]))
const customParagraphs = computed(() => customDocument.value
    ? customDocument.value.split(/\n\s*\n/).map((paragraph) => paragraph.trim()).filter(Boolean)
    : [])
const developerName = computed(() => localizedContent(developer.value?.developer_name, t('Iraq Techno Information Technology')))
const intro = computed(() => isPrivacy.value
    ? t('Privacy policy introduction')
    : t('Terms of use introduction'))
const sections = computed(() => isPrivacy.value
    ? [
        { title: t('Information we collect'), body: t('Privacy information we collect') },
        { title: t('How we use your information'), body: t('Privacy how we use information') },
        { title: t('Location and notifications'), body: t('Privacy location and notifications') },
        { title: t('Data protection and retention'), body: t('Privacy data protection') },
        { title: t('Your choices'), body: t('Privacy your choices') },
    ]
    : [
        { title: t('Using the service'), body: t('Terms using the service') },
        { title: t('Orders and responsibilities'), body: t('Terms orders and responsibilities') },
        { title: t('Account and platform rules'), body: t('Terms account and platform rules') },
        { title: t('Changes to these terms'), body: t('Terms changes') },
        { title: t('Contact and support'), body: t('Terms contact and support') },
    ])
</script>

<template>
    <main class="legal-page" :dir="locale === 'en' ? 'ltr' : 'rtl'" :lang="locale">
        <header class="legal-header">
            <a class="legal-back" :href="route('login')" :aria-label="t('Back')">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6" /></svg>
            </a>
            <div class="legal-brand">
                <span><img :src="branding.logo_url" :alt="branding.name"></span>
                <b>{{ branding.name }}</b>
            </div>
            <span class="legal-header-space" aria-hidden="true"></span>
        </header>

        <section class="legal-content">
            <div class="legal-kicker">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 20 6v5c0 5-3.2 8.3-8 10-4.8-1.7-8-5-8-10V6l8-3Z" /><path d="M9 12h6" /></svg>
                {{ isPrivacy ? t('Privacy') : t('Terms') }}
            </div>
            <h1>{{ title }}</h1>
            <p class="legal-intro">{{ intro }}</p>

            <template v-if="customParagraphs.length">
                <article v-for="(paragraph, index) in customParagraphs" :key="`${documentType}-${index}`" class="legal-section legal-custom-section">
                    <span>{{ String(index + 1).padStart(2, '0') }}</span>
                    <p>{{ paragraph }}</p>
                </article>
            </template>
            <template v-else>
                <article v-for="(section, index) in sections" :key="section.title" class="legal-section">
                    <span>{{ String(index + 1).padStart(2, '0') }}</span>
                    <div>
                        <h2>{{ section.title }}</h2>
                        <p>{{ section.body }}</p>
                    </div>
                </article>
            </template>
        </section>

        <footer class="legal-footer">
            <span>{{ t('Developed by') }}</span>
            <b>{{ developerName }}</b>
        </footer>
    </main>
</template>

<style scoped>
.legal-page{min-height:100dvh;background:var(--page);color:var(--ink);padding-bottom:calc(22px + env(safe-area-inset-bottom,0px))}.legal-header{display:grid;grid-template-columns:38px 1fr 38px;align-items:center;min-height:72px;padding:14px 18px;border-bottom:1px solid var(--border);background:var(--surface)}.legal-back{display:grid;width:36px;height:36px;place-items:center;border:1px solid var(--border);border-radius:11px;color:var(--primary-strong);background:var(--surface-2)}.legal-brand{display:flex;align-items:center;justify-content:center;gap:8px;min-width:0}.legal-brand>span{display:grid;width:31px;height:31px;place-items:center;overflow:hidden;border-radius:9px;background:#fff;box-shadow:0 3px 10px rgba(15,27,26,.1)}.legal-brand img{width:100%;height:100%;object-fit:contain}.legal-brand b{overflow:hidden;font-size:12px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.legal-content{width:min(100%,620px);margin:0 auto;padding:26px 18px 18px}.legal-kicker{display:inline-flex;align-items:center;gap:7px;padding:7px 10px;border-radius:999px;background:var(--primary-tint);color:var(--primary-strong);font-size:10px;font-weight:900}.legal-content h1{margin:13px 0 6px;font-size:22px;font-weight:950;letter-spacing:-.3px}.legal-intro{margin:0 0 23px;color:var(--ink-soft);font-size:12px;font-weight:700;line-height:1.8}.legal-section{display:grid;grid-template-columns:32px minmax(0,1fr);gap:11px;padding:15px 0;border-top:1px solid var(--border)}.legal-section>span{display:grid;width:28px;height:28px;place-items:center;border-radius:9px;background:var(--surface-2);color:var(--primary-strong);font:900 9px var(--font)}.legal-section h2{margin:1px 0 4px;font-size:13px;font-weight:900}.legal-section p{margin:0;color:var(--ink-soft);font-size:11px;font-weight:650;line-height:1.85}.legal-custom-section>p{padding-top:3px;white-space:pre-line}.legal-footer{display:grid;gap:2px;margin:12px 18px 0;padding:16px;border:1px solid var(--border);border-radius:14px;background:var(--surface);text-align:center}.legal-footer span{color:var(--ink-faint);font-size:9.5px;font-weight:750}.legal-footer b{color:var(--primary-strong);font-size:11px;font-weight:900}
</style>
