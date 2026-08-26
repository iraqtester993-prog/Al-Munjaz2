<script setup>
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'

const props = defineProps({
    campaigns: { type: Array, default: () => [] },
    deliveries: { type: Array, default: () => [] },
    recipients: { type: Array, default: () => [] },
    counts: { type: Object, default: () => ({}) },
})

const showTranslations = ref(false)
const form = useForm({
    audience: 'all',
    target_user_id: '',
    type: 'announcement',
    title_ar: '',
    title_en: '',
    title_ku: '',
    body_ar: '',
    body_en: '',
    body_ku: '',
})

const recipientEstimate = computed(() => {
    if (form.audience === 'merchants') return props.recipients.filter((recipient) => recipient.role === 'merchant').length
    if (form.audience === 'couriers') return props.recipients.filter((recipient) => recipient.role === 'courier').length
    if (form.audience === 'user') return form.target_user_id ? 1 : 0
    return props.recipients.length
})

const audienceOptions = computed(() => [
    { value: 'all', label: t('All active merchants and couriers') },
    { value: 'merchants', label: t('All Merchants') },
    { value: 'couriers', label: t('All Couriers') },
    { value: 'user', label: t('Specific Account') },
])

const typeOptions = computed(() => [
    { value: 'announcement', label: t('Announcement') },
    { value: 'system', label: t('System Update') },
    { value: 'account', label: t('Account Update') },
    { value: 'finance', label: t('Finance') },
    { value: 'order', label: t('Order') },
])

function setAudience(value) {
    form.audience = value
    if (value !== 'user') form.target_user_id = ''
}

function recipientLabel(recipient) {
    return `${recipient.name} · ${t(recipient.role === 'merchant' ? 'Merchant' : 'Courier')} · ${recipient.phone || '—'}`
}

function audienceLabel(campaign) {
    const labels = {
        all: t('All Users'),
        merchants: t('Merchants'),
        couriers: t('Couriers'),
        user: campaign.target_user?.name || t('One Account'),
    }

    return labels[campaign.audience] || campaign.audience
}

function typeLabel(type) {
    return typeOptions.value.find((option) => option.value === type)?.label || type
}

function typeClass(type) {
    return `type-${type}`
}

function submit() {
    form.post(route('admin.notifications.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
            form.clearErrors()
            showTranslations.value = false
        },
    })
}
</script>

