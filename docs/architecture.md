# بنية المنجز السريع

## النطاقات

- `app.our-qiq.com`: Vue PWA للتاجر والمندوب.
- `dashboard.our-qiq.com`: Vue PWA للإدارة.
- `api.our-qiq.com/v1`: Laravel JSON API.

## قواعد الفصل

الـAPI لا يعرض واجهات المستخدم. لكل واجهة مشروع Vue مستقل، وتشارك الواجهتان حزمة مكونات اختيارية فقط. المصادقة تتم عبر Laravel Sanctum tokens ولا تُخزن كلمات المرور في الواجهة.

## المجلدات

- `backend/`: مشروع Laravel بالكامل، بما فيه الـAPI وmigrations وملفات بيئة الخادم.
- `frontends/app-pwa/`: تطبيق التاجر والمندوب.
- `frontends/dashboard-pwa/`: لوحة الإدارة.
- `docs/`: معمارية ونشر المشروع.

## المحافظات

تُخزن محافظات العراق في `provinces`. يختار الحساب محافظة أساسية عند التسجيل، ويمكن ربطه بأكثر من محافظة عبر `province_user`. كل طلب يحمل `province_id`، ولا يسمح الداشبورد بتعيين مندوب خارج نطاق محافظة الطلب.

## دورة الطلب

`pending` → `approved` → `courier` → `delivered` أو `returned`.

كل تغيير يسجل في `order_status_logs`، وتبقى الحركات المالية في `transactions` منفصلة عن رصيد المحفظة.

## أول API

- `POST /api/v1/auth/login`
- `GET /api/v1/me`
- `POST /api/v1/auth/logout`
- `GET /api/v1/dashboard`
- `GET|POST /api/v1/orders`
- `GET /api/v1/orders/{order}`
- `PATCH /api/v1/orders/{order}/status`
