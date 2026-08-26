<script setup>
import { computed, ref, watch } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'

const props = defineProps({
    slides: { type: Array, default: () => [] },
})

const page = usePage()
const modalOpen = ref(false)
const editing = ref(null)
const imageInput = ref(null)
const imagePreview = ref('')

const text = {
    ar: {
        title: 'محتوى التطبيق', eyebrow: 'مركز النشر', intro: 'تحكّم بالسلايدر الذي يظهر للتاجر والمندوب من نفس لوحة الإدارة.', new: 'إضافة سلايد', edit: 'تعديل السلايد', image: 'صورة السلايد', upload: 'رفع صورة', replace: 'استبدال الصورة', noImage: 'خلفية لونية تلقائية', audience: 'الفئة المستهدفة', all: 'الكل', merchant: 'التاجر', courier: 'المندوب', active: 'منشور الآن', order: 'ترتيب الظهور', schedule: 'جدولة النشر', starts: 'يبدأ في', ends: 'ينتهي في', Arabic: 'العربية', English: 'الإنجليزية', Kurdish: 'الكردية', heading: 'العنوان', body: 'النص التوضيحي', tag: 'وسم صغير', button: 'نص الزر', link: 'رابط الزر', save: 'حفظ السلايد', cancel: 'إلغاء', delete: 'حذف', empty: 'لا توجد سلايدات منشورة بعد. أضف أول رسالة تظهر في واجهة التطبيق.', published: 'منشور', hidden: 'مخفي', saveError: 'راجع الحقول المظللة ثم أعد المحاولة.', deleteConfirm: 'هل تريد حذف هذا السلايد نهائياً؟', preview: 'معاينة التطبيق', activeNow: 'ظاهر حالياً', scheduled: 'مجدول', expired: 'منتهي', audienceAll: 'للتاجر والمندوب', imageHint: 'PNG أو JPG أو WebP حتى 5 MB.', safeLink: 'مسار داخلي مثل /app/orders أو رابط https://', close: 'إغلاق', audienceHint: 'يظهر فقط للفئة المحددة أو للجميع.', calendarHint: 'اترك التاريخين فارغين للنشر المفتوح.', actionHint: 'اختياري — عند ضغط المستخدم على الزر.', unsaved: 'لا يظهر في التطبيق إلا بعد الحفظ والنشر.',
    },
    en: {
        title: 'Mobile Content', eyebrow: 'Publishing Center', intro: 'Manage the home slider shown to merchants and couriers from the same dashboard.', new: 'Add Slide', edit: 'Edit Slide', image: 'Slide Image', upload: 'Upload Image', replace: 'Replace Image', noImage: 'Automatic colour background', audience: 'Audience', all: 'Everyone', merchant: 'Merchant', courier: 'Courier', active: 'Published now', order: 'Display order', schedule: 'Publishing schedule', starts: 'Starts at', ends: 'Ends at', Arabic: 'Arabic', English: 'English', Kurdish: 'Kurdish', heading: 'Heading', body: 'Supporting text', tag: 'Small tag', button: 'Button text', link: 'Button link', save: 'Save Slide', cancel: 'Cancel', delete: 'Delete', empty: 'No published slides yet. Add the first message for the mobile app.', published: 'Published', hidden: 'Hidden', saveError: 'Review the highlighted fields and try again.', deleteConfirm: 'Delete this slide permanently?', preview: 'App preview', activeNow: 'Visible now', scheduled: 'Scheduled', expired: 'Expired', audienceAll: 'Merchant and courier', imageHint: 'PNG, JPG, or WebP up to 5 MB.', safeLink: 'Internal path such as /app/orders or an https:// link', close: 'Close', audienceHint: 'It appears only to the selected audience or everyone.', calendarHint: 'Leave both dates blank for open publishing.', actionHint: 'Optional — used when the person taps the button.', unsaved: 'It reaches the app only after it is saved and published.',
    },
    ku: {
        title: 'ناوەڕۆکی ئەپ', eyebrow: 'ناوەندی بڵاوکردنەوە', intro: 'سلایدەری سەرەکی بۆ بازرگان و گەیەنەر لەم داشبۆردەوە بەڕێوەببە.', new: 'زیادکردنی سلاید', edit: 'دەستکاریکردنی سلاید', image: 'وێنەی سلاید', upload: 'بارکردنی وێنە', replace: 'گۆڕینی وێنە', noImage: 'پاشبنەمای ڕەنگی خۆکار', audience: 'ئامانج', all: 'هەمووان', merchant: 'بازرگان', courier: 'گەیەنەر', active: 'ئێستا بڵاوکراوە', order: 'ڕیزبەندی نیشاندان', schedule: 'کاتی بڵاوکردنەوە', starts: 'دەستپێدەکات لە', ends: 'کۆتایی دێت لە', Arabic: 'عەرەبی', English: 'ئینگلیزی', Kurdish: 'کوردی', heading: 'ناونیشان', body: 'دەقی ڕوونکەرەوە', tag: 'تاگی بچووک', button: 'دەقی دوگمە', link: 'بەستەری دوگمە', save: 'پاشەکەوتکردنی سلاید', cancel: 'هەڵوەشاندنەوە', delete: 'سڕینەوە', empty: 'هێشتا هیچ سلایدێکی بڵاوکراو نییە. یەکەم پەیام بۆ ئەپی مۆبایل زیاد بکە.', published: 'بڵاوکراوە', hidden: 'شاراوە', saveError: 'خانە دیاریکراوەکان بپشکنە و دووبارە هەوڵ بدە.', deleteConfirm: 'دڵنیایت لەم سڕینەوەیە؟', preview: 'پێشبینینی ئەپ', activeNow: 'ئێستا دیارە', scheduled: 'کات‌بەندی کراوە', expired: 'بەسەرچووە', audienceAll: 'بازرگان و گەیەنەر', imageHint: 'PNG، JPG یان WebP تا ٥ MB.', safeLink: 'ڕێڕەوی ناوخۆ وەک /app/orders یان بەستەری https://', close: 'داخستن', audienceHint: 'تەنها بۆ ئەو ئامانجە یان هەمووان پیشان دەدرێت.', calendarHint: 'بۆ بڵاوکردنەوەی کراوە هەردوو بەروار بەتاڵ بهێڵە.', actionHint: 'ئارەزوومەندانە — کاتێک بەکارهێنەر دوگمەکە دەکاتەوە.', unsaved: 'تەنها دوای پاشەکەوتکردن و بڵاوکردنەوە دەگاتە ئەپ.',
    },
}

