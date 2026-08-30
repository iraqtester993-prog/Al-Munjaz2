# تقديم أصول Vite مباشرة من cPanel

## الهدف

إذا كان `mobile.our-qiq.com` و`admin.our-qiq.com` يستخدمان ملف
`deployment/cpanel-root-index-live.php` داخل `public_html`، فإن طلبات
`/build/assets/*` تمر عبر PHP قبل أن تصل إلى JavaScript أو CSS. هذا حل صحيح
للـ MIME و404، لكنه ليس خادماً ثابتاً حقيقياً ولا يدعم Range/304 بكفاءة خادم
الويب. الخطوات التالية تجعل **الأصول ذات الاسم المحتوي على hash فقط** تُخدم
مباشرة من Apache/Nginx، بينما يبقى Laravel مسؤولاً عن الصفحات وواجهة API
و`/pwa/manifest` و`/pwa/worker`.

لا تنفذ هذه الخطوات قبل وجود إصدار سليم في
`/home/ourqiq/releases/current/backend/public`، ولا تنفذها على مسار مختلف عن
المسارات التي تم التحقق منها أدناه.

## 1. فحص آمن قبل التغيير

نفّذ في cPanel Terminal، ثم راجع الناتج. لا تعرض ملف `.env` أو أي كلمة مرور:

```bash
release_public=/home/ourqiq/releases/current/backend/public

test -f "$release_public/index.php" \
  && test -f "$release_public/build/manifest.json" \
  && test -d "$release_public/assets" \
  || { echo 'Release public directory is not ready'; exit 1; }

for root in /home/ourqiq/public_html/mobile /home/ourqiq/public_html/admin; do
  echo "--- $root ---"
  realpath "$root"
  test -f "$root/index.php" || { echo 'Missing fallback index.php'; exit 1; }
  for name in build assets; do
    if [ -e "$root/$name" ] || [ -L "$root/$name" ]; then
      printf '%s: ' "$name"
      readlink -f "$root/$name" || true
    else
      echo "$name: absent (safe to create)"
    fi
  done
done
```

إذا ظهر أن `build` أو `assets` يشير إلى إصدار قديم أو يحتوي ملفات يدوية لازمة،
توقف هنا وخذ نسخة احتياطية باسمه أولاً. لا تستخدم `rm -rf` ولا تنسخ محتوى
`build` يدوياً؛ الأسماء المحززة في `manifest.json` يجب أن تبقى من الإصدار نفسه.

## 2. إنشاء روابط ثابتة قابلة للعكس

بعد الفحص فقط، أنشئ رابطين لكل جذر نطاق. الأوامر التالية **ترفض الاستبدال** إذا
كان الاسم موجوداً، لذلك لا تمسح أي ملف دون مراجعته:

```bash
release_public=/home/ourqiq/releases/current/backend/public

for root in /home/ourqiq/public_html/mobile /home/ourqiq/public_html/admin; do
  test ! -e "$root/build" && test ! -L "$root/build" \
    || { echo "Refusing to replace $root/build"; exit 1; }
  test ! -e "$root/assets" && test ! -L "$root/assets" \
    || { echo "Refusing to replace $root/assets"; exit 1; }

  ln -s "$release_public/build" "$root/build"
  ln -s "$release_public/assets" "$root/assets"
done
```

هذه الروابط تشير إلى `releases/current`، لذا لا تحتاج إلى تغييرها عند كل نشر
لاحق. عند نشر إصدار جديد يجب تبديل رابط `current` فقط بالطريقة الذرية المعتمدة
للنظام.

إذا كان `build` أو `assets` موجودين بالفعل بعد أن تحققت من محتواهما، استخدم
نقلاً قابلاً للاستعادة مثل الآتي **لكل اسم ومسار راجعته بنفسك**، ثم أعد أمر
`ln -s` السابق:

```bash
stamp=$(date +%Y%m%d-%H%M%S)
mv /home/ourqiq/public_html/mobile/build "/home/ourqiq/public_html/mobile/build.before-direct-static-$stamp"
```

لا تنقل أو تحذف `index.php` أو `.htaccess` أو `manifest.json` أو `sw.js` من
جذر النطاق. يبقيان ديناميكيين لكي تتلقى نسخ PWA المثبتة تحديث العامل والـ manifest.

## 3. ترويسات التخزين المؤقت

يحتوي `backend/public/.htaccess` في هذا الإصدار على ترويسة سنة واحدة
`immutable` لمسار `/build/assets/` وترويسة ساعة واحدة لمسار `/assets/` عند
توافر `mod_headers`. انسخ هذا الملف بجانب `index.php` في جذري النطاقين إذا لم
يكن موجوداً بالفعل، مع الإبقاء على قواعد إعادة التوجيه الخاصة به.

إذا كان مزود الاستضافة يمنع الروابط الرمزية خارج `public_html` أو يعيد كتابة
كل الملفات إلى PHP، اطلب منه أحد الخيارين بدلاً من نسخ الملفات يدوياً:

1. جعل Document Root للنطاقين هو
   `/home/ourqiq/releases/current/backend/public`.
2. إضافة Alias/Location مباشر لمساري `/build/` و`/assets/` إلى ذلك المجلد مع
   إبقاء بقية الطلبات في Laravel.

## 4. تحقق بعد التغيير

استخرج ملف الدخول الحالي من Vite manifest ثم افحصه. يجب أن تكون الاستجابة
`200` للطلب العادي و`206` لطلب Range، مع `Cache-Control` يتضمن `immutable`:

```bash
release_public=/home/ourqiq/releases/current/backend/public
entry=$(php -r '$m=json_decode(file_get_contents($argv[1]), true); echo $m["resources/js/app.js"]["file"];' "$release_public/build/manifest.json")

curl -sS -D - -o /dev/null "https://mobile.our-qiq.com/build/$entry" | grep -Ei 'HTTP/|content-type|cache-control|content-length|accept-ranges'
curl -sS -D - -o /dev/null -H 'Range: bytes=0-1' "https://mobile.our-qiq.com/build/$entry" | grep -Ei 'HTTP/|content-range|accept-ranges|cache-control'
curl -sS -D - -o /dev/null "https://admin.our-qiq.com/build/$entry" | grep -Ei 'HTTP/|content-type|cache-control|content-length|accept-ranges'
curl -sS -D - -o /dev/null "https://mobile.our-qiq.com/pwa/worker" | grep -Ei 'HTTP/|content-type|cache-control'
curl -sS -D - -o /dev/null "https://mobile.our-qiq.com/pwa/manifest" | grep -Ei 'HTTP/|content-type|cache-control'
```

توقعات التحقق:

- `build/assets` لا يمر عبر PHP، ويقبل Range/إعادة التحقق من خادم الويب.
- `/pwa/worker` و`/pwa/manifest` يبقيان `no-store` وديناميكيين.
- التطبيق ولوحة الإدارة يحمّلان الملف المحزز نفسه من `current` من دون خطأ MIME أو 404.

إذا لم يتحقق شرط `206` أو ظل الخادم يرسل `text/html` لملف JavaScript، أزل فقط
الرابطين اللذين أنشأتهما بعد التحقق من وجهتهما (`unlink` للرابط الرمزي فقط)، ثم
عد إلى fallback الحالي واطلب من الاستضافة إعداد Document Root/alias. لا تلمس
الإصدار أو قاعدة البيانات عند استكشاف مشكلة في تقديم الملفات الثابتة.
