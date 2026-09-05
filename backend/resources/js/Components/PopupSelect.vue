<script setup>
import { computed, onBeforeUnmount, ref, useAttrs, useSlots, watch } from 'vue'

defineOptions({ inheritAttrs: false })

const props = defineProps({
    modelValue: { default: '' },
    disabled: { type: Boolean, default: false },
    placeholder: { type: String, default: '' },
    searchable: { type: Boolean, default: false },
    searchPlaceholder: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'change', 'focus'])
const attrs = useAttrs()
const slots = useSlots()
const isOpen = ref(false)
const query = ref('')

function flattenNodes(nodes) {
    return (nodes || []).flatMap((node) => {
        if (Array.isArray(node?.children)) return flattenNodes(node.children)
        if (node?.type === 'option') {
            return [{
                value: node.props?.value ?? '',
                disabled: Boolean(node.props?.disabled),
                label: typeof node.children === 'string' ? node.children.trim() : '',
            }]
        }

        return []
    })
}

const options = computed(() => flattenNodes(slots.default?.()))
const selected = computed(() => options.value.find((option) => String(option.value) === String(props.modelValue)))
const filteredOptions = computed(() => {
    const term = query.value.trim().toLocaleLowerCase()
    if (!term) return options.value

    return options.value.filter((option) => option.label.toLocaleLowerCase().includes(term))
})
const shouldSearch = computed(() => props.searchable || options.value.length > 8)
const displayLabel = computed(() => selected.value?.label || props.placeholder || 'اختر من القائمة')

function open() {
    if (props.disabled || attrs.disabled) return
    query.value = ''
    isOpen.value = true
}

function close() {
    isOpen.value = false
    query.value = ''
}

function choose(option) {
    if (option.disabled) return

    emit('update:modelValue', option.value)
    // Existing dashboard handlers use the native select event shape.
    emit('change', { target: { value: option.value } })
    close()
}

function handleKeydown(event) {
    if (event.key === 'Escape') close()
}

function handleFocus(event) {
    emit('focus', event)
}

watch(isOpen, (open) => {
    document.body.classList.toggle('popup-select-open', open)
})

onBeforeUnmount(() => {
    document.body.classList.remove('popup-select-open')
})
</script>

<template>
    <button
        v-bind="attrs"
        class="popup-select-trigger"
        :class="{ 'has-value': selected, disabled: disabled || attrs.disabled }"
        type="button"
        :disabled="disabled || attrs.disabled"
        :aria-expanded="isOpen"
        aria-haspopup="dialog"
        @click="open"
        @focus="handleFocus"
    >
        <span class="popup-select-value">{{ displayLabel }}</span>
        <span class="popup-select-chevron" aria-hidden="true">⌄</span>
    </button>

    <Teleport to="body">
        <div v-if="isOpen" class="popup-select-backdrop" @click.self="close" @keydown="handleKeydown">
            <section class="popup-select-dialog" role="dialog" aria-modal="true" :aria-label="attrs['aria-label'] || displayLabel">
                <header class="popup-select-header">
                    <div>
                        <small>اختيار من القائمة</small>
                        <h3>{{ attrs['aria-label'] || displayLabel }}</h3>
                    </div>
                    <button type="button" aria-label="إغلاق" @click="close">×</button>
                </header>

                <div v-if="shouldSearch" class="popup-select-search">
                    <span aria-hidden="true">⌕</span>
                    <input v-model="query" type="search" autofocus :placeholder="searchPlaceholder || 'ابحث في الخيارات'" />
                </div>

                <div class="popup-select-options" role="listbox">
                    <button
                        v-for="(option, index) in filteredOptions"
                        :key="`${String(option.value)}-${index}`"
                        class="popup-select-option"
                        :class="{ selected: String(option.value) === String(modelValue), unavailable: option.disabled }"
                        :disabled="option.disabled"
                        type="button"
                        role="option"
                        :aria-selected="String(option.value) === String(modelValue)"
                        @click="choose(option)"
                    >
                        <span>{{ option.label }}</span>
                        <b v-if="String(option.value) === String(modelValue)" aria-hidden="true">✓</b>
                    </button>
                    <p v-if="!filteredOptions.length" class="popup-select-empty">لا توجد خيارات مطابقة.</p>
                </div>
            </section>
        </div>
    </Teleport>