const locale = computed(() => page.props.locale || 'ar')
const l = (key) => text[locale.value]?.[key] || text.ar[key] || key

const blank = () => ({
    audience: 'all', title_ar: '', title_en: '', title_ku: '',
    body_ar: '', body_en: '', body_ku: '',
    tag_ar: '', tag_en: '', tag_ku: '',
    cta_ar: '', cta_en: '', cta_ku: '',
    action_url: '', image: null, is_active: true, sort_order: props.slides.length + 1,
    starts_at: '', ends_at: '',
})

const form = useForm(blank())
const formError = computed(() => Object.values(form.errors)[0] || '')

function openCreate() {
    editing.value = null
    form.clearErrors()
    Object.assign(form, blank())
    imagePreview.value = ''
    modalOpen.value = true
}

function openEdit(slide) {
    editing.value = slide
    form.clearErrors()
    Object.assign(form, {
        audience: slide.audience || 'all',
        title_ar: slide.title_ar || '', title_en: slide.title_en || '', title_ku: slide.title_ku || '',
        body_ar: slide.body_ar || '', body_en: slide.body_en || '', body_ku: slide.body_ku || '',
        tag_ar: slide.tag_ar || '', tag_en: slide.tag_en || '', tag_ku: slide.tag_ku || '',
        cta_ar: slide.cta_ar || '', cta_en: slide.cta_en || '', cta_ku: slide.cta_ku || '',
        action_url: slide.action_url || '', image: null, is_active: Boolean(slide.is_active),
        sort_order: Number(slide.sort_order || 0), starts_at: slide.starts_at || '', ends_at: slide.ends_at || '',
    })
    imagePreview.value = slide.image_url || ''
    modalOpen.value = true
}

