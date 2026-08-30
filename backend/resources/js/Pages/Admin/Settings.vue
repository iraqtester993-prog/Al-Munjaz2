<script setup>
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'

const props = defineProps({
    branding: { type: Object, required: true },
    settings: { type: Object, required: true },
    slides: { type: Array, default: () => [] },
    canUpdateSettings: { type: Boolean, default: false },
    canViewContent: { type: Boolean, default: false },
    canViewLoyalty: { type: Boolean, default: false },
    canUpdateLoyalty: { type: Boolean, default: false },
    provinces: { type: Array, default: () => [] },
    canViewProvinces: { type: Boolean, default: false },
    canCreateProvinces: { type: Boolean, default: false },
    canUpdateProvinces: { type: Boolean, default: false },
})

const logoPreview = ref(props.branding.logo_url)
const fileInput = ref(null)
const contentLocale = ref('ar')
const activeTab = ref('general')
const settingsTabs = computed(() => [
    { id: 'general', label: 'General Settings', icon: '⚙' },
    ...(props.canViewProvinces ? [{ id: 'provinces', label: 'Governorates', icon: '⌖' }] : []),
    ...(props.canViewContent ? [{ id: 'slider', label: 'Slider', icon: '▧' }] : []),
    { id: 'timing', label: 'Order Timing Rules', icon: '◷' },
    ...(props.canViewLoyalty ? [{ id: 'points', label: 'Courier Points', icon: '✦' }] : []),
])
const contentLocales = [
    { code: 'ar', label: 'العربية' },
    { code: 'en', label: 'English' },
    { code: 'ku', label: 'کوردی' },
]

function publicContentField(key) {
    return {
        ar: props.settings.public_content?.[key]?.ar || '',
        en: props.settings.public_content?.[key]?.en || '',
        ku: props.settings.public_content?.[key]?.ku || '',
    }
}

const form = useForm({
    brand_name: props.branding.name || '',
    brand_tagline: props.branding.tagline || '',
    logo: null,
    support_phone: props.settings.support_phone || '',
    support_email: props.settings.support_email || '',
    currency: props.settings.currency || 'IQD',
    delivery_fee: Number(props.settings.delivery_fee || 0),
    order_expiry_minutes: Number(props.settings.order_expiry_minutes || 30),
    pickup_eta_minutes: Number(props.settings.pickup_eta_minutes || 30),
    public_content: {
        about_app: publicContentField('about_app'),
        developer_name: publicContentField('developer_name'),
        developer_description: publicContentField('developer_description'),
        privacy_policy: publicContentField('privacy_policy'),
        terms_of_use: publicContentField('terms_of_use'),
    },
})

const hasError = computed(() => Object.keys(form.errors).length > 0)
const sliderStats = computed(() => {
    const now = Date.now()

    return props.slides.reduce((stats, slide) => {
        if (!slide.is_active) {
            stats.hidden += 1

            return stats
        }

        const startsAt = slide.starts_at ? new Date(slide.starts_at).getTime() : null
        const endsAt = slide.ends_at ? new Date(slide.ends_at).getTime() : null

        if ((startsAt && startsAt > now) || (endsAt && endsAt < now)) stats.scheduled += 1
        else stats.published += 1

        return stats
    }, { published: 0, hidden: 0, scheduled: 0 })
})

const pointsForm = useForm({
    points_per_delivery: Number(props.settings.points_per_delivery ?? 10),
})
const provinceModalOpen = ref(false)
const editingProvince = ref(null)
const changingProvinceId = ref(null)
const provinceActionError = ref('')
const blankProvince = () => ({
    name_ar: '',
    name_en: '',
    name_ku: '',
    sort_order: 0,
})
const provinceForm = useForm(blankProvince())
const provinceFormError = computed(() => Object.values(provinceForm.errors)[0] || '')
const activeProvinceCount = computed(() => props.provinces.filter((province) => province.is_active).length)

watch(() => props.branding, (branding) => {
    if (!form.logo) logoPreview.value = branding.logo_url
}, { deep: true })

watch(() => props.settings.points_per_delivery, (points) => {
    if (!pointsForm.isDirty) pointsForm.points_per_delivery = Number(points ?? 10)
})

watch(settingsTabs, (tabs) => {
    if (!tabs.some((tab) => tab.id === activeTab.value)) activeTab.value = tabs[0]?.id || 'general'
}, { immediate: true })

function chooseLogo() {
    if (!props.canUpdateSettings) return
    fileInput.value?.click()
}

function onLogoSelected(event) {
    if (!props.canUpdateSettings) return
    const file = event.target.files?.[0] || null
    form.logo = file

    if (file) logoPreview.value = URL.createObjectURL(file)
}

function contentError(key) {
    return form.errors[`public_content.${key}.${contentLocale.value}`]
}

function submit() {
    if (!props.canUpdateSettings) return
    form.post(route('admin.settings.update'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.logo = null
            if (fileInput.value) fileInput.value.value = ''
        },
    })
}

function saveCourierPoints() {
    if (!props.canUpdateLoyalty) return
    pointsForm.post(route('admin.loyalty.settings'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            activeTab.value = 'points'
        },
    })
}

function provinceName(province) {
    return province?.name_ar || province?.name_en || province?.name_ku || t('Governorate')
}

function provinceAlternateNames(province) {
    const primary = provinceName(province)

    return [province?.name_ar, province?.name_en, province?.name_ku]
        .filter((name, index, names) => name && name !== primary && names.indexOf(name) === index)
        .join(' · ')
}

