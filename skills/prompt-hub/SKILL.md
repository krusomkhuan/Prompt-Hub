---
name: prompt-hub
description: Build, extend, fix, install or explain "Prompt Hub" (เปลี่ยนไอเดียให้เป็นจริงด้วย AI) — a Thai AI-prompt library web app written in PHP 8 OOP + MySQLi + Bootstrap 5.3 for XAMPP, by ครูสมควร สากำ. Use when the user asks to add a feature, change a page, write a migration, debug, review security, install on XAMPP, configure Google login / SMTP / GD, or rebuild the system from scratch. ใช้เมื่อผู้ใช้ต้องการพัฒนา แก้ไข ติดตั้ง หรืออธิบายระบบ Prompt Hub
---

# Prompt Hub — คู่มือสำหรับผู้ช่วย AI ที่พัฒนาระบบนี้

Prompt Hub คือเว็บคลัง Prompt ภาษาไทย (เวอร์ชัน 1.11.0) ผู้เยี่ยมชมค้นหาและดู Prompt ได้บางส่วน สมาชิกคัดลอก เติมตัวแปร `[ชื่อตัวแปร]` เก็บรายการโปรด สร้างและส่ง Prompt ให้ผู้ดูแลตรวจอนุมัติ สะสมแต้มรับเหรียญ ผู้ดูแลจัดการทุกอย่างในหลังบ้าน

**เทคโนโลยี:** PHP 8.0+ (OOP, ไม่มี framework, ไม่มี Composer) · MySQL 8 / MariaDB 10.4 ผ่าน **MySQLi prepared statements เท่านั้น** · Bootstrap 5.3.3 + Bootstrap Icons (โหมดมืด โทนม่วง–น้ำเงิน) · ไม่มี inline JavaScript (มี CSP) · ทำงานบน XAMPP (Windows)

## ไฟล์ในสกิลนี้

| ไฟล์ | อ่านเมื่อ |
|---|---|
| `references/architecture.md` | ต้องรู้โครงสร้างโฟลเดอร์ การไหลของคำขอ และหน้าที่ของแต่ละคลาส |
| `references/conventions.md` | **ก่อนเขียนโค้ดทุกครั้ง** — รูปแบบ Controller/Service/Repository/View พร้อมตัวอย่างจริง |
| `references/security.md` | งานที่แตะการเข้าสู่ระบบ ฟอร์ม ไฟล์อัปโหลด สิทธิ์ หรือข้อมูลส่วนบุคคล |
| `references/features.md` | ต้องรู้กฎธุรกิจ: สถานะ Prompt, แต้ม/เหรียญ, ภาพ, Google, ลืมรหัสผ่าน, PDPA |
| `references/database.md` + `references/schema.sql` | ออกแบบตาราง เขียน query หรือ migration |
| `references/routes.php` | เพิ่ม/ค้นหาเส้นทาง (route) และสิทธิ์ของแต่ละหน้า |
| `references/testing.md` | เขียนหรือรันชุดทดสอบ |
| `references/deployment.md` | ติดตั้ง ตั้งค่า config, SMTP, Google, GD, สำรองข้อมูล |
| `assets/prompt-hub-source.zip` | ซอร์สโค้ดทั้งระบบ (ไม่มีรหัสผ่าน/ไฟล์ตั้งค่าจริง) — แตกไฟล์เพื่ออ่านหรือแก้โค้ดจริงแทนการเดา |

ถ้ามีเครื่องมือรันโค้ด (Code Interpreter) ให้แตก `assets/prompt-hub-source.zip` แล้วเปิดไฟล์จริงก่อนแก้ไข อย่าเขียนโค้ดจากความจำเมื่อมีไฟล์ให้อ่าน

## กฎที่ห้ามละเมิด

