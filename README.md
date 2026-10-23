# Prompt Hub · เปลี่ยนไอเดียให้เป็นจริงด้วย AI

ศูนย์รวมคำสั่ง AI (Prompt) ภาษาไทย มีหน้าบ้านสำหรับค้นหาและคัดลอก พื้นที่สมาชิกสำหรับเก็บและแบ่งปัน Prompt และหลังบ้านสำหรับผู้ดูแลตรวจอนุมัติและจัดการระบบ

- PHP 8.2+ แบบ OOP (โค้ดใช้ได้ตั้งแต่ PHP 8.0 และทดสอบจริงบน XAMPP PHP 8.0.30)
- MySQL 8 / MariaDB 10.4+ ผ่าน **MySQLi + Prepared Statements** (ไม่ใช้ PDO)
- Bootstrap 5.3.3, Bootstrap Icons 1.11.3, ฟอนต์ Sarabun (โหลดจาก CDN พร้อมฟอนต์สำรอง)
- utf8mb4 ทั้งฐานข้อมูลและการเชื่อมต่อ เวลาเก็บเป็น UTC แสดงผลเป็น Asia/Bangkok
- ไม่มี Composer dependency (ใช้ extension `fileinfo` และ `curl` ที่มากับ XAMPP ไม่ต้องใช้ GD)
- หมวดหมู่ที่ผู้ดูแลเปิด “รับภาพตัวอย่าง” (ค่าเริ่มต้นคือ “การสร้างภาพ”) แนบภาพผลลัพธ์จาก AI ได้สูงสุด 4 ภาพต่อ Prompt
- ระบบสะสมแต้มและเหรียญรางวัล: 1 แต้มต่อ Prompt สาธารณะที่ผ่านการอนุมัติ — 5–10 แต้ม ทองแดง, 11–20 เงิน, 21 ขึ้นไป ทอง พร้อมหน้าทำเนียบ (`index.php?r=leaders`) เกณฑ์อยู่ใน `app/Services/BadgeService.php`
- สมัคร/เข้าสู่ระบบด้วยบัญชี Google ได้ (เปิดใน config) และ **คัดลอก Prompt ได้เฉพาะสมาชิก** ผู้เยี่ยมชมเห็นเพียงบางส่วน

> สโลแกนเริ่มต้นคือ “เปลี่ยนไอเดียให้เป็นจริงด้วย AI” ผู้ดูแลเปลี่ยนได้ภายหลัง
> ผู้ดูแลเปลี่ยนชื่อเว็บไซต์ สโลแกน ป้ายหน้าแรก คำอธิบาย ชื่อผู้พัฒนาระบบ (ส่วนท้ายเว็บ) และข้อมูลผู้ควบคุมข้อมูลตาม PDPA ได้ที่ **หลังบ้าน › ตั้งค่าเว็บไซต์** โดยไม่ต้องแก้โค้ด
>
> ผู้พัฒนาระบบ: **ครูสมควร สากำ** · เวอร์ชัน **1.12.0** (ดู [CHANGELOG.md](CHANGELOG.md))
>
> ส่วนท้ายเว็บแสดง “© 2026 ครูสมควร สากำ. All rights reserved.” และเวอร์ชันระบบ — แก้เจ้าของลิขสิทธิ์และปีที่เริ่มเผยแพร่ได้ที่ **หลังบ้าน › ตั้งค่าเว็บไซต์** (ปีถัดไปแสดงเป็นช่วงปี เช่น 2026–2027 อัตโนมัติ) ส่วนเลขเวอร์ชันแก้ใน `app/version.php` เมื่อออกรุ่นใหม่

---

## 1. สถาปัตยกรรม

```
Browser ──► public/index.php (Front Controller)
              │  ตั้ง Security Headers (CSP, X-Frame-Options, nosniff)
              ▼
            Core\App::boot()  ── Database (MySQLi, utf8mb4, UTC)
              │                 Session (cookie HttpOnly/SameSite, idle timeout)
              │                 Auth (โหลดผู้ใช้จาก DB ทุกคำขอ → ระงับ/ลบมีผลทันที)
              ▼
            Core\Router  ── ตรวจ HTTP method → CSRF (ทุก POST) → สิทธิ์ any/guest/member/admin
              ▼
            Controllers  ── รับ/ตรวจค่า (Validator) → เรียก Services/Repositories → View
              ▼
            Services     ── กฎธุรกิจ: วงจรเผยแพร่, ตรวจอนุมัติ, กฎผู้ดูแลคนสุดท้าย, จำกัดการ login
              ▼
            Repositories ── SQL ทั้งหมดอยู่ที่นี่ ใช้ Prepared Statements เท่านั้น
```

- **PromptPolicy** คือจุดเดียวที่ตัดสินว่าใครเห็น/แก้ Prompt ใดได้ และ `PromptRepository::PUBLIC_CONDITION` คือเงื่อนไข “เผยแพร่สาธารณะ” จุดเดียว ใช้ร่วมกันทุกหน้า การค้นหา รายการโปรด และ endpoint คัดลอก
- URL ใช้รูปแบบ `index.php?r=ชื่อเส้นทาง` จึงไม่ต้องเปิด mod_rewrite (เส้นทางทั้งหมดอยู่ที่ `app/routes.php`)
- คำขอที่เปลี่ยนข้อมูลทั้งหมดเป็น POST + CSRF Token ส่วน GET ใช้อ่านอย่างเดียว

## 2. โครงสร้างโฟลเดอร์

