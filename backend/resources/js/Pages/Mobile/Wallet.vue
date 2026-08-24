<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppShell from '../../Components/AppShell.vue'
import SheetModal from '../../Components/SheetModal.vue'

const props = defineProps({
    isCourier: { type: Boolean, default: false },
    balance: { type: Number, default: 0 },
    budget: { type: Number, default: 0 },
    transactions: { type: Array, default: () => [] },
})

const showWithdraw = ref(false)
const showBudget = ref(false)
const withdrawAmount = ref('')
const gateway = ref('')
const budgetAmount = ref('')
const budgetMode = ref('add')
const busy = ref(false)

const typeMap = {
    withdrawal: { label: t('Withdrawal'), tint: 'var(--danger-tint)', color: 'var(--danger)', icon: 'out' },
    cash_added: { label: t('Cash Added'), tint: 'var(--success-tint)', color: 'var(--success)', icon: 'in' },
    budget_deduct: { label: t('Budget Deduct'), tint: 'var(--warning-tint)', color: 'var(--warning)', icon: 'out' },
    settlement: { label: t('Settlement'), tint: 'var(--primary-tint)', color: 'var(--primary-strong)', icon: 'in' },
    delivery_fee: { label: t('Delivery Fee'), tint: 'var(--accent-tint)', color: 'var(--accent)', icon: 'fee' },
    collected: { label: t('Collected'), tint: 'var(--success-tint)', color: 'var(--success)', icon: 'in' },
}

function txMeta(t) {
    return typeMap[t.type] || { label: t.type, tint: 'var(--surface-2)', color: 'var(--ink-soft)', icon: 'in' }
}

function txIcon(name) {
    const paths = {
        in: 'M12 3v14m0 0 6-6m-6 6-6-6 M4 21h16',
        out: 'M12 21V7m0 0 6 6m-6-6-6 6 M4 3h16',
        fee: 'M12 3v4m0 10v4M3 12h4m10 0h4 M12 12l-1.5 3h3L12 18',
    }
    return paths[name] || paths.in
}

function doWithdraw() {
    const amount = parseInt(withdrawAmount.value, 10)
    if (!amount || amount < 1000) return
    busy.value = true
    router.post(route('app.wallet.withdraw'), { amount, gateway: gateway.value }, {
        preserveScroll: true,
        onSuccess: () => {
            showWithdraw.value = false
            withdrawAmount.value = ''
            gateway.value = ''
            busy.value = false
        },
        onFinish: () => (busy.value = false),
    })
}

function doBudget() {
    const amount = parseInt(budgetAmount.value, 10)
    if (!amount || amount < 1) return
    busy.value = true
    router.post(route('app.wallet.budget'), { amount, mode: budgetMode.value }, {
        preserveScroll: true,
        onSuccess: () => {
            showBudget.value = false
            budgetAmount.value = ''
            busy.value = false
        },
        onFinish: () => (busy.value = false),
    })
}
</script>

<template>
    <AppShell :title="t('Wallet')">
        <div class="wallet-card">
            <div class="wc-top">
                <span class="wc-badge">{{ isCourier ? t('Courier') : t('Merchant') }}</span>
                <span class="wc-badge">{{ t('Available') }}</span>
            </div>
            <div class="wc-value mono">{{ fmt(balance) }} <span style="font-size: 15px; font-weight: 700">د.ع</span></div>
            <div class="wc-label">{{ t('Total Balance') }}</div>
            <div v-if="isCourier" style="margin-top: 10px; background: rgba(255,255,255,.1); border-radius: 12px; padding: 10px 12px; display: flex; justify-content: space-between; font-size: 11.5px; font-weight: 700">
                <span>{{ t('Budget') }}</span>
                <b class="mono">{{ fmt(budget) }} د.ع</b>
            </div>
            <div class="wallet-actions">
                <button @click="showWithdraw = true">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 21V7m0 0 6 6m-6-6-6 6 M4 3h16" />
                    </svg>
                    {{ t('Withdraw') }}
                </button>
                <button v-if="isCourier" class="solid" @click="showBudget = true">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 5v14M5 12h14" />
                    </svg>
                    {{ t('Budget') }}
                </button>
            </div>
        </div>

        <div class="section-title">
            <h3>{{ t('My Statement') }}</h3>
        </div>

        <div v-if="transactions.length" class="list-card">
            <div v-for="tx in transactions" :key="tx.id" class="tx-row">
                <div class="tx-ic" :style="{ background: txMeta(tx).tint, color: txMeta(tx).color }">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="txIcon(txMeta(tx).icon)" />
                    </svg>
                </div>
                <div class="tx-mid">
                    <b>{{ txMeta(tx).label }}</b>
                    <span class="mono">{{ tx.ref }} · {{ tx.date }}</span>
                </div>
                <div class="tx-amt" :class="tx.direction >= 0 ? 'up' : 'dn'" style="direction: ltr">
                    {{ tx.direction >= 0 ? '+' : '-' }}{{ fmt(tx.amount) }}
                </div>
            </div>
        </div>
        <div v-else class="empty-hint">{{ t('No transactions yet') }}</div>

        <SheetModal :open="showWithdraw" :title="t('Withdraw')" @close="showWithdraw = false">
            <div class="field">
                <label>{{ t('Amount') }} ({{ t('Min') }} 1,000)</label>
                <input v-model="withdrawAmount" type="number" min="1000" :placeholder="t('Amount')" dir="ltr" style="text-align: center; font-size: 20px; font-weight: 800" />
            </div>
            <div class="field">
                <label>{{ t('Gateway') }}</label>
                <input v-model="gateway" :placeholder="t('Gateway')" />
            </div>
            <div v-if="balance > 0" class="detail-row" style="background: var(--surface-2); border-radius: 10px; padding: 10px 12px">
                <span class="text-muted">{{ t('Available') }}</span>
                <b class="mono">{{ fmt(balance) }} د.ع</b>
            </div>
            <button class="btn btn-primary" style="width: 100%; margin-top: 14px" :disabled="busy || !withdrawAmount" @click="doWithdraw">
                <span v-if="busy" class="loader"></span>
                {{ t('Confirm') }}
            </button>
        </SheetModal>

        <SheetModal :open="showBudget" :title="t('Budget')" @close="showBudget = false">
            <div class="seg" style="margin-bottom: 14px">
                <button :class="{ active: budgetMode === 'add' }" @click="budgetMode = 'add'">{{ t('Add') }}</button>
                <button :class="{ active: budgetMode === 'set' }" @click="budgetMode = 'set'">{{ t('Set') }}</button>
            </div>
            <div class="field">
                <label>{{ t('Amount') }}</label>
                <input v-model="budgetAmount" type="number" min="1" :placeholder="t('Amount')" dir="ltr" style="text-align: center; font-size: 20px; font-weight: 800" />
            </div>
            <button class="btn btn-primary" style="width: 100%; margin-top: 14px" :disabled="busy || !budgetAmount" @click="doBudget">
                <span v-if="busy" class="loader"></span>
                {{ t('Save') }}
            </button>
        </SheetModal>
    </AppShell>
</template>
