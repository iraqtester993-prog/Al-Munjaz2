<script setup>
import { computed, defineAsyncComponent, onMounted, ref, watch } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Flash from '../../Components/Flash.vue'
import SheetModal from '../../Components/SheetModal.vue'

const CourierLocationsMap = defineAsyncComponent(() => import('../../Components/CourierLocationsMap.vue'))

const props = defineProps({
    branches: { type: Array, default: () => [] },
    recentOrders: { type: Array, default: () => [] },
    orders: { type: Array, default: () => [] },
    orderCouriers: { type: Array, default: () => [] },
    merchants: { type: Array, default: () => [] },
    couriers: { type: Array, default: () => [] },
    courierLocations: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    operationalDashboard: { type: Object, default: () => ({}) },
})

const page = usePage()
const selectedBranchKey = ref('all')
const activeView = ref('overview')
const loadedViews = ref({ orders: false, merchants: false, couriers: false, locations: false })
const loadingViews = ref({ orders: false, merchants: false, couriers: false, locations: false })
const theme = ref('light')
const locale = ref('ar')
const showAllLocationsMap = ref(false)
const selectedOrder = ref(null)
const detailsPerson = ref(null)
const detailsKind = ref('merchant')
const editingPerson = ref(null)
const editingKind = ref('merchant')
const accountForm = useForm({
    name: '',
    username: '',
    email: '',
    phone: '',
    shop_name: '',
    address: '',
    vehicle: '',
})
const orderStatusForm = useForm({ status: '', note: '' })
const courierAssignmentForm = useForm({ courier_id: '', assignment_role: 'courier' })

const copy = {
    ar: {
        portal: 'بوابة الفروع',
        subtitle: 'نظرة آمنة ومركزة على الفروع المخوّلة لك فقط.',
        allBranches: 'كل الفروع المصرّح بها',
        branchDetails: 'تفاصيل الفرع',
        branches: 'الفروع',
        activeBranches: 'الفروع النشطة',
        totalOrders: 'إجمالي الطلبات',
        operationalDetails: 'تفاصيل التشغيل',
        statusSummary: 'ملخص حالات الطلبات',
        financialSummary: 'الملخص المالي',
        weeklyActivity: 'نشاط الطلبات الأسبوعي',
        topMerchants: 'أعلى التجار نشاطاً',
        orderValue: 'قيمة الطلبات',
        deliveredValue: 'قيمة الطلبات المسلّمة',
        deliveryFees: 'أجور التوصيل',
        merchantBalance: 'أرصدة التجار',
        courierBudget: 'ميزانيات المندوبين',
        activeOrders: 'طلبات قيد التشغيل',
        deliveredOrders: 'الطلبات المسلّمة',
        todayOrders: 'طلبات اليوم',
        cashBalance: 'رصيد الصندوق',
        orderActivity: 'حركة الطلبات الأخيرة',
        noOrders: 'لا توجد طلبات ضمن الفرع المحدد حالياً.',
        noBranches: 'لا توجد فروع مخوّلة لهذا الحساب حالياً.',
        access: 'نوع الوصول',
        owner: 'مالك الفرع',
        manager: 'مدير الفرع',
        active: 'نشط',
        inactive: 'موقوف',
        branchCode: 'رمز الفرع',
        contact: 'بيانات الاتصال',
        city: 'المدينة',
        address: 'العنوان',
        phone: 'الهاتف',
        order: 'طلب',
        customer: 'العميل',
        date: 'التاريخ',
        status: 'الحالة',
        value: 'القيمة',
        overview: 'نظرة عامة',
        orders: 'الطلبات',
        merchants: 'التجار',
        couriers: 'المندوبون',
        merchantsCount: 'التجار المسجلون',
        couriersCount: 'المندوبون المسجلون',
        onlineCouriers: 'المندوبون المتاحون',
        orderList: 'قائمة طلبات الفرع',
        merchantList: 'تجار الفرع',
        courierList: 'مندوبي الفرع',
        courierLocations: 'مواقع المندوبين',
        allCourierLocations: 'جميع مواقع المندوبين',
        courierLocationHint: 'تظهر المواقع الحديثة التي شاركها مندوبو الفروع المحددة فقط.',
        noCourierLocations: 'لا توجد مواقع حديثة للمندوبين ضمن الفروع المحددة.',
        showAllLocations: 'عرض جميع المواقع على الخريطة',
        noScopedOrders: 'لا توجد طلبات ضمن الفرع المحدد حالياً.',
        noMerchants: 'لا يوجد تجار مسجلون في هذا الفرع حالياً.',
        noCouriers: 'لا يوجد مندوبون مسجلون في هذا الفرع حالياً.',
        unassigned: 'غير معيّن',
        available: 'متاح',
        offline: 'غير متاح',
        pending: 'قيد المراجعة',
        rejected: 'مرفوض',
        shop: 'المتجر',
        vehicle: 'المركبة',
        assignedCourier: 'المندوب المكلّف',
        orderBranch: 'فرع التشغيل',
        deliveryFee: 'أجرة التوصيل',
        joined: 'تاريخ التسجيل',
        includedBranches: 'الفروع المرتبطة',
        lastUpdated: 'تُعرض البيانات من النظام التشغيلي مباشرة.',
        logout: 'تسجيل الخروج',
        language: 'اللغة',
        light: 'الوضع الفاتح',
        dark: 'الوضع الداكن',
        noContact: 'لا توجد بيانات اتصال مسجلة',
        selectBranch: 'اختر فرعاً لعرض تفاصيله',
        noData: 'لا توجد بيانات بعد',
        loading: 'جارٍ التحميل…',
        currency: 'د.ع',
        role: 'الدور',
        manage: 'إدارة',
        viewDetails: 'عرض التفاصيل',
        edit: 'تعديل',
        save: 'حفظ التعديل',
        close: 'إغلاق',
        activate: 'تفعيل',
        suspend: 'تعطيل',
        delete: 'حذف',
        accountDetails: 'تفاصيل الحساب',
        email: 'البريد الإلكتروني',
        username: 'اسم المستخدم',
        identityNumber: 'رقم الهوية',
        verification: 'توثيق الحساب',
        verified: 'موثّق',
        grantVerification: 'منح التوثيق',
        removeVerification: 'إزالة التوثيق',
        documents: 'الوثائق',
        approve: 'اعتماد',
        reject: 'رفض',
        viewDocument: 'عرض الوثيقة',
        orderManagement: 'إدارة الطلب',
        updateStatus: 'تغيير الحالة',
        administrativeNote: 'ملاحظة تشغيلية',
        assignCourier: 'تعيين مندوب',
        chooseCourier: 'اختر المندوب',
        reofferOverdue: 'إعادة طرح الطلب المتأخر',
        orderNote: 'ملاحظة الطلب',
        vehicleNote: 'ملاحظة وسيلة النقل',
        pickupDeadline: 'مهلة الوصول للتاجر',
        noNote: 'لا توجد ملاحظة',
        pendingAssignment: 'بانتظار تعيين المندوب',
        notSubmitted: 'لم تُرفع الوثائق',
        confirmDelete: 'سيتم حذف الحساب بشكل قابل للاستعادة، بشرط عدم ارتباطه بطلبات مفتوحة. هل تريد المتابعة؟',
    },
    en: {
        portal: 'Branch Portal',
        subtitle: 'A secure, focused view of only the branches assigned to you.',
        allBranches: 'All authorised branches',
        branchDetails: 'Branch details',
        branches: 'Branches',
        activeBranches: 'Active branches',
        totalOrders: 'Total orders',
        operationalDetails: 'Operational detail',
        statusSummary: 'Order status summary',
        financialSummary: 'Financial summary',
        weeklyActivity: 'Weekly order activity',
        topMerchants: 'Top merchants',
        orderValue: 'Order value',
        deliveredValue: 'Delivered value',
        deliveryFees: 'Delivery fees',
        merchantBalance: 'Merchant balances',
        courierBudget: 'Courier budgets',
        activeOrders: 'Active orders',
        deliveredOrders: 'Delivered orders',
        todayOrders: 'Today’s orders',
        cashBalance: 'Cashbox balance',
        orderActivity: 'Recent order activity',
        noOrders: 'There are no orders for the selected branch yet.',
        noBranches: 'There are no branches assigned to this account yet.',
        access: 'Access level',
        owner: 'Branch owner',
        manager: 'Branch manager',
        active: 'Active',
        inactive: 'Inactive',
        branchCode: 'Branch code',
        contact: 'Contact details',
        city: 'City',
        address: 'Address',
        phone: 'Phone',
        order: 'Order',
        customer: 'Customer',
        date: 'Date',
        status: 'Status',
        value: 'Value',
        overview: 'Overview',
        orders: 'Orders',
        merchants: 'Merchants',
        couriers: 'Couriers',
        merchantsCount: 'Registered merchants',
        couriersCount: 'Registered couriers',
        onlineCouriers: 'Available couriers',
        orderList: 'Branch order list',
        merchantList: 'Branch merchants',
        courierList: 'Branch couriers',
        courierLocations: 'Courier locations',
        allCourierLocations: 'All courier locations',
        courierLocationHint: 'Only current locations shared by couriers in the selected branches are shown.',
        noCourierLocations: 'There are no current courier locations in the selected branches.',
        showAllLocations: 'Show all locations on map',
        noScopedOrders: 'There are no orders in the selected branch yet.',
        noMerchants: 'There are no merchants registered in this branch yet.',
        noCouriers: 'There are no couriers registered in this branch yet.',
        unassigned: 'Unassigned',
        available: 'Available',
        offline: 'Offline',
        pending: 'Pending review',
        rejected: 'Rejected',
        shop: 'Shop',
        vehicle: 'Vehicle',
        assignedCourier: 'Assigned courier',
        orderBranch: 'Operating branch',
        deliveryFee: 'Delivery fee',
        joined: 'Joined',
        includedBranches: 'Related branches',
        lastUpdated: 'Data is shown directly from the operational system.',
        logout: 'Log out',
        language: 'Language',
        light: 'Light mode',
        dark: 'Dark mode',
        noContact: 'No contact information is on record',
        selectBranch: 'Choose a branch to see its details',
        noData: 'No data yet',
        loading: 'Loading…',
        currency: 'IQD',
        role: 'Role',
        manage: 'Manage',
        viewDetails: 'View details',
        edit: 'Edit',
        save: 'Save changes',
        close: 'Close',
        activate: 'Activate',
        suspend: 'Suspend',
        delete: 'Delete',
        accountDetails: 'Account details',
        email: 'Email',
        username: 'Username',
        identityNumber: 'Identity number',
        verification: 'Account verification',
        verified: 'Verified',
        grantVerification: 'Grant verification',
        removeVerification: 'Remove verification',
        documents: 'Documents',
        approve: 'Approve',
        reject: 'Reject',
        viewDocument: 'View document',
        orderManagement: 'Manage order',
        updateStatus: 'Update status',
        administrativeNote: 'Operational note',
        assignCourier: 'Assign courier',
        chooseCourier: 'Choose courier',
        reofferOverdue: 'Re-offer overdue order',
        orderNote: 'Order note',
        vehicleNote: 'Vehicle note',
        pickupDeadline: 'Pickup deadline',
        noNote: 'No note',
        pendingAssignment: 'Waiting for courier assignment',
        notSubmitted: 'Documents not submitted',
        confirmDelete: 'The account is recoverable, but cannot be deleted while it has open orders. Continue?',
    },
    ku: {
        portal: 'پۆرتاڵی لقەکان',
        subtitle: 'بینینێکی پارێزراو و سەرنج‌دراو بۆ تەنها ئەو لقانەی مۆڵەتت پێدراوە.',
        allBranches: 'هەموو لقە ڕێگەپێدراوەکان',
        branchDetails: 'وردەکاری لق',
        branches: 'لقەکان',
        activeBranches: 'لقە چالاکەکان',
        totalOrders: 'کۆی داواکارییەکان',
        operationalDetails: 'وردەکاریی کارپێکردن',
        statusSummary: 'کورتەی دۆخی داواکارییەکان',
        financialSummary: 'کورتەی دارایی',
        weeklyActivity: 'چالاکیی هەفتانەی داواکارییەکان',
        topMerchants: 'چالاکترین بازرگانەکان',
        orderValue: 'بەهای داواکارییەکان',
        deliveredValue: 'بەهای داواکارییە گەیەنراوەکان',
        deliveryFees: 'کرێی گەیاندن',
        merchantBalance: 'باڵانسی بازرگانەکان',
        courierBudget: 'بودجەی گەیەنەران',
        activeOrders: 'داواکارییە چالاکەکان',
        deliveredOrders: 'داواکارییە گەیەنراوەکان',
        todayOrders: 'داواکارییەکانی ئەمڕۆ',
        cashBalance: 'باڵانسی سندوق',
        orderActivity: 'جووڵەی دوا داواکارییەکان',
        noOrders: 'لە ئێستادا بۆ ئەم لقە هیچ داواکارییەک نییە.',
        noBranches: 'بۆ ئەم هەژمارە هیچ لقێک دیاری نەکراوە.',
        access: 'ئاستی دەسەڵات',
        owner: 'خاوەنی لق',
        manager: 'بەڕێوەبەری لق',
        active: 'چالاک',
        inactive: 'ناچالاک',
        branchCode: 'کۆدی لق',
        contact: 'زانیاری پەیوەندی',
        city: 'شار',
        address: 'ناونیشان',
        phone: 'تەلەفۆن',
        order: 'داواکاری',
        customer: 'کڕیار',
        date: 'بەروار',
        status: 'دۆخ',
        value: 'بەها',
        overview: 'گشتی',
        orders: 'داواکارییەکان',
        merchants: 'بازرگانەکان',
        couriers: 'گەیەنەرەکان',
        merchantsCount: 'بازرگانە تۆمارکراوەکان',
        couriersCount: 'گەیەنەرە تۆمارکراوەکان',
        onlineCouriers: 'گەیەنەرە بەردەستەکان',
        orderList: 'لیستی داواکارییەکانی لق',
        merchantList: 'بازرگانەکانی لق',
        courierList: 'گەیەنەرەکانی لق',
        courierLocations: 'شوێنەکانی گەیەنەران',
        allCourierLocations: 'هەموو شوێنەکانی گەیەنەران',
        courierLocationHint: 'تەنها شوێنە نوێیە هاوبەشکراوەکانی گەیەنەرانی لقە دیاریکراوەکان پیشان دەدرێن.',
        noCourierLocations: 'لە لقە دیاریکراوەکاندا هیچ شوێنێکی نوێی گەیەنەر نییە.',
        showAllLocations: 'هەموو شوێنەکان لەسەر نەخشە پیشان بدە',
        noScopedOrders: 'لە ئێستادا هیچ داواکارییەک لەم لقە نییە.',
        noMerchants: 'لە ئێستادا هیچ بازرگانێک لەم لقەدا تۆمار نەکراوە.',
        noCouriers: 'لە ئێستادا هیچ گەیەنەرێک لەم لقەدا تۆمار نەکراوە.',
        unassigned: 'دیاری نەکراوە',
        available: 'بەردەستە',
        offline: 'ناچالاک',
        pending: 'لە چاوەڕوانی پشکنین',
        rejected: 'ڕەتکراوە',
        shop: 'فرۆشگا',
        vehicle: 'ئۆتۆمبێل',
        assignedCourier: 'گەیەنەری دیاریکراو',
        orderBranch: 'لقی کارپێکردن',
        deliveryFee: 'کرێی گەیاندن',
        joined: 'بەرواری تۆماربوون',
        includedBranches: 'لقە پەیوەندیدارەکان',
        lastUpdated: 'داتا بە ڕاستەوخۆ لە سیستەمی کارپێکردن نیشان دەدرێت.',
        logout: 'چوونەدەرەوە',
        language: 'زمان',
        light: 'دۆخی ڕووناک',
        dark: 'دۆخی تاریک',
        noContact: 'هیچ زانیارییەکی پەیوەندی تۆمار نەکراوە',
        selectBranch: 'لقێک هەڵبژێرە بۆ بینینی وردەکارییەکان',
        noData: 'هێشتا داتا نییە',
        loading: 'بارکردن…',
        currency: 'د.ع',
        role: 'ڕۆڵ',
        manage: 'بەڕێوەبردن',
        viewDetails: 'بینینی وردەکاری',
        edit: 'دەستکاری',
        save: 'پاشەکەوتکردن',
        close: 'داخستن',
        activate: 'چالاککردن',
        suspend: 'ناچالاککردن',
        delete: 'سڕینەوە',
        accountDetails: 'وردەکاری هەژمار',
        email: 'ئیمەیڵ',
        username: 'ناوی بەکارهێنەر',
        identityNumber: 'ژمارەی ناسنامە',
        verification: 'پشتڕاستکردنەوەی هەژمار',
        verified: 'پشتڕاستکراوە',
        grantVerification: 'دانانی پشتڕاستکردنەوە',
        removeVerification: 'لابردنی پشتڕاستکردنەوە',
        documents: 'بەڵگەنامەکان',
        approve: 'پەسەندکردن',
        reject: 'ڕەتکردنەوە',
        viewDocument: 'بینینی بەڵگەنامە',
        orderManagement: 'بەڕێوەبردنی داواکاری',
        updateStatus: 'گۆڕینی دۆخ',
        administrativeNote: 'تێبینی کارپێکردن',
        assignCourier: 'دیاریکردنی گەیەنەر',
        chooseCourier: 'گەیەنەر هەڵبژێرە',
        reofferOverdue: 'دووبارە پێشکەشکردنی داواکاری دواکەوتوو',
        orderNote: 'تێبینی داواکاری',
        vehicleNote: 'تێبینی ئامراز',
        pickupDeadline: 'کاتی گەیشتن بۆ بازرگان',
        noNote: 'هیچ تێبینییەک نییە',
        pendingAssignment: 'چاوەڕێی دیاریکردنی گەیەنەر',
        notSubmitted: 'بەڵگەنامە پێشکەش نەکراوە',
        confirmDelete: 'هەژمارەکە دەتوانرێت بگەڕێندرێتەوە، بەڵام لەکاتی داواکاری کراوە ناسڕدرێتەوە. بەردەوام بیت؟',
    },
}

