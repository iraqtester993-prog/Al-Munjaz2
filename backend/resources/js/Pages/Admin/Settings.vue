<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'

const props = defineProps({
    branding: { type: Object, required: true },
    settings: { type: Object, required: true },
})

const logoPreview = ref(props.branding.logo_url)
const fileInput = ref(null)
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
})

const hasError = computed(() => Object.keys(form.errors).length > 0)

watch(() => props.branding, (branding) => {
    if (!form.logo) logoPreview.value = branding.logo_url
}, { deep: true })

function chooseLogo() {
    fileInput.value?.click()
}

function onLogoSelected(event) {
    const file = event.target.files?.[0] || null
    form.logo = file

    if (file) logoPreview.value = URL.createObjectURL(file)
}

function submit() {
    form.post(route('admin.settings.update'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.logo = null
            if (fileInput.value) fileInput.value.value = ''
        },
    })
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
            <button class="save-button" type="button" :disabled="form.processing" @click="submit">
                <span v-if="form.processing" class="save-spinner" aria-hidden="true" />
                {{ form.processing ? t('Saving...') : t('Save Settings') }}
            </button>
        </section>

        <form class="settings-layout" @submit.prevent="submit">
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
                        <span>{{ t('Default Delivery Fee') }}</span>
                        <div class="suffix-input"><input v-model.number="form.delivery_fee" type="number" min="0" max="1000000" required /><b>{{ t('IQD') }}</b></div>
                        <small v-if="form.errors.delivery_fee" class="field-error">{{ form.errors.delivery_fee }}</small>
                    </label>
                </div>
            </section>

            <section class="panel settings-panel">
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

            <p v-if="hasError" class="settings-error">{{ t('Please review the highlighted settings and try again.') }}</p>
            <button class="mobile-save save-button" type="submit" :disabled="form.processing">{{ form.processing ? t('Saving...') : t('Save Settings') }}</button>
        </form>
    </AdminShell>
</template>