```
prompt_hub/
├── index.php                 ส่งต่อไป public/
├── .htaccess                 ปิด directory listing และไฟล์ .md .sql .log ฯลฯ
├── app/                      โค้ดแอป (ปฏิเสธการเข้าถึงผ่านเว็บด้วย .htaccess)
│   ├── bootstrap.php         autoload, ตั้งค่า error/log, โหลด config
│   ├── helpers.php           e(), url(), thai_date(), csrf_field() ฯลฯ
│   ├── routes.php            ตารางเส้นทาง + method + สิทธิ์
│   ├── Core/                 App, Router, Database, Session, Auth, Csrf, Validator, View, Mailer (SMTP), Logger, Exceptions
│   ├── Repositories/         Prompt, User, Category, Tag, AiTool, Favorite, Setting, ActivityLog, LoginAttempt
│   ├── Services/             PromptService, PromptPolicy, AuthService, AdminService, PasswordResetService
│   ├── Controllers/          Home, Prompt, Auth, Member, Admin
│   └── Cli/Console.php       ตัวช่วยติดตั้งและสร้างผู้ดูแล
├── bin/
│   ├── install.php           ติดตั้งฐานข้อมูล (+ข้อมูลสาธิต) และสร้างผู้ดูแลคนแรก
│   └── create-admin.php      สร้างผู้ดูแลเพิ่ม
├── config/
│   ├── config.example.php    ไฟล์ตัวอย่าง (ไม่มีข้อมูลลับ)
│   └── config.php            ค่าจริงของเครื่อง (ไม่ commit — อยู่ใน .gitignore)
├── database/
│   ├── schema.sql            โครงสร้าง + ค่าเริ่มต้น (หมวดหมู่ 8 หมวด, เครื่องมือ AI 8 รายการ, ตั้งค่าเว็บไซต์)
│   ├── demo.sql              ข้อมูลสาธิต 16 Prompt (ไม่บังคับ)
│   └── migrations/           ไฟล์ปรับฐานข้อมูลที่ติดตั้งไว้ก่อนแล้ว
├── public/                   ส่วนเดียวที่เว็บเข้าถึงได้
│   ├── index.php             Front Controller
│   └── assets/css/app.css, assets/js/app.js
├── views/                    layouts, partials, public, auth, member, admin, errors
├── storage/logs/             log ข้อผิดพลาดฝั่งเซิร์ฟเวอร์ (ปฏิเสธการเข้าถึงผ่านเว็บ)
├── tests/                    integration.py, password_reset.py, fake_smtp.py, fixtures.php
└── docs/USER_GUIDE.md        คู่มือใช้งานสำหรับสมาชิกและผู้ดูแล
```

## 3. ฐานข้อมูล

| ตาราง | หน้าที่ | คีย์และข้อกำหนดสำคัญ |
|---|---|---|
| `users` | สมาชิกและผู้ดูแล | UNIQUE `username`, UNIQUE `email`; `role` member/admin; `status` active/suspended; `privacy_accepted_at` เวลาที่ยอมรับนโยบายความเป็นส่วนตัว |
| `categories` | หมวดหมู่ | UNIQUE `name`; `is_active`, `sort_order` |
| `prompts` | Prompt | FK `user_id` → users **CASCADE**; FK `category_id` → categories **RESTRICT**; FK `reviewed_by` → users **SET NULL**; `visibility` private/public; `status` draft/pending/published/rejected; `rejection_reason`, `copy_count`, `view_count`, `published_at` |
| `tags` | แท็ก | UNIQUE `name` |
| `prompt_tags` | Prompt ↔ แท็ก | PK (`prompt_id`,`tag_id`) ป้องกันซ้ำ; CASCADE ทั้งสองฝั่ง |
| `ai_tools` | เครื่องมือ AI | UNIQUE `name`; `is_active`, `sort_order` |
| `prompt_ai_tools` | Prompt ↔ เครื่องมือ | PK (`prompt_id`,`ai_tool_id`); CASCADE ทั้งสองฝั่ง |
| `favorites` | รายการโปรด | PK (`user_id`,`prompt_id`) ไม่ซ้ำต่อคู่; CASCADE |
| `site_settings` | ชื่อ สโลแกน คำอธิบาย ป้ายหน้าแรก ผู้พัฒนาระบบ ผู้ควบคุมข้อมูลและช่องทางติดต่อ PDPA | PK `setting_key` |
| `admin_activity_logs` | บันทึกกิจกรรมผู้ดูแล | FK `admin_id` → users **SET NULL** และเก็บสำเนา `admin_username` |
| `login_attempts` | จำกัดความถี่การเข้าสู่ระบบและคำขอลืมรหัสผ่าน | เก็บ hash ของชื่อผู้ใช้/อีเมล+IP (ไม่เก็บรหัสผ่าน) |
| `password_resets` | ลิงก์ตั้งรหัสผ่านใหม่ | เก็บเฉพาะ SHA-256 ของ token (UNIQUE), `expires_at`, `used_at`; FK `user_id` → users **CASCADE** |
| `shop_products` | สินค้า Digital Shop (eBook / Prompt / ชุดโค้ด) | `product_type`, `price` (0 = ฟรี), `compare_price`, `full_content`, ไฟล์ปก/ดาวน์โหลด/โค้ด (ชื่อสุ่มใน `storage/uploads/shop`), `external_url`, `is_active`; FK `created_by` → users **SET NULL** |
| `shop_orders` | คำสั่งซื้อ/การรับสินค้า | UNIQUE `order_no` (DS+ปีเดือนวัน+ลำดับ); `status` pending/paid/rejected; `slip_file`, `admin_note`; FK users/shop_products **CASCADE**, `reviewed_by` **SET NULL** |
| `shop_favorites` | รายการโปรดของร้านค้า | PK (`user_id`,`product_id`); CASCADE |
| `shop_reviews` | คะแนน/รีวิวสินค้า | UNIQUE (`product_id`,`user_id`) คนละ 1 รีวิว; รีวิวได้เฉพาะผู้ที่ได้รับสินค้าแล้ว |

