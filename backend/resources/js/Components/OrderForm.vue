<script setup>
import { reactive, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import SheetModal from './SheetModal.vue'

const props = defineProps({
    open: { type: Boolean, default: false },
    order: { type: Object, default: null },
})

const emit = defineEmits(['close', 'saved'])

const submitting = ref(false)

const form = useForm({
    customer_name_ar: '',
    customer_name_en: '',
    phone: '',
    phone2: '',
    address_ar: '',
    address_en: '',
    order_type: '',
    price: '',
    notes: '',
    date: '',
})

watch(
    () => props.open,
    (open) => {
        if (!open) return
        form.clearErrors()
        if (props.order) {
            form.set({
                customer_name_ar: props.order.customer_name_ar || '',
                customer_name_en: props.order.customer_name_en || '',
                phone: props.order.phone || '',
                phone2: props.order.phone2 || '',
                address_ar: props.order.address_ar || '',
                address_en: props.order.address_en || '',
                order_type: props.order.order_type || '',
                price: props.order.price || '',
                notes: props.order.notes || '',
                date: props.order.date || '',
            })
        } else {
            form.reset()
        }
    }
)

function submit() {
    if (!form.customer_name_ar || !form.phone || !form.address_ar || !form.price) {
        form.setError({
            customer_name_ar: !form.customer_name_ar ? t('This field is required') : '',
            phone: !form.phone ? t('This field is required') : '',
            address_ar: !form.address_ar ? t('This field is required') : '',
            price: !form.price ? t('This field is required') : '',
        })
        return
    }
    submitting.value = true
    const payload = {
        customer_name_ar: form.customer_name_ar,
        customer_name_en: form.customer_name_en,
        phone: form.phone,
        phone2: form.phone2,
        address_ar: form.address_ar,
        address_en: form.address_en,
        order_type: form.order_type,
        price: form.price,
        notes: form.notes,
    }
    if (props.order) {
        form
            .transform((data) => ({ ...data, ...payload }))
            .post(route('app.orders.update', props.order.id), {
                preserveScroll: true,
                onSuccess: () => {
                    submitting.value = false
                    emit('saved')
                    emit('close')
                },
                onError: () => (submitting.value = false),
                onFinish: () => (submitting.value = false),
            })
    } else {
        form
            .transform((data) => ({ ...data, ...payload }))
            .post(route('app.orders.store'), {
                preserveScroll: true,
                onSuccess: () => {
                    submitting.value = false
                    emit('saved')
                    emit('close')
                },
                onError: () => (submitting.value = false),
                onFinish: () => (submitting.value = false),
            })
    }
}
</script>

<template>
    <SheetModal :open="open" :title="order ? t('Edit') + ' — ' + order.track_no : t('New Order')" @close="emit('close')">
        <form @submit.prevent="submit">
            <div class="field" :class="{ 'has-error': form.errors.customer_name_ar }">
                <label>{{ t('Customer') }}</label>
                <input v-model="form.customer_name_ar" :placeholder="t('Customer')" />
                <span v-if="form.errors.customer_name_ar" class="field-error">{{ form.errors.customer_name_ar }}</span>
            </div>
            <div class="field" :class="{ 'has-error': form.errors.phone }">
                <label>{{ t('Phone') }}</label>
                <input v-model="form.phone" dir="ltr" :placeholder="t('Phone')" />
                <span v-if="form.errors.phone" class="field-error">{{ form.errors.phone }}</span>
            </div>
            <div class="field">
                <label>{{ t('Phone 2') }}</label>
                <input v-model="form.phone2" dir="ltr" :placeholder="t('Phone 2')" />
            </div>
            <div class="field" :class="{ 'has-error': form.errors.address_ar }">
                <label>{{ t('Address') }}</label>
                <textarea v-model="form.address_ar" :placeholder="t('Address')"></textarea>
                <span v-if="form.errors.address_ar" class="field-error">{{ form.errors.address_ar }}</span>
            </div>
            <div class="field" :class="{ 'has-error': form.errors.price }">
                <label>{{ t('Price') }}</label>
                <input v-model="form.price" type="number" min="1" :placeholder="t('Price')" />
                <span v-if="form.errors.price" class="field-error">{{ form.errors.price }}</span>
            </div>
            <div class="field">
                <label>{{ t('Order Type') }}</label>
                <input v-model="form.order_type" :placeholder="t('Order Type')" />
            </div>
            <div class="field">
                <label>{{ t('Notes') }}</label>
                <textarea v-model="form.notes" :placeholder="t('Notes')"></textarea>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%" :disabled="submitting">
                <span v-if="submitting" class="loader"></span>
                {{ t('Save') }}
            </button>
        </form>
    </SheetModal>
</template>
