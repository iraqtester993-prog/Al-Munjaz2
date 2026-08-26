<script setup>
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import AdminShell from '../../Components/AdminShell.vue'

const props = defineProps({
    summary: { type: Object, default: () => ({}) },
    companies: { type: Array, default: () => [] },
    plans: { type: Array, default: () => [] },
    subscriptions: { type: Array, default: () => [] },
    invoices: { type: Array, default: () => [] },
    operators: { type: Array, default: () => [] },
    invitations: { type: Array, default: () => [] },
})

const page = usePage()
const locale = computed(() => page.props.locale || 'ar')
const activeTab = ref('overview')
const modal = ref(null)
const editing = ref(null)
const busy = ref(false)
const actionError = ref('')
const inviteLink = computed(() => page.props.flash?.invite_link || '')

const copy = {
    ar: {
        title: 'مركز إدارة المنصة', eyebrow: 'SaaS CONTROL', subtitle: 'الشركات، الباقات، الاشتراكات، الفواتير، وصلاحيات مشغلي لوحة الإدارة في مكان واحد.',
        overview: 'نظرة المنصة', companies: 'الشركات', plans: 'الباقات', billing: 'الاشتراكات والفواتير', people: 'المستخدمون والصلاحيات',
        activeSubs: 'اشتراكات نشطة', trials: 'فترات تجريبية', mrr: 'إيراد شهري متوقع', outstanding: 'فواتير مستحقة', operators: 'مشغلو المنصة',
        company: 'الشركة', package: 'الباقة', usage: 'استخدام الشهر', branches: 'الفروع', users: 'المستخدمون', status: 'الحالة', nextInvoice: 'الفاتورة التالية', actions: 'إجراءات',
        addCompany: 'شركة جديدة', editCompany: 'تعديل الشركة', companyName: 'اسم الشركة', companySlug: 'معرّف الشركة', companyStatus: 'حالة الشركة', trialEnds: 'ينتهي التجريبي في', billingPeriod: 'دورة الفوترة', monthly: 'شهري', annual: 'سنوي', save: 'حفظ', cancel: 'إلغاء',
        addPlan: 'باقة جديدة', editPlan: 'تعديل الباقة', planSlug: 'رمز الباقة', nameAr: 'الاسم بالعربية', nameEn: 'الاسم بالإنجليزية', nameKu: 'الاسم بالكردية', monthlyPrice: 'السعر الشهري', ordersLimit: 'حد الطلبات الشهري', branchesLimit: 'حد الفروع', usersLimit: 'حد المستخدمين', merchantsLimit: 'حد التجار', features: 'المزايا (افصل بفاصلة)', active: 'نشط', inactive: 'موقوف',
        newSubscription: 'اشتراك / تجديد', subscription: 'الاشتراك', starts: 'يبدأ', ends: 'ينتهي', autoRenew: 'تجديد تلقائي', amount: 'المبلغ', createInvoice: 'إنشاء فاتورة مع الاشتراك', activate: 'تفعيل', suspend: 'إيقاف', cancelSubscription: 'إلغاء الاشتراك',
        invoices: 'الفواتير', issueInvoice: 'إصدار فاتورة', invoice: 'الفاتورة', due: 'الاستحقاق', issued: 'الإصدار', paid: 'مدفوعة', overdue: 'متأخرة', draft: 'مسودة', void: 'ملغاة', markPaid: 'تأكيد الدفع', markOverdue: 'تحديد متأخرة', voidInvoice: 'إلغاء الفاتورة', note: 'ملاحظة',
        platformOperators: 'مشغلو لوحة الإدارة', inviteOperator: 'دعوة مشغّل', inviteName: 'الاسم', inviteEmail: 'البريد الإلكتروني', expiresDays: 'صلاحية الدعوة بالأيام', sendInvitation: 'إنشاء رابط دعوة آمن', copyLink: 'نسخ رابط الدعوة', linkReady: 'رابط الدعوة جاهز — أرسله عبر قناة آمنة، ولا تشاركه علناً.',
        pending: 'بانتظار القبول', accepted: 'تم القبول', expired: 'منتهية', lastActive: 'آخر نشاط', created: 'أُنشئ في', roleMatrix: 'مصفوفة الأدوار والصلاحيات', roleMatrixSub: 'الأدوار التشغيلية المعتمدة في نظام التوصيل. الدعوات في هذه الشاشة مخصصة لمشغلي لوحة الإدارة فقط.',
        noCompanies: 'لا توجد شركات بعد.', noInvoices: 'لا توجد فواتير بعد.', noInvites: 'لا توجد دعوات بعد.', noPlans: 'لا توجد باقات بعد.', noSubscriptions: 'لا توجد اشتراكات بعد.',
        trial: 'تجريبي', suspended: 'موقوف', cancelled: 'ملغى', expiredStatus: 'منتهي', issuedStatus: 'مستحقة', required: 'يرجى إكمال الحقول المطلوبة.',
        roleAdmin: 'مدير المنصة', roleOwner: 'مالك الشركة', roleBranch: 'مدير الفرع', roleMerchant: 'تاجر', rolePickup: 'مندوب استلام', roleDelivery: 'مندوب توزيع', roleTransporter: 'ناقل فروع',
        permAdmin: 'الشركات والباقات والاشتراكات والفواتير ومراقبة الاستخدام', permOwner: 'الفروع والمستخدمون والتسعير والصناديق والتقارير', permBranch: 'الفرز والتحويل وصندوق الفرع وتقارير الفرع', permMerchant: 'إنشاء الطلبات والتتبع وكشف الحساب وطلب التسوية', permPickup: 'استلام الطرود وتأكيد مرحلة الاستلام', permDelivery: 'التسليم والتحصيل وإرجاع الطلبات', permTransporter: 'رحلات نقل الطلبات بين الفروع بسجل حركة كامل',
    },
    en: {
        title: 'Platform control center', eyebrow: 'SaaS CONTROL', subtitle: 'Companies, packages, subscriptions, invoices, and dashboard-operator access in one place.',
        overview: 'Platform overview', companies: 'Companies', plans: 'Packages', billing: 'Subscriptions & invoices', people: 'Users & roles',
        activeSubs: 'Active subscriptions', trials: 'Trials', mrr: 'Expected monthly revenue', outstanding: 'Outstanding invoices', operators: 'Platform operators',
        company: 'Company', package: 'Package', usage: 'This-month usage', branches: 'Branches', users: 'Users', status: 'Status', nextInvoice: 'Next invoice', actions: 'Actions',
        addCompany: 'New company', editCompany: 'Edit company', companyName: 'Company name', companySlug: 'Company slug', companyStatus: 'Company status', trialEnds: 'Trial ends', billingPeriod: 'Billing period', monthly: 'Monthly', annual: 'Annual', save: 'Save', cancel: 'Cancel',
        addPlan: 'New package', editPlan: 'Edit package', planSlug: 'Package code', nameAr: 'Arabic name', nameEn: 'English name', nameKu: 'Kurdish name', monthlyPrice: 'Monthly price', ordersLimit: 'Monthly order limit', branchesLimit: 'Branch limit', usersLimit: 'User limit', merchantsLimit: 'Merchant limit', features: 'Features (comma separated)', active: 'Active', inactive: 'Inactive',
        newSubscription: 'Subscribe / renew', subscription: 'Subscription', starts: 'Starts', ends: 'Ends', autoRenew: 'Auto renew', amount: 'Amount', createInvoice: 'Create invoice with subscription', activate: 'Activate', suspend: 'Suspend', cancelSubscription: 'Cancel subscription',
        invoices: 'Invoices', issueInvoice: 'Issue invoice', invoice: 'Invoice', due: 'Due', issued: 'Issued', paid: 'Paid', overdue: 'Overdue', draft: 'Draft', void: 'Void', markPaid: 'Mark paid', markOverdue: 'Mark overdue', voidInvoice: 'Void invoice', note: 'Note',
        platformOperators: 'Dashboard operators', inviteOperator: 'Invite operator', inviteName: 'Name', inviteEmail: 'Email', expiresDays: 'Invitation validity (days)', sendInvitation: 'Create secure invitation link', copyLink: 'Copy invitation link', linkReady: 'The invitation link is ready — share it through a secure channel and never publish it.',
        pending: 'Awaiting acceptance', accepted: 'Accepted', expired: 'Expired', lastActive: 'Last active', created: 'Created', roleMatrix: 'Role & permission matrix', roleMatrixSub: 'Operational roles used by the delivery platform. Invitations here are restricted to dashboard operators.',
        noCompanies: 'No companies yet.', noInvoices: 'No invoices yet.', noInvites: 'No invitations yet.', noPlans: 'No packages yet.', noSubscriptions: 'No subscriptions yet.',
        trial: 'Trial', suspended: 'Suspended', cancelled: 'Cancelled', expiredStatus: 'Expired', issuedStatus: 'Issued', required: 'Please complete the required fields.',
        roleAdmin: 'Platform admin', roleOwner: 'Company owner', roleBranch: 'Branch manager', roleMerchant: 'Merchant', rolePickup: 'Pickup courier', roleDelivery: 'Delivery courier', roleTransporter: 'Branch transporter',
        permAdmin: 'Companies, packages, subscriptions, invoices, and usage monitoring', permOwner: 'Branches, users, pricing, cashboxes, and reports', permBranch: 'Sorting, transfers, branch cashbox, and branch reports', permMerchant: 'Create orders, tracking, statement, and settlement requests', permPickup: 'Parcel pickup and pickup-stage confirmation', permDelivery: 'Delivery, collection, and return handling', permTransporter: 'Inter-branch delivery trips with a complete movement log',
    },
    ku: {
        title: 'ناوەندی بەڕێوەبردنی پلاتفۆرم', eyebrow: 'SaaS CONTROL', subtitle: 'کۆمپانیاکان، پاکێجەکان، بەشداربوونەکان، پسوولەکان و دەستگەیشتنی بەڕێوەبەرانی داشبۆرد لە یەک شوێن.',
        overview: 'پوختەی پلاتفۆرم', companies: 'کۆمپانیاکان', plans: 'پاکێجەکان', billing: 'بەشداربوون و پسوولەکان', people: 'بەکارهێنەران و ڕۆڵەکان',
        activeSubs: 'بەشداربوونی چالاک', trials: 'تاقیکردنەوەکان', mrr: 'داهاتی مانگانەی چاوەڕوانکراو', outstanding: 'پسوولە نەپارێدراوەکان', operators: 'بەڕێوەبەرانی پلاتفۆرم',
        company: 'کۆمپانیا', package: 'پاکێج', usage: 'بەکارهێنانی ئەم مانگە', branches: 'لقەکان', users: 'بەکارهێنەران', status: 'دۆخ', nextInvoice: 'پسوولی داهاتوو', actions: 'کردارەکان',
        addCompany: 'کۆمپانیای نوێ', editCompany: 'دەستکاریکردنی کۆمپانیا', companyName: 'ناوی کۆمپانیا', companySlug: 'ناسنامەی کۆمپانیا', companyStatus: 'دۆخی کۆمپانیا', trialEnds: 'کۆتایی تاقیکردنەوە', billingPeriod: 'خولی پسوولە', monthly: 'مانگانە', annual: 'ساڵانە', save: 'پاشەکەوتکردن', cancel: 'هەڵوەشاندنەوە',
        addPlan: 'پاکێجی نوێ', editPlan: 'دەستکاریکردنی پاکێج', planSlug: 'کۆدی پاکێج', nameAr: 'ناوی عەرەبی', nameEn: 'ناوی ئینگلیزی', nameKu: 'ناوی کوردی', monthlyPrice: 'نرخی مانگانە', ordersLimit: 'سنووری داواکاریی مانگانە', branchesLimit: 'سنووری لق', usersLimit: 'سنووری بەکارهێنەر', merchantsLimit: 'سنووری بازرگان', features: 'تایبەتمەندییەکان (بە کاما جیا بکەوە)', active: 'چالاک', inactive: 'ناچالاک',
        newSubscription: 'بەشداربوون / نوێکردنەوە', subscription: 'بەشداربوون', starts: 'دەستپێدەکات', ends: 'کۆتایی دێت', autoRenew: 'نوێکردنەوەی خۆکار', amount: 'بڕ', createInvoice: 'دروستکردنی پسوولە لەگەڵ بەشداربوون', activate: 'چالاککردن', suspend: 'وەستاندن', cancelSubscription: 'هەڵوەشاندنی بەشداربوون',
        invoices: 'پسوولەکان', issueInvoice: 'دەرکردنی پسوولە', invoice: 'پسوولە', due: 'وادەی پارەدان', issued: 'دەرکراو', paid: 'پارێدراو', overdue: 'دواکەوتوو', draft: 'ڕەشنووس', void: 'هەڵوەشاوە', markPaid: 'دیاریکردنی پارێدراو', markOverdue: 'دیاریکردنی دواکەوتوو', voidInvoice: 'هەڵوەشاندنی پسوولە', note: 'تێبینی',
        platformOperators: 'بەڕێوەبەرانی داشبۆرد', inviteOperator: 'بانگهێشتی بەڕێوەبەر', inviteName: 'ناو', inviteEmail: 'ئیمەیڵ', expiresDays: 'ماوەی بانگهێشت (ڕۆژ)', sendInvitation: 'دروستکردنی بەستەری بانگهێشتی پارێزراو', copyLink: 'کۆپیکردنی بەستەری بانگهێشت', linkReady: 'بەستەری بانگهێشت ئامادەیە — لە ڕێگەی پارێزراو بینێرە و بە گشتی بڵاوی مەکەوە.',
        pending: 'چاوەڕێی وەرگرتن', accepted: 'وەرگیراوە', expired: 'بەسەرچووە', lastActive: 'دوا چالاکی', created: 'دروستکراوە', roleMatrix: 'ماتریسی ڕۆڵ و دەسەڵات', roleMatrixSub: 'ڕۆڵە کارپێکردنەکان لە پلاتفۆرمی گەیاندن بەکاردێن. بانگهێشتەکان لێرە تەنها بۆ بەڕێوەبەرانی داشبۆردن.',
        noCompanies: 'هێشتا هیچ کۆمپانیایەک نییە.', noInvoices: 'هێشتا هیچ پسوولەیەک نییە.', noInvites: 'هێشتا هیچ بانگهێشتێک نییە.', noPlans: 'هێشتا هیچ پاکێجێک نییە.', noSubscriptions: 'هێشتا هیچ بەشداربوونێک نییە.',
        trial: 'تاقیکردنەوە', suspended: 'وەستاو', cancelled: 'هەڵوەشاو', expiredStatus: 'بەسەرچوو', issuedStatus: 'دەرکراو', required: 'تکایە خانە پێویستەکان پڕ بکەرەوە.',
        roleAdmin: 'بەڕێوەبەری پلاتفۆرم', roleOwner: 'خاوەنی کۆمپانیا', roleBranch: 'بەڕێوەبەری لق', roleMerchant: 'بازرگان', rolePickup: 'پێشانده‌ری وەرگرتن', roleDelivery: 'پێشانده‌ری گەیاندن', roleTransporter: 'گواستنەوەی لقەکان',
        permAdmin: 'کۆمپانیاکان، پاکێجەکان، بەشداربوونەکان، پسوولەکان و چاودێری بەکارهێنان', permOwner: 'لقەکان، بەکارهێنەران، نرخدانان، سندوقەکان و ڕاپۆرتەکان', permBranch: 'جیاکردنەوە، گواستنەوە، سندوقی لق و ڕاپۆرتی لق', permMerchant: 'دروستکردنی داواکاری، شوێنکەوتن، کەشفی حیساب و داوای تەسویە', permPickup: 'وەرگرتنی پارچەکان و پشتڕاستکردنەوەی قۆناغی وەرگرتن', permDelivery: 'گەیاندن، کۆکردنەوە و گەڕاندنەوەی داواکاری', permTransporter: 'گەشتەکانی گواستنەوەی نێوان لقەکان بە تۆماری جوڵەی تەواو',
    },
}