<style scoped>
.settings-heading{display:flex;align-items:end;justify-content:space-between;gap:20px;margin:0 0 21px}.eyebrow{margin:0 0 3px;color:var(--primary);font-size:10px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.settings-heading h2{margin:0;color:var(--ink);font-size:23px;font-weight:900;line-height:1.35}.settings-heading>div>p:last-child{max-width:620px;margin:5px 0 0;color:var(--ink-faint);font-size:12px;font-weight:650;line-height:1.75}.save-button{min-height:42px;display:inline-flex;align-items:center;justify-content:center;gap:8px;flex:none;padding:10px 17px;border:0;border-radius:11px;color:#062033;background:linear-gradient(135deg,var(--primary),#0ea5e9);font:inherit;font-size:12px;font-weight:900;box-shadow:0 10px 20px -14px var(--primary);transition:filter .15s,transform .15s}.save-button:hover:not(:disabled){filter:brightness(1.08)}.save-button:active:not(:disabled){transform:translateY(1px)}.save-button:disabled{cursor:wait;opacity:.65}.save-spinner{width:14px;height:14px;border:2px solid rgba(6,32,51,.25);border-top-color:#062033;border-radius:50%;animation:spin .65s linear infinite}.settings-layout{display:grid;gap:18px}.settings-panel{margin:0}.panel-head{align-items:flex-start}.panel-head>div:last-child{flex:1}.panel-head h3{margin:0}.panel-head p{margin:3px 0 0;color:var(--ink-faint);font-size:10.5px;font-weight:650;line-height:1.65}.panel-icon{width:35px;height:35px;display:grid;place-items:center;flex:none;border-radius:10px;font-size:17px;font-weight:900}.brand-icon{color:var(--primary-strong);background:var(--primary-tint)}.support-icon{color:var(--st-approved);background:var(--st-approved-tint)}.timing-icon{color:var(--warning);background:var(--warning-tint)}.branding-body{display:grid;gap:18px}.logo-preview-wrap{display:flex;align-items:center;gap:15px;padding:13px;border:1px dashed var(--border);border-radius:14px;background:var(--surface-2)}.logo-preview{width:76px;height:76px;display:grid;place-items:center;flex:none;overflow:hidden;border:1px solid var(--border);border-radius:16px;background:#fff;box-shadow:0 8px 18px -16px rgba(0,0,0,.7)}.logo-preview img{width:100%;height:100%;object-fit:contain;padding:5px}.logo-copy{display:grid;justify-items:start;gap:2px;min-width:0}.logo-copy b{color:var(--ink);font-size:12.5px;font-weight:900}.logo-copy span{color:var(--ink-faint);font-size:10.5px;font-weight:700}.secondary-button{margin-top:7px;padding:7px 11px;border:1px solid var(--border);border-radius:9px;color:var(--primary-strong);background:var(--surface);font:inherit;font-size:10.5px;font-weight:850}.file-input{display:none}.field-grid{display:grid;gap:13px}.field-grid.two{grid-template-columns:repeat(2,minmax(0,1fr))}.field-grid.three{grid-template-columns:repeat(3,minmax(0,1fr))}.field{display:grid;gap:6px;color:var(--ink-soft);font-size:11px;font-weight:850}.field input,.suffix-input{width:100%;min-height:42px;border:1px solid var(--border);border-radius:10px;outline:none;color:var(--ink);background:var(--surface-2);font:inherit;font-size:12px;font-weight:700;transition:border-color .15s,box-shadow .15s}.field input{padding:9px 11px}.field input:focus,.suffix-input:focus-within{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-tint)}.suffix-input{display:flex;align-items:center;gap:8px;padding-inline:11px}.suffix-input input{min-width:0;min-height:0;flex:1;padding:0;border:0;background:transparent;box-shadow:none}.suffix-input b{color:var(--ink-faint);font-size:10.5px}.compact-field{max-width:300px;margin-top:14px}.field-error{color:var(--danger);font-size:10px;font-weight:750;line-height:1.5}.timing-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.timing-card{padding:15px;border:1px solid var(--border);border-radius:14px;background:var(--surface-2)}.timing-card-head{display:flex;align-items:center;justify-content:space-between;gap:8px}.timing-card-head b{color:var(--ink);font-size:12px;font-weight:900}.timing-card-head span{padding:3px 7px;border-radius:20px;color:var(--primary-strong);background:var(--primary-tint);font-size:9px;font-weight:850}.timing-card p{min-height:35px;margin:7px 0 12px;color:var(--ink-faint);font-size:10px;font-weight:650;line-height:1.7}.timing-control{display:flex;align-items:center;gap:8px;max-width:185px;padding:7px 10px;border:1px solid var(--border);border-radius:10px;background:var(--surface)}.timing-control input{width:70px;min-width:0;border:0;outline:0;color:var(--ink);background:transparent;font:inherit;font-size:15px;font-weight:900}.timing-control span{color:var(--ink-faint);font-size:10px;font-weight:800}.quick-values{display:flex;flex-wrap:wrap;gap:5px;margin-top:10px}.quick-values button{min-width:32px;padding:4px 7px;border:1px solid var(--border);border-radius:7px;color:var(--ink-soft);background:var(--surface);font:inherit;font-size:9.5px;font-weight:850}.quick-values button.active{border-color:var(--primary);color:#062033;background:var(--primary)}.settings-error{margin:0;color:var(--danger);font-size:11px;font-weight:800}.mobile-save{display:none;width:100%}@keyframes spin{to{transform:rotate(360deg)}}@media(max-width:760px){.settings-heading{align-items:flex-start;flex-direction:column}.settings-heading>.save-button{display:none}.mobile-save{display:flex}.field-grid.three{grid-template-columns:1fr}.field-grid.two,.timing-grid{grid-template-columns:1fr}.compact-field{max-width:none}.timing-card p{min-height:0}.settings-heading h2{font-size:20px}}@media(max-width:430px){.logo-preview-wrap{align-items:flex-start}.logo-preview{width:64px;height:64px}.panel-head{padding:14px}.panel-body{padding:14px}.timing-card{padding:13px}}
</style>
