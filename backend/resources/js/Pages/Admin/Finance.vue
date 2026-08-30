<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'

const props = defineProps({
    requests: { type: Array, default: () => [] },
    transactions: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    canUpdateFinance: { type: Boolean, default: false },
})

const activeRequest = ref(null)
const decisionAction = ref('approve')
const decision = ref({ approved_amount: '', branch_id: '', decision_note: '' })
const decisionBusy = ref(false)
const manualBusy = ref(false)
const manual = ref({ user_id: '', type: 'qi_topup', amount: '', branch_id: '', external_reference: '', note: '' })

const typeMeta = {
    cash_handover: { key: 'Delivery Collection Handover', tint: 'var(--warning-tint)', color: 'var(--warning)' },
    budget_recharge: { key: 'Cash Budget Added', tint: 'var(--success-tint)', color: 'var(--success)' },
    qi_topup: { key: 'Qi Balance Top Up', tint: 'var(--primary-tint)', color: 'var(--primary-strong)' },
    merchant_payout: { key: 'Merchant Payout', tint: 'var(--primary-tint)', color: 'var(--primary-strong)' },
    settlement: { key: 'Settlement', tint: 'var(--primary-tint)', color: 'var(--primary-strong)' },
    withdrawal: { key: 'Withdrawal', tint: 'var(--danger-tint)', color: 'var(--danger)' },
    delivery_fee: { key: 'Delivery Fee', tint: 'var(--accent-tint)', color: 'var(--accent)' },
    collected: { key: 'Collected', tint: 'var(--success-tint)', color: 'var(--success)' },
    cash_added: { key: 'Cash Added', tint: 'var(--success-tint)', color: 'var(--success)' },
    budget_deduct: { key: 'Budget Deduct', tint: 'var(--warning-tint)', color: 'var(--warning)' },
}

const statusMeta = {
    pending: { key: 'Pending Review', className: 'pending' },
    approved: { key: 'Approved', className: 'approved' },
    rejected: { key: 'Rejected', className: 'rejected' },
}

const pendingRequests = computed(() => props.requests.filter((request) => request.status === 'pending'))
const manualAccounts = computed(() => props.accounts.filter((account) => {
    if (manual.value.type === 'merchant_payout') return account.role === 'merchant'
    return account.role === 'courier'
}))

const cards = computed(() => [
    { label: t('Pending Requests'), value: props.summary.pending_count || 0, suffix: '', tint: 'var(--warning-tint)', color: 'var(--warning)' },
    { label: t('Pending Amount'), value: fmt(props.summary.pending_amount || 0), suffix: t('IQD'), tint: 'var(--accent-tint)', color: 'var(--accent)' },
    { label: t('Delivery Collection Handovers'), value: fmt(props.summary.cash_handover || 0), suffix: t('IQD'), tint: 'var(--success-tint)', color: 'var(--success)' },
    { label: t('Cash Budget Added'), value: fmt(props.summary.budget_added || 0), suffix: t('IQD'), tint: 'var(--primary-tint)', color: 'var(--primary-strong)' },
    { label: t('Qi Top-ups'), value: fmt(props.summary.qi_topups || 0), suffix: t('IQD'), tint: 'var(--accent-tint)', color: 'var(--accent)' },
    { label: t('Branch Delivery Collections'), value: fmt(props.summary.branch_cash || 0), suffix: t('IQD'), tint: 'var(--surface-2)', color: 'var(--ink)' },
])

function meta(type) {
    return typeMeta[type] || { key: type || 'Transaction', tint: 'var(--surface-2)', color: 'var(--ink-soft)' }
}

function typeLabel(type) { return t(meta(type).key) }
function statusLabel(status) { return t((statusMeta[status] || { key: status }).key) }
function statusClass(status) { return statusMeta[status]?.className || '' }
function requiresBranch(type) { return type === 'cash_handover' }
function isQiTopup(type) { return type === 'qi_topup' }

function openDecision(request, action) {
    if (!props.canUpdateFinance) return
    activeRequest.value = request
    decisionAction.value = action
    decision.value = { approved_amount: request.amount, branch_id: request.branch?.id || '', decision_note: '' }
}

function closeDecision() {
    activeRequest.value = null
    decisionBusy.value = false
}

