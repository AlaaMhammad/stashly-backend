# تقرير حزمة الإنتاجية والاكتشاف ونقل البيانات (DEC-041)

> الحالة: **مُنفَّذ ومُختبر على SQLite**؛ لم يُجرَّب بملفات مفضّلات حقيقية من Chrome/Firefox/Safari، ولم تُقَس سرعة البحث على MySQL/MariaDB. الإصدار غير معتمد للإنتاج.
> (سمّاها المالك «المرحلة 12»؛ في `plan.md` رقمها 13 لأن المرحلة 12 كانت استعادة كلمة المرور برمز OTP.)

## 1. نظرة عامة والهدف من كل ميزة

| # | الميزة | الـ Endpoints | الفائدة للمستخدم |
|---|---|---|---|
| 1 | استيراد وتصدير المفضّلات | `POST /links/import` ، `GET /links/export` | الانتقال من/إلى المتصفح دون فقدان البيانات، ونسخة احتياطية كاملة |
| 2 | مشاركة المجموعات علناً | `POST /collections/{id}/share` ، `GET /public/collections/{slug}` | مشاركة قائمة قراءة مع الآخرين برابط للقراءة فقط |
| 3 | فحص الروابط الميتة | `POST /links/{id}/check-health` ، `GET /links/broken` | صيانة المكتبة: معرفة الروابط التي لم تعد تعمل |
| 4 | بحث متقدم | `GET /links/search` | تصفية مركّبة وفرز دون تعقيد في التطبيق |

القواعد العامة: كل شيء **متزامن وبلا Cron وبلا Queue** لهذه الميزات (الاستثناء الوحيد: جلب الـ metadata للروابط المستوردة يتم عبر الطابور الحالي كأي رابط محفوظ؛ الاستيراد نفسه لا يحتاجه). الحدود: الاستيراد 3/دقيقة، فحص الرابط 10/دقيقة، التصدير 5 كل 10 دقائق، العرض العام 60/دقيقة لكل IP.

### أين اختلف التنفيذ عن الطلب (مهم)
1. **لا يوجد أرشفة ولا «آخر مشاهدة» في النظام.** لم تُخترع حقول: `is_archived=true` يعيد قائمة فارغة و`false` يعيد كل شيء؛ `sort=last_viewed_at` يعيد 422. («الروابط غير المؤرشفة» في العرض العام = كل الروابط.) إن أردتموهما فهما ميزتان مستقلتان (أعمدة + endpoints).
2. **فحص الرابط لا يستخدم `Http::fake()`/عميل Laravel HTTP** بل ناقل curl الآمن نفسه المستخدم لجلب الـ metadata (حماية SSRF وتثبيت عنوان IP). الاختبارات تحاكي هذا الناقل.
3. **لا يوجد «اسم عام» للمستخدم:** يُعرض `display_name` لصاحب المجموعة؛ المشاركة قرار صريح منه.
4. **`share_url` رابط API يعيد JSON** وليس صفحة ويب للناس؛ صفحة ويب للمشاهدة تحتاج عملاً إضافياً.
5. إضافات صغيرة ضرورية: `offset/next_offset` في الاستيراد (وإلا لا يمكن استيراد ملف أكبر من 100 رابط)، و`skipped_invalid_count` و`total_in_file`، و`direction` في البحث، وحقل `error` في نتيجة الفحص.
6. حقول جديدة في كائنات موجودة: كل رابط فيه `health {is_broken, last_checked_at}`، وكل مجموعة فيها `is_public` و`share_url`.

## 2. الطلبات والاستجابات

الترويسات (ما لم يُذكر خلاف ذلك): `Authorization: Bearer {token}` و`Accept: application/json`.

### أ) الاستيراد — `POST /api/v1/links/import`

`multipart/form-data`: الحقل `file` (مطلوب) و`offset` (اختياري، رقم صحيح ≥ 0).

- الملفات المقبولة: `.html` `.htm` `.csv` `.txt`، بنوع محتوى نصي (`text/html` `text/plain` `text/csv`)، حتى **2 ميغابايت**، بترميز UTF-8 (HTML بترميز Windows-1252 يُحوَّل)، ولا يُقبل أي ملف يحتوي بايتات ثنائية (صور، zip، UTF-16).
- يُحفظ **100 رابط كحد أقصى لكل طلب** متزامناً بنفس مسار الحفظ العادي (تطبيع الرابط، تخطي المكرر لنفس المستخدم، وسوم).

