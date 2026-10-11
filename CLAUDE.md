# ES Attendance Server

## قواعد ثابتة
- لا تستخدم PowerShell أبداً، لا في الأوامر ولا في الشرح ولا في السكربتات.
- التواصل مع المستخدم بالعربي.

## عن المشروع
سيرفر Node.js يعمل على Railway ويستقبل البصمات من جهاز ZKTeco uFace 800
عبر بروتوكول ADMS (iclock push)، ويحفظها في PostgreSQL، ويعرض لوحة حضور بالإنجليزي (LTR).

- `server.js`: نقاط ADMS (`/iclock/*`) + API اللوحة + تسجيل الدخول
- `db.js`: الاتصال بقاعدة البيانات وإنشاء الجداول
- `public/`: واجهة اللوحة (إنجليزي، LTR)

متغيرات البيئة: `DATABASE_URL`, `ADMIN_PASSWORD`, `SESSION_SECRET`, `PORT`.
