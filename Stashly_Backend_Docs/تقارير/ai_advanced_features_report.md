# تقرير ميزات الذكاء الاصطناعي المتقدمة — المرحلة 11 (DEC-039)

> الحالة: **مُنفَّذ ومُختبر بمحاكاة Gemini فقط.** لم يُجرَ أي طلب حقيقي إلى Gemini. الإصدار غير معتمد للإنتاج.
> يكمل هذا التقرير `ai_features_report.md` (المرحلة 10: التلخيص و«اسأل روابطك») ويستخدم نفس الإعدادات: `GEMINI_API_KEY` و`GEMINI_MODEL=gemini-3.5-flash-lite` و`GEMINI_TIMEOUT=20`.

## 1. الميزات الأربع، هدفها، ولماذا تهم

| # | الميزة | الـ Endpoint | الهدف |
|---|---|---|---|
| 1 | النقاط الرئيسية (وضع القراءة) | `POST /api/v1/links/{link}/key-points` | فهم الرابط سريعاً: 3–5 نقاط، اقتباسات، زمن القراءة، مستوى الصعوبة |
| 2 | خطة العمل | `POST /api/v1/links/{link}/action-plan` | تحويل مقال تعليمي/تقني إلى قائمة خطوات تنفيذية |
| 3 | الروابط المتعلقة | `GET /api/v1/links/{link}/related` | اكتشاف روابط محفوظة تتشارك الموضوع مع الرابط الحالي |
| 4 | مقترح المجموعة | `POST /api/v1/ai/suggest-collection` | اقتراح المجموعة الأنسب لرابط على وشك الحفظ |

**قيد مهم يجب أن يعرفه الفريق:** الـ Backend لا يخزّن نص الصفحة ولا يحمّلها؛ كل الميزات تعمل من **العنوان + الملاحظة + وصف الصفحة (metadata)** فقط. لذلك:

- `estimated_read_time_minutes` **تقدير** من هذا النص القصير وليس زمن قراءة فعلي للصفحة.
- `memorable_quotes` لا تُقبل إلا إذا وردت **حرفياً** في النص المُرسل؛ وبما أن النص قصير فستكون القائمة غالباً فارغة. لا يُسمح للنموذج باختلاق اقتباسات.
- جودة النقاط وخطة العمل تعتمد على جودة الوصف المحفوظ.

قواعد مشتركة (كما في المرحلة 10): الحد 10 طلبات AI/دقيقة لكل مستخدم مشتركة بين **كل** endpoints الـ AI الستة؛ المفتاح في الترويسة فقط ولا يُسجَّل؛ لا تُرسَل الروابط الكاملة إلى Google (النطاق فقط)؛ نص الصفحات يُعامَل كبيانات لا كتعليمات؛ كل id يعود من النموذج يُتحقق أنه يخص المستخدم نفسه.

## 2. الطلبات والاستجابات

الترويسات لكل الطلبات:

```
Authorization: Bearer {access_token}
Accept: application/json
Content-Type: application/json
```

### أ) النقاط الرئيسية — `POST /api/v1/links/{link}/key-points[?refresh=true]`

لا يوجد Body. `refresh` اختياري (`true|false|1|0`، غير ذلك 422).

```json
{
  "data": {
    "link_id": "01jc3k9r5e8f1a2d4g6h7j9k0m",
    "insights": {
      "key_takeaways": [
        { "point": "Queues defer slow work", "explanation": "Slow tasks run outside the web request." }
      ],
      "memorable_quotes": ["Queues defer slow work."],
      "estimated_read_time_minutes": 5,
      "difficulty_level": "intermediate"
    }
  }
}
```

- `key_takeaways`: من 1 إلى 5 عناصر (المطلوب من النموذج 3–5، ولا نرفض ردّاً أقل). `memorable_quotes`: 0–3. `estimated_read_time_minutes`: عدد صحيح 1–240. `difficulty_level`: `beginner | intermediate | advanced`.
- **التخزين المؤقت:** أول نتيجة تُخزَّن في `links.ai_insights`، والنداءات التالية تعيدها **بدون** استدعاء Gemini (حتى لو أُزيل المفتاح لاحقاً). `?refresh=true` يعيد الحساب ويستبدل النتيجة؛ وإذا فشلت إعادة الحساب تبقى النتيجة القديمة.
- ⚠ لا تُبطَل النتيجة المخزنة تلقائياً إذا تغيّر عنوان الرابط/ملاحظته/وصفه؛ استخدم `refresh=true` بعد التعديل.