ดัชนีที่ใช้จริง: `(visibility,status,created_at)` สำหรับรายการล่าสุด, `(visibility,status,copy_count)` สำหรับเรียงตามการคัดลอก, `(category_id,visibility,status)` สำหรับกรองหมวด, `(user_id,status)` สำหรับหน้า Prompt ของฉัน, `(status,updated_at)` สำหรับคิวตรวจสอบ, และดัชนีฝั่งตรงข้ามของตารางเชื่อมสำหรับกรองแท็ก/เครื่องมือ

พฤติกรรมเมื่อลบ: ลบสมาชิก → ลบ Prompt และรายการโปรดของสมาชิกนั้น · ลบ Prompt → ลบความสัมพันธ์แท็ก/เครื่องมือ/รายการโปรด · ลบแท็กหรือเครื่องมือ → นำออกจาก Prompt เท่านั้น · ลบหมวดหมู่ → ฐานข้อมูลปฏิเสธถ้ายังมี Prompt ระบบให้เลือกหมวดปลายทางแล้วย้ายและลบใน Transaction เดียว

การบันทึก Prompt พร้อมแท็กและเครื่องมือ AI, การอนุมัติ, การจัดการสมาชิก (ล็อกแถวผู้ดูแล `FOR UPDATE`) และการย้าย/ลบหมวดหมู่ ทำใน Transaction

## 4. วงจรการเผยแพร่ Prompt

| การกระทำ | ผลลัพธ์ |
|---|---|
| บันทึกเป็น “ส่วนตัว” | `draft` เห็นเฉพาะเจ้าของและผู้ดูแล |
| “สาธารณะ” + บันทึกฉบับร่าง | `draft` ยังไม่แสดงหน้าบ้าน |
| “สาธารณะ” + ส่งตรวจ | `pending` |
| ผู้ดูแลอนุมัติ | `published` ปรากฏหน้าบ้าน |
| ผู้ดูแลไม่อนุมัติ (ต้องระบุเหตุผล) | `rejected` เจ้าของเห็นเหตุผล แก้แล้วส่งใหม่ได้ |
| สมาชิกแก้ไข Prompt ที่ `published` | กลับเป็น `pending` หยุดแสดงหน้าบ้าน/การค้นหา/รายการโปรดจนกว่าจะอนุมัติใหม่ |
| ผู้ดูแลแก้ไข Prompt ที่ `published` | คงสถานะ `published` และบันทึกกิจกรรม |

ตัวแปรในเนื้อหา เช่น `[ชื่อสินค้า]` `[กลุ่มเป้าหมาย]` `[น้ำเสียง]` จะมีช่องกรอกในหน้ารายละเอียด JavaScript สร้างข้อความใหม่จากต้นฉบับทุกครั้ง ไม่แก้ต้นฉบับในหน้าและในฐานข้อมูล

## 5. คู่มือติดตั้งบน XAMPP (Windows)

### 5.1 เตรียมเครื่อง

1. ติดตั้ง XAMPP (แนะนำรุ่นที่มี PHP 8.2 ขึ้นไป) เปิด **Apache** และ **MySQL** ใน XAMPP Control Panel
2. ตรวจว่า `php.ini` เปิด extension `mysqli` และ `mbstring` (XAMPP เปิดไว้แล้วโดยปกติ)
3. วางโค้ดไว้ที่ `C:\xampp\htdocs\prompt_hub`

### 5.2 ตั้งค่า

คัดลอก `config\config.example.php` เป็น `config\config.php` แล้วแก้ค่า:

```php
'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'prompt_hub', 'user' => 'root', 'pass' => ''],
'app' => ['base_url' => '/prompt_hub/public', 'secure_cookie' => false, ...],
```

- XAMPP ค่าเริ่มต้นใช้ผู้ใช้ `root` รหัสผ่านว่าง สำหรับเครื่องจริงควรสร้างผู้ใช้ MySQL แยกสำหรับแอป
- ถ้าตั้ง DocumentRoot ชี้ไปที่ `public` โดยตรง ให้ `base_url` เป็น `''`
- ใช้ HTTPS แล้วให้ตั้ง `secure_cookie` เป็น `true`

### 5.3 ติดตั้งฐานข้อมูลและสร้างผู้ดูแลคนแรก

เปิด PowerShell หรือ Command Prompt:

```bash
cd C:\xampp\htdocs\prompt_hub
C:\xampp\php\php.exe bin\install.php --with-demo
```

สคริปต์จะ

1. ตรวจรุ่น PHP และ extension
2. สร้างฐานข้อมูล `prompt_hub` (utf8mb4) และตารางทั้งหมด
3. นำเข้าข้อมูลสาธิต 16 รายการ (ตัด `--with-demo` ออกถ้าไม่ต้องการ)
4. **ถามชื่อผู้ใช้ อีเมล ชื่อที่แสดง และรหัสผ่านของผู้ดูแลคนแรก** — คุณกำหนดรหัสผ่านเอง (อย่างน้อย 10 ตัวอักษร) ระบบไม่มีบัญชีหรือรหัสผ่านเริ่มต้นฝังในโค้ด บน Windows ช่องรหัสผ่านไม่แสดงตัวอักษรขณะพิมพ์ (ผ่าน PowerShell)

