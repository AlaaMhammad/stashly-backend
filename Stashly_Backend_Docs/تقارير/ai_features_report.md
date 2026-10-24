# تقرير ميزات الذكاء الاصطناعي — المرحلة 10 (DEC-038)

> الحالة: **مُنفَّذ ومُختبر بمحاكاة Gemini فقط** — لم يتم أي اتصال حقيقي بـ Gemini بعد (لا يوجد مفتاح). الإصدار غير معتمد للإنتاج.

## 1. ما تم إضافته والهدف منه

أُضيف إلى الـ Backend ميزتان تعملان **عند الطلب فقط** (عند ضغط المستخدم على زر) باستخدام Google Gemini REST API (الطبقة المجانية):

1. **تلخيص رابط واحد واقتراح وسوم** — `POST /api/v1/links/{link}/summarize`
   الهدف: تلخيص سريع (2–3 نقاط) و3–5 وسوم مقترحة تُضاف تلقائياً إلى الرابط.
2. **اسأل روابطك** — `POST /api/v1/ai/ask`
   الهدف: سؤال بلغة طبيعية يُجاب عنه من آخر 30 رابطاً محفوظاً للمستخدم، مع إرجاع الروابط التي اعتمد عليها الجواب.

تفاصيل التنفيذ:

| العنصر | القيمة |
|---|---|
| المزوّد | Google Gemini REST (`generateContent`) |
| النموذج | `gemini-3.5-flash-lite` (قابل للتغيير من `GEMINI_MODEL`) — اسم النموذج من اختيار المالك ولم يُتحقق من وجوده فعلياً |
| مهلة الطلب | 20 ثانية (`GEMINI_TIMEOUT`) |
| الوضع | متزامن 100% — لا Cron ولا Queue Worker لهذه الميزة |
| قاعدة البيانات | ترحيل إضافي على جدول `links`: `ai_summary` (text) و`ai_tags` (json) و`ai_generated_at` (timestamp)، كلها nullable |
| معدّل الطلبات | 10 طلبات AI في الدقيقة لكل مستخدم (مشتركة بين الـ endpoint-ين) |
| حالة الإعداد | `GET /config` يعيد `data.ai.enabled` (true فقط إذا وُجد المفتاح) |

أمان وخصوصية:

- المفتاح يُرسل في ترويسة `x-goog-api-key` فقط، ولا يُكتب في السجلات ولا في الاستجابات.
- يُرسل إلى Google: العنوان والملاحظة ووصف الصفحة والنطاق (domain) والملخص والوسوم وسؤال المستخدم. **لا يُرسل**: الروابط الكاملة (قد تحوي رموزاً)، البريد، رموز الدخول، بيانات مستخدمين آخرين. أُضيفت جملة بهذا المعنى إلى صفحة الخصوصية (مسودة).
- نص الصفحات المحفوظة غير موثوق (Prompt Injection): يُرسل داخل أقسام `DATA` مع تعليمات للنموذج بعدم اتباعه، ويُتحقق من مخرجات النموذج، ولا يمكن أن تحتوي `referenced_links` إلا على روابط المستخدم نفسه.
- الرابط الذي لا يملكه المستخدم يعيد 404.

## 2. الطلبات والاستجابات

### أ) تلخيص رابط — `POST /api/v1/links/{link}/summarize`

**Headers**

```
Authorization: Bearer {access_token}
Accept: application/json
Content-Type: application/json
```

**Body**: لا يوجد.

**200 OK**

```json
{
  "data": {
    "link": {
      "id": "01jc3k9r5e8f1a2d4g6h7j9k0m",
      "original_url": "https://example.com/post",
      "normalized_url": "https://example.com/post",
      "source_domain": "example.com",
      "title": "Laravel queues",
      "display_title": "Laravel queues",
      "note": "read later",
      "is_favorite": false,
      "content_type": "article",
      "metadata": { "status": "ready", "title": "Laravel queues", "description": "Queue workers explained", "site_name": "Example", "favicon_url": "https://example.com/favicon.ico", "preview_image_url": null, "fetched_at": "2026-10-24T09:30:12Z", "error_code": null },
      "ai": {
        "summary": "- Explains how queue workers pick up jobs\n- Covers retries and failed jobs",
        "tags": ["laravel", "queues", "php"],
        "generated_at": "2026-10-24T09:31:02Z"
      },
      "collections": [],
      "collections_count": 0,
      "tags": ["laravel", "php", "queues"],
      "created_at": "2026-10-24T09:30:12Z",
      "updated_at": "2026-10-24T09:31:02Z"
    }
  }
}
```