const roles = [
    ['roleAdmin', 'permAdmin', 'admin'], ['roleOwner', 'permOwner', 'owner'], ['roleBranch', 'permBranch', 'branch_manager'],
    ['roleMerchant', 'permMerchant', 'merchant'], ['rolePickup', 'permPickup', 'pickup_courier'], ['roleDelivery', 'permDelivery', 'delivery_courier'], ['roleTransporter', 'permTransporter', 'transporter'],
]

function l(key) { return copy[locale.value]?.[key] || copy.ar[key] || key }
function money(value) { return `${fmt(Number(value || 0))} ${t('IQD')}` }
function planName(plan) { return plan?.[`name_${locale.value}`] || plan?.name_ar || plan?.name_en || '—' }
function statusLabel(status) {
    return ({ trial: l('trial'), active: l('active'), suspended: l('suspended'), cancelled: l('cancelled'), expired: l('expiredStatus'), draft: l('draft'), issued: l('issuedStatus'), paid: l('paid'), overdue: l('overdue'), void: l('void'), pending: l('pending'), accepted: l('accepted') })[status] || status
}
function statusClass(status) {
    return ({ active: 'success', paid: 'success', accepted: 'success', trial: 'warning', draft: 'neutral', pending: 'warning', issued: 'primary', overdue: 'danger', suspended: 'danger', cancelled: 'danger', expired: 'danger', void: 'neutral' })[status] || 'neutral'
}
function periodLabel(value) { return value === 'annual' ? l('annual') : l('monthly') }
function dateValue(value) { return value ? String(value).slice(0, 10) : '—' }
function startTab(tab) { activeTab.value = tab; modal.value = null; actionError.value = '' }

