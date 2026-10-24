# تقرير استعادة كلمة المرور برمز OTP وقالب البريد — المرحلة 12 (DEC-040)

> الحالة: **مُنفَّذ ومُختبر على SQLite وبناقل بريد تجريبي (array) فقط.** لم يُرسَل أي بريد حقيقي عبر Hostinger، ولم يُعاين القالب في عملاء بريد حقيقيين (Gmail/Outlook/Apple Mail). الإصدار غير معتمد للإنتاج.
> ⚠ **تغيير كاسر لشاشة الاستعادة في الأندرويد** (انظر القسم 2).

## 1. التحول المعماري: من رابط إلى رمز من 6 أرقام + إرسال فوري

### السبب الجذري لعدم وصول البريد
كان إشعار استعادة كلمة المرور يطبّق `ShouldQueue` والإعداد `QUEUE_CONNECTION=database`؛ أي أن البريد يُحفظ في جدول `jobs` ولا يُرسَل إلا إذا كان `queue:work` يعمل باستمرار. على استضافة Hostinger المشتركة لا يوجد Worker، فيبقى البريد عالقاً. (يوجد أيضاً خطأ إعداد شائع: Laravel 12 لا يعرف `MAIL_ENCRYPTION`؛ المنفذ 465 يحتاج `MAIL_SCHEME=smtps`.)

### ما تغيّر
| الجانب | قبل | بعد |
|---|---|---|
| وسيلة الاستعادة | رابط فيه `token` يفتح صفحة ويب | رمز من **6 أرقام** يُدخَل داخل التطبيق |
| الإرسال | طابور (يحتاج Worker) | **فوري** بعد إرسال الاستجابة (`notifyNow` داخل `defer`) — بلا Cron ولا Worker |
| صلاحية الرمز | 60 دقيقة | **15 دقيقة**، استخدام واحد |
| التخزين | جدول `password_reset_tokens` (توكن طويل مجزّأ) | جدول جديد `password_reset_codes`: `email`، `code_hash`، `attempts`، `expires_at`، `created_at` |
| حماية التخمين | — | 5 محاولات خاطئة كحد أقصى لكل رمز ثم يُحذف الرمز؛ حدود طلبات لكل بريد ولكل IP |
| فشل SMTP | خطأ 500 محتمل يكشف وجود الحساب | يُسجَّل في `Log::error` (فئة الاستثناء + تفصيل مُنقَّح بلا عناوين بريد) ويبقى الرد 200 كما هو |

### تفاصيل الأمان
- الرمز من `random_int`، مكوّن من 6 خانات (مع الأصفار البادئة).
- يُخزَّن كـ `HMAC-SHA256` مفتاحه `APP_KEY` (وليس هاش بسيط): 1,000,000 قيمة فقط ممكنة، فالهاش البسيط يُكسر فوراً لو تسرّب الجدول؛ بدون المفتاح لا يمكن كسر HMAC.
- رمز واحد فعّال لكل بريد؛ طلب جديد يستبدل القديم. طلب ثانٍ خلال 60 ثانية لا يرسل بريداً جديداً (والرد متطابق).
- بريد غير موجود / رمز خاطئ / رمز منتهٍ → نفس الخطأ `422 TOKEN_INVALID_OR_EXPIRED` ونفس الرسالة.
- الحدود: طلب الرمز `3/ساعة لكل بريد` و`10/ساعة لكل IP`؛ التحقق وإعادة التعيين `20/ساعة لكل بريد` و`40/ساعة لكل IP`. مع 5 محاولات لكل رمز و3 رموز في الساعة يكون أقصى تخمين نحو 15 محاولة في الساعة من أصل مليون.
- مهلة اتصال SMTP 10 ثوانٍ (`MAIL_TIMEOUT`).
- حدّ معروف: الحساب الموجود يكلّف قاعدة البيانات كتابة إضافية صغيرة فيختلف الزمن قليلاً عن حساب غير موجود (الإرسال نفسه بعد الاستجابة فلا يؤثر).

## 2. الطلبات والاستجابات لتطبيق الأندرويد

الترويسات: `Accept: application/json` و`Content-Type: application/json` (كلها Endpoints عامة بلا Bearer).

