# ES Attendance Server

سيرفر حضور وانصراف يستقبل البصمات مباشرة من جهاز **ZKTeco uFace 800** عبر خاصية Cloud Server (ADMS)، ويحفظها في PostgreSQL، ويعرضها في لوحة ويب بالعربي.

## التشغيل على Railway

1. في المشروع على Railway: **New ← GitHub Repository** واختر هذا المستودع.
2. إذا ما عندك قاعدة بيانات: **New ← Database ← PostgreSQL**.
3. في خدمة السيرفر ← **Variables** أضف:

   | المتغير | القيمة |
   |---|---|
   | `DATABASE_URL` | `${{Postgres.DATABASE_URL}}` |
   | `ADMIN_PASSWORD` | كلمة مرور اللوحة |
   | `SESSION_SECRET` | نص عشوائي طويل |
   | `WORK_START` | بداية الدوام، مثلاً `08:00` (اختياري) |

4. **Settings ← Networking**:
   - **Generate Domain**: هذا رابط اللوحة اللي تفتحه من المتصفح.
   - **TCP Proxy** على المنفذ `8080` (أو قيمة `PORT`): هذا العنوان اللي تكتبه في الجهاز.

## إعداد الجهاز

**Menu ← COMM ← Cloud Server Setting**

- Enable Domain Name: **ON**
- Server Address: عنوان الـ TCP Proxy بدون المنفذ (مثل `xxx.proxy.rlwy.net`)
- Server Port: منفذ الـ TCP Proxy (مثل `12345`)
- HTTPS: **OFF**
- Proxy Server: **OFF**

بعد الحفظ أعد تشغيل الجهاز. خلال دقيقة يظهر في تبويب **الأجهزة** في اللوحة.

## ملاحظات

- أسماء الموظفين: اضغط "سحب أسماء الموظفين من الجهاز" في تبويب الأجهزة، أو اكتبها يدوياً في تبويب الموظفين.
- البصمات القديمة المخزنة في الجهاز: اضغط "إعادة سحب كل البصمات". البصمات المكررة تُتجاهل تلقائياً.