function blankCompany() { return { name: '', slug: '', plan_id: props.plans[0]?.id || '', status: 'trial', billing_period: 'monthly', trial_ends_at: '' } }
function blankPlan() { return { slug: '', name_ar: '', name_en: '', name_ku: '', price: 0, max_orders_month: '', max_branches: '', max_users: '', max_merchants: '', features_text: '', is_active: true } }
function blankSubscription(company = null) { return { tenant_id: company?.id || props.companies[0]?.id || '', plan_id: company?.plan?.id || props.plans[0]?.id || '', status: company?.status === 'suspended' ? 'suspended' : 'active', billing_period: company?.subscription?.billing_period || 'monthly', amount: company?.subscription?.amount ?? '', ends_at: '', auto_renew: true, create_invoice: true } }
function blankInvoice(company = null) { return { tenant_id: company?.id || props.companies[0]?.id || '', subscription_id: company?.subscription?.id || '', amount: company?.subscription?.amount ?? '', due_at: '', note: '' } }

const companyForm = ref(blankCompany())
const planForm = ref(blankPlan())
const subscriptionForm = ref(blankSubscription())
const invoiceForm = ref(blankInvoice())
const inviteForm = ref({ name: '', email: '', expires_in_days: 7 })

function openCompany(company = null) {
    editing.value = company
    companyForm.value = company ? { name: company.name, slug: company.slug, plan_id: company.plan?.id || '', status: company.status, billing_period: company.subscription?.billing_period || 'monthly', trial_ends_at: company.trial_ends_at || '' } : blankCompany()
    modal.value = 'company'
}
function openPlan(plan = null) {
    editing.value = plan
    planForm.value = plan ? {
        slug: plan.slug, name_ar: plan.name_ar || '', name_en: plan.name_en || '', name_ku: plan.name_ku || '', price: plan.price || 0,
        max_orders_month: plan.limits?.max_orders_month ?? '', max_branches: plan.limits?.max_branches ?? '', max_users: plan.limits?.max_users ?? '', max_merchants: plan.limits?.max_merchants ?? '',
        features_text: (plan.features || []).join(', '), is_active: !!plan.is_active,
    } : blankPlan()
    modal.value = 'plan'
}
function openSubscription(company = null) { editing.value = company; subscriptionForm.value = blankSubscription(company); modal.value = 'subscription' }
function openInvoice(company = null) { editing.value = company; invoiceForm.value = blankInvoice(company); modal.value = 'invoice' }
function openInvite() { editing.value = null; inviteForm.value = { name: '', email: '', expires_in_days: 7 }; modal.value = 'invite' }
function closeModal() { modal.value = null; editing.value = null; actionError.value = '' }

