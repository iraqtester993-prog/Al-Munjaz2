<script setup>
import { computed, onMounted, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AppShell from '../../Components/AppShell.vue'
import SheetModal from '../../Components/SheetModal.vue'

const props = defineProps({
    isCourier: { type: Boolean, default: false },
    balance: { type: Number, default: 0 },
    budget: { type: Number, default: 0 },
    summary: { type: Object, default: () => ({}) },
    transactions: { type: Array, default: () => [] },
    requests: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    loyalty: { type: Object, default: () => ({ balance: 0, entries: [] }) },
    intent: { type: String, default: null },
})

const page = usePage()
const locale = computed(() => page.props.locale || 'ar')
const busy = ref(false)
const showWithdraw = ref(false)
const showHandover = ref(false)
const showBudget = ref(false)
const showQiComingSoon = ref(false)
const withdraw = ref({ amount: '', gateway: '' })
const handover = ref({ amount: '', branch_id: '', note: '' })
const budgetCash = ref({ amount: '', note: '' })

function moneyDigits(value) {
    return String(value ?? '').replace(/[^0-9]/g, '')
}

function formattedAmount(model) {
    return computed({
        get: () => String(model.value.amount ?? '') === '' ? '' : fmt(Number(model.value.amount || 0)),
        set: (value) => { model.value.amount = moneyDigits(value) },
    })
}

const withdrawAmountInput = formattedAmount(withdraw)
const handoverAmountInput = formattedAmount(handover)
const budgetCashAmountInput = formattedAmount(budgetCash)

const typeMap = {
    withdrawal: { key: 'Withdrawal', tint: 'var(--danger-tint)', color: 'var(--danger)', icon: 'out' },
    cash_added: { key: 'Cash Added', tint: 'var(--success-tint)', color: 'var(--success)', icon: 'in' },
    budget_deduct: { key: 'Budget Deduct', tint: 'var(--warning-tint)', color: 'var(--warning)', icon: 'out' },
    budget_release: { key: 'Budget Released', tint: 'var(--success-tint)', color: 'var(--success)', icon: 'in' },
    paid_order: { key: 'Order Budget Hold', tint: 'var(--warning-tint)', color: 'var(--warning)', icon: 'out' },
    settlement: { key: 'Settlement', tint: 'var(--primary-tint)', color: 'var(--primary-strong)', icon: 'in' },
    merchant_payout: { key: 'Merchant Payout', tint: 'var(--primary-tint)', color: 'var(--primary-strong)', icon: 'out' },
    cash_handover: { key: 'Cash Handover', tint: 'var(--warning-tint)', color: 'var(--warning)', icon: 'out' },
    budget_recharge: { key: 'Cash Budget Added', tint: 'var(--success-tint)', color: 'var(--success)', icon: 'in' },
    qi_topup: { key: 'Qi Balance Top Up', tint: 'var(--primary-tint)', color: 'var(--primary-strong)', icon: 'in' },
    delivery_fee: { key: 'Delivery Fee', tint: 'var(--accent-tint)', color: 'var(--accent)', icon: 'fee' },
    collected: { key: 'Collected', tint: 'var(--success-tint)', color: 'var(--success)', icon: 'in' },
    returned: { key: 'Returned', tint: 'var(--st-returned-tint)', color: 'var(--st-returned)', icon: 'out' },
    commission: { key: 'Delivery Commission', tint: 'var(--accent-tint)', color: 'var(--accent)', icon: 'fee' },
}

const requestMap = {
    cash_handover: 'Cash Handover',
    budget_recharge: 'Cash Budget Added',
    qi_topup: 'Qi Balance Top Up',
    merchant_payout: 'Settlement Request',
}

const transactionGroups = computed(() => {
    const groups = new Map()

    props.transactions.forEach((transaction) => {
        const date = transaction.date || ''
        const current = groups.get(date) || []
        current.push(transaction)
        groups.set(date, current)
    })

    return [...groups.entries()]
        .sort(([a], [b]) => b.localeCompare(a))
        .map(([date, transactions]) => ({
            date,
            transactions,
            total: transactions.reduce((sum, transaction) => sum + (Number(transaction.direction) >= 0 ? Number(transaction.amount || 0) : -Number(transaction.amount || 0)), 0),
        }))
})

const lastSettlement = computed(() => props.summary.last_settlement || null)
const pendingRequests = computed(() => props.requests.filter((request) => request.status === 'pending'))
const cashOnHand = computed(() => Number(props.summary.cash_on_hand || 0))
const remainingBudget = computed(() => Math.max(0, Number(props.budget || 0) - cashOnHand.value))
const loyaltyEntries = computed(() => Array.isArray(props.loyalty?.entries) ? props.loyalty.entries : [])

function loyaltyLabel(entry) {
    if (entry?.type === 'delivery_reward') return t('Completed delivery reward')
    if (Number(entry?.points || 0) >= 0) return t('Points adjustment')
    return t('Points redemption')
}

function loyaltyDate(entry) {
    if (!entry?.created_at) return ''
    return new Intl.DateTimeFormat({ ar: 'ar-IQ-u-nu-latn', en: 'en-US', ku: 'ku-IQ-u-nu-latn' }[locale.value] || 'en-US', {
        day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
    }).format(new Date(entry.created_at))
}

function txMeta(transaction) {
    const meta = typeMap[transaction.type] || { key: transaction.type || 'Transaction', tint: 'var(--surface-2)', color: 'var(--ink-soft)', icon: 'in' }
    return { ...meta, label: t(meta.key) }
}

function requestLabel(request) {
    return t(requestMap[request.type] || request.type)
}

function statusLabel(status) {
    return t({ pending: 'Pending Review', approved: 'Approved', rejected: 'Rejected' }[status] || status)
}

function txIcon(name) {
    const paths = {
        in: 'M12 3v14m0 0 6-6m-6 6-6-6 M4 21h16',
        out: 'M12 21V7m0 0 6 6m-6-6-6 6 M4 3h16',
        fee: 'M12 3v4m0 10v4M3 12h4m10 0h4 M12 12l-1.5 3h3L12 18',
    }
    return paths[name] || paths.in
}

function formatDate(date) {
    if (!date) return t('Not specified')

    const language = { ar: 'ar-IQ-u-nu-latn', en: 'en-US', ku: 'ku-IQ-u-nu-latn' }[locale.value] || 'en-US'
    return new Intl.DateTimeFormat(language, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
        .format(new Date(`${date.slice(0, 10)}T12:00:00`))
}

function requestDate(date) {
    if (!date) return t('Not specified')
    return new Intl.DateTimeFormat({ ar: 'ar-IQ-u-nu-latn', en: 'en-US', ku: 'ku-IQ-u-nu-latn' }[locale.value] || 'en-US', {
        day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
    }).format(new Date(date))
}

function contactSupport() {
    router.post(route('app.chats.open'), {}, { preserveScroll: true })
}

function submit(routeName, payload, done) {
    const amount = Number.parseInt(payload.amount, 10)
    if (!amount || amount < 1000) return

    busy.value = true
    router.post(route(routeName), { ...payload, amount }, {
        preserveScroll: true,
        onSuccess: done,
        onFinish: () => (busy.value = false),
    })
}

function doWithdraw() {
    submit('app.wallet.withdraw', withdraw.value, () => {
        showWithdraw.value = false
        withdraw.value = { amount: '', gateway: '' }
    })
}

function submitHandover() {
    submit('app.wallet.handover', handover.value, () => {
        showHandover.value = false
        handover.value = { amount: '', branch_id: '', note: '' }
    })
}

function submitBudget() {
    submit('app.wallet.budget', budgetCash.value, () => {
        showBudget.value = false
        budgetCash.value = { amount: '', note: '' }
    })
}

function openBudget() {
    showBudget.value = true
}

function openQiComingSoon() {
    showQiComingSoon.value = true
}

onMounted(() => {
    if (props.intent === 'budget') openBudget()
    if (props.intent === 'qi') openQiComingSoon()

    if (props.intent && typeof window !== 'undefined') {
        const url = new URL(window.location.href)
        url.searchParams.delete('intent')
        window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`)
    }
})
</script>

<template>
    <AppShell :title="t('Wallet')">
        <template v-if="!isCourier">
            <section class="wallet-card merchant-wallet-card">
                <div class="wallet-orbit wallet-orbit-one" aria-hidden="true"></div>
                <div class="wallet-orbit wallet-orbit-two" aria-hidden="true"></div>
                <div class="wallet-content">
                    <div class="wc-top">
                        <span class="wc-badge">{{ t('Available Balance') }}</span>
                        <span class="wallet-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M20 7H6a2 2 0 0 1-2-2 2 2 0 0 1 2-2h13v3M20 7a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6M16 14h.01" /></svg>
                        </span>
                    </div>
                    <div class="wc-value mono">{{ fmt(balance) }} <span>{{ t('IQD') }}</span></div>
                    <div class="wc-label">{{ t('Current Balance') }}</div>

                    <div class="wallet-divider"></div>
                    <div class="merchant-finance-grid">
                        <div>
                            <span>{{ t('Undisbursed Dues') }}</span>
                            <strong class="mono">{{ fmt(summary.undisbursed_due || 0) }}</strong>
                        </div>
                        <div>
                            <span>{{ t('Last Settlement') }}</span>
                            <strong v-if="lastSettlement" class="mono">{{ fmt(lastSettlement.amount) }}</strong>
                            <strong v-else>—</strong>
                            <small v-if="lastSettlement">{{ formatDate(lastSettlement.date) }}</small>
                        </div>
                    </div>

                    <div class="wallet-actions wallet-actions-inline">
                        <button type="button" class="solid" @click="showWithdraw = true">
                            <svg viewBox="0 0 24 24"><path d="M12 21V7m0 0 6 6m-6-6-6 6M4 3h16" /></svg>
                            {{ t('Request Withdrawal') }}
                        </button>
                        <button type="button" @click="contactSupport">
                            <svg viewBox="0 0 24 24"><path d="M21 12a8 8 0 0 1-8 8H4l1.5-3.5A8 8 0 1 1 21 12Z" /></svg>
                            {{ t('Support') }}
                        </button>
                    </div>
                </div>
            </section>
        </template>

        <template v-else>
            <section class="courier-wallet-balance list-card">
                <span class="courier-wallet-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M20 7H6a2 2 0 0 1-2-2 2 2 0 0 1 2-2h13v3M20 7a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6M16 14h.01" /></svg>
                </span>
                <div>
                    <span>{{ t('My Available Wallet Balance') }}</span>
                    <strong class="mono">{{ fmt(balance) }} <small>{{ t('IQD') }}</small></strong>
                </div>
            </section>

            <section class="courier-overview-grid">
                <article class="courier-overview-card courier-points-card">
                    <span class="courier-overview-orb" aria-hidden="true"></span>
                    <span>{{ t('Courier Points') }}</span>
                    <strong class="mono">{{ fmt(loyalty.balance || 0) }} <small>{{ t('Point') }}</small></strong>
                </article>
                <article class="courier-overview-card courier-deliveries-card">
                    <span>{{ t('Number of Deliveries') }}</span>
                    <strong class="mono">{{ fmt(summary.completed_deliveries || 0) }} <small>{{ t('Times') }}</small></strong>
                </article>
            </section>

            <section class="wallet-card courier-budget-card">
                <div class="wallet-orbit wallet-orbit-one" aria-hidden="true"></div>
                <div class="wallet-orbit wallet-orbit-two" aria-hidden="true"></div>
                <div class="wallet-content">
                    <div class="budget-title">
                        <span class="wallet-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 4.7 3 8.8 7 10 4-1.2 7-5.3 7-10V6l-7-3Zm0 5v7m0 0 3-3m-3 3-3-3" /></svg>
                        </span>
                        <b>{{ t('Budget') }}</b>
                    </div>
                    <span class="budget-label">{{ t('Budget Amount') }}</span>
                    <strong class="budget-value mono">{{ fmt(budget) }} <small>{{ t('IQD') }}</small></strong>
                    <div class="wallet-divider"></div>
                    <div class="budget-bottom-row">
                        <span>{{ t('Remaining budget') }}</span>
                        <b class="mono">{{ fmt(remainingBudget) }} {{ t('IQD') }}</b>
                    </div>
                    <button class="courier-budget-add" type="button" @click="openBudget">{{ t('Add Cash Budget') }}</button>
                </div>
            </section>
        </template>

        <section v-if="!isCourier && requests.length" class="finance-requests list-card">
            <header class="finance-requests-head">
                <div>
                    <h3>{{ t('Finance Requests') }}</h3>
                    <span>{{ pendingRequests.length ? t(':count Pending', { count: pendingRequests.length }) : t('Request History') }}</span>
                </div>
                <b v-if="pendingRequests.length" class="pending-count">{{ pendingRequests.length }}</b>
            </header>
            <article v-for="request in requests" :key="request.id" class="finance-request-row">
                <span class="request-icon" :class="request.status">
                    <svg viewBox="0 0 24 24"><path d="M12 3v9l3 2M5.5 5.5a9 9 0 1 0 13 0" /></svg>
                </span>
                <span class="request-main">
                    <b>{{ requestLabel(request) }}</b>
                    <small class="mono">{{ request.reference }}</small>
                    <small v-if="request.external_reference" class="mono">QI: {{ request.external_reference }}</small>
                    <small v-if="request.branch">{{ request.branch.name }}{{ request.branch.city ? ` — ${request.branch.city}` : '' }}</small>
                    <small v-else>{{ requestDate(request.created_at) }}</small>
                </span>
                <span class="request-end">
                    <b class="mono">{{ fmt(request.approved_amount ?? request.amount) }}</b>
                    <small :class="`state-${request.status}`">{{ statusLabel(request.status) }}</small>
                </span>
            </article>
        </section>

        <div class="section-title wallet-section-title">
            <h3>{{ t('Transaction History') }}</h3>
            <span>{{ t('Recent Transactions') }}</span>
        </div>

        <div v-if="transactionGroups.length" class="list-card wallet-history">
            <section v-for="group in transactionGroups" :key="group.date" class="transaction-day">
                <header class="transaction-day-header">
                    <span>{{ formatDate(group.date) }}</span>
                    <b class="mono" :class="group.total >= 0 ? 'up' : 'dn'">{{ group.total >= 0 ? '+' : '-' }}{{ fmt(Math.abs(group.total)) }} {{ t('IQD') }}</b>
                </header>
                <article v-for="tx in group.transactions" :key="tx.id" class="tx-row">
                    <span class="tx-ic" :style="{ background: txMeta(tx).tint, color: txMeta(tx).color }">
                        <svg viewBox="0 0 24 24"><path :d="txIcon(txMeta(tx).icon)" /></svg>
                    </span>
                    <span class="tx-mid">
                        <b>{{ txMeta(tx).label }}</b>
                        <small :title="tx.note || ''">{{ formatDate(tx.date) }} · {{ tx.ref || t('Not specified') }}</small>
                    </span>
                    <b class="tx-amt" :class="Number(tx.direction) >= 0 ? 'up' : 'dn'">{{ Number(tx.direction) >= 0 ? '+' : '-' }}{{ fmt(tx.amount) }}</b>
                </article>
            </section>
        </div>
        <div v-else class="empty-hint">{{ t('No transactions yet') }}</div>

        <SheetModal :open="showWithdraw" :title="t('Request Withdrawal')" :subtitle="t('Administration confirms the settlement before your balance changes.')" @close="showWithdraw = false">
            <div class="field">
                <label>{{ t('Amount') }} ({{ t('Min') }} 1,000)</label>
                <input v-model="withdrawAmountInput" type="text" inputmode="numeric" :placeholder="t('Amount')" dir="ltr" />
            </div>
            <div class="field">
                <label>{{ t('Gateway') }}</label>
                <input v-model="withdraw.gateway" :placeholder="t('Gateway')" />
            </div>
            <div class="withdraw-available">
                <span>{{ t('Available Balance') }}</span>
                <b class="mono">{{ fmt(balance) }} {{ t('IQD') }}</b>
            </div>
            <button class="btn btn-primary withdraw-submit" :disabled="busy || !withdraw.amount" @click="doWithdraw">
                <span v-if="busy" class="loader"></span>{{ t('Submit Request') }}
            </button>
        </SheetModal>

        <SheetModal :open="showHandover" :title="t('Hand Over Cash')" :subtitle="t('The branch cashbox changes only after administration approval.')" @close="showHandover = false">
            <div class="field">
                <label>{{ t('Amount') }} ({{ t('Net cash available') }}: {{ fmt(cashOnHand) }})</label>
                <input v-model="handoverAmountInput" type="text" inputmode="numeric" :placeholder="t('Amount')" dir="ltr" />
            </div>
            <div class="field">
                <label>{{ t('Receiving Branch') }}</label>
                <select v-model="handover.branch_id">
                    <option value="">{{ t('Select branch') }}</option>
                    <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}{{ branch.city ? ` — ${branch.city}` : '' }}</option>
                </select>
            </div>
            <div class="field">
                <label>{{ t('Note (optional)') }}</label>
                <textarea v-model="handover.note" rows="3" :placeholder="t('Handover note')"></textarea>
            </div>
            <button class="btn btn-primary withdraw-submit" :disabled="busy || !handover.amount" @click="submitHandover">
                <span v-if="busy" class="loader"></span>{{ t('Submit Request') }}
            </button>
        </SheetModal>

        <SheetModal :open="showBudget" :title="t('Add Cash Budget')" :subtitle="t('Add the cash you currently hold. It will be available immediately for receiving merchant orders.')" @close="showBudget = false">
            <div class="field">
                <label>{{ t('Amount') }}</label>
                <input v-model="budgetCashAmountInput" type="text" inputmode="numeric" :placeholder="t('Amount')" dir="ltr" />
            </div>
            <div class="field">
                <label>{{ t('Note (optional)') }}</label>
                <textarea v-model="budgetCash.note" rows="3" :placeholder="t('Cash budget note')"></textarea>
            </div>
            <button class="btn btn-primary withdraw-submit" :disabled="busy || !budgetCash.amount" @click="submitBudget">
                <span v-if="busy" class="loader"></span>{{ t('Add Cash Budget') }}
            </button>
        </SheetModal>

        <SheetModal :open="showQiComingSoon" :title="t('Recharge Balance')" @close="showQiComingSoon = false">
            <div class="qi-coming-soon">
                <span class="qi-coming-soon-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20 7H6a2 2 0 0 1-2-2 2 2 0 0 1 2-2h13v3M20 7a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6M16 14h.01" /></svg></span>
                <b>{{ t('Qi balance top-up will be connected soon.') }}</b>
                <button class="btn btn-primary withdraw-submit" type="button" @click="showQiComingSoon = false">{{ t('Understood') }}</button>
            </div>
        </SheetModal>
    </AppShell>
</template>

<style scoped>
.wallet-card{position:relative;overflow:hidden;padding:22px 20px;border-radius:22px}.wallet-content{position:relative;z-index:1}.wallet-orbit{position:absolute;border-radius:50%;background:rgba(255,255,255,.07);pointer-events:none}.wallet-orbit-one{top:-30px;inset-inline-start:-30px;width:120px;height:120px}.wallet-orbit-two{right:-20px;bottom:-40px;width:140px;height:140px}.wc-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px}.wc-badge{font-size:10px;font-weight:800;opacity:.78}.wallet-icon,.courier-wallet-icon{display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:rgba(255,255,255,.14);color:#fff}.wallet-icon svg,.courier-wallet-icon svg,.wallet-actions svg,.courier-recharge svg,.tx-ic svg,.request-icon svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:1.85;stroke-linecap:round;stroke-linejoin:round}.wc-value{font-size:30px;font-weight:900}.wc-value span{font-family:var(--font);font-size:13px;opacity:.72}.wc-label{margin-top:3px;font-size:10.5px;font-weight:700;opacity:.72}.wallet-divider{height:1px;margin:18px 0 14px;background:rgba(255,255,255,.14)}.merchant-finance-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.merchant-finance-grid>div{min-width:0;padding:12px 13px;border:1px solid rgba(255,255,255,.1);border-radius:14px;background:rgba(255,255,255,.08)}.merchant-finance-grid span,.merchant-finance-grid small{display:block;font-size:9.5px;font-weight:700;opacity:.74}.merchant-finance-grid strong{display:block;overflow:hidden;margin-top:5px;font-size:15.5px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.merchant-finance-grid small{overflow:hidden;margin-top:4px;text-overflow:ellipsis;white-space:nowrap}.wallet-actions{display:flex;gap:9px}.wallet-actions-inline{margin-top:16px}.wallet-actions-inline button{display:flex;flex:1;align-items:center;flex-direction:row;justify-content:center;padding:12px;gap:7px;border:1px solid rgba(255,255,255,.22);border-radius:12px;background:rgba(255,255,255,.1);color:#fff;font:800 11px var(--font)}.wallet-actions-inline button.solid{border-color:#fff;background:#fff;color:var(--primary-strong)}.wallet-actions-inline button svg{width:15px;height:15px}.courier-wallet-balance{display:flex;align-items:flex-start;gap:13px;margin-bottom:14px;padding:18px 20px}.courier-wallet-icon{flex:none;background:var(--primary-tint);color:var(--primary-strong)}.courier-wallet-balance>div{min-width:0}.courier-wallet-balance span,.courier-wallet-balance strong,.courier-wallet-balance small{display:block}.courier-wallet-balance span{margin-bottom:3px;color:var(--ink-soft);font-size:11px;font-weight:750}.courier-wallet-balance strong{font-size:21px;font-weight:900}.courier-wallet-balance small{margin-top:3px;color:var(--ink-faint);font-size:9.5px;font-weight:700}.courier-wallet-balance strong small{display:inline;margin:0;font-family:var(--font);font-size:11px}.loyalty-card{overflow:hidden;margin-bottom:14px}.loyalty-head{display:flex;align-items:center;gap:10px;padding:15px}.loyalty-icon{display:grid;flex:none;width:35px;height:35px;place-items:center;border-radius:11px;background:linear-gradient(135deg,#fbbf24,#f97316);color:#fff;font-size:17px}.loyalty-head>div:nth-child(2){min-width:0;flex:1}.loyalty-head span,.loyalty-head strong{display:block}.loyalty-head span{color:var(--ink-soft);font-size:10px;font-weight:800}.loyalty-head strong{margin-top:3px;color:var(--ink);font-size:20px;font-weight:900}.loyalty-head strong small{font-size:10px;color:var(--ink-faint)}.loyalty-completed{padding-inline-start:10px;border-inline-start:1px solid var(--border);text-align:end}.loyalty-completed b,.loyalty-completed small{display:block}.loyalty-completed b{font-size:15px}.loyalty-completed small{font-size:8.5px!important}.loyalty-history{border-top:1px solid var(--border)}.loyalty-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 15px;border-bottom:1px solid var(--border)}.loyalty-row:last-child{border-bottom:0}.loyalty-row div{min-width:0}.loyalty-row b,.loyalty-row small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.loyalty-row b{font-size:10.5px}.loyalty-row small{margin-top:2px;color:var(--ink-faint);font-size:8.5px}.loyalty-row>strong{font-size:12px}.loyalty-empty{margin:0;padding:0 15px 14px;color:var(--ink-faint);font-size:9.5px;line-height:1.6}.courier-summary-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px}.courier-metric{position:relative;overflow:hidden;padding:16px;border:1px solid var(--border);border-radius:16px;background:var(--surface)}.collection-metric{border:0;background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff}.courier-metric span,.courier-metric small{display:block;font-size:10px;font-weight:750}.courier-metric span{color:var(--ink-soft)}.collection-metric span{color:rgba(255,255,255,.85)}.courier-metric strong{display:inline-block;margin-top:7px;font-size:23px;font-weight:900}.courier-metric small{display:inline-block;margin-inline-start:4px;color:var(--ink-faint)}.collection-metric small{color:rgba(255,255,255,.75)}.courier-budget-card{background:linear-gradient(135deg,var(--primary-strong),var(--primary));color:#fff}.budget-title{display:flex;align-items:center;gap:8px;margin-bottom:19px}.budget-title .wallet-icon{width:34px;height:34px;border-radius:11px}.budget-title b{font-size:13px;font-weight:900}.budget-label{display:block;font-size:10.5px;font-weight:700;opacity:.75}.budget-value{display:block;margin-top:5px;font-size:28px;font-weight:900}.budget-value small{font-family:var(--font);font-size:13px;opacity:.7}.budget-bottom-row{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:10.5px;font-weight:750}.budget-bottom-row b{font-size:12px}.courier-finance-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:16px}.courier-recharge,.courier-secondary{display:flex;align-items:center;justify-content:center;gap:6px;min-height:43px;padding:10px;border-radius:12px;font:800 10.5px var(--font)}.courier-recharge{background:#fff;color:var(--primary-strong)}.courier-secondary{border:1px solid rgba(255,255,255,.32);background:rgba(255,255,255,.1);color:#fff}.courier-secondary:disabled{opacity:.5}.courier-recharge svg{width:15px;height:15px}.finance-note{margin:9px 0 0;color:rgba(255,255,255,.75);font-size:9.5px;font-weight:650;line-height:1.65;text-align:center}.finance-requests{overflow:hidden;margin-top:17px}.finance-requests-head{display:flex;align-items:center;justify-content:space-between;padding:13px 14px;border-bottom:1px solid var(--border);background:var(--surface-2)}.finance-requests-head h3{margin:0;font-size:12px;font-weight:900}.finance-requests-head span{display:block;margin-top:2px;color:var(--ink-faint);font-size:9.5px;font-weight:700}.pending-count{display:grid;min-width:22px;height:22px;place-items:center;border-radius:999px;background:var(--warning-tint);color:var(--warning);font-size:10px}.finance-request-row{display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid var(--border)}.finance-request-row:last-child{border-bottom:0}.request-icon{display:grid;flex:none;width:32px;height:32px;place-items:center;border-radius:10px;background:var(--warning-tint);color:var(--warning)}.request-icon.approved{background:var(--success-tint);color:var(--success)}.request-icon.rejected{background:var(--danger-tint);color:var(--danger)}.request-main{flex:1;min-width:0}.request-main b,.request-main small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.request-main b{font-size:11px;font-weight:850}.request-main small{margin-top:2px;color:var(--ink-faint);font-size:9px;font-weight:700}.request-end{text-align:end}.request-end b,.request-end small{display:block}.request-end b{font-size:11px}.request-end small{margin-top:3px;font-size:9px;font-weight:850}.state-pending{color:var(--warning)}.state-approved{color:var(--success)}.state-rejected{color:var(--danger)}.wallet-section-title{margin-top:18px}.wallet-section-title>span{color:var(--ink-faint);font-size:10px;font-weight:700}.wallet-history{overflow:hidden}.transaction-day-header{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 14px;border-bottom:1px solid var(--border);background:var(--surface-2)}.transaction-day-header span{color:var(--ink-soft);font-size:10.5px;font-weight:800}.transaction-day-header b{font-size:10.5px;font-weight:850}.tx-row{display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid var(--border)}.tx-row:last-child{border-bottom:0}.tx-ic{display:grid;flex:none;width:32px;height:32px;place-items:center;border-radius:10px}.tx-mid{flex:1;min-width:0}.tx-mid b,.tx-mid small,.tx-mid span{display:block}.tx-mid b{font-size:11px;font-weight:850}.tx-mid small{overflow:hidden;margin-top:2px;color:var(--ink-soft);font-size:9.5px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}.tx-mid span{margin-top:2px;color:var(--ink-faint);font-size:8.5px}.tx-amt{font-size:12.5px;font-weight:900;direction:ltr}.up{color:var(--success)}.dn{color:var(--danger)}.empty-hint{padding:27px 10px;color:var(--ink-faint);font-size:11px;font-weight:750;text-align:center}.withdraw-available{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-radius:10px;background:var(--surface-2);color:var(--ink-soft);font-size:11px;font-weight:750}.withdraw-available b{color:var(--ink);font-size:12px}.recharge-capacity{margin-bottom:14px}.withdraw-submit{display:flex;width:100%;align-items:center;justify-content:center;gap:8px;margin-top:14px}.field{margin-bottom:13px}.field label{display:block;margin-bottom:6px;color:var(--ink-soft);font-size:10.5px;font-weight:800}.field input,.field select,.field textarea{box-sizing:border-box;width:100%;border:1px solid var(--border);border-radius:10px;background:var(--surface);color:var(--ink);font:inherit;font-size:13px;outline:none;padding:10px 11px}.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-tint)}.field textarea{resize:vertical}.field input[type=number]{font-size:16px;font-weight:800;text-align:center}@media(max-width:350px){.courier-finance-actions{grid-template-columns:1fr}.merchant-finance-grid{grid-template-columns:1fr}.wallet-actions{flex-direction:column}}
.courier-handover-link{display:block;width:100%;margin-top:9px;padding:0;border:0;background:transparent;color:rgba(255,255,255,.88);font:800 10px var(--font);text-decoration:underline;text-underline-offset:3px;cursor:pointer}

/* Courier wallet: exact hierarchy, spacing and numeric rhythm from the
   approved Al-Munjaz reference. Ledger data remains real and user-scoped. */
.courier-wallet-balance{align-items:center;gap:13px;margin-bottom:14px;padding:18px 20px;border-radius:18px;background:var(--surface);box-shadow:none}
.courier-wallet-icon{width:44px;height:44px;border-radius:13px;background:var(--primary-tint);color:var(--primary-strong)}
.courier-wallet-balance>div{flex:1;min-width:0;text-align:start}
.courier-wallet-balance>div>span{display:block;margin-bottom:3px;color:var(--ink-soft);font-size:11px;font-weight:700}
.courier-wallet-balance strong{color:var(--ink);font-size:21px;font-weight:900;letter-spacing:normal}
.courier-wallet-balance strong small{margin:0;color:var(--ink-faint);font-size:11px;font-weight:700}
.courier-overview-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px}
.courier-overview-card{position:relative;overflow:hidden;min-height:0;padding:16px;border:1px solid var(--border);border-radius:16px;background:var(--surface);text-align:start}
.courier-overview-card>span:not(.courier-overview-orb),.courier-overview-card strong{position:relative;z-index:1}
.courier-overview-card>span:not(.courier-overview-orb){display:block;margin-bottom:6px;color:var(--ink-soft);font-size:10px;font-weight:700}
.courier-overview-card strong{display:flex;align-items:baseline;gap:4px;margin-top:0;color:var(--ink);font-size:24px;font-weight:900;letter-spacing:normal}
.courier-overview-card strong small{margin:0;font-family:var(--font);font-size:10px;font-weight:700;color:var(--ink-faint)}
.courier-points-card{border:0;background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff}
.courier-points-card>span:not(.courier-overview-orb){color:rgba(255,255,255,.85)}
.courier-points-card strong{color:#fff}
.courier-points-card strong small{color:rgba(255,255,255,.75)}
.courier-overview-orb{position:absolute;top:-15px;inset-inline-end:-15px;width:60px;height:60px;border-radius:50%;background:rgba(255,255,255,.15)}
.courier-budget-card{min-height:0;padding:22px 20px;border-radius:22px;background:linear-gradient(135deg,var(--primary-strong),var(--primary))}
.courier-budget-card .wallet-orbit-one{top:-30px;inset-inline-start:-30px;width:120px;height:120px;background:rgba(255,255,255,.07)}
.courier-budget-card .wallet-orbit-two{right:-20px;bottom:-40px;width:140px;height:140px;background:rgba(255,255,255,.07)}
.budget-title{gap:8px;margin-bottom:18px}
.budget-title .wallet-icon{width:34px;height:34px;border-radius:11px;background:rgba(255,255,255,.16)}
.budget-title .wallet-icon svg{width:16px;height:16px}
.budget-title b{font-size:13px;font-weight:800}
.budget-label{font-size:10.5px;font-weight:700;opacity:.75}
.budget-value{margin-top:5px;font-size:28px;font-weight:900;letter-spacing:.3px}
.budget-value small{margin:0;font-size:13px;font-weight:inherit}
.courier-budget-card .wallet-divider{margin:16px 0 14px;background:rgba(255,255,255,.14)}
.budget-bottom-row{align-items:center;font-size:10.5px;font-weight:700}
.budget-bottom-row b{font-size:24px;font-weight:900;letter-spacing:.3px}.budget-bottom-row b::first-letter{font-size:inherit}
.courier-budget-add{display:flex;width:100%;align-items:center;justify-content:center;min-height:40px;margin-top:16px;border:1px solid rgba(255,255,255,.34);border-radius:11px;background:#fff;color:var(--primary-strong);font:850 11px var(--font);cursor:pointer}.courier-budget-add:active{transform:translateY(1px)}
.qi-coming-soon{display:grid;justify-items:center;gap:15px;padding:10px 1px 3px;text-align:center}.qi-coming-soon-icon{display:grid;width:54px;height:54px;place-items:center;border-radius:16px;background:var(--primary-tint);color:var(--primary-strong)}.qi-coming-soon-icon svg{width:24px;height:24px;fill:none;stroke:currentColor;stroke-width:1.85;stroke-linecap:round;stroke-linejoin:round}.qi-coming-soon>b{font-size:13px;font-weight:900;line-height:1.7}
.tx-row{gap:11px;padding:12px 14px}
.tx-ic{width:34px;height:34px;border-radius:10px}
.tx-mid b{font-size:12px;font-weight:700}
.tx-mid small{margin-top:2px;color:var(--ink-faint);font-size:10px;font-weight:600}
.tx-amt{font-size:12.5px;font-weight:800;direction:inherit}
</style>