function closeModal() {
    modalOpen.value = false
    editing.value = null
    form.clearErrors()
    if (imageInput.value) imageInput.value.value = ''
}

function chooseImage() {
    imageInput.value?.click()
}

function onImage(event) {
    const file = event.target.files?.[0] || null
    form.image = file
    if (file) imagePreview.value = URL.createObjectURL(file)
}

function submit() {
    const options = {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: closeModal,
    }
    if (editing.value) form.put(route('admin.content.update', editing.value.id), options)
    else form.post(route('admin.content.store'), options)
}

function removeSlide(slide) {
    if (!window.confirm(l('deleteConfirm'))) return
    router.delete(route('admin.content.destroy', slide.id), { preserveScroll: true })
}

function audienceLabel(audience) {
    if (audience === 'merchant') return l('merchant')
    if (audience === 'courier') return l('courier')
    return l('audienceAll')
}

function publicationState(slide) {
    if (!slide.is_active) return { label: l('hidden'), className: 'hidden' }
    const now = Date.now()
    if (slide.starts_at && new Date(slide.starts_at).getTime() > now) return { label: l('scheduled'), className: 'scheduled' }
    if (slide.ends_at && new Date(slide.ends_at).getTime() < now) return { label: l('expired'), className: 'expired' }
    return { label: l('activeNow'), className: 'live' }
}

watch(() => props.slides.length, (length) => {
    if (!editing.value && !modalOpen.value) form.sort_order = length + 1
})
</script>