function send(method, url, payload, onSuccess = closeModal) {
    if (busy.value) return
    busy.value = true
    actionError.value = ''
    router[method](url, payload, {
        preserveScroll: true,
        onSuccess,
        onError: (errors) => { actionError.value = Object.values(errors)[0] || l('required') },
        onFinish: () => { busy.value = false },
    })
}
function submitCompany() {
    if (!companyForm.value.name || (!editing.value && !companyForm.value.slug) || (!editing.value && !companyForm.value.plan_id)) { actionError.value = l('required'); return }
    const payload = { ...companyForm.value, plan_id: Number(companyForm.value.plan_id) }
    if (editing.value) send('put', route('admin.platform.companies.update', editing.value.id), payload)
    else send('post', route('admin.platform.companies.store'), payload)
}
function submitPlan() {
    if (!planForm.value.name_ar || !planForm.value.name_en || (!editing.value && !planForm.value.slug)) { actionError.value = l('required'); return }
    const f = planForm.value
    const payload = {
        slug: f.slug || null, name_ar: f.name_ar, name_en: f.name_en, name_ku: f.name_ku || null, price: Number(f.price || 0), is_active: !!f.is_active,
        limits: { max_orders_month: nullableNumber(f.max_orders_month), max_branches: nullableNumber(f.max_branches), max_users: nullableNumber(f.max_users), max_merchants: nullableNumber(f.max_merchants) },
        features: f.features_text.split(',').map((item) => item.trim()).filter(Boolean),
    }
    if (editing.value) send('put', route('admin.platform.plans.update', editing.value.id), payload)
    else send('post', route('admin.platform.plans.store'), payload)
}
function nullableNumber(value) { return value === '' || value === null || value === undefined ? null : Number(value) }
function submitSubscription() {
    const f = subscriptionForm.value
    if (!f.tenant_id || !f.plan_id) { actionError.value = l('required'); return }
    send('post', route('admin.platform.subscriptions.store'), { ...f, tenant_id: Number(f.tenant_id), plan_id: Number(f.plan_id), amount: nullableNumber(f.amount), ends_at: f.ends_at || null, auto_renew: !!f.auto_renew, create_invoice: !!f.create_invoice })
}
function submitInvoice() {
    const f = invoiceForm.value
    if (!f.tenant_id || f.amount === '') { actionError.value = l('required'); return }
    send('post', route('admin.platform.invoices.store'), { ...f, tenant_id: Number(f.tenant_id), subscription_id: f.subscription_id ? Number(f.subscription_id) : null, amount: Number(f.amount), due_at: f.due_at || null })
}
function submitInvite() {
    if (!inviteForm.value.name || !inviteForm.value.email) { actionError.value = l('required'); return }
    send('post', route('admin.platform.invitations.store'), { ...inviteForm.value, expires_in_days: Number(inviteForm.value.expires_in_days) }, () => { modal.value = null })
}
function updateSubscription(subscription, status) {
    if (!confirm(`${l('status')}: ${statusLabel(status)}?`)) return
    send('patch', route('admin.platform.subscriptions.status', subscription.id), { status, auto_renew: status === 'active' ? subscription.auto_renew : false }, () => {})
}
function updateInvoice(invoice, status) {
    if (!confirm(`${l('status')}: ${statusLabel(status)}?`)) return
    send('patch', route('admin.platform.invoices.status', invoice.id), { status }, () => {})
}
function updateOperator(operator, status) {
    if (!confirm(`${l('status')}: ${statusLabel(status)}?`)) return
    send('post', route('admin.users.status', operator.id), { status }, () => {})
}
async function copyInviteLink() {
    if (!inviteLink.value) return
    try { await navigator.clipboard.writeText(inviteLink.value) } catch { window.prompt(l('copyLink'), inviteLink.value) }
}
</script>