### ب) خطة العمل — `POST /api/v1/links/{link}/action-plan[?refresh=true]`

```json
{
  "data": {
    "link_id": "01jc3k9r5e8f1a2d4g6h7j9k0m",
    "action_plan": {
      "objective": "Run queue workers reliably.",
      "steps": [
        { "step_number": 1, "title": "Install a process monitor", "description": "Keeps the worker alive after crashes.", "action_item": "Install Supervisor on the server" },
        { "step_number": 2, "title": "Start the worker", "description": "Process queued jobs.", "action_item": "Run php artisan queue:work" }
      ]
    }
  }
}
```

- `step_number` يُرقَّم من الخادم (1..n) ولا يُعتمد على ترقيم النموذج؛ حتى 15 خطوة. التخزين المؤقت في `links.ai_action_plan` بنفس قواعد (أ).

### ج) الروابط المتعلقة — `GET /api/v1/links/{link}/related`

```json
{
  "data": {
    "source_link_id": "01jc3k9r5e8f1a2d4g6h7j9k0m",
    "related_links": [
      { "id": "01jc3kq7w8x9y0z1a2b3c4d5e6", "title": "Laravel Horizon", "url": "https://example.com/horizon", "similarity_reason": "Both explain how queue workers are run and monitored." }
    ]
  }
}
```

- يُقارَن الرابط بآخر 30 رابطاً **آخر** للمستخدم (عنوان، ملاحظة، ملخص AI، وسوم). أقصى نتيجة 5.
- معرّفات مخترعة، أو لمستخدم آخر، أو الرابط نفسه، أو مكررة: تُحذف. إن لم يوجد روابط أخرى تعود قائمة فارغة بدون استدعاء Gemini.
- طلب GET لكنه يكلّف استدعاءً خارجياً؛ لا يُخزَّن.

### د) مقترح المجموعة — `POST /api/v1/ai/suggest-collection`

```json
{ "title": "Laravel queue workers", "url": "https://example.com/queues", "description": "How to run queue workers" }
```

- `title` مطلوب (حتى 255)، `url` مطلوب وبصيغة http/https، `description` اختياري (حتى 1000).

```json
{
  "data": {
    "suggested_collection_id": "01jc3km2n3p4q5r6s7t8v9w0x1",
    "suggested_collection_name": "Development",
    "confidence": "high",
    "reason": "It is a programming tutorial about queue workers."
  }
}
```

- بلا مجموعات لدى المستخدم: `suggested_collection_id: null` و`suggested_collection_name: null` و`confidence: "low"` ورسالة اقتراح إنشاء مجموعة، **بدون** استدعاء Gemini (ويعمل حتى لو لم يُضبط المفتاح).
- إذا لم تناسب أي مجموعة، أو أعاد النموذج id ليس من مجموعات المستخدم: `id` و`name` = `null` و`confidence = "low"`.
- يُرسَل إلى Gemini: العنوان والوصف ونطاق الموقع (host) وأسماء مجموعاتك فقط.

### الأخطاء (كل الـ endpoints)

| الحالة | الكود | التصرف في التطبيق |
|---|---|---|
| 401 | `UNAUTHENTICATED` | تسجيل الخروج |
| 404 | `NOT_FOUND` | الرابط غير موجود أو ليس للمستخدم (للثلاثة الأولى) |
| 422 | `VALIDATION_FAILED` | `refresh` غير صالح، أو الرابط بلا عنوان/ملاحظة/وصف بعد (انتظر الـ metadata)، أو جسم suggest-collection غير صالح |
| 429 | `RATE_LIMITED` | انتظر `Retry-After` |
| 503 | `AI_SERVICE_UNAVAILABLE` | لا مفتاح على الخادم أو Gemini غير متاح — أخفِ الميزة أو «حاول لاحقاً» (`GET /config` → `ai.enabled`) |
| 504 | `AI_TIMEOUT` | أكثر من 20 ثانية — زر إعادة المحاولة |
| 502 | `AI_INVALID_RESPONSE` | ردّ غير صالح من النموذج — إعادة المحاولة |

