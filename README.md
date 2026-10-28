# Labour App — ระบบบริหารข้อมูลแรงงานต่างด้าว (The Importer 168)

Laravel 8 · PHP 8.x · MySQL · Bootstrap 5.3 (local copy in `public/vendor/bootstrap`)

## การติดตั้ง / Deploy

1. Document root ของเว็บเซิร์ฟเวอร์ควรชี้ที่โฟลเดอร์ `public/`
   (ถ้าชี้ที่โฟลเดอร์โปรเจกต์ ไฟล์ `.htaccess` ที่ root จะส่งทุก request ไปที่ `public/` ให้อัตโนมัติ)
2. หลังอัปโหลดโค้ดใหม่ให้รัน
   ```bash
   composer install --no-dev --optimize-autoloader   # หรือ composer dump-autoload (มี helpers ใน app/Support/helpers.php)
   php artisan storage:link                          # ลิงก์ public/storage -> storage/app/public (ไฟล์เอกสารแรงงาน)
   php artisan view:clear && php artisan config:clear && php artisan route:clear
   ```
3. ตั้งค่า `.env` (`APP_URL`, `DB_*`) และตั้ง `APP_DEBUG=false` บน production

## สิทธิ์การใช้งาน (กำหนดใน `routes/web.php`)

| สิทธิ์ | ทำอะไรได้ |
|---|---|
| Member | ดูข้อมูลทุกหน้า, ดาวน์โหลดรายงาน/Excel |
| Admin | Member + เพิ่ม/แก้ไขแรงงาน บริษัท เอเจนซี่, นำเข้า Excel, Mass update, ตั้งค่าวันแจ้งเตือน |
| MasterAdmin | Admin + ลบข้อมูล/ไฟล์, จัดการสมาชิก, ดูประวัติการแก้ไข, ดูประวัติการใช้ผู้ช่วย AI |

## ผู้ช่วย AI และประวัติการใช้งาน

- หน้าแชท `/ai-chat` (ทุกสิทธิ์) ใช้ OpenAI ผ่าน `app/Services/Ai/LabourAssistant.php` + `LabourQueryService.php` (อ่านข้อมูลอย่างเดียว รวมรายการไฟล์เอกสารพร้อมลิงก์เปิด)
- AI อ่านเนื้อหาในไฟล์เอกสารได้ (เครื่องมือ `read_labour_document` → `app/Services/Ai/DocumentReader.php`): ส่ง PDF/รูปจาก disk เอกสาร (public หรือ R2, ไม่เกิน 15 MB) อ่านทีละหน้าด้วย Google Cloud Vision OCR (`app/Services/GoogleVisionOcr.php`, key `GOOGLE_VISION_API_KEY` หรือใช้ `GOOGLE_TRANSLATE_API_KEY` แทนถ้าว่าง ต้องเปิด "Cloud Vision API" ในโปรเจค Google และ key ต้องไม่ถูกจำกัดเฉพาะ Translation) แล้วให้ OpenAI จัดระเบียบเป็นฟิลด์สำคัญ + สรุปรายหน้า ถ้า Vision ใช้ไม่ได้/ไม่พบข้อความ จะส่งไฟล์ทั้งไฟล์ให้โมเดล vision ของ OpenAI แทน (นับหน้า PDF เองด้วย `app/Support/PdfInfo.php`) cache ผล 7 วันต่อไฟล์ ใช้เมื่อถามว่า "ดึงเนื้อหา/อ่าน/ตรวจสอบ/เทียบ" ไฟล์
- แนบไฟล์มากับคำถามได้ (ปุ่มคลิปหรือลากวาง PDF/JPG/PNG/WEBP/Excel/CSV ≤ 15 MB): ไฟล์เก็บที่ `storage/app/ai-uploads/<user>/` 24 ชม. เอกสารอ่านด้วย OCR ตัวเดียวกัน ตาราง Excel/CSV แปลงเป็นข้อความแถวต่อแถวด้วย `app/Services/Ai/SpreadsheetReader.php` (วันที่ใน Excel ออกมาเป็น ปี-เดือน-วัน สูงสุด 200 แถว) แล้วแนบไปกับคำถาม เช่น "อัปเดตวีซ่าตามไฟล์นี้" AI จะเสนอแก้ทุกแถวใน propose_update ครั้งเดียว (`items`) และยืนยันครั้งเดียว
- **แก้ไขข้อมูลผ่าน AI (MasterAdmin เท่านั้น)** `app/Services/Ai/DataUpdateService.php` ทำงาน 2 ขั้น: `propose_update` ตรวจสอบค่า (ฟิลด์ที่อนุญาตเท่านั้น วันที่ไทย/สากล ชื่อบริษัท/เอเจนซี่/สัญชาติแปลงเป็นรหัสให้) แล้วคืนตารางค่าเดิม→ค่าใหม่ เก็บไว้ 10 นาที → ผู้ใช้พิมพ์ "ยืนยัน" → `apply_update` บันทึกค่าที่เก็บไว้ (ไม่ใช่ค่าที่โมเดลส่งมาตอนนั้น) ผ่าน Eloquent จึงมีประวัติใน action_logs และ `updated_by` ลงท้าย "(AI)" ไม่มีการลบรายการ ผู้ใช้สิทธิ์อื่นจะไม่เห็นเครื่องมือนี้และถูกปฏิเสธถ้าเรียก
- ทุกคำถาม-คำตอบถูกบันทึกลงตาราง `ai_chat_logs` (ผู้ถาม, รหัสบทสนทนา, คำถาม, คำตอบ, เครื่องมือที่ AI เรียก, token, เวลาที่ใช้, สถานะ/ข้อผิดพลาด, IP)
  ตัว log ไม่เก็บข้อมูลแรงงานที่ AI ดึงไปดู เก็บเฉพาะชื่อเครื่องมือ
