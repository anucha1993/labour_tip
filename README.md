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
- **แก้ไขข้อมูลผ่าน AI (MasterAdmin เท่านั้น)** `app/Services/Ai/DataUpdateService.php` ทำงาน 2 ขั้น: `propose_update` (ระบุรายคนด้วย query, หลายรายการด้วย items, หรือทุกคนที่ตรงเงื่อนไขด้วย filters เช่น ทั้งบริษัท สูงสุด 500 คน; ไม่มี filters = ไม่อนุญาต) ตรวจสอบค่า (ฟิลด์ที่อนุญาตเท่านั้น วันที่ไทย/สากล ชื่อบริษัท/เอเจนซี่/สัญชาติแปลงเป็นรหัสให้) แล้วคืนตารางค่าเดิม→ค่าใหม่ เก็บไว้ 10 นาที → ผู้ใช้พิมพ์ "ยืนยัน" → `apply_update` บันทึกค่าที่เก็บไว้ (ไม่ใช่ค่าที่โมเดลส่งมาตอนนั้น) ผ่าน Eloquent จึงมีประวัติใน action_logs และ `updated_by` ลงท้าย "(AI)" ไม่มีการลบรายการ ผู้ใช้สิทธิ์อื่นจะไม่เห็นเครื่องมือนี้และถูกปฏิเสธถ้าเรียก
- โมเดล: ตั้งใน `.env` `OPENAI_MODEL` (ปัจจุบัน gpt-4.1 ผ่าน proxy datacorpapi.com รองรับ gpt-4o-mini, gpt-4.1, gpt-4o, gpt-5) prompt หลักอยู่ที่ `LabourAssistant::systemPrompt()` (อภิธานศัพท์ ตารางเลือกเครื่องมือ รูปแบบคำตอบ กฎ) และคำอธิบายเครื่องมือใน `tools()`
- เครื่องมือวิเคราะห์เพิ่มเติม: `get_expiry_timeline` (เอกสารหมดอายุรายเดือนล่วงหน้า แยกบริษัทได้), `get_change_history` (ใครแก้อะไรเมื่อไร จาก action_logs), `check_data_quality` (ฟิลด์ว่าง วันที่ขัดกัน ทำงานอยู่แต่เอกสารหมด ไม่มีไฟล์)
- ทุกคำตอบมีปุ่ม 👍/👎 (เก็บใน `ai_chat_logs.rating/feedback` migration `2026_10_30_000001`) และชิปคำถามต่อเนื่องที่เปลี่ยนตามสิ่งที่ AI เพิ่งทำ หน้าประวัติการใช้ AI กรองคำตอบที่ถูกกด 👎 ได้ เพื่อนำไปปรับ prompt
- ชุดทดสอบคุณภาพกับโมเดลจริง: `php artisan ai:eval [-v] [--only=id] [--model=...]` อ่านคำถามจาก `tests/ai-eval.json` (ตรวจเครื่องมือที่ใช้/ห้ามใช้ และคำที่ต้องมีในคำตอบ) รันทุกครั้งที่แก้ prompt หรือเปลี่ยนโมเดล ใช้ token จริง ไม่เขียนฐานข้อมูล
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

## คลังเอกสาร (เปิดดูไฟล์แบบ Google Drive)

เมนู **คลังเอกสาร** (`/documents`, ทุกสิทธิ์เข้าดูได้) รวมไฟล์เอกสารทุกไฟล์ในระบบเป็นโฟลเดอร์: บริษัท → แรงงาน → ไฟล์

