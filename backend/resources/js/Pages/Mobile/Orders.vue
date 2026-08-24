<script setup>
import { ref, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppShell from '../../Components/AppShell.vue'
import StatusBadge from '../../Components/StatusBadge.vue'
import SheetModal from '../../Components/SheetModal.vue'
import OrderForm from '../../Components/OrderForm.vue'

const props = defineProps({
    orders: { type: Array, required: true },
    counts: { type: Object, required: true },
    filter: { type: String, default: 'all' },
    q: { type: String, default: '' },
    isCourier: { type: Boolean, default: false },
    wallet: { type: Object, default: () => ({ balance: 0, budget: 0 }) },
})

const page = usePage()
const user = computed(() => page.props.auth?.user)

const query = ref(props.q)
const active = ref(props.filter)
const selected = ref(null)
const showForm = ref(false)
const editing = ref(null)
const busy = ref(null)

const filters = computed(() => {
    const list = [{ key: 'all', label: t('All') }]
    for (const s of ['pending', 'approved', 'courier', 'delivered', 'returned']) {
        list.push({ key: s, label: tStatus(s) })
    }
    return list
})

function tStatus(s) {
    const m = { pending: t('Pending'), approved: t('Approved'), courier: t('With Courier'), delivered: t('Delivered'), returned: t('Returned') }
    return m[s] || s
}

function changeFilter(key) {
    active.value = key
    router.get(route('app.orders'), { filter: key, q: query.value }, { preserveState: true, replace: true })
}

function doSearch() {
    router.get(route('app.orders'), { filter: active.value, q: query.value }, { preserveState: true, replace: true })
}

function openOrder(o) {
    selected.value = o
}

function setStatus(order, status) {
    if (busy.value) return
    busy.value = order.id
    router.post(
        route('app.orders.status', order.id),
        { status },
        {
            preserveScroll: true,
            onSuccess: () => {
                selected.value = { ...selected.value, status }
                busy.value = null
            },
            onFinish: () => (busy.value = null),
        }
    )
}

function canAct(order) {
    if (props.isCourier) {
        if (order.status === 'approved') return ['courier']
        if (order.status === 'courier') return ['delivered', 'returned']
        return []
    }
    if (order.status === 'pending') return ['approved']
    return []
}

function actionsFor(order) {
    const acts = {
        approved: { label: t('Accept'), next: 'approved' },
        courier: { label: t('Start Delivery'), next: 'courier' },
        delivered: { label: t('Mark Delivered'), next: 'delivered', kind: 'success' },
        returned: { label: t('Mark Returned'), next: 'returned', kind: 'danger' },
    }
    return canAct(order).map((s) => ({
        label: s === 'approved' ? t('Accept') : s === 'courier' ? t('Start Delivery') : s === 'delivered' ? t('Mark Delivered') : t('Mark Returned'),
        next: s,
        kind: s === 'returned' ? 'danger' : s === 'delivered' ? 'success' : 'primary',
    }))
}

function openEdit() {
    if (!selected.value) return
    editing.value = selected.value
    showForm.value = true
}

const STATUS_FLOW = ['pending', 'approved', 'courier', 'delivered']

function flowIndex(status) {
    const i = STATUS_FLOW.indexOf(status)
    return i >= 0 ? i : (status === 'returned' ? 0 : 0)
}

const steps = computed(() => {
    if (!selected.value) return []
    const cur = flowIndex(selected.value.status)
    return STATUS_FLOW.map((s, i) => ({
        key: s,
        label: tStatus(s),
        done: selected.value.status === 'delivered' ? i < cur + 1 : i < cur,
        current: i === cur && selected.value.status !== 'returned',
    }))
})
</script>

<template>
    <AppShell :title="isCourier ? t('My Deliveries') : t('My Orders')">
        <template #title>
            {{ isCourier ? t('My Deliveries') : t('My Orders') }}
            <span v-if="isCourier" class="tb-sub">{{ t('Available') }}: {{ fmt(wallet.budget) }} / {{ fmt(wallet.balance) }} د.ع</span>
        </template>

        <div class="status-scroll">
            <button v-for="f in filters" :key="f.key" class="status-chip-pill" :class="{ active: active === f.key }" @click="changeFilter(f.key)">
                {{ f.label }}
                <b>{{ counts[f.key] ?? 0 }}</b>
            </button>
        </div>

        <div class="search" style="max-width: 100%; margin-bottom: 12px">
            <input v-model="query" :placeholder="t('Search')" @keyup.enter="doSearch" />
        </div>

        <div v-if="orders.length" class="list-card">
            <div v-for="o in orders" :key="o.id" class="order-row" @click="openOrder(o)">
                <div class="order-ic">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 8 12 3 3 8v8l9 5 9-5V8Z M3 8l9 5 9-5 M12 13v8" />
                    </svg>
                </div>
                <div class="order-mid">
                    <b>{{ o.customer_name_ar }}</b>
                    <span class="mono">{{ o.track_no }} · {{ o.phone }}</span>
                </div>
                <div class="order-end">
                    <b class="mono">{{ fmt(o.price) }}</b>
                    <StatusBadge :status="o.status" style="margin-top: 5px" />
                </div>
            </div>
        </div>
        <div v-else class="empty-hint">{{ t('No orders found') }}</div>

        <template #fab>
            <button v-if="!isCourier" class="fab" @click="editing = null; showForm = true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round">
                    <path d="M12 5v14M5 12h14" />
                </svg>
            </button>
        </template>

        <SheetModal :open="!!selected" :title="selected?.track_no" :subtitle="selected?.customer_name_ar" @close="selected = null">
            <template v-if="selected">
                <div class="detail-row">
                    <span class="text-muted">{{ t('Status') }}</span>
                    <StatusBadge :status="selected.status" />
                </div>
                <div class="detail-row">
                    <span class="text-muted">{{ t('Customer') }}</span>
                    <b>{{ selected.customer_name_ar }}</b>
                </div>
                <div class="detail-row">
                    <span class="text-muted">{{ t('Phone') }}</span>
                    <b class="mono">{{ selected.phone }}{{ selected.phone2 ? ' / ' + selected.phone2 : '' }}</b>
                </div>
                <div class="detail-row">
                    <span class="text-muted">{{ t('Address') }}</span>
                    <b>{{ selected.address_ar }}</b>
                </div>
                <div class="detail-row">
                    <span class="text-muted">{{ t('Price') }}</span>
                    <b class="mono">{{ fmt(selected.price) }} د.ع</b>
                </div>
                <div class="detail-row">
                    <span class="text-muted">{{ t('Date') }}</span>
                    <b>{{ selected.date }}</b>
                </div>
                <div v-if="selected.courier" class="detail-row">
                    <span class="text-muted">{{ t('Courier') }}</span>
                    <b>{{ selected.courier.name }}</b>
                </div>

                <div v-if="selected.status !== 'returned'" class="sheet-route" style="margin: 14px 0">
                    <div v-for="s in steps" :key="s.key" class="sheet-step" :class="{ done: s.done, current: s.current }">
                        <span class="sheet-line"></span>
                        <span class="sheet-node">{{ s.done ? '✓' : '' }}</span>
                        <span class="sheet-label">{{ s.label }}</span>
                    </div>
                </div>

                <div v-if="actionsFor(selected).length" class="deliv-actions" style="margin-top: 6px">
                    <button v-for="a in actionsFor(selected)" :key="a.next" class="mini-btn" :class="a.kind" :disabled="busy === selected.id" @click="setStatus(selected, a.next)">
                        <span v-if="busy === selected.id" class="loader"></span>
                        {{ a.label }}
                    </button>
                </div>

                <button v-if="!isCourier" class="btn btn-ghost" style="width: 100%; margin-top: 10px" @click="openEdit">{{ t('Edit') }}</button>
            </template>
        </SheetModal>

        <OrderForm :open="showForm" :order="editing" @close="showForm = false" />
    </AppShell>
</template>