- MasterAdmin ดูได้ที่เมนู "ประวัติการใช้ AI" (`/ai-chat/logs`): ค้นหาคำถาม/คำตอบ, กรองตามผู้ใช้ เครื่องมือ สถานะ ช่วงวันที่,
  กด "บทสนทนา" เพื่อดูทั้งบทสนทนาเรียงตามลำดับ, สรุปผู้ที่ถามมากที่สุด 30 วัน
- ต้องรัน `php artisan migrate` หนึ่งครั้งเพื่อสร้างตาราง `ai_chat_logs` (ถ้าตารางยังไม่มี การบันทึกจะถูกข้ามและเขียน warning ลง log แต่แชทยังใช้ได้)

## ชื่อ/ที่อยู่บริษัทภาษาอังกฤษ (สำหรับ Worker App)

- ตาราง `company` มี `company_name_en` และ `company_address_en` (migration `2026_10_28_000004`) แก้ได้ในฟอร์มบริษัท
- เว้นว่างไว้ ระบบจะเรียก Google Translate ตอนบันทึก (`app/Services/GoogleTranslate.php`, key `GOOGLE_TRANSLATE_API_KEY` ใน `.env`) ถ้า Google ล้มเหลวก็ยังบันทึกได้ แค่ช่องอังกฤษว่าง
- เติมให้บริษัทเก่าทั้งหมดครั้งเดียวด้วย `php artisan company:translate` (`--force` = แปลทับทั้งหมด)
- Worker App แสดง "Company Name" เป็นภาษาอังกฤษก่อน ไทยตัวเล็กด้านล่าง และที่อยู่บรรทัดแรกใช้ `company_address_en`

## รูปแรงงาน (labour photo)

- ฟอร์มเพิ่ม/แก้ไขแรงงาน ส่วน "ข้อมูลส่วนตัว" มีช่อง "รูปแรงงาน" เลือกรูปแล้วเห็นตัวอย่างทันที กด "ลบรูปเดิม" เพื่อเอาออก
- รูปถูกย่อ/ตัดเป็นสี่เหลี่ยม 512×512 JPEG (`app/Services/LabourPhotoService.php` ใช้ `app/Support/SquareImage.php`) เก็บบน disk เดียวกับเอกสาร (`LABOUR_DOCS_DISK`: public หรือ r2) ที่ `photos/<labour_id>_<rand>.jpg` คอลัมน์ `labour.labour_photo` + `labour_photo_disk` (migration `2026_10_28_000003`)
- แสดงที่หน้ารายละเอียด รายการแรงงาน และส่งให้ Worker App ผ่าน `profile.photo_url` (signed URL 15 นาที key `photo`) เปิดผ่าน `/labour/{id}/photo` (ต้องล็อกอิน; บน R2 จะ redirect ไป signed URL)
- ลบแรงงานแล้วไฟล์รูปถูกลบตาม

## โปรไฟล์ผู้ใช้ / รูปโปรไฟล์

- ทุกสิทธิ์แก้ชื่อ รหัสผ่าน (ต้องใส่รหัสผ่านปัจจุบัน) และอัปโหลดรูปโปรไฟล์ได้ที่ `/profile` (เมนูมุมขวาบน หรือกดรูปใน sidebar)
- รูปถูกย่อ/ตัดเป็นสี่เหลี่ยม 256×256 JPEG ด้วย GD (`app/Services/AvatarService.php`) เก็บที่ `public/uploads/avatars/` (โฟลเดอร์ต้องเขียนได้ ไม่ต้องใช้ `storage:link`) ชื่อไฟล์อยู่ในคอลัมน์ `users.avatar` (migration `2026_10_28_000002`)
- แสดงผลผ่าน component `<x-avatar :user="$u" :size="32" />` (sidebar, topbar, หน้าสมาชิก, ห้องแชท AI) ถ้าไม่มีรูปจะแสดงตัวอักษรย่อเหมือนเดิม

## โครงสร้างที่ควรรู้