สคริปต์ติดตั้งใช้กับฐานข้อมูลใหม่เท่านั้น ถ้าพบตารางอยู่แล้วจะหยุดเพื่อไม่ทับข้อมูลเดิม

สร้างผู้ดูแลเพิ่มภายหลัง:

```bash
C:\xampp\php\php.exe bin\create-admin.php
```

หรือให้ผู้ดูแลที่มีอยู่กด “ตั้งเป็นผู้ดูแล” ให้สมาชิกในหน้า **หลังบ้าน › สมาชิก**

### 5.4 ทางเลือก: ติดตั้งผ่าน phpMyAdmin

1. สร้างฐานข้อมูล `prompt_hub` เลือก Collation `utf8mb4_unicode_ci`
2. Import `database/schema.sql` แล้ว (ถ้าต้องการ) `database/demo.sql`
3. รัน `C:\xampp\php\php.exe bin\create-admin.php` เพื่อสร้างผู้ดูแล

### 5.5 เปิดใช้งาน

เปิด `http://localhost/prompt_hub/` (ระบบส่งต่อไป `/prompt_hub/public/`) แล้วเข้าสู่ระบบด้วยบัญชีผู้ดูแลที่สร้างไว้ เมนู **หลังบ้าน** จะปรากฏบนแถบด้านบน

### 5.6 ตั้งค่าอีเมลสำหรับ “ลืมรหัสผ่าน”

XAMPP ส่งอีเมลเองไม่ได้ ระบบจึงส่งผ่าน SMTP ของบัญชีอีเมลที่คุณมี (เช่น Gmail ของวิทยาลัย) จนกว่าจะตั้งค่า หน้า “ลืมรหัสผ่าน” จะแจ้งให้ติดต่อผู้ดูแล

ตัวอย่างใช้ Gmail:

1. เปิด **2-Step Verification** ของบัญชี Google ที่จะใช้ส่ง
2. สร้าง **App Password** ที่ https://myaccount.google.com/apppasswords (ได้รหัส 16 ตัว)
3. แก้ `config\config.php`:

```php
'app' => [
    // ...
    'app_url' => 'http://localhost',   // ที่อยู่เว็บที่สมาชิกเปิดได้จริง เช่น https://prompt.ตัวอย่าง.ac.th
],
'mail' => [
    'enabled' => true,
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'encryption' => 'tls',
    'username' => 'ชื่อบัญชี@gmail.com',
    'password' => 'App Password 16 ตัว',
    'from_email' => 'ชื่อบัญชี@gmail.com',
    'from_name' => 'Prompt Hub',
    'timeout' => 15,
],
```

- ลิงก์ในอีเมลสร้างจาก **URL เว็บไซต์** ที่ตั้งใน **หลังบ้าน › ตั้งค่าเว็บไซต์** (ถ้าเว้นว่างใช้ `app_url` + `base_url` ในไฟล์ตั้งค่า) ระบบไม่อ่านจาก Host header ของคำขอ เพื่อกันการปลอมลิงก์ ถ้าใช้แค่ `localhost` สมาชิกจะเปิดลิงก์ได้จากเครื่องเซิร์ฟเวอร์เท่านั้น
- ถ้าส่งไม่สำเร็จ สมาชิกยังเห็นข้อความปกติ (ไม่เปิดเผยว่ามีบัญชีหรือไม่) สาเหตุจะอยู่ใน `storage/logs/` โดยไม่บันทึกรหัสผ่าน SMTP
- ติดตั้งไว้ก่อนมีฟีเจอร์ใหม่? รันไฟล์ใน `database\migrations\` ตามลำดับเลข (ครั้งเดียวต่อไฟล์) ผ่าน phpMyAdmin หรือ
  `C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 prompt_hub < database\migrations\001_password_resets.sql`
  แล้วทำซ้ำกับ `002_pdpa.sql`, `003_images_google.sql`, `004_copyright.sql`, `005_avatar.sql`, `006_site_url.sql`, `007_content_th.sql` และ `008_drive.sql`

### 5.6.1 สมัคร/เข้าสู่ระบบด้วย Google

1. เข้า [Google Cloud Console](https://console.cloud.google.com/) › APIs & Services › **OAuth consent screen** ตั้งชื่อแอป อีเมลติดต่อ และ scope `openid` `email` `profile` — เลือก **External** และกด **Publish app** (In production) เพื่อให้สมาชิกที่ใช้ Gmail ทั่วไปสมัครได้ (แบบ Internal ใช้ได้เฉพาะบัญชีในโดเมนองค์กร; แบบ Testing ใช้ได้เฉพาะ Test users) scope พื้นฐานนี้ไม่ต้องรอ Google ตรวจสอบแอป
2. **Credentials › Create credentials › OAuth client ID** เลือก *Web application*
3. **Authorized redirect URIs** ใส่ค่าที่แสดงในช่อง “Google redirect URI” ของ **หลังบ้าน › ตั้งค่าเว็บไซต์** ให้ตรงทุกตัวอักษร (= URL เว็บไซต์ + `/index.php?r=google.callback`) เช่น
   `http://localhost/prompt_hub/public/index.php?r=google.callback`
