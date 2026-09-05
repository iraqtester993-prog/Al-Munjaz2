<script setup>
import { computed, ref } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'
import BranchFilter from '../../Components/BranchFilter.vue'
import SheetModal from '../../Components/SheetModal.vue'

const props = defineProps({
    transfers: { type: Object, required: true },
    branches: { type: Array, default: () => [] },
    transporters: { type: Array, default: () => [] },
    eligible_orders: { type: Array, default: () => [] },
    counts: { type: Object, default: () => ({}) },
    filter: { type: String, default: 'all' },
    q: { type: String, default: '' },
    canCreateTransfers: { type: Boolean, default: false },
    canDispatchTransfers: { type: Boolean, default: false },
    canReceiveTransfers: { type: Boolean, default: false },
    canViewTransferFinancials: { type: Boolean, default: false },
    branchFilter: { type: Object, default: () => ({}) },
})

const page = usePage()
const active = ref(props.filter)
const query = ref(props.q)
const composeOpen = ref(false)
const selected = ref(null)
const actionBusy = ref(null)
const createError = ref('')

const blankTransfer = () => ({
    origin_branch_id: '',
    destination_branch_id: '',
    transporter_id: '',
    order_ids: [],
    notes: '',
})

const form = useForm(blankTransfer())
const filters = [
    { key: 'all', label: 'All transfers' },
    { key: 'draft', label: 'Draft' },
    { key: 'dispatched', label: 'Dispatched' },
    { key: 'received', label: 'Received' },
]

const locale = computed(() => page.props.locale || 'ar')
const selectedTenantId = computed(() => {
    const order = props.eligible_orders.find((candidate) => form.order_ids.map(Number).includes(candidate.id))
    return order?.tenant_id || null
})

const routeCandidates = computed(() => props.eligible_orders.filter((order) =>
    Number(order.origin_branch_id) === Number(form.origin_branch_id)
    && Number(order.destination_branch_id) === Number(form.destination_branch_id)
    && (!selectedTenantId.value || Number(order.tenant_id) === Number(selectedTenantId.value))
))

const selectedOrders = computed(() => props.eligible_orders.filter((order) => form.order_ids.map(Number).includes(order.id)))
const selectedTotal = computed(() => props.canViewTransferFinancials
    ? selectedOrders.value.reduce((total, order) => total + Number(order.price || 0), 0)
    : 0)
const formError = computed(() => createError.value || Object.values(form.errors)[0] || '')

function branchName(branch) {
    if (!branch) return t('Not specified')
    const language = locale.value === 'en' ? 'en' : locale.value === 'ku' ? 'ku' : 'ar'
    return branch[`name_${language}`] || branch.name_ar || branch.name_en || branch.name_ku || branch.code || t('Not specified')
}

function statusLabel(status) {
    return t({ draft: 'Draft', dispatched: 'Dispatched', received: 'Received' }[status] || status)
}

function stageLabel(stage) {
    return t({
        awaiting_transfer: 'Awaiting transfer',
        in_transfer: 'In transfer',
        at_destination_branch: 'At destination branch',
    }[stage] || stage || 'Not specified')
}

function dateTime(value) {
    if (!value) return '—'
    try {
        return new Intl.DateTimeFormat(
            { ar: 'ar-IQ-u-nu-latn', en: 'en-US', ku: 'ku-IQ-u-nu-latn' }[locale.value] || 'en-US',
            { dateStyle: 'medium', timeStyle: 'short' },
        ).format(new Date(value))
    } catch {
        return value
    }
}

function switchFilter(key) {
    active.value = key
    router.get(route('admin.transfers'), { status: key, q: query.value || undefined, branch_id: props.branchFilter?.selected_id || undefined }, {
        preserveState: true,
        replace: true,
    })
}

function search() {
    router.get(route('admin.transfers'), { status: active.value, q: query.value || undefined, branch_id: props.branchFilter?.selected_id || undefined }, {
        preserveState: true,
        replace: true,
    })
}

function changeBranchFilter(branchId) {
    router.get(route('admin.transfers'), {
        status: active.value,
        q: query.value || undefined,
        branch_id: branchId || undefined,
    }, {
        preserveState: true,
        replace: true,
    })
}

