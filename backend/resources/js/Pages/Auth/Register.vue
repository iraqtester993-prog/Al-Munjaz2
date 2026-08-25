<script setup>
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Flash from '../../Components/Flash.vue'

const props = defineProps({
    role: { type: String, required: true },
    vehicles: { type: Object, required: true },
    provinces: { type: Array, required: true },
})

const isCourier = props.role === 'courier'
const step = ref('form')
const sending = ref(false)

const form = useForm({
    role: props.role,
    name: '',
    phone: '',
    shop: '',
    address: '',
    vehicle: 'bike',
    province_id: '',
    password: '',
    password_confirmation: '',
    residence_document: null,
    id_front_document: null,
    id_back_document: null,
    license_front_document: null,
    license_back_document: null,
})

const vehicleList = computed(() =>
    Object.entries(props.vehicles).map(([key, v]) => ({ key, label: v[window.__locale] || v.ar }))
)

function submitForm() {
    sending.value = true
    form.post('/register', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => (step.value = 'pending'),
        onFinish: () => (sending.value = false),
    })
}

function chooseFile(event, key) {
    form[key] = event.target.files?.[0] || null
}
</script>

<template>
    <div class="app-stage" style="padding: 24px 16px">
        <div class="app-shell" style="justify-content: flex-start; overflow-y: auto">
            <Flash />
            <div style="padding: 32px 22px 20px">
                <button class="tb-icon-btn" style="margin-bottom: 18px" @click="$inertia.visit(route('login'))">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" :style="{ transform: window.__locale === 'ar' ? 'rotate(180deg)' : '' }">
                        <path d="M19 12H5m0 0 6-6m-6 6 6 6" />
                    </svg>
                </button>
                <div class="role-ico-lg">
                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path :d="isCourier ? 'M5 18a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm14-8a4 4 0 1 1 0 8 4 4 0 0 1 0-8Z M5 10h14 M12 10l-2-4h5' : 'M4 10v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V10 M2 7l1-3h18l1 3a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0Z'" />
                    </svg>
                </div>
                <h1 style="font-size: 19px; font-weight: 900; margin-top: 14px">
                    {{ isCourier ? t('Courier App') : t('Merchant App') }}
                </h1>
                <p class="text-muted" style="margin-top: 4px">{{ t('Register') }}</p>
            </div>

            <form v-if="step === 'form'" @submit.prevent="submitForm" style="padding: 0 22px 30px">
                <div class="field" :class="{ 'has-error': form.errors.name }">
                    <label>{{ t('Full Name') }}</label>
                    <input v-model="form.name" :placeholder="t('Full Name')" />
                    <span v-if="form.errors.name" class="field-error">{{ form.errors.name }}</span>
                </div>
                <div class="field" :class="{ 'has-error': form.errors.phone }">
                    <label>{{ t('Phone') }}</label>
                    <input v-model="form.phone" dir="ltr" :placeholder="t('Phone')" />
                    <span v-if="form.errors.phone" class="field-error">{{ form.errors.phone }}</span>
                </div>
                <div v-if="!isCourier" class="field" :class="{ 'has-error': form.errors.shop }">
                    <label>{{ t('Shop Name') }}</label>
                    <input v-model="form.shop" :placeholder="t('Shop Name')" />
                    <span v-if="form.errors.shop" class="field-error">{{ form.errors.shop }}</span>
                </div>
                <div class="field" :class="{ 'has-error': form.errors.address }">
                    <label>{{ isCourier ? 'العنوان' : t('Shop Address') }}</label>
                    <input v-model="form.address" :placeholder="t('Shop Address')" />
                    <span v-if="form.errors.address" class="field-error">{{ form.errors.address }}</span>
                </div>
                <div v-if="isCourier" class="field">
                    <label>{{ t('Vehicle') }}</label>
                    <select v-model="form.vehicle">
                        <option v-for="v in vehicleList" :key="v.key" :value="v.key">{{ v.label }}</option>
                    </select>
                </div>
                <div class="field" :class="{ 'has-error': form.errors.province_id }">
                    <label>المحافظة</label>
                    <select v-model="form.province_id" required>
                        <option disabled value="">اختر المحافظة</option>
                        <option v-for="province in props.provinces" :key="province.id" :value="province.id">{{ province.name_ar }}</option>
                    </select>
                    <span v-if="form.errors.province_id" class="field-error">{{ form.errors.province_id }}</span>
                </div>
                <div class="field" :class="{ 'has-error': form.errors.password }">
                    <label>{{ t('Password') }}</label>
                    <input v-model="form.password" type="password" :placeholder="t('Password')" autocomplete="new-password" />
                    <span v-if="form.errors.password" class="field-error">{{ form.errors.password }}</span>
                </div>
                <div class="field" :class="{ 'has-error': form.errors.password }">
                    <label>تأكيد كلمة المرور</label>
                    <input v-model="form.password_confirmation" type="password" placeholder="أعد كتابة كلمة المرور" autocomplete="new-password" />
                </div>
                <template v-if="isCourier">
                    <p class="text-muted" style="font-size: 12px; font-weight: 700; margin: 20px 0 8px">وثائق المندوب المطلوبة للمراجعة</p>
                    <div v-for="doc in [
                        ['residence_document', 'وثيقة السكن'], ['id_front_document', 'البطاقة الوطنية — الوجه الأمامي'], ['id_back_document', 'البطاقة الوطنية — الوجه الخلفي'],
                        ['license_front_document', 'إجازة السوق — الوجه الأمامي'], ['license_back_document', 'إجازة السوق — الوجه الخلفي']
                    ]" :key="doc[0]" class="field" :class="{ 'has-error': form.errors[doc[0]] }">
                        <label>{{ doc[1] }}</label>
                        <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" @change="chooseFile($event, doc[0])" />
                        <span v-if="form.errors[doc[0]]" class="field-error">{{ form.errors[doc[0]] }}</span>
                    </div>
                </template>
                <button type="submit" class="btn btn-primary" style="width: 100%" :disabled="sending">
                    <span v-if="sending" class="loader"></span>
                    {{ t('Continue') }}
                </button>
            </form>

            <div v-else style="padding: 0 22px 30px; text-align: center">
                <div class="role-ico-lg" style="margin: 8px auto 18px; background: var(--success-tint); color: var(--success)">✓</div>
                <h2 style="font-size: 19px; font-weight: 900">تم استلام طلب الحساب</h2>
                <p class="text-muted" style="margin: 12px 0 22px; line-height: 1.8">تم حفظ بياناتك على خادم المنجز السريع. ستقوم الإدارة بمراجعة الحساب{{ isCourier ? ' والوثائق' : '' }} ثم تفعيله.</p>
                <p class="text-muted" style="font-size: 12px">اسم الدخول هو رقم الهاتف: <b dir="ltr">{{ form.phone }}</b></p>
                <button class="btn btn-primary" style="width: 100%; margin-top: 18px" @click="$inertia.visit(route('login'))">الذهاب لتسجيل الدخول</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.role-ico-lg {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    background: var(--primary-tint);
    color: var(--primary-strong);
    display: flex;
    align-items: center;
    justify-content: center;
}
</style>
