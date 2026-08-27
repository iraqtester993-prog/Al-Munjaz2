<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import SheetModal from './SheetModal.vue'
import OrderMapPicker from './OrderMapPicker.vue'

const props = defineProps({
    open: { type: Boolean, default: false },
    order: { type: Object, default: null },
})

const emit = defineEmits(['close', 'saved'])

const submitting = ref(false)
const vehiclePickerOpen = ref(false)
const pickupLocationBusy = ref(false)
const pickupLocationMessage = ref('')
const pickupLocationError = ref('')
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
    pickup_latitude: '',
    pickup_longitude: '',
    pickup_location_label: '',
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

const hasPickupCoordinates = computed(() => {
    if (String(form.pickup_latitude ?? '').trim() === '' || String(form.pickup_longitude ?? '').trim() === '') {
        return false
    }

    const latitude = Number(form.pickup_latitude)
    const longitude = Number(form.pickup_longitude)

    return Number.isFinite(latitude)
        && Number.isFinite(longitude)
        && latitude >= -90
        && latitude <= 90
        && longitude >= -180
        && longitude <= 180
})

const pickupCoordinates = computed(() => {
    if (!hasPickupCoordinates.value) return ''

    return `${Number(form.pickup_latitude).toFixed(6)}, ${Number(form.pickup_longitude).toFixed(6)}`
})

// Money is stored as plain digits for the API, while the person entering it
// sees the same Latin-digit grouping used everywhere else in the app.
function moneyDigits(value) {
    return String(value ?? '').replace(/[^0-9]/g, '')
}

const priceInput = computed({
    get: () => String(form.price ?? '') === '' ? '' : fmt(Number(form.price || 0)),
    set: (value) => { form.price = moneyDigits(value) },
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
                pickup_latitude: props.order.pickup_latitude ?? '',
                pickup_longitude: props.order.pickup_longitude ?? '',
                pickup_location_label: props.order.pickup_location_label || '',
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
        pickupLocationMessage.value = ''
        pickupLocationError.value = ''
    }
)

function chooseVehicle(value) {
    form.delivery_vehicle = value
    vehiclePickerOpen.value = false
}

function defaultPickupLabel() {
    const shopName = page.props.auth?.user?.shop_name || page.props.auth?.user?.name

    return shopName ? `${shopName} — ${t('Current location')}` : t('Merchant pickup location')
}

function locationFailureMessage(error) {
    if (error?.code === error?.PERMISSION_DENIED || error?.code === 1) {
        return t('Allow location from your device settings, then return here.')
    }

    if (error?.code === error?.TIMEOUT || error?.code === 3) {
        return t('Location request timed out. Move to an open area and try again.')
    }

    return t('Could not access your location. Check your device settings and try again.')
}

function capturePickupLocation() {
    pickupLocationError.value = ''
    pickupLocationMessage.value = ''

    if (typeof window === 'undefined' || !('geolocation' in navigator)) {
        pickupLocationError.value = t('Location access is not available on this device.')
        return
    }

    if (!window.isSecureContext) {
        pickupLocationError.value = t('Location access requires a secure connection.')
        return
    }

    pickupLocationBusy.value = true
    navigator.geolocation.getCurrentPosition(
        (position) => {
            form.pickup_latitude = Number(position.coords.latitude.toFixed(7))
            form.pickup_longitude = Number(position.coords.longitude.toFixed(7))
            form.pickup_location_label = String(form.pickup_location_label || '').trim() || defaultPickupLabel()
            form.clearErrors('pickup_latitude', 'pickup_longitude', 'pickup_location_label')
            pickupLocationMessage.value = t('Pickup location captured. You can edit its label before saving.')
            pickupLocationBusy.value = false
        },
        (error) => {
            pickupLocationError.value = locationFailureMessage(error)
            pickupLocationBusy.value = false
        },
        {
            enableHighAccuracy: true,
            timeout: 18000,
            maximumAge: 60000,
        },
    )
}

function clearPickupLocation() {
    form.pickup_latitude = ''
    form.pickup_longitude = ''
    form.pickup_location_label = ''
    form.clearErrors('pickup_latitude', 'pickup_longitude', 'pickup_location_label')
    pickupLocationMessage.value = ''
    pickupLocationError.value = ''
}

