<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import PopupSelect from './PopupSelect.vue'

const props = defineProps({
    filter: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['change'])
const page = usePage()
const locale = computed(() => page.props.locale || 'ar')
const copy = {
    ar: { label: 'فلترة الفرع', all: 'كل الفروع', search: 'ابحث باسم الفرع أو المحافظة', disabled: 'معطّل' },
    en: { label: 'Branch filter', all: 'All branches', search: 'Search by branch or governorate', disabled: 'Disabled' },
    ku: { label: 'فلتەری لق', all: 'هەموو لقەکان', search: 'بە ناوی لق یان پارێزگا بگەڕێ', disabled: 'ناچالاک' },
}
const t = (key) => copy[locale.value]?.[key] || copy.ar[key]
const branches = computed(() => props.filter?.branches || [])
const selected = computed(() => props.filter?.selected_id ? String(props.filter.selected_id) : '')
const isEnabled = computed(() => props.filter?.enabled === true)

function label(branch) {
    const name = branch.name_ar || branch.name_en || branch.name_ku || `#${branch.id}`
    const location = branch.city && branch.city !== name ? `${name} — ${branch.city}` : name

    return branch.is_active === false ? `${location} · ${t('disabled')}` : location
}

function select(value) {
    emit('change', value ? Number(value) : null)
}
</script>

<template>
    <label v-if="isEnabled" class="branch-filter">
        <span>{{ t('label') }}</span>
        <PopupSelect
            :model-value="selected"
            :placeholder="t('all')"
            :aria-label="t('label')"
            searchable
            :search-placeholder="t('search')"
            @update:model-value="select"
        >
            <option value="">{{ t('all') }}</option>
            <option v-for="branch in branches" :key="branch.id" :value="String(branch.id)">
                {{ label(branch) }}
            </option>
        </PopupSelect>
    </label>
</template>

<style scoped>
.branch-filter { min-width: min(100%, 245px); display: grid; gap: 5px; color: var(--ink-soft); font-size: 10px; font-weight: 850; }
.branch-filter :deep(.popup-select-trigger) { min-width: 245px; background: var(--surface); }
@media (max-width: 640px) { .branch-filter, .branch-filter :deep(.popup-select-trigger) { width: 100%; min-width: 0; } }
</style>