function openCreateProvince() {
    if (!props.canCreateProvinces) return

    editingProvince.value = null
    provinceActionError.value = ''
    provinceForm.clearErrors()
    Object.assign(provinceForm, blankProvince())
    provinceModalOpen.value = true
}

function openEditProvince(province) {
    if (!props.canUpdateProvinces) return

    editingProvince.value = province
    provinceActionError.value = ''
    provinceForm.clearErrors()
    Object.assign(provinceForm, {
        name_ar: province.name_ar || '',
        name_en: province.name_en || '',
        name_ku: province.name_ku || '',
        sort_order: Number(province.sort_order ?? 0),
    })
    provinceModalOpen.value = true
}

function closeProvinceModal() {
    provinceModalOpen.value = false
    editingProvince.value = null
    provinceForm.clearErrors()
}

function submitProvince() {
    if (editingProvince.value ? !props.canUpdateProvinces : !props.canCreateProvinces) return

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            closeProvinceModal()
            Object.assign(provinceForm, blankProvince())
        },
    }

    if (editingProvince.value) {
        provinceForm.put(route('admin.provinces.update', editingProvince.value.id), options)
        return
    }

    provinceForm.post(route('admin.provinces.store'), options)
}

function toggleProvinceStatus(province) {
    if (!props.canUpdateProvinces) return

    provinceActionError.value = ''
    changingProvinceId.value = province.id

    router.patch(route('admin.provinces.status', province.id), {
        is_active: !province.is_active,
    }, {
        preserveScroll: true,
        onError: (errors) => {
            provinceActionError.value = errors.is_active || errors.province || t('Unable to update governorate status.')
        },
        onFinish: () => {
            changingProvinceId.value = null
        },
    })
}

function slideState(slide) {
    if (!slide.is_active) return 'hidden'

    const now = Date.now()
    const startsAt = slide.starts_at ? new Date(slide.starts_at).getTime() : null
    const endsAt = slide.ends_at ? new Date(slide.ends_at).getTime() : null

    return (startsAt && startsAt > now) || (endsAt && endsAt < now) ? 'scheduled' : 'published'
}

function slideAudience(slide) {
    if (slide.audience === 'merchant') return 'Merchant'
    if (slide.audience === 'courier') return 'Courier'

    return 'All Users'
}
</script>