ملاحظات:

- الحقل `ai` **جديد وإضافي** في كل كائن link (في `GET /links` و`GET /links/{id}` وغيرها): قيمته `{ "summary": null, "tags": [], "generated_at": null }` قبل التلخيص.
- `ai.tags` هي الاقتراحات؛ تُضاف إلى `tags` فقط ما لم يكن موجوداً أصلاً وبما لا يتجاوز حد 20 وسماً للرابط. الوسوم الموجودة لا تُحذف.
- إعادة الاستدعاء تستبدل الملخص السابق.
- يجب أن يكون للرابط عنوان أو ملاحظة أو وصف (وإلا 422).

**الأخطاء** (غلاف الأخطاء المعتاد `{"error": {code, message, details, request_id}}`):

| الحالة | الكود | المعنى / تصرّف التطبيق |
|---|---|---|
| 401 | `UNAUTHENTICATED` | الرمز منتهٍ — سجّل الخروج |
| 404 | `NOT_FOUND` | الرابط غير موجود أو ليس للمستخدم |
| 422 | `VALIDATION_FAILED` | لا يوجد نص كافٍ لتلخيصه بعد (انتظر اكتمال الـ metadata أو أضف ملاحظة) |
| 429 | `RATE_LIMITED` | أكثر من 10 طلبات AI/دقيقة — استخدم ترويسة `Retry-After` |
| 503 | `AI_SERVICE_UNAVAILABLE` | لا يوجد مفتاح على السيرفر، أو Gemini غير متاح/تجاوز الحصة — أخفِ الزر أو اعرض «حاول لاحقاً» |
| 504 | `AI_TIMEOUT` | لم يردّ Gemini خلال 20 ثانية — زر «إعادة المحاولة» |
| 502 | `AI_INVALID_RESPONSE` | ردّ غير صالح من النموذج — إعادة المحاولة |

### ب) اسأل روابطك — `POST /api/v1/ai/ask`

**Headers**: كما سبق.

**Body**

```json
{ "query": "What have I saved about queues?" }
```

- `query`: مطلوب، نص، من 2 إلى 500 حرف (يُقصّ من الفراغات الجانبية). يقبل العربية.

**200 OK**

```json
{
  "data": {
    "query": "What have I saved about queues?",
    "answer": "You saved \"Laravel queues\", which explains how queue workers pick up jobs and how retries work.",
    "referenced_links": [
      { "id": "01jc3k9r5e8f1a2d4g6h7j9k0m", "title": "Laravel queues", "url": "https://example.com/post" }
    ]
  }
}
```

- `referenced_links`: صفر إلى 10 عناصر، كلها من روابط المستخدم (معرّفات مخترعة أو مكررة تُحذف). `url` هو `original_url`.
- إذا لم يحفظ المستخدم أي رابط يعود جواب بسيط بلا استدعاء Gemini.
- الأخطاء: 401 / 422 (`query` غير صالح) / 429 / 503 / 504 / 502 كما في الجدول أعلاه.

## 3. استخدامها في تطبيق الأندرويد

عند فتح التطبيق: استدعِ `GET /config` واقرأ `data.ai.enabled`. إذا كانت `false` فأخفِ كل عناصر الـ AI.

| الشاشة | العنصر | الاستدعاء | التعامل مع الاستجابة |
|---|---|---|---|
| تفاصيل الرابط | زر «لخّص بالذكاء الاصطناعي» (يظهر إذا `ai.generated_at == null`، ويتحول إلى «إعادة التلخيص») | `POST /links/{id}/summarize` | اعرض `ai.summary` (كل سطر يبدأ بـ `- `) في بطاقة، وحدّث شرائح الوسوم من `tags`، وخزّن الكائن link المُرجَع |
| قوائم الروابط / البحث | مؤشر صغير «ملخّص» إن وُجد `ai.summary` | لا شيء (البيانات تأتي مع `GET /links`) | — |
| الرئيسية أو البحث | حقل «اسأل روابطك» | `POST /ai/ask` | اعرض `answer` في فقاعة، وتحتها بطاقات `referenced_links`؛ الضغط على بطاقة يفتح تفاصيل الرابط بالـ `id` (`GET /links/{id}`) |

