<script setup>
import { computed, ref } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'

const props = defineProps({ branches: { type: Array, default: () => [] } })
const page = usePage()
const modalOpen = ref(false)
const editing = ref(null)
const changingBranchId = ref(null)
const actionError = ref('')

const blankBranch = () => ({
    code: '',
    name_ar: '',
    name_en: '',
    name_ku: '',
    city: '',
    phone: '',
    address: '',
})

const form = useForm(blankBranch())
const formError = computed(() => Object.values(form.errors)[0] || '')
const activeBranches = computed(() => props.branches.filter((branch) => branch.is_active).length)
const routeTotals = computed(() => props.branches.reduce((total, branch) => total + Number(branch.inbound_orders_count || 0) + Number(branch.outbound_orders_count || 0), 0))

function locale() {
    return page.props.locale || 'ar'
}

function branchName(branch) {
    return branch[`name_${locale()}`] || branch.name_ar || branch.name_en || branch.name_ku || branch.code
}

function secondaryNames(branch) {
    const primary = branchName(branch)
    return [branch.name_ar, branch.name_en, branch.name_ku]
        .filter((name, index, names) => name && name !== primary && names.indexOf(name) === index)
        .join(' · ')
}

function openCreate() {
    editing.value = null
    actionError.value = ''
    form.clearErrors()
    Object.assign(form, blankBranch())
    modalOpen.value = true
}

function openEdit(branch) {
    editing.value = branch
    actionError.value = ''
    form.clearErrors()
    Object.assign(form, {
        code: branch.code || '',
        name_ar: branch.name_ar || '',
        name_en: branch.name_en || '',
        name_ku: branch.name_ku || '',
        city: branch.city || '',
        phone: branch.phone || '',
        address: branch.address || '',
    })
    modalOpen.value = true
}

function closeModal() {
    modalOpen.value = false
    editing.value = null
    form.clearErrors()
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            closeModal()
            Object.assign(form, blankBranch())
        },
    }

    if (editing.value) {
        form.put(route('admin.branches.update', editing.value.id), options)
        return
    }

    form.post(route('admin.branches.store'), options)
}

function toggleStatus(branch) {
    actionError.value = ''
    changingBranchId.value = branch.id

    router.patch(route('admin.branches.status', branch.id), {
        is_active: !branch.is_active,
    }, {
        preserveScroll: true,
        onError: (errors) => {
            actionError.value = errors.is_active || errors.branch || t('Unable to update branch status.')
        },
        onFinish: () => {
            changingBranchId.value = null
        },
    })
}
</script>