<template>
    <AdminShell :title="t('Settings')">
        <section class="settings-heading">
            <div>
                <p class="eyebrow">{{ t('Platform Configuration') }}</p>
                <h2>{{ t('Settings') }}</h2>
                <p>{{ t('Manage the brand shown to users, support contacts, and delivery timing rules.') }}</p>
            </div>
            <button v-if="canUpdateSettings && (activeTab === 'general' || activeTab === 'timing')" class="save-button" type="button" :disabled="form.processing" @click="submit">
                <span v-if="form.processing" class="save-spinner" aria-hidden="true" />
                {{ form.processing ? t('Saving...') : t('Save Settings') }}
            </button>
        </section>

        <nav class="settings-tabs" role="tablist" :aria-label="t('Settings')">
            <button
                v-for="tab in settingsTabs"
                :key="tab.id"
                type="button"
                role="tab"
                :aria-selected="activeTab === tab.id"
                :class="{ active: activeTab === tab.id }"
                @click="activeTab = tab.id"
            >
                <span aria-hidden="true">{{ tab.icon }}</span>{{ t(tab.label) }}
            </button>
        </nav>

        <form class="settings-layout" @submit.prevent="submit">
            <fieldset class="settings-fieldset" :disabled="!canUpdateSettings">
            <div v-show="activeTab === 'general'" class="tab-stack">
            <section class="panel settings-panel branding-panel">
                <header class="panel-head">
                    <div class="panel-icon brand-icon">✦</div>
                    <div><h3>{{ t('Branding and Logo') }}</h3><p>{{ t('This identity appears on the dashboard and administrator sign-in screen.') }}</p></div>
                </header>
                <div class="panel-body branding-body">
                    <div class="logo-preview-wrap">
                        <div class="logo-preview"><img :src="logoPreview" :alt="form.brand_name || t('Logo')" /></div>
                        <div class="logo-copy">
                            <b>{{ t('Platform Logo') }}</b>
                            <span>{{ t('PNG, JPG, or WebP up to 3 MB') }}</span>
                            <button class="secondary-button" type="button" @click="chooseLogo">{{ t('Upload Logo') }}</button>
                            <input ref="fileInput" class="file-input" type="file" accept="image/png,image/jpeg,image/webp" @change="onLogoSelected" />
                            <small v-if="form.errors.logo" class="field-error">{{ form.errors.logo }}</small>
                        </div>
                    </div>

                    <div class="field-grid two">
                        <label class="field">
                            <span>{{ t('Platform Name') }}</span>
                            <input v-model="form.brand_name" :placeholder="t('Al-Munjaz Al-Saree')" maxlength="80" required />
                            <small v-if="form.errors.brand_name" class="field-error">{{ form.errors.brand_name }}</small>
                        </label>
                        <label class="field">
                            <span>{{ t('Brand Tagline') }}</span>
                            <input v-model="form.brand_tagline" :placeholder="t('Admin Dashboard')" maxlength="120" />
                            <small v-if="form.errors.brand_tagline" class="field-error">{{ form.errors.brand_tagline }}</small>
                        </label>
                    </div>
                </div>
            </section>

            <section class="panel settings-panel public-content-panel">
                <header class="panel-head">
                    <div class="panel-icon content-icon">Aa</div>
                    <div><h3>{{ t('Public App Content') }}</h3><p>{{ t('Manage the text displayed in About the App, the developer panel, and legal pages.') }}</p></div>
                </header>
                <div class="panel-body public-content-body">
                    <div class="content-language-tabs" role="tablist" :aria-label="t('Language')">
                        <button v-for="language in contentLocales" :key="language.code" type="button" :class="{ active: contentLocale === language.code }" :aria-selected="contentLocale === language.code" @click="contentLocale = language.code">{{ language.label }}</button>
                    </div>
                    <p class="content-help">{{ t('Custom content is optional. Leave a field blank to keep the default in-app text.') }}</p>

                    <div class="public-content-grid" :dir="contentLocale === 'en' ? 'ltr' : 'rtl'">
                        <label class="field">
                            <span>{{ t('Developer Company Name') }}</span>
                            <input v-model="form.public_content.developer_name[contentLocale]" maxlength="160" :placeholder="t('Iraq Techno Information Technology')" />
                            <small v-if="contentError('developer_name')" class="field-error">{{ contentError('developer_name') }}</small>
                        </label>
                        <label class="field wide-field">
                            <span>{{ t('Application Description') }}</span>
                            <textarea v-model="form.public_content.about_app[contentLocale]" rows="3" maxlength="2000" :placeholder="t('About application description')" />
                            <small v-if="contentError('about_app')" class="field-error">{{ contentError('about_app') }}</small>
                        </label>
                        <label class="field wide-field">
                            <span>{{ t('Developer Company Description') }}</span>
                            <textarea v-model="form.public_content.developer_description[contentLocale]" rows="3" maxlength="2000" :placeholder="t('About developer company description')" />
                            <small v-if="contentError('developer_description')" class="field-error">{{ contentError('developer_description') }}</small>
                        </label>
                    </div>
                </div>
            </section>

            <section class="panel settings-panel legal-content-panel">
                <header class="panel-head">
                    <div class="panel-icon legal-icon">§</div>
                    <div><h3>{{ t('Legal Pages') }}</h3><p>{{ t('Write the full text that users will read on the Privacy Policy and Terms of Use pages.') }}</p></div>
                </header>
                <div class="panel-body public-content-body">
                    <div class="legal-actions">
                        <a :href="route('legal.privacy')" target="_blank" rel="noopener">{{ t('Preview Privacy Policy') }} ↗</a>
                        <a :href="route('legal.terms')" target="_blank" rel="noopener">{{ t('Preview Terms of Use') }} ↗</a>
                    </div>
                    <div class="content-language-tabs" role="tablist" :aria-label="t('Language')">
                        <button v-for="language in contentLocales" :key="`legal-${language.code}`" type="button" :class="{ active: contentLocale === language.code }" :aria-selected="contentLocale === language.code" @click="contentLocale = language.code">{{ language.label }}</button>
                    </div>
                    <p class="content-help">{{ t('Use a blank line to separate paragraphs. Empty text keeps the current default policy or terms.') }}</p>
                    <div class="public-content-grid" :dir="contentLocale === 'en' ? 'ltr' : 'rtl'">
                        <label class="field wide-field">
                            <span>{{ t('Privacy Policy Text') }}</span>
                            <textarea v-model="form.public_content.privacy_policy[contentLocale]" class="legal-textarea" rows="9" maxlength="20000" :placeholder="t('Privacy policy introduction')" />
                            <small v-if="contentError('privacy_policy')" class="field-error">{{ contentError('privacy_policy') }}</small>
                        </label>
                        <label class="field wide-field">
                            <span>{{ t('Terms of Use Text') }}</span>
                            <textarea v-model="form.public_content.terms_of_use[contentLocale]" class="legal-textarea" rows="9" maxlength="20000" :placeholder="t('Terms of use introduction')" />
                            <small v-if="contentError('terms_of_use')" class="field-error">{{ contentError('terms_of_use') }}</small>
                        </label>
                    </div>
                </div>
            </section>

            <section class="panel settings-panel">
                <header class="panel-head">
                    <div class="panel-icon support-icon">⌁</div>
                    <div><h3>{{ t('Support and Financial Defaults') }}</h3><p>{{ t('Keep the contact information and default delivery fee consistent across operations.') }}</p></div>
                </header>
                <div class="panel-body">
                    <div class="field-grid three">
                        <label class="field">
                            <span>{{ t('Support Phone') }}</span>
                            <input v-model="form.support_phone" dir="ltr" inputmode="tel" placeholder="07xx xxx xxxx" maxlength="30" />
                            <small v-if="form.errors.support_phone" class="field-error">{{ form.errors.support_phone }}</small>
                        </label>
                        <label class="field">
                            <span>{{ t('Support Email') }}</span>
                            <input v-model="form.support_email" dir="ltr" inputmode="email" placeholder="support@example.com" maxlength="120" />
                            <small v-if="form.errors.support_email" class="field-error">{{ form.errors.support_email }}</small>
                        </label>
                        <label class="field">
                            <span>{{ t('Currency') }}</span>
                            <input v-model="form.currency" dir="ltr" maxlength="10" required />
                            <small v-if="form.errors.currency" class="field-error">{{ form.errors.currency }}</small>
                        </label>
                    </div>
                    <label class="field compact-field">
                        <span>{{ t('Default Delivery Price') }}</span>
                        <div class="suffix-input"><input v-model.number="form.delivery_fee" type="number" min="0" max="1000000" required /><b>{{ t('IQD') }}</b></div>
                        <small v-if="form.errors.delivery_fee" class="field-error">{{ form.errors.delivery_fee }}</small>
                    </label>
                </div>
            </section>
            </div>

            <section v-show="activeTab === 'timing'" class="panel settings-panel">
                <header class="panel-head">
                    <div class="panel-icon timing-icon">◷</div>
                    <div><h3>{{ t('Order Timing Rules') }}</h3><p>{{ t('These rules define how long a new job remains visible and the expected pickup time.') }}</p></div>
                </header>
                <div class="panel-body timing-grid">
                    <article class="timing-card">
                        <div class="timing-card-head"><b>{{ t('New Order Availability') }}</b><span>{{ t('Minutes') }}</span></div>
                        <p>{{ t('How long a new order remains available to couriers before it expires from their queue.') }}</p>
                        <div class="timing-control"><input v-model.number="form.order_expiry_minutes" type="number" min="1" max="1440" required /><span>{{ t('Minutes') }}</span></div>
                        <div class="quick-values"><button v-for="value in [15, 30, 45, 60, 120]" :key="value" type="button" :class="{ active: form.order_expiry_minutes === value }" @click="form.order_expiry_minutes = value">{{ value }}</button></div>
                        <small v-if="form.errors.order_expiry_minutes" class="field-error">{{ form.errors.order_expiry_minutes }}</small>
                    </article>
                    <article class="timing-card">
                        <div class="timing-card-head"><b>{{ t('Expected Merchant Pickup Time') }}</b><span>{{ t('Minutes') }}</span></div>
                        <p>{{ t('The expected time for a courier to reach the merchant after accepting a delivery.') }}</p>
                        <div class="timing-control"><input v-model.number="form.pickup_eta_minutes" type="number" min="5" max="240" required /><span>{{ t('Minutes') }}</span></div>
                        <div class="quick-values"><button v-for="value in [10, 15, 20, 30, 45, 60]" :key="value" type="button" :class="{ active: form.pickup_eta_minutes === value }" @click="form.pickup_eta_minutes = value">{{ value }}</button></div>
                        <small v-if="form.errors.pickup_eta_minutes" class="field-error">{{ form.errors.pickup_eta_minutes }}</small>
                    </article>
                </div>
            </section>
            </fieldset>

            <p v-if="hasError && (activeTab === 'general' || activeTab === 'timing')" class="settings-error">{{ t('Please review the highlighted settings and try again.') }}</p>
            <button v-if="canUpdateSettings && (activeTab === 'general' || activeTab === 'timing')" class="mobile-save save-button" type="submit" :disabled="form.processing">{{ form.processing ? t('Saving...') : t('Save Settings') }}</button>
        </form>

        <section v-if="canViewProvinces && activeTab === 'provinces'" class="settings-tab-panel">
            <section class="panel settings-panel provinces-panel">
                <header class="panel-head">
                    <div class="panel-icon provinces-icon">⌖</div>
                    <div>
                        <h3>{{ t('Governorates') }}</h3>
                        <p>{{ t('Add and maintain the governorates available when creating branches and organizing operations.') }}</p>
                    </div>
                </header>
                <div class="panel-body provinces-body">
                    <div class="provinces-toolbar">
                        <div class="province-summary">
                            <span>{{ t('Active Governorates') }}</span>
                            <b>{{ activeProvinceCount }} / {{ provinces.length }}</b>
                        </div>
                        <button v-if="canCreateProvinces" class="save-button province-create-button" type="button" @click="openCreateProvince">+ {{ t('New Governorate') }}</button>
                    </div>

                    <p v-if="provinceActionError" class="settings-error" role="alert">{{ provinceActionError }}</p>

                    <div v-if="provinces.length" class="provinces-grid">
                        <article v-for="province in provinces" :key="province.id" class="province-card" :class="{ inactive: !province.is_active }">
                            <div class="province-card-head">
                                <div class="province-title">
                                    <span class="province-order">#{{ province.sort_order ?? 0 }}</span>
                                    <div>
                                        <h4>{{ provinceName(province) }}</h4>
                                        <p v-if="provinceAlternateNames(province)">{{ provinceAlternateNames(province) }}</p>
                                    </div>
                                </div>
                                <span class="province-state" :class="{ off: !province.is_active }">{{ province.is_active ? t('Active') : t('Inactive') }}</span>
                            </div>
                            <div class="province-card-meta">
                                <span>{{ t('Branches') }} <b>{{ Number(province.branches_count || 0) }}</b></span>
                                <span>{{ t('Display Order') }} <b>{{ province.sort_order ?? 0 }}</b></span>
                            </div>
                            <div v-if="canUpdateProvinces" class="province-card-actions">
                                <button class="secondary-button province-action" type="button" @click="openEditProvince(province)">{{ t('Edit') }}</button>
                                <button
                                    class="secondary-button province-action"
                                    :class="province.is_active ? 'province-danger' : 'province-activate'"
                                    type="button"
                                    :disabled="changingProvinceId === province.id"
                                    @click="toggleProvinceStatus(province)"
                                >
                                    {{ changingProvinceId === province.id ? t('Saving...') : province.is_active ? t('Deactivate') : t('Activate') }}
                                </button>
                            </div>
                        </article>
                    </div>
                    <div v-else class="provinces-empty">
                        <span aria-hidden="true">⌖</span>
                        <b>{{ t('No governorates yet') }}</b>
                        <p>{{ t('Create a governorate before assigning a branch to it.') }}</p>
                    </div>
                </div>
            </section>
        </section>

        <section v-if="canViewContent && activeTab === 'slider'" class="settings-tab-panel">
            <section class="panel settings-panel slider-panel">
                <header class="panel-head">
                    <div class="panel-icon slider-icon">▧</div>
                    <div>
                        <h3>{{ t('Slider Settings') }}</h3>
                        <p>{{ t('Review the slides that appear on the mobile home screen and open the slider manager to create, schedule, edit, or remove them.') }}</p>
                    </div>
                </header>
                <div class="panel-body slider-body">
                    <div class="slider-stats" aria-label="Slider summary">
                        <article><span>{{ t('Published Slides') }}</span><b>{{ sliderStats.published }}</b></article>
                        <article><span>{{ t('Scheduled Slides') }}</span><b>{{ sliderStats.scheduled }}</b></article>
                        <article><span>{{ t('Hidden Slides') }}</span><b>{{ sliderStats.hidden }}</b></article>
                    </div>

                    <div v-if="slides.length" class="slider-list">
                        <article v-for="slide in slides" :key="slide.id" class="slider-item" :class="slideState(slide)">
                            <div class="slider-thumb" :style="slide.image_url ? { backgroundImage: `linear-gradient(135deg, rgba(5, 37, 33, .78), rgba(5, 37, 33, .14)), url(${slide.image_url})` } : {}">
                                <span>{{ t(slideState(slide) === 'published' ? 'Published' : slideState(slide) === 'scheduled' ? 'Scheduled' : 'Hidden') }}</span>
                            </div>
                            <div class="slider-item-copy">
                                <b>{{ slide.title_ar }}</b>
                                <span>{{ t(slideAudience(slide)) }} · {{ slide.branch_name || t('All Branches') }}</span>
                                <small>#{{ slide.sort_order }} <template v-if="slide.starts_at || slide.ends_at">· {{ slide.starts_at || '…' }} → {{ slide.ends_at || '…' }}</template></small>
                            </div>
                        </article>
                    </div>
                    <div v-else class="slider-empty"><span>▧</span><p>{{ t('No mobile slides have been created yet.') }}</p></div>

                    <div class="tab-actions">
                        <a class="secondary-button tab-link" :href="route('admin.content')">{{ t('Open Slider Manager') }} ↗</a>
                    </div>
                </div>
            </section>
        </section>

        <form v-if="canViewLoyalty && activeTab === 'points'" class="settings-layout settings-tab-panel" @submit.prevent="saveCourierPoints">
            <section class="panel settings-panel points-panel">
                <header class="panel-head">
                    <div class="panel-icon points-icon">✦</div>
                    <div>
                        <h3>{{ t('Courier Points Settings') }}</h3>
                        <p>{{ t('Set the automatic courier reward that is added when an order reaches Delivered. Set zero to stop future rewards without changing the existing ledger.') }}</p>
                    </div>
                </header>
                <div class="panel-body points-body">
                    <div class="points-rule-card">
                        <div>
                            <b>{{ t('Points per Delivered Order') }}</b>
                            <span>{{ t('This value applies to newly delivered orders only. Previous points remain recorded.') }}</span>
                        </div>
                        <label class="points-control">
                            <span>{{ t('Courier Points') }}</span>
                            <input v-model.number="pointsForm.points_per_delivery" type="number" min="0" max="1000000" inputmode="numeric" :disabled="!canUpdateLoyalty" required />
                        </label>
                    </div>
                    <small v-if="pointsForm.errors.points_per_delivery" class="field-error">{{ pointsForm.errors.points_per_delivery }}</small>
                    <div class="tab-actions">
                        <button v-if="canUpdateLoyalty" class="save-button" type="submit" :disabled="pointsForm.processing">{{ pointsForm.processing ? t('Saving...') : t('Save Courier Points') }}</button>
                        <a class="secondary-button tab-link" :href="route('admin.loyalty')">{{ t('Open Courier Points Manager') }} ↗</a>
                    </div>
                </div>
            </section>
        </form>

        <div v-if="provinceModalOpen && (editingProvince ? canUpdateProvinces : canCreateProvinces)" class="province-modal-backdrop" @click.self="closeProvinceModal">
            <form class="province-modal" @submit.prevent="submitProvince">
                <header>
                    <div>
                        <span class="province-modal-kicker">{{ t('Platform Settings') }}</span>
                        <h3>{{ editingProvince ? t('Edit Governorate') : t('New Governorate') }}</h3>
                    </div>
                    <button type="button" :aria-label="t('Close')" @click="closeProvinceModal">×</button>
                </header>

                <p class="province-modal-copy">{{ t('The Arabic name is required. Other names are used when the dashboard language changes.') }}</p>
                <div class="province-form-grid">
                    <label class="field">
                        <span>{{ t('Arabic Governorate Name') }}</span>
                        <input v-model="provinceForm.name_ar" maxlength="80" required :placeholder="t('Baghdad')" />
                        <small v-if="provinceForm.errors.name_ar" class="field-error">{{ provinceForm.errors.name_ar }}</small>
                    </label>
                    <label class="field">
                        <span>{{ t('English Governorate Name') }}</span>
                        <input v-model="provinceForm.name_en" dir="ltr" maxlength="80" placeholder="Baghdad" />
                        <small v-if="provinceForm.errors.name_en" class="field-error">{{ provinceForm.errors.name_en }}</small>
                    </label>
                    <label class="field">
                        <span>{{ t('Kurdish Governorate Name') }}</span>
                        <input v-model="provinceForm.name_ku" maxlength="80" placeholder="بەغدا" />
                        <small v-if="provinceForm.errors.name_ku" class="field-error">{{ provinceForm.errors.name_ku }}</small>
                    </label>
                    <label class="field">
                        <span>{{ t('Display Order') }}</span>
                        <input v-model.number="provinceForm.sort_order" type="number" min="0" max="9999" inputmode="numeric" required />
                        <small v-if="provinceForm.errors.sort_order" class="field-error">{{ provinceForm.errors.sort_order }}</small>
                    </label>
                </div>

                <p v-if="provinceFormError" class="settings-error" role="alert">{{ provinceFormError }}</p>
                <footer>
                    <button class="secondary-button province-cancel" type="button" @click="closeProvinceModal">{{ t('Cancel') }}</button>
                    <button class="save-button province-submit" type="submit" :disabled="provinceForm.processing">{{ provinceForm.processing ? t('Saving...') : editingProvince ? t('Save Governorate') : t('Create Governorate') }}</button>
                </footer>
            </form>
        </div>
    </AdminShell>