function submitDecision() {
    if (!props.canUpdateFinance || !activeRequest.value || decisionBusy.value) return
    const request = activeRequest.value
    const payload = { decision_note: decision.value.decision_note || null }
    if (decisionAction.value === 'approve') {
        const amount = Number.parseInt(decision.value.approved_amount, 10)
        if (!amount || amount < 1000) return
        payload.approved_amount = amount
        if (requiresBranch(request.type)) payload.branch_id = decision.value.branch_id || null
    }
    decisionBusy.value = true
    router.post(route(decisionAction.value === 'approve' ? 'admin.finance.approve' : 'admin.finance.reject', request.id), payload, {
        preserveScroll: true,
        onSuccess: closeDecision,
        onFinish: () => (decisionBusy.value = false),
    })
}

function submitManual() {
    if (!props.canUpdateFinance || manualBusy.value) return
    const amount = Number.parseInt(manual.value.amount, 10)
    if (!manual.value.user_id || !amount || amount < 1000) return
    manualBusy.value = true
    router.post(route('admin.finance.settlements.store'), {
        ...manual.value,
        user_id: Number(manual.value.user_id),
        amount,
        branch_id: requiresBranch(manual.value.type) ? (manual.value.branch_id || null) : null,
    }, {
        preserveScroll: true,
        onSuccess: () => { manual.value = { user_id: '', type: manual.value.type, amount: '', branch_id: '', external_reference: '', note: '' } },
        onFinish: () => (manualBusy.value = false),
    })
}
</script>