function openComposer() {
    if (!props.canCreateTransfers) return
    createError.value = ''
    form.clearErrors()
    Object.assign(form, blankTransfer())
    composeOpen.value = true
}

function closeComposer() {
    composeOpen.value = false
    createError.value = ''
    form.clearErrors()
}

function changeRoute() {
    // A manifest belongs to one merchant tenant and one exact route. Clearing
    // selected rows here stops a route edit from accidentally merging orders.
    form.order_ids = []
    createError.value = ''
}

function submit() {
    if (!props.canCreateTransfers) return
    createError.value = ''
    if (!form.origin_branch_id || !form.destination_branch_id) {
        createError.value = t('Choose both branches before selecting orders.')
        return
    }
    if (Number(form.origin_branch_id) === Number(form.destination_branch_id)) {
        createError.value = t('Origin and destination branches must be different.')
        return
    }
    if (!form.order_ids.length) {
        createError.value = t('Select at least one order')
        return
    }

    form.transform((data) => ({
        ...data,
        origin_branch_id: Number(data.origin_branch_id),
        destination_branch_id: Number(data.destination_branch_id),
        transporter_id: Number(data.transporter_id),
        order_ids: data.order_ids.map(Number),
    })).post(route('admin.transfers.store'), {
        preserveScroll: true,
        onSuccess: () => closeComposer(),
        onFinish: () => form.transform((data) => data),
    })
}

function runAction(transfer, action) {
    const allowed = action === 'dispatch'
        ? props.canDispatchTransfers
        : action === 'receive' && props.canReceiveTransfers

    if (!allowed || actionBusy.value || !transfer) return
    const message = action === 'dispatch'
        ? t('Dispatch this transfer and move all its orders into transit?')
        : t('Confirm receipt of all orders at the destination branch?')
    if (!window.confirm(message)) return

    actionBusy.value = `${action}-${transfer.id}`
    router.post(route(action === 'dispatch' ? 'admin.transfers.dispatch' : 'admin.transfers.receive', transfer.id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            if (selected.value?.id === transfer.id) selected.value = null
        },
        onFinish: () => { actionBusy.value = null },
    })
}
</script>