const statusCopy = {
    pending: { ar: 'بانتظار المراجعة', en: 'Pending review', ku: 'لە چاوەڕوانی پشکنین' },
    approved: { ar: 'تمت الموافقة', en: 'Approved', ku: 'پەسەند کرا' },
    courier: { ar: 'مع المندوب', en: 'With courier', ku: 'لەگەڵ پێگەیەنەر' },
    delivered: { ar: 'تم التسليم', en: 'Delivered', ku: 'گەیەنرا' },
    returned: { ar: 'مرتجع', en: 'Returned', ku: 'گەڕێنراوە' },
    cancelled: { ar: 'ملغى', en: 'Cancelled', ku: 'هەڵوەشاوە' },
    damaged: { ar: 'تالف', en: 'Damaged', ku: 'زیان‌دراوە' },
    rejected: { ar: 'مرفوض', en: 'Rejected', ku: 'ڕەتکراوە' },
}

const user = computed(() => page.props.auth?.user || {})
const branding = computed(() => page.props.branding || {})
const locales = computed(() => page.props.locales || ['ar', 'en', 'ku'])
const selectedBranch = computed(() => props.branches.find((branch) => String(branch.id) === selectedBranchKey.value) || null)
const isAllBranches = computed(() => selectedBranchKey.value === 'all')
const canManageOrders = computed(() => user.value?.role === 'owner' || (user.value?.dashboard_permissions || []).includes('orders'))
const canManageMerchants = computed(() => user.value?.role === 'owner' || (user.value?.dashboard_permissions || []).includes('merchants'))
const canManageCouriers = computed(() => user.value?.role === 'owner' || (user.value?.dashboard_permissions || []).includes('couriers'))
const selectedPersonIsMerchant = computed(() => detailsKind.value === 'merchant')
const selectedOrderCouriers = computed(() => {
    if (!selectedOrder.value) return []
    const branchIds = new Set((selectedOrder.value.branch_ids || []).map((id) => Number(id)))

    return props.orderCouriers.filter((courier) => courier.status === 'active'
        && (branchIds.size === 0 || branchIds.has(Number(courier.branch_id))))
})

const dashboardSummary = computed(() => {
    if (!selectedBranch.value) return props.summary || {}

    return {
        branches: 1,
        activeBranches: selectedBranch.value.is_active ? 1 : 0,
        orders: Number(selectedBranch.value.orders?.total || 0),
        activeOrders: Number(selectedBranch.value.orders?.active || 0),
        deliveredOrders: Number(selectedBranch.value.orders?.delivered || 0),
        todayOrders: Number(selectedBranch.value.orders?.today || 0),
        merchants: Number(selectedBranch.value.people?.merchants || 0),
        couriers: Number(selectedBranch.value.people?.couriers || 0),
        onlineCouriers: Number(selectedBranch.value.people?.online_couriers || 0),
    }
})

const tabs = computed(() => [
    { key: 'overview', label: text('overview'), count: null },
    { key: 'orders', label: text('orders'), count: dashboardSummary.value.orders || 0 },
    { key: 'merchants', label: text('merchants'), count: dashboardSummary.value.merchants || 0 },
    { key: 'couriers', label: text('couriers'), count: dashboardSummary.value.couriers || 0 },
    { key: 'locations', label: text('courierLocations'), count: loadedViews.value.locations ? mappedCourierCount.value : null },
])

const metrics = computed(() => [
    { key: 'branches', label: text('branches'), value: dashboardSummary.value.branches || 0, icon: 'branch' },
    { key: 'active', label: text('activeOrders'), value: dashboardSummary.value.activeOrders || 0, icon: 'bolt' },
    { key: 'delivered', label: text('deliveredOrders'), value: dashboardSummary.value.deliveredOrders || 0, icon: 'check' },
    { key: 'today', label: text('todayOrders'), value: dashboardSummary.value.todayOrders || 0, icon: 'calendar' },
    { key: 'merchants', label: text('merchantsCount'), value: dashboardSummary.value.merchants || 0, icon: 'shop' },
    { key: 'couriers', label: text('couriersCount'), value: dashboardSummary.value.couriers || 0, icon: 'courier' },
])

const branchStatusRows = computed(() => {
    const counts = props.operationalDashboard?.statusCounts || {}
    const statuses = ['pending', 'approved', 'courier', 'delivered', 'returned']
    const maximum = Math.max(1, ...statuses.map((status) => Number(counts[status] || 0)))

    return statuses.map((status) => ({
        status,
        label: statusLabel(status),
        value: Number(counts[status] || 0),
        percent: Math.max(3, Math.round((Number(counts[status] || 0) / maximum) * 100)),
    }))
})