<template>
    <AdminShell :title="t('Finance')">
        <div class="kpi-grid finance-kpis">
            <article v-for="card in cards" :key="card.label" class="kpi">
                <span class="ki" :style="{ background: card.tint, color: card.color }"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16v12H4z M4 10h16 M8 15h3" /></svg></span>
                <span><strong class="kval mono">{{ card.value }}</strong><small v-if="card.suffix" class="kpi-unit">{{ card.suffix }}</small><b class="klab">{{ card.label }}</b></span>
            </article>
        </div>

        <section class="finance-overview" :class="{ 'finance-read-only': !canUpdateFinance }">
            <div class="panel finance-requests-panel">
                <header class="panel-head finance-head"><span><h3>{{ t('Finance Requests') }}</h3><p>{{ pendingRequests.length ? t(':count requests awaiting an administrative decision', { count: pendingRequests.length }) : t('All finance requests are up to date.') }}</p></span><b v-if="pendingRequests.length" class="finance-pending-count">{{ pendingRequests.length }}</b></header>
                <div class="request-stack">
                    <article v-for="request in pendingRequests" :key="request.id" class="finance-request-card">
                        <div class="request-card-top">
                            <span class="finance-type-icon" :style="{ background: meta(request.type).tint, color: meta(request.type).color }"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v9l3 2M5.5 5.5a9 9 0 1 0 13 0" /></svg></span>
                            <span class="request-card-main"><b>{{ typeLabel(request.type) }}</b><small>{{ request.user?.name || '—' }} · {{ request.reference }}<template v-if="request.external_reference"> · QI: {{ request.external_reference }}</template></small></span>
                            <strong class="mono">{{ fmt(request.amount) }} <small>{{ t('IQD') }}</small></strong>
                        </div>
                        <div class="request-card-meta"><span>{{ request.branch ? `${request.branch.name} — ${request.branch.city}` : t('No branch selected') }}</span><span>{{ request.created_at?.slice(0, 16).replace('T', ' ') || '—' }}</span></div>
                        <p v-if="request.note" class="request-note">{{ request.note }}</p>
                        <div v-if="canUpdateFinance" class="request-card-actions"><button class="finance-btn approve" type="button" @click="openDecision(request, 'approve')">{{ t('Approve') }}</button><button class="finance-btn reject" type="button" @click="openDecision(request, 'reject')">{{ t('Reject') }}</button></div>
                    </article>
                    <div v-if="!pendingRequests.length" class="finance-empty">{{ t('No pending finance requests.') }}</div>
                </div>
            </div>

            <form v-if="canUpdateFinance" class="panel finance-manual-panel" @submit.prevent="submitManual">
                <header class="panel-head"><span><h3>{{ t('Record Settlement') }}</h3><p>{{ t('Record a verified office transaction with a complete audit trail.') }}</p></span></header>
                <div class="panel-body finance-form-grid">
                    <label><span>{{ t('Operation') }}</span><select v-model="manual.type"><option value="qi_topup">{{ t('Qi Balance Top Up') }}</option><option value="budget_recharge">{{ t('Cash Budget Added') }}</option><option value="cash_handover">{{ t('Delivery Collection Handover') }}</option><option value="merchant_payout">{{ t('Merchant Payout') }}</option></select></label>
                    <label><span>{{ t('Account') }}</span><select v-model="manual.user_id" required><option value="" disabled>{{ t('Select account') }}</option><option v-for="account in manualAccounts" :key="account.id" :value="account.id">{{ account.name }} — {{ account.phone }}</option></select></label>
                    <label><span>{{ t('Amount') }} ({{ t('IQD') }})</span><input v-model="manual.amount" type="number" min="1000" step="1000" inputmode="numeric" required></label>
                    <label v-if="isQiTopup(manual.type)"><span>{{ t('Qi Transaction Reference') }}</span><input v-model.trim="manual.external_reference" type="text" inputmode="text" :placeholder="t('Optional for direct administrative credit')"></label>
                    <label v-if="requiresBranch(manual.type)"><span>{{ t('Receiving Branch') }}</span><select v-model="manual.branch_id" required><option value="" disabled>{{ t('Select branch') }}</option><option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }} — {{ branch.city }}</option></select></label>
                    <p v-if="requiresBranch(manual.type)" class="collection-scope form-wide">{{ t('Delivery collections are posted to the selected branch cashbox after approval. Qi top-ups and courier budgets do not enter that cashbox.') }}</p>
                    <label class="form-wide"><span>{{ t('Note') }}</span><textarea v-model="manual.note" rows="2" :placeholder="t('Optional settlement note')"></textarea></label>
                    <button class="finance-submit form-wide" type="submit" :disabled="manualBusy"><span v-if="manualBusy" class="loader"></span><span v-else>{{ t('Record Settlement') }}</span></button>
                </div>
            </form>
        </section>

        <section class="panel finance-history-panel">
            <header class="panel-head"><span><h3>{{ t('Finance Request History') }}</h3><p>{{ t('Every decision is preserved with its ledger reference.') }}</p></span></header>
            <div class="finance-table-scroll"><table class="tbl finance-table"><thead><tr><th>{{ t('Reference') }}</th><th>{{ t('Operation') }}</th><th>{{ t('Account') }}</th><th>{{ t('Branch') }}</th><th>{{ t('Amount') }}</th><th>{{ t('Status') }}</th><th>{{ t('Processed By') }}</th><th>{{ t('Date') }}</th></tr></thead><tbody><tr v-for="request in requests" :key="request.id"><td class="mono">{{ request.reference }}<small v-if="request.external_reference" class="cell-sub">QI: {{ request.external_reference }}</small></td><td><span class="chip" :style="{ background: meta(request.type).tint, color: meta(request.type).color }"><i :style="{ background: meta(request.type).color }"></i>{{ typeLabel(request.type) }}</span></td><td><b>{{ request.user?.name || '—' }}</b><small class="cell-sub">{{ request.user?.phone }}</small></td><td>{{ request.branch ? `${request.branch.name} — ${request.branch.city}` : '—' }}</td><td class="mono">{{ fmt(request.approved_amount ?? request.amount) }}</td><td><span class="finance-status" :class="statusClass(request.status)">{{ statusLabel(request.status) }}</span></td><td>{{ request.processor || '—' }}</td><td class="mono">{{ request.processed_at?.slice(0, 10) || request.created_at?.slice(0, 10) || '—' }}</td></tr></tbody></table><div v-if="!requests.length" class="finance-empty">{{ t('No finance requests yet.') }}</div></div>
        </section>

        <section class="panel finance-ledger-panel">
            <header class="panel-head"><span><h3>{{ t('Transactions') }}</h3><p>{{ t('Immutable ledger entries posted after approval.') }}</p></span></header>
            <div class="finance-table-scroll"><table class="tbl finance-table"><thead><tr><th>{{ t('Type') }}</th><th>{{ t('User') }}</th><th>{{ t('Ref') }}</th><th>{{ t('Date') }}</th><th>{{ t('Amount') }}</th></tr></thead><tbody><tr v-for="transaction in transactions" :key="transaction.id"><td><span class="chip" :style="{ background: meta(transaction.type).tint, color: meta(transaction.type).color }"><i :style="{ background: meta(transaction.type).color }"></i>{{ typeLabel(transaction.type) }}</span></td><td><b>{{ transaction.user || '—' }}</b><small class="cell-sub">{{ transaction.role ? t(transaction.role === 'courier' ? 'Courier' : 'Merchant') : '' }}</small></td><td class="mono">{{ transaction.request_ref || transaction.ref || '—' }}</td><td>{{ transaction.date || '—' }}</td><td><b class="mono" :class="transaction.direction >= 0 ? 'up' : 'dn'" style="direction:ltr">{{ transaction.direction >= 0 ? '+' : '-' }}{{ fmt(transaction.amount) }}</b></td></tr></tbody></table><div v-if="!transactions.length" class="finance-empty">{{ t('No transactions yet') }}</div></div>
        </section>

        <div v-if="activeRequest && canUpdateFinance" class="finance-dialog-backdrop" @click.self="closeDecision">
            <form class="finance-dialog" @submit.prevent="submitDecision">
                <header><span><b>{{ decisionAction === 'approve' ? t('Approve Finance Request') : t('Reject Finance Request') }}</b><small>{{ activeRequest.reference }} · {{ typeLabel(activeRequest.type) }}</small></span><button type="button" :aria-label="t('Close')" @click="closeDecision">×</button></header>
                <div class="finance-dialog-body">
                    <div class="finance-dialog-amount"><span>{{ t('Requested Amount') }}</span><b class="mono">{{ fmt(activeRequest.amount) }} {{ t('IQD') }}</b></div>
                    <label v-if="decisionAction === 'approve'"><span>{{ t('Approved Amount') }}</span><input v-model="decision.approved_amount" type="number" min="1000" :max="activeRequest.amount" step="1000" inputmode="numeric" required></label>
                    <label v-if="decisionAction === 'approve' && requiresBranch(activeRequest.type)"><span>{{ t('Receiving Branch') }}</span><select v-model="decision.branch_id" required><option value="" disabled>{{ t('Select branch') }}</option><option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }} — {{ branch.city }}</option></select></label>
                    <label><span>{{ decisionAction === 'approve' ? t('Decision Note') : t('Reason for Rejection') }}</span><textarea v-model="decision.decision_note" rows="3" :placeholder="t('Optional settlement note')"></textarea></label>
                </div>
                <footer><button class="finance-btn neutral" type="button" @click="closeDecision">{{ t('Cancel') }}</button><button class="finance-btn" :class="decisionAction === 'approve' ? 'approve' : 'reject'" type="submit" :disabled="decisionBusy"><span v-if="decisionBusy" class="loader"></span><span v-else>{{ decisionAction === 'approve' ? t('Approve') : t('Reject') }}</span></button></footer>
            </form>
        </div>
    </AdminShell>
