<script setup>
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'
import BranchFilter from '../../Components/BranchFilter.vue'

const props = defineProps({
    recentOperations: { type: Array, default: () => [] },
    recipients: { type: Array, default: () => [] },
    counts: { type: Object, default: () => ({}) },
    canCreateNotifications: { type: Boolean, default: false },
    branchFilter: { type: Object, default: () => ({}) },
})

const showTranslations = ref(false)
const recipientQuery = ref('')
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

const courierRoles = ['courier', 'pickup_courier', 'delivery_courier', 'transporter']
const individualAudiences = ['merchant', 'courier']

const selectedRecipients = computed(() => {
    if (form.audience === 'merchant') {
        return props.recipients.filter((recipient) => recipient.role === 'merchant')
    }

    if (form.audience === 'courier') {
        return props.recipients.filter((recipient) => courierRoles.includes(recipient.role))
    }

    return []
})

const filteredRecipients = computed(() => {
    const query = recipientQuery.value.trim().toLocaleLowerCase()
    if (!query) return selectedRecipients.value

    return selectedRecipients.value.filter((recipient) => [recipient.name, recipient.phone]
        .filter(Boolean)
        .some((value) => String(value).toLocaleLowerCase().includes(query)))
})

const recipientSearchPlaceholder = computed(() => `${t('Search')} ${t('Name')} / ${t('Phone')}`)

const recipientEstimate = computed(() => {
    if (form.audience === 'merchants') return props.recipients.filter((recipient) => recipient.role === 'merchant').length
    if (form.audience === 'couriers') return props.recipients.filter((recipient) => courierRoles.includes(recipient.role)).length
    if (individualAudiences.includes(form.audience)) {
        return selectedRecipients.value.some((recipient) => String(recipient.id) === String(form.target_user_id)) ? 1 : 0
    }
    return props.recipients.length
})

const audienceOptions = computed(() => [
    { value: 'all', label: t('All Users') },
    { value: 'merchants', label: t('All Merchants') },
    { value: 'merchant', label: `${t('Specific Account')} — ${t('Merchant')}` },
    { value: 'couriers', label: t('All Couriers') },
    { value: 'courier', label: `${t('Specific Account')} — ${t('Courier')}` },
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
    recipientQuery.value = ''
    if (!individualAudiences.includes(value)) {
        form.target_user_id = ''
        return
    }

    const targetStillMatchesAudience = selectedRecipients.value.some((recipient) => String(recipient.id) === String(form.target_user_id))
    if (!targetStillMatchesAudience) form.target_user_id = ''
}

function recipientLabel(recipient) {
    return `${recipient.name} · ${roleLabel(recipient.role)} · ${recipient.phone || '—'}`
}

function audienceLabel(campaign) {
    const targetName = campaign.target_user?.name || t('One Account')
    const individualLabel = (role) => `${t('Specific Account')} — ${role} · ${targetName}`
    const labels = {
        all: t('All Users'),
        merchants: t('Merchants'),
        merchant: individualLabel(t('Merchant')),
        couriers: t('Couriers'),
        courier: individualLabel(t('Courier')),
        // Campaigns dispatched before the role-specific target audiences
        // remain understandable in the delivery history.
        user: campaign.target_user?.role === 'merchant'
            ? individualLabel(t('Merchant'))
            : individualLabel(t('Courier')),
    }

    return labels[campaign.audience] || campaign.audience
}

function roleLabel(role) {
    const labels = {
        merchant: 'Merchant',
        courier: 'Courier',
        pickup_courier: 'Pickup courier',
        delivery_courier: 'Delivery courier',
        transporter: 'Transporter',
    }

    return t(labels[role] || role)
}

function typeLabel(type) {
    return typeOptions.value.find((option) => option.value === type)?.label || type
}

function typeClass(type) {
    return `type-${type}`
}

function submit() {
    if (!props.canCreateNotifications) return
    form.post(route('admin.notifications.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
            form.clearErrors()
            showTranslations.value = false
        },
    })
}

