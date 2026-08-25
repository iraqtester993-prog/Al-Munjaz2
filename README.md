# المنجز السريع

نظام توصيل عراقي للتاجر والمندوب وإدارة الشركة. التطبيق ولوحة الإدارة يعملان من إصدار Laravel واحد، مع واجهات Vue عبر Inertia وقاعدة بيانات واحدة للإنتاج.

## الروابط الإنتاجية

- تطبيق التاجر والمندوب: https://mobile.our-qiq.com
- لوحة الإدارة: https://admin.our-qiq.com/dashboard/login

لا تستخدم نطاقات app أو dashboard أو api القديمة في إعدادات الإنتاج أو في روابط المستخدمين.

## البنية الفعلية

    backend/
      app/                    منطق Laravel والمصادقة والطلبات والإدارة
      database/               migrations وseeders
      resources/js/           صفحات Vue/Inertia ومكوّنات التطبيق والداشبورد
      resources/css/          أنماط الواجهات
      resources/pwa/          قالب manifest وService Worker
      routes/web.php          كل مسارات الويب
      public/                 نقطة الدخول الوحيدة وملفات Vite المبنية
    docs/                     التوثيق التشغيلي
    frontends/                نماذج أو واجهات قديمة؛ ليست مصدر نشر الإنتاج

تخدم mobile.our-qiq.com وadmin.our-qiq.com المجلد نفسه: backend/public من الإصدار نفسه. اختلاف الواجهة تحدده المضيفات والمسارات والصلاحيات، وليس مشروع Vue مستقل أو نسخة ملفات منفصلة.

## PWA

تطبيق الهاتف فقط هو PWA. صفحة Laravel تولد الروابط التالية ديناميكيا:

- /pwa/manifest
- /pwa/worker

كلاهما لا يخزن في كاش وسيط، ويأخذان النسخة نفسها من PWA_VERSION. يحتفظ المشروع بمساري /manifest.json و/sw.js كتحويل متوافق للتطبيقات المثبتة القديمة؛ لا تعدلهما أو تبنِ عليهما إصداراً مستقلاً. لا تنشر frontends/app-pwa/dist إلى الإنتاج.

## التطوير المحلي

    cd backend
    composer install
    npm install
    php artisan migrate --seed
    npm run dev

لإنشاء ملفات الإنتاج:

    cd backend
    npm run build
    php artisan test

راجع docs/architecture.md قبل التطوير، وdocs/deployment.md قبل أي رفع، وdocs/staging.md قبل اختبار نسخة تجريبية.

## قاعدة العمل

لا تعدل ملفات البيئة الإنتاجية أو كلمات مرور قاعدة البيانات داخل Git. يجب أن يكون ملف .env محفوظا على الخادم فقط، ويجب نشر الكود وملفات public/build الناتجة من نفس commit.