<template>
    <AdminShell :title="l('title')">
        <header class="platform-heading">
            <div>
                <p>{{ l('eyebrow') }}</p>
                <h2>{{ l('title') }}</h2>
                <span>{{ l('subtitle') }}</span>
            </div>
            <div class="heading-actions">
                <button class="btn secondary" type="button" @click="openInvite">{{ l('inviteOperator') }}</button>
                <button class="btn primary" type="button" @click="openCompany">＋ {{ l('addCompany') }}</button>
            </div>
        </header>

        <nav class="platform-tabs" :aria-label="l('title')">
            <button v-for="tab in [['overview','overview'],['companies','companies'],['plans','plans'],['billing','billing'],['people','people']]" :key="tab[0]" type="button" :class="{ active: activeTab === tab[0] }" @click="startTab(tab[0])">{{ l(tab[1]) }}</button>
        </nav>

        <section v-if="activeTab === 'overview'" class="platform-overview">
            <div class="metric-grid">
                <article class="metric-card cyan"><small>{{ l('companies') }}</small><b>{{ summary.companies || 0 }}</b><span>{{ l('activeSubs') }}: {{ summary.active_subscriptions || 0 }}</span></article>
                <article class="metric-card violet"><small>{{ l('trials') }}</small><b>{{ summary.trials || 0 }}</b><span>{{ l('operators') }}: {{ summary.operators || 0 }}</span></article>
                <article class="metric-card gold"><small>{{ l('mrr') }}</small><b class="mono">{{ money(summary.monthly_revenue) }}</b><span>{{ l('monthly') }}</span></article>
                <article class="metric-card rose"><small>{{ l('outstanding') }}</small><b class="mono">{{ money(summary.outstanding) }}</b><span>{{ l('invoices') }}</span></article>
            </div>

            <div class="overview-grid">
                <section class="panel-surface">
                    <header class="panel-title"><div><h3>{{ l('companies') }}</h3><p>{{ l('usage') }}</p></div><button type="button" @click="startTab('companies')">{{ l('companies') }}</button></header>
                    <div v-if="companies.length" class="mini-list">
                        <article v-for="company in companies.slice(0, 6)" :key="company.id" class="company-line">
                            <div class="company-letter">{{ company.name?.charAt(0) }}</div>
                            <div class="line-main"><b>{{ company.name }}</b><span>{{ planName(company.plan) }} · {{ company.orders_this_month }} / {{ company.order_limit ?? '∞' }}</span></div>
                            <span class="status" :class="statusClass(company.status)">{{ statusLabel(company.status) }}</span>
                        </article>
                    </div>
                    <div v-else class="empty-card">{{ l('noCompanies') }}</div>
                </section>
                <section class="panel-surface">
                    <header class="panel-title"><div><h3>{{ l('invoices') }}</h3><p>{{ l('outstanding') }}</p></div><button type="button" @click="startTab('billing')">{{ l('billing') }}</button></header>
                    <div v-if="invoices.length" class="mini-list">
                        <article v-for="invoice in invoices.slice(0, 6)" :key="invoice.id" class="company-line">
                            <div class="invoice-letter">#</div>
                            <div class="line-main"><b class="mono">{{ invoice.number }}</b><span>{{ invoice.tenant?.name }} · {{ dateValue(invoice.due_at) }}</span></div>
                            <div class="line-end"><b class="mono">{{ money(invoice.amount) }}</b><span class="status" :class="statusClass(invoice.status)">{{ statusLabel(invoice.status) }}</span></div>
                        </article>
                    </div>
                    <div v-else class="empty-card">{{ l('noInvoices') }}</div>
                </section>
            </div>
        </section>

        <section v-else-if="activeTab === 'companies'" class="tab-section">
            <header class="section-heading"><div><h3>{{ l('companies') }}</h3><p>{{ l('subtitle') }}</p></div><button class="btn primary" type="button" @click="openCompany">＋ {{ l('addCompany') }}</button></header>
            <div v-if="companies.length" class="company-grid">
                <article v-for="company in companies" :key="company.id" class="company-card">
                    <header><div class="company-letter big">{{ company.name?.charAt(0) }}</div><div class="company-ident"><h3>{{ company.name }}</h3><p class="mono">{{ company.slug }}</p></div><span class="status" :class="statusClass(company.status)">{{ statusLabel(company.status) }}</span></header>
                    <div class="company-kpis"><span><small>{{ l('package') }}</small><b>{{ planName(company.plan) }}</b></span><span><small>{{ l('usage') }}</small><b>{{ company.orders_this_month }} / {{ company.order_limit ?? '∞' }}</b></span><span><small>{{ l('branches') }}</small><b>{{ company.branches_count }}</b></span><span><small>{{ l('users') }}</small><b>{{ company.users_count }}</b></span></div>
                    <div class="company-billing"><span>{{ l('subscription') }} <b>{{ company.subscription ? statusLabel(company.subscription.status) : '—' }}</b></span><span>{{ l('nextInvoice') }} <b>{{ company.next_invoice?.number || dateValue(company.subscription?.next_invoice_at) }}</b></span></div>
                    <footer><button type="button" @click="openCompany(company)">{{ l('editCompany') }}</button><button type="button" @click="openSubscription(company)">{{ l('newSubscription') }}</button><button type="button" @click="openInvoice(company)">{{ l('issueInvoice') }}</button></footer>
                </article>
            </div>
            <div v-else class="empty-card">{{ l('noCompanies') }}</div>
        </section>

        <section v-else-if="activeTab === 'plans'" class="tab-section">
            <header class="section-heading"><div><h3>{{ l('plans') }}</h3><p>{{ l('subtitle') }}</p></div><button class="btn primary" type="button" @click="openPlan">＋ {{ l('addPlan') }}</button></header>
            <div v-if="plans.length" class="plan-grid">
                <article v-for="plan in plans" :key="plan.id" class="plan-card" :class="{ off: !plan.is_active }">
                    <header><span class="plan-code mono">{{ plan.slug }}</span><span class="status" :class="plan.is_active ? 'success' : 'neutral'">{{ plan.is_active ? l('active') : l('inactive') }}</span></header>
                    <h3>{{ planName(plan) }}</h3><strong class="mono">{{ money(plan.price) }}<small> / {{ l('monthly') }}</small></strong>
                    <div class="plan-limits"><span>{{ plan.limits?.max_orders_month ?? '∞' }} {{ l('ordersLimit') }}</span><span>{{ plan.limits?.max_branches ?? '∞' }} {{ l('branchesLimit') }}</span><span>{{ plan.tenants_count }} {{ l('companies') }}</span></div>
                    <ul><li v-for="feature in plan.features || []" :key="feature">✓ {{ feature }}</li></ul>
                    <footer><span>{{ plan.subscriptions_count }} {{ l('subscription') }}</span><button type="button" @click="openPlan(plan)">{{ l('editPlan') }}</button></footer>
                </article>
            </div>
            <div v-else class="empty-card">{{ l('noPlans') }}</div>
        </section>

        <section v-else-if="activeTab === 'billing'" class="tab-section">
            <header class="section-heading"><div><h3>{{ l('billing') }}</h3><p>{{ l('subscription') }} · {{ l('invoices') }}</p></div><div class="heading-actions"><button class="btn secondary" type="button" @click="openInvoice">{{ l('issueInvoice') }}</button><button class="btn primary" type="button" @click="openSubscription">{{ l('newSubscription') }}</button></div></header>
            <div class="billing-stack">
                <section class="panel-surface wide-table"><header class="panel-title"><div><h3>{{ l('subscription') }}</h3><p>{{ l('activeSubs') }}</p></div></header>
                    <div v-if="subscriptions.length" class="table-wrap"><table><thead><tr><th>{{ l('company') }}</th><th>{{ l('package') }}</th><th>{{ l('status') }}</th><th>{{ l('amount') }}</th><th>{{ l('ends') }}</th><th>{{ l('actions') }}</th></tr></thead><tbody><tr v-for="sub in subscriptions" :key="sub.id"><td><b>{{ sub.tenant?.name }}</b></td><td>{{ planName(sub.plan) }}</td><td><span class="status" :class="statusClass(sub.status)">{{ statusLabel(sub.status) }}</span></td><td class="mono">{{ money(sub.amount) }}</td><td class="mono">{{ dateValue(sub.ends_at) }}</td><td class="table-actions"><button v-if="sub.status !== 'active'" type="button" @click="updateSubscription(sub, 'active')">{{ l('activate') }}</button><button v-if="sub.status === 'active'" type="button" @click="updateSubscription(sub, 'suspended')">{{ l('suspend') }}</button><button v-if="!['cancelled','expired'].includes(sub.status)" type="button" class="danger-link" @click="updateSubscription(sub, 'cancelled')">{{ l('cancelSubscription') }}</button></td></tr></tbody></table></div>
                    <div v-else class="empty-card">{{ l('noSubscriptions') }}</div>
                </section>
                <section class="panel-surface wide-table"><header class="panel-title"><div><h3>{{ l('invoices') }}</h3><p>{{ l('outstanding') }}</p></div></header>
                    <div v-if="invoices.length" class="table-wrap"><table><thead><tr><th>{{ l('invoice') }}</th><th>{{ l('company') }}</th><th>{{ l('amount') }}</th><th>{{ l('due') }}</th><th>{{ l('status') }}</th><th>{{ l('actions') }}</th></tr></thead><tbody><tr v-for="invoice in invoices" :key="invoice.id"><td class="mono"><b>{{ invoice.number }}</b></td><td>{{ invoice.tenant?.name }}</td><td class="mono">{{ money(invoice.amount) }}</td><td class="mono">{{ dateValue(invoice.due_at) }}</td><td><span class="status" :class="statusClass(invoice.status)">{{ statusLabel(invoice.status) }}</span></td><td class="table-actions"><button v-if="['draft','issued','overdue'].includes(invoice.status)" type="button" @click="updateInvoice(invoice, 'paid')">{{ l('markPaid') }}</button><button v-if="invoice.status === 'issued'" type="button" @click="updateInvoice(invoice, 'overdue')">{{ l('markOverdue') }}</button><button v-if="!['paid','void'].includes(invoice.status)" class="danger-link" type="button" @click="updateInvoice(invoice, 'void')">{{ l('voidInvoice') }}</button></td></tr></tbody></table></div>
                    <div v-else class="empty-card">{{ l('noInvoices') }}</div>
                </section>
            </div>
        </section>

        <section v-else class="tab-section">
            <header class="section-heading"><div><h3>{{ l('people') }}</h3><p>{{ l('roleMatrixSub') }}</p></div><button class="btn primary" type="button" @click="openInvite">＋ {{ l('inviteOperator') }}</button></header>
            <div v-if="inviteLink" class="invite-link"><div><b>{{ l('linkReady') }}</b><code>{{ inviteLink }}</code></div><button class="btn primary" type="button" @click="copyInviteLink">{{ l('copyLink') }}</button></div>
            <div class="people-grid">
                <section class="panel-surface operators-panel"><header class="panel-title"><div><h3>{{ l('platformOperators') }}</h3><p>{{ l('operators') }}: {{ operators.length }}</p></div></header>
                    <div v-if="operators.length" class="operator-list"><article v-for="operator in operators" :key="operator.id" class="operator-line"><div class="company-letter">{{ operator.name?.charAt(0) }}</div><div class="line-main"><b>{{ operator.name }}</b><span>{{ operator.email || operator.username }} · {{ l('lastActive') }}: {{ dateValue(operator.last_active_at) }}</span></div><span class="status" :class="statusClass(operator.status)">{{ statusLabel(operator.status) }}</span><button v-if="operator.status === 'active'" type="button" class="icon-action danger-link" @click="updateOperator(operator, 'suspended')">{{ l('suspend') }}</button><button v-else type="button" class="icon-action" @click="updateOperator(operator, 'active')">{{ l('activate') }}</button></article></div>
                </section>
                <section class="panel-surface"><header class="panel-title"><div><h3>{{ l('inviteOperator') }}</h3><p>{{ l('pending') }}</p></div></header>
                    <div v-if="invitations.length" class="operator-list"><article v-for="invite in invitations" :key="invite.id" class="operator-line"><div class="invoice-letter">✉</div><div class="line-main"><b>{{ invite.name }}</b><span>{{ invite.email }} · {{ dateValue(invite.expires_at) }}</span></div><span class="status" :class="statusClass(invite.state)">{{ statusLabel(invite.state) }}</span></article></div>
                    <div v-else class="empty-card">{{ l('noInvites') }}</div>
                </section>
            </div>
            <section class="role-section"><header class="section-heading small"><div><h3>{{ l('roleMatrix') }}</h3><p>{{ l('roleMatrixSub') }}</p></div></header><div class="role-grid"><article v-for="role in roles" :key="role[2]" class="role-card"><span class="role-icon">{{ role[2].slice(0, 1).toUpperCase() }}</span><div><h4>{{ l(role[0]) }}</h4><p>{{ l(role[1]) }}</p></div></article></div></section>
        </section>

        <div v-if="modal" class="modal-backdrop" @click.self="closeModal">
            <form v-if="modal === 'company'" class="modal-card" @submit.prevent="submitCompany"><header><div><h3>{{ editing ? l('editCompany') : l('addCompany') }}</h3><p>{{ l('companies') }}</p></div><button type="button" @click="closeModal">×</button></header><div class="form-grid"><label class="wide"><span>{{ l('companyName') }}</span><input v-model.trim="companyForm.name" required></label><label v-if="!editing"><span>{{ l('companySlug') }}</span><input v-model.trim="companyForm.slug" dir="ltr" required></label><label v-if="!editing"><span>{{ l('package') }}</span><select v-model="companyForm.plan_id" required><option v-for="plan in plans.filter(p => p.is_active)" :key="plan.id" :value="plan.id">{{ planName(plan) }}</option></select></label><label><span>{{ l('companyStatus') }}</span><select v-model="companyForm.status"><option value="trial">{{ l('trial') }}</option><option value="active">{{ l('active') }}</option><option value="suspended">{{ l('suspended') }}</option></select></label><label v-if="!editing"><span>{{ l('billingPeriod') }}</span><select v-model="companyForm.billing_period"><option value="monthly">{{ l('monthly') }}</option><option value="annual">{{ l('annual') }}</option></select></label><label v-if="companyForm.status === 'trial'" class="wide"><span>{{ l('trialEnds') }}</span><input v-model="companyForm.trial_ends_at" type="date"></label></div><p v-if="actionError" class="form-error">{{ actionError }}</p><footer><button class="btn secondary" type="button" @click="closeModal">{{ l('cancel') }}</button><button class="btn primary" :disabled="busy">{{ l('save') }}</button></footer></form>

            <form v-else-if="modal === 'plan'" class="modal-card" @submit.prevent="submitPlan"><header><div><h3>{{ editing ? l('editPlan') : l('addPlan') }}</h3><p>{{ l('plans') }}</p></div><button type="button" @click="closeModal">×</button></header><div class="form-grid"><label><span>{{ l('planSlug') }}</span><input v-model.trim="planForm.slug" dir="ltr" :disabled="!!editing" :required="!editing"></label><label><span>{{ l('monthlyPrice') }} ({{ t('IQD') }})</span><input v-model.number="planForm.price" min="0" type="number" required></label><label><span>{{ l('nameAr') }}</span><input v-model.trim="planForm.name_ar" required></label><label><span>{{ l('nameEn') }}</span><input v-model.trim="planForm.name_en" required></label><label class="wide"><span>{{ l('nameKu') }}</span><input v-model.trim="planForm.name_ku"></label><label><span>{{ l('ordersLimit') }}</span><input v-model="planForm.max_orders_month" min="0" type="number"></label><label><span>{{ l('branchesLimit') }}</span><input v-model="planForm.max_branches" min="0" type="number"></label><label><span>{{ l('usersLimit') }}</span><input v-model="planForm.max_users" min="0" type="number"></label><label><span>{{ l('merchantsLimit') }}</span><input v-model="planForm.max_merchants" min="0" type="number"></label><label class="wide"><span>{{ l('features') }}</span><textarea v-model="planForm.features_text" rows="3" /></label><label class="check wide"><input v-model="planForm.is_active" type="checkbox"><span>{{ l('active') }}</span></label></div><p v-if="actionError" class="form-error">{{ actionError }}</p><footer><button class="btn secondary" type="button" @click="closeModal">{{ l('cancel') }}</button><button class="btn primary" :disabled="busy">{{ l('save') }}</button></footer></form>

            <form v-else-if="modal === 'subscription'" class="modal-card" @submit.prevent="submitSubscription"><header><div><h3>{{ l('newSubscription') }}</h3><p>{{ l('subscription') }}</p></div><button type="button" @click="closeModal">×</button></header><div class="form-grid"><label class="wide"><span>{{ l('company') }}</span><select v-model="subscriptionForm.tenant_id" required><option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option></select></label><label><span>{{ l('package') }}</span><select v-model="subscriptionForm.plan_id" required><option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ planName(plan) }}</option></select></label><label><span>{{ l('status') }}</span><select v-model="subscriptionForm.status"><option value="trial">{{ l('trial') }}</option><option value="active">{{ l('active') }}</option><option value="suspended">{{ l('suspended') }}</option></select></label><label><span>{{ l('billingPeriod') }}</span><select v-model="subscriptionForm.billing_period"><option value="monthly">{{ l('monthly') }}</option><option value="annual">{{ l('annual') }}</option></select></label><label><span>{{ l('amount') }} ({{ t('IQD') }})</span><input v-model="subscriptionForm.amount" min="0" type="number"></label><label class="wide"><span>{{ l('ends') }}</span><input v-model="subscriptionForm.ends_at" type="date"></label><label class="check"><input v-model="subscriptionForm.auto_renew" type="checkbox"><span>{{ l('autoRenew') }}</span></label><label class="check"><input v-model="subscriptionForm.create_invoice" type="checkbox"><span>{{ l('createInvoice') }}</span></label></div><p v-if="actionError" class="form-error">{{ actionError }}</p><footer><button class="btn secondary" type="button" @click="closeModal">{{ l('cancel') }}</button><button class="btn primary" :disabled="busy">{{ l('save') }}</button></footer></form>

            <form v-else-if="modal === 'invoice'" class="modal-card" @submit.prevent="submitInvoice"><header><div><h3>{{ l('issueInvoice') }}</h3><p>{{ l('invoices') }}</p></div><button type="button" @click="closeModal">×</button></header><div class="form-grid"><label class="wide"><span>{{ l('company') }}</span><select v-model="invoiceForm.tenant_id" required><option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option></select></label><label><span>{{ l('subscription') }}</span><select v-model="invoiceForm.subscription_id"><option value="">—</option><option v-for="sub in subscriptions.filter(s => String(s.tenant_id) === String(invoiceForm.tenant_id))" :key="sub.id" :value="sub.id">{{ planName(sub.plan) }} · {{ dateValue(sub.ends_at) }}</option></select></label><label><span>{{ l('amount') }} ({{ t('IQD') }})</span><input v-model="invoiceForm.amount" min="0" type="number" required></label><label class="wide"><span>{{ l('due') }}</span><input v-model="invoiceForm.due_at" type="date"></label><label class="wide"><span>{{ l('note') }}</span><textarea v-model.trim="invoiceForm.note" rows="3" /></label></div><p v-if="actionError" class="form-error">{{ actionError }}</p><footer><button class="btn secondary" type="button" @click="closeModal">{{ l('cancel') }}</button><button class="btn primary" :disabled="busy">{{ l('issueInvoice') }}</button></footer></form>

            <form v-else class="modal-card compact" @submit.prevent="submitInvite"><header><div><h3>{{ l('inviteOperator') }}</h3><p>{{ l('platformOperators') }}</p></div><button type="button" @click="closeModal">×</button></header><div class="form-grid"><label class="wide"><span>{{ l('inviteName') }}</span><input v-model.trim="inviteForm.name" required></label><label class="wide"><span>{{ l('inviteEmail') }}</span><input v-model.trim="inviteForm.email" dir="ltr" type="email" required></label><label><span>{{ l('expiresDays') }}</span><input v-model.number="inviteForm.expires_in_days" type="number" min="1" max="30" required></label></div><p v-if="actionError" class="form-error">{{ actionError }}</p><footer><button class="btn secondary" type="button" @click="closeModal">{{ l('cancel') }}</button><button class="btn primary" :disabled="busy">{{ l('sendInvitation') }}</button></footer></form>
        </div>
    </AdminShell>
