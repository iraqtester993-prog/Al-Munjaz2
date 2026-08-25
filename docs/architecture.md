# بنية المنجز السريع

## المنتج والنطاقات

| المنتج | النطاق | نقطة الدخول | المستخدمون |
| --- | --- | --- | --- |
| تطبيق الهاتف | https://mobile.our-qiq.com | /login ثم /app | التاجر والمندوب |
| لوحة الإدارة | https://admin.our-qiq.com | /dashboard/login ثم /dashboard | الإدارة فقط |

لا يوجد في البنية الحالية تطبيق إنتاجي مستقل على app.our-qiq.com أو dashboard.our-qiq.com أو api.our-qiq.com. لا تعتمد عليها في DNS أو ملفات البيئة أو التوثيق.

## إصدار واحد ومصدرا واجهة

المصدر التشغيلي هو backend/. Laravel يعرض صفحة Inertia واحدة، ثم تحمل Vue الصفحة الصحيحة:

    mobile.our-qiq.com
      /login و/register/{role}          الدخول والتسجيل
      /app و/app/*                     تطبيق التاجر أو المندوب

    admin.our-qiq.com
      /dashboard/login                 دخول الإدارة
      /dashboard و/dashboard/*         لوحة الإدارة

كلا النطاقين يجب أن يشيرا إلى نسخة الإصدار نفسها في backend/public. طبقة EnsureDashboardHost تمنع ظهور واجهة التطبيق العامة تحت نطاق الإدارة، والـmiddleware الخاص بالأدوار يمنع دخول التاجر أو المندوب إلى لوحة الإدارة.

## طبقات المشروع

- backend/app: وحدات Laravel، المصادقة، الصلاحيات، سير الطلب، الدردشة، المحافظ والمحافظ المالية.
- backend/routes/web.php: مسارات الويب الخاصة بالتطبيق والإدارة.
- backend/resources/js: واجهات Vue/Inertia؛ AppShell للتاجر والمندوب وAdminShell للإدارة.
- backend/resources/pwa: قوالب PWA فقط.
- backend/public: نقطة دخول Apache وملفات Vite الناتجة في public/build.
- backend/database: المخطط وseeders.
- frontends: ملفات أو معاينات قديمة، وليست جزءا من مسار إنتاج Laravel الحالي.

## الجلسات والحماية بين النطاقين

التطبيق يستخدم جلسات Laravel وCSRF، وليس مفاتيح Sanctum مخزنة في المتصفح. يجب أن تبقى الجلسة host-only كي لا تنتقل كوكي التاجر أو المندوب إلى admin.our-qiq.com. الإعداد الإنتاجي المقصود هو:

    SESSION_COOKIE=almunjaz_session_v13
    SESSION_DOMAIN=null
    SESSION_SECURE_COOKIE=true
    SESSION_SAME_SITE=lax

عند تغيير اسم الكوكي أو إصدار كبير، يسجل المستخدمون الدخول من جديد؛ هذا مقصود وآمن. لا تضبط SESSION_DOMAIN على .our-qiq.com لأن ذلك يعيد مشكلة اختلاط جلسات التطبيق والإدارة.

## PWA وكاش الإصدارات

المضيف mobile فقط يضم manifest وService Worker. Laravel يخدم:

    GET /pwa/manifest
    GET /pwa/worker

ويضيف قيمة PWA_VERSION نفسها إلى رابط manifest وإلى اسم كاش العامل. ترفع ملفات Vite المبنية مع الإصدار، ثم تغير PWA_VERSION لكل نشر لتجديد التطبيق المثبت بأمان. المساران القديمان /manifest.json و/sw.js موجودان فقط لترحيل التطبيقات المثبتة سابقاً إلى المسارين الديناميكيين؛ لا تستخدمهما كمصدر نشر مستقل.

## البيانات والتشغيل

يحمل كل طلب province_id، ويرتبط المستخدم بمحافظته أو محافظاته المسموح بها. لا يسمح بإسناد مندوب خارج نطاق محافظة الطلب. يدعم النظام فروع الشركة في لوحة الإدارة، ويبقى سجل حالات الطلب والحركات المالية منفصلا عن الرصيد الحالي للمحفظة.

دورة الطلب الأساسية:

    pending -> approved -> courier -> delivered أو returned

كل تغيير حالة يسجل في order_status_logs. يجب تنفيذ تغييرات الطلبات عبر خدمات Laravel ومساراتها، لا بتعديل الجداول يدويا.