</template>

<style scoped>
.settings-heading{display:flex;align-items:end;justify-content:space-between;gap:20px;margin:0 0 21px}.eyebrow{margin:0 0 3px;color:var(--primary);font-size:10px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.settings-heading h2{margin:0;color:var(--ink);font-size:23px;font-weight:900;line-height:1.35}.settings-heading>div>p:last-child{max-width:620px;margin:5px 0 0;color:var(--ink-faint);font-size:12px;font-weight:650;line-height:1.75}.save-button{min-height:42px;display:inline-flex;align-items:center;justify-content:center;gap:8px;flex:none;padding:10px 17px;border:0;border-radius:11px;color:#062033;background:linear-gradient(135deg,var(--primary),#0ea5e9);font:inherit;font-size:12px;font-weight:900;box-shadow:0 10px 20px -14px var(--primary);transition:filter .15s,transform .15s}.save-button:hover:not(:disabled){filter:brightness(1.08)}.save-button:active:not(:disabled){transform:translateY(1px)}.save-button:disabled{cursor:wait;opacity:.65}.save-spinner{width:14px;height:14px;border:2px solid rgba(6,32,51,.25);border-top-color:#062033;border-radius:50%;animation:spin .65s linear infinite}.settings-layout{display:grid;gap:18px}.settings-fieldset{display:contents;min-width:0;margin:0;padding:0;border:0}.settings-panel{margin:0}.panel-head{align-items:flex-start}.panel-head>div:last-child{flex:1}.panel-head h3{margin:0}.panel-head p{margin:3px 0 0;color:var(--ink-faint);font-size:10.5px;font-weight:650;line-height:1.65}.panel-icon{width:35px;height:35px;display:grid;place-items:center;flex:none;border-radius:10px;font-size:17px;font-weight:900}.brand-icon{color:var(--primary-strong);background:var(--primary-tint)}.support-icon{color:var(--st-approved);background:var(--st-approved-tint)}.timing-icon{color:var(--warning);background:var(--warning-tint)}.branding-body{display:grid;gap:18px}.logo-preview-wrap{display:flex;align-items:center;gap:15px;padding:13px;border:1px dashed var(--border);border-radius:14px;background:var(--surface-2)}.logo-preview{width:76px;height:76px;display:grid;place-items:center;flex:none;overflow:hidden;border:1px solid var(--border);border-radius:16px;background:#fff;box-shadow:0 8px 18px -16px rgba(0,0,0,.7)}.logo-preview img{width:100%;height:100%;object-fit:contain;padding:5px}.logo-copy{display:grid;justify-items:start;gap:2px;min-width:0}.logo-copy b{color:var(--ink);font-size:12.5px;font-weight:900}.logo-copy span{color:var(--ink-faint);font-size:10.5px;font-weight:700}.secondary-button{margin-top:7px;padding:7px 11px;border:1px solid var(--border);border-radius:9px;color:var(--primary-strong);background:var(--surface);font:inherit;font-size:10.5px;font-weight:850}.file-input{display:none}.field-grid{display:grid;gap:13px}.field-grid.two{grid-template-columns:repeat(2,minmax(0,1fr))}.field-grid.three{grid-template-columns:repeat(3,minmax(0,1fr))}.field{display:grid;gap:6px;color:var(--ink-soft);font-size:11px;font-weight:850}.field input,.suffix-input{width:100%;min-height:42px;border:1px solid var(--border);border-radius:10px;outline:none;color:var(--ink);background:var(--surface-2);font:inherit;font-size:12px;font-weight:700;transition:border-color .15s,box-shadow .15s}.field input{padding:9px 11px}.field input:focus,.suffix-input:focus-within{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-tint)}.suffix-input{display:flex;align-items:center;gap:8px;padding-inline:11px}.suffix-input input{min-width:0;min-height:0;flex:1;padding:0;border:0;background:transparent;box-shadow:none}.suffix-input b{color:var(--ink-faint);font-size:10.5px}.compact-field{max-width:300px;margin-top:14px}.field-error{color:var(--danger);font-size:10px;font-weight:750;line-height:1.5}.timing-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.timing-card{padding:15px;border:1px solid var(--border);border-radius:14px;background:var(--surface-2)}.timing-card-head{display:flex;align-items:center;justify-content:space-between;gap:8px}.timing-card-head b{color:var(--ink);font-size:12px;font-weight:900}.timing-card-head span{padding:3px 7px;border-radius:20px;color:var(--primary-strong);background:var(--primary-tint);font-size:9px;font-weight:850}.timing-card p{min-height:35px;margin:7px 0 12px;color:var(--ink-faint);font-size:10px;font-weight:650;line-height:1.7}.timing-control{display:flex;align-items:center;gap:8px;max-width:185px;padding:7px 10px;border:1px solid var(--border);border-radius:10px;background:var(--surface)}.timing-control input{width:70px;min-width:0;border:0;outline:0;color:var(--ink);background:transparent;font:inherit;font-size:15px;font-weight:900}.timing-control span{color:var(--ink-faint);font-size:10px;font-weight:800}.quick-values{display:flex;flex-wrap:wrap;gap:5px;margin-top:10px}.quick-values button{min-width:32px;padding:4px 7px;border:1px solid var(--border);border-radius:7px;color:var(--ink-soft);background:var(--surface);font:inherit;font-size:9.5px;font-weight:850}.quick-values button.active{border-color:var(--primary);color:#062033;background:var(--primary)}.settings-error{margin:0;color:var(--danger);font-size:11px;font-weight:800}.mobile-save{display:none;width:100%}@keyframes spin{to{transform:rotate(360deg)}}@media(max-width:760px){.settings-heading{align-items:flex-start;flex-direction:column}.settings-heading>.save-button{display:none}.mobile-save{display:flex}.field-grid.three{grid-template-columns:1fr}.field-grid.two,.timing-grid{grid-template-columns:1fr}.compact-field{max-width:none}.timing-card p{min-height:0}.settings-heading h2{font-size:20px}}@media(max-width:430px){.logo-preview-wrap{align-items:flex-start}.logo-preview{width:64px;height:64px}.panel-head{padding:14px}.panel-body{padding:14px}.timing-card{padding:13px}}
.content-icon{color:var(--st-courier);background:var(--st-courier-tint);font-size:12px}.legal-icon{color:var(--warning);background:var(--warning-tint)}.public-content-body{display:grid;gap:14px}.content-language-tabs{display:flex;align-items:center;gap:5px;width:max-content;max-width:100%;padding:4px;border-radius:11px;background:var(--surface-2)}.content-language-tabs button{min-height:31px;padding:5px 12px;border-radius:8px;color:var(--ink-faint);font:inherit;font-size:10px;font-weight:850}.content-language-tabs button.active{background:var(--surface);color:var(--primary-strong);box-shadow:0 2px 8px rgba(15,27,26,.08)}.content-help{margin:-3px 0 0;color:var(--ink-faint);font-size:10px;font-weight:700;line-height:1.7}.public-content-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.wide-field{grid-column:1/-1}.field textarea{width:100%;min-height:76px;padding:9px 11px;border:1px solid var(--border);border-radius:10px;outline:none;resize:vertical;color:var(--ink);background:var(--surface-2);font:inherit;font-size:12px;font-weight:700;line-height:1.7;transition:border-color .15s,box-shadow .15s}.field textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-tint)}.legal-textarea{min-height:178px}.legal-actions{display:flex;flex-wrap:wrap;gap:8px}.legal-actions a{display:inline-flex;min-height:35px;align-items:center;justify-content:center;padding:7px 11px;border:1px solid var(--border);border-radius:9px;color:var(--primary-strong);background:var(--surface-2);font-size:10px;font-weight:850}.legal-actions a:hover{border-color:var(--primary);background:var(--primary-tint)}@media(max-width:760px){.public-content-grid{grid-template-columns:1fr}.content-language-tabs{width:100%}.content-language-tabs button{flex:1;padding-inline:6px}.legal-actions{display:grid;grid-template-columns:1fr 1fr}.legal-actions a{padding-inline:7px;text-align:center}}@media(max-width:430px){.legal-actions{grid-template-columns:1fr}.content-help{font-size:9.5px}}
.settings-tabs{display:flex;align-items:center;gap:7px;overflow:auto;margin:-4px 0 18px;padding:4px;border:1px solid var(--border);border-radius:14px;background:var(--surface)}.settings-tabs button{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:38px;flex:1;min-width:max-content;padding:8px 13px;border:0;border-radius:10px;color:var(--ink-faint);background:transparent;font:850 10.5px var(--font);white-space:nowrap;cursor:pointer;transition:background .15s,color .15s,box-shadow .15s}.settings-tabs button span{font-size:13px}.settings-tabs button.active{color:var(--primary-strong);background:var(--primary-tint);box-shadow:inset 0 0 0 1px rgba(12,125,116,.14)}.tab-stack{display:grid;gap:18px}.settings-tab-panel{display:grid;gap:18px}.slider-icon{color:#2563eb;background:#dbeafe}.points-icon{color:#b45309;background:#fef3c7}.slider-body,.points-body{display:grid;gap:16px}.slider-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.slider-stats article{display:grid;gap:4px;padding:13px;border:1px solid var(--border);border-radius:12px;background:var(--surface-2)}.slider-stats span{color:var(--ink-faint);font-size:9.5px;font-weight:800}.slider-stats b{color:var(--ink);font-size:20px;font-weight:950}.slider-list{display:grid;gap:9px}.slider-item{display:flex;align-items:center;gap:11px;padding:10px;border:1px solid var(--border);border-radius:12px;background:var(--surface-2)}.slider-item.hidden{opacity:.62}.slider-thumb{width:82px;height:54px;display:grid;place-items:center;flex:none;overflow:hidden;border-radius:9px;background:linear-gradient(135deg,var(--primary-strong),var(--primary));background-position:center;background-size:cover;color:#fff}.slider-thumb span{padding:3px 5px;border-radius:99px;background:rgba(3,20,25,.42);font-size:8px;font-weight:900}.slider-item-copy{display:grid;min-width:0;gap:2px}.slider-item-copy b{overflow:hidden;color:var(--ink);font-size:11.5px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.slider-item-copy span,.slider-item-copy small{overflow:hidden;color:var(--ink-faint);font-size:9.5px;font-weight:750;text-overflow:ellipsis;white-space:nowrap}.slider-empty{display:grid;min-height:145px;place-content:center;justify-items:center;gap:8px;border:1px dashed var(--border);border-radius:13px;color:var(--ink-faint);text-align:center}.slider-empty span{width:38px;height:38px;display:grid;place-items:center;border-radius:10px;color:var(--primary-strong);background:var(--primary-tint);font-size:18px}.slider-empty p{max-width:270px;margin:0;font-size:10.5px;font-weight:750;line-height:1.7}.tab-actions{display:flex;align-items:center;flex-wrap:wrap;gap:9px}.tab-link{display:inline-flex;align-items:center;justify-content:center;min-height:42px;margin:0;text-decoration:none}.points-rule-card{display:flex;align-items:end;justify-content:space-between;gap:18px;padding:15px;border:1px solid var(--border);border-radius:14px;background:var(--surface-2)}.points-rule-card>div{display:grid;gap:4px;max-width:570px}.points-rule-card b{color:var(--ink);font-size:12px;font-weight:900}.points-rule-card>div span{color:var(--ink-faint);font-size:10px;font-weight:700;line-height:1.65}.points-control{display:grid;gap:6px;flex:none;color:var(--ink-soft);font-size:9.5px;font-weight:850}.points-control input{width:142px;min-height:42px;padding:8px 10px;border:1px solid var(--border);border-radius:10px;outline:0;color:var(--ink);background:var(--surface);font:900 15px var(--font)}.points-control input:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-tint)}@media(max-width:760px){.settings-tabs{margin-bottom:14px}.settings-tabs button{flex:none;min-width:auto;padding:8px 11px}.slider-stats{grid-template-columns:1fr}.points-rule-card{align-items:stretch;flex-direction:column}.points-control input{width:100%}.tab-actions{display:grid;grid-template-columns:1fr}.tab-actions .save-button,.tab-actions .tab-link{width:100%;box-sizing:border-box}}@media(max-width:430px){.settings-tabs button{padding:8px 9px;font-size:9.5px}.settings-tabs button span{display:none}.slider-thumb{width:67px;height:50px}}
.provinces-icon{color:#4338ca;background:#e0e7ff}.provinces-body{display:grid;gap:14px}.provinces-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px}.province-summary{display:grid;gap:2px}.province-summary span{color:var(--ink-faint);font-size:10px;font-weight:800}.province-summary b{color:var(--primary-strong);font-size:17px;font-weight:950}.province-create-button{min-height:38px;padding:8px 13px;font-size:10.5px}.provinces-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}.province-card{display:grid;gap:12px;padding:14px;border:1px solid var(--border);border-radius:14px;background:var(--surface-2);transition:opacity .18s ease}.province-card.inactive{opacity:.66}.province-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.province-title{display:flex;min-width:0;align-items:flex-start;gap:9px}.province-order{display:grid;min-width:30px;height:24px;place-items:center;padding-inline:4px;border-radius:7px;color:var(--primary-strong);background:var(--primary-tint);font-size:9px;font-weight:900}.province-title h4{margin:0;color:var(--ink);font-size:12.5px;font-weight:900;line-height:1.45}.province-title p{overflow:hidden;margin:2px 0 0;color:var(--ink-faint);font-size:9.5px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}.province-state{flex:none;padding:4px 7px;border-radius:99px;color:var(--success);background:var(--success-tint);font-size:8.5px;font-weight:900}.province-state.off{color:var(--danger);background:var(--danger-tint)}.province-card-meta{display:flex;align-items:center;gap:14px;padding-top:10px;border-top:1px solid var(--border);color:var(--ink-faint);font-size:9.5px;font-weight:750}.province-card-meta b{margin-inline-start:3px;color:var(--ink);font-size:11px}.province-card-actions{display:flex;gap:7px}.province-action{flex:1;min-height:33px;margin:0;padding:6px 8px;background:var(--surface-1);font-size:9.5px}.province-action:disabled{cursor:wait;opacity:.62}.province-danger{color:var(--danger)}.province-activate{color:var(--success)}.provinces-empty{display:grid;min-height:180px;place-content:center;justify-items:center;gap:6px;border:1px dashed var(--border);border-radius:14px;color:var(--ink-faint);text-align:center}.provinces-empty>span{display:grid;width:40px;height:40px;place-items:center;border-radius:11px;color:#4338ca;background:#e0e7ff;font-size:20px}.provinces-empty b{color:var(--ink);font-size:11px}.provinces-empty p{max-width:260px;margin:0;font-size:10px;font-weight:700;line-height:1.65}.province-modal-backdrop{position:fixed;inset:0;z-index:99;display:grid;place-items:center;padding:20px;overflow:auto;background:#0a121180}.province-modal{width:min(570px,100%);display:grid;gap:14px;padding:21px;border:1px solid var(--border);border-radius:20px;background:var(--surface);box-shadow:0 24px 72px #0004}.province-modal header{display:flex;align-items:flex-start;justify-content:space-between;gap:14px}.province-modal header h3{margin:4px 0 0;color:var(--ink);font-size:18px}.province-modal header button{width:32px;height:32px;border:0;border-radius:9px;color:var(--ink);background:var(--surface-2);font-size:22px;line-height:1;cursor:pointer}.province-modal-kicker{color:var(--primary-strong);font-size:10px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.province-modal-copy{margin:0;color:var(--ink-faint);font-size:10.5px;font-weight:700;line-height:1.7}.province-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.province-modal footer{display:flex;justify-content:flex-end;gap:8px}.province-cancel{margin:0}.province-submit{min-height:36px;padding:8px 13px;font-size:10.5px}@media(max-width:760px){.provinces-grid,.province-form-grid{grid-template-columns:1fr}.provinces-toolbar{align-items:stretch;flex-direction:column}.province-create-button{width:100%}.province-modal{margin:auto;padding:17px}}@media(max-width:430px){.province-card-meta{gap:9px}.province-modal footer{display:grid;grid-template-columns:1fr}.province-modal footer button{width:100%;margin:0}}
.province-action{background:var(--surface)}
</style>