- หน้าแรก: โฟลเดอร์บริษัททั้งหมดพร้อมจำนวนคน/ไฟล์, สรุปพื้นที่ (R2 / เซิร์ฟเวอร์), ชิปกรองตามประเภทเอกสาร, ไฟล์ที่อัปโหลดล่าสุด, ช่องกรองชื่อบริษัทในหน้า
- โฟลเดอร์บริษัท: รายชื่อแรงงาน (รูป, พาสปอร์ต, จำนวนไฟล์, สถานะ) ค้นหา/กรองสถานะ/เรียงได้ แบ่งหน้า 60 คน
- โฟลเดอร์แรงงาน: ไฟล์เป็นการ์ดหรือรายการ คลิกเพื่อดูตัวอย่างในแผงด้านขวา (PDF/รูป) เปิดเต็มจอ ดาวน์โหลด (`?download=1` ตั้งชื่อไฟล์เป็น `ชื่อเอกสาร - พาสปอร์ต.ext`) ดาวน์โหลดทั้งโฟลเดอร์เป็น zip (`/documents/zip/{labour}`)
- ค้นหาทั้งระบบ `?q=` จากชื่อแรงงาน พาสปอร์ต ชื่อบริษัท ชื่อเอกสาร ชื่อไฟล์ และ `?type=` ตามประเภทเอกสาร
- Admin+: อัปโหลดหลายไฟล์ด้วยการลากมาวาง (`POST /documents/upload`) และเปลี่ยนชื่อเอกสาร (`PATCH /documents/{document}`); MasterAdmin: ลบ (ใช้เส้นทางลบเดิม)
- การอัปโหลดจากฟอร์มแรงงานและจากคลังเอกสารใช้ `App\Services\LabourDocumentService` ตัวเดียวกัน ไฟล์ไปที่ disk ตาม `LABOUR_DOCS_DISK`
- โค้ด: `app/Http/Controllers/documents/DocumentExplorerController.php`, `resources/views/documents/`, CSS ส่วน `.dx-*` ใน `public/css/labour.css`; เทสต์ `tests/Feature/DocumentExplorerTest.php`

## แชร์โฟลเดอร์เอกสาร (ลิงก์สาธารณะแบบ Google Drive)

Admin/MasterAdmin กด **แชร์โฟลเดอร์** ในคลังเอกสาร (โฟลเดอร์บริษัทหรือแรงงาน) เพื่อสร้างลิงก์ `/share/{token}` ให้คนนอกเปิดดูไฟล์โดยไม่ต้อง login