<template>
    <AdminShell :title="t('Branches')">
        <div class="section-head">
            <div>
                <div class="eyebrow">{{ activeBranches }} / {{ branches.length }} {{ t('Active') }}</div>
                <h2>{{ t('Branches') }}</h2>
                <p>{{ t('Manage branches and cashbox balances') }}</p>
            </div>
            <div class="head-actions">
                <span class="route-total"><b>{{ routeTotals }}</b> {{ t('Branch route records') }}</span>
                <button class="btn primary" type="button" @click="openCreate">+ {{ t('New Branch') }}</button>
            </div>
        </div>

        <p v-if="actionError" class="page-error" role="alert">{{ actionError }}</p>

        <div class="branch-grid">
            <article v-for="branch in branches" :key="branch.id" class="branch-card" :class="{ inactive: !branch.is_active }">
                <div class="branch-head">
                    <div class="branch-title-row">
                        <span class="branch-icon" aria-hidden="true">⌂</span>
                        <div>
                            <h3>{{ branchName(branch) }}</h3>
                            <p class="branch-place">{{ branch.city }} <span v-if="branch.code">· {{ branch.code }}</span></p>
                        </div>
                    </div>
                    <span class="state" :class="{ off: !branch.is_active }">{{ branch.is_active ? t('Active') : t('Inactive') }}</span>
                </div>

                <p v-if="secondaryNames(branch)" class="alternate-names">{{ secondaryNames(branch) }}</p>
                <div class="branch-contact">
                    <span v-if="branch.phone">{{ branch.phone }}</span>
                    <span v-if="branch.address">{{ branch.address }}</span>
                </div>

                <div class="branch-flow" :aria-label="t('Branch route records')">
                    <div><span>{{ t('Outgoing Orders') }}</span><b>{{ branch.outbound_orders_count }}</b></div>
                    <div><span>{{ t('Incoming Orders') }}</span><b>{{ branch.inbound_orders_count }}</b></div>
                    <div><span>{{ t('Users') }}</span><b>{{ branch.users_count }}</b></div>
                </div>

                <div class="cash">
                    <span>{{ t('Cashbox Balance') }}</span>
                    <b class="mono">{{ fmt(branch.cash_balance) }} {{ t('IQD') }}</b>
                </div>

                <div class="branch-actions">
                    <button class="btn ghost" type="button" @click="openEdit(branch)">{{ t('Edit') }}</button>
                    <button
                        class="btn status-action"
                        :class="branch.is_active ? 'danger' : 'primary'"
                        type="button"
                        :disabled="changingBranchId === branch.id"
                        @click="toggleStatus(branch)"
                    >
                        {{ branch.is_active ? t('Deactivate Branch') : t('Activate Branch') }}
                    </button>
                </div>
            </article>
            <div v-if="!branches.length" class="panel empty">{{ t('No branches yet. Add your first branch to start operations.') }}</div>
        </div>

        <div v-if="modalOpen" class="modal-backdrop" @click.self="closeModal">
            <form class="branch-modal" @submit.prevent="submit">
                <header>
                    <div>
                        <span class="modal-kicker">{{ t('Platform Operations') }}</span>
                        <h3>{{ editing ? t('Edit Branch') : t('New Branch') }}</h3>
                    </div>
                    <button type="button" :aria-label="t('Close')" @click="closeModal">×</button>
                </header>

                <div class="form-grid">
                    <label>{{ t('Branch Code') }}<input v-model="form.code" required maxlength="20" placeholder="BGD-HQ" /></label>
                    <label>{{ t('Governorate / City') }}<input v-model="form.city" required maxlength="60" :placeholder="t('Baghdad')" /></label>
                </div>

                <fieldset>
                    <legend>{{ t('Branch names') }}</legend>
                    <label>{{ t('Arabic Branch Name') }}<input v-model="form.name_ar" required maxlength="120" :placeholder="t('Main Baghdad Branch')" /></label>
                    <label>{{ t('English Branch Name') }}<input v-model="form.name_en" maxlength="120" placeholder="Baghdad Main Branch" /></label>
                    <label>{{ t('Kurdish Branch Name') }}<input v-model="form.name_ku" maxlength="120" placeholder="لقی سەرەکی بەغدا" /></label>
                </fieldset>

                <div class="form-grid">
                    <label>{{ t('Phone') }}<input v-model="form.phone" maxlength="30" inputmode="tel" placeholder="07xxxxxxxxx" /></label>
                    <label>{{ t('Address') }}<textarea v-model="form.address" rows="2" maxlength="255" /></label>
                </div>

                <p v-if="formError" class="error" role="alert">{{ formError }}</p>
                <footer>
                    <button class="btn ghost" type="button" @click="closeModal">{{ t('Cancel') }}</button>
                    <button class="btn primary" type="submit" :disabled="form.processing">{{ editing ? t('Update Branch') : t('Save Branch') }}</button>
                </footer>
            </form>
        </div>
    </AdminShell>
</template>