const branchFinancialRows = computed(() => {
    const values = props.operationalDashboard?.financials || {}

    return [
        { label: text('orderValue'), value: values.value || 0, tone: 'default' },
        { label: text('deliveredValue'), value: values.deliveredValue || 0, tone: 'positive' },
        { label: text('deliveryFees'), value: values.fees || 0, tone: 'accent' },
        { label: text('merchantBalance'), value: values.merchantBalance || 0, tone: 'default' },
        { label: text('courierBudget'), value: values.courierBudget || 0, tone: 'default' },
    ]
})

const weekMax = computed(() => Math.max(1, ...(props.operationalDashboard?.week || []).map((item) => Number(item.count || 0))))

const visibleOrders = computed(() => {
    if (isAllBranches.value) return props.orders
    const branchId = Number(selectedBranchKey.value)
    return props.orders.filter((order) => order.branch_ids?.some((id) => Number(id) === branchId))
})

const visibleRecentOrders = computed(() => {
    if (isAllBranches.value) return props.recentOrders
    const branchId = Number(selectedBranchKey.value)
    return props.recentOrders.filter((order) => order.branches?.some((branch) => Number(branch.id) === branchId))
})

const visibleMerchants = computed(() => {
    if (isAllBranches.value) return props.merchants
    return props.merchants.filter((merchant) => Number(merchant.branch_id) === Number(selectedBranchKey.value))
})

const visibleCouriers = computed(() => {
    if (isAllBranches.value) return props.couriers
    return props.couriers.filter((courier) => Number(courier.branch_id) === Number(selectedBranchKey.value))
})

const visibleCourierLocations = computed(() => {
    if (isAllBranches.value) return props.courierLocations
    return props.courierLocations.filter((courier) => Number(courier.branch_id) === Number(selectedBranchKey.value))
})

const mappedCourierCount = computed(() => visibleCourierLocations.value.filter((courier) => {
    const latitude = Number(courier?.location?.latitude)
    const longitude = Number(courier?.location?.longitude)

    return Number.isFinite(latitude)
        && Number.isFinite(longitude)
        && latitude >= -90
        && latitude <= 90
        && longitude >= -180
        && longitude <= 180
}).length)

const lazyViewProps = {
    orders: ['orders', 'orderCouriers'],
    merchants: ['merchants'],
    couriers: ['couriers'],
    locations: ['courierLocations'],
}

function isViewLoading(view) {
    return Boolean(loadingViews.value[view])
}

function loadView(view, { force = false, onSuccess } = {}) {
    const requestedProps = lazyViewProps[view]

    if (!requestedProps?.length || isViewLoading(view) || (loadedViews.value[view] && !force)) {
        onSuccess?.()
        return
    }

    loadingViews.value = { ...loadingViews.value, [view]: true }

    router.reload({
        only: requestedProps,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            loadedViews.value = { ...loadedViews.value, [view]: true }
            onSuccess?.()
        },
        onFinish: () => {
            loadingViews.value = { ...loadingViews.value, [view]: false }
        },
    })
}

function openView(view) {
    activeView.value = view
    loadView(view)
}

function refreshView(view, onSuccess) {
    loadView(view, { force: true, onSuccess })
}

function text(key) {
    return copy[locale.value]?.[key] || copy.ar[key] || key
}

function localizedBranch(branch) {
    if (!branch) return ''
    return branch[`name_${locale.value}`] || branch.name || branch.name_ar || branch.name_en || branch.name_ku || branch.code
}

function formatMoney(value) {
    return `${fmt(value)} ${text('currency')}`
}

function fmt(value) {
    return new Intl.NumberFormat('en-US', {
        numberingSystem: 'latn',
        maximumFractionDigits: 0,
    }).format(Number(value || 0))
}

function formatDate(value) {
    if (!value) return '—'
    try {
        const language = locale.value === 'ar' ? 'ar-IQ-u-nu-latn' : locale.value === 'ku' ? 'ku-IQ-u-nu-latn' : 'en-US'
        const normalisedValue = String(value).includes('T') ? value : `${value}T00:00:00`
        return new Intl.DateTimeFormat(language, { day: 'numeric', month: 'short', year: 'numeric', numberingSystem: 'latn' }).format(new Date(normalisedValue))
    } catch (_) {
        return value
    }
}

function statusLabel(status) {
    return statusCopy[status]?.[locale.value] || statusCopy[status]?.ar || status || '—'
}

function statusTone(status) {
    return {
        pending: 'pending',
        approved: 'approved',
        courier: 'courier',
        delivered: 'delivered',
        returned: 'returned',
        cancelled: 'returned',
    }[status] || 'default'
}

function accountStatusLabel(status) {
    return text(status === 'active' ? 'active' : status === 'pending' ? 'pending' : status === 'rejected' ? 'rejected' : 'inactive')
}

function accountStatusTone(status) {
    return status === 'active' ? 'active' : status === 'pending' ? 'pending' : 'inactive'
}

function courierRoleLabel(role) {
    return {
        courier: { ar: 'مندوب', en: 'Courier', ku: 'گەیەنەر' },
        // Legacy account records remain visible for audit, but direct orders
        // use one courier and the portal must not advertise split roles.
        pickup_courier: { ar: 'مندوب', en: 'Courier', ku: 'گەیەنەر' },
        delivery_courier: { ar: 'مندوب', en: 'Courier', ku: 'گەیەنەر' },
        transporter: { ar: 'ناقل فروع', en: 'Branch transporter', ku: 'گواستەرەوەی لق' },
    }[role]?.[locale.value] || role || '—'
}

function accessLabel(role) {
    return role === 'owner' ? text('owner') : text('manager')
}

function vehicleLabel(vehicle) {
    return {
        bike: locale.value === 'ar' ? 'دراجة نارية' : locale.value === 'ku' ? 'ماتۆڕسکیل' : 'Motorcycle',
        sedan: locale.value === 'ar' ? 'سيارة' : locale.value === 'ku' ? 'ئۆتۆمبێل' : 'Car',
        suv: 'SUV',
        truck: locale.value === 'ar' ? 'شاحنة' : locale.value === 'ku' ? 'باربەر' : 'Truck',
    }[vehicle] || vehicle || '—'
}

function documentLabel(type) {
    return {
        id_front: locale.value === 'ar' ? 'الهوية الوطنية — أمامي' : locale.value === 'ku' ? 'ناسنامەی نیشتمانی — پێشەوە' : 'National ID — front',
        id_back: locale.value === 'ar' ? 'الهوية الوطنية — خلفي' : locale.value === 'ku' ? 'ناسنامەی نیشتمانی — دواوە' : 'National ID — back',
        residence: locale.value === 'ar' ? 'بطاقة السكن — أمامي' : locale.value === 'ku' ? 'کارتی نیشتەجێبوون — پێشەوە' : 'Residence card — front',
        residence_back: locale.value === 'ar' ? 'بطاقة السكن — خلفي' : locale.value === 'ku' ? 'کارتی نیشتەجێبوون — دواوە' : 'Residence card — back',
        license_front: locale.value === 'ar' ? 'رخصة القيادة — أمامي' : locale.value === 'ku' ? 'مۆڵەتی شۆفێری — پێشەوە' : 'Driving licence — front',
        license_back: locale.value === 'ar' ? 'رخصة القيادة — خلفي' : locale.value === 'ku' ? 'مۆڵەتی شۆفێری — دواوە' : 'Driving licence — back',
    }[type] || type
}

function documentStatus(status) {
    if (status === 'approved') return text('approve')
    if (status === 'rejected') return text('rejected')
    return text('pending')
}

function verificationLabel(status) {
    if (status === 'verified') return text('verified')
    if (status === 'rejected') return text('rejected')
    if (status === 'pending') return text('pending')
    return text('notSubmitted')
}

function openPerson(person, kind) {
    detailsPerson.value = person
    detailsKind.value = kind
}

function closePerson() {
    detailsPerson.value = null
}

function openEditPerson(person = detailsPerson.value, kind = detailsKind.value) {
    if (!person) return
    detailsPerson.value = null
    editingPerson.value = person
    editingKind.value = kind
    accountForm.clearErrors()
    accountForm.defaults({
        name: person.name || '',
        username: person.username || '',
        email: person.email || '',
        phone: person.phone || '',
        shop_name: person.shop_name || '',
        address: person.address || '',
        vehicle: person.vehicle || '',
    })
    accountForm.reset()
}

function closeEditPerson(force = false) {
    if (accountForm.processing && !force) return
    editingPerson.value = null
    accountForm.clearErrors()
}

function savePerson() {
    if (!editingPerson.value || accountForm.processing) return
    const view = editingKind.value === 'merchant' ? 'merchants' : 'couriers'

    accountForm.put(route('admin.branch.users.update', editingPerson.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            closeEditPerson(true)
            refreshView(view)
        },
    })
}

function setPersonStatus(person, kind, status) {
    const permission = kind === 'merchant' ? canManageMerchants.value : canManageCouriers.value
    if (!permission || !confirm(`${text(status === 'active' ? 'activate' : 'suspend')}: ${person.name}?`)) return
    router.post(route('admin.branch.users.status', person.id), { status }, {
        preserveScroll: true,
        onSuccess: () => refreshView(kind === 'merchant' ? 'merchants' : 'couriers'),
    })
}

function setMerchantVerification(person, verified) {
    if (!canManageMerchants.value || !confirm(`${text(verified ? 'grantVerification' : 'removeVerification')}: ${person.name}?`)) return
    router.post(route('admin.branch.users.merchant-verification', person.id), { verified }, {
        preserveScroll: true,
        onSuccess: () => refreshView('merchants'),
    })
}

function reviewDocument(person, document, status, kind) {
    const permission = kind === 'merchant' ? canManageMerchants.value : canManageCouriers.value
    if (!permission || !confirm(`${text(status === 'approved' ? 'approve' : 'reject')}: ${documentLabel(document.type)}?`)) return
    router.post(route('admin.branch.users.documents.review', [person.id, document.id]), { status }, {
        preserveScroll: true,
        onSuccess: () => refreshView(kind === 'merchant' ? 'merchants' : 'couriers'),
    })
}

function openDocument(document) {
    if (document?.url) window.open(document.url, '_blank', 'noopener')
}

function deletePerson(person, kind) {
    const permission = kind === 'merchant' ? canManageMerchants.value : canManageCouriers.value
    if (!permission || !confirm(text('confirmDelete'))) return
    router.delete(route('admin.branch.users.destroy', person.id), {
        preserveScroll: true,
        onSuccess: () => {
            closePerson()
            refreshView(kind === 'merchant' ? 'merchants' : 'couriers')
        },
    })
}

function openOrder(order) {
    selectedOrder.value = order
    orderStatusForm.defaults({ status: order.status || 'pending', note: '' })
    orderStatusForm.reset()
    courierAssignmentForm.defaults({ courier_id: order.courier?.id || '', assignment_role: 'courier' })
    courierAssignmentForm.reset()
}

function closeOrder() {
    selectedOrder.value = null
    orderStatusForm.clearErrors()
    courierAssignmentForm.clearErrors()
}

function saveOrderStatus() {
    if (!selectedOrder.value || orderStatusForm.processing) return
    if (!confirm(`${text('save')}: ${statusLabel(orderStatusForm.status)}?`)) return
    orderStatusForm.post(route('admin.branch.orders.status', selectedOrder.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            closeOrder()
            refreshView('orders')
        },
    })
}

function assignOrderCourier() {
    if (!selectedOrder.value || !courierAssignmentForm.courier_id || courierAssignmentForm.processing) return
    courierAssignmentForm.post(route('admin.branch.orders.courier', selectedOrder.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            closeOrder()
            refreshView('orders')
        },
    })
}