</template>

<style>
body.popup-select-open { overflow: hidden; }
.popup-select-trigger { width: 100%; min-height: 42px; display: flex; align-items: center; justify-content: space-between; gap: 10px; box-sizing: border-box; padding: 9px 11px; border: 1px solid var(--border); border-radius: 10px; color: var(--ink); background: var(--surface-2); font: inherit; font-size: 12px; font-weight: 750; text-align: start; cursor: pointer; }
.popup-select-trigger:hover:not(:disabled) { border-color: var(--primary); }
.popup-select-trigger:focus-visible { outline: 0; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-tint); }
.popup-select-trigger.disabled { cursor: not-allowed; opacity: .58; }
.popup-select-value { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.popup-select-chevron { display: grid; width: 20px; height: 20px; flex: none; place-items: center; border-radius: 6px; color: var(--primary-strong); background: var(--primary-tint); font-size: 16px; line-height: 1; }
.popup-select-backdrop { position: fixed; z-index: 250; inset: 0; display: grid; place-items: center; padding: 18px; background: rgba(4, 12, 26, .62); backdrop-filter: blur(4px); }
.popup-select-dialog { width: min(100%, 620px); max-height: min(82dvh, 760px); display: flex; flex-direction: column; overflow: hidden; border: 1px solid var(--border); border-radius: 18px; color: var(--ink); background: var(--surface); box-shadow: 0 28px 76px rgba(0,0,0,.34); }
.popup-select-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 17px 18px; border-bottom: 1px solid var(--border); }
.popup-select-header small { color: var(--primary); font-size: 9px; font-weight: 900; letter-spacing: .07em; text-transform: uppercase; }
.popup-select-header h3 { margin: 4px 0 0; color: var(--ink); font-size: 16px; }
.popup-select-header button { display: grid; width: 31px; height: 31px; flex: none; place-items: center; border: 0; border-radius: 9px; color: var(--ink); background: var(--surface-2); font-size: 21px; cursor: pointer; }
.popup-select-search { display: flex; align-items: center; gap: 8px; margin: 13px 14px 8px; padding: 0 11px; border: 1px solid var(--border); border-radius: 10px; color: var(--primary-strong); background: var(--surface-2); }
.popup-select-search input { width: 100%; min-width: 0; padding: 10px 0; border: 0; outline: 0; color: var(--ink); background: transparent; font: inherit; font-size: 12px; }
.popup-select-options { display: grid; min-height: 0; gap: 7px; overflow-y: auto; padding: 10px 14px 14px; overscroll-behavior: contain; }
.popup-select-option { width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 12px 13px; border: 1px solid var(--border); border-radius: 11px; color: var(--ink); background: var(--surface-2); font: inherit; font-size: 12px; font-weight: 800; line-height: 1.5; text-align: start; cursor: pointer; }
.popup-select-option:hover:not(:disabled),.popup-select-option.selected { border-color: var(--primary); color: var(--primary-strong); background: var(--primary-tint); }
.popup-select-option.unavailable { cursor: not-allowed; opacity: .46; }
.popup-select-option b { display: grid; width: 21px; height: 21px; flex: none; place-items: center; border-radius: 50%; color: #fff; background: var(--primary); font-size: 11px; }
.popup-select-empty { margin: 8px 0; padding: 18px; color: var(--ink-faint); font-size: 12px; font-weight: 750; text-align: center; }
@media (max-width: 580px) { .popup-select-backdrop { align-items: end; padding: 0; } .popup-select-dialog { width: 100%; max-height: 86dvh; border-radius: 18px 18px 0 0; } .popup-select-options { padding-bottom: max(18px, env(safe-area-inset-bottom)); } }
</style>