4. นำ Client ID และ Client secret มาใส่ที่ **หลังบ้าน › ตั้งค่าเว็บไซต์ › เข้าสู่ระบบ/สมัครสมาชิกด้วย Google** แล้วเปิดสวิตช์ และกด **ทดสอบกับ Google** (หรือใส่ใน `config\config.php` แบบเดิม — ค่าในหลังบ้านมีผลก่อน):

```php
'google' => [
    'enabled' => true,
    'client_id' => 'xxxx.apps.googleusercontent.com',
    'client_secret' => 'GOCSPX-...',
],
```

- ปุ่ม “เข้าสู่ระบบด้วย Google” และ “สมัครด้วยบัญชี Google” จะแสดงในหน้าเข้าสู่ระบบและสมัครสมาชิกเมื่อ `enabled` เป็น `true` เท่านั้น
- ครั้งแรกระบบให้ผู้ใช้ตั้งชื่อผู้ใช้และติ๊กยอมรับนโยบายความเป็นส่วนตัวก่อนสร้างบัญชี ถ้าอีเมล Google ตรงกับบัญชีเดิม ระบบเชื่อมบัญชีให้ (อีเมลต้องยืนยันกับ Google แล้ว)
- บัญชีที่สร้างด้วย Google ไม่มีรหัสผ่าน ถ้าต้องการเข้าด้วยรหัสผ่านให้ใช้ “ลืมรหัสผ่าน” ตั้งใหม่
- ใช้ PKCE + state + nonce, ตรวจ `iss`/`aud`/`exp` ของ id_token และเรียก token/userinfo ผ่าน HTTPS (curl ตรวจใบรับรอง)

### 5.6.2 ภาพตัวอย่างผลลัพธ์

- เปิด/ปิดการรับภาพรายหมวดที่ **หลังบ้าน › หมวดหมู่** (สวิตช์ “รับภาพตัวอย่าง”)
- รับ JPEG, PNG, WebP ไม่เกิน 5 MB ต่อภาพ ด้านยาวไม่เกิน 8000 px สูงสุด 4 ภาพต่อ Prompt ตรวจชนิดจากเนื้อไฟล์ (ไม่เชื่อนามสกุล)
- ระบบตัดข้อมูลแฝง (EXIF/GPS, XMP, ข้อความใน PNG) ก่อนเก็บ และเก็บใน `storage\uploads\prompts\` ด้วยชื่อสุ่ม (นอก `public`)
- ภาพแสดงผ่าน `index.php?r=media&id=` ที่ตรวจสิทธิ์เดียวกับ Prompt — ภาพของ Prompt ส่วนตัว/รอตรวจสอบเปิดจาก URL ตรงไม่ได้
- ลบภาพ ลบ Prompt หรือลบสมาชิก ไฟล์ภาพบนดิสก์ถูกลบตาม ให้รวมโฟลเดอร์ `storage\uploads` ในการสำรองข้อมูล
- ถ้าต้องการไฟล์ใหญ่กว่า 5 MB ต้องตรวจ `upload_max_filesize` / `post_max_size` ใน php.ini ด้วย (XAMPP ค่าเริ่มต้น 40M)

### 5.6.3 ภาพโปรไฟล์และการย่อขนาดภาพ

- สมาชิกและผู้ดูแลเปลี่ยน/ลบภาพโปรไฟล์ได้ที่หน้า **โปรไฟล์** (JPEG/PNG/WebP ไม่เกิน 2 MB) ไฟล์อยู่ใน `storage\uploads\avatars\` แสดงผ่าน `index.php?r=avatar&id=` ผู้ดูแลกด **ลบภาพ** ของสมาชิกได้ที่ **หลังบ้าน › สมาชิก**
- ระบบย่อภาพให้อัตโนมัติ: ภาพตัวอย่างด้านยาวไม่เกิน **1600 px**, ภาพโปรไฟล์ตัดกลาง **256×256 px**, หมุนภาพมือถือให้ตั้งตรง และแปลง PNG ทึบที่ใหญ่เกิน 1 MB เป็น JPEG เมื่อไฟล์เล็กกว่า
- ย่อ 2 ชั้น: (1) เบราว์เซอร์ย่อก่อนอัปโหลด — ทำงานได้ทันที (2) เซิร์ฟเวอร์ย่อซ้ำด้วย **GD** — กันกรณีปิด JavaScript หรือส่งไฟล์ตรง ต้องเปิด GD ใน `C:\xampp\php\php.ini` โดยลบ `;` หน้าบรรทัด `extension=gd` แล้ว Restart Apache ใน XAMPP Control Panel (ไม่เปิดก็ใช้งานได้ ระบบเก็บไฟล์ตามที่เบราว์เซอร์ย่อมา)
- ย่อภาพที่อัปโหลดไว้ก่อนหน้า (สำรอง `storage\uploads` ก่อน):
  ```
  C:\xampp\php\php.exe bin\resize-images.php --dry-run
  C:\xampp\php\php.exe bin\resize-images.php
  ```
  ถ้ายังไม่เปิด GD ใน php.ini ให้เพิ่ม `-d extension=gd` หลัง `php.exe` (ถ้าเปิดแล้วห้ามใส่ จะมีคำเตือน “Module gd is already loaded”)
- เครื่องนี้เปิด `extension=gd` แล้ว (17 ต.ค. 2026, สำรอง php.ini เดิมไว้ที่ `C:\xampp\php\php.ini.bak-2026-10-17-before-gd`)

### 5.6.4 URL เว็บไซต์ (เมื่อย้ายขึ้นเซิร์ฟเวอร์จริง)

ไปที่ **หลังบ้าน › ตั้งค่าเว็บไซต์** กรอก **URL เว็บไซต์** = ที่อยู่หน้าแรกที่ผู้ใช้เปิดจริง เช่น `https://prompthub.example.ac.th` (เมื่อตั้ง DocumentRoot ชี้ `public`) หรือ `https://www.example.ac.th/prompt_hub/public`