function isPickupOverdue(order) {
    return order?.status === 'approved'
        && order?.pickup_deadline_at
        && new Date(order.pickup_deadline_at).getTime() < Date.now()
}

function reofferOrder() {
    if (!selectedOrder.value || !isPickupOverdue(selectedOrder.value) || !confirm(text('reofferOverdue'))) return
    const note = window.prompt(text('administrativeNote'))
    if (note === null) return
    router.post(route('admin.branch.orders.reoffer-overdue-pickup', selectedOrder.value.id), { note }, {
        preserveScroll: true,
        onSuccess: () => {
            closeOrder()
            refreshView('orders')
        },
    })
}

function selectBranch(branch) {
    selectedBranchKey.value = String(branch.id)
}

function applyTheme(value) {
    document.documentElement.dataset.theme = value
    document.documentElement.lang = locale.value
    document.documentElement.dir = locale.value === 'en' ? 'ltr' : 'rtl'
}

function toggleTheme() {
    const previous = theme.value
    const next = previous === 'dark' ? 'light' : 'dark'
    theme.value = next
    applyTheme(next)

    // Saving a visual preference must not reload the isolated branch portal.
    window.axios.post(route('admin.branch.preferences.theme'), { theme: next }).catch(() => {
        if (theme.value !== next) return

        theme.value = previous
        applyTheme(previous)
    })
}

function changeLocale(event) {
    const previous = locale.value
    const next = event.target.value
    if (!locales.value.includes(next) || next === previous) return

    locale.value = next
    applyTheme(theme.value)
    router.post(route('admin.branch.preferences.locale'), { locale: next }, {
        preserveScroll: true,
        onError: () => {
            locale.value = previous
            applyTheme(theme.value)
        },
        onSuccess: () => window.location.reload(),
    })
}

function logout() {
    router.post(route('logout'))
}

function icon(name) {
    return {
        branch: 'M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16M3 21h18M9 7h.01M13 7h.01M9 11h.01M13 11h.01M9 15h.01M13 15h.01',
        bolt: 'm13 2-9 12h7l-1 8 9-12h-7l1-8Z',
        check: 'M20 6 9 17l-5-5',
        calendar: 'M7 3v3m10-3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z',
        shop: 'M4 10v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V10M2 7l1-3h18l1 3a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0Z',
        courier: 'M5 18a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm14-8a4 4 0 1 1 0 8 4 4 0 0 1 0-8ZM5 10h14m-7 0-2-4h5',
        logout: 'M10 17l5-5-5-5m5 5H3m7 9H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4',
        moon: 'M21 12.8A8.5 8.5 0 1 1 11.2 3 6.5 6.5 0 0 0 21 12.8Z',
        sun: 'M12 3v2m0 14v2m9-9h-2M5 12H3m15.36-6.36-1.42 1.42M7.06 16.94l-1.42 1.42m12.72 0-1.42-1.42M7.06 7.06 5.64 5.64M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
    }[name] || ''
}

watch(() => user.value?.theme, (value) => {
    if (value === 'dark' || value === 'light') {
        theme.value = value
        applyTheme(value)
    }
}, { immediate: true })

watch(() => page.props.locale, (value) => {
    if (value && copy[value]) {
        locale.value = value
        applyTheme(theme.value)
    }
}, { immediate: true })

onMounted(() => applyTheme(theme.value))
</script>

