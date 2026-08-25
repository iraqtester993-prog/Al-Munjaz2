# البيئة التجريبية

## الهدف والبنية

بيئة Staging تستعمل إصدار Laravel مستقلا عن الإنتاج، لكنها تتبع البنية نفسها:

- https://staging-mobile.our-qiq.com للتطبيق.
- https://staging-admin.our-qiq.com/dashboard/login للإدارة.

النطاقان يشيران إلى backend/public من إصدار staging نفسه. لا توجد واجهات dist مستقلة أو نطاق API مطلوب للتشغيل العادي.

## قاعدة البيانات الحقيقية: تحذير ضروري

لا تربط staging مباشرة بقاعدة الإنتاج الحية. عندها لا تكون بيئة تجريبية، بل تصبح اختبارا على المستخدمين والطلبات والأرصدة الحقيقية.

إذا كان المطلوب مشاهدة بيانات واقعية، استخدم نسخة مستقلة من قاعدة الإنتاج في وقت محدد، مثلا ourqiq_almunjaz_staging. الاختبارات والتعديلات تذهب إلى هذه النسخة فقط. يفضل إخفاء أرقام الهواتف والوثائق والبيانات الحساسة إن لم تكن ضرورية للاختبار.

لا تستخدم في staging:

- قاعدة الإنتاج نفسها أو مستخدم MySQL الإنتاج.
- APP_KEY أو SESSION_COOKIE الإنتاجيين.
- خدمات رسائل أو دفع أو webhooks أو queue إنتاجية فعالة.
- Storage الإنتاجي القابل للكتابة.

## ملف البيئة التجريبي

    APP_ENV=staging
    APP_DEBUG=false
    APP_URL=https://staging-mobile.our-qiq.com
    PRODUCT_DOMAIN=our-qiq.com
    PRODUCT_MOBILE_HOST=staging-mobile.our-qiq.com
    PRODUCT_ADMIN_HOST=staging-admin.our-qiq.com
    PWA_VERSION=staging-v13

    DB_CONNECTION=mysql
    DB_DATABASE=ourqiq_almunjaz_staging
    DB_USERNAME=<staging_user>
    DB_PASSWORD=<staging_secret>

    SESSION_COOKIE=almunjaz_staging_session_v13
    SESSION_DOMAIN=null
    SESSION_SECURE_COOKIE=true
    SESSION_SAME_SITE=lax

    MAIL_MAILER=log
    QUEUE_CONNECTION=sync

استخدم APP_KEY منفصلا للتجريبي. اختلاف اسم الجلسة وPWA_VERSION عن الإنتاج يمنع تداخل الكوكيز والكاش بين البيئتين.

## الوصول وحماية البيانات

- اجعل الوصول خاصا بفريق الإدارة والاختبار فقط، مع كلمات مرور قوية ويفضل تقييد IP أو Basic Auth أمام النطاقين.
- لا تفهرس نطاقات staging ولا تشاركها علنا.
- عطل أي إرسال خارجي فعلي، بما فيه SMS والبريد والدفع والتكاملات التي تغير بيانات العملاء.
- امنح أقل صلاحية ممكنة لحساب قاعدة staging ولملفات storage.
- لا تلتقط أو تشارك لقطات شاشة تتضمن وثائق الهوية أو معلومات الدفع.

## دورة اختبار آمنة

1. خذ نسخة احتياطية من الإنتاج.
2. انسخها إلى قاعدة staging منفصلة، ثم طبق سياسة إخفاء البيانات عند الحاجة.
3. انشر commit محددا إلى إصدار staging وشغل migrations عليه.
4. تأكد من mobile وadmin وPWA ومن سجل الأخطاء.
5. اختبر الوظائف على حسابات اختبار، لا على حسابات العملاء الحقيقية.
6. بعد الموافقة، انشر commit نفسه إلى الإنتاج وفق docs/deployment.md.

تحديث البيانات من الإنتاج إلى staging عملية مقصودة ومجدولة، وليست مزامنة مباشرة أو تلقائية.