function selectPickupFromMap(location) {
    form.pickup_latitude = Number(location.latitude).toFixed(7)
    form.pickup_longitude = Number(location.longitude).toFixed(7)
    form.pickup_location_label = String(location.label || '').trim() || defaultPickupLabel()
    form.clearErrors('pickup_latitude', 'pickup_longitude', 'pickup_location_label')
    pickupLocationError.value = ''
    pickupLocationMessage.value = t('Pickup location selected on the map. You can edit its label before saving.')
}

function submit() {
    if (!form.customer_name_ar || !form.phone || !form.address_ar || !form.price || !hasPickupCoordinates.value || !String(form.pickup_location_label || '').trim()) {
        form.setError({
            customer_name_ar: !form.customer_name_ar ? t('This field is required') : '',
            phone: !form.phone ? t('This field is required') : '',
            address_ar: !form.address_ar ? t('This field is required') : '',
            price: !form.price ? t('This field is required') : '',
            pickup_latitude: !hasPickupCoordinates.value ? t('Pickup location is required before saving the order.') : '',
            pickup_location_label: !String(form.pickup_location_label || '').trim() ? t('Enter a clear pickup location label.') : '',
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
        pickup_latitude: Number(form.pickup_latitude),
        pickup_longitude: Number(form.pickup_longitude),
        pickup_location_label: String(form.pickup_location_label).trim(),
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
            <section class="pickup-location-picker" :class="{ captured: hasPickupCoordinates, 'has-error': form.errors.pickup_latitude || form.errors.pickup_location_label }">
                <div class="pickup-location-head">
                    <span class="pickup-location-icon" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 5.2-8 11-8 11S4 15.2 4 10a8 8 0 1 1 16 0Z" /><circle cx="12" cy="10" r="2.5" /></svg>
                    </span>
                    <span class="pickup-location-copy">
                        <b>{{ t('Merchant pickup location') }}</b>
                        <small v-if="hasPickupCoordinates">{{ pickupCoordinates }}</small>
                        <small v-else>{{ t('Save the merchant location with this order so the courier can open it in their preferred navigation app.') }}</small>
                    </span>
                    <button type="button" class="pickup-location-action" :disabled="pickupLocationBusy" @click="capturePickupLocation">
                        <span v-if="pickupLocationBusy" class="loader"></span>
                        <span v-else>{{ hasPickupCoordinates ? t('Update location') : t('Use current location') }}</span>
                    </button>
                </div>

                <div v-if="hasPickupCoordinates" class="pickup-location-details">
                    <div class="field pickup-location-label-field" :class="{ 'has-error': form.errors.pickup_location_label }">
                        <label>{{ t('Pickup location label') }}</label>
                        <input v-model="form.pickup_location_label" :placeholder="t('Example: Al-Munjaz store — Karrada')" maxlength="255" />
                        <span v-if="form.errors.pickup_location_label" class="field-error">{{ form.errors.pickup_location_label }}</span>
                    </div>
                    <button type="button" class="pickup-location-clear" @click="clearPickupLocation">{{ t('Clear location') }}</button>
                </div>

                <OrderMapPicker
                    :latitude="form.pickup_latitude"
                    :longitude="form.pickup_longitude"
                    :label="form.pickup_location_label"
                    :locale="locale"
                    @selected="selectPickupFromMap"
                    @cleared="clearPickupLocation"
                />
                <small v-if="pickupLocationMessage" class="pickup-location-message success">{{ pickupLocationMessage }}</small>
                <small v-if="pickupLocationError || form.errors.pickup_latitude" class="pickup-location-message error">{{ pickupLocationError || form.errors.pickup_latitude }}</small>
            </section>
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
                <input v-model="priceInput" type="text" inputmode="numeric" dir="ltr" :placeholder="t('Price')" />
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
.pickup-location-picker{display:grid;gap:10px;margin:12px 0;padding:12px;border:1.5px solid color-mix(in srgb,var(--primary) 26%,var(--border));border-radius:14px;background:linear-gradient(135deg,color-mix(in srgb,var(--primary-tint) 68%,var(--surface)),var(--surface));transition:border-color .18s,background .18s}.pickup-location-picker.captured{border-color:color-mix(in srgb,var(--success) 50%,var(--border));background:linear-gradient(135deg,color-mix(in srgb,var(--success-tint) 64%,var(--surface)),var(--surface))}.pickup-location-picker.has-error{border-color:var(--danger)}.pickup-location-head{display:flex;align-items:center;gap:9px}.pickup-location-icon{display:grid;place-items:center;width:38px;height:38px;flex:none;border-radius:11px;background:var(--primary);color:#fff}.pickup-location-picker.captured .pickup-location-icon{background:var(--success)}.pickup-location-copy{display:grid;min-width:0;flex:1;gap:2px}.pickup-location-copy b{color:var(--ink);font-size:11.5px;font-weight:900}.pickup-location-copy small{color:var(--ink-soft);font-size:9.5px;font-weight:700;line-height:1.45}.pickup-location-action{min-height:35px;display:inline-flex;align-items:center;justify-content:center;gap:6px;flex:none;padding:8px 10px;border:0;border-radius:9px;background:var(--primary);color:#fff;font:inherit;font-size:9.5px;font-weight:900;cursor:pointer}.pickup-location-action:disabled{cursor:wait;opacity:.72}.pickup-location-action .loader{width:13px;height:13px;border-width:2px}.pickup-location-details{display:flex;align-items:end;gap:9px;padding-top:10px;border-top:1px solid color-mix(in srgb,var(--primary) 18%,var(--border))}.pickup-location-label-field{flex:1;margin:0}.pickup-location-label-field input{min-height:39px}.pickup-location-clear,.pickup-location-manual{border:0;color:var(--primary-strong);background:transparent;font:inherit;font-size:9.5px;font-weight:850;cursor:pointer}.pickup-location-clear{min-height:39px;padding:0 2px;color:var(--danger)}.pickup-location-manual{justify-self:start;padding:0;text-decoration:underline;text-underline-offset:3px}.pickup-coordinate-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}.pickup-coordinate-grid .field{margin:0}.pickup-coordinate-grid input{min-height:39px}.pickup-location-message{display:block;margin-top:-3px;font-size:9.5px;font-weight:750;line-height:1.55}.pickup-location-message.success{color:var(--success)}.pickup-location-message.error{color:var(--danger)}
.delivery-vehicle-picker{position:relative}.delivery-vehicle-trigger{width:100%;display:flex;align-items:center;gap:11px;padding:12px;border:1.5px solid var(--primary);border-radius:13px;background:var(--primary-tint);color:var(--ink);font:inherit;text-align:start;cursor:pointer;transition:.18s ease}.delivery-vehicle-trigger.open{background:var(--surface);box-shadow:0 5px 16px rgba(11,110,104,.14)}.vehicle-choice-icon{width:38px;height:38px;border-radius:11px;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;flex:none}.vehicle-choice-copy{flex:1;min-width:0}.vehicle-choice-copy small{display:block;margin-bottom:3px;color:var(--ink-soft);font-size:10px;font-weight:700}.vehicle-choice-copy strong{display:block;color:var(--primary-strong);font-size:15px;font-weight:900}.vehicle-choice-chev{color:var(--primary-strong);display:flex;transition:transform .18s}.delivery-vehicle-trigger.open .vehicle-choice-chev{transform:rotate(180deg)}.delivery-vehicle-menu{display:grid;grid-template-columns:1fr 1fr;gap:8px;max-height:0;opacity:0;overflow:hidden;margin-top:0;transition:max-height .25s ease,opacity .18s ease,margin-top .25s ease}.delivery-vehicle-menu.open{max-height:300px;opacity:1;margin-top:9px}.delivery-vehicle-option{display:flex;align-items:center;gap:8px;padding:10px;border:1px solid var(--border);border-radius:11px;background:var(--surface);color:var(--ink);font:inherit;text-align:start;cursor:pointer;transition:.15s ease}.delivery-vehicle-option:hover,.delivery-vehicle-option.selected{border-color:var(--primary);background:var(--primary-tint);box-shadow:0 3px 10px rgba(11,110,104,.1)}.option-icon{width:29px;height:29px;border-radius:9px;background:var(--surface-2);color:var(--primary-strong);display:flex;align-items:center;justify-content:center;flex:none}.delivery-vehicle-option.selected .option-icon{background:var(--primary);color:#fff}.delivery-vehicle-option span:last-child{font-size:12px;font-weight:800}@media(max-width:360px){.delivery-vehicle-menu{grid-template-columns:1fr}.delivery-vehicle-menu.open{max-height:360px}}
</style>