function changeBranchFilter(branchId) {
    router.get(route('admin.notifications'), { branch_id: branchId || undefined }, {
        preserveState: true,
        replace: true,
    })
}
</script>

<template>
    <AdminShell :title="t('Notifications')">
        <section class="notification-heading">
            <div>
                <p class="eyebrow">{{ t('Communication Center') }}</p>
                <h2>{{ t('Notifications') }}</h2>
                <p>أرسل إشعاراً عند الحاجة، وراجع إشعارات النظام والحركات المحفوظة في سجل واحد واضح.</p>
            </div>
            <div class="notification-totals" aria-label="Notification totals">
                <span><b>{{ counts.system || 0 }}</b>إشعارات النظام</span>
                <span class="unread-total"><b>{{ counts.operations || 0 }}</b>آخر العمليات</span>
            </div>
        </section>
        <div class="notification-branch-filter">
            <BranchFilter :filter="branchFilter" @change="changeBranchFilter" />
        </div>

        <div class="notification-grid" :class="{ 'history-only': !canCreateNotifications }">
            <form v-if="canCreateNotifications" class="panel composer-panel" @submit.prevent="submit">
                <header class="panel-head composer-head">
                    <div class="compose-icon">✦</div>
                    <div><h3>{{ t('Compose Notification') }}</h3><p>{{ t('Send a clear in-app message to all active users, a role, or one account.') }}</p></div>
                </header>

                <div class="panel-body composer-body">
                    <div class="form-row">
                        <label class="field">
                            <span>{{ t('Audience') }}</span>
                            <PopupSelect :model-value="form.audience" @change="setAudience($event.target.value)">
                                <option v-for="option in audienceOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </PopupSelect>
                            <small v-if="form.errors.audience" class="field-error">{{ form.errors.audience }}</small>
                        </label>
                        <label class="field">
                            <span>{{ t('Notification Type') }}</span>
                            <PopupSelect v-model="form.type">
                                <option v-for="option in typeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </PopupSelect>
                            <small v-if="form.errors.type" class="field-error">{{ form.errors.type }}</small>
                        </label>
                    </div>

                    <label v-if="individualAudiences.includes(form.audience)" class="field">
                        <span>{{ form.audience === 'merchant' ? t('Merchant') : t('Courier') }}</span>
                        <input v-model="recipientQuery" type="search" :placeholder="recipientSearchPlaceholder" autocomplete="off" />
                        <PopupSelect v-model="form.target_user_id" required>
                            <option disabled value="">{{ t('Choose active user') }}</option>
                            <option v-for="recipient in filteredRecipients" :key="recipient.id" :value="recipient.id">{{ recipientLabel(recipient) }}</option>
                        </PopupSelect>
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
                    <p class="dispatch-note">يحفظ النظام الإشعارات التشغيلية والحركات المهمة في سجل آخر العمليات.</p>
                </div>
            </form>

            <section class="history-stack">
                <article class="panel history-panel system-operations-panel">
                    <header class="panel-head"><div><h3>إشعارات النظام وآخر العمليات</h3><p>سجل موحّد للرسائل التي ينشئها النظام وللحركات التشغيلية المهمة.</p></div></header>
                    <div class="system-operation-list">
                        <article v-for="operation in recentOperations" :key="operation.id" class="system-operation-row">
                            <span class="system-operation-icon" :class="operation.kind">{{ operation.kind === 'notification' ? '🔔' : '◈' }}</span>
                            <div>
                                <b>{{ operation.title }}</b>
                                <p v-if="operation.detail">{{ operation.detail }}</p>
                                <small><span v-if="operation.actor">{{ operation.actor }} · </span>{{ operation.created_at }}</small>
                            </div>
                        </article>
                        <div v-if="!recentOperations.length" class="empty-state">لا توجد إشعارات أو حركات محفوظة حالياً.</div>
                    </div>
                </article>
            </section>
        </div>
    </AdminShell>
</template>