توصيات للعميل:

- اجعل مهلة الشبكة لاستدعاءي الـ AI حوالي **25 ثانية** (الباقي 10–15 ثانية كالمعتاد) واعرض مؤشر تحميل ومنع الضغط المتكرر على الزر.
- لا تُسجّل الخروج عند 502/503/504؛ هي أخطاء مؤقتة. عند 429 انتظر `Retry-After`.
- الاستدعاء يستغرق عادةً عدة ثوانٍ، لذلك لا تُجرِه تلقائياً عند فتح الشاشة، بل عند ضغط المستخدم فقط.

## 4. كيف تعمل على Hostinger بدون Cron Jobs

- الاستدعاء إلى Gemini يحدث **داخل طلب HTTP نفسه** الذي يرسله التطبيق، ثم يُحفظ الناتج في قاعدة البيانات ويُرجَع فوراً. لا توجد مهام مجدولة، ولا Queue Worker، ولا عمليات تعمل في الخلفية لهذه الميزة.
- الأمر يحتاج فقط إلى: PHP مع امتداد cURL (موجود في استضافة Hostinger المشتركة)، واتصال HTTPS خارجي إلى `generativelanguage.googleapis.com`، وحد زمن تنفيذ PHP أعلى من 20 ثانية (القيمة الافتراضية على Hostinger عادةً 30 ثانية أو أكثر؛ تحقق منها في إعدادات PHP، وإن كانت أقل فخفّض `GEMINI_TIMEOUT`).
- لا يزيد حملاً على السيرفر: كل طلب يشغل عملية PHP واحدة أثناء الانتظار (حتى 20 ثانية)، ومعدّل الطلبات محدود بـ 10/دقيقة لكل مستخدم.
- **تنبيه صريح:** هذا ينطبق على ميزات الـ AI فقط. جلب الـ metadata عند حفظ الرابط ما زال يستخدم طابور (queue) ويحتاج Worker وجدولة كما في وثائق النشر السابقة؛ لم يتغير ذلك في هذه المرحلة.

## 5. إعداد مفتاح Gemini المجاني

1. افتح Google AI Studio وسجّل الدخول بحساب Google، ثم أنشئ **API key** (الطبقة المجانية لا تتطلب بطاقة، ولها حدود طلبات قد تتغير — راجع صفحة الأسعار/الحصص الحالية).
2. في ملف `.env` على السيرفر (Hostinger: `public_html/../.env` أو مجلد المشروع) أضف:

```
GEMINI_API_KEY=ضع_المفتاح_هنا
GEMINI_MODEL=gemini-3.5-flash-lite
GEMINI_TIMEOUT=20
```

3. أعد تحميل الكاش إن كنت تستخدمه: `php artisan config:clear` (أو `config:cache`).
4. شغّل `php artisan migrate --force` مرة واحدة لإضافة أعمدة `ai_*` (ترحيل إضافي لا يحذف شيئاً) — بعد أخذ نسخة احتياطية.
5. تحقّق: `GET /api/v1/config` يجب أن يعيد `"ai": {"enabled": true}`، و`php artisan stashly:preflight` لا يحذّر من المفتاح.
6. لا تضع المفتاح في الكود أو في Git أو في Postman. إذا كان المفتاح فارغاً، يعيد الـ endpoint-ان 503 برمز `AI_SERVICE_UNAVAILABLE` ورسالة واضحة.

## 6. ما لم يُتحقق منه

- لم يُجرَ أي طلب حقيقي إلى Gemini؛ كل الاختبارات (54 اختباراً) تستخدم `Http::fake()`.
- اسم النموذج `gemini-3.5-flash-lite` مأخوذ من طلب المالك ولم يُتأكد من توفره، وشكل الاستجابة (`candidates[0].content.parts[0].text`) مبني على توثيق REST المعروف.
- حدود وشروط الطبقة المجانية (وخصوصية البيانات المرسلة) تحتاج مراجعة قبل الإطلاق العام، وصفحة الخصوصية ما زالت مسودة.
- تأكيد فريق الأندرويد على الحقل الجديد `ai` والأكواد الجديدة.
