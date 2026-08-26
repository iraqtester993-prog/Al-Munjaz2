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
const vehiclePickerOpen = ref(false)
const page = usePage()
const provinces = computed(() => page.props.auth?.provinces || [])
const locale = computed(() => page.props.locale || 'ar')

const vehicleOptions = computed(() => [
    { value: 'normal', label: t('Regular Delivery'), helper: t('Normal order by default'), icon: 'box' },
    { value: 'suv', label: t('SUV'), helper: t('Suitable for large parcels'), icon: 'suv' },
])

const selectedVehicle = computed(() => vehicleOptions.value.find((vehicle) => vehicle.value === form.delivery_vehicle) || vehicleOptions.value[0])
const trackingNumber = computed(() => props.order?.track_no || '—')
const trackingHint = computed(() => {
    if (props.order?.track_no) return t('Tracking Number')

    return {
        ar: 'يُنشأ تلقائياً عند حفظ الطلب',
        en: 'Created automatically when the order is saved',
        ku: 'لە کاتی پاشەکەوتکردنی داواکاری بەخۆکار دروست دەکرێت',
    }[locale.value] || 'Created automatically when the order is saved'
})

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
        vehiclePickerOpen.value = false
    }
)

function chooseVehicle(value) {
    form.delivery_vehicle = value
    vehiclePickerOpen.value = false
}

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
            <div class="order-track-card">
                <span class="order-track-icon" aria-hidden="true">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8 12 3 3 8v8l9 5 9-5V8ZM3 8l9 5 9-5M12 13v8" /></svg>
                </span>
                <span class="order-track-copy">
                    <small>{{ t('Tracking Number') }}</small>
                    <strong class="mono">{{ trackingNumber }}</strong>
                </span>
                <small v-if="!order" class="order-track-hint">{{ trackingHint }}</small>
            </div>
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
                <input v-model="customerAddress" :placeholder="t('Address')" />
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
            <div class="field">
                <label>{{ t('Order Type') }}</label>
                <input v-model="form.order_type" :placeholder="t('Order Type')" />
            </div>
            <div class="field">
                <label>{{ t('Delivery Vehicle') }}</label>
                <div class="delivery-vehicle-picker">
                    <button type="button" class="delivery-vehicle-trigger" :class="{ open: vehiclePickerOpen }" @click="vehiclePickerOpen = !vehiclePickerOpen">
                        <span class="vehicle-choice-icon">
                            <svg v-if="selectedVehicle.icon === 'box'" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="m3 7 9-4 9 4-9 4-9-4Z M3 7v10l9 4 9-4V7 M12 11v10" /></svg>
                            <svg v-else-if="selectedVehicle.icon === 'bike'" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="16.5" r="3" /><circle cx="18" cy="16.5" r="3" /><path d="m6 16.5 4.5-7.5H14l2.5 3.5-3 4h-3M10.5 9H13" /></svg>
                            <svg v-else-if="selectedVehicle.icon === 'truck'" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 6.5h12V15H2zM14 9.5h3.6l2.6 2.6V15H14" /><circle cx="6.5" cy="16.5" r="1.7" /><circle cx="17" cy="16.5" r="1.7" /></svg>
                            <svg v-else width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 14 1.9-4.6a2 2 0 0 1 1.9-1.2h6.4a2 2 0 0 1 1.9 1.2L19 14M3.5 14h17A1.5 1.5 0 0 1 22 15.5V17h-2M2 16.5h1V17a1 1 0 0 0 1 1h.8" /><circle cx="7" cy="17.5" r="1.6" /><circle cx="17" cy="17.5" r="1.6" /></svg>
                        </span>
                        <span class="vehicle-choice-copy"><small>{{ selectedVehicle.helper }}</small><strong>{{ selectedVehicle.label }}</strong></span>
                        <span class="vehicle-choice-chev"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6" /></svg></span>
                    </button>
                    <div class="delivery-vehicle-menu" :class="{ open: vehiclePickerOpen }">
                        <button v-for="vehicle in vehicleOptions" :key="vehicle.value" type="button" class="delivery-vehicle-option" :class="{ selected: form.delivery_vehicle === vehicle.value }" @click="chooseVehicle(vehicle.value)">
                            <span class="option-icon">
                                <svg v-if="vehicle.icon === 'box'" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="m3 7 9-4 9 4-9 4-9-4Z M3 7v10l9 4 9-4V7 M12 11v10" /></svg>
                                <svg v-else-if="vehicle.icon === 'bike'" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="16.5" r="3" /><circle cx="18" cy="16.5" r="3" /><path d="m6 16.5 4.5-7.5H14l2.5 3.5-3 4h-3" /></svg>
                                <svg v-else-if="vehicle.icon === 'truck'" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 6.5h12V15H2zM14 9.5h3.6l2.6 2.6V15H14" /><circle cx="6.5" cy="16.5" r="1.7" /><circle cx="17" cy="16.5" r="1.7" /></svg>
                                <svg v-else width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 14 1.9-4.6a2 2 0 0 1 1.9-1.2h6.4a2 2 0 0 1 1.9 1.2L19 14" /><circle cx="7" cy="17.5" r="1.6" /><circle cx="17" cy="17.5" r="1.6" /></svg>
                            </span>
                            <span>{{ vehicle.label }}</span>
                        </button>
                    </div>
                </div>
            </div>
            <div class="field" :class="{ 'has-error': form.errors.price }">
                <label>{{ t('Price') }}</label>
                <input v-model="form.price" type="number" min="1" :placeholder="t('Price')" />
                <span v-if="form.errors.price" class="field-error">{{ form.errors.price }}</span>
            </div>
            <div class="field">
                <label>{{ t('Vehicle Note') }}</label>
                <textarea v-model="form.vehicle_note" rows="3" :placeholder="t('Example: needs a box or larger load')"></textarea>
            </div>
            <div class="field">
                <label>{{ t('Notes') }}</label>
                <textarea v-model="form.notes" rows="3" :placeholder="t('Notes')"></textarea>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%" :disabled="submitting">
                <span v-if="submitting" class="loader"></span>
                {{ t('Save') }}
            </button>
        </form>
    </SheetModal>
