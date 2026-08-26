<script setup>
import { computed } from 'vue'

const props = defineProps({
    status: { type: String, required: true },
    dot: { type: Boolean, default: true },
})

const map = {
    pending: { cls: 'b-pending', label: t('Pending') },
    approved: { cls: 'b-approved', label: t('Approved') },
    courier: { cls: 'b-courier', label: t('With Courier') },
    delivered: { cls: 'b-delivered', label: t('Delivered') },
    returned: { cls: 'b-returned', label: t('Returned') },
    cancelled: { cls: 'b-danger', label: t('Cancelled') },
    damaged: { cls: 'b-warning', label: t('Damaged') },
    rejected: { cls: 'b-danger', label: t('Rejected') },
    late: { cls: 'b-warning', label: t('Late') },
}

const item = computed(() => map[props.status] ?? { cls: 'b-neutral', label: props.status })
</script>

<template>
    <span class="badge" :class="item.cls">
        <span v-if="dot" class="badge-dot"></span>
        {{ item.label }}
    </span>
</template>