<template>
    <AdminShell :title="l('title')">
        <section class="content-heading">
            <div>
                <p class="eyebrow">{{ l('eyebrow') }}</p>
                <h2>{{ l('title') }}</h2>
                <p>{{ l('intro') }}</p>
            </div>
            <button class="btn primary" type="button" @click="openCreate">＋ {{ l('new') }}</button>
        </section>

        <section v-if="slides.length" class="slides-grid">
            <article v-for="slide in slides" :key="slide.id" class="slide-card" :class="{ muted: !slide.is_active }">
                <div class="slide-preview" :style="slide.image_url ? { backgroundImage: `linear-gradient(135deg, rgba(5, 37, 33, .74), rgba(5, 37, 33, .14)), url(${slide.image_url})` } : {}">
                    <span class="slide-state" :class="publicationState(slide).className">{{ publicationState(slide).label }}</span>
                    <div><small>{{ audienceLabel(slide.audience) }}</small><h3>{{ slide.title_ar }}</h3><p>{{ slide.body_ar }}</p></div>
                </div>
                <div class="slide-meta">
                    <span>#{{ slide.sort_order }}</span>
                    <span v-if="slide.starts_at || slide.ends_at">{{ slide.starts_at || '…' }} ← {{ slide.ends_at || '…' }}</span>
                    <span v-else>{{ l('published') }}</span>
                </div>
                <footer>
                    <button class="btn secondary" type="button" @click="openEdit(slide)">{{ l('edit') }}</button>
                    <button class="delete-button" type="button" @click="removeSlide(slide)">{{ l('delete') }}</button>
                </footer>
            </article>
        </section>
        <section v-else class="empty-state">
            <span>✦</span><b>{{ l('empty') }}</b><button class="btn primary" type="button" @click="openCreate">{{ l('new') }}</button>
        </section>

        <div v-if="modalOpen" class="modal-backdrop" @click.self="closeModal">
            <form class="slide-modal" @submit.prevent="submit">
                <header>
                    <div><span>{{ l('preview') }}</span><h3>{{ editing ? l('edit') : l('new') }}</h3><p>{{ l('unsaved') }}</p></div>
                    <button type="button" :aria-label="l('close')" @click="closeModal">×</button>
                </header>

                <div class="modal-scroll">
                    <section class="image-field">
                        <div class="preview-image" :class="{ blank: !imagePreview }" :style="imagePreview ? { backgroundImage: `linear-gradient(135deg, rgba(5, 37, 33, .62), rgba(5, 37, 33, .1)), url(${imagePreview})` } : {}"><span v-if="!imagePreview">{{ l('noImage') }}</span></div>
                        <div><b>{{ l('image') }}</b><small>{{ l('imageHint') }}</small><button type="button" class="btn secondary" @click="chooseImage">{{ imagePreview ? l('replace') : l('upload') }}</button><input ref="imageInput" type="file" accept="image/png,image/jpeg,image/webp" @change="onImage" /><small v-if="form.errors.image" class="error">{{ form.errors.image }}</small></div>
                    </section>

                    <div class="control-grid">
                        <label><span>{{ l('audience') }}</span><select v-model="form.audience"><option value="all">{{ l('all') }}</option><option value="merchant">{{ l('merchant') }}</option><option value="courier">{{ l('courier') }}</option></select><small>{{ l('audienceHint') }}</small></label>
                        <label><span>{{ l('order') }}</span><input v-model.number="form.sort_order" type="number" min="0" max="10000" required /><small v-if="form.errors.sort_order" class="error">{{ form.errors.sort_order }}</small></label>
                        <label class="toggle-row"><span><b>{{ l('active') }}</b><small>{{ l('published') }}</small></span><input v-model="form.is_active" type="checkbox" /></label>
                    </div>

                    <fieldset>
                        <legend>{{ l('Arabic') }}</legend>
                        <label><span>{{ l('heading') }}</span><input v-model.trim="form.title_ar" maxlength="160" required /><small v-if="form.errors.title_ar" class="error">{{ form.errors.title_ar }}</small></label>
                        <label><span>{{ l('body') }}</span><textarea v-model.trim="form.body_ar" rows="3" maxlength="1200" /></label>
                        <div class="two"><label><span>{{ l('tag') }}</span><input v-model.trim="form.tag_ar" maxlength="80" /></label><label><span>{{ l('button') }}</span><input v-model.trim="form.cta_ar" maxlength="80" /></label></div>
                    </fieldset>
                    <fieldset>
                        <legend>{{ l('English') }}</legend>
                        <label><span>{{ l('heading') }}</span><input v-model.trim="form.title_en" maxlength="160" dir="ltr" /></label>
                        <label><span>{{ l('body') }}</span><textarea v-model.trim="form.body_en" rows="3" maxlength="1200" dir="ltr" /></label>
                        <div class="two"><label><span>{{ l('tag') }}</span><input v-model.trim="form.tag_en" maxlength="80" dir="ltr" /></label><label><span>{{ l('button') }}</span><input v-model.trim="form.cta_en" maxlength="80" dir="ltr" /></label></div>
                    </fieldset>
                    <fieldset>
                        <legend>{{ l('Kurdish') }}</legend>
                        <label><span>{{ l('heading') }}</span><input v-model.trim="form.title_ku" maxlength="160" /></label>
                        <label><span>{{ l('body') }}</span><textarea v-model.trim="form.body_ku" rows="3" maxlength="1200" /></label>
                        <div class="two"><label><span>{{ l('tag') }}</span><input v-model.trim="form.tag_ku" maxlength="80" /></label><label><span>{{ l('button') }}</span><input v-model.trim="form.cta_ku" maxlength="80" /></label></div>
                    </fieldset>

                    <section class="action-section"><label><span>{{ l('link') }}</span><input v-model.trim="form.action_url" dir="ltr" maxlength="500" :placeholder="l('safeLink')" /><small>{{ l('actionHint') }}</small><small v-if="form.errors.action_url" class="error">{{ form.errors.action_url }}</small></label></section>
                    <section class="schedule"><div><b>{{ l('schedule') }}</b><small>{{ l('calendarHint') }}</small></div><div class="two"><label><span>{{ l('starts') }}</span><input v-model="form.starts_at" type="datetime-local" /></label><label><span>{{ l('ends') }}</span><input v-model="form.ends_at" type="datetime-local" /></label></div><small v-if="form.errors.ends_at" class="error">{{ form.errors.ends_at }}</small></section>
                </div>
                <p v-if="formError" class="form-error">{{ formError }}</p>
                <footer><button class="btn secondary" type="button" @click="closeModal">{{ l('cancel') }}</button><button class="btn primary" :disabled="form.processing">{{ form.processing ? '…' : l('save') }}</button></footer>
            </form>
        </div>
    </AdminShell>