**200**:

```json
{ "data": { "imported_count": 42, "skipped_duplicates_count": 8, "skipped_invalid_count": 1, "collections_created": 3, "total_in_file": 51, "next_offset": null } }
```

إذا كان في الملف أكثر من 100 رابط يعود `next_offset` رقماً (مثل 100)؛ أعد إرسال **نفس الملف** مع `offset=100` وهكذا حتى يصبح `null`. إعادة إرسال ملف سبق استيراده آمنة (كلها تُحسب مكررة).

**422 `VALIDATION_FAILED`**: ملف مفقود/كبير/نوع خاطئ/ثنائي، أو لا يوجد فيه أي مفضّلة (`details.file`)، أو `offset` غير صالح. **429** عند تجاوز 3 طلبات/دقيقة.

قواعد التحويل:

| في الملف | في ستاشلي |
|---|---|
| `<H3>اسم</H3>` ثم `<DL>` | مجموعة (collection) بهذا الاسم تُنشأ عند أول رابط جديد فيها، وتُعاد الموجودة (بدون حساسية حالة). المجلدات المتداخلة تُسطَّح إلى **اسم المجلد الأعمق** |
| رابط خارج أي مجلد | المجموعة الافتراضية **Imported** |
| `<A HREF>` النص `</A>` | عنوان الرابط (يُنظَّف من الوسوم، حتى 300 حرف) |
| `TAGS="a,b"` (Firefox) | وسوم (تُطبَّع، حتى 20) |
| `<DD>نص` بعد الرابط | ملاحظة |
| `javascript:` `file:` `data:` `place:` `ftp:` أو فارغ | يُتخطى ويُحسب في `skipped_invalid_count` |
| `ADD_DATE` | لا يُحفظ (تاريخ الحفظ = وقت الاستيراد) |

CSV: الأعمدة بالاسم في الصف الأول (`url|link|href`، `title|name`، `folder|collection|category`، `tags`، `note|notes|description`) أو بالترتيب `url,title,folder,tags,note` بلا ترويسة؛ الوسوم تُفصل بـ `;` أو `,`.

إذا بلغ عدد المجموعات 100 (الحد الأعلى) تُحفظ بقية الروابط بدون مجموعة بدل الفشل.

### ب) التصدير — `GET /api/v1/links/export?format=json|html`

استجابة **ملف مُرفق** (streamed، `Content-Disposition: attachment`، `nosniff`، `no-store`).

- `format=json` (الافتراضي): نفس وثيقة `GET /me/export` (schema_version 1: user, collections, tags, links) + لكل رابط `ai` (الملخص) و`ai_insights` و`ai_action_plan`. الاسم `stashly-library-YYYYMMDD-HHMMSS.json`.
- `format=html`: ملف Netscape؛ كل مجموعة مجلد، والرابط في مجموعتين يظهر في كل منهما، وغير المصنّف في الجذر. الاسم `stashly-bookmarks-….html`. لا تخرج أي معرّفات أو بيانات AI.
- `format` غير ذلك: 422. الحد 5 كل 10 دقائق.

### ج) البحث المتقدم — `GET /api/v1/links/search`

| المعامل | المعنى |
|---|---|
| `query` | كلمات (كلها يجب أن تظهر) في العنوان، وصف الصفحة، الملاحظة، ملخص AI، الموقع. غير حساس للحالة، ويتجاهل التشكيل العربي |
| `collection_id` | روابط مجموعة محددة |
| `tag` | وسم محدد |
| `is_favorite` | `true/false/1/0` |
| `is_archived` | `false` = الكل، `true` = لا شيء (لا توجد أرشفة) |
| `has_ai_summary` | `true/false` |
| `date_from` / `date_to` | ISO-8601: تاريخ (يوم كامل UTC) أو تاريخ ووقت (مع منطقة زمنية)، شاملان؛ `date_to` قبل `date_from` = 422 |
| `sort` | `created_at` (الافتراضي) أو `title` |
| `direction` | `asc` أو `desc` (الافتراضي desc للتاريخ، asc للعنوان) |
| `limit`, `cursor` | 1–50 ومؤشر الصفحة (صالح فقط مع نفس المرشحات والفرز) |

كل المرشحات تُجمع بـ AND. الاستجابة كقائمة الروابط العادية: `{"data":[link…],"meta":{per_page,has_more,next_cursor,total}}` (`total` في الصفحة الأولى فقط). `GET /links` القديم لم يتغير.