- ตั้งค่าได้: อีเมลผู้รับ (หลายคนคั่นด้วยจุลภาค, คอลัมน์ `recipient_email` จาก migration `2026_11_03_000003`), อายุลิงก์ (1/7/30/90 วัน หรือไม่หมดอายุ), รหัสผ่าน (ไม่บังคับ, เก็บแบบ bcrypt), อนุญาตดาวน์โหลดหรือดูอย่างเดียว, หมายเหตุ
- โหมดการเข้าถึง (`access`): `anyone` = ใครมีลิงก์ก็เปิดได้ (อีเมลเป็นบันทึก), `email` = เฉพาะอีเมลที่ระบุ ผู้รับต้องกรอกอีเมล; ถ้า server ส่งเมลได้ (`DocumentShare::mailReady()` ตรวจ MAIL_* ว่าไม่ใช่ log/mailhog และมี MAIL_FROM_ADDRESS) ระบบส่งรหัส 6 หลัก (`App\Mail\ShareAccessCode`, cache 10 นาที) ให้ยืนยัน ถ้ายังไม่ได้ตั้งค่าเมลจะตรวจแค่ว่าอีเมลตรงรายชื่อ
- ค่าเริ่มต้นของหน้าต่างแชร์: โหมด `email` (เฉพาะอีเมลที่ระบุ) ถูกเลือกไว้เสมอ (ช่องอีเมลเป็น required ขณะเลือกโหมดนี้) ต้องตั้งใจคลิก `anyone` เอง; ฝั่ง server ถ้าไม่ส่ง `access` มาแต่มีอีเมล → `email`, ไม่มีอีเมลเลย → `anyone`; หน้าต่าง "สร้างลิงก์แชร์แล้ว" บอกชัดว่าลิงก์นี้ใครเปิดได้ (`share_restricted`)
- ส่งลิงก์ทางอีเมลอัตโนมัติ (เหมือน Drive): เมื่อ server ส่งเมลได้ (`mailReady()`) การสร้างลิงก์ที่มีอีเมลผู้รับจะส่ง `App\Mail\ShareLinkNotice` (view `emails/share-link`) ให้ทุกคนทันที พร้อมข้อความถึงผู้รับ (`message`); เพิ่มอีเมลภายหลังส่งเฉพาะคนใหม่; ปุ่ม "ส่งอีเมลอีกครั้ง"/"ส่งลิงก์" → `POST /documents/share/{share}/notify` (ส่งให้ทุกคนในรายชื่อ หรือ `email` เดียว); ส่งไม่สำเร็จลิงก์ยังถูกสร้างและแสดงคำเตือน; ถ้ายังไม่มี SMTP ปุ่มเป็น `mailto:` เหมือนเดิม
- ลิงก์กดครั้งเดียว (one-click) สำหรับโหมด `email`: ปุ่ม "เปิดโฟลเดอร์" ในอีเมล (และในอีเมลรหัส 6 หลัก) เป็น signed URL ต่อผู้รับ `DocumentShare::openUrl($email)` → `GET /share/{token}/open?email&expires&signature` (`share.open`) อายุไม่เกิน 30 วันและไม่เกินวันหมดอายุของลิงก์; `open()` ตรวจ `hasValidSignature()` + `allows()` แล้วปลดล็อก session ทันที (ไม่ส่งรหัส) ถ้าลิงก์มีรหัสผ่าน จะจำอีเมลที่ยืนยันแล้วไว้ใน session (`share_verified_{id}`) และถามแค่รหัสผ่าน ลิงก์ถูกแก้/หมดอายุ/ถูกถอนสิทธิ์ → กลับไปหน้ากรอกอีเมล+รหัสตามปกติ
- ลบสิทธิ์รายคน: ในหน้าต่างแชร์ อีเมลแต่ละคนเป็นชิปกด × (หรือเพิ่มอีเมล) → `PATCH /documents/share/{share}`; คนที่ถูกลบจะเปิดลิงก์ไม่ได้ทันทีแม้เปิดค้างอยู่ (ตรวจอีเมลใน session กับรายชื่อทุกคำขอ) ลิงก์โหมด email ต้องเหลืออีเมลอย่างน้อย 1 รายการ
- หน้าผู้รับ: ลิงก์บริษัทเปิดเป็นโฟลเดอร์รายคน (grid/list) คลิกเข้าไปเห็นไฟล์แบบรายการเหมือน Drive (`?labour=ID`) นับจำนวนเปิดดูครั้งเดียวต่อ session; throttle ของ unlock ใช้ตัวนับแยก (`throttle:10,1,unlock`) ไม่ปนกับตัวนับ 120/นาทีของหน้าแชร์
- ทุกโฟลเดอร์ในคลังเอกสาร (การ์ดบริษัทและแรงงาน) มีปุ่ม ⋮ → แชร์โฟลเดอร์ โดยไม่ต้องเข้าไปข้างใน; การ์ดที่แชร์อยู่จะแสดง "แชร์ให้ <อีเมล>" และไอคอนโฟลเดอร์เปลี่ยนเป็นสีน้ำเงิน
- หลังสร้างลิงก์มีปุ่ม "ส่งทางอีเมล" (เปิดโปรแกรมอีเมลด้วย mailto พร้อมลิงก์) เพราะ server ยังไม่ได้ตั้งค่า SMTP (`MAIL_HOST=mailhog`); ถ้าตั้งค่า SMTP แล้วค่อยเพิ่มการส่งอัตโนมัติ
- ตาราง `document_shares` (migration `2026_11_03_000002`, **ต้องรัน `php artisan migrate`**) โมเดล `App\Models\DocumentShare`; ก่อนรัน migration ปุ่มแชร์จะไม่แสดงและหน้าอื่นทำงานปกติ
- หน้า `/documents/shares` ดูลิงก์ทั้งหมด จำนวนครั้งที่เปิด และกด **ยกเลิก** ได้ทันที (ลิงก์ที่ยกเลิก/หมดอายุตอบ 410 พร้อมหน้าอธิบาย)
- ไฟล์ในลิงก์เสิร์ฟผ่าน `/share/{token}/file/{document}` (ตรวจว่าไฟล์อยู่ในโฟลเดอร์ที่แชร์เท่านั้น) และ `/share/{token}/legacy/{labour}/{column}` สำหรับไฟล์เก่า; ไม่เปิดเผย path จริงหรือ URL R2 ถาวร (R2 ใช้ signed URL 15 นาทีเหมือนเดิม)
- rate limit: หน้าแชร์ 120 ครั้ง/นาที/IP, ใส่รหัสผ่าน 10 ครั้ง/นาที
- โค้ด: `app/Http/Controllers/documents/DocumentShareController.php`, views `documents/share-public`, `share-password`, `share-gone`, `shares`, `_share-modal`, layout `layouts/public.blade.php`; เทสต์ `tests/Feature/DocumentShareTest.php`

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
