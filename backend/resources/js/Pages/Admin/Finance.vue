<script setup>
import { computed } from 'vue'
import AdminShell from '../../Components/AdminShell.vue'

const props = defineProps({
    transactions: { type: Array, default: () => [] },
    summary: { type: Object, required: true },
})

const cards = computed(() => [
    { label: t('Settlements'), value: fmt(props.summary.settlements), tint: 'var(--primary-tint)', color: 'var(--primary-strong)' },
    { label: t('Withdrawals'), value: fmt(props.summary.withdrawals), tint: 'var(--danger-tint)', color: 'var(--danger)' },
    { label: t('Fees'), value: fmt(props.summary.fees), tint: 'var(--accent-tint)', color: 'var(--accent)' },
    { label: t('Collected'), value: fmt(props.summary.collected), tint: 'var(--success-tint)', color: 'var(--success)' },
])

const typeMeta = {
    settlement: { label: t('Settlement'), tint: 'var(--primary-tint)', color: 'var(--primary-strong)' },
    withdrawal: { label: t('Withdrawal'), tint: 'var(--danger-tint)', color: 'var(--danger)' },
    delivery_fee: { label: t('Delivery Fee'), tint: 'var(--accent-tint)', color: 'var(--accent)' },
    collected: { label: t('Collected'), tint: 'var(--success-tint)', color: 'var(--success)' },
    cash_added: { label: t('Cash Added'), tint: 'var(--success-tint)', color: 'var(--success)' },
    budget_deduct: { label: t('Budget Deduct'), tint: 'var(--warning-tint)', color: 'var(--warning)' },
}

function meta(type) {
    return typeMeta[type] || { label: type, tint: 'var(--surface-2)', color: 'var(--ink-soft)' }
}
</script>

<template>
    <AdminShell :title="t('Finance')">
        <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr))">
            <div v-for="c in cards" :key="c.label" class="kpi">
                <div class="ki" :style="{ background: c.tint, color: c.color }">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 6h18v12H3z M3 10h18 M7 15h4" />
                    </svg>
                </div>
                <div>
                    <div class="kval mono">{{ c.value }}</div>
                    <div class="klab">{{ c.label }}</div>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <h3>{{ t('Transactions') }}</h3>
            </div>
            <div class="panel-body" style="padding: 0">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>{{ t('Type') }}</th>
                            <th>{{ t('User') }}</th>
                            <th>{{ t('Ref') }}</th>
                            <th>{{ t('Date') }}</th>
                            <th>{{ t('Amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="tx in transactions" :key="tx.id">
                            <td>
                                <span class="chip" :style="{ background: meta(tx.type).tint, color: meta(tx.type).color }">
                                    <i :style="{ background: meta(tx.type).color }"></i>
                                    {{ meta(tx.type).label }}
                                </span>
                            </td>
                            <td>{{ tx.user || '—' }}</td>
                            <td class="mono">{{ tx.ref }}</td>
                            <td>{{ tx.date }}</td>
                            <td>
                                <b class="mono" :class="tx.direction >= 0 ? 'up' : 'dn'" style="direction: ltr">
                                    {{ tx.direction >= 0 ? '+' : '-' }}{{ fmt(tx.amount) }}
                                </b>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!transactions.length" class="empty">{{ t('No transactions yet') }}</div>
            </div>
        </div>
    </AdminShell>
</template>