1. **ฐานข้อมูล:** ใช้ `PromptHub\Core\Database` (MySQLi) และ prepared statements ทุกค่าที่มาจากผู้ใช้ **ห้ามใช้ PDO** ห้ามต่อสตริง SQL ด้วยค่าจากผู้ใช้ คอลัมน์สำหรับ ORDER BY ต้องมาจาก whitelist
2. **คำขอที่เปลี่ยนข้อมูล:** ต้องเป็น POST และผ่าน CSRF (Router ตรวจให้อัตโนมัติ — ฟอร์มต้องมี `<?= csrf_field() ?>`) ห้ามเปลี่ยนข้อมูลผ่าน GET
3. **การแสดงผล:** ทุกค่าใน view ผ่าน `e()` ห้าม `innerHTML` กับข้อมูลผู้ใช้ ห้าม `<script>` หรือ `style="..."`/`onclick` แบบ inline (CSP จะบล็อก) — JavaScript อยู่ใน `public/assets/js/app.js` เท่านั้น ตั้งค่าแบบไดนามิกผ่าน data-attribute
4. **สิทธิ์:** กำหนดใน `app/routes.php` (`any|guest|member|admin`) และตรวจความเป็นเจ้าของฝั่งเซิร์ฟเวอร์ (`PromptPolicy`) สิ่งที่ไม่มีสิทธิ์ดูให้ตอบ **404** ไม่ใช่ 403 เพื่อไม่เปิดเผยว่ามีอยู่
5. **บัญชีผู้ดูแล:** ห้ามฝังบัญชี/รหัสผ่านเริ่มต้นในโค้ด สร้างผ่าน `php bin/create-admin.php` เท่านั้น ระบบต้องเหลือผู้ดูแลที่ใช้งานได้อย่างน้อย 1 คน
6. **ข้อความและเวลา:** UI ภาษาไทยทั้งหมด · ฐานข้อมูล utf8mb4 · เก็บเวลาเป็น UTC แสดงผลด้วย `thai_date()` (Asia/Bangkok, พ.ศ.)
7. **ความเข้ากันได้:** โค้ดต้องรันได้บน PHP 8.0 (ห้าม enum, readonly property, `never`, `new` ใน initializer) ไม่เพิ่ม Composer dependency
8. **ไฟล์อัปโหลด:** ตรวจชนิดจากเนื้อไฟล์ (`finfo` + `getimagesize`) ตัด metadata เก็บนอก `public` ชื่อสุ่ม ส่งผ่าน route ที่ตรวจสิทธิ์ พร้อม `X-Content-Type-Options: nosniff` และ `Content-Security-Policy: sandbox`
9. **ความจริงใจ:** ห้ามบอกว่าทดสอบผ่านถ้ายังไม่ได้รันจริง ห้ามส่งโค้ดจำลอง/TODO แทนฟังก์ชันหลัก บอกให้ชัดว่าส่วนไหนยังไม่ได้ทดสอบ

## ขั้นตอนเพิ่มฟีเจอร์ (ทำตามลำดับ)

1. **ฐานข้อมูล** — ถ้ามีคอลัมน์/ตารางใหม่: แก้ `database/schema.sql` (สำหรับติดตั้งใหม่) **และ** สร้าง `database/migrations/00N_ชื่อ.sql` (สำหรับระบบที่ติดตั้งแล้ว) เลขต่อจากไฟล์ล่าสุด (ล่าสุดคือ `008_drive.sql`)
2. **Repository** (`app/Repositories`) — query ทั้งหมดของตารางนั้น
3. **Service** (`app/Services`) — กฎธุรกิจ, transaction, การตรวจสิทธิ์ที่ซับซ้อน, งานไฟล์ (ลบไฟล์ **หลัง** commit)
4. **Route** ใน `app/routes.php` → **Controller** (บางที่สุด: อ่าน input → เรียก service → render/redirect)
5. **View** (`views/...`) + CSS/JS ใน `public/assets`
6. **การดูแล** — ถ้าผู้ดูแลทำรายการใหม่ ให้บันทึกใน `admin_activity_logs` (เพิ่ม label ใน `ActivityLogRepository::ACTIONS`)
7. **ทดสอบ** — เพิ่มข้อทดสอบ HTTP ใน `tests/*.py` ตามรูปแบบใน `references/testing.md` แล้วรันจริง
8. **เอกสาร** — `CHANGELOG.md`, ขยับเวอร์ชันใน `app/version.php` (ฟีเจอร์ใหม่ = MINOR, แก้บั๊ก = PATCH), README, `docs/USER_GUIDE.md`, `TESTING.md`

## รูปแบบคำตอบที่ผู้ใช้ต้องการ

- ตอบเป็นภาษาไทย ใช้ศัพท์เทคนิคภาษาอังกฤษได้
- เมื่อแก้โค้ด ให้บอกชื่อไฟล์เต็มและแสดงโค้ดที่ต้องเพิ่ม/แทนที่ทั้งเมธอดหรือทั้งบล็อก ไม่ใช่แค่ส่วนย่อยที่เดาตำแหน่งยาก
- ถ้ามี migration ให้ให้ทั้งไฟล์ migration และคำสั่งรัน:
  `C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 prompt_hub < database\migrations\00N_ชื่อ.sql`
- จบด้วยรายการ "ต้องทดสอบอะไร" และ "สิ่งที่ยังไม่ได้ทดสอบ" อย่างตรงไปตรงมา
- ถ้าคำขอกำกวมจนเลือกผิดแล้วเสียงานมาก (เช่น นับแต้มจากอะไร ใครเห็นข้อมูล) ให้ถามก่อน ถ้าไม่มากให้เลือกค่าที่ปลอดภัยและบอกว่าเลือกอะไร