<template>
    <AdminShell :title="t('Notifications')">
        <section class="notification-heading">
            <div>
                <p class="eyebrow">{{ t('Communication Center') }}</p>
                <h2>{{ t('Notifications') }}</h2>
                <p>{{ t('Create general or targeted in-app notifications and keep a clear delivery record for each campaign.') }}</p>
            </div>
            <div class="notification-totals" aria-label="Notification totals">
                <span><b>{{ counts.campaigns || 0 }}</b>{{ t('Campaigns') }}</span>
                <span><b>{{ counts.deliveries || 0 }}</b>{{ t('Deliveries') }}</span>
                <span class="unread-total"><b>{{ counts.unread || 0 }}</b>{{ t('Unread') }}</span>
            </div>
        </section>

        <div class="notification-grid">
            <form class="panel composer-panel" @submit.prevent="submit">
                <header class="panel-head composer-head">
                    <div class="compose-icon">✦</div>
                    <div><h3>{{ t('Compose Notification') }}</h3><p>{{ t('Send a clear in-app message to all active users, a role, or one account.') }}</p></div>
                </header>

                <div class="panel-body composer-body">
                    <div class="form-row">
                        <label class="field">
                            <span>{{ t('Audience') }}</span>
                            <select :value="form.audience" @change="setAudience($event.target.value)">
                                <option v-for="option in audienceOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                            <small v-if="form.errors.audience" class="field-error">{{ form.errors.audience }}</small>
                        </label>
                        <label class="field">
                            <span>{{ t('Notification Type') }}</span>
                            <select v-model="form.type">
                                <option v-for="option in typeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                            <small v-if="form.errors.type" class="field-error">{{ form.errors.type }}</small>
                        </label>
                    </div>

                    <label v-if="form.audience === 'user'" class="field">
                        <span>{{ t('Recipient') }}</span>
                        <select v-model="form.target_user_id" required>
                            <option disabled value="">{{ t('Choose active user') }}</option>
                            <option v-for="recipient in recipients" :key="recipient.id" :value="recipient.id">{{ recipientLabel(recipient) }}</option>
                        </select>
                        <small v-if="form.errors.target_user_id" class="field-error">{{ form.errors.target_user_id }}</small>
                    </label>

                    <div class="recipient-strip">
                        <span class="recipient-dot" />
                        <span>{{ t('Active recipients') }}</span>
                        <b>{{ recipientEstimate }}</b>
                    </div>

                    <fieldset class="language-block">
                        <legend>{{ t('Arabic Content') }}</legend>
                        <label class="field">
                            <span>{{ t('Title') }}</span>
                            <input v-model="form.title_ar" :placeholder="t('Title')" maxlength="160" required />
                            <small v-if="form.errors.title_ar" class="field-error">{{ form.errors.title_ar }}</small>
                        </label>
                        <label class="field">
                            <span>{{ t('Message') }}</span>
                            <textarea v-model="form.body_ar" :placeholder="t('Message')" rows="4" maxlength="1000" />
                            <small v-if="form.errors.body_ar" class="field-error">{{ form.errors.body_ar }}</small>
                        </label>
                    </fieldset>

                    <button class="translations-toggle" type="button" @click="showTranslations = !showTranslations">
                        <span>{{ showTranslations ? '−' : '+' }}</span>{{ t('Add English and Kurdish content') }}
                    </button>

                    <div v-if="showTranslations" class="translation-grid">
                        <fieldset class="language-block compact" dir="ltr">
                            <legend>{{ t('English Content') }}</legend>
                            <label class="field"><span>{{ t('Title') }}</span><input v-model="form.title_en" :placeholder="t('Title')" maxlength="160" /><small v-if="form.errors.title_en" class="field-error">{{ form.errors.title_en }}</small></label>
                            <label class="field"><span>{{ t('Message') }}</span><textarea v-model="form.body_en" :placeholder="t('Message')" rows="3" maxlength="1000" /><small v-if="form.errors.body_en" class="field-error">{{ form.errors.body_en }}</small></label>
                        </fieldset>
                        <fieldset class="language-block compact">
                            <legend>{{ t('Kurdish Content') }}</legend>
                            <label class="field"><span>{{ t('Title') }}</span><input v-model="form.title_ku" :placeholder="t('Title')" maxlength="160" /><small v-if="form.errors.title_ku" class="field-error">{{ form.errors.title_ku }}</small></label>
                            <label class="field"><span>{{ t('Message') }}</span><textarea v-model="form.body_ku" :placeholder="t('Message')" rows="3" maxlength="1000" /><small v-if="form.errors.body_ku" class="field-error">{{ form.errors.body_ku }}</small></label>
                        </fieldset>
                    </div>

                    <p v-if="form.errors.audience && !form.errors.target_user_id" class="field-error form-error">{{ form.errors.audience }}</p>
                    <button class="send-button" type="submit" :disabled="form.processing || !recipientEstimate">
                        <span v-if="form.processing" class="send-spinner" aria-hidden="true" />
                        {{ form.processing ? t('Sending...') : t('Send Notification') }}
                    </button>
                    <p class="dispatch-note">{{ t('Every campaign creates an in-app delivery record for its selected recipients. Device push is handled separately when permission is available.') }}</p>
                </div>
            </form>

            <section class="history-stack">
                <article class="panel history-panel">
                    <header class="panel-head"><div><h3>{{ t('Campaign History') }}</h3><p>{{ t('A campaign groups the delivery records created by one dashboard send.') }}</p></div></header>
                    <div class="campaign-list">
                        <article v-for="campaign in campaigns" :key="campaign.id" class="campaign-row">
                            <div class="campaign-main">
                                <div class="campaign-meta"><span :class="['type-chip', typeClass(campaign.type)]">{{ typeLabel(campaign.type) }}</span><span>{{ audienceLabel(campaign) }}</span></div>
                                <b>{{ campaign.title }}</b>
                                <p v-if="campaign.body">{{ campaign.body }}</p>
                                <small>{{ campaign.sent_at }} <span v-if="campaign.created_by">· {{ t('Sent by') }} {{ campaign.created_by }}</span></small>
                            </div>
                            <div class="campaign-counts">
                                <span><b>{{ campaign.delivery_count || campaign.recipient_count }}</b>{{ t('Delivered') }}</span>
                                <span><b>{{ campaign.read_count }}</b>{{ t('Read') }}</span>
                            </div>
                        </article>
                        <div v-if="!campaigns.length" class="empty-state">{{ t('No notification campaigns have been sent yet.') }}</div>
                    </div>
                </article>

                <article class="panel delivery-panel">
                    <header class="panel-head"><div><h3>{{ t('In-app Delivery Records') }}</h3><p>{{ t('The latest individual inbox records created from dashboard campaigns.') }}</p></div></header>
                    <div class="delivery-list">
                        <div v-for="delivery in deliveries" :key="delivery.id" class="delivery-row">
                            <span class="delivery-state" :class="{ read: delivery.read }" :title="delivery.read ? t('Read') : t('Not read')" />
                            <div><b>{{ delivery.recipient?.name || t('Unknown User') }}</b><span>{{ delivery.title }} · {{ delivery.created_at }}</span></div>
                            <small>{{ delivery.recipient?.role === 'merchant' ? t('Merchant') : t('Courier') }}</small>
                        </div>
                        <div v-if="!deliveries.length" class="empty-state">{{ t('No delivery records yet.') }}</div>
                    </div>
                </article>
            </section>
        </div>
    </AdminShell>
