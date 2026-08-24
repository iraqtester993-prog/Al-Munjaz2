# نشر بنية المنجز الجديدة

## 1. API

ينشر Laravel في خادم لا تكون واجهته العامة إلا مجلد `public`، ويربط النطاق `api.our-qiq.com` بهذا المجلد. في ملف البيئة على الخادم:

```dotenv
APP_URL=https://api.our-qiq.com
SANCTUM_STATEFUL_DOMAINS=app.our-qiq.com,dashboard.our-qiq.com
```

ثم تنفذ على الخادم بعد `composer install --no-dev --optimize-autoloader`:

```bash
php artisan migrate --force
php artisan optimize
```

## 2. تطبيق PWA

في `frontends/app-pwa`، أنشئ `.env.production` يحتوي:

```dotenv
VITE_API_URL=https://api.our-qiq.com/api/v1
```

شغّل `npm ci && npm run build`، ثم ارفع محتويات `dist` فقط إلى document root الخاص بـ `app.our-qiq.com`.

## 3. لوحة التحكم

كرر نفس الخطوة من `frontends/dashboard-pwa` وارفع محتويات `dist` إلى document root الخاص بـ `dashboard.our-qiq.com`.

لا ترفع `.env` أو `node_modules` أو كامل كود Laravel داخل `public_html`.