- `app/Services/ExpiryService.php` — ตรรกะ "เอกสารใกล้หมดอายุ" ที่ใช้ร่วมกันทั้ง dashboard, badge ใน sidebar และ Excel export
- `app/Support/helpers.php` — ฟังก์ชันช่วย เช่น `fmt_date()`, `days_left()`, `status_job_label()`, `user_is()`
- `resources/views/layouts/main_layout.blade.php` — โครงหน้าหลัก (sidebar/topbar), `layouts/app.blade.php` — หน้า login
- `resources/views/components/` — Blade components (`x-form.input`, `x-form.select`, `x-days-left`, `x-status-badge`, ...)
- `public/css/labour.css`, `public/js/labour.js` — ธีมและ behaviour ของระบบ (รองรับโหมดมืด)
- `tests/Feature/PagesRenderTest.php` — smoke test เปิดทุกหน้า (อ่านจากฐานข้อมูลจริงที่ตั้งใน `.env`, ไม่มีการเขียน)

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400"></a></p>

<p align="center">
<a href="https://travis-ci.org/laravel/framework"><img src="https://travis-ci.org/laravel/framework.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains over 1500 video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the Laravel [Patreon page](https://patreon.com/taylorotwell).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Cubet Techno Labs](https://cubettech.com)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[Many](https://www.many.co.uk)**
- **[Webdock, Fast VPS Hosting](https://www.webdock.io/en)**
- **[DevSquad](https://devsquad.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[OP.GG](https://op.gg)**
- **[WebReinvent](https://webreinvent.com/?utm_source=laravel&utm_medium=github&utm_campaign=patreon-sponsors)**
- **[Lendio](https://lendio.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## ไฟล์เอกสารแรงงาน และ Cloudflare R2

เอกสารของแรงงานเก็บในตาราง `labour_documents` (เพิ่มได้ไม่จำกัด ตั้งชื่อเอกสารเองได้) แต่ละแถวจำ `disk` ที่เก็บไฟล์ไว้
จึงมีไฟล์อยู่บนเครื่อง (`public`) และบน R2 พร้อมกันได้ ไฟล์เดิมในคอลัมน์ `labour_file_*` ยังแสดงผลตามปกติแม้ยังไม่ migrate

**ขั้นตอนย้ายไป R2 (ไม่กระทบไฟล์เดิม)**

1. รัน `php artisan migrate` (สร้างตาราง `labour_documents`)
2. สร้าง bucket + API token บน Cloudflare R2 แล้วตั้งค่าใน `.env`
   ```
   R2_ACCESS_KEY_ID=...
   R2_SECRET_ACCESS_KEY=...
   R2_BUCKET=labour-documents
   R2_ENDPOINT=https://<ACCOUNT_ID>.r2.cloudflarestorage.com
   ```
3. ทดลองก่อน: `php artisan labour:migrate-documents --to=r2 --dry-run`
4. คัดลอกจริง: `php artisan labour:migrate-documents --to=r2`
   (ลงทะเบียนไฟล์เดิมลงตาราง แล้วคัดลอกขึ้น R2 ทีละไฟล์ ตรวจขนาดไฟล์ก่อนสลับ disk ไฟล์ต้นทางไม่ถูกลบ รันซ้ำได้)
5. ตั้ง `LABOUR_DOCS_DISK=r2` ใน `.env` เพื่อให้ไฟล์ใหม่ขึ้น R2
6. (เมื่อมั่นใจแล้ว) `php artisan labour:migrate-documents --to=r2 --delete-local` เพื่อลบสำเนาบนเครื่อง

ไฟล์บน R2 เปิดผ่าน signed URL อายุ 15 นาที (bucket เป็น private) ส่วนไฟล์บนเครื่องส่งผ่านแอปจึงต้องล็อกอินก่อนเสมอ

## API สำหรับ Worker Portal (labour.importer168.com)

โปรเจค `labour_portal` (แยกต่างหาก) ให้แรงงานล็อกอินด้วย **เลขที่ Passport + วันเกิด (ddmmyyyy)** เพื่อดูข้อมูลและไฟล์ของตนเอง
โดยดึงข้อมูลจากระบบนี้ผ่าน `routes/api.php` (`/api/portal/*`) ซึ่งป้องกันด้วย header `X-Portal-Key`

1. ตั้งค่า `PORTAL_API_KEY=<คีย์ยาวๆ>` ใน `.env` ของทั้งสองระบบ (ค่าเดียวกัน) แล้ว `php artisan config:clear`
2. ไม่มี migration เพิ่ม — ใช้ตาราง `labour`, `labour_documents`, `company`, `settings` เดิม
3. ไฟล์เอกสารถูกเปิดผ่านลิงก์ signed อายุ 15 นาที (`/portal-file/{labour}/{key}`) ไม่ต้องล็อกอิน admin

โค้ดอยู่ที่ `app/Http/Controllers/api/PortalController.php`, `app/Services/Portal/WorkerProfile.php`, `app/Http/Middleware/VerifyPortalKey.php`
ทดสอบ: `php artisan test --filter=PortalApiTest`