### أ) طلب الرمز — `POST /api/v1/auth/forgot-password`

```json
{ "email": "lina@example.com" }
```

**200 دائماً** (حتى لو البريد غير مسجّل أو أُرسل رمز قبل أقل من دقيقة):

```json
{ "data": { "message": "If an account exists for this email, a password reset code has been sent." } }
```

أخطاء: `422 VALIDATION_FAILED` (بريد غير صالح)، `429 RATE_LIMITED` (مع ترويسة `Retry-After`).
واجهة مقترحة: بعد النجاح انتقل مباشرة إلى شاشة إدخال الرمز مع عدّاد 60 ثانية قبل تفعيل «إعادة الإرسال».

### ب) التحقق من الرمز (اختياري) — `POST /api/v1/auth/verify-reset-code`

```json
{ "email": "lina@example.com", "code": "482913" }
```

**200**: `{ "data": { "valid": true } }` — لا يستهلك الرمز، فقط يسمح بإظهار شاشة كلمة المرور الجديدة.

**422**:

```json
{ "error": { "code": "TOKEN_INVALID_OR_EXPIRED", "message": "This code is invalid or has expired.", "details": {}, "request_id": "01..." } }
```

- `code` يجب أن يكون **نصاً من 6 أرقام بالضبط** (تُزال الفراغات)؛ غير ذلك `422 VALIDATION_FAILED` ولا يُحتسب كمحاولة. أرسله كنص `"012345"` وليس رقماً حتى لا يضيع الصفر.
- كل رمز خاطئ يُحتسب؛ بعد **5** يُحذف الرمز ويجب طلب رمز جديد (اعرض «طلب رمز جديد»).

### ج) تعيين كلمة المرور — `POST /api/v1/auth/reset-password`

```json
{ "email": "lina@example.com", "code": "482913", "password": "new-password-123", "password_confirmation": "new-password-123" }
```

**200**: `{ "data": { "message": "Your password has been reset." } }` — وتُلغى **كل** رموز الجلسات للمستخدم؛ سجّل الدخول من جديد.

أخطاء: `422 TOKEN_INVALID_OR_EXPIRED` (رمز خاطئ/مستخدم/منتهٍ/بريد غير موجود)، `422 VALIDATION_FAILED` (كلمة مرور أقل من 8 أو أكثر من 128 أو تأكيد غير مطابق أو `code` ليس 6 أرقام)، `429 RATE_LIMITED`.

⚠ الحقل القديم `token` لم يعد مقبولاً (يعيد 422 لأن `code` مطلوب). كلمة المرور الخاطئة الصيغة لا تستهلك الرمز.

ملاحظة: حسابات Google فقط (بلا كلمة مرور) تستطيع بهذا المسار تعيين كلمة مرور أولى (انظر DEC-036/037).

صفحة الويب `/reset-password` ما زالت موجودة وصارت نموذجاً يطلب البريد والرمز وكلمة المرور الجديدة (للحالات التي لا يتوفر فيها التطبيق)؛ التطبيق لا يحتاجها.

## 3. إعداد SMTP على Hostinger