<style scoped>
.notification-heading{display:flex;align-items:end;justify-content:space-between;gap:20px;margin-bottom:21px}.eyebrow{margin:0 0 3px;color:var(--primary);font-size:10px;font-weight:900}.notification-heading h2{margin:0;color:var(--ink);font-size:23px;font-weight:900}.notification-heading>div>p:last-child,.panel-head p{margin:5px 0 0;color:var(--ink-faint);font-size:11px;font-weight:650;line-height:1.65}.notification-totals{display:flex;gap:8px}.notification-totals span{display:grid;min-width:92px;padding:7px 10px;border:1px solid var(--border);border-radius:10px;color:var(--ink-faint);background:var(--surface);font-size:8.5px;font-weight:800;text-align:center}.notification-totals b{color:var(--ink);font-size:14px}.notification-grid{display:grid;grid-template-columns:minmax(330px,.88fr) minmax(0,1.12fr);gap:18px;align-items:start}.composer-panel,.history-panel{margin:0}.composer-head{align-items:flex-start}.composer-head>div:last-child{flex:1}.panel-head h3{margin:0}.compose-icon{width:36px;height:36px;display:grid;place-items:center;flex:none;border-radius:11px;color:var(--primary-strong);background:var(--primary-tint);font-size:17px}.composer-body{display:grid;gap:14px}.form-row,.translation-grid{display:grid;grid-template-columns:1fr 1fr;gap:11px}.field{display:grid;gap:6px;color:var(--ink-soft);font-size:10.5px;font-weight:850}.field input,.field textarea,.field select{width:100%;box-sizing:border-box;border:1px solid var(--border);border-radius:10px;padding:9px 10px;color:var(--ink);background:var(--surface-2);font:700 11.5px var(--font)}.field input,.field select{min-height:41px}.field textarea{min-height:80px;resize:vertical}.language-block{min-width:0;margin:0;padding:12px;border:1px solid var(--border);border-radius:12px}.language-block legend{color:var(--primary-strong);font-size:10px;font-weight:900}.language-block .field+.field{margin-top:10px}.send-button{min-height:44px;width:100%;border:0;border-radius:11px;color:#062033;background:linear-gradient(135deg,var(--primary),#0ea5e9);font:900 12px var(--font)}.dispatch-note,.field-error{margin:0;color:var(--ink-faint);font-size:9.5px}.history-stack{display:grid;gap:18px}.system-operation-row{display:grid;grid-template-columns:34px minmax(0,1fr);gap:10px;margin:4px 7px;padding:11px 10px;border:1px solid var(--border);border-radius:10px;background:var(--surface)}.system-operation-icon{width:30px;height:30px;display:grid;place-items:center;border-radius:9px;color:var(--primary-strong);background:var(--primary-tint)}.system-operation-icon.activity{color:var(--warning);background:var(--warning-tint)}.system-operation-row>div{min-width:0}.system-operation-row b,.system-operation-row p,.system-operation-row small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.system-operation-row b{color:var(--ink);font-size:11px;font-weight:900}.system-operation-row p{margin:2px 0;color:var(--ink-soft);font-size:9.5px}.system-operation-row small{color:var(--ink-faint);font-size:8.5px}.empty-state{padding:27px 16px;color:var(--ink-faint);text-align:center}@media(max-width:1080px){.notification-grid{grid-template-columns:1fr}}@media(max-width:680px){.notification-heading{align-items:flex-start;flex-direction:column}.notification-totals{width:100%}.notification-totals span{flex:1}.form-row,.translation-grid{grid-template-columns:1fr}}
.system-operations-panel{display:flex;min-height:0;flex-direction:column}.system-operations-panel .panel-head{flex:none}.system-operation-list{display:grid;max-height:420px;overflow-y:auto;overscroll-behavior:contain;scrollbar-gutter:stable;padding-inline-end:3px}.system-operation-list::-webkit-scrollbar{width:7px}.system-operation-list::-webkit-scrollbar-thumb{border-radius:999px;background:color-mix(in srgb,var(--primary) 34%,transparent)}@media(max-width:680px){.system-operation-list{max-height:48vh}}
</style>
