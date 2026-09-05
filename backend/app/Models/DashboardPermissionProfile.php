<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named dashboard permission set. A profile may belong to the platform
 * (the historical default) or to exactly one operational branch.
 *
 * The application's existing `role` field identifies the kind of account
 * (admin, merchant, courier, etc.). It is intentionally not overloaded for
 * per-screen privileges: an admin account receives exactly one of these
 * profiles, while a super administrator remains the explicitly audited
 * break-glass account.
 */
class DashboardPermissionProfile extends Model
{
    /**
     * The complete, server-owned vocabulary of delegated dashboard actions.
     *
     * Each checkbox in the permission editor must correspond to a real
     * operation or a deliberately scoped read surface. Do not replace these
     * with a broad "update" grant: changing an order status, assigning a
     * courier, approving a finance request, and deleting an account are
     * separate security decisions.
     *
     * @var array<string, array{name_ar: string, name_en: string, name_ku: string, actions: array<string, array{ar: string, en: string, ku: string}>}>
     */
    public const MODULES = [
        'orders' => [
            'name_ar' => 'الطلبات', 'name_en' => 'Orders', 'name_ku' => 'داواکاریەکان',
            'actions' => [
                'view' => ['ar' => 'عرض الطلبات', 'en' => 'View orders', 'ku' => 'پیشاندانی داواکاریەکان'],
                'view_financial' => ['ar' => 'عرض مبالغ ورسوم الطلبات', 'en' => 'View order values and fees', 'ku' => 'پیشاندانی بڕ و کرێی داواکاریەکان'],
                'edit' => ['ar' => 'تعديل بيانات الطلب', 'en' => 'Edit order details', 'ku' => 'دەستکاریکردنی زانیارییەکانی داواکاری'],
                'change_status' => ['ar' => 'تغيير حالة الطلب', 'en' => 'Change order status', 'ku' => 'گۆڕینی دۆخی داواکاری'],
                'assign_courier' => ['ar' => 'تعيين مندوب', 'en' => 'Assign courier', 'ku' => 'دیاریکردنی گەیەنەر'],
                'reoffer_overdue_pickup' => ['ar' => 'إعادة نشر طلب متأخر', 'en' => 'Re-offer overdue pickup', 'ku' => 'دووبارە پێشکەشکردنی وەرگرتنی دواخراو'],
                'assign_branches' => ['ar' => 'تحديد مسار الفروع', 'en' => 'Assign branch route', 'ku' => 'دیاریکردنی ڕێڕەوی لقەکان'],
                'restore' => ['ar' => 'استعادة طلب محذوف', 'en' => 'Restore deleted order', 'ku' => 'گەڕاندنەوەی داواکاریی سڕاوە'],
                'delete' => ['ar' => 'حذف طلب', 'en' => 'Delete order', 'ku' => 'سڕینەوەی داواکاری'],
            ],
        ],
        'branches' => [
            'name_ar' => 'الفروع', 'name_en' => 'Branches', 'name_ku' => 'لقەکان',
            'actions' => [
                'view' => ['ar' => 'عرض الفروع', 'en' => 'View branches', 'ku' => 'پیشاندانی لقەکان'],
                'create' => ['ar' => 'إنشاء فرع', 'en' => 'Create branch', 'ku' => 'دروستکردنی لق'],
                'edit' => ['ar' => 'تعديل بيانات الفرع', 'en' => 'Edit branch details', 'ku' => 'دەستکاریکردنی زانیارییەکانی لق'],
                'change_status' => ['ar' => 'تفعيل أو تعطيل الفرع', 'en' => 'Change branch status', 'ku' => 'گۆڕینی دۆخی لق'],
                'manage_access' => ['ar' => 'إدارة حسابات وصول الفروع', 'en' => 'Manage branch access accounts', 'ku' => 'بەڕێوەبردنی هەژمارەکانی دەستگەیشتنی لق'],
                'delete' => ['ar' => 'حذف فرع', 'en' => 'Delete branch', 'ku' => 'سڕینەوەی لق'],
            ],
        ],
        'merchants' => [
            'name_ar' => 'التجار', 'name_en' => 'Merchants', 'name_ku' => 'بازرگانەکان',
            'actions' => [
                'view' => ['ar' => 'عرض التجار', 'en' => 'View merchants', 'ku' => 'پیشاندانی بازرگانەکان'],
                'edit' => ['ar' => 'تعديل بيانات التاجر', 'en' => 'Edit merchant profile', 'ku' => 'دەستکاریکردنی پڕۆفایلی بازرگان'],
                'change_status' => ['ar' => 'تفعيل أو تعطيل التاجر', 'en' => 'Change merchant status', 'ku' => 'گۆڕینی دۆخی بازرگان'],
                'verify' => ['ar' => 'توثيق التاجر أو إلغاء التوثيق', 'en' => 'Grant or revoke merchant verification', 'ku' => 'پشتڕاستکردنەوە یان لابردنی پشتڕاستی بازرگان'],
                'documents_view' => ['ar' => 'عرض مستندات التاجر', 'en' => 'View merchant documents', 'ku' => 'پیشاندانی بەڵگەنامەکانی بازرگان'],
                'documents_review' => ['ar' => 'مراجعة مستندات التاجر', 'en' => 'Review merchant documents', 'ku' => 'پێداچوونەوەی بەڵگەنامەکانی بازرگان'],
                'delete' => ['ar' => 'حذف تاجر', 'en' => 'Delete merchant', 'ku' => 'سڕینەوەی بازرگان'],
            ],
        ],
        'couriers' => [
            'name_ar' => 'المندوبون', 'name_en' => 'Couriers', 'name_ku' => 'گەیەنەرەکان',
            'actions' => [
                'view' => ['ar' => 'عرض المندوبين', 'en' => 'View couriers', 'ku' => 'پیشاندانی گەیەنەرەکان'],
                'edit' => ['ar' => 'تعديل بيانات المندوب', 'en' => 'Edit courier profile', 'ku' => 'دەستکاریکردنی پڕۆفایلی گەیەنەر'],
                'update_deduction' => ['ar' => 'تعديل استقطاع الإدارة لكل طلب', 'en' => 'Update administration deduction per order', 'ku' => 'دەستکاریکردنی بڕینی بەڕێوەبەرایەتی بۆ هەر داواکارییەک'],
                'change_status' => ['ar' => 'تفعيل أو تعطيل المندوب', 'en' => 'Change courier status', 'ku' => 'گۆڕینی دۆخی گەیەنەر'],
                'verify' => ['ar' => 'توثيق المندوب أو إلغاء التوثيق', 'en' => 'Grant or revoke courier verification', 'ku' => 'پشتڕاستکردنەوە یان لابردنی پشتڕاستی گەیەنەر'],
                'documents_view' => ['ar' => 'عرض مستندات المندوب', 'en' => 'View courier documents', 'ku' => 'پیشاندانی بەڵگەنامەکانی گەیەنەر'],
                'documents_review' => ['ar' => 'مراجعة مستندات المندوب', 'en' => 'Review courier documents', 'ku' => 'پێداچوونەوەی بەڵگەنامەکانی گەیەنەر'],
                'delete' => ['ar' => 'حذف مندوب', 'en' => 'Delete courier', 'ku' => 'سڕینەوەی گەیەنەر'],
            ],
        ],
        'courier_locations' => [
            'name_ar' => 'مواقع المندوبين', 'name_en' => 'Courier locations', 'name_ku' => 'شوێنی گەیەنەرەکان',
            'actions' => [
                'view' => ['ar' => 'عرض المواقع المباشرة', 'en' => 'View live locations', 'ku' => 'پیشاندانی شوێنە ڕاستەوخۆکان'],
            ],
        ],
        'finance' => [
            'name_ar' => 'المالية', 'name_en' => 'Finance', 'name_ku' => 'دارایی',
            'actions' => [
                // Opening the Finance screen alone deliberately carries no
                // finance records or totals. Each read surface below is an
                // independent decision so an operator can, for example,
                // approve requests without browsing the historical ledger.
                'view' => ['ar' => 'فتح شاشة المالية', 'en' => 'Open finance screen', 'ku' => 'کردنەوەی شاشەی دارایی'],
                'view_requests' => ['ar' => 'عرض سجل الطلبات المالية', 'en' => 'View finance request history', 'ku' => 'پیشاندانی مێژووی داواکارییە داراییەکان'],
                'view_ledger' => ['ar' => 'عرض دفتر الحركات المالية', 'en' => 'View finance ledger', 'ku' => 'پیشاندانی تۆماری جووڵە داراییەکان'],
                'view_summary' => ['ar' => 'عرض الملخص والبطاقات المالية', 'en' => 'View finance summary and cards', 'ku' => 'پیشاندانی پوختە و کارتە داراییەکان'],
                'view_balances' => ['ar' => 'عرض الأرصدة والتسويات', 'en' => 'View balances and settlement directory', 'ku' => 'پیشاندانی باڵانس و بەڕێوەبردنی یەکلاکردنەوەکان'],
                'approve' => ['ar' => 'اعتماد طلب مالي', 'en' => 'Approve finance request', 'ku' => 'پەسەندکردنی داواکاریی دارایی'],
                'reject' => ['ar' => 'رفض طلب مالي', 'en' => 'Reject finance request', 'ku' => 'ڕەتکردنەوەی داواکاریی دارایی'],
                'record_settlement' => ['ar' => 'تسجيل تسوية مالية', 'en' => 'Record financial settlement', 'ku' => 'تۆمارکردنی یەکلاکردنەوەی دارایی'],
            ],
        ],
        'cashboxes' => [
            'name_ar' => 'الصناديق', 'name_en' => 'Cashboxes', 'name_ku' => 'سندوقەکان',
            'actions' => [
                // Opening the operational cashbox directory must not reveal
                // collection values.  Balances and the custody ledger are
                // two separate financial read decisions.
                'view' => ['ar' => 'عرض أسماء وحالات الصناديق', 'en' => 'View cashbox identities and status', 'ku' => 'پیشاندانی ناسنامە و دۆخی سندوقەکان'],
                'view_balances' => ['ar' => 'عرض أرصدة صناديق التحصيل', 'en' => 'View cashbox collection balances', 'ku' => 'پیشاندانی باڵانسی کۆکردنەوەی سندوقەکان'],
                'view_ledger' => ['ar' => 'عرض سجل تحصيلات الصناديق', 'en' => 'View cashbox collection ledger', 'ku' => 'پیشاندانی تۆماری کۆکردنەوەی سندوقەکان'],
                'create' => ['ar' => 'إنشاء صندوق', 'en' => 'Create cashbox', 'ku' => 'دروستکردنی سندووق'],
                'transfer' => ['ar' => 'تحويل تحصيلات بين الصناديق', 'en' => 'Transfer collections between cashboxes', 'ku' => 'گواستنەوەی کۆکردنەوە لەنێوان سندوقەکان'],
                'change_status' => ['ar' => 'تفعيل أو تعطيل صندوق', 'en' => 'Change cashbox status', 'ku' => 'گۆڕینی دۆخی سندووق'],
            ],
        ],
        'pricing' => [
            'name_ar' => 'التسعير', 'name_en' => 'Pricing', 'name_ku' => 'نرخدانان',
            'actions' => [
                'view' => ['ar' => 'عرض قواعد التسعير', 'en' => 'View pricing rules', 'ku' => 'پیشاندانی یاساکانی نرخدانان'],
                'create' => ['ar' => 'إنشاء قاعدة تسعير', 'en' => 'Create pricing rule', 'ku' => 'دروستکردنی یاسای نرخدانان'],
                'edit' => ['ar' => 'تعديل قاعدة تسعير', 'en' => 'Edit pricing rule', 'ku' => 'دەستکاریکردنی یاسای نرخدانان'],
                'change_status' => ['ar' => 'تفعيل أو تعطيل قاعدة تسعير', 'en' => 'Change pricing-rule status', 'ku' => 'گۆڕینی دۆخی یاسای نرخدانان'],
            ],
        ],
        'reports' => [
            'name_ar' => 'التقارير', 'name_en' => 'Reports', 'name_ku' => 'ڕاپۆرتەکان',
            'actions' => [
                'view' => ['ar' => 'عرض تقارير التشغيل', 'en' => 'View operational reports', 'ku' => 'پیشاندانی ڕاپۆرتە کارپێکردنەکان'],
                'view_financial' => ['ar' => 'عرض التقارير المالية والأرصدة', 'en' => 'View financial reports and balances', 'ku' => 'پیشاندانی ڕاپۆرت و باڵانسە داراییەکان'],
            ],
        ],
        'platform' => [
            'name_ar' => 'إدارة المنصة', 'name_en' => 'Platform', 'name_ku' => 'بەڕێوەبردنی پلاتفۆرم',
            'actions' => [
                'view' => ['ar' => 'عرض إدارة المنصة', 'en' => 'View platform management', 'ku' => 'پیشاندانی بەڕێوەبردنی پلاتفۆرم'],
                'view_financial' => ['ar' => 'عرض الأسعار والفوترة وإيرادات المنصة', 'en' => 'View platform prices, billing, and revenue', 'ku' => 'پیشاندانی نرخ، پسوولە و داهاتی پلاتفۆرم'],
                'companies_create' => ['ar' => 'إنشاء شركة فقط (الاشتراك والفاتورة بصلاحيتين مستقلتين)', 'en' => 'Create company only (subscription and invoice are separate permissions)', 'ku' => 'تەنها دروستکردنی کۆمپانیا (بەشداربوون و پسوولە دەسەڵاتی جیاوازن)'],
                'companies_edit' => ['ar' => 'تعديل بيانات وحالة وصول الشركة', 'en' => 'Edit company details and access status', 'ku' => 'دەستکاریکردنی زانیاری و دۆخی دەستگەیشتنی کۆمپانیا'],
                'plans_create' => ['ar' => 'إنشاء باقة', 'en' => 'Create plan', 'ku' => 'دروستکردنی پلانی بەشداری'],
                'plans_edit' => ['ar' => 'تعديل باقة', 'en' => 'Edit plan', 'ku' => 'دەستکاریکردنی پلانی بەشداری'],
                'subscriptions_create' => ['ar' => 'إنشاء/تجديد اشتراك وتحديث وصول الشركة (من دون فاتورة)', 'en' => 'Create or renew subscription and update company access (no invoice)', 'ku' => 'دروستکردن یان نوێکردنەوەی بەشداربوون و نوێکردنەوەی دەستگەیشتنی کۆمپانیا (بێ پسوولە)'],
                'subscriptions_change_status' => ['ar' => 'تغيير حالة الاشتراك ووصول الشركة المرتبط (من دون فاتورة)', 'en' => 'Change subscription and linked company access status (no invoice)', 'ku' => 'گۆڕینی دۆخی بەشداربوون و دەستگەیشتنی پەیوەندیداری کۆمپانیا (بێ پسوولە)'],
                'invoices_create' => ['ar' => 'إصدار فاتورة', 'en' => 'Issue invoice', 'ku' => 'دەرکردنی پسوڵە'],
                'invoices_change_status' => ['ar' => 'تغيير حالة فاتورة', 'en' => 'Change invoice status', 'ku' => 'گۆڕینی دۆخی پسوڵە'],
            ],
        ],
        'notifications' => [
            'name_ar' => 'الإشعارات', 'name_en' => 'Notifications', 'name_ku' => 'ئاگادارکردنەوەکان',
            'actions' => [
                'view' => ['ar' => 'عرض سجل الإشعارات', 'en' => 'View notification history', 'ku' => 'پیشاندانی تۆماری ئاگادارکردنەوەکان'],
                'send' => ['ar' => 'إرسال إشعار', 'en' => 'Send notification', 'ku' => 'ناردنی ئاگادارکردنەوە'],
            ],
        ],
        'settings' => [
            'name_ar' => 'الإعدادات', 'name_en' => 'Settings', 'name_ku' => 'ڕێکخستنەکان',
            'actions' => [
                'view' => ['ar' => 'عرض الإعدادات', 'en' => 'View settings', 'ku' => 'پیشاندانی ڕێکخستنەکان'],
                'update_branding' => ['ar' => 'تعديل الهوية والشعار', 'en' => 'Update branding and logo', 'ku' => 'دەستکاریکردنی ناسنامە و لۆگۆ'],
                'update_support' => ['ar' => 'تعديل معلومات الدعم', 'en' => 'Update support information', 'ku' => 'دەستکاریکردنی زانیارییەکانی پشتگیری'],
                'update_financial_defaults' => ['ar' => 'تعديل سعر التوصيل الافتراضي', 'en' => 'Update default delivery fee', 'ku' => 'دەستکاریکردنی نرخی بنەڕەتی گەیاندن'],
                'update_courier_deduction_default' => ['ar' => 'تعديل استقطاع الإدارة الافتراضي للمندوب', 'en' => 'Update default courier administration deduction', 'ku' => 'دەستکاریکردنی بڕینی بنەڕەتی بەڕێوەبەرایەتی بۆ گەیەنەر'],
                'update_timing' => ['ar' => 'تعديل أوقات الطلبات', 'en' => 'Update order timing', 'ku' => 'دەستکاریکردنی کاتەکانی داواکاری'],
                'update_public_content' => ['ar' => 'تعديل محتوى التطبيق والقانوني', 'en' => 'Update public and legal content', 'ku' => 'دەستکاریکردنی ناوەڕۆکی گشتی و یاسایی'],
            ],
        ],
        'provinces' => [
            'name_ar' => 'المحافظات', 'name_en' => 'Governorates', 'name_ku' => 'پارێزگاکان',
            'actions' => [
                'view' => ['ar' => 'عرض المحافظات', 'en' => 'View governorates', 'ku' => 'پیشاندانی پارێزگاکان'],
                'create' => ['ar' => 'إضافة محافظة', 'en' => 'Create governorate', 'ku' => 'زیادکردنی پارێزگا'],
                'edit' => ['ar' => 'تعديل محافظة', 'en' => 'Edit governorate', 'ku' => 'دەستکاریکردنی پارێزگا'],
                'change_status' => ['ar' => 'تفعيل أو تعطيل محافظة', 'en' => 'Change governorate status', 'ku' => 'گۆڕینی دۆخی پارێزگا'],
            ],
        ],
        // The slider lives inside Settings, but it has its own data and its
        // own write boundary. Keeping a module key avoids giving a settings
        // editor the ability to publish app-facing artwork by accident.
        'content' => [
            'name_ar' => 'السلايدر', 'name_en' => 'Slider', 'name_ku' => 'سلایدەر',
            'actions' => [
                'view' => ['ar' => 'عرض السلايدر', 'en' => 'View slider', 'ku' => 'پیشاندانی سلایدەر'],
                'create' => ['ar' => 'إضافة شريحة', 'en' => 'Create slide', 'ku' => 'زیادکردنی سلاید'],
                'edit' => ['ar' => 'تعديل شريحة', 'en' => 'Edit slide', 'ku' => 'دەستکاریکردنی سلاید'],
                'delete' => ['ar' => 'حذف شريحة', 'en' => 'Delete slide', 'ku' => 'سڕینەوەی سلاید'],
            ],
        ],
        'loyalty' => [
            'name_ar' => 'نقاط المندوبين', 'name_en' => 'Courier points', 'name_ku' => 'خاڵەکانی گەیەنەر',
            'actions' => [
                'view' => ['ar' => 'عرض النقاط والسجل', 'en' => 'View points and ledger', 'ku' => 'پیشاندانی خاڵەکان و تۆمار'],
                'update_reward_setting' => ['ar' => 'تعديل نقاط الطلب المسلّم', 'en' => 'Update delivery reward', 'ku' => 'دەستکاریکردنی پاداشتی داواکاریی گەیەنراو'],
                'adjust_points' => ['ar' => 'إضافة أو خصم نقاط يدوياً', 'en' => 'Adjust points manually', 'ku' => 'زیادکردن یان کەمکردنەوەی دەستیی خاڵەکان'],
            ],
        ],
        'chat' => [
            'name_ar' => 'المحادثات', 'name_en' => 'Chat', 'name_ku' => 'گفتوگۆکان',
            'actions' => [
                'view' => ['ar' => 'عرض المحادثات', 'en' => 'View conversations', 'ku' => 'پیشاندانی گفتوگۆکان'],
                'reply' => ['ar' => 'الرد على محادثة الدعم', 'en' => 'Reply to support conversation', 'ku' => 'وەڵامدانەوەی گفتوگۆی پشتگیری'],
            ],
        ],
        'transfers' => [
            'name_ar' => 'التحويلات', 'name_en' => 'Transfers', 'name_ku' => 'گواستنەوەکان',
            'actions' => [
                'view' => ['ar' => 'عرض التحويلات', 'en' => 'View transfers', 'ku' => 'پیشاندانی گواستنەوەکان'],
                'create' => ['ar' => 'إنشاء تحويل', 'en' => 'Create transfer', 'ku' => 'دروستکردنی گواستنەوە'],
                'dispatch' => ['ar' => 'إرسال تحويل', 'en' => 'Dispatch transfer', 'ku' => 'ناردنی گواستنەوە'],
                'receive' => ['ar' => 'استلام تحويل', 'en' => 'Receive transfer', 'ku' => 'وەرگرتنی گواستنەوە'],
            ],
        ],
        'employees' => [
            'name_ar' => 'موظفو النظام', 'name_en' => 'System employees', 'name_ku' => 'کارمەندانی سیستەم',
            'actions' => [
                'view' => ['ar' => 'عرض موظفي النظام', 'en' => 'View system employees', 'ku' => 'پیشاندانی کارمەندانی سیستەم'],
                'create' => ['ar' => 'إضافة موظف نظام', 'en' => 'Create system employee', 'ku' => 'دروستکردنی کارمەندی سیستەم'],
                'edit' => ['ar' => 'تعديل موظف نظام', 'en' => 'Edit system employee', 'ku' => 'دەستکاریکردنی کارمەندی سیستەم'],
                'change_status' => ['ar' => 'تفعيل أو تعطيل موظف', 'en' => 'Change employee status', 'ku' => 'گۆڕینی دۆخی کارمەند'],
                'delete' => ['ar' => 'حذف موظف نظام', 'en' => 'Delete system employee', 'ku' => 'سڕینەوەی کارمەندی سیستەم'],
            ],
        ],
        'permissions' => [
            'name_ar' => 'الصلاحيات', 'name_en' => 'Permissions', 'name_ku' => 'دەسەڵاتەکان',
            'actions' => [
                'view' => ['ar' => 'عرض ملفات الصلاحيات', 'en' => 'View permission profiles', 'ku' => 'پیشاندانی پڕۆفایلەکانی دەسەڵات'],
                'create' => ['ar' => 'إنشاء ملف صلاحيات', 'en' => 'Create permission profile', 'ku' => 'دروستکردنی پڕۆفایلی دەسەڵات'],
                'edit' => ['ar' => 'تعديل ملف صلاحيات', 'en' => 'Edit permission profile', 'ku' => 'دەستکاریکردنی پڕۆفایلی دەسەڵات'],
                'delete' => ['ar' => 'حذف ملف صلاحيات', 'en' => 'Delete permission profile', 'ku' => 'سڕینەوەی پڕۆفایلی دەسەڵات'],
                'assign' => ['ar' => 'إسناد صلاحيات لموظف', 'en' => 'Assign a profile to an employee', 'ku' => 'دیاریکردنی پڕۆفایل بۆ کارمەند'],
            ],
        ],
    ];

