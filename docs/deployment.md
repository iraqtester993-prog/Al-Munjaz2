# نشر المنجز السريع على cPanel

## النتيجة المطلوبة

ينشر إصدار Laravel واحد ويخدم النطاقين من backend/public نفسه:

- https://mobile.our-qiq.com للتاجر والمندوب وPWA.
- https://admin.our-qiq.com/dashboard/login للإدارة.

لا ترفع frontends/app-pwa/dist أو frontends/dashboard-pwa/dist إلى الإنتاج، ولا تنشئ API أو app أو dashboard كأنها تطبيقات مستقلة. ملفات Vue الناتجة المطلوبة موجودة في backend/public/build بعد تنفيذ npm run build داخل backend.

## قبل النشر

1. خذ نسخة احتياطية قابلة للاسترجاع من قاعدة البيانات الحالية ومن ملفات .env وstorage.
2. من نسخة Git المراد نشرها شغل محليا أو على الخادم: npm ci ثم npm run build، وبعدها php artisan test.
3. ارفع مجلد backend كاملا إلى مسار خارج public_html إن أمكن، مثل /home/ourqiq/releases/<release>/backend. لا ترفع .env المحلي ولا node_modules.
4. أنشئ أو انسخ ملف .env للإصدار على الخادم فقط، واحفظ كلمة مرور MySQL خارج Git.
5. تأكد أن كل من mobile.our-qiq.com وadmin.our-qiq.com يملكان شهادة SSL فعالة قبل تفعيل الإصدار.

## Document Root الصحيح

عيّن Document Root لكلا النطاقين إلى المسار نفسه:

    /home/ourqiq/releases/<release>/backend/public

يفضل ضبط ذلك من cPanel أو من شركة الاستضافة. إذا كانت اللوحة لا تقبل المسار خارج public_html، اطلب من الاستضافة تفعيل document root مخصص أو وصل رمزي مسموح به لكلا النطاقين إلى هذا المجلد نفسه. لا تنسخ محتوى public إلى مجلد mobile ومجلد admin بشكل يدوي؛ النسختان المنفصلتان تسببان 404 وMIME خاطئ وPWA قديم.

بديل محدود عندما يرفض المزود تغيير Document Root: انسخ deployment/cpanel-root-index.php إلى index.php في كل جذر نطاق بعد تعديل مسار الإصدار داخله، وانسخ backend/public/.htaccess إلى جانبه. هذا البديل يمرر ملفات build/assets وassets بأمان إلى public الحقيقي؛ لا تستخدم ملف index بسيطاً يحتوي require فقط.

يجب أن يحتوي الجذر العام على index.php و.htaccess وbuild وassets. لا تجعل الجذر يشير إلى backend نفسه، وإلا ستظهر أخطاء 403 أو 404 أو صفحة بيضاء.

## ملف البيئة الإنتاجي

استخدم قيما مكافئة للتالي، مع بيانات قاعدة الإنتاج الحقيقية فقط:

    APP_ENV=production
    APP_DEBUG=false
    APP_URL=https://mobile.our-qiq.com
    PRODUCT_DOMAIN=our-qiq.com
    PRODUCT_MOBILE_HOST=mobile.our-qiq.com
    PRODUCT_ADMIN_HOST=admin.our-qiq.com
    PWA_VERSION=v13

    DB_CONNECTION=mysql
    DB_HOST=localhost
    DB_PORT=3306
    DB_DATABASE=<production_database>
    DB_USERNAME=<production_user>
    DB_PASSWORD=<secret>

    SESSION_DRIVER=database
    SESSION_COOKIE=almunjaz_session_v13
    SESSION_DOMAIN=null
    SESSION_SECURE_COOKIE=true
    SESSION_SAME_SITE=lax

استخدم اسم كوكي جديدا عند أول تفعيل هذه البنية أو عند تغيير كبير. لا تستخدم SESSION_DOMAIN=.our-qiq.com؛ المطلوب أن تكون كل جلسة محصورة في مضيفها. لا تنسخ APP_KEY أو كلمة مرور قاعدة البيانات إلى README أو Git أو لقطة شاشة.

## تفعيل الإصدار من Terminal

من داخل مجلد backend للإصدار الجديد، وبعد التحقق من المسار وملف .env:

    composer install --no-dev --prefer-dist --optimize-autoloader
    php artisan migrate --force
    php artisan storage:link
    php artisan optimize:clear
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache

اجعل storage وbootstrap/cache قابلين للكتابة من حساب cPanel نفسه. استخدم صلاحيات ملفات ومجلدات اعتيادية مثل 644 للملفات و755 للمجلدات، ولا تستخدم 777 كحل دائم.

إذا لم تتوفر Composer أو صلاحية تغيير Document Root في الاستضافة، فهذه صلاحية خادم وليست خطوة يمكن تعويضها برفع ملفات عشوائية. اطلب من شركة الاستضافة تفعيل Composer أو تنفيذ الأوامر وتعيين الجذرين للمسار المشترك.

## تحديث PWA بلا صفحة بيضاء

1. ابن ملفات Vite الجديدة قبل الرفع وتحقق أن backend/public/build/manifest.json يطابق ملفات assets المرفوعة.
2. ارفع الكود وpublic/build في الإصدار نفسه قبل تحويل Document Root إليه.
3. غير PWA_VERSION إلى قيمة جديدة لكل نشر تطبيق، مثل v14 ثم نفذ optimize:clear وconfig:cache.
4. تعتمد النسخة الحية على /pwa/manifest و/pwa/worker. أبقِ /manifest.json و/sw.js ضمن public لأنهما مسارا ترحيل متوافقان للتطبيقات القديمة، ولا تعدلهما يدوياً أو تنشر worker منفصلاً.
5. افتح في نافذة خاصة: /login و/dashboard/login و/pwa/manifest و/pwa/worker. يجب أن تكون أول صفحتين 200، وأن يرجع manifest نوع application/manifest+json والعامل application/javascript.

لا تمسح كاش المتصفح كحل وحيد؛ عدم تطابق public/build مع manifest أو نشر worker قديم هو السبب الذي يجب إصلاحه في الإصدار.

## فحص ما بعد النشر

- تأكد أن mobile.our-qiq.com/login يعرض تطبيق التاجر والمندوب فقط.
- تأكد أن admin.our-qiq.com/dashboard/login يعرض دخول الإدارة فقط.
- تأكد أن فتح /dashboard على mobile يعيد إلى /login.
- تأكد أن فتح /app على admin لا يعرض تطبيق التاجر أو المندوب.
- اختبر التسجيل وتسجيل الدخول وCSRF ورفع المستندات وطلبا تجريبيا بصلاحيات مناسبة.
- من هاتف فعلي، ثبت PWA من mobile فقط وافتحه بعد التثبيت.

## التراجع

احتفظ بالإصدار السابق كما هو، ولا تحذف مجلده قبل نجاح الفحص. عند حدوث خلل، أعد كلا Document Root إلى public للإصدار السابق، ثم استعد قاعدة البيانات فقط إذا كانت migration الأخيرة غير متوافقة. لا تنفذ rollback تلقائيا على قاعدة إنتاج فيها بيانات جديدة من دون مراجعة.