1. من لوحة Hostinger أنشئ صندوق بريد على نطاقك (مثل `noreply@yourdomain.com`).
2. في `.env` على الخادم:

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=noreply@yourdomain.com
MAIL_PASSWORD=كلمة_مرور_الصندوق
MAIL_TIMEOUT=10
MAIL_FROM_ADDRESS="noreply@yourdomain.com"
MAIL_FROM_NAME="${APP_NAME}"
```

3. ملاحظات: المنفذ 465 مع `MAIL_SCHEME=smtps` (SSL ضمني). بديل: المنفذ 587 مع `MAIL_SCHEME=smtp` (STARTTLS). **لا تستخدم `MAIL_ENCRYPTION`** فهو لا يعمل في Laravel 12. لا تضع `MAIL_MAILER=log` في الإنتاج لأنه يكتب الرمز في ملف السجل.
4. شغّل `php artisan config:clear` ثم `php artisan migrate --force` (ينشئ `password_reset_codes`) بعد نسخة احتياطية.
5. تحقق: `php artisan stashly:preflight` (يحذّر إن كان عنوان المرسل نصاً افتراضياً)، ثم أرسل رمزاً حقيقياً لصندوق بريدك وافحص وصوله (وليس في Spam) وشكل القالب. اضبط سجلات SPF/DKIM للنطاق من Hostinger لتحسين التسليم.
6. إن فشل الإرسال تجد في `storage/logs/laravel.log` السطر `Password reset email could not be sent.` مع فئة الاستثناء (مثل `TransportException`) وتفصيل مُنقَّح؛ المستخدم يرى 200 ويمكنه الطلب من جديد فوراً.
7. لا حاجة لـ Cron ولا لـ `queue:work` لبريد الاستعادة. (جلب الـ metadata عند حفظ الروابط ما زال يحتاج Worker كما سبق.)

## 4. قالب البريد HTML

الملف: `resources/views/emails/auth/password-reset-otp.blade.php` (النسخة النصية: `password-reset-otp-text.blade.php`). يُربط عبر `PasswordResetCodeNotification::toMail()` بـ `->view(['emails.auth.password-reset-otp', 'emails.auth.password-reset-otp-text'])`.

مكوّنات القالب (جداول `role="presentation"` وأنماط inline لتوافق عملاء البريد، وعرض أقصى 600px يتحول لعرض كامل على الجوال):

1. **Preheader** مخفي: «Your Stashly password reset code is 482913. It expires in 15 minutes.»
2. **ترويسة** بخلفية نيلية `#4f46e5` واسم التطبيق بخط أبيض عريض.
3. **ترحيب** باسم المستخدم (يُهرَّب HTML) وجملة «Enter this code in the app...».
4. **صندوق الرمز** في المنتصف: `font-size: 34px; letter-spacing: 8px; font-weight: 800; color: #4f46e5; font-family: monospace; background: #f1f5f9;` داخل خلية بحدود فاتحة وزوايا مدورة.
5. **ملاحظة الصلاحية**: «This code expires in **15 minutes** and can be used once.»
6. **تنبيه أمني** في صندوق أحمر خفيف (`#fef2f2` / حد `#fecaca` / نص `#b91c1c`): «If you didn't request this, ignore this email. Your password will not change. Never share this code with anyone.»
7. **تذييل** رمادي: رسالة آلية، لا ترد.

لا توجد روابط في البريد (لا حاجة للنقر)، والموضوع: «482913 is your Stashly password reset code».

معاينة نصية مبسطة:

```
┌────────────────────────────────────────┐
│             [ Stashly ]  (indigo)      │
├────────────────────────────────────────┤
│  Reset your password                   │
│  Hi Lina,                              │
│  Enter this code in the Stashly app... │
│          ┌───────────────┐             │
│          │  4 8 2 9 1 3  │  (box)      │
│          └───────────────┘             │
│  This code expires in 15 minutes...    │
│  ┌──────────────────────────────────┐  │
│  │ Didn't request this? ... ignore  │  │ (red)
│  └──────────────────────────────────┘  │
│  Automated message. Please don't reply │
└────────────────────────────────────────┘
```

## 5. ما اختُبر وما لم يُختبر

- 31 اختباراً في `PasswordResetTest` (توليد الرمز وشكله وتخزينه، ربط القالب وعرض الـ HTML الفعلي بالأنماط المطلوبة، الإرسال بلا طابور، الحدود، رمز خاطئ/منتهٍ/مستخدم، حد 5 محاولات، فشل ناقل البريد وسلامة السجلات، صفحة الويب)، و`RouteMatrixTest` على 40 عملية، وحُدّثت اختبارات Google/Export/Observability التي تستخدم الاستعادة. 6 اختبارات طفرات (إزالة حد المحاولات، الانتهاء، الاستهلاك، HMAC، فترة الانتظار) كُشفت كلها.
- فحص يدوي كامل (طلب ← تحقق ← إعادة تعيين ← دخول بالكلمة الجديدة ← رفض القديمة ← رفض إعادة استعمال الرمز) على خادم محلي مؤقت بناقل `log`.
- **لم يُختبر:** إرسال حقيقي عبر Hostinger، شكل القالب في Gmail/Outlook/Apple Mail (خصوصاً الوضع الداكن)، تأكيد فريق الأندرويد على التغيير الكاسر، وتشغيل MySQL.