    /**
     * A profile created before the granular matrix existed can remain in the
     * database while deployments roll out. New saves expand these legacy
     * verbs into their old, equivalent powers; they are never shown as new
     * checkboxes.
     *
     * @var array<string, array<string, array<string, array<int, string>>>>
     */
    private const LEGACY_PERMISSION_EXPANSIONS = [
        // Keep these expansions constrained to operations that the former
        // broad route actually exposed. In particular, an old order updater
        // could not edit/delete an order, and an old branch updater could
        // not delete a branch. Compatibility must never become escalation.
        'orders' => ['update' => ['orders' => ['change_status', 'assign_courier', 'reoffer_overdue_pickup', 'assign_branches', 'restore']]],
        'branches' => ['update' => ['branches' => ['edit', 'change_status', 'manage_access']]],
        // Document review and document-image access are deliberately
        // independent. Historical update routes could review a document but
        // did not expose its protected image endpoint.
        'merchants' => ['update' => ['merchants' => ['edit', 'change_status', 'verify', 'documents_review']]],
        'couriers' => ['update' => ['couriers' => ['edit', 'update_deduction', 'change_status', 'documents_review']]],
        'finance' => ['update' => ['finance' => ['view_balances', 'approve', 'reject', 'record_settlement']]],
        'cashboxes' => ['update' => ['cashboxes' => ['transfer', 'change_status']]],
        'pricing' => ['update' => ['pricing' => ['edit', 'change_status']]],
        'platform' => [
            'create' => ['platform' => ['companies_create', 'plans_create', 'subscriptions_create', 'invoices_create']],
            'update' => ['platform' => ['companies_edit', 'plans_edit', 'subscriptions_change_status', 'invoices_change_status']],
        ],
        'notifications' => ['create' => ['notifications' => ['send']]],
        'settings' => [
            'update' => [
                'settings' => ['update_branding', 'update_support', 'update_financial_defaults', 'update_timing', 'update_public_content'],
                'provinces' => ['create', 'edit', 'change_status'],
            ],
        ],
        'content' => ['update' => ['content' => ['edit']]],
        'loyalty' => ['update' => ['loyalty' => ['update_reward_setting', 'adjust_points']]],
        'chat' => ['create' => ['chat' => ['reply']]],
        'transfers' => ['update' => ['transfers' => ['dispatch', 'receive']]],
    ];