<template>
    <div class="branch-portal" :class="`theme-${theme}`">
        <Flash />

        <header class="portal-topbar">
            <div class="portal-brand">
                <span class="brand-mark"><img :src="branding.logo_url" alt="" /></span>
                <div>
                    <b>{{ branding.name || 'المنجز السريع' }}</b>
                    <span>{{ text('portal') }}</span>
                </div>
            </div>

            <div class="portal-top-actions">
                <label class="language-picker">
                    <span class="sr-only">{{ text('language') }}</span>
                    <PopupSelect :model-value="locale" :aria-label="text('language')" @change="changeLocale">
                        <option v-for="code in locales" :key="code" :value="code">{{ { ar: 'العربية', en: 'English', ku: 'کوردی' }[code] || code }}</option>
                    </PopupSelect>
                </label>
                <button class="icon-button" type="button" :aria-label="theme === 'dark' ? text('light') : text('dark')" @click="toggleTheme">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="icon(theme === 'dark' ? 'sun' : 'moon')" /></svg>
                </button>
                <button class="logout-button" type="button" @click="logout">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="icon('logout')" /></svg>
                    <span>{{ text('logout') }}</span>
                </button>
            </div>
        </header>

        <main class="portal-main">
            <section class="portal-hero">
                <div>
                    <span class="eyebrow"><i /> {{ text('access') }} · {{ accessLabel(user.role === 'owner' ? 'owner' : 'manager') }}</span>
                    <h1>{{ text('portal') }}</h1>
                    <p>{{ text('subtitle') }}</p>
                </div>
                <div class="operator-card">
                    <span class="operator-avatar">{{ user.name?.slice(0, 1) || 'م' }}</span>
                    <div><b>{{ user.name }}</b><span>{{ accessLabel(user.role === 'owner' ? 'owner' : 'manager') }}</span></div>
                </div>
            </section>

            <section v-if="branches.length" class="portal-controls" aria-label="Branch selector">
                <label>
                    <span>{{ text('branches') }}</span>
                    <PopupSelect v-model="selectedBranchKey">
                        <option value="all">{{ text('allBranches') }}</option>
                        <option v-for="branch in branches" :key="branch.id" :value="String(branch.id)">{{ localizedBranch(branch) }} · {{ branch.code }}</option>
                    </PopupSelect>
                </label>
                <p>{{ text('lastUpdated') }}</p>
            </section>

            <nav v-if="branches.length" class="portal-tabs" :aria-label="text('portal')">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    class="portal-tab"
                    :class="{ active: activeView === tab.key }"
                    type="button"
                    @click="openView(tab.key)"
                >
                    <span>{{ tab.label }}</span>
                    <b v-if="tab.count !== null" class="mono">{{ fmt(tab.count) }}</b>
                </button>
            </nav>

            <section v-if="branches.length && activeView === 'overview'" class="overview-content">
                <section class="metric-grid" :aria-label="text('portal')">
                    <article v-for="metric in metrics" :key="metric.key" class="metric-card">
                        <span class="metric-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="icon(metric.icon)" /></svg></span>
                        <div><strong class="mono">{{ fmt(metric.value) }}</strong><span>{{ metric.label }}</span></div>
                    </article>
                </section>

                <section class="branch-dashboard-details" :aria-label="text('operationalDetails')">
                    <article class="branch-detail-panel status-summary-panel">
                        <div class="section-heading"><div><span class="eyebrow">{{ text('operationalDetails') }}</span><h2>{{ text('statusSummary') }}</h2></div></div>
                        <div class="status-summary-list">
                            <div v-for="row in branchStatusRows" :key="row.status" class="status-summary-row">
                                <span class="status-pill" :class="statusTone(row.status)">{{ row.label }}</span>
                                <div class="status-meter"><i :class="statusTone(row.status)" :style="{ width: `${row.percent}%` }" /></div>
                                <b class="mono">{{ fmt(row.value) }}</b>
                            </div>
                        </div>
                    </article>

                    <article class="branch-detail-panel financial-summary-panel">
                        <div class="section-heading"><div><span class="eyebrow">{{ text('operationalDetails') }}</span><h2>{{ text('financialSummary') }}</h2></div></div>
                        <div class="branch-financial-grid">
                            <div v-for="row in branchFinancialRows" :key="row.label" :class="row.tone"><span>{{ row.label }}</span><b class="money mono">{{ formatMoney(row.value) }}</b></div>
                        </div>
                    </article>

                    <article class="branch-detail-panel weekly-panel">
                        <div class="section-heading"><div><span class="eyebrow">{{ text('operationalDetails') }}</span><h2>{{ text('weeklyActivity') }}</h2></div></div>
                        <div class="week-bars">
                            <div v-for="item in operationalDashboard.week || []" :key="item.label" class="week-bar"><b class="mono">{{ fmt(item.count) }}</b><span><i :style="{ height: `${Math.max(6, Math.round((Number(item.count || 0) / weekMax) * 100))}%` }" /></span><small>{{ item.label }}</small></div>
                        </div>
                    </article>

                    <article class="branch-detail-panel merchants-summary-panel">
                        <div class="section-heading"><div><span class="eyebrow">{{ text('operationalDetails') }}</span><h2>{{ text('topMerchants') }}</h2></div></div>
                        <div v-if="operationalDashboard.topMerchants?.length" class="top-merchant-list">
                            <div v-for="merchant in operationalDashboard.topMerchants" :key="merchant.id"><span>{{ merchant.name }}</span><small>{{ fmt(merchant.orders) }} {{ text('orders') }}</small><b class="money mono">{{ formatMoney(merchant.value) }}</b></div>
                        </div>
                        <div v-else class="compact-empty">{{ text('noData') }}</div>
                    </article>
                </section>

                <section class="portal-layout">
                <div class="branch-area">
                    <div class="section-heading"><div><span class="eyebrow">{{ text('branches') }}</span><h2>{{ isAllBranches ? text('allBranches') : text('branchDetails') }}</h2></div><span class="count-chip">{{ isAllBranches ? branches.length : 1 }}</span></div>

                    <div v-if="isAllBranches" class="branch-grid">
                        <button v-for="branch in branches" :key="branch.id" class="branch-card" type="button" @click="selectBranch(branch)">
                            <span class="branch-status" :class="{ inactive: !branch.is_active }">{{ branch.is_active ? text('active') : text('inactive') }}</span>
                            <div class="branch-card-title"><span class="branch-symbol">⌂</span><div><h3>{{ localizedBranch(branch) }}</h3><p>{{ branch.city || text('noData') }} · {{ branch.code }}</p></div></div>
                            <div class="branch-card-data"><span>{{ text('activeOrders') }}<b class="mono">{{ fmt(branch.orders?.active || 0) }}</b></span><span>{{ text('deliveredOrders') }}<b class="mono">{{ fmt(branch.orders?.delivered || 0) }}</b></span><span>{{ text('cashBalance') }}<b>{{ formatMoney(branch.cash_balance) }}</b></span></div>
                        </button>
                    </div>

                    <article v-else-if="selectedBranch" class="branch-detail-card">
                        <div class="detail-head">
                            <div class="branch-card-title"><span class="branch-symbol">⌂</span><div><span class="eyebrow">{{ text('branchCode') }} · {{ selectedBranch.code }}</span><h2>{{ localizedBranch(selectedBranch) }}</h2><p>{{ selectedBranch.city || text('noData') }}</p></div></div>
                            <div class="detail-badges"><span class="branch-status" :class="{ inactive: !selectedBranch.is_active }">{{ selectedBranch.is_active ? text('active') : text('inactive') }}</span><span class="access-badge">{{ accessLabel(selectedBranch.access_role) }}</span></div>
                        </div>
                        <div class="detail-stat-grid"><div><span>{{ text('totalOrders') }}</span><b class="mono">{{ fmt(selectedBranch.orders?.total || 0) }}</b></div><div><span>{{ text('activeOrders') }}</span><b class="mono">{{ fmt(selectedBranch.orders?.active || 0) }}</b></div><div><span>{{ text('deliveredOrders') }}</span><b class="mono">{{ fmt(selectedBranch.orders?.delivered || 0) }}</b></div><div><span>{{ text('todayOrders') }}</span><b class="mono">{{ fmt(selectedBranch.orders?.today || 0) }}</b></div></div>
                        <div class="detail-info-grid"><div><span>{{ text('cashBalance') }}</span><b class="money">{{ formatMoney(selectedBranch.cash_balance) }}</b></div><div><span>{{ text('contact') }}</span><b>{{ selectedBranch.phone || selectedBranch.address || text('noContact') }}</b></div><div><span>{{ text('city') }}</span><b>{{ selectedBranch.city || '—' }}</b></div><div><span>{{ text('address') }}</span><b>{{ selectedBranch.address || '—' }}</b></div></div>
                    </article>
                </div>

                <section class="recent-orders-panel">
                    <div class="section-heading"><div><span class="eyebrow">{{ text('orderActivity') }}</span><h2>{{ text('orderActivity') }}</h2></div><span class="count-chip">{{ visibleRecentOrders.length }}</span></div>
                    <div v-if="visibleRecentOrders.length" class="order-list">
                        <article v-for="order in visibleRecentOrders" :key="order.id" class="order-row">
                            <div class="order-main"><b>{{ order.track_no || `#${order.id}` }}</b><span>{{ order.customer_name || text('noData') }}</span></div>
                            <div class="order-meta"><span class="status-pill" :class="statusTone(order.status)">{{ statusLabel(order.status) }}</span><b class="money">{{ formatMoney(order.price) }}</b></div>
                            <div class="order-foot"><span>{{ formatDate(order.date) }}</span><span>{{ order.branches?.map((branch) => branch.name || branch.code).join(' · ') || '—' }}</span></div>
                        </article>
                    </div>
                    <div v-else class="empty-state"><span>⌁</span><p>{{ text('noOrders') }}</p></div>
                </section>
            </section>

            </section>

            <section v-else-if="branches.length && activeView === 'orders'" class="operational-panel">
                <div class="section-heading">
                    <div><span class="eyebrow">{{ text('orders') }}</span><h2>{{ text('orderList') }}</h2></div>
                    <span class="count-chip mono">{{ fmt(visibleOrders.length) }}</span>
                </div>

                <div v-if="isViewLoading('orders')" class="empty-state"><span class="portal-loading">◌</span><p>{{ text('loading') }}</p></div>
                <div v-else-if="visibleOrders.length" class="scoped-order-list">
                    <article v-for="order in visibleOrders" :key="order.id" class="scoped-order-card">
                        <div class="scoped-order-head">
                            <div><b>{{ order.track_no || `#${order.id}` }}</b><span>{{ order.customer_name || text('noData') }}</span></div>
                            <span class="status-pill" :class="statusTone(order.status)">{{ statusLabel(order.status) }}</span>
                        </div>
                        <div class="scoped-order-grid">
                            <span><small>{{ text('phone') }}</small><b class="mono">{{ order.phone || '—' }}</b></span>
                            <span><small>{{ text('value') }}</small><b class="money">{{ formatMoney(order.price) }}</b></span>
                            <span><small>{{ text('deliveryFee') }}</small><b class="money">{{ order.fee === null ? '—' : formatMoney(order.fee) }}</b></span>
                            <span><small>{{ text('assignedCourier') }}</small><b>{{ order.courier?.name || text('unassigned') }}</b></span>
                        </div>
                        <p v-if="order.address" class="order-address">{{ order.address }}</p>
                        <div class="scoped-order-foot">
                            <span>{{ text('includedBranches') }}: {{ order.branches?.map((branch) => branch.name || branch.code).join(' · ') || '—' }}</span>
                            <span>{{ formatDate(order.created_at) }}</span>
                        </div>
                        <div v-if="canManageOrders" class="card-actions">
                            <button type="button" class="manage-button" @click="openOrder(order)">{{ text('manage') }}</button>
                        </div>
                    </article>
                </div>
                <div v-else class="empty-state"><span>⌁</span><p>{{ text('noScopedOrders') }}</p></div>
            </section>

            <section v-else-if="branches.length && activeView === 'merchants'" class="operational-panel">
                <div class="section-heading">
                    <div><span class="eyebrow">{{ text('merchants') }}</span><h2>{{ text('merchantList') }}</h2></div>
                    <span class="count-chip mono">{{ fmt(visibleMerchants.length) }}</span>
                </div>

                <div v-if="isViewLoading('merchants')" class="empty-state"><span class="portal-loading">◌</span><p>{{ text('loading') }}</p></div>
                <div v-else-if="visibleMerchants.length" class="people-grid">
                    <article v-for="merchant in visibleMerchants" :key="merchant.id" class="person-card">
                        <div class="person-head">
                            <span class="person-avatar merchant-avatar"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="icon('shop')" /></svg></span>
                            <div><b>{{ merchant.shop_name || merchant.name }}</b><span>{{ merchant.name }}</span></div>
                            <span class="account-pill" :class="accountStatusTone(merchant.status)">{{ accountStatusLabel(merchant.status) }}</span>
                        </div>
                        <div class="person-details">
                            <span><small>{{ text('phone') }}</small><b class="mono">{{ merchant.phone || '—' }}</b></span>
                            <span><small>{{ text('branches') }}</small><b>{{ merchant.branch?.name || merchant.branch?.code || '—' }}</b></span>
                            <span class="person-full"><small>{{ text('address') }}</small><b>{{ merchant.address || '—' }}</b></span>
                        </div>
                        <div class="person-foot"><span>{{ text('joined') }}</span><b>{{ formatDate(merchant.created_at) }}</b></div>
                        <div v-if="canManageMerchants" class="card-actions">
                            <button type="button" class="manage-button" @click="openPerson(merchant, 'merchant')">{{ text('viewDetails') }}</button>
                            <span v-if="merchant.verification?.verified" class="verified-mark">✓ {{ text('verified') }}</span>
                        </div>
                    </article>
                </div>
                <div v-else class="empty-state"><span>⌁</span><p>{{ text('noMerchants') }}</p></div>
            </section>

            <section v-else-if="branches.length && activeView === 'couriers'" class="operational-panel">
                <div class="section-heading">
                    <div><span class="eyebrow">{{ text('couriers') }}</span><h2>{{ text('courierList') }}</h2></div>
                    <span class="count-chip mono">{{ fmt(visibleCouriers.length) }}</span>
                </div>

                <div v-if="isViewLoading('couriers')" class="empty-state"><span class="portal-loading">◌</span><p>{{ text('loading') }}</p></div>
                <div v-else-if="visibleCouriers.length" class="people-grid">
                    <article v-for="courier in visibleCouriers" :key="courier.id" class="person-card">
                        <div class="person-head">
                            <span class="person-avatar courier-avatar"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="icon('courier')" /></svg></span>
                            <div><b>{{ courier.name }}</b><span>{{ courierRoleLabel(courier.role) }}</span></div>
                            <span class="account-pill" :class="courier.is_online && courier.status === 'active' ? 'online' : accountStatusTone(courier.status)">{{ courier.is_online && courier.status === 'active' ? text('available') : accountStatusLabel(courier.status) }}</span>
                        </div>
                        <div class="person-details">
                            <span><small>{{ text('phone') }}</small><b class="mono">{{ courier.phone || '—' }}</b></span>
                            <span><small>{{ text('vehicle') }}</small><b>{{ courier.vehicle || '—' }}</b></span>
                            <span class="person-full"><small>{{ text('branches') }}</small><b>{{ courier.branch?.name || courier.branch?.code || '—' }}</b></span>
                        </div>
                        <div class="person-foot"><span>{{ text('joined') }}</span><b>{{ formatDate(courier.created_at) }}</b></div>
                        <div v-if="canManageCouriers" class="card-actions">
                            <button type="button" class="manage-button" @click="openPerson(courier, 'courier')">{{ text('viewDetails') }}</button>
                        </div>
                    </article>
                </div>
                <div v-else class="empty-state"><span>⌁</span><p>{{ text('noCouriers') }}</p></div>
            </section>

            <section v-else-if="branches.length && activeView === 'locations'" class="operational-panel">
                <div class="section-heading">
                    <div><span class="eyebrow">{{ text('courierLocations') }}</span><h2>{{ text('allCourierLocations') }}</h2></div>
                    <span class="count-chip mono">{{ fmt(mappedCourierCount) }}</span>
                </div>

                <div v-if="isViewLoading('locations')" class="empty-state"><span class="portal-loading">◌</span><p>{{ text('loading') }}</p></div>
                <div v-else class="location-overview-card">
                    <span class="location-overview-icon" aria-hidden="true">
                        <svg width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-5.2 7-12A7 7 0 1 0 5 9c0 6.8 7 12 7 12Z" /><circle cx="12" cy="9" r="2.2" /><path d="M4 19h4M16 19h4" /></svg>
                    </span>
                    <div>
                        <b>{{ text('allCourierLocations') }}</b>
                        <p>{{ mappedCourierCount ? text('courierLocationHint') : text('noCourierLocations') }}</p>
                    </div>
                    <button type="button" class="manage-button primary location-overview-action" :disabled="!mappedCourierCount" @click="showAllLocationsMap = true">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s7-5.2 7-12A7 7 0 1 0 5 9c0 6.8 7 12 7 12Z" /><circle cx="12" cy="9" r="2.2" /></svg>
                        {{ text('showAllLocations') }}
                    </button>
                </div>
            </section>

            <SheetModal :open="!!selectedOrder" :title="text('orderManagement')" :subtitle="selectedOrder?.track_no" :wide="true" @close="closeOrder">
                <div v-if="selectedOrder" class="portal-sheet">
                    <div class="sheet-order-summary">
                        <span class="status-pill" :class="statusTone(selectedOrder.status)">{{ statusLabel(selectedOrder.status) }}</span>
                        <b class="money">{{ formatMoney(selectedOrder.price) }}</b>
                    </div>
                    <dl class="sheet-data-grid">
                        <div><dt>{{ text('customer') }}</dt><dd>{{ selectedOrder.customer_name || '—' }}</dd></div>
                        <div><dt>{{ text('phone') }}</dt><dd class="mono" dir="ltr">{{ selectedOrder.phone || '—' }}</dd></div>
                        <div class="wide"><dt>{{ text('address') }}</dt><dd>{{ selectedOrder.address || '—' }}</dd></div>
                        <div class="wide"><dt>{{ text('orderNote') }}</dt><dd class="note-value">{{ selectedOrder.notes || text('noNote') }}</dd></div>
                        <div v-if="selectedOrder.vehicle_note" class="wide"><dt>{{ text('vehicleNote') }}</dt><dd class="note-value">{{ selectedOrder.vehicle_note }}</dd></div>
                        <div><dt>{{ text('assignedCourier') }}</dt><dd>{{ selectedOrder.courier?.name || text('pendingAssignment') }}</dd></div>
                        <div><dt>{{ text('pickupDeadline') }}</dt><dd>{{ selectedOrder.pickup_deadline_at ? formatDate(selectedOrder.pickup_deadline_at) : '—' }}</dd></div>
                    </dl>

                    <form v-if="canManageOrders" class="portal-action-form" @submit.prevent="saveOrderStatus">
                        <h4>{{ text('updateStatus') }}</h4>
                        <label>{{ text('status') }}
                            <PopupSelect v-model="orderStatusForm.status">
                                <option v-for="state in Object.keys(statusCopy)" :key="state" :value="state">{{ statusLabel(state) }}</option>
                            </PopupSelect>
                        </label>
                        <label>{{ text('administrativeNote') }}<textarea v-model="orderStatusForm.note" rows="2" maxlength="255"></textarea></label>
                        <small v-if="orderStatusForm.errors.status" class="portal-error">{{ orderStatusForm.errors.status }}</small>
                        <button type="submit" class="manage-button primary" :disabled="orderStatusForm.processing">{{ text('save') }}</button>
                    </form>

                    <form v-if="canManageOrders && !['delivered', 'returned', 'cancelled', 'damaged', 'rejected'].includes(selectedOrder.status)" class="portal-action-form" @submit.prevent="assignOrderCourier">
                        <h4>{{ text('assignCourier') }}</h4>
                        <label>{{ text('chooseCourier') }}
                            <PopupSelect v-model="courierAssignmentForm.courier_id" required>
                                <option value="">{{ text('chooseCourier') }}</option>
                                <option v-for="courier in selectedOrderCouriers" :key="courier.id" :value="courier.id">{{ courier.name }} · {{ courierRoleLabel(courier.role) }}</option>
                            </PopupSelect>
                        </label>
                        <small v-if="courierAssignmentForm.errors.courier_id" class="portal-error">{{ courierAssignmentForm.errors.courier_id }}</small>
                        <button type="submit" class="manage-button primary" :disabled="courierAssignmentForm.processing">{{ text('assignCourier') }}</button>
                    </form>

                    <button v-if="canManageOrders && isPickupOverdue(selectedOrder)" type="button" class="manage-button warning" @click="reofferOrder">{{ text('reofferOverdue') }}</button>
                </div>
            </SheetModal>

            <SheetModal :open="!!detailsPerson" :title="text('accountDetails')" :subtitle="detailsPerson?.name" :wide="true" @close="closePerson">
                <div v-if="detailsPerson" class="portal-sheet">
                    <div class="person-sheet-head">
                        <span class="person-avatar" :class="selectedPersonIsMerchant ? 'merchant-avatar' : 'courier-avatar'">{{ detailsPerson.name?.slice(0, 1) || 'م' }}</span>
                        <div><b>{{ selectedPersonIsMerchant ? (detailsPerson.shop_name || detailsPerson.name) : detailsPerson.name }}</b><span>{{ selectedPersonIsMerchant ? detailsPerson.name : courierRoleLabel(detailsPerson.role) }}</span></div>
                        <span class="account-pill" :class="accountStatusTone(detailsPerson.status)">{{ accountStatusLabel(detailsPerson.status) }}</span>
                    </div>
                    <dl class="sheet-data-grid">
                        <div><dt>{{ text('username') }}</dt><dd class="mono" dir="ltr">{{ detailsPerson.username || '—' }}</dd></div>
                        <div><dt>{{ text('phone') }}</dt><dd class="mono" dir="ltr">{{ detailsPerson.phone || '—' }}</dd></div>
                        <div><dt>{{ text('email') }}</dt><dd dir="ltr">{{ detailsPerson.email || '—' }}</dd></div>
                        <div><dt>{{ text('branches') }}</dt><dd>{{ detailsPerson.branch?.name || detailsPerson.branch?.code || '—' }}</dd></div>
                        <div v-if="!selectedPersonIsMerchant"><dt>{{ text('vehicle') }}</dt><dd>{{ vehicleLabel(detailsPerson.vehicle) }}</dd></div>
                        <div v-if="detailsPerson.identity_number"><dt>{{ text('identityNumber') }}</dt><dd class="mono" dir="ltr">{{ detailsPerson.identity_number }}</dd></div>
                        <div class="wide"><dt>{{ text('address') }}</dt><dd>{{ detailsPerson.address || '—' }}</dd></div>
                        <div v-if="selectedPersonIsMerchant" class="wide"><dt>{{ text('verification') }}</dt><dd><span class="verification-state" :class="detailsPerson.verification?.status">{{ verificationLabel(detailsPerson.verification?.status) }}</span><small v-if="detailsPerson.verification?.verified_by">{{ detailsPerson.verification.verified_by }}</small></dd></div>
                    </dl>

                    <section v-if="detailsPerson.documents?.length" class="documents-area">
                        <h4>{{ text('documents') }}</h4>
                        <div v-for="document in detailsPerson.documents" :key="document.id" class="document-row">
                            <button type="button" @click="openDocument(document)">{{ text('viewDocument') }} · {{ documentLabel(document.type) }}</button>
                            <span :class="document.status">{{ documentStatus(document.status) }}</span>
                            <div v-if="document.status === 'pending'" class="document-actions">
                                <button type="button" @click="reviewDocument(detailsPerson, document, 'approved', detailsKind)">{{ text('approve') }}</button>
                                <button type="button" @click="reviewDocument(detailsPerson, document, 'rejected', detailsKind)">{{ text('reject') }}</button>
                            </div>
                        </div>
                    </section>

                    <div class="sheet-actions">
                        <button type="button" class="manage-button" @click="openEditPerson()">{{ text('edit') }}</button>
                        <button v-if="detailsPerson.status !== 'active'" type="button" class="manage-button success" @click="setPersonStatus(detailsPerson, detailsKind, 'active')">{{ text('activate') }}</button>
                        <button v-else type="button" class="manage-button warning" @click="setPersonStatus(detailsPerson, detailsKind, 'suspended')">{{ text('suspend') }}</button>
                        <button v-if="selectedPersonIsMerchant && !detailsPerson.verification?.verified" type="button" class="manage-button primary" @click="setMerchantVerification(detailsPerson, true)">{{ text('grantVerification') }}</button>
                        <button v-if="selectedPersonIsMerchant && detailsPerson.verification?.verified" type="button" class="manage-button warning" @click="setMerchantVerification(detailsPerson, false)">{{ text('removeVerification') }}</button>
                        <button type="button" class="manage-button danger" @click="deletePerson(detailsPerson, detailsKind)">{{ text('delete') }}</button>
                    </div>
                </div>
            </SheetModal>

            <SheetModal :open="!!editingPerson" :title="text('edit')" :subtitle="editingPerson?.name" @close="closeEditPerson">
                <form v-if="editingPerson" class="portal-sheet edit-person-form" @submit.prevent="savePerson">
                    <label>{{ text('customer') }}<input v-model="accountForm.name" required maxlength="120" /></label>
                    <label>{{ text('username') }}<input v-model="accountForm.username" required maxlength="60" dir="ltr" /></label>
                    <label>{{ text('phone') }}<input v-model="accountForm.phone" required maxlength="30" dir="ltr" inputmode="tel" /></label>
                    <label>{{ text('email') }}<input v-model="accountForm.email" type="email" maxlength="255" dir="ltr" /></label>
                    <label v-if="editingKind === 'merchant'">{{ text('shop') }}<input v-model="accountForm.shop_name" maxlength="120" /></label>
                    <label v-else>{{ text('vehicle') }}<PopupSelect v-model="accountForm.vehicle"><option value="">—</option><option value="bike">{{ vehicleLabel('bike') }}</option><option value="sedan">{{ vehicleLabel('sedan') }}</option><option value="suv">SUV</option><option value="truck">{{ vehicleLabel('truck') }}</option></PopupSelect></label>
                    <label class="wide">{{ text('address') }}<textarea v-model="accountForm.address" rows="2" maxlength="255"></textarea></label>
                    <small v-if="Object.keys(accountForm.errors).length" class="portal-error">{{ Object.values(accountForm.errors)[0] }}</small>
                    <button type="submit" class="manage-button primary" :disabled="accountForm.processing">{{ text('save') }}</button>
                </form>
            </SheetModal>

            <section v-if="!branches.length" class="empty-portal"><span>⌂</span><h1>{{ text('portal') }}</h1><p>{{ text('noBranches') }}</p></section>
        </main>

        <CourierLocationsMap
            v-if="showAllLocationsMap"
            :couriers="visibleCourierLocations"
            :locale="locale"
            :theme="theme"
            @close="showAllLocationsMap = false"
        />
    </div>
