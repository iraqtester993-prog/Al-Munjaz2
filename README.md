# المنجز

نظام توصيل عراقي يدعم المحافظات، التجار، المندوبين، المحافظ المالية، والدردشة التشغيلية.

## هيكل المشروع

```text
backend/                 Laravel: API، قاعدة البيانات، لوحات الويب التقليدية
frontends/
  app-pwa/               Vue PWA للتاجر والمندوب
  dashboard-pwa/         Vue PWA للإدارة
docs/                    البنية وخطوات النشر
deployment/              ملفات مساعدة للنشر
```

## تشغيل التطوير

```bash
cd backend
composer install
npm install
php artisan migrate --seed
npm run dev
```

كل واجهة PWA مستقلة ويمكن تشغيلها من داخل مجلدها عبر `npm install` ثم `npm run dev`.

راجع `docs/architecture.md` و`docs/deployment.md` قبل النشر.

للنشر التجريبي ببيانات حقيقية منسوخة بشكل آمن، راجع `docs/staging.md`.

## فحص التحديثات

كل رفع إلى GitHub يُفحص آلياً عبر GitHub Actions. محلياً يمكن استخدام:

```bash
make test
make build
```
