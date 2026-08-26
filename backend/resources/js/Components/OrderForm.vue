<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import SheetModal from './SheetModal.vue'

const props = defineProps({
    open: { type: Boolean, default: false },
    order: { type: Object, default: null },
})

const emit = defineEmits(['close', 'saved'])

const submitting = ref(false)
const page = usePage()
const provinces = computed(() => page.props.auth?.provinces || [])
const locale = computed(() => page.props.locale || 'ar')

const form = useForm({
    customer_name_ar: '',
    customer_name_en: '',
    phone: '',
    phone2: '',
    address_ar: '',
    address_en: '',
    order_type: '',
    delivery_vehicle: 'normal',
    vehicle_note: '',
    province_id: '',
    price: '',
    notes: '',
    date: '',
})

const customerName = computed({
    get: () => locale.value === 'en'
        ? (form.customer_name_en || form.customer_name_ar)
        : (form.customer_name_ar || form.customer_name_en),
    set: (value) => {
        form.customer_name_ar = value
        if (locale.value === 'en') form.customer_name_en = value
    },
})

const customerAddress = computed({
    get: () => locale.value === 'en'
        ? (form.address_en || form.address_ar)
        : (form.address_ar || form.address_en),
    set: (value) => {
        form.address_ar = value
        if (locale.value === 'en') form.address_en = value
    },
})

function provinceName(province) {
    const preferred = locale.value === 'en' ? 'en' : locale.value === 'ku' ? 'ku' : 'ar'

    return province?.[`name_${preferred}`]
        || province?.name_en
        || province?.name_ar
        || ''
}

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
                delivery_vehicle: props.order.delivery_vehicle || 'normal',
                vehicle_note: props.order.vehicle_note || '',
                province_id: props.order.province_id || provinces.value[0]?.id || '',
                price: props.order.price || '',
                notes: props.order.notes || '',
                date: props.order.date || '',
            })
        } else {
            form.reset()
            form.province_id = provinces.value[0]?.id || ''
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
        delivery_vehicle: form.delivery_vehicle,
        vehicle_note: form.vehicle_note,
        price: form.price,
        notes: form.notes,
        province_id: form.province_id,
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
                <input v-model="customerName" :placeholder="t('Customer')" />
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
                <textarea v-model="customerAddress" :placeholder="t('Address')"></textarea>
                <span v-if="form.errors.address_ar" class="field-error">{{ form.errors.address_ar }}</span>
            </div>
            <div class="field" :class="{ 'has-error': form.errors.province_id }">
                <label>{{ t('Delivery Governorate') }}</label>
                <select v-model="form.province_id" required>
                    <option disabled value="">{{ t('Choose Governorate') }}</option>
                    <option v-for="province in provinces" :key="province.id" :value="province.id">{{ provinceName(province) }}</option>
                </select>
                <span v-if="form.errors.province_id" class="field-error">{{ form.errors.province_id }}</span>
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
                <label>{{ t('Delivery Vehicle') }}</label>
                <select v-model="form.delivery_vehicle">
                    <option value="normal">{{ t('Regular Delivery') }}</option>
                    <option value="bike">{{ t('Motorcycle') }}</option>
                    <option value="sedan">{{ t('Sedan') }}</option>
                    <option value="suv">{{ t('SUV') }}</option>
                    <option value="truck">{{ t('Van / Truck') }}</option>
                </select>
            </div>
            <div v-if="form.delivery_vehicle !== 'normal'" class="field">
                <label>{{ t('Vehicle Note') }}</label>
                <input v-model="form.vehicle_note" :placeholder="t('Example: needs a box or larger load')" />
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
