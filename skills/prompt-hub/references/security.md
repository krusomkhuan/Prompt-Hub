# ความปลอดภัย — สิ่งที่ระบบทำไว้และต้องรักษาไว้

## เข้าสู่ระบบและ session

- `password_hash()` / `password_verify()` (rehash อัตโนมัติ) รหัสผ่าน 8–72 ไบต์
- `session_regenerate_id(true)` หลังเข้าสู่ระบบและเปลี่ยนรหัสผ่าน · cookie `PROMPTHUB_SID` HttpOnly + SameSite=Lax (+ Secure เมื่อ `secure_cookie = true`) · หมดอายุเมื่อไม่ใช้งาน 2 ชั่วโมง
- session เก็บ “รุ่นรหัสผ่าน” (`pwv`) — เปลี่ยนรหัสผ่านแล้ว session อื่นหมดสิทธิ์ · ทุกคำขอโหลดผู้ใช้ใหม่จากฐานข้อมูล ถูกระงับ/ถูกลบ → ออกจากระบบทันที
- จำกัดการเข้าสู่ระบบผิด: 5 ครั้ง/บัญชี+IP และ 20 ครั้ง/IP ใน 15 นาที (429) ตาราง `login_attempts` เก็บ hash ไม่เก็บรหัสผ่าน
- เปลี่ยนรหัสผ่านต้องยืนยันรหัสผ่านเดิม

## คำขอ

- ทุก POST ตรวจ CSRF token (64 hex ต่อ session) ใน Router · ไม่มีการเปลี่ยนข้อมูลผ่าน GET (route กำหนด method; ผิด → 405)
- ตรวจบทบาท สถานะบัญชี และความเป็นเจ้าของฝั่งเซิร์ฟเวอร์ทุกคำขอ · ทรัพยากรที่ไม่มีสิทธิ์ดู → 404
- รับค่าตัวเลข/ตัวเลือกผ่าน `queryId`, `queryIn`, `Validator` ค่าผิดรูปแบบใช้ค่าเริ่มต้นหรือ 422

## SQL

- prepared statements เสมอ (`Database::all/one/value/execute`) · LIKE ใช้ `Database::like()` · ORDER BY/ทิศทางจาก whitelist · IN ใช้ `Database::placeholders()`

## แสดงผล / XSS

- `e()` = `htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')` ทุกค่าใน view เนื้อหา Prompt แสดงใน `<pre>`/`<textarea>` เป็นข้อความ
- CSP: `default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' https://cdn.jsdelivr.net https://fonts.googleapis.com; img-src 'self' data: blob: https://lh3.googleusercontent.com; form-action 'self' https://accounts.google.com; frame-ancestors 'none'` → **ห้าม inline script/style/event handler** · ฟอร์มที่ redirect ไปโดเมนอื่น (เช่น OAuth) ต้องเพิ่มโดเมนนั้นใน `form-action` ไม่เช่นนั้นเบราว์เซอร์บล็อกและปุ่มค้าง (ชุดทดสอบ urllib จับไม่ได้ — ต้องลองในเบราว์เซอร์จริง)
- JS ใช้ `textContent` · ส่วนหัวอื่น: `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `Cache-Control: no-store, private`

## ไฟล์อัปโหลด

- ตรวจ `UPLOAD_ERR_*`, `is_uploaded_file()`, ขนาด, `finfo` MIME ต้องตรงกับ `getimagesize` type, ขนาดภาพ/พิกเซล
- ย่อ/หมุนด้วย GD แล้วตัด metadata ด้วยตนเอง (JPEG: APP1/APP13/COM, PNG: tEXt/zTXt/iTXt/eXIf/tIME, WebP: EXIF/XMP + ล้าง flag ใน VP8X)
- เก็บนอก `public/` ชื่อสุ่ม 32 hex ตรวจชื่อไฟล์ด้วย regex ก่อน `unlink`
- ส่งไฟล์ผ่าน route ที่ตรวจสิทธิ์ พร้อม `X-Content-Type-Options: nosniff` และ `Content-Security-Policy: default-src 'none'; sandbox`
- ลบไฟล์ **หลัง** commit; เขียนไฟล์ก่อนแล้วลบทิ้งถ้า transaction ล้มเหลว

## OAuth Google

PKCE (S256) + state + nonce ใน session อายุ 10 นาที · แลก token ฝั่งเซิร์ฟเวอร์ผ่าน HTTPS (curl ตรวจใบรับรอง) · ตรวจ `iss`, `aud`, `exp`, `nonce` · ต้อง `email_verified` · ไม่รับ token จากเบราว์เซอร์โดยตรง

## ความลับและการตั้งค่า

- `config/config.php` อยู่นอก `public` ไม่อยู่ใน git · ห้าม log รหัสผ่าน/SMTP password/token · `zend.exception_ignore_args = 1` กันค่าพารามิเตอร์หลุดใน stack trace
- ไม่มีบัญชีเริ่มต้นในโค้ด ผู้ดูแลคนแรกสร้างด้วย `bin/install.php` / `bin/create-admin.php` (ซ่อนตัวอักษรรหัสผ่าน)
- ข้อผิดพลาดบันทึกใน `storage/logs/app-YYYY-MM.log` ผู้ใช้เห็นข้อความทั่วไป

## เช็กลิสต์ก่อนส่งโค้ดใหม่

- [ ] route ใหม่กำหนด method และสิทธิ์ถูกต้อง · ฟอร์ม POST มี `csrf_field()`
- [ ] ทุกค่าใน view ผ่าน `e()` · ไม่มี inline script/style
- [ ] SQL ใหม่เป็น prepared statement · ตรวจความเป็นเจ้าของ
- [ ] ข้อผิดพลาดของผู้ใช้ตอบ 422 พร้อมข้อความไทย ไม่ใช่ 500
- [ ] การกระทำของผู้ดูแลบันทึกใน activity log
- [ ] ข้อมูลส่วนบุคคลใหม่ → อัปเดตหน้า `privacy`
