# ติดตั้งและตั้งค่า (XAMPP บน Windows)

## ติดตั้งใหม่

1. ติดตั้ง XAMPP (PHP 8.0+ แนะนำ 8.2) เปิด Apache และ MySQL วางโค้ดที่ `C:\xampp\htdocs\prompt_hub`
2. คัดลอก `config\config.example.php` เป็น `config\config.php` แก้ `db` และ `app.base_url` (`/prompt_hub/public` หรือ `''` ถ้า DocumentRoot ชี้ `public`)
3. ติดตั้งฐานข้อมูลและสร้างผู้ดูแลคนแรก (ผู้ติดตั้งตั้งรหัสผ่านเอง ≥ 10 ตัวอักษร):
   ```
   C:\xampp\php\php.exe bin\install.php --with-demo
   ```
   (`--with-demo` = Prompt ตัวอย่าง 16 รายการ, หยุดทันทีถ้าพบตารางเดิม) หรือ import `database/schema.sql` (+ `demo.sql`) ผ่าน phpMyAdmin แล้ว `php bin\create-admin.php`
4. เปิด `http://localhost/prompt_hub/public/`
5. ตรวจว่า URL เหล่านี้ได้ 403: `/prompt_hub/config/config.php`, `/database/schema.sql`, `/app/bootstrap.php`, `/storage/logs/`, `/storage/uploads/prompts/`, `/storage/uploads/avatars/`

## อัปเดตระบบที่ติดตั้งแล้ว

รัน migration ที่ยังไม่เคยรันตามลำดับเลข:
```
C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 prompt_hub < database\migrations\008_drive.sql
```

## config/config.php

| ส่วน | ค่า |
|---|---|
| `db` | host, port, name, user, pass (เครื่องจริงควรสร้างผู้ใช้ MySQL แยก) |
| `app` | `base_url`, `display_timezone` (Asia/Bangkok), `secure_cookie` (true เมื่อใช้ HTTPS), `session_idle_seconds` (7200), `app_url` (URL เต็มสำหรับลิงก์ในอีเมลและ Google redirect) |
| `mail` | `enabled`, `host`, `port`, `encryption` (tls/ssl/none), `username`, `password`, `from_email`, `from_name`, `timeout` — Gmail ใช้ App Password 16 หลัก |
| `google` | `enabled`, `client_id`, `client_secret` (ทดสอบ: `auth_url`, `token_url`, `userinfo_url` ชี้เซิร์ฟเวอร์จำลองได้) — consent screen External + Publish app เพื่อให้ Gmail ทั่วไปสมัครได้ |
| `drive` | `client_id`, `client_secret` ของ OAuth Client สำหรับ Drive (Internal) ไม่ใส่ = ใช้ส่วน google · ทดสอบ: `api_base`, `upload_base`, `image_base` |

## URL เว็บไซต์

ย้ายขึ้นเซิร์ฟเวอร์จริงแล้วตั้ง **URL เว็บไซต์** ใน หลังบ้าน › ตั้งค่าเว็บไซต์ (เช่น `https://prompthub.example.ac.th`) — ลิงก์ในอีเมล, Google redirect URI และลิงก์แชร์จะใช้ค่านี้ ส่วน `base_url` ใน config ต้องตรงกับ path จริงที่ Apache เสิร์ฟ `public/`

## Google Sign-In

Google Cloud Console → OAuth consent screen (scope `openid email profile`) → Credentials → OAuth client ID ชนิด Web application → Authorized redirect URI = ค่าที่หน้า ตั้งค่าเว็บไซต์ แสดง (URL เว็บไซต์ + `/index.php?r=google.callback`) เช่น `http://localhost/prompt_hub/public/index.php?r=google.callback` → ใส่ค่าใน config แล้วตั้ง `enabled => true`

## Google Drive (ที่เก็บภาพตัวอย่าง)

เปิด Google Drive API · consent screen แบบ Internal + scope `auth/drive` · เพิ่ม redirect URI ที่หน้า หลังบ้าน › Google Drive แสดง (`…/index.php?r=admin.drive.callback`) · ใส่ client_id/secret ในส่วน drive ของ config (หรือใช้ส่วน google) · หลังบ้าน › Google Drive › บันทึกโฟลเดอร์ › เชื่อมต่อ › อัปโหลดภาพที่ค้าง (หรือ `php bin\drive-sync.php`) · สำรอง `storage/google-drive.json` พร้อม `storage/uploads`

## เปิด GD (ย่อภาพฝั่งเซิร์ฟเวอร์)

แก้ `C:\xampp\php\php.ini` ลบ `;` หน้า `extension=gd` → Restart Apache ใน XAMPP Control Panel → ย่อภาพเก่า:
```
C:\xampp\php\php.exe bin\resize-images.php --dry-run
C:\xampp\php\php.exe bin\resize-images.php
```
(ยังไม่เปิดใน php.ini ก็รันด้วย `php -d extension=gd ...` ได้ — ถ้าเปิดแล้วห้ามใส่ `-d` เพราะจะมีคำเตือน “already loaded” ปนในผลลัพธ์) · แก้ php.ini ให้สำรองไฟล์ก่อน และแก้เฉพาะบรรทัดนั้น (คง CRLF) · ตรวจ `httpd -t` ก่อน restart

## อัปเดตเว็บบนโฮสต์

`python deployuild_update.py` → zip เฉพาะไฟล์ที่เปลี่ยน (แก้ `FILES` ทุกรุ่น ห้ามใส่ config/storage/.sql) · อัปโหลดทับในโฟลเดอร์ระบบบนโฮสต์ · `python deploy\check_live.py <URL public>` ตรวจเวอร์ชัน HTTPS CSP ปุ่ม Google redirect URI และคำตอบของ Google

## สำรองข้อมูล

สำรองทั้งฐานข้อมูล (`mysqldump prompt_hub`) และโฟลเดอร์ `storage/uploads/` (ภาพ) พร้อมกัน · `config/config.php` เก็บแยกอย่างปลอดภัย

## ก่อนเปิดใช้งานจริง

ตั้ง HTTPS + `secure_cookie = true` · กรอกผู้ควบคุมข้อมูล/ช่องทางติดต่อ PDPA ในหลังบ้าน และให้ DPO ตรวจทานนโยบาย · ตั้ง SMTP · เปิด GD · ตั้ง VirtualHost ให้ DocumentRoot ชี้ `public/`