- ใช้สร้างลิงก์ในอีเมลลืมรหัสผ่าน, Google redirect URI และลิงก์แชร์ (canonical / og:url / og:image) — หน้าตั้งค่าแสดงตัวอย่างลิงก์ทั้งหมดขณะพิมพ์
- ลิงก์ภายในเว็บยังใช้ `base_url` ใน config ถ้าย้ายโฟลเดอร์หรือเปลี่ยน DocumentRoot ต้องแก้ `base_url` ด้วย
- เปลี่ยน URL แล้ว ถ้าเปิด Google ต้องเพิ่ม redirect URI ใหม่ใน Google Cloud Console · ใช้ HTTPS แล้วตั้ง `secure_cookie = true`
- รับเฉพาะ `http://` / `https://` ไม่มี `?` `#` หรือชื่อผู้ใช้ โดเมนภาษาไทยให้กรอกแบบ punycode (`xn--...`)

### 5.6.5 เก็บภาพตัวอย่างใน Google Drive

1. Google Cloud Console — แนะนำ **โปรเจกต์/OAuth Client แยก** จาก Google Sign-In (Sign-In ต้องเป็น External ส่วน Drive ควรเป็น Internal): เปิดใช้ **Google Drive API** · OAuth consent screen เลือก **Internal** (บัญชี @pticec.ac.th — refresh token ไม่หมดอายุ; ถ้าเป็น External โหมด Testing ต้องเชื่อมต่อใหม่ทุก 7 วัน) และเพิ่ม scope `https://www.googleapis.com/auth/drive`
2. OAuth client (Web application) › Authorized redirect URIs เพิ่มค่าที่หน้า **หลังบ้าน › Google Drive** แสดง เช่น `http://localhost/prompt_hub/public/index.php?r=admin.drive.callback`
3. ใส่ `client_id` / `client_secret` ของ Client สำหรับ Drive ในส่วน **`drive`** ของ `config/config.php` (ถ้าไม่ใส่ ระบบใช้ค่าในส่วน `google`)
4. **หลังบ้าน › Google Drive** › วางลิงก์โฟลเดอร์ › บันทึก › **เชื่อมต่อ Google Drive** ด้วยบัญชีเจ้าของโฟลเดอร์
5. กด **อัปโหลดภาพที่ยังไม่อยู่บน Drive** เพื่อย้ายภาพเดิม (ครั้งละ 20 ภาพ) หรือรัน `C:\xampp\php\php.exe bin\drive-sync.php`

- ไฟล์บน Drive ตั้งเป็น “ทุกคนที่มีลิงก์ดูได้” เพื่อให้ลิงก์ภาพตรงได้ → ภาพของ Prompt ส่วนตัว/รอตรวจสอบเปิดได้ถ้ามีลิงก์ไฟล์ และผู้มีลิงก์โฟลเดอร์เห็นภาพทั้งหมด อย่าเก็บไฟล์ลับในโฟลเดอร์เดียวกัน
- token เก็บใน `storage/google-drive.json` (เว็บเข้าไม่ได้) — สำรอง/ย้ายเครื่องให้คัดลอกไฟล์นี้ หรือเชื่อมต่อใหม่
- ภาพในเครื่องยังเก็บไว้ทั้งหมด ถ้าภาพจาก Drive โหลดไม่ได้ หน้าเว็บใช้สำเนาในเครื่องแทน · ยกเลิกการเชื่อมต่อแล้วกลับไปแสดงภาพจากเครื่อง
- ทดสอบอัตโนมัติ (`tests/*.py`) ขณะเชื่อม Drive จริงอยู่ จะอัปโหลดภาพทดสอบขึ้น Drive แล้วย้ายไปถังขยะตอนจบ

### 5.6.6 Digital Shop

- ฐานข้อมูลที่ติดตั้งก่อนเวอร์ชัน 1.12 ให้นำเข้า `database/migrations/009_shop.sql` (ติดตั้งใหม่ด้วย `schema.sql` มีให้แล้ว)
- **หลังบ้าน › คำสั่งซื้อ › ตั้งค่าร้าน**: ใส่หมายเลขพร้อมเพย์ (เบอร์โทร 10 หลัก / เลขประจำตัว 13 หลัก) ระบบสร้าง QR ระบุยอดเงินให้อัตโนมัติ, ชื่อบัญชี และคำแนะนำการชำระเงิน · ปิดร้านได้ (หน้าร้านเห็นเฉพาะผู้ดูแล)
- **หลังบ้าน › Digital Shop › เพิ่มสินค้า**: สินค้าต้องมีสิ่งที่ผู้ซื้อได้รับอย่างน้อย 1 อย่าง — เนื้อหาเต็ม/โค้ด, ไฟล์ดาวน์โหลด (PDF, EPUB, ZIP, DOCX, PPTX, XLSX, TXT, MD), ไฟล์โค้ด .zip หรือลิงก์รับสินค้า · ไฟล์ละไม่เกิน 100 MB และไม่เกิน `upload_max_filesize`/`post_max_size` ของ PHP (XAMPP ค่าเริ่มต้น 40M)
- ไม่มีการตัดเงินอัตโนมัติ: ผู้ดูแลตรวจสลิปที่ **หลังบ้าน › คำสั่งซื้อ** แล้วกด “ยืนยันการชำระเงิน” หรือ “ไม่ผ่าน” (ระบุเหตุผล) สินค้าจึงปรากฏใน “คลังของฉัน” ของผู้ซื้อ
- ปิดการขายสินค้า: ผู้ที่ได้รับแล้วยังเปิดได้ · ลบสินค้า: ลบไฟล์ คำสั่งซื้อ และรีวิวของสินค้านั้นด้วย