</template>

<style scoped>
.order-track-card{display:flex;align-items:center;gap:9px;margin-bottom:14px;padding:10px 12px;border-radius:11px;background:var(--primary-tint);color:var(--primary-strong)}.order-track-icon{display:grid;width:29px;height:29px;place-items:center;flex:none;border-radius:8px;background:var(--primary);color:#fff}.order-track-copy{display:grid;gap:2px;min-width:0}.order-track-copy small{color:var(--ink-soft);font-size:10px;font-weight:700}.order-track-copy strong{font-size:13px;font-weight:900;letter-spacing:.3px}.order-track-hint{margin-inline-start:auto;max-width:122px;color:var(--ink-soft);font-size:9px;font-weight:700;line-height:1.45;text-align:end}
.delivery-vehicle-picker{position:relative}.delivery-vehicle-trigger{width:100%;display:flex;align-items:center;gap:11px;padding:12px;border:1.5px solid var(--primary);border-radius:13px;background:var(--primary-tint);color:var(--ink);font:inherit;text-align:start;cursor:pointer;transition:.18s ease}.delivery-vehicle-trigger.open{background:var(--surface);box-shadow:0 5px 16px rgba(11,110,104,.14)}.vehicle-choice-icon{width:38px;height:38px;border-radius:11px;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;flex:none}.vehicle-choice-copy{flex:1;min-width:0}.vehicle-choice-copy small{display:block;margin-bottom:3px;color:var(--ink-soft);font-size:10px;font-weight:700}.vehicle-choice-copy strong{display:block;color:var(--primary-strong);font-size:15px;font-weight:900}.vehicle-choice-chev{color:var(--primary-strong);display:flex;transition:transform .18s}.delivery-vehicle-trigger.open .vehicle-choice-chev{transform:rotate(180deg)}.delivery-vehicle-menu{display:grid;grid-template-columns:1fr 1fr;gap:8px;max-height:0;opacity:0;overflow:hidden;margin-top:0;transition:max-height .25s ease,opacity .18s ease,margin-top .25s ease}.delivery-vehicle-menu.open{max-height:300px;opacity:1;margin-top:9px}.delivery-vehicle-option{display:flex;align-items:center;gap:8px;padding:10px;border:1px solid var(--border);border-radius:11px;background:var(--surface);color:var(--ink);font:inherit;text-align:start;cursor:pointer;transition:.15s ease}.delivery-vehicle-option:hover,.delivery-vehicle-option.selected{border-color:var(--primary);background:var(--primary-tint);box-shadow:0 3px 10px rgba(11,110,104,.1)}.option-icon{width:29px;height:29px;border-radius:9px;background:var(--surface-2);color:var(--primary-strong);display:flex;align-items:center;justify-content:center;flex:none}.delivery-vehicle-option.selected .option-icon{background:var(--primary);color:#fff}.delivery-vehicle-option span:last-child{font-size:12px;font-weight:800}@media(max-width:360px){.delivery-vehicle-menu{grid-template-columns:1fr}.delivery-vehicle-menu.open{max-height:360px}}
</style>