<style scoped>
.section-head{display:flex;justify-content:space-between;align-items:end;gap:16px;margin-bottom:20px}.eyebrow,.modal-kicker{color:var(--primary-strong);font-size:10px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.section-head h2{margin:4px 0 0;font-size:22px}.section-head p{margin:4px 0 0;color:var(--ink-faint);font-size:12px}.head-actions{display:flex;align-items:center;gap:10px}.route-total{font-size:11px;color:var(--ink-faint);white-space:nowrap}.route-total b{color:var(--primary-strong)}.btn{border:0;border-radius:10px;padding:9px 13px;font:inherit;font-size:12px;font-weight:800;cursor:pointer;transition:transform .18s ease,opacity .18s ease}.btn:hover:not(:disabled){transform:translateY(-1px)}.btn:disabled{cursor:wait;opacity:.65}.primary{background:var(--primary);color:#fff}.ghost{background:var(--surface-2);border:1px solid var(--border);color:var(--ink)}.danger{background:var(--danger-tint);color:var(--danger)}.page-error{margin:-6px 0 16px;padding:10px 12px;border-radius:10px;background:var(--danger-tint);color:var(--danger);font-size:12px;font-weight:700}.branch-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:16px}.branch-card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:17px;box-shadow:var(--shadow);display:flex;flex-direction:column;min-height:258px;transition:border-color .18s ease,opacity .18s ease}.branch-card.inactive{opacity:.78}.branch-head{display:flex;justify-content:space-between;gap:10px}.branch-title-row{display:flex;gap:10px;min-width:0}.branch-icon{width:38px;height:38px;flex:0 0 auto;display:grid;place-items:center;border-radius:11px;background:var(--primary-tint);color:var(--primary-strong);font-weight:900;font-size:19px}.branch-card h3{margin:1px 0 3px;font-size:15px;line-height:1.3}.branch-place{margin:0;color:var(--ink-faint);font-size:11px}.state{font-size:10px;font-weight:800;color:var(--success);background:var(--success-tint);padding:5px 8px;border-radius:20px;height:max-content;white-space:nowrap}.state.off{color:var(--danger);background:var(--danger-tint)}.alternate-names{min-height:17px;margin:12px 0 0;color:var(--ink-faint);font-size:10px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.branch-contact{display:flex;flex-direction:column;gap:3px;min-height:35px;margin-top:8px;color:var(--ink-soft);font-size:11px;overflow:hidden}.branch-contact span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.branch-flow{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin:16px 0 13px}.branch-flow>div{padding:8px 7px;border-radius:10px;background:var(--surface-2);min-width:0}.branch-flow span{display:block;color:var(--ink-faint);font-size:9px;line-height:1.2}.branch-flow b{display:block;margin-top:4px;color:var(--ink);font-size:15px}.cash{border-top:1px solid var(--border);padding-top:11px;display:flex;justify-content:space-between;gap:10px;font-size:11px;color:var(--ink-faint)}.cash b{color:var(--primary-strong);font-size:12px;white-space:nowrap}.branch-actions{display:flex;gap:8px;margin-top:auto;padding-top:15px}.branch-actions .btn{flex:1}.status-action{font-size:11px}.modal-backdrop{position:fixed;inset:0;background:#0a121180;display:grid;place-items:center;z-index:99;padding:20px;overflow:auto}.branch-modal{width:min(560px,100%);background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:21px;display:grid;gap:14px;box-shadow:0 24px 72px #0004}.branch-modal header{display:flex;justify-content:space-between;align-items:start;gap:14px}.branch-modal header h3{margin:4px 0 0;font-size:18px}.branch-modal header button{width:32px;height:32px;border:0;border-radius:9px;background:var(--surface-2);color:var(--ink);font-size:22px;line-height:1;cursor:pointer}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.branch-modal label{font-size:11px;font-weight:800;display:grid;gap:6px;color:var(--ink-soft)}.branch-modal input,.branch-modal textarea{width:100%;box-sizing:border-box;font:inherit;font-size:13px;color:var(--ink);border:1px solid var(--border);border-radius:10px;padding:10px;background:var(--surface-2);outline:none}.branch-modal input:focus,.branch-modal textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-tint)}fieldset{border:1px solid var(--border);border-radius:12px;padding:12px;display:grid;gap:10px}legend{padding:0 5px;color:var(--ink-faint);font-size:10px;font-weight:900}.error{color:var(--danger);margin:0;font-size:12px;font-weight:700}footer{display:flex;justify-content:flex-end;gap:8px}.empty{padding:30px}@media(max-width:620px){.section-head{align-items:stretch;flex-direction:column}.head-actions{justify-content:space-between}.branch-grid{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}.branch-modal{margin:auto;padding:17px}.route-total{white-space:normal}}
</style>