</template>

<style scoped>
.platform-heading,.section-heading{display:flex;align-items:end;justify-content:space-between;gap:18px;margin-bottom:18px}.platform-heading p{margin:0 0 5px;color:var(--primary);font-size:10px;font-weight:900;letter-spacing:.11em}.platform-heading h2{margin:0;color:var(--ink);font-size:25px;font-weight:950}.platform-heading span,.section-heading p{display:block;max-width:720px;margin-top:5px;color:var(--ink-faint);font-size:11px;font-weight:700;line-height:1.75}.heading-actions{display:flex;gap:9px;flex-wrap:wrap}.btn{min-height:38px;padding:8px 13px;border:0;border-radius:10px;font:850 11px var(--font);cursor:pointer;white-space:nowrap}.btn.primary{color:#062033;background:linear-gradient(135deg,var(--primary),#0ea5e9)}.btn.secondary{color:var(--ink-soft);background:var(--surface-2);border:1px solid var(--border)}.btn:disabled{opacity:.55;cursor:wait}.platform-tabs{display:flex;gap:7px;overflow:auto;margin:0 -2px 20px;padding:3px 2px 7px;scrollbar-width:none}.platform-tabs::-webkit-scrollbar{display:none}.platform-tabs button{flex:none;padding:9px 13px;border:1px solid var(--border);border-radius:10px;color:var(--ink-faint);background:var(--surface);font:850 10.5px var(--font)}.platform-tabs button.active{border-color:transparent;color:#062033;background:var(--primary)}.metric-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:13px}.metric-card{min-height:126px;position:relative;overflow:hidden;padding:16px;border:1px solid var(--border);border-radius:16px;background:var(--surface)}.metric-card:after{position:absolute;width:90px;height:90px;inset:auto -27px -42px auto;border-radius:50%;content:'';opacity:.17}.metric-card.cyan:after{background:var(--primary)}.metric-card.violet:after{background:#a78bfa}.metric-card.gold:after{background:var(--accent)}.metric-card.rose:after{background:var(--danger)}.metric-card small,.metric-card span{display:block;position:relative;color:var(--ink-faint);font-size:10px;font-weight:800}.metric-card b{display:block;position:relative;margin:10px 0 5px;color:var(--ink);font-size:22px;font-weight:950}.metric-card b.mono{font-size:15px}.overview-grid,.people-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px}.panel-surface{overflow:hidden;border:1px solid var(--border);border-radius:16px;background:var(--surface);box-shadow:0 12px 25px rgba(0,0,0,.04)}.panel-title{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:15px 16px;border-bottom:1px solid var(--border)}.panel-title h3,.section-heading h3{margin:0;color:var(--ink);font-size:14px;font-weight:900}.panel-title p{margin:2px 0 0;color:var(--ink-faint);font-size:9.5px;font-weight:750}.panel-title button{border:0;color:var(--primary-strong);background:transparent;font:850 10px var(--font)}.mini-list,.operator-list{display:grid}.company-line,.operator-line{display:flex;align-items:center;gap:10px;padding:12px 15px;border-bottom:1px solid var(--border)}.company-line:last-child,.operator-line:last-child{border-bottom:0}.company-letter,.invoice-letter{width:31px;height:31px;display:grid;place-items:center;flex:none;border-radius:10px;color:#062033;background:var(--primary);font-size:12px;font-weight:950}.invoice-letter{color:var(--accent);background:var(--accent-tint)}.line-main{min-width:0;flex:1}.line-main b,.line-main span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.line-main b{color:var(--ink);font-size:11px;font-weight:900}.line-main span{margin-top:2px;color:var(--ink-faint);font-size:9.5px;font-weight:700}.line-end{display:grid;justify-items:end;gap:4px}.line-end>b{color:var(--ink);font-size:10px}.status{display:inline-flex;align-items:center;justify-content:center;flex:none;padding:3px 7px;border-radius:99px;font-size:9px;font-weight:900;white-space:nowrap}.status.success{color:var(--success);background:var(--success-tint)}.status.warning{color:var(--warning);background:var(--warning-tint)}.status.primary{color:var(--primary-strong);background:var(--primary-tint)}.status.danger{color:var(--danger);background:var(--danger-tint)}.status.neutral{color:var(--ink-faint);background:var(--surface-2)}.empty-card{padding:30px 16px;color:var(--ink-faint);font-size:11px;font-weight:750;text-align:center}.tab-section{min-width:0}.section-heading h3{font-size:18px}.section-heading.small{align-items:start;margin:23px 0 12px}.company-grid,.plan-grid,.role-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(275px,1fr));gap:13px}.company-card,.plan-card,.role-card{overflow:hidden;border:1px solid var(--border);border-radius:16px;background:var(--surface)}.company-card>header{display:flex;align-items:center;gap:10px;padding:15px}.company-letter.big{width:39px;height:39px;border-radius:12px;font-size:15px}.company-ident{min-width:0;flex:1}.company-ident h3{overflow:hidden;margin:0;color:var(--ink);font-size:13px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.company-ident p{margin:2px 0 0;color:var(--ink-faint);font-size:9px}.company-kpis{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--border)}.company-kpis span{min-width:0;padding:11px 14px;background:var(--surface)}.company-kpis small,.company-kpis b{display:block}.company-kpis small{color:var(--ink-faint);font-size:8.5px;font-weight:750}.company-kpis b{overflow:hidden;margin-top:3px;color:var(--ink);font-size:10.5px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.company-billing{display:flex;justify-content:space-between;gap:8px;padding:11px 14px;color:var(--ink-faint);font-size:9px;font-weight:750}.company-billing b{color:var(--ink-soft)}.company-card footer,.plan-card footer{display:flex;justify-content:space-between;gap:8px;padding:11px 14px;border-top:1px solid var(--border)}.company-card footer button,.plan-card footer button,.table-actions button,.icon-action{border:0;color:var(--primary-strong);background:transparent;font:850 9.5px var(--font);cursor:pointer}.danger-link{color:var(--danger)!important}.plan-card{padding:15px}.plan-card.off{opacity:.57}.plan-card header{display:flex;justify-content:space-between;align-items:center}.plan-code{padding:4px 7px;border-radius:7px;color:var(--primary-strong);background:var(--primary-tint);font-size:9px;font-weight:900}.plan-card h3{margin:13px 0 5px;color:var(--ink);font-size:15px}.plan-card>strong{color:var(--ink);font-size:16px}.plan-card>strong small{color:var(--ink-faint);font-size:9px}.plan-limits{display:grid;gap:5px;margin:13px 0;padding:11px;border-radius:11px;background:var(--surface-2)}.plan-limits span{color:var(--ink-soft);font-size:9.5px;font-weight:750}.plan-card ul{display:grid;gap:5px;min-height:59px;margin:0 0 11px;color:var(--ink-soft);font-size:9.5px;font-weight:750}.plan-card footer{margin:0 -15px -15px}.plan-card footer>span{color:var(--ink-faint);font-size:9px;font-weight:750}.billing-stack{display:grid;gap:14px}.wide-table{min-width:0}.table-wrap{overflow:auto}table{width:100%;min-width:740px;border-collapse:collapse;text-align:start}th,td{padding:11px 14px;border-bottom:1px solid var(--border);vertical-align:middle}th{color:var(--ink-faint);background:var(--surface-2);font-size:9px;font-weight:900;white-space:nowrap}td{color:var(--ink-soft);font-size:10px;font-weight:700}td>b{color:var(--ink)}.table-actions{display:flex;align-items:center;gap:8px;white-space:nowrap}.invite-link{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;padding:13px 14px;border:1px solid rgba(34,211,238,.28);border-radius:14px;background:var(--primary-tint)}.invite-link b{display:block;color:var(--ink);font-size:10.5px}.invite-link code{display:block;max-width:620px;overflow:auto;margin-top:6px;color:var(--primary-strong);font:9px Consolas,monospace;direction:ltr;text-align:start}.operators-panel{min-width:0}.icon-action{padding:5px}.role-section{margin-top:4px}.role-grid{grid-template-columns:repeat(auto-fill,minmax(220px,1fr))}.role-card{display:flex;align-items:start;gap:10px;padding:14px}.role-icon{width:31px;height:31px;display:grid;place-items:center;flex:none;border-radius:10px;color:#062033;background:var(--primary);font-size:11px;font-weight:950}.role-card h4{margin:0;color:var(--ink);font-size:11px;font-weight:900}.role-card p{margin:4px 0 0;color:var(--ink-faint);font-size:9px;font-weight:700;line-height:1.7}.modal-backdrop{position:fixed;z-index:100;inset:0;display:grid;place-items:center;padding:18px;background:rgba(3,10,22,.68);backdrop-filter:blur(4px)}.modal-card{width:min(100%,720px);overflow:hidden;border:1px solid var(--border);border-radius:18px;background:var(--surface);box-shadow:0 28px 70px rgba(0,0,0,.35)}.modal-card.compact{width:min(100%,510px)}.modal-card header,.modal-card footer{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 18px;border-bottom:1px solid var(--border)}.modal-card footer{justify-content:flex-end;border-top:1px solid var(--border);border-bottom:0}.modal-card header h3{margin:0;color:var(--ink);font-size:14px}.modal-card header p{margin:3px 0 0;color:var(--ink-faint);font-size:9.5px;font-weight:750}.modal-card header>button{width:27px;height:27px;border:0;border-radius:8px;color:var(--ink-soft);background:var(--surface-2);font-size:19px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:17px}.form-grid label{display:grid;gap:5px;color:var(--ink-soft);font-size:10px;font-weight:850}.form-grid .wide{grid-column:1/-1}.form-grid input,.form-grid select,.form-grid textarea{width:100%;min-height:39px;padding:8px 9px;border:1px solid var(--border);border-radius:9px;outline:0;color:var(--ink);background:var(--surface-2);font:700 11px var(--font)}.form-grid textarea{resize:vertical}.form-grid input:focus,.form-grid select:focus,.form-grid textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-tint)}.form-grid .check{display:flex;align-items:center;gap:8px;padding-top:7px}.form-grid .check input{width:15px;min-height:15px;accent-color:var(--primary)}.form-error{margin:-3px 17px 13px;color:var(--danger);font-size:10px;font-weight:800}@media(max-width:1020px){.metric-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:720px){.platform-heading,.section-heading{align-items:start;flex-direction:column}.heading-actions{width:100%}.heading-actions .btn{flex:1}.overview-grid,.people-grid{grid-template-columns:1fr}.metric-grid{gap:9px}.metric-card{min-height:108px;padding:13px}.platform-heading h2{font-size:21px}.modal-backdrop{align-items:end;padding:0}.modal-card{width:100%;max-height:94dvh;overflow:auto;border-radius:18px 18px 0 0}.form-grid{grid-template-columns:1fr}.form-grid .wide{grid-column:auto}.company-billing{flex-direction:column}.invite-link{align-items:stretch;flex-direction:column}.invite-link .btn{width:100%}}@media(max-width:390px){.metric-grid{grid-template-columns:1fr}.company-card footer{flex-wrap:wrap}}
</style>
