<script setup>
import { ref, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'
import StatusBadge from '../../Components/StatusBadge.vue'
import SheetModal from '../../Components/SheetModal.vue'

const props = defineProps({
    orders: { type: Object, required: true },
    counts: { type: Object, required: true },
    filter: { type: String, default: 'all' },
    q: { type: String, default: '' },
    couriers: { type: Array, default: () => [] },
})

const page = usePage()
const query = ref(props.q)
const active = ref(props.filter)
const assignFor = ref(null)
const assignCourier = ref('')
const busyId = ref(null)

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

function apply() {
    router.get(route('admin.orders'), { filter: active.value, q: query.value }, { preserveState: true, replace: true })
}

function setStatus(order, status) {
    if (busyId.value) return
    busyId.value = order.id
    router.post(
        route('admin.orders.status', order.id),
        { status },
        {
            preserveScroll: true,
            onFinish: () => (busyId.value = null),
        }
    )
}

function openAssign(order) {
    assignFor.value = order
    assignCourier.value = ''
}

function doAssign() {
    if (!assignCourier.value || !assignFor.value) return
    busyId.value = assignFor.value.id
    router.post(
        route('admin.orders.courier', assignFor.value.id),
        { courier_id: assignCourier.value },
        {
            preserveScroll: true,
            onSuccess: () => (assignFor.value = null),
            onFinish: () => (busyId.value = null),
        }
    )
}

const statusOptions = ['pending', 'approved', 'courier', 'delivered', 'returned']
</script>

<template>
    <AdminShell title="Orders">
        <div class="filter-bar">
            <button v-for="f in filters" :key="f.key" class="fbtn" :class="{ active: active === f.key }" @click="active = f.key; apply()">
                {{ f.label }} <span class="cnt">{{ counts[f.key] ?? 0 }}</span>
            </button>
            <div class="search">
                <input v-model="query" :placeholder="t('Search')" @keyup.enter="apply" />
            </div>
        </div>

        <div class="panel">
            <div class="panel-body" style="padding: 0">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>{{ t('Order') }}</th>
                            <th>{{ t('Customer') }}</th>
                            <th>{{ t('Price') }}</th>
                            <th>{{ t('Status') }}</th>
                            <th>{{ t('Courier') }}</th>
                            <th>{{ t('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="o in orders.data" :key="o.id">
                            <td class="mono" style="font-weight: 800">{{ o.track_no }}</td>
                            <td>
                                <div class="user-cell">
                                    <div>
                                        <b>{{ o.customer_name_ar }}</b>
                                        <div class="text-muted mono" style="font-size: 10px">{{ o.phone }}</div>
                                    </div>
                                </div>
                            </td>
                            <td><b class="mono">{{ fmt(o.price) }}</b></td>
                            <td><StatusBadge :status="o.status" /></td>
                            <td>
                                <span v-if="o.courier">{{ o.courier.name }}</span>
                                <span v-else class="text-muted">—</span>
                            </td>
                            <td>
                                <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap">
                                    <select
                                        class="fbtn mini"
                                        :value="o.status"
                                        :disabled="busyId === o.id"
                                        style="appearance: auto"
                                        @change="setStatus(o, $event.target.value)"
                                    >
                                        <option v-for="s in statusOptions" :key="s" :value="s">{{ tStatus(s) }}</option>
                                    </select>
                                    <button v-if="o.status === 'pending'" class="fbtn mini" @click="openAssign(o)">{{ t('Assign') }}</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!orders.data.length" class="empty">{{ t('No orders found') }}</div>
            </div>
        </div>

        <div v-if="orders.last_page > 1" class="filter-bar">
            <button class="fbtn" :disabled="!orders.prev_page_url" @click="router.get(orders.prev_page_url, {}, { preserveState: true })">←</button>
            <span class="fbtn" style="cursor: default">{{ orders.current_page }} / {{ orders.last_page }}</span>
            <button class="fbtn" :disabled="!orders.next_page_url" @click="router.get(orders.next_page_url, {}, { preserveState: true })">→</button>
        </div>

        <SheetModal :open="!!assignFor" :title="t('Assign Courier')" :subtitle="assignFor?.track_no" @close="assignFor = null">
            <div class="field">
                <label>{{ t('Courier') }}</label>
                <select v-model="assignCourier">
                    <option value="" disabled>{{ t('Select courier') }}</option>
                    <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }} — {{ c.phone }}</option>
                </select>
            </div>
            <button class="btn btn-primary" style="width: 100%" :disabled="!assignCourier || busyId" @click="doAssign">
                {{ t('Confirm') }}
            </button>
        </SheetModal>
    </AdminShell>
</template>
