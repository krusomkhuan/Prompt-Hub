# สถาปัตยกรรม Prompt Hub

## การไหลของคำขอ

```
เบราว์เซอร์ → public/index.php?r=ชื่อเส้นทาง
  → app/bootstrap.php (autoload PromptHub\ → app/, helpers, version, load_config)
  → ส่ง security headers (CSP, X-Frame-Options DENY, nosniff, Referrer-Policy, no-store)
  → App::boot(config)  สร้าง Database, Session, Auth, View, settings
  → Router::dispatch(route, method)
       1) route ต้องอยู่ใน app/routes.php และ method ตรง (ไม่ตรง → 405)
       2) POST ทุกตัวต้องผ่าน Csrf::verify() (ไม่ผ่าน → 403)
       3) สิทธิ์: any | guest (ล็อกอินแล้ว redirect ไป dashboard) | member (ไม่ล็อกอิน → 401) | admin (403)
       4) new Controller($app)->action()
  → ข้อผิดพลาด: HttpException → หน้า error ตาม status, ValidationException → 422,
     อื่น ๆ → Logger::error() ลง storage/logs/ แล้วแสดงข้อความทั่วไป (ไม่เปิดเผย SQL)
```

ไม่ใช้ mod_rewrite ลิงก์ทุกตัวสร้างด้วย `url('ชื่อเส้นทาง', [...])`

## โครงสร้างโฟลเดอร์

```
prompt_hub/
├─ public/                 ← DocumentRoot (เข้าถึงจากเว็บได้เฉพาะโฟลเดอร์นี้)
│  ├─ index.php            front controller + security headers
│  └─ assets/css/app.css, assets/js/app.js
├─ app/
│  ├─ bootstrap.php, helpers.php, routes.php, version.php
│  ├─ Core/                App, Database (MySQLi), Session, Csrf, Auth, Router, View,
│  │                       Validator, ValidationException, HttpException, Logger, Mailer (SMTP)
│  ├─ Repositories/        Prompt, User, Category, Tag, AiTool, Favorite, Setting,
│  │                       ActivityLog, LoginAttempt  — SQL ทั้งหมดอยู่ที่นี่
│  ├─ Services/            PromptService (บันทึก/สถานะ/transaction), PromptPolicy (สิทธิ์),
│  │                       AuthService, PasswordResetService, GoogleAuthService,
│  │                       AdminService (จัดการสมาชิก/หมวดหมู่), ImageService (ภาพตัวอย่าง),
│  │                       AvatarService (ภาพโปรไฟล์), ImageResizer (GD), BadgeService (แต้ม/เหรียญ),
│  │                       GoogleDriveService (เก็บภาพตัวอย่างใน Google Drive)
│  ├─ Controllers/         Controller (base), Home, Prompt, Auth, Member, Admin
│  └─ Cli/Console.php      ตัวช่วย CLI (อ่านรหัสผ่านแบบซ่อนตัวอักษร ฯลฯ)
├─ views/                  layouts/main.php, partials/, public/, auth/, member/, admin/, errors/
├─ config/                 config.example.php (คัดลอกเป็น config.php — ไม่อยู่ใน git)
├─ database/               schema.sql, demo.sql (16 Prompt ตัวอย่าง), migrations/001–005
├─ bin/                    install.php, create-admin.php, resize-images.php, drive-sync.php (CLI เท่านั้น)
├─ storage/                logs/, uploads/prompts/, uploads/avatars/, google-drive.json (token Drive) (เว็บเข้าไม่ได้)
├─ tests/                  integration.py, password_reset.py, features.py, browser_resize.py,
│                          image_resize.php, fixtures.php, fake_smtp.py, fake_google.py
└─ docs/                   USER_GUIDE.md, คู่มือ Word/PDF, screenshots/
```

ทุกโฟลเดอร์นอก `public/` มี `.htaccess` ที่ `Require all denied` และ `.htaccess` ที่ราก ปฏิเสธไฟล์ `.md .sql .log .py .json .ini` และไฟล์ที่ขึ้นต้นด้วยจุด

## หน้าที่ของแต่ละชั้น

| ชั้น | ทำ | ไม่ทำ |
|---|---|---|
| Controller | อ่าน `$_GET/$_POST/$_FILES` ผ่านตัวช่วย (`queryId`, `postId`, `query`, `queryIn`, `page`) เรียก service แล้ว `render()` / `redirect()` / `json()` / `flash()` | เขียน SQL, กฎธุรกิจซับซ้อน |
| Service | กฎธุรกิจ, `Validator`, `$db->transaction()`, ตรวจสิทธิ์, จัดการไฟล์ (ลบไฟล์หลัง commit) | แสดงผล HTML |
| Repository | SQL ทั้งหมดผ่าน `Database` | ตรวจสิทธิ์ |
| View | แสดงผลด้วย `e()` และ helper | query ฐานข้อมูล (ยกเว้น `$app->setting()`) |

## ตัวแปรที่ทุก view ใช้ได้

`$app` (App: `->setting('key')`, `->config`), `$auth` (`->user()`, `->check()`, `->isAdmin()`), `$route` (ชื่อเส้นทางปัจจุบัน), `$view` (`->partial('partials/x', [...])`) และ `$title` (ชื่อหน้า)

## ค่าตั้งค่าเว็บไซต์ (ตาราง site_settings)

แก้ได้ที่ หลังบ้าน › ตั้งค่าเว็บไซต์ — คีย์อยู่ใน `SettingRepository::KEYS`:
`site_name, site_url, site_slogan, site_description, hero_badge, developer_name, copyright_owner, copyright_start_year, privacy_controller, privacy_contact`
`site_url` = URL จริงของเว็บไซต์ → `App::publicUrl()` (ว่าง = `app_url` + `base_url`) และ `absolute_url($route, $params)` ใช้กับลิงก์ในอีเมล, Google redirect URI, canonical/og — ลิงก์ภายในใช้ `url()` (base_url) เสมอ
เพิ่มคีย์ใหม่ต้องแก้ 4 จุด: `KEYS`, validator ใน `AdminController::settingsSave`, ฟอร์ม `views/admin/settings.php`, และ INSERT ใน schema.sql + migration