</template>

<style scoped>
.notification-heading{display:flex;align-items:end;justify-content:space-between;gap:20px;margin-bottom:21px}.eyebrow{margin:0 0 3px;color:var(--primary);font-size:10px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.notification-heading h2{margin:0;color:var(--ink);font-size:23px;font-weight:900;line-height:1.35}.notification-heading>div>p:last-child{max-width:650px;margin:5px 0 0;color:var(--ink-faint);font-size:12px;font-weight:650;line-height:1.75}.notification-totals{display:flex;align-items:center;gap:8px;flex:none}.notification-totals span{display:grid;gap:0;min-width:70px;padding:7px 10px;border:1px solid var(--border);border-radius:10px;color:var(--ink-faint);background:var(--surface);font-size:8.5px;font-weight:800;text-align:center}.notification-totals b{color:var(--ink);font-size:14px;font-weight:900;line-height:1.35}.notification-totals .unread-total b{color:var(--accent)}.notification-grid{display:grid;grid-template-columns:minmax(330px,.88fr) minmax(0,1.12fr);gap:18px;align-items:start}.composer-panel,.history-panel,.delivery-panel{margin:0}.composer-head{align-items:flex-start}.composer-head>div:last-child{flex:1}.panel-head h3{margin:0}.panel-head p{margin:3px 0 0;color:var(--ink-faint);font-size:10.5px;font-weight:650;line-height:1.65}.compose-icon{width:36px;height:36px;display:grid;place-items:center;flex:none;border-radius:11px;color:var(--primary-strong);background:var(--primary-tint);font-size:17px;font-weight:900}.composer-body{display:grid;gap:14px}.form-row{display:grid;grid-template-columns:1fr 1fr;gap:11px}.field{display:grid;gap:6px;color:var(--ink-soft);font-size:10.5px;font-weight:850}.field input,.field textarea,.field select{width:100%;border:1px solid var(--border);border-radius:10px;outline:none;color:var(--ink);background:var(--surface-2);font:inherit;font-size:11.5px;font-weight:700;transition:border-color .15s,box-shadow .15s}.field input,.field select{min-height:41px;padding:9px 10px}.field textarea{min-height:80px;padding:9px 10px;line-height:1.65;resize:vertical}.field input:focus,.field textarea:focus,.field select:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-tint)}.field-error{color:var(--danger);font-size:9.5px;font-weight:780;line-height:1.5}.recipient-strip{display:flex;align-items:center;gap:7px;padding:8px 10px;border:1px solid var(--border);border-radius:10px;color:var(--ink-soft);background:var(--surface-2);font-size:10px;font-weight:800}.recipient-strip b{margin-inline-start:auto;color:var(--ink);font-size:13px;font-weight:900}.recipient-dot{width:7px;height:7px;border-radius:50%;background:var(--success);box-shadow:0 0 0 4px var(--success-tint)}.language-block{min-width:0;margin:0;padding:12px;border:1px solid var(--border);border-radius:12px}.language-block legend{padding:0 5px;color:var(--primary-strong);font-size:10px;font-weight:900}.language-block .field+.field{margin-top:10px}.translations-toggle{display:inline-flex;align-items:center;justify-content:flex-start;gap:7px;width:max-content;padding:5px 0;border:0;color:var(--primary-strong);font:inherit;font-size:10.5px;font-weight:850}.translations-toggle span{width:17px;height:17px;display:grid;place-items:center;border-radius:5px;color:#062033;background:var(--primary);font-size:14px;line-height:1}.translation-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.language-block.compact{padding:10px}.send-button{min-height:44px;display:inline-flex;align-items:center;justify-content:center;gap:8px;width:100%;border:0;border-radius:11px;color:#062033;background:linear-gradient(135deg,var(--primary),#0ea5e9);font:inherit;font-size:12px;font-weight:900;transition:filter .15s,opacity .15s}.send-button:hover:not(:disabled){filter:brightness(1.08)}.send-button:disabled{cursor:not-allowed;opacity:.6}.send-spinner{width:14px;height:14px;border:2px solid rgba(6,32,51,.25);border-top-color:#062033;border-radius:50%;animation:spin .65s linear infinite}.dispatch-note{margin:-5px 0 0;color:var(--ink-faint);font-size:9.5px;font-weight:650;line-height:1.65}.form-error{margin-top:-4px}.history-stack{display:grid;gap:18px}.campaign-list{display:grid}.campaign-row{display:flex;gap:14px;padding:14px 17px;border-bottom:1px solid var(--border)}.campaign-row:last-child{border-bottom:0}.campaign-main{min-width:0;flex:1}.campaign-meta{display:flex;align-items:center;gap:6px;margin-bottom:5px;color:var(--ink-faint);font-size:9.5px;font-weight:800}.type-chip{display:inline-flex;padding:3px 7px;border-radius:20px;font-size:8.5px;font-weight:900}.type-announcement{color:var(--primary-strong);background:var(--primary-tint)}.type-system{color:var(--st-approved);background:var(--st-approved-tint)}.type-account{color:var(--warning);background:var(--warning-tint)}.type-finance{color:var(--success);background:var(--success-tint)}.type-order{color:var(--st-courier);background:var(--st-courier-tint)}.campaign-main>b{display:block;overflow:hidden;color:var(--ink);font-size:12px;font-weight:900;line-height:1.55;text-overflow:ellipsis;white-space:nowrap}.campaign-main p{display:-webkit-box;overflow:hidden;margin:2px 0 0;color:var(--ink-soft);font-size:10.5px;font-weight:650;line-height:1.6;-webkit-box-orient:vertical;-webkit-line-clamp:2}.campaign-main small{display:block;margin-top:5px;color:var(--ink-faint);font-size:9px;font-weight:700}.campaign-counts{display:grid;align-content:start;gap:5px;min-width:56px}.campaign-counts span{display:grid;padding:4px 6px;border-radius:7px;color:var(--ink-faint);background:var(--surface-2);font-size:8px;font-weight:800;text-align:center}.campaign-counts b{color:var(--ink);font-size:11px;font-weight:900}.delivery-list{display:grid}.delivery-row{display:flex;align-items:center;gap:9px;padding:10px 17px;border-bottom:1px solid var(--border)}.delivery-row:last-child{border-bottom:0}.delivery-state{width:8px;height:8px;flex:none;border-radius:50%;background:var(--accent);box-shadow:0 0 0 3px var(--accent-tint)}.delivery-state.read{background:var(--success);box-shadow:0 0 0 3px var(--success-tint)}.delivery-row>div{min-width:0;flex:1}.delivery-row b,.delivery-row span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.delivery-row b{color:var(--ink);font-size:10.5px;font-weight:900}.delivery-row span{color:var(--ink-faint);font-size:9px;font-weight:700}.delivery-row small{padding:3px 6px;border-radius:6px;color:var(--ink-soft);background:var(--surface-2);font-size:8px;font-weight:850}.empty-state{padding:27px 16px;color:var(--ink-faint);font-size:10.5px;font-weight:750;text-align:center}@keyframes spin{to{transform:rotate(360deg)}}@media(max-width:1080px){.notification-grid{grid-template-columns:1fr}.history-stack{grid-template-columns:1.15fr .85fr;align-items:start}.delivery-panel{height:max-content}}@media(max-width:680px){.notification-heading{align-items:flex-start;flex-direction:column}.notification-heading h2{font-size:20px}.notification-totals{width:100%}.notification-totals span{flex:1}.form-row,.translation-grid,.history-stack{grid-template-columns:1fr}.campaign-row{padding:13px}.delivery-row{padding:10px 13px}.campaign-counts{min-width:48px}.panel-head{padding:14px}.panel-body{padding:14px}}@media(max-width:390px){.campaign-row{gap:8px}.campaign-counts{display:none}.notification-totals span{min-width:0}}
</style>