## 3. اقتراحات تجربة المستخدم في الأندرويد

| الشاشة | العنصر | الاستدعاء |
|---|---|---|
| **ورقة الحفظ السريع (Quick-save sheet)** بعد لصق/مشاركة رابط | شريحة «مجموعة مقترحة: Development — ثقة عالية» تُملأ تلقائياً مع زر تغيير | `POST /ai/suggest-collection` بعد جلب المعاينة (`POST /links/preview`)؛ عند `id = null` اعرض «أنشئ مجموعة» أو اتركها «غير مصنّف». لا تُبطئ الحفظ: اعرض الاقتراح عند وصوله وتجاهله عند الخطأ |
| **تبويب «قراءة» في تفاصيل الرابط** | بطاقات النقاط الرئيسية، شارة زمن القراءة والصعوبة، الاقتباسات إن وُجدت | `POST /links/{id}/key-points` عند أول فتح للتبويب (مؤشر تحميل حتى 25 ثانية)، وزر «تحديث» يمرر `?refresh=true` |
| **شاشة «قائمة المهام»** | قائمة خطوات بمربعات اختيار (الحالة تُحفظ محلياً في التطبيق، لا في الخادم) مع الهدف في الأعلى | `POST /links/{id}/action-plan`؛ اعرض الزر فقط للروابط التقنية/التعليمية |
| **تذييل «روابط ذات صلة» في تفاصيل الرابط** | 3–5 بطاقات مع سبب التشابه؛ الضغط يفتح الرابط | `GET /links/{id}/related` عند الوصول إلى أسفل الشاشة (كسول)، لا عند الفتح |

توصيات: مهلة الشبكة ~25 ثانية لهذه النداءات؛ لا تستدعِها تلقائياً في القوائم؛ خزّن النتائج محلياً؛ لا تُسجّل الخروج عند 502/503/504؛ أخفِ كل عناصر الـ AI إذا كانت `ai.enabled = false`.

## 4. Hostinger: بدون Cron وبدون تكلفة

- كل استدعاء Gemini يحدث **داخل طلب HTTP نفسه** ويُرجَع فوراً: لا Cron، لا Queue Worker، لا عمليات خلفية لهذه الميزات.
- النتائج المخزّنة (النقاط وخطة العمل) تقلل الاستدعاءات: الفتح الثاني مجاني تماماً.
- مطلوب فقط: PHP + cURL، اتصال HTTPS خارجي إلى `generativelanguage.googleapis.com`، وحد تنفيذ PHP أكبر من 20 ثانية، وتشغيل `php artisan migrate --force` مرة واحدة لإضافة العمودين `ai_insights` و`ai_action_plan` (ترحيل إضافي لا يحذف شيئاً؛ خذ نسخة احتياطية أولاً).
- التكلفة: الطبقة المجانية من Gemini لا تتطلب دفعاً، لكن لها حصصاً قد تتغير (راجع صفحة الحصص الحالية)؛ عند تجاوزها يعيد الخادم 503 وتبقى بقية التطبيق تعمل. الحد 10 طلبات/دقيقة لكل مستخدم يحمي الحصة.
- **تنبيه صريح:** «بدون Cron» ينطبق على ميزات الـ AI فقط؛ جلب الـ metadata عند حفظ الرابط ما زال يحتاج Queue Worker كما في وثائق النشر السابقة.

## 5. ما لم يُتحقق منه

- لم يُجرَ أي طلب حقيقي إلى Gemini؛ كل الاختبارات (64 اختباراً جديداً + 54 من المرحلة 10) تستخدم `Http::fake()`. اسم النموذج وشكل استجابته مبنيان على ما ذُكر في الطلب وعلى توثيق REST المعروف.
- حصص الطبقة المجانية وشروطها وخصوصية البيانات المرسلة تحتاج مراجعة قبل الإطلاق العام.
- تأكيد فريق الأندرويد على أشكال الاستجابات والأكواد، وقرار إبطال التخزين المؤقت عند تعديل الرابط.