    /** @var array<string, array{ar: string, en: string, ku: string}> */
    public const ACTION_LABELS = [
        'view' => ['ar' => 'عرض', 'en' => 'View', 'ku' => 'پیشاندان'],
        'create' => ['ar' => 'إنشاء', 'en' => 'Create', 'ku' => 'دروستکردن'],
        'edit' => ['ar' => 'تعديل', 'en' => 'Edit', 'ku' => 'دەستکاری'],
        'delete' => ['ar' => 'حذف', 'en' => 'Delete', 'ku' => 'سڕینەوە'],
    ];

    protected $fillable = [
        'branch_id',
        'name',
        'permissions',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'permission_profile_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Human-facing catalog for the dashboard. Names and action-specific
     * labels are supplied by the server so the browser cannot invent a
     * permission vocabulary.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function catalog(): array
    {
        return collect(self::MODULES)
            ->map(function (array $module, string $key): array {
                $actionLabels = $module['actions'];

                return [
                    'key' => $key,
                    'name' => $module['name_ar'],
                    'name_ar' => $module['name_ar'],
                    'name_en' => $module['name_en'],
                    'name_ku' => $module['name_ku'],
                    'actions' => collect($actionLabels)
                        ->map(fn (array $label, string $action) => ['key' => $action, 'label' => $label])
                        ->values()
                        ->all(),
                    // Retain this map for profile summaries and employee
                    // cards that deliberately consume the catalog directly.
                    'action_labels' => $actionLabels,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Reduce arbitrary request input to the finite, server-owned permission
     * vocabulary. It accepts both an action list (`['view', 'edit']`) and a
     * checkbox map (`['view' => true, 'edit' => false]).
     *
     * Any non-view capability automatically includes its module's view
     * capability. A person who can act in a screen must be able to open that
     * screen, while the server still checks the exact mutation independently.
     *
     * @param  array<string, mixed>  $permissions
     * @return array<string, array<int, string>>
     */
    public static function normalizePermissions(array $permissions): array
    {
        /** @var array<string, array<string, true>> $selected */
        $selected = [];

        foreach ($permissions as $module => $actions) {
            if (! is_string($module) || ! isset(self::MODULES[$module]) || ! is_array($actions)) {
                continue;
            }

            foreach ($actions as $key => $value) {
                $action = is_int($key) ? $value : $key;
                $enabled = is_int($key) || filter_var($value, FILTER_VALIDATE_BOOLEAN);

                if (! is_string($action) || ! $enabled) {
                    continue;
                }

                if (isset(self::MODULES[$module]['actions'][$action])) {
                    $selected[$module][$action] = true;

                    continue;
                }

                foreach (self::legacyExpansionFor($module, $action) as $targetModule => $targetActions) {
                    foreach ($targetActions as $targetAction) {
                        $selected[$targetModule][$targetAction] = true;
                    }
                }
            }
        }

        $normalized = [];

        foreach (self::MODULES as $module => $definition) {
            if (empty($selected[$module])) {
                continue;
            }

            if (isset($definition['actions']['view'])) {
                $selected[$module]['view'] = true;
            }

            $actions = array_values(array_filter(
                array_keys($definition['actions']),
                fn (string $action): bool => isset($selected[$module][$action]),
            ));

            if ($actions !== []) {
                $normalized[$module] = $actions;
            }
        }

        return $normalized;
    }

    public function allows(string $module, string $action): bool
    {
        $permissions = is_array($this->permissions) ? $this->permissions : [];

        if (isset(self::MODULES[$module]['actions'][$action])
            && in_array($action, $permissions[$module] ?? [], true)) {
            return true;
        }

        // Retain runtime compatibility for a profile that was written before
        // the data migration has reached this deployment. This branch can
        // only expand a historical broad grant; it never turns a new action
        // into an unrecognised grant.
        foreach (self::LEGACY_PERMISSION_EXPANSIONS as $legacyModule => $legacyActions) {
            foreach ($legacyActions as $legacyAction => $targets) {
                if (! in_array($legacyAction, $permissions[$legacyModule] ?? [], true)) {
                    continue;
                }

                $targetActions = $targets[$module] ?? [];

                // A former broad mutation also gave access to the screen it
                // operated on. Mirror the normalizer's automatic `view`
                // dependency for an un-migrated profile that is still held
                // in memory during a rolling deployment.
                if (in_array($action, $targetActions, true)
                    || ($action === 'view' && $targetActions !== [])) {
                    return true;
                }
            }
        }

        // Older browser bundles post all five independent settings groups to
        // one endpoint. A migrated legacy profile now contains those exact
        // actions rather than the obsolete `settings.update` string, so it
        // may still use that endpoint only when it has every equivalent
        // scoped permission. Selecting just one checkbox can never unlock
        // the old broad request.
        if ($module === 'settings' && $action === 'update') {
            $requiredActions = self::LEGACY_PERMISSION_EXPANSIONS['settings']['update']['settings'];

            return in_array('update', $permissions['settings'] ?? [], true)
                || array_diff($requiredActions, $permissions['settings'] ?? []) === [];
        }

        // The retained /dashboard/settings compatibility endpoint also
        // accepts a genuinely un-migrated legacy profile during deployment.
        return isset(self::LEGACY_PERMISSION_EXPANSIONS[$module][$action])
            && in_array($action, $permissions[$module] ?? [], true);
    }

    /**
     * A profile which contains every current dashboard action is an explicit
     * delegated full-access profile.  Keeping this derived from the server
     * catalogue means that a newly added action is never silently granted to
     * old profiles: it must be selected and saved again by an administrator.
     */
    public function grantsFullDashboardAccess(): bool
    {
        $permissions = is_array($this->permissions) ? $this->permissions : [];

        foreach (self::MODULES as $module => $definition) {
            $granted = $permissions[$module] ?? [];

            if (! is_array($granted)
                || array_diff(array_keys($definition['actions']), $granted) !== []) {
                return false;
            }
        }

        return self::MODULES !== [];
    }

    /** @return array<string, array<int, string>> */
    private static function legacyExpansionFor(string $module, string $action): array
    {
        return self::LEGACY_PERMISSION_EXPANSIONS[$module][$action] ?? [];
    }
}