</template>

<style scoped>
.finance-kpis{grid-template-columns:repeat(auto-fit,minmax(175px,1fr));margin-bottom:18px}.kpi{min-height:90px}.kpi-unit{display:inline-block;margin-inline-start:5px;color:var(--ink-faint);font-size:10px;font-weight:800}.finance-overview{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(300px,.9fr);gap:16px;margin-bottom:16px}.finance-overview.finance-read-only{grid-template-columns:minmax(0,1fr)}.panel-head{align-items:flex-start}.panel-head h3{margin:0}.panel-head p{margin:4px 0 0;color:var(--ink-faint);font-size:10.5px;font-weight:700;line-height:1.7}.finance-head{justify-content:space-between}.finance-pending-count{display:grid;place-items:center;min-width:28px;height:28px;border-radius:20px;background:var(--warning-tint);color:var(--warning);font-size:13px}.request-stack{display:grid;gap:10px;padding:0 15px 15px}.finance-request-card{padding:13px;border:1px solid var(--border);border-radius:14px;background:var(--surface-2)}.request-card-top{display:flex;align-items:center;gap:10px}.finance-type-icon{display:grid;place-items:center;flex:none;width:35px;height:35px;border-radius:11px}.request-card-main{display:grid;min-width:0;flex:1}.request-card-main b{font-size:12px}.request-card-main small,.request-card-meta{color:var(--ink-faint);font-size:9.5px;font-weight:700}.request-card-top>strong{font-size:13px;white-space:nowrap}.request-card-top>strong small{font-family:var(--font);font-size:9px;color:var(--ink-faint)}.request-card-meta{display:flex;justify-content:space-between;gap:10px;margin:9px 0 0;padding-top:8px;border-top:1px solid var(--border)}.request-note{margin:9px 0 0;padding:7px 9px;border-radius:8px;background:var(--surface);color:var(--ink-soft);font-size:10px;font-weight:700}.request-card-actions{display:flex;gap:8px;margin-top:11px}.finance-btn{min-height:34px;padding:7px 12px;border:1px solid transparent;border-radius:9px;font:800 11px var(--font);cursor:pointer}.finance-btn.approve{background:var(--primary);color:#fff}.finance-btn.reject{border-color:color-mix(in srgb,var(--danger) 30%,var(--border));background:var(--danger-tint);color:var(--danger)}.finance-btn.neutral{background:var(--surface-2);color:var(--ink-soft)}.request-card-actions .finance-btn{flex:1}.finance-empty{padding:24px 16px;color:var(--ink-faint);font-size:11px;font-weight:700;text-align:center}.finance-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.finance-form-grid label,.finance-dialog-body label{display:grid;gap:5px;color:var(--ink-soft);font-size:10.5px;font-weight:800}.finance-form-grid input,.finance-form-grid select,.finance-form-grid textarea,.finance-dialog-body input,.finance-dialog-body select,.finance-dialog-body textarea{width:100%;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--ink);font:700 12px var(--font);outline:0;padding:9px 10px;resize:vertical}.finance-form-grid input:focus,.finance-form-grid select:focus,.finance-form-grid textarea:focus,.finance-dialog-body input:focus,.finance-dialog-body select:focus,.finance-dialog-body textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px color-mix(in srgb,var(--primary) 15%,transparent)}.form-wide{grid-column:1 / -1}.collection-scope{margin:0;padding:9px 10px;border:1px solid color-mix(in srgb,var(--success) 24%,var(--border));border-radius:9px;color:var(--ink-soft);background:var(--success-tint);font-size:9.5px;font-weight:750;line-height:1.65}.finance-submit{display:grid;place-items:center;min-height:40px;border:0;border-radius:10px;background:var(--primary);color:#fff;font:900 12px var(--font);cursor:pointer}.finance-history-panel,.finance-ledger-panel{margin-top:16px}.finance-table-scroll{overflow:auto}.finance-table{min-width:790px}.finance-table td{vertical-align:middle}.cell-sub{display:block;margin-top:2px;color:var(--ink-faint);font-size:9.5px;font-weight:700}.finance-status{display:inline-flex;padding:3px 8px;border-radius:16px;background:var(--surface-2);color:var(--ink-soft);font-size:9.5px;font-weight:850;white-space:nowrap}.finance-status.pending{background:var(--warning-tint);color:var(--warning)}.finance-status.approved{background:var(--success-tint);color:var(--success)}.finance-status.rejected{background:var(--danger-tint);color:var(--danger)}.up{color:var(--success)}.dn{color:var(--danger)}.finance-dialog-backdrop{position:fixed;z-index:90;inset:0;display:grid;place-items:center;padding:18px;background:rgba(8,18,17,.5);backdrop-filter:blur(4px)}.finance-dialog{width:min(100%,430px);overflow:hidden;border:1px solid var(--border);border-radius:18px;background:var(--surface);box-shadow:0 28px 70px rgba(0,0,0,.3)}.finance-dialog header{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:15px 17px;border-bottom:1px solid var(--border)}.finance-dialog header span{display:grid;gap:2px}.finance-dialog header b{font-size:14px}.finance-dialog header small{color:var(--ink-faint);font-size:10px;font-weight:700}.finance-dialog header button{display:grid;place-items:center;width:28px;height:28px;border-radius:8px;background:var(--surface-2);color:var(--ink-soft);font-size:20px;line-height:1}.finance-dialog-body{display:grid;gap:13px;padding:17px}.finance-dialog-amount{display:flex;justify-content:space-between;align-items:center;padding:10px 11px;border-radius:10px;background:var(--surface-2);font-size:11px;color:var(--ink-soft);font-weight:800}.finance-dialog-amount b{color:var(--ink);font-size:13px}.finance-dialog footer{display:flex;justify-content:flex-end;gap:8px;padding:13px 17px;border-top:1px solid var(--border)}@media(max-width:980px){.finance-overview{grid-template-columns:1fr}.finance-manual-panel{order:-1}}@media(max-width:560px){.finance-form-grid{grid-template-columns:1fr}.form-wide{grid-column:auto}.finance-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.finance-kpis .kpi:last-child{grid-column:1 / -1}.request-card-meta{align-items:flex-start;flex-direction:column;gap:3px}.finance-dialog-backdrop{align-items:end;padding:0}.finance-dialog{width:100%;border-radius:19px 19px 0 0}.finance-dialog footer{padding-bottom:calc(13px + env(safe-area-inset-bottom,0px))}}
</style>