</template>

<style scoped>
.branch-portal{--bg:#eef5f5;--surface:#fff;--surface-2:#f6faf9;--ink:#102a43;--ink-soft:#5b7481;--ink-faint:#8ca0a9;--border:rgba(16,42,67,.1);--primary:#087b73;--primary-strong:#05645e;--primary-tint:rgba(8,123,115,.1);--success:#059669;--success-tint:rgba(5,150,105,.12);--danger:#dc5a50;--danger-tint:rgba(220,90,80,.12);--warning:#c98316;--warning-tint:rgba(201,131,22,.13);--shadow:0 18px 52px rgba(21,66,73,.09);min-height:100dvh;color:var(--ink);background:radial-gradient(circle at 100% 0,rgba(47,180,166,.13),transparent 30rem),var(--bg);font-family:inherit}.branch-portal.theme-dark{--bg:#0c1720;--surface:#13232d;--surface-2:#172b36;--ink:#e5f0f2;--ink-soft:#a8bcc3;--ink-faint:#718b95;--border:rgba(213,241,240,.1);--primary:#28b3a5;--primary-strong:#66dbcf;--primary-tint:rgba(40,179,165,.13);--success:#52d1a0;--success-tint:rgba(82,209,160,.13);--danger:#fb8d83;--danger-tint:rgba(251,141,131,.14);--warning:#f4bc50;--warning-tint:rgba(244,188,80,.14);--shadow:0 18px 52px rgba(0,0,0,.24)}.portal-topbar{height:72px;box-sizing:border-box;display:flex;align-items:center;justify-content:space-between;gap:20px;padding:0 clamp(18px,4vw,58px);border-bottom:1px solid var(--border);background:color-mix(in srgb,var(--surface) 90%,transparent);backdrop-filter:blur(14px);position:sticky;top:0;z-index:5}.portal-brand,.portal-top-actions,.operator-card,.branch-card-title,.detail-head,.section-heading,.order-meta,.order-foot{display:flex;align-items:center}.portal-brand{gap:10px;min-width:0}.brand-mark{width:42px;height:42px;flex:none;display:grid;place-items:center;overflow:hidden;border:1px solid var(--border);border-radius:13px;background:#fff}.brand-mark img{width:100%;height:100%;object-fit:contain;padding:3px;box-sizing:border-box}.portal-brand b,.portal-brand span{display:block}.portal-brand b{font-size:14px;font-weight:900;line-height:1.2}.portal-brand span{margin-top:3px;color:var(--ink-faint);font-size:10px;font-weight:800}.portal-top-actions{gap:8px}.language-picker select,.icon-button,.logout-button{height:38px;border:1px solid var(--border);border-radius:10px;color:var(--ink-soft);background:var(--surface);font:inherit;font-size:11px;font-weight:800}.language-picker select{padding:0 9px;outline:0;cursor:pointer}.language-picker option{color:#102a43}.icon-button{width:38px;display:grid;place-items:center;cursor:pointer}.logout-button{display:inline-flex;align-items:center;gap:7px;padding:0 11px;cursor:pointer}.logout-button:hover{color:var(--danger);border-color:color-mix(in srgb,var(--danger) 50%,var(--border));background:var(--danger-tint)}.portal-main{width:min(1280px,100%);box-sizing:border-box;margin:0 auto;padding:clamp(22px,4vw,48px) clamp(15px,3vw,34px) 56px}.portal-hero{display:flex;justify-content:space-between;align-items:center;gap:22px;margin-bottom:22px;padding:clamp(22px,3vw,34px);border:1px solid color-mix(in srgb,var(--primary) 20%,var(--border));border-radius:24px;color:#fff;background:linear-gradient(125deg,#056b64,#074d58);box-shadow:var(--shadow);overflow:hidden;position:relative}.portal-hero:after{content:"";position:absolute;inset:auto -80px -155px auto;width:330px;height:330px;border:40px solid rgba(255,255,255,.07);border-radius:50%;pointer-events:none}.portal-hero h1{margin:7px 0 5px;font-size:clamp(23px,3vw,32px);line-height:1.2}.portal-hero p{max-width:670px;margin:0;color:rgba(255,255,255,.8);font-size:13px;line-height:1.75}.eyebrow{display:inline-flex;align-items:center;gap:7px;color:var(--primary-strong);font-size:10px;font-weight:900;letter-spacing:.06em;text-transform:uppercase}.portal-hero .eyebrow{color:#c7fff8}.portal-hero .eyebrow i{width:7px;height:7px;border-radius:50%;background:#69e6b5;box-shadow:0 0 0 4px rgba(105,230,181,.16)}.operator-card{position:relative;z-index:1;gap:10px;min-width:176px;padding:10px 13px;border:1px solid rgba(255,255,255,.16);border-radius:15px;background:rgba(2,32,39,.22)}.operator-avatar{width:36px;height:36px;display:grid;place-items:center;flex:none;border-radius:50%;color:#074e53;background:#c7fff8;font-size:13px;font-weight:900}.operator-card b,.operator-card span{display:block}.operator-card b{font-size:12px}.operator-card span:last-child{margin-top:3px;color:rgba(255,255,255,.7);font-size:10px;font-weight:800}.portal-controls{display:flex;justify-content:space-between;align-items:end;gap:15px;margin:0 0 17px;padding:14px 16px;border:1px solid var(--border);border-radius:15px;background:var(--surface);box-shadow:0 8px 28px rgba(21,66,73,.04)}.portal-controls label{display:grid;gap:5px;color:var(--ink-faint);font-size:10px;font-weight:900}.portal-controls select{min-width:min(370px,70vw);padding:9px 11px;border:1px solid var(--border);border-radius:9px;outline:0;color:var(--ink);background:var(--surface-2);font:inherit;font-size:12px;font-weight:800}.portal-controls p{margin:0;color:var(--ink-faint);font-size:10.5px;font-weight:700}.metric-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:13px;margin-bottom:22px}.metric-card{display:flex;align-items:center;gap:11px;padding:16px;border:1px solid var(--border);border-radius:17px;background:var(--surface);box-shadow:0 10px 30px rgba(21,66,73,.05)}.metric-icon{width:40px;height:40px;display:grid;place-items:center;flex:none;border-radius:12px;color:var(--primary-strong);background:var(--primary-tint)}.metric-card:nth-child(2) .metric-icon{color:var(--warning);background:var(--warning-tint)}.metric-card:nth-child(3) .metric-icon{color:var(--success);background:var(--success-tint)}.metric-card strong,.metric-card span{display:block}.metric-card strong{font-size:21px;line-height:1.15}.metric-card div>span{margin-top:3px;color:var(--ink-faint);font-size:10px;font-weight:800}.portal-layout{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr);gap:18px;align-items:start}.branch-area,.recent-orders-panel{min-width:0;padding:19px;border:1px solid var(--border);border-radius:20px;background:var(--surface);box-shadow:var(--shadow)}.section-heading{justify-content:space-between;gap:12px;margin-bottom:16px}.section-heading h2{margin:3px 0 0;font-size:17px;line-height:1.3}.count-chip{min-width:25px;padding:4px 7px;border-radius:999px;color:var(--primary-strong);background:var(--primary-tint);font-size:11px;font-weight:900;text-align:center}.branch-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(205px,1fr));gap:12px}.branch-card{position:relative;min-height:168px;padding:16px;border:1px solid var(--border);border-radius:16px;color:inherit;background:var(--surface-2);font:inherit;text-align:inherit;cursor:pointer;transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease}.branch-card:hover{border-color:color-mix(in srgb,var(--primary) 55%,var(--border));box-shadow:0 12px 28px rgba(7,87,84,.13);transform:translateY(-2px)}.branch-status,.access-badge,.status-pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;font-size:9px;font-weight:900}.branch-status{position:absolute;inset:13px 13px auto auto;padding:5px 7px;color:var(--success);background:var(--success-tint)}[dir="ltr"] .branch-status{inset:13px auto auto 13px}.branch-status.inactive{color:var(--danger);background:var(--danger-tint)}.branch-card-title{align-items:flex-start;gap:9px;padding-inline-end:52px}.branch-symbol{width:36px;height:36px;display:grid;place-items:center;flex:none;border-radius:11px;color:var(--primary-strong);background:var(--primary-tint);font-size:19px;font-weight:900}.branch-card-title h3,.branch-card-title h2{margin:0;font-size:14px;line-height:1.35}.branch-card-title p{margin:3px 0 0;color:var(--ink-faint);font-size:10px;font-weight:700}.branch-card-data{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:17px}.branch-card-data span{display:flex;flex-direction:column;gap:4px;color:var(--ink-faint);font-size:9px;font-weight:800}.branch-card-data span:last-child{grid-column:span 2;padding-top:9px;border-top:1px solid var(--border)}.branch-card-data b{color:var(--ink);font-size:13px}.branch-card-data span:last-child b{color:var(--primary-strong);font-size:12px}.branch-detail-card{padding:20px;border:1px solid color-mix(in srgb,var(--primary) 25%,var(--border));border-radius:17px;background:linear-gradient(135deg,var(--surface-2),var(--surface))}.detail-head{justify-content:space-between;align-items:flex-start;gap:14px}.detail-head .branch-card-title{padding:0}.detail-head .branch-card-title h2{font-size:19px}.detail-badges{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:6px}.detail-badges .branch-status{position:static}.access-badge{padding:5px 7px;color:var(--primary-strong);background:var(--primary-tint)}.detail-stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:9px;margin:21px 0 12px}.detail-stat-grid>div,.detail-info-grid>div{padding:10px;border:1px solid var(--border);border-radius:11px;background:var(--surface)}.detail-stat-grid span,.detail-info-grid span{display:block;color:var(--ink-faint);font-size:9px;font-weight:800}.detail-stat-grid b{display:block;margin-top:5px;font-size:18px}.detail-info-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:9px}.detail-info-grid b{display:block;margin-top:4px;font-size:11px;line-height:1.45;overflow-wrap:anywhere}.money{color:var(--primary-strong)!important;white-space:nowrap}.recent-orders-panel{min-height:296px}.order-list{display:grid;gap:8px}.order-row{padding:11px;border:1px solid var(--border);border-radius:13px;background:var(--surface-2)}.order-main{display:flex;flex-direction:column;gap:2px;min-width:0}.order-main b{font-size:12px}.order-main span{overflow:hidden;color:var(--ink-soft);font-size:10.5px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}.order-meta{justify-content:space-between;gap:8px;margin-top:8px}.status-pill{padding:4px 7px;color:var(--ink-soft);background:var(--surface)}.status-pill.pending{color:var(--warning);background:var(--warning-tint)}.status-pill.approved{color:#1879bd;background:rgba(24,121,189,.1)}.status-pill.courier{color:#8561d8;background:rgba(133,97,216,.12)}.status-pill.delivered{color:var(--success);background:var(--success-tint)}.status-pill.returned{color:var(--danger);background:var(--danger-tint)}.order-meta .money{font-size:11px}.order-foot{justify-content:space-between;gap:10px;margin-top:9px;padding-top:8px;border-top:1px solid var(--border);color:var(--ink-faint);font-size:9px;font-weight:800}.order-foot span:last-child{overflow:hidden;text-align:end;text-overflow:ellipsis;white-space:nowrap}.empty-state,.empty-portal{display:grid;place-items:center;text-align:center}.empty-state{min-height:215px;padding:18px;color:var(--ink-faint)}.empty-state span,.empty-portal>span{color:var(--primary-strong);font-size:31px;font-weight:900}.empty-state p{max-width:240px;margin:9px 0 0;font-size:12px;line-height:1.7;font-weight:700}.empty-portal{min-height:420px;padding:30px;border:1px dashed color-mix(in srgb,var(--primary) 40%,var(--border));border-radius:25px;background:var(--surface)}.empty-portal h1{margin:11px 0 0;font-size:20px}.empty-portal p{margin:5px 0 0;color:var(--ink-faint);font-size:13px}.sr-only{position:absolute;width:1px;height:1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}@media(max-width:950px){.portal-layout{grid-template-columns:1fr}.metric-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:620px){.portal-topbar{height:64px;padding:0 13px}.portal-brand b{font-size:12px}.portal-brand span{font-size:9px}.brand-mark{width:36px;height:36px}.language-picker select{max-width:64px;padding:0 4px}.logout-button{width:37px;padding:0;justify-content:center}.logout-button span{display:none}.portal-top-actions{gap:5px}.portal-main{padding:18px 12px 36px}.portal-hero{display:block;padding:21px 18px;border-radius:19px}.portal-hero h1{font-size:23px}.operator-card{width:max-content;max-width:100%;margin-top:17px}.portal-controls{align-items:stretch;flex-direction:column;padding:12px}.portal-controls select{min-width:0;width:100%;box-sizing:border-box}.portal-controls p{line-height:1.5}.metric-grid{gap:9px;margin-bottom:15px}.metric-card{gap:8px;padding:12px}.metric-icon{width:34px;height:34px;border-radius:10px}.metric-card strong{font-size:18px}.metric-card div>span{font-size:9px}.branch-area,.recent-orders-panel{padding:14px;border-radius:16px}.branch-grid{grid-template-columns:1fr}.detail-head{display:block}.detail-badges{justify-content:flex-start;margin-top:12px}.detail-stat-grid{grid-template-columns:repeat(2,1fr);margin-top:15px}.detail-info-grid{grid-template-columns:1fr}.section-heading h2{font-size:15px}}
.portal-tabs{display:flex;align-items:center;gap:8px;overflow:auto;margin:0 0 18px;padding:5px;border:1px solid var(--border);border-radius:15px;background:var(--surface);box-shadow:0 8px 28px rgba(21,66,73,.04)}
.portal-tab{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:36px;padding:0 12px;border:0;border-radius:10px;color:var(--ink-soft);background:transparent;font:inherit;font-size:11px;font-weight:900;white-space:nowrap;cursor:pointer;transition:background .18s ease,color .18s ease,box-shadow .18s ease}
.portal-tab:hover{color:var(--primary-strong);background:var(--primary-tint)}
.portal-tab.active{color:#fff;background:var(--primary);box-shadow:0 7px 17px rgba(8,123,115,.24)}
.portal-tab b{min-width:18px;padding:2px 5px;border-radius:999px;color:inherit;background:color-mix(in srgb,currentColor 12%,transparent);font-size:10px;text-align:center}
.portal-loading{display:inline-block;animation:portal-spin .85s linear infinite}
@keyframes portal-spin{to{transform:rotate(360deg)}}
.overview-content{display:grid;gap:0}
.operational-panel{min-width:0;padding:20px;border:1px solid var(--border);border-radius:20px;background:var(--surface);box-shadow:var(--shadow)}
.scoped-order-list{display:grid;gap:11px}
.scoped-order-card{padding:15px;border:1px solid var(--border);border-radius:15px;background:var(--surface-2)}
.scoped-order-head,.person-head,.scoped-order-foot,.person-foot{display:flex;align-items:center;justify-content:space-between;gap:12px}
.scoped-order-head>div,.person-head>div{display:grid;gap:3px;min-width:0}
.scoped-order-head b,.person-head b{font-size:14px;line-height:1.3}.scoped-order-head span:not(.status-pill),.person-head span:not(.account-pill){overflow:hidden;color:var(--ink-faint);font-size:10px;font-weight:800;text-overflow:ellipsis;white-space:nowrap}
.scoped-order-grid,.person-details{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:13px}
.scoped-order-grid>span,.person-details>span{display:grid;gap:4px;min-width:0;padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:var(--surface)}
.scoped-order-grid small,.person-details small{color:var(--ink-faint);font-size:9px;font-weight:900}
.scoped-order-grid b,.person-details b{overflow:hidden;font-size:11px;line-height:1.4;text-overflow:ellipsis;white-space:nowrap}
.order-address{margin:10px 0 0;padding:9px 10px;border-radius:10px;color:var(--ink-soft);background:var(--surface);font-size:11px;line-height:1.6}
.scoped-order-foot,.person-foot{margin-top:11px;padding-top:10px;border-top:1px solid var(--border);color:var(--ink-faint);font-size:9px;font-weight:800}.scoped-order-foot span:first-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.scoped-order-foot span:last-child,.person-foot b{white-space:nowrap}
.people-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.person-card{padding:15px;border:1px solid var(--border);border-radius:15px;background:var(--surface-2)}
.person-head{align-items:flex-start}.person-avatar{width:38px;height:38px;display:grid;place-items:center;flex:none;border-radius:12px}.merchant-avatar{color:var(--warning);background:var(--warning-tint)}.courier-avatar{color:#5268d8;background:rgba(82,104,216,.12)}
.account-pill{display:inline-flex;align-items:center;justify-content:center;flex:none;padding:5px 7px;border-radius:999px;color:var(--ink-soft);background:var(--surface);font-size:9px;font-weight:900}.account-pill.active,.account-pill.online{color:var(--success);background:var(--success-tint)}.account-pill.pending{color:var(--warning);background:var(--warning-tint)}.account-pill.inactive{color:var(--danger);background:var(--danger-tint)}
.person-details{grid-template-columns:repeat(2,minmax(0,1fr))}.person-details .person-full{grid-column:span 2}
.card-actions{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:11px;padding-top:10px;border-top:1px solid var(--border)}
.manage-button{display:inline-flex;align-items:center;justify-content:center;min-height:32px;padding:0 11px;border:1px solid color-mix(in srgb,var(--primary) 28%,var(--border));border-radius:9px;color:var(--primary-strong);background:var(--primary-tint);font:inherit;font-size:10px;font-weight:900;cursor:pointer}.manage-button:hover{filter:brightness(.97)}.manage-button:disabled{opacity:.55;cursor:not-allowed}.manage-button.primary{color:#fff;background:var(--primary);border-color:var(--primary)}.manage-button.success{color:var(--success);background:var(--success-tint);border-color:color-mix(in srgb,var(--success) 30%,var(--border))}.manage-button.warning{color:var(--warning);background:var(--warning-tint);border-color:color-mix(in srgb,var(--warning) 36%,var(--border))}.manage-button.danger{color:var(--danger);background:var(--danger-tint);border-color:color-mix(in srgb,var(--danger) 36%,var(--border))}
.verified-mark{display:inline-flex;align-items:center;gap:4px;color:var(--success);font-size:9px;font-weight:900}.portal-sheet{display:grid;gap:13px}.sheet-order-summary,.person-sheet-head,.sheet-actions{display:flex;align-items:center;gap:10px}.sheet-order-summary{justify-content:space-between;padding:11px;border:1px solid var(--border);border-radius:12px;background:var(--surface-2)}.sheet-data-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin:0}.sheet-data-grid>div{min-width:0;padding:10px;border:1px solid var(--border);border-radius:11px;background:var(--surface-2)}.sheet-data-grid .wide{grid-column:span 2}.sheet-data-grid dt{color:var(--ink-faint);font-size:9px;font-weight:900}.sheet-data-grid dd{margin:5px 0 0;overflow-wrap:anywhere;color:var(--ink);font-size:11px;font-weight:800;line-height:1.55}.sheet-data-grid dd small{display:block;margin-top:3px;color:var(--ink-faint);font-size:9px}.note-value{color:var(--ink-soft)!important}.person-sheet-head{padding:12px;border:1px solid var(--border);border-radius:13px;background:var(--surface-2)}.person-sheet-head>div{display:grid;flex:1;gap:2px;min-width:0}.person-sheet-head>div b{overflow:hidden;font-size:13px;text-overflow:ellipsis;white-space:nowrap}.person-sheet-head>div span{color:var(--ink-faint);font-size:9px;font-weight:800}.person-sheet-head .person-avatar{width:39px;height:39px}.verification-state{display:inline-flex;padding:4px 7px;border-radius:999px;color:var(--warning);background:var(--warning-tint);font-size:9px;font-weight:900}.verification-state.verified{color:var(--success);background:var(--success-tint)}.verification-state.rejected{color:var(--danger);background:var(--danger-tint)}.portal-action-form,.edit-person-form{display:grid;gap:9px;padding:13px;border:1px solid var(--border);border-radius:13px;background:var(--surface-2)}.portal-action-form h4{margin:0;color:var(--ink);font-size:12px}.portal-action-form label,.edit-person-form label{display:grid;gap:5px;color:var(--ink-soft);font-size:9px;font-weight:900}.portal-action-form select,.portal-action-form textarea,.edit-person-form input,.edit-person-form select,.edit-person-form textarea{box-sizing:border-box;width:100%;border:1px solid var(--border);border-radius:9px;outline:0;padding:8px 9px;color:var(--ink);background:var(--surface);font:inherit;font-size:11px;font-weight:700}.portal-action-form textarea,.edit-person-form textarea{resize:vertical}.portal-error{color:var(--danger);font-size:10px;font-weight:800}.documents-area{padding:12px;border:1px solid var(--border);border-radius:13px;background:var(--surface-2)}.documents-area h4{margin:0 0 9px;font-size:12px}.document-row{display:flex;align-items:center;gap:7px;padding:8px 0;border-top:1px solid var(--border)}.document-row:first-of-type{border-top:0}.document-row>button{min-width:0;overflow:hidden;border:0;color:var(--primary-strong);background:transparent;font:inherit;font-size:9.5px;font-weight:900;text-overflow:ellipsis;white-space:nowrap;cursor:pointer}.document-row>span{margin-inline-start:auto;padding:4px 6px;border-radius:999px;color:var(--warning);background:var(--warning-tint);font-size:8px;font-weight:900}.document-row>span.approved{color:var(--success);background:var(--success-tint)}.document-row>span.rejected{color:var(--danger);background:var(--danger-tint)}.document-actions{display:flex;gap:4px}.document-actions button{border:0;border-radius:7px;padding:4px 6px;color:var(--success);background:var(--success-tint);font:inherit;font-size:8px;font-weight:900;cursor:pointer}.document-actions button:last-child{color:var(--danger);background:var(--danger-tint)}.sheet-actions{flex-wrap:wrap}.edit-person-form{grid-template-columns:repeat(2,minmax(0,1fr))}.edit-person-form .wide{grid-column:span 2}.edit-person-form .manage-button{width:max-content}
.location-overview-card{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:14px;padding:19px;border:1px solid color-mix(in srgb,var(--primary) 25%,var(--border));border-radius:16px;background:linear-gradient(135deg,var(--primary-tint),var(--surface-2))}.location-overview-icon{display:grid;place-items:center;width:48px;height:48px;border-radius:14px;color:var(--primary-strong);background:var(--surface)}.location-overview-card>div{display:grid;gap:4px}.location-overview-card b{font-size:13px}.location-overview-card p{margin:0;color:var(--ink-soft);font-size:10.5px;font-weight:700;line-height:1.65}.location-overview-action{min-height:38px;gap:7px;white-space:nowrap}
.branch-dashboard-details{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:0 0 18px}.branch-detail-panel{padding:18px;border:1px solid var(--border);border-radius:18px;background:var(--surface);box-shadow:0 10px 30px rgba(21,66,73,.05)}.branch-detail-panel .section-heading{margin-bottom:13px}.branch-detail-panel h2{font-size:14px}.status-summary-list{display:grid;gap:10px}.status-summary-row{display:grid;grid-template-columns:minmax(86px,.8fr) minmax(70px,2fr) auto;align-items:center;gap:8px}.status-meter{height:7px;overflow:hidden;border-radius:99px;background:var(--surface-2)}.status-meter i{display:block;height:100%;border-radius:inherit;background:var(--primary)}.status-meter i.pending{background:var(--warning)}.status-meter i.approved{background:#1879bd}.status-meter i.courier{background:#8561d8}.status-meter i.delivered{background:var(--success)}.status-meter i.returned{background:var(--danger)}.status-summary-row>b{font-size:11px}.branch-financial-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}.branch-financial-grid>div{display:grid;gap:5px;padding:10px;border-radius:11px;background:var(--surface-2)}.branch-financial-grid>div.positive{background:var(--success-tint)}.branch-financial-grid>div.accent{background:var(--primary-tint)}.branch-financial-grid span{color:var(--ink-faint);font-size:9px;font-weight:800}.branch-financial-grid b{font-size:12px}.week-bars{display:flex;align-items:end;justify-content:space-between;gap:7px;height:125px;padding-top:4px}.week-bar{display:grid;grid-template-rows:auto 72px auto;align-items:end;gap:5px;min-width:0;flex:1;text-align:center}.week-bar>b{font-size:10px}.week-bar>span{display:flex;align-items:end;height:72px;border-radius:7px;background:var(--surface-2)}.week-bar i{display:block;width:100%;border-radius:7px;background:linear-gradient(180deg,var(--primary),var(--primary-strong))}.week-bar small{overflow:hidden;color:var(--ink-faint);font-size:9px;font-weight:800;text-overflow:ellipsis;white-space:nowrap}.top-merchant-list{display:grid;gap:7px}.top-merchant-list>div{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:3px 9px;padding:9px 0;border-bottom:1px solid var(--border)}.top-merchant-list>div:last-child{border:0;padding-bottom:0}.top-merchant-list span{overflow:hidden;font-size:11px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.top-merchant-list small{color:var(--ink-faint);font-size:9px;font-weight:800}.top-merchant-list b{grid-column:2;grid-row:1 / span 2;align-self:center;font-size:10px}.compact-empty{display:grid;place-items:center;min-height:104px;color:var(--ink-faint);font-size:11px;font-weight:800}
@media(max-width:760px){.portal-tabs{margin-bottom:14px}.portal-tab{min-height:34px;padding:0 10px;font-size:10px}.operational-panel{padding:14px;border-radius:16px}.scoped-order-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.people-grid{grid-template-columns:1fr}.scoped-order-foot{align-items:flex-start;flex-direction:column;gap:5px}.location-overview-card{grid-template-columns:auto minmax(0,1fr);padding:15px}.location-overview-action{grid-column:span 2;width:100%}}
@media(max-width:760px){.branch-dashboard-details{grid-template-columns:1fr;gap:11px;margin-bottom:14px}.branch-detail-panel{padding:14px}.week-bars{height:110px}.week-bar{grid-template-rows:auto 62px auto}.week-bar>span{height:62px}.branch-financial-grid{gap:7px}}
@media(max-width:440px){.portal-tab{padding:0 9px}.portal-tab b{display:none}.scoped-order-card,.person-card{padding:12px}.scoped-order-head{align-items:flex-start;flex-direction:column}.status-pill{align-self:flex-start}.sheet-data-grid,.edit-person-form{grid-template-columns:1fr}.sheet-data-grid .wide,.edit-person-form .wide{grid-column:span 1}.document-row{align-items:flex-start;flex-wrap:wrap}.document-actions{width:100%}}
</style>