⚠ الأداء: البحث النصي `LIKE '%كلمة%'` على روابط المستخدم وحده مع فهارس `(user_id, created_at)` و`(user_id, is_favorite, created_at)` و`(user_id, sort_title)`؛ **هدف 50ms غير مقاس** على MySQL/MariaDB.

### د) فحص رابط — `POST /api/v1/links/{link}/check-health`

بلا Body. يرسل طلب `HEAD` (و`GET` إن رفض الخادم HEAD بـ 405/501)، مهلة إجمالية 5 ثوانٍ، حتى 3 تحويلات، ولا يقرأ الجسم.

```json
{ "data": { "link_id": "01jc3k9r5e8f1a2d4g6h7j9k0m", "status_code": 404, "response_time_ms": 212, "is_broken": true, "error": "http_404", "last_checked_at": "2026-10-27T09:31:00Z" } }
```

| الحالة | `is_broken` | `error` |
|---|---|---|
| 2xx / 3xx نهائي | false | null |
| 401 / 403 / 429 (الخادم يرد لكنه يحجب) | false | null |
| 404، 410، أي 4xx آخر، 5xx | true | `http_<رمز>` |
| مهلة / لا اتصال / خطأ TLS / أكثر من 3 تحويلات | true | `timeout` / `unreachable` / `tls_error` / `too_many_redirects` |
| عنوان خاص أو منفذ غير مسموح (حماية SSRF) | **بلا تغيير** | `blocked` |

النتيجة تُحفظ في الرابط (`health` في كائن الرابط). 404 لرابط ليس للمستخدم. الحد 10/دقيقة.

⚠ على خادم بلا حزمة شهادات CA ستظهر الروابط HTTPS كـ `tls_error`/معطّلة (تحذير `stashly:preflight` موجود). ظهر هذا فعلاً على جهاز التطوير (Windows) أثناء الفحص اليدوي، وليس خللاً في الكود.

### هـ) الروابط المعطّلة — `GET /api/v1/links/broken?limit=&cursor=`

قائمة الروابط التي وجد آخر فحص أنها معطّلة، الأحدث أولاً، بنفس صيغة قائمة الروابط. الروابط التي لم تُفحص لا تظهر.

### و) المشاركة — `POST /api/v1/collections/{collection}/share`

Body: `{"is_public": true}` (أو `false`).

```json
{ "data": { "is_public": true, "share_slug": "laravel-reading-list-k3x9a2b7qz", "share_url": "https://api.example.com/api/v1/public/collections/laravel-reading-list-k3x9a2b7qz" } }
```

- العنوان = بادئة مقروءة من اسم المجموعة + 10 أحرف عشوائية (غير قابل للتخمين)، يُنشأ مرة واحدة ويبقى؛ إيقاف المشاركة ثم تشغيلها يعيد نفس العنوان. عند الإيقاف: `{"is_public":false,"share_slug":null,"share_url":null}` ويصبح العرض العام 404 فوراً. تصادم العناوين يُعاد توليده تلقائياً.

### ز) العرض العام — `GET /api/v1/public/collections/{slug}?limit=&cursor=` (بدون توكن)

```json
{
  "data": {
    "collection": { "name": "Laravel Reading List", "description": "Good reads", "owner": { "display_name": "Lina" }, "links_count": 1, "updated_at": "2026-10-27T09:30:12Z" },
    "links": [ { "title": "Laravel queues", "url": "https://example.com/post", "description": "A page", "created_at": "2026-10-27T09:30:12Z" } ]
  },
  "meta": { "per_page": 20, "has_more": false, "next_cursor": null, "total": 1 }
}
```

- **لا يظهر أبداً:** الملاحظات، الوسوم، ملخصات/خطط AI، المعرّفات، البريد، أي بيانات حساب. `url` هو الرابط المطبَّع (بدون معاملات التتبع). المجموعة غير موجودة/خاصة/موقوفة المشاركة = نفس الـ 404. الترويسات: `Cache-Control: public, max-age=60` و`X-Robots-Tag: noindex`.

## 3. توافق صيغة Netscape Bookmark

الصيغة التي يصدّرها ويستوردها Chrome وEdge وBrave وFirefox وSafari:

```html
<!DOCTYPE NETSCAPE-Bookmark-file-1>
<META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset=UTF-8">
<TITLE>Bookmarks</TITLE>
<H1>Bookmarks</H1>
<DL><p>
    <DT><H3 ADD_DATE="1790000000">اسم المجموعة</H3>
    <DL><p>
        <DT><A HREF="https://example.com/post" ADD_DATE="1790000000" TAGS="laravel,php">عنوان الرابط</A>
        <DD>ملاحظة اختيارية
    </DL><p>
    <DT><A HREF="https://example.org/">رابط بلا مجموعة</A>
</DL><p>
```

- **القراءة:** المحلل يمسح النص ببساطة (بدون تنفيذ أو جلب أو XML) ويتسامح مع الوسوم الكبيرة/الصغيرة والاقتباس المفرد أو بدونه ووسوم غير مغلقة وصور `ICON="data:…"` الطويلة وترميز Windows-1252 وBOM.
- **الكتابة:** كل القيم تُهرَّب (`&amp; &quot; &lt;`)، والسطور المتعددة في الملاحظة تُدمج في سطر واحد. اختُبر **دورة كاملة** (تصدير ثم استيراد في حساب آخر ← نفس المجموعات والعناوين والوسوم والملاحظات).
- **غير مختبر:** استيراد الملف المصدَّر داخل متصفح حقيقي، وملفات مفضّلات حقيقية ضخمة أو غريبة.

## 4. دليل دمج تطبيق الأندرويد

| الشاشة | العنصر | الاستدعاء |
|---|---|---|
| الإعدادات ← «استيراد المفضّلات» | منتقي ملفات النظام (`ActivityResultContracts.OpenDocument` بأنواع `text/html`, `text/csv`, `text/plain`) ثم شريط تقدم | `POST /links/import` متعدد الأجزاء؛ كرّر بنفس الملف مع `offset=next_offset` حتى `null`؛ اعرض الملخص (مستورد/مكرر/غير صالح/مجموعات جديدة). عند 429 انتظر `Retry-After`. أخبر المستخدم أن العناوين والصور ستكتمل لاحقاً (الـ metadata) |
| الإعدادات ← «تصدير» | زرّان: «نسخة كاملة (JSON)» و«مفضّلات المتصفح (HTML)» | `GET /links/export?format=…` وحفظ الملف بـ `ACTION_CREATE_DOCUMENT` أو مشاركته؛ لا تُعد المحاولة قبل 10 دقائق عند 429 |
| تفاصيل المجموعة | مفتاح «مشاركة عامة» + زر «مشاركة الرابط» (Share Sheet) | `POST /collections/{id}/share`؛ الحالة الأولية من `collection.is_public`/`share_url`؛ حذّر المستخدم أن اسمه المعروض وعناوين الروابط ستكون مرئية لكل من يملك الرابط؛ لاحظ أن الرابط يعيد JSON وليس صفحة |
| شاشة المكتبة | شارة (badge) «روابط معطّلة (N)» تفتح قائمة | `GET /links/broken` (`meta.total` للعدد)؛ على كل رابط يظهر `health.is_broken` كأيقونة تحذير |
| تفاصيل الرابط | زر «افحص الرابط» + «آخر فحص: …» | `POST /links/{id}/check-health` ثم تحديث `health` محلياً؛ مهلة شبكة ~8 ثوانٍ؛ عند `error = "blocked"` اعرض «لا يمكن فحص هذا العنوان» |
| البحث | شاشة مرشحات (مجموعة، وسم، مفضلة، له ملخص AI، من/إلى تاريخ، ترتيب) | `GET /links/search`؛ أرسل فقط المرشحات المفعّلة؛ التواريخ بصيغة `YYYY-MM-DD`؛ لا تُظهر خيار «مؤرشف» أو «آخر مشاهدة» (غير موجودين) |

## 5. الاختبارات

حزمة جديدة: `ImportExportTest` (31) و`PublicCollectionTest` (20) و`DeadLinkCheckTest` (23) و`AdvancedSearchTest` (22) + اختبارات HEAD الحقيقية في `CurlTransportTest` على خادم محلي، و`RouteMatrixTest` على **47** عملية. 7 اختبارات طفرات (إزالة تقييد الاستيراد، تسريب الملاحظات، ملكية الفحص، فحص التكرار، نهاية اليوم، اعتبار المحجوب معطّلاً، فحص البايتات الثنائية) كُشفت كلها. وكوليكشن Postman صار 50 طلباً تغطي 47 عملية (تحقق آلي 47/47).