### 5.7 ตรวจความปลอดภัยหลังติดตั้ง

ต้องเปิด `AllowOverride All` (XAMPP เปิดไว้แล้ว) เพื่อให้ไฟล์ `.htaccess` ทำงาน ลองเปิด URL เหล่านี้ ทุกรายการต้องได้ **403 Forbidden**:

- `http://localhost/prompt_hub/config/config.php`
- `http://localhost/prompt_hub/database/schema.sql`
- `http://localhost/prompt_hub/app/bootstrap.php`
- `http://localhost/prompt_hub/storage/logs/`
- `http://localhost/prompt_hub/storage/uploads/prompts/`
- `http://localhost/prompt_hub/storage/uploads/avatars/`
- `http://localhost/prompt_hub/storage/uploads/shop/`

สำหรับเครื่องจริง แนะนำตั้ง VirtualHost ให้ DocumentRoot ชี้ไปที่ `C:/xampp/htdocs/prompt_hub/public` โดยตรง

## 6. PDPA (พ.ร.บ.คุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562)

- หน้า **นโยบายความเป็นส่วนตัว** (`index.php?r=privacy`, ลิงก์อยู่ส่วนท้ายทุกหน้า) อธิบายข้อมูลที่ระบบเก็บจริง วัตถุประสงค์ ฐานทางกฎหมาย ผู้ให้บริการภายนอก (jsDelivr, Google Fonts, SMTP, Google Sign-In) ระยะเวลาเก็บ สิทธิ์ของเจ้าของข้อมูล และคุกกี้
- สมัครสมาชิกต้องติ๊กยอมรับนโยบาย ระบบบันทึกเวลาไว้ใน `users.privacy_accepted_at`
- แถบแจ้งคุกกี้ด้านล่างหน้าเว็บ (ระบบใช้เฉพาะคุกกี้ session ที่จำเป็น ไม่มีคุกกี้โฆษณา/วิเคราะห์)
- ลบคำขอ login ผิดและลิงก์ตั้งรหัสผ่านที่เกิน 1 วันอัตโนมัติ
- **ก่อนเปิดใช้จริง:** กรอกชื่อผู้ควบคุมข้อมูลและช่องทางติดต่อที่ **หลังบ้าน › ตั้งค่าเว็บไซต์** และให้หน่วยงาน/เจ้าหน้าที่คุ้มครองข้อมูลส่วนบุคคล (DPO) ตรวจทานเนื้อหา เพราะเนื้อหาเป็นแม่แบบตามการทำงานของระบบ ไม่ใช่คำปรึกษาทางกฎหมาย
- ยังไม่มีปุ่มให้สมาชิกดาวน์โหลดข้อมูลของตนเองหรือลบบัญชีเอง คำขอเหล่านี้ทำผ่านผู้ดูแล (ผู้ดูแลลบบัญชีได้ในหน้าสมาชิก)

## 7. ความปลอดภัยที่ทำไว้

- รหัสผ่านใช้ `password_hash()` / `password_verify()` (และ rehash อัตโนมัติเมื่ออัลกอริทึมเปลี่ยน)
- SQL ทุกคำสั่งที่มีค่าจากผู้ใช้ใช้ Prepared Statements; คำค้นหา escape `%` และ `_`; การเรียงลำดับใช้รายการที่อนุญาตเท่านั้น
- Escape ตามบริบทด้วย `htmlspecialchars` ทุกจุด เนื้อหา Prompt แสดงเป็นข้อความใน `<pre>`/`<textarea>`; JavaScript ใช้ `textContent` ไม่ใช้ `innerHTML` กับข้อมูลผู้ใช้; Content-Security-Policy ไม่อนุญาต inline script
- CSRF Token ทุก POST (รวม endpoint คัดลอก) และไม่มีการเปลี่ยนข้อมูลผ่าน GET (ได้ 405)
- ตรวจการเข้าสู่ระบบ บทบาท สถานะบัญชี และความเป็นเจ้าของฝั่งเซิร์ฟเวอร์ทุกคำขอ Prompt ที่ไม่มีสิทธิ์ดูตอบ 404 เพื่อไม่เปิดเผยว่ามีอยู่
- `session_regenerate_id()` หลังเข้าสู่ระบบและเปลี่ยนรหัสผ่าน; cookie `HttpOnly` + `SameSite=Lax` (+ `Secure` เมื่อเปิดใน config); session หมดอายุเมื่อไม่ใช้งาน 2 ชั่วโมง; เปลี่ยนรหัสผ่านแล้ว session อื่นหมดสิทธิ์
- จำกัด login ล้มเหลว 5 ครั้ง/บัญชี+IP และ 20 ครั้ง/IP ใน 15 นาที
- เปลี่ยนรหัสผ่านต้องยืนยันรหัสผ่านเดิม
- ลืมรหัสผ่าน: token สุ่ม 256 บิต เก็บเฉพาะ hash ใช้ได้ครั้งเดียว หมดอายุ 60 นาที ขอใหม่แล้วลิงก์เก่าหมดสิทธิ์ ตอบข้อความเดียวกันไม่ว่าจะมีบัญชีหรือไม่ จำกัด 3 ครั้ง/อีเมล และ 10 ครั้ง/IP ต่อชั่วโมง ลิงก์ในอีเมลสร้างจาก URL เว็บไซต์ที่ตั้งไว้ (ไม่ใช่ Host header) และ token ถูกย้ายออกจาก URL ทันทีที่เปิด ตั้งรหัสใหม่แล้ว session เดิมทุกเครื่องหมดสิทธิ์
- ผู้ดูแลระงับ/ลบ/ลดสิทธิ์ตัวเองไม่ได้ และระบบต้องเหลือผู้ดูแลที่ใช้งานได้อย่างน้อย 1 บัญชี
- ข้อผิดพลาดบันทึกใน `storage/logs/` (ไม่เก็บค่าพารามิเตอร์ใน stack trace) ผู้ใช้เห็นเพียงข้อความทั่วไป
- ไฟล์ตั้งค่าอยู่นอก `public` และถูกปฏิเสธการเข้าถึงด้วย `.htaccess`
- คัดลอก Prompt ต้องเข้าสู่ระบบ: ผู้เยี่ยมชมได้เนื้อหาเพียงบางส่วน (ไม่เกิน 40% และไม่เกิน 120 ตัวอักษร) และ endpoint คัดลอกตอบ 401
- ไฟล์ภาพ: ตรวจชนิดจากเนื้อไฟล์, ตัด metadata, ชื่อไฟล์สุ่ม, ส่งพร้อม `X-Content-Type-Options: nosniff` และ `Content-Security-Policy: sandbox`