</template>

<style scoped>
.content-heading{display:flex;align-items:end;justify-content:space-between;gap:18px;margin-bottom:21px}.eyebrow{margin:0 0 3px;color:var(--primary);font-size:10px;font-weight:900;letter-spacing:.1em;text-transform:uppercase}.content-heading h2{margin:0;color:var(--ink);font-size:24px;font-weight:950}.content-heading p{max-width:670px;margin:5px 0 0;color:var(--ink-faint);font-size:11.5px;font-weight:700;line-height:1.75}.btn{min-height:38px;padding:8px 12px;border:0;border-radius:10px;font:850 11px var(--font);cursor:pointer}.btn.primary{color:#062033;background:linear-gradient(135deg,var(--primary),#0ea5e9)}.btn.secondary{border:1px solid var(--border);color:var(--ink-soft);background:var(--surface-2)}.btn:disabled{opacity:.56;cursor:wait}.slides-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(255px,1fr));gap:15px}.slide-card{overflow:hidden;border:1px solid var(--border);border-radius:17px;background:var(--surface);box-shadow:0 11px 24px rgba(0,0,0,.08)}.slide-card.muted{opacity:.65}.slide-preview{min-height:158px;display:flex;align-items:end;position:relative;padding:15px;background:linear-gradient(135deg,var(--primary-strong),var(--primary));background-position:center;background-size:cover;color:#fff}.slide-preview:before{position:absolute;inset:0;background:linear-gradient(135deg,rgba(4,24,31,.16),rgba(4,24,31,.35));content:''}.slide-preview>div,.slide-state{position:relative;z-index:1}.slide-preview small{display:inline-flex;max-width:100%;margin-bottom:7px;padding:3px 7px;border-radius:99px;background:rgba(0,0,0,.25);font-size:8.5px;font-weight:850}.slide-preview h3{margin:0;font-size:16px;line-height:1.4}.slide-preview p{display:-webkit-box;overflow:hidden;margin:4px 0 0;font-size:10px;font-weight:650;line-height:1.55;-webkit-line-clamp:2;-webkit-box-orient:vertical}.slide-state{position:absolute;top:10px;inset-inline-start:10px;padding:4px 7px;border-radius:99px;background:#fff;color:#073139;font-size:8.5px;font-weight:900}.slide-state.hidden,.slide-state.expired{color:#991b1b;background:#fee2e2}.slide-state.scheduled{color:#92400e;background:#fef3c7}.slide-meta{display:flex;justify-content:space-between;gap:9px;padding:10px 13px;color:var(--ink-faint);font-size:9.5px;font-weight:750}.slide-meta span:last-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.slide-card footer{display:flex;justify-content:space-between;gap:9px;padding:10px 13px;border-top:1px solid var(--border)}.delete-button{border:0;color:var(--danger);background:transparent;font:850 10.5px var(--font);cursor:pointer}.empty-state{min-height:260px;display:grid;place-content:center;justify-items:center;gap:10px;border:1px dashed var(--border);border-radius:18px;color:var(--ink-faint);text-align:center}.empty-state span{width:49px;height:49px;display:grid;place-items:center;border-radius:15px;color:var(--primary-strong);background:var(--primary-tint);font-size:22px}.empty-state b{max-width:390px;font-size:12px;line-height:1.7}.modal-backdrop{position:fixed;z-index:110;inset:0;display:grid;place-items:center;padding:18px;background:rgba(3,10,22,.7);backdrop-filter:blur(4px)}.slide-modal{width:min(780px,100%);max-height:min(94dvh,950px);display:grid;grid-template-rows:auto minmax(0,1fr) auto;overflow:hidden;border:1px solid var(--border);border-radius:19px;background:var(--surface);box-shadow:0 30px 70px rgba(0,0,0,.4)}.slide-modal>header,.slide-modal>footer{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 18px;border-bottom:1px solid var(--border)}.slide-modal>header>div>span{color:var(--primary);font-size:9px;font-weight:900;letter-spacing:.09em;text-transform:uppercase}.slide-modal>header h3{margin:3px 0;color:var(--ink);font-size:17px}.slide-modal>header p{margin:0;color:var(--ink-faint);font-size:9.5px;font-weight:700}.slide-modal>header>button{width:30px;height:30px;border:0;border-radius:9px;color:var(--ink-soft);background:var(--surface-2);font-size:20px}.modal-scroll{display:grid;gap:15px;overflow:auto;padding:18px}.slide-modal>footer{justify-content:flex-end;border-top:1px solid var(--border);border-bottom:0}.image-field{display:flex;align-items:center;gap:14px;padding:12px;border:1px dashed var(--border);border-radius:14px;background:var(--surface-2)}.preview-image{width:158px;height:90px;display:grid;place-items:center;flex:none;border-radius:11px;background-position:center;background-size:cover;color:#fff;overflow:hidden}.preview-image.blank{background:linear-gradient(135deg,var(--primary-strong),var(--primary))}.preview-image span{padding:5px 7px;border-radius:7px;background:rgba(0,0,0,.2);font-size:9px;font-weight:800;text-align:center}.image-field>div:last-child{display:grid;justify-items:start;gap:3px}.image-field b{color:var(--ink);font-size:12px}.image-field small{color:var(--ink-faint);font-size:9.5px;font-weight:700}.image-field .btn{margin-top:5px}.image-field input{display:none}.control-grid{display:grid;grid-template-columns:1.1fr .6fr .9fr;gap:11px}.control-grid>label,.schedule label,fieldset label,.action-section label{display:grid;gap:5px;color:var(--ink-soft);font-size:10px;font-weight:850}.control-grid select,.control-grid input,fieldset input,fieldset textarea,.action-section input,.schedule input{width:100%;box-sizing:border-box;min-height:39px;padding:8px 9px;border:1px solid var(--border);border-radius:9px;outline:0;color:var(--ink);background:var(--surface-2);font:700 11px var(--font)}.control-grid select:focus,.control-grid input:focus,fieldset input:focus,fieldset textarea:focus,.action-section input:focus,.schedule input:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-tint)}.control-grid small,.schedule small,.action-section small{color:var(--ink-faint);font-size:8.7px;font-weight:700;line-height:1.45}.toggle-row{display:flex!important;align-items:center;justify-content:space-between;gap:8px;padding:8px 10px;border:1px solid var(--border);border-radius:9px;background:var(--surface-2)}.toggle-row span{display:grid;gap:1px}.toggle-row b{color:var(--ink);font-size:10px}.toggle-row input{width:17px;height:17px;min-height:0;padding:0;accent-color:var(--primary)}fieldset{display:grid;gap:10px;margin:0;padding:13px;border:1px solid var(--border);border-radius:13px}legend{padding:0 5px;color:var(--primary-strong);font-size:10px;font-weight:900}.two{display:grid;grid-template-columns:1fr 1fr;gap:10px}.schedule{display:grid;gap:10px;padding:13px;border:1px solid var(--border);border-radius:13px;background:var(--surface-2)}.schedule>div:first-child{display:grid;gap:3px}.schedule b{color:var(--ink);font-size:11px}.error,.form-error{color:var(--danger)!important;font-size:9.5px!important;font-weight:800!important}.form-error{margin:0 18px 12px}.action-section{padding:13px;border:1px solid var(--border);border-radius:13px;background:var(--surface-2)}@media(max-width:700px){.content-heading{align-items:start;flex-direction:column}.content-heading .btn{width:100%}.modal-backdrop{align-items:end;padding:0}.slide-modal{width:100%;max-height:94dvh;border-radius:19px 19px 0 0}.control-grid,.two{grid-template-columns:1fr}.image-field{align-items:start}.preview-image{width:106px;height:76px}.slides-grid{grid-template-columns:1fr}.modal-scroll{padding:14px}.slide-modal>header,.slide-modal>footer{padding:13px 14px}}@media(max-width:370px){.image-field{flex-direction:column}.preview-image{width:100%;height:105px}.image-field>div:last-child{justify-items:stretch}.image-field .btn{width:100%}}
</style>