<template>
    <AdminShell :title="t('Branch Transfers')">
        <section class="transfer-hero">
            <div>
                <span class="transfer-eyebrow">{{ t('Platform Operations') }}</span>
                <h2>{{ t('Branch Transfers') }}</h2>
                <p>{{ t('Create one audited manifest per merchant route, then dispatch and receive it as a real operational handoff.') }}</p>
            </div>
            <button v-if="canCreateTransfers" class="transfer-button primary" type="button" @click="openComposer">+ {{ t('Create Transfer') }}</button>
        </section>

        <div class="transfer-toolbar">
            <div class="transfer-filters">
                <button
                    v-for="item in filters"
                    :key="item.key"
                    class="transfer-filter"
                    :class="{ active: active === item.key }"
                    type="button"
                    @click="switchFilter(item.key)"
                >
                    {{ t(item.label) }} <b>{{ counts[item.key] || 0 }}</b>
                </button>
            </div>
            <div class="transfer-toolbar-end">
                <BranchFilter :filter="branchFilter" @change="changeBranchFilter" />
                <form class="transfer-search" @submit.prevent="search">
                    <input v-model="query" :placeholder="t('Search transfer reference or order')" />
                    <button type="submit" :aria-label="t('Search')">⌕</button>
                </form>
            </div>
        </div>

        <section class="panel transfer-panel">
            <div class="transfer-table-wrap">
                <table class="tbl transfer-table">
                    <thead>
                        <tr>
                            <th>{{ t('Transfer Reference') }}</th>
                            <th>{{ t('Transfer route') }}</th>
                            <th>{{ t('Orders') }}</th>
                            <th>{{ t('Transporter') }}</th>
                            <th>{{ t('Transfer Status') }}</th>
                            <th>{{ t('Created at') }}</th>
                            <th>{{ t('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="transfer in transfers.data" :key="transfer.id" class="transfer-row" @click="selected = transfer">
                            <td><b class="mono transfer-reference">{{ transfer.reference }}</b><small v-if="transfer.notes">{{ transfer.notes }}</small></td>
                            <td>
                                <b>{{ branchName(transfer.origin_branch) }}</b>
                                <small>{{ branchName(transfer.destination_branch) }}</small>
                            </td>
                            <td><b>{{ transfer.orders?.length || 0 }}</b><small>{{ transfer.orders?.map((order) => order.track_no).join(' · ') }}</small></td>
                            <td><b>{{ transfer.transporter?.name || t('No transporter assigned') }}</b><small v-if="transfer.transporter?.phone" class="mono">{{ transfer.transporter.phone }}</small></td>
                            <td><span class="transfer-status" :class="transfer.status">{{ statusLabel(transfer.status) }}</span></td>
                            <td><span class="mono">{{ dateTime(transfer.created_at) }}</span></td>
                            <td class="transfer-actions" @click.stop>
                                <button class="transfer-button ghost compact" type="button" @click="selected = transfer">{{ t('View Details') }}</button>
                                <button v-if="transfer.status === 'draft' && canDispatchTransfers" class="transfer-button dispatch compact" type="button" :disabled="actionBusy" @click="runAction(transfer, 'dispatch')">{{ t('Dispatch Transfer') }}</button>
                                <button v-if="transfer.status === 'dispatched' && canReceiveTransfers" class="transfer-button receive compact" type="button" :disabled="actionBusy" @click="runAction(transfer, 'receive')">{{ t('Receive Transfer') }}</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="!transfers.data.length" class="transfer-empty">{{ t('No branch transfers match this filter.') }}</div>
        </section>

        <div v-if="transfers.last_page > 1" class="transfer-pagination">
            <button class="transfer-button ghost compact" type="button" :disabled="!transfers.prev_page_url" @click="router.get(transfers.prev_page_url, {}, { preserveState: true })">←</button>
            <span>{{ transfers.current_page }} / {{ transfers.last_page }}</span>
            <button class="transfer-button ghost compact" type="button" :disabled="!transfers.next_page_url" @click="router.get(transfers.next_page_url, {}, { preserveState: true })">→</button>
        </div>

        <SheetModal v-if="canCreateTransfers" :open="composeOpen" :title="t('Create Transfer')" :subtitle="t('A transfer is restricted to one merchant and one exact branch route.')" :wide="true" @close="closeComposer">
            <form class="transfer-form" @submit.prevent="submit">
                <div class="transfer-form-grid">
                    <label>
                        <span>{{ t('Origin Branch') }}</span>
                        <PopupSelect v-model="form.origin_branch_id" required @change="changeRoute">
                            <option value="" disabled>{{ t('Select branch') }}</option>
                            <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branchName(branch) }} — {{ branch.city }}</option>
                        </PopupSelect>
                    </label>
                    <label>
                        <span>{{ t('Destination Branch') }}</span>
                        <PopupSelect v-model="form.destination_branch_id" required @change="changeRoute">
                            <option value="" disabled>{{ t('Select branch') }}</option>
                            <option v-for="branch in branches" :key="branch.id" :value="branch.id" :disabled="Number(branch.id) === Number(form.origin_branch_id)">{{ branchName(branch) }} — {{ branch.city }}</option>
                        </PopupSelect>
                    </label>
                    <label>
                        <span>{{ t('Transporter') }}</span>
                        <PopupSelect v-model="form.transporter_id" required>
                            <option value="" disabled>{{ t('Select transporter') }}</option>
                            <option v-for="transporter in transporters" :key="transporter.id" :value="transporter.id">{{ transporter.name }} — {{ transporter.phone }}</option>
                        </PopupSelect>
                    </label>
                    <label>
                        <span>{{ t('Transfer notes') }}</span>
                        <input v-model="form.notes" maxlength="1000" :placeholder="t('Optional operational note')" />
                    </label>
                </div>

                <section class="order-picker">
                    <header>
                        <div><b>{{ t('Eligible orders') }}</b><span>{{ form.origin_branch_id && form.destination_branch_id ? t('Only awaiting-transfer orders for the selected route are shown.') : t('Choose both branches to list the eligible orders.') }}</span></div>
                        <strong class="mono">
                            {{ selectedOrders.length }} {{ t('Orders') }}
                            <template v-if="canViewTransferFinancials"> · {{ fmt(selectedTotal) }} {{ t('IQD') }}</template>
                        </strong>
                    </header>
                    <div v-if="routeCandidates.length" class="order-picker-list">
                        <label v-for="order in routeCandidates" :key="order.id" class="order-picker-row" :class="{ 'without-financials': !canViewTransferFinancials }">
                            <input v-model="form.order_ids" type="checkbox" :value="order.id" />
                            <span class="order-picker-main"><b class="mono">{{ order.track_no }}</b><small>{{ order.customer }} · {{ order.merchant || order.tenant }}</small></span>
                            <span class="order-picker-stage">{{ stageLabel(order.workflow_stage) }}</span>
                            <strong v-if="canViewTransferFinancials" class="mono">{{ fmt(order.price) }}</strong>
                        </label>
                    </div>
                    <div v-else class="transfer-empty compact-empty">{{ form.origin_branch_id && form.destination_branch_id ? t('No orders are awaiting this route.') : t('Choose both branches to list the eligible orders.') }}</div>
                </section>

                <p v-if="formError" class="transfer-error" role="alert">{{ formError }}</p>
                <footer class="transfer-form-footer">
                    <button class="transfer-button ghost" type="button" @click="closeComposer">{{ t('Cancel') }}</button>
                    <button class="transfer-button primary" type="submit" :disabled="form.processing || !form.order_ids.length">{{ t('Create Transfer') }}</button>
                </footer>
            </form>
        </SheetModal>

        <SheetModal :open="!!selected" :title="selected?.reference" :subtitle="selected ? `${branchName(selected.origin_branch)} → ${branchName(selected.destination_branch)}` : ''" :wide="true" @close="selected = null">
            <section v-if="selected" class="transfer-detail">
                <div class="transfer-detail-meta">
                    <div><span>{{ t('Transfer Status') }}</span><b><i class="transfer-status" :class="selected.status">{{ statusLabel(selected.status) }}</i></b></div>
                    <div><span>{{ t('Transporter') }}</span><b>{{ selected.transporter?.name || t('No transporter assigned') }}</b></div>
                    <div><span>{{ t('Created at') }}</span><b class="mono">{{ dateTime(selected.created_at) }}</b></div>
                    <div v-if="selected.dispatched_at"><span>{{ t('Dispatched at') }}</span><b class="mono">{{ dateTime(selected.dispatched_at) }}</b></div>
                    <div v-if="selected.received_at"><span>{{ t('Received at') }}</span><b class="mono">{{ dateTime(selected.received_at) }}</b></div>
                </div>
                <p v-if="selected.notes" class="transfer-note"><b>{{ t('Notes') }}:</b> {{ selected.notes }}</p>
                <h4>{{ t('Orders in transfer') }}</h4>
                <div class="transfer-detail-orders">
                    <article v-for="order in selected.orders" :key="order.id">
                        <div><b class="mono">{{ order.track_no }}</b><span>{{ order.customer }} · {{ order.merchant || order.tenant }}</span></div>
                        <div><small>{{ stageLabel(order.workflow_stage) }}</small><strong v-if="canViewTransferFinancials" class="mono">{{ fmt(order.price) }} {{ t('IQD') }}</strong></div>
                    </article>
                </div>
                <div class="transfer-detail-actions">
                    <button v-if="selected.status === 'draft' && canDispatchTransfers" class="transfer-button dispatch" type="button" :disabled="actionBusy" @click="runAction(selected, 'dispatch')">{{ t('Dispatch Transfer') }}</button>
                    <button v-if="selected.status === 'dispatched' && canReceiveTransfers" class="transfer-button receive" type="button" :disabled="actionBusy" @click="runAction(selected, 'receive')">{{ t('Receive Transfer') }}</button>
                </div>
            </section>
        </SheetModal>
    </AdminShell>
</template>

<style scoped>
.order-picker-row.without-financials{grid-template-columns:18px minmax(0,1fr) auto}
.transfer-hero{display:flex;align-items:end;justify-content:space-between;gap:18px;margin-bottom:20px}.transfer-eyebrow{display:block;color:var(--primary-strong);font-size:10px;font-weight:900;letter-spacing:.09em;text-transform:uppercase}.transfer-hero h2{margin:4px 0 0;font-size:23px;letter-spacing:-.03em}.transfer-hero p{max-width:620px;margin:6px 0 0;color:var(--ink-faint);font-size:12px;line-height:1.7}.transfer-button{border:1px solid transparent;border-radius:10px;padding:9px 13px;background:var(--surface-2);color:var(--ink);font:inherit;font-size:11.5px;font-weight:850;cursor:pointer;white-space:nowrap;transition:transform .16s ease,opacity .16s ease}.transfer-button:hover:not(:disabled){transform:translateY(-1px)}.transfer-button:disabled{cursor:wait;opacity:.58}.transfer-button.primary{background:var(--primary);color:#05202b}.transfer-button.ghost{border-color:var(--border);background:var(--surface-2)}.transfer-button.dispatch{background:var(--warning-tint);color:var(--warning)}.transfer-button.receive{background:var(--success-tint);color:var(--success)}.transfer-button.compact{padding:7px 9px;font-size:10px}.transfer-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.transfer-toolbar-end{display:flex;align-items:end;justify-content:flex-end;gap:10px}.transfer-filters{display:flex;gap:7px;overflow:auto;padding-bottom:2px}.transfer-filter{border:1px solid var(--border);border-radius:999px;padding:7px 10px;color:var(--ink-soft);background:var(--surface);font:inherit;font-size:10.5px;font-weight:800;white-space:nowrap;cursor:pointer}.transfer-filter b{display:inline-grid;place-items:center;min-width:16px;height:16px;margin-inline-start:4px;border-radius:99px;color:var(--ink-faint);background:var(--surface-2);font-size:9px}.transfer-filter.active{border-color:transparent;color:#05202b;background:var(--primary)}.transfer-filter.active b{color:#05202b;background:rgba(255,255,255,.38)}.transfer-search{display:flex;align-items:center;min-width:min(310px,100%);border:1px solid var(--border);border-radius:10px;background:var(--surface)}.transfer-search input{min-width:0;flex:1;border:0;outline:0;padding:9px 11px;color:var(--ink);background:transparent;font:inherit;font-size:11px}.transfer-search button{width:37px;border:0;border-inline-start:1px solid var(--border);color:var(--primary-strong);background:transparent;font-size:19px;cursor:pointer}.transfer-panel{overflow:hidden}.transfer-table-wrap{overflow:auto}.transfer-table{min-width:1110px}.transfer-row{cursor:pointer}.transfer-row:hover{background:var(--surface-2)}.transfer-table td{vertical-align:middle}.transfer-table td small{display:block;max-width:200px;margin-top:3px;overflow:hidden;color:var(--ink-faint);font-size:9.5px;line-height:1.4;text-overflow:ellipsis;white-space:nowrap}.transfer-reference{color:var(--primary-strong);font-size:11.5px}.transfer-status{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:5px 8px;font-size:10px;font-style:normal;font-weight:850;white-space:nowrap}.transfer-status.draft{color:var(--warning);background:var(--warning-tint)}.transfer-status.dispatched{color:var(--primary-strong);background:var(--primary-tint)}.transfer-status.received{color:var(--success);background:var(--success-tint)}.transfer-actions{min-width:226px}.transfer-actions .transfer-button+.transfer-button{margin-inline-start:5px}.transfer-empty{padding:34px 18px;color:var(--ink-faint);font-size:12px;font-weight:700;text-align:center}.transfer-pagination{display:flex;align-items:center;justify-content:center;gap:8px;color:var(--ink-soft);font-size:11px;font-weight:800;margin-top:16px}.transfer-form{display:grid;gap:16px}.transfer-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.transfer-form label{display:grid;gap:6px;color:var(--ink-soft);font-size:10.5px;font-weight:850}.transfer-form input,.transfer-form select{width:100%;box-sizing:border-box;border:1px solid var(--border);border-radius:10px;outline:0;padding:10px;color:var(--ink);background:var(--surface-2);font:inherit;font-size:12px}.transfer-form input:focus,.transfer-form select:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-tint)}.order-picker{overflow:hidden;border:1px solid var(--border);border-radius:13px;background:var(--surface)}.order-picker header{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 13px;border-bottom:1px solid var(--border);background:var(--surface-2)}.order-picker header div{display:grid;gap:2px}.order-picker header b{font-size:11.5px}.order-picker header span{color:var(--ink-faint);font-size:9.5px;font-weight:700;line-height:1.45}.order-picker header strong{color:var(--primary-strong);font-size:10.5px;white-space:nowrap}.order-picker-list{max-height:265px;overflow:auto}.order-picker-row{display:grid!important;grid-template-columns:18px minmax(0,1fr) auto auto;align-items:center;gap:10px;padding:10px 13px;border-bottom:1px solid var(--border);cursor:pointer}.order-picker-row:last-child{border-bottom:0}.order-picker-row:hover{background:var(--surface-2)}.order-picker-row input{width:15px;height:15px;padding:0;accent-color:var(--primary);box-shadow:none}.order-picker-main{min-width:0}.order-picker-main b,.order-picker-main small{display:block}.order-picker-main b{font-size:11px}.order-picker-main small{margin-top:2px;overflow:hidden;color:var(--ink-faint);font-size:9.5px;text-overflow:ellipsis;white-space:nowrap}.order-picker-stage{border-radius:999px;padding:4px 7px;color:var(--primary-strong);background:var(--primary-tint);font-size:9px;font-weight:800}.order-picker-row strong{font-size:10.5px}.compact-empty{padding:22px}.transfer-error{margin:0;color:var(--danger);font-size:11px;font-weight:800}.transfer-form-footer{display:flex;justify-content:flex-end;gap:8px}.transfer-detail{display:grid;gap:15px}.transfer-detail-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}.transfer-detail-meta>div{min-width:0;padding:10px;border:1px solid var(--border);border-radius:11px;background:var(--surface-2)}.transfer-detail-meta span,.transfer-detail-meta b{display:block}.transfer-detail-meta span{color:var(--ink-faint);font-size:9px;font-weight:800}.transfer-detail-meta b{margin-top:4px;overflow:hidden;color:var(--ink);font-size:10.5px;text-overflow:ellipsis;white-space:nowrap}.transfer-note{margin:0;padding:10px 12px;border-radius:10px;color:var(--ink-soft);background:var(--surface-2);font-size:11px;line-height:1.7}.transfer-detail h4{margin:0;font-size:12px}.transfer-detail-orders{display:grid;gap:8px}.transfer-detail-orders article{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px;border:1px solid var(--border);border-radius:11px;background:var(--surface)}.transfer-detail-orders article>div{min-width:0}.transfer-detail-orders b,.transfer-detail-orders span,.transfer-detail-orders small,.transfer-detail-orders strong{display:block}.transfer-detail-orders b{color:var(--primary-strong);font-size:11px}.transfer-detail-orders span,.transfer-detail-orders small{margin-top:3px;color:var(--ink-faint);font-size:9.5px}.transfer-detail-orders strong{margin-top:4px;color:var(--ink);font-size:10.5px;text-align:end}.transfer-detail-actions{display:flex;justify-content:flex-end;gap:8px}@media(max-width:760px){.transfer-hero{align-items:stretch;flex-direction:column}.transfer-hero .primary{align-self:stretch}.transfer-toolbar,.transfer-toolbar-end{align-items:stretch;flex-direction:column}.transfer-search{min-width:0}.transfer-form-grid,.transfer-detail-meta{grid-template-columns:1fr}.order-picker header{align-items:start;flex-direction:column}.order-picker-row{grid-template-columns:18px minmax(0,1fr) auto}.order-picker-stage{display:none}.transfer-detail-orders article{align-items:start;flex-direction:column}.transfer-detail-orders strong{text-align:start}}
</style>