## 7.1 อัปเดตเว็บบนโฮสต์ (krusomkhuan.com)

```
python deployuild_update.py
python deploy\check_live.py https://krusomkhuan.com/prompthub/public
```
- `build_update.py` สร้าง `deploy/prompthub-update-<จาก>-to-<เป็น>.zip` เฉพาะไฟล์ที่เปลี่ยน พร้อม `อ่านก่อนอัปโหลด.txt` (ไม่มี `config/config.php`, `storage/`, ไฟล์ `.sql`) — แก้รายการไฟล์ใน `FILES` เมื่อออกรุ่นใหม่
- `check_live.py` ตรวจเว็บจริงแบบอ่านอย่างเดียว: เวอร์ชัน, HTTPS, CSP form-action, ปุ่ม Google, redirect URI และคำตอบของ Google (เช่น `redirect_uri_mismatch`, `invalid_client`) — ตรวจได้ถึงหน้าเลือกบัญชี ส่วนการกดอนุญาตต้องลองเอง

## 8. ทดสอบ

ดูผลการทดสอบจริงและส่วนที่ยังไม่ได้ทดสอบใน [TESTING.md](TESTING.md)

```bash
python tests\integration.py --allow-test-data
python tests\password_reset.py --allow-test-data
python tests\features.py --allow-test-data
python tests\browser_resize.py --allow-test-data
python tests\server_resize.py --allow-test-data
python tests\drive.py --allow-test-data
C:\xampp\php\php.exe tests\image_resize.php
```

`password_reset.py` ใช้เซิร์ฟเวอร์ SMTP จำลองบน 127.0.0.1:2525 (ไม่ส่งอีเมลออกจริง) และแก้ส่วน `mail` ใน `config.php` ชั่วคราว แล้วคืนค่าเดิมเมื่อจบ `features.py` ทดสอบภาพตัวอย่าง การคัดลอกเฉพาะสมาชิก และ Google โดยใช้เซิร์ฟเวอร์ Google จำลองบน 127.0.0.1:2526 (แก้ส่วน `google` ใน `config.php` ชั่วคราวเช่นกัน) และทดสอบภาพโปรไฟล์ `browser_resize.py` เปิด Chrome แบบ headless เพื่อทดสอบการย่อภาพในเบราว์เซอร์จริง `server_resize.py` ส่งภาพใหญ่ตรงถึงเซิร์ฟเวอร์เพื่อทดสอบการย่อด้วย GD ของ Apache (ต้องเปิด GD) `image_resize.php` ทดสอบการย่อ/หมุน/ตัดภาพด้วย GD (ทดสอบกรณีไม่มี GD ด้วย `php -c ไฟล์ini-ที่ปิด-gd tests\image_resize.php`)

ชุดทดสอบสร้างบัญชีและข้อมูลชั่วคราว แล้วลบเองเมื่อจบ ใช้กับเครื่องทดลองเท่านั้น (ตั้ง `PROMPT_HUB_BASE` และ `PROMPT_HUB_PHP` ถ้าที่อยู่ต่างจากค่าเริ่มต้น)

## 9. คู่มือใช้งาน

ดู [docs/USER_GUIDE.md](docs/USER_GUIDE.md)

## 10. สกิลสำหรับ ChatGPT

`skills/prompt-hub-skill.zip` ให้ ChatGPT รู้จักระบบนี้ (โครงสร้าง กฎความปลอดภัย ฐานข้อมูล การทดสอบ และซอร์สโค้ด) เพื่อช่วยพัฒนาต่อ วิธีติดตั้งดู [skills/README.md](skills/README.md) สร้างใหม่หลังแก้โค้ดด้วย `python skillsuild_skill.py`
