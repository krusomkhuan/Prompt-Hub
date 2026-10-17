# Prompt Hub · ช่วยกันคิด

เว็บคลังคำสั่ง AI ภาษาไทยสำหรับ PHP 8.2+ / MySQL 8.0+ หรือ MariaDB 10.6+ ใช้ MySQLi Prepared Statements เท่านั้น ไม่มี Composer dependency และไม่มีการอัปโหลดไฟล์

## โครงสร้าง

```text
app/                 DB, Auth, Prompts, Admin และ bootstrap
public/              index.php และ assets (DocumentRoot ที่แนะนำ)
views/               layout และหน้าจอ
config/example.php   ตัวอย่างการตั้งค่า ไม่มีข้อมูลลับ
config/local.php     การตั้งค่าจริง (Git ignore)
database/schema.sql  โครงสร้างและค่าเว็บไซต์เริ่มต้น
database/demo.sql    Prompt ภาษาไทยที่แต่งใหม่ 16 รายการ (ข้อมูลสาธิต)
bin/                 CLI ติดตั้งฐานข้อมูลและสร้างผู้ดูแล
tests/               ทดสอบ HTTP และฐานข้อมูลจริง
storage/             log ภายใน (Git ignore)
```

เวลาใน DB ใช้ UTC ทุก connection และแสดง Asia/Bangkok ตาราง users, categories, prompts, tags, prompt_tags, ai_tools, prompt_ai_tools, favorites, site_settings, admin_activity_logs และ login_attempts ใช้ InnoDB และ utf8mb4 ตารางเชื่อมใช้ composite primary key ป้องกันซ้ำ ลบ Prompt แล้วลบแท็กเชื่อม/เครื่องมือเชื่อม/รายการโปรดตามไปด้วย ลบแท็กหรือเครื่องมือจะลบเฉพาะความสัมพันธ์ ลบหมวดหมู่ที่มี Prompt ต้องย้ายไปหมวดหมู่ที่เปิดใช้งานก่อน

## ติดตั้ง XAMPP บน Windows

1. ใช้ XAMPP ที่มี PHP 8.2 ขึ้นไป เปิด Apache และ MySQL ตรวจว่า mysqli, mysqlnd และ mbstring เปิดใช้งาน
2. ใช้โค้ดที่ `C:\xampp\htdocs\prompt_hub` (ถ้ามีไฟล์เดิมให้สำรองและรวมไฟล์อย่างระมัดระวัง)
3. คัดลอก `config\example.php` เป็น `config\local.php` ตั้ง host, port, username และ password ของ MySQL ให้ถูกต้อง database ใช้ `prompt_hub` และ base_path ใช้ `/prompt_hub/public` ห้าม commit ไฟล์นี้
4. เปิด PowerShell:

```powershell
cd C:\xampp\htdocs\prompt_hub
C:\xampp\php\php.exe bin\install.php
cmd /c "C:\xampp\mysql\bin\mysql.exe -u root -p --default-character-set=utf8mb4 prompt_hub < database\demo.sql"
C:\xampp\php\php.exe bin\create-admin.php
```

`install.php` ใช้เฉพาะฐานข้อมูลใหม่ ไม่ใช่ migration และต้องมีสิทธิ์ CREATE TABLE/CREATE DATABASE การติดตั้งซ้ำบนฐานข้อมูลที่มีตารางจะหยุดด้วย error เพื่อไม่ทำลายข้อมูล การนำเข้า demo เป็นทางเลือกและทำครั้งเดียว บัญชีผู้เขียน demo ถูกปิดและไม่มีรหัสผ่านที่ใช้ login ได้ ผู้ดูแลต้องกำหนด username/email/password ใหม่เองใน CLI รหัสผ่าน 12 ตัวขึ้นไปและไม่เกิน 72 ไบต์ บน Windows CLI แสดงตัวอักษรที่พิมพ์ จึงควรรันในพื้นที่ส่วนตัว

5. เปิด `http://localhost/prompt_hub/public/` แล้วเข้าสู่ระบบด้วยบัญชีที่เพิ่งสร้าง
6. ให้ Apache เขียน `storage` ได้ เปิด `AllowOverride All` เพื่อใช้ `.htaccess` โฟลเดอร์รากปฏิเสธ HTTP และ public อนุญาตเฉพาะส่วนเว็บ ตรวจว่า URL `/prompt_hub/config/local.php`, `/prompt_hub/database/schema.sql` และ `/prompt_hub/storage/error.log` ถูกปฏิเสธ หากโฮสต์ไม่อ่าน `.htaccess` ต้องตั้ง DocumentRoot ไป `public` โดยตรง

### แนะนำสำหรับใช้งานจริง

ตั้ง Apache VirtualHost DocumentRoot เป็น `C:/xampp/htdocs/prompt_hub/public` และ `<Directory ...>` เป็น `Require all granted`, `Options -Indexes`, `AllowOverride All` ตั้ง base_path เป็น `''` เมื่อเสิร์ฟที่ root ของโดเมน ใช้ HTTPS และ secure_cookie=true ใช้บัญชี DB สำหรับแอปที่มีเฉพาะ SELECT/INSERT/UPDATE/DELETE หลังติดตั้ง เก็บไฟล์ตั้งค่า/ฐานข้อมูลสำรองนอก public และตั้งสิทธิ์อ่านให้เฉพาะ service account ข้อผิดพลาดภายในบันทึก storage/error.log ไม่แสดง SQL ให้ผู้ใช้

Bootstrap 5.3.3, Bootstrap Icons 1.11.3 และ Sarabun 5.1.1 (fontsource package) เก็บไว้ใน public/assets/vendor พร้อม license ใช้งานหน้าจอได้โดยไม่ต้องเชื่อมต่อ CDN

## ใช้งาน

ผู้เยี่ยมชม: ค้นชื่อ คำอธิบาย เนื้อหา หรือแท็ก กรองหมวดหมู่/แท็ก/AI พร้อมกัน เรียงล่าสุดหรือคัดลอกมากที่สุด หน้าละ 12 รายการ เปิดรายละเอียด เติม `[ชื่อตัวแปร]` และคัดลอก เนื้อหาต้นฉบับไม่เปลี่ยน

สมาชิก: สมัครและ login → ภาพรวม → เพิ่ม Prompt เลือกส่วนตัวเพื่อเก็บเป็นร่าง หรือเลือกสาธารณะแล้วส่งตรวจ รายการร่าง/รอตรวจ/ไม่อนุมัติไม่แสดงต่อสาธารณะ แก้ไขรายการที่เผยแพร่แล้วจะพักเผยแพร่และกลับรอตรวจ ดูเหตุผลไม่อนุมัติในรายละเอียด จัดการรายการของตัวเอง รายการโปรด และโปรไฟล์ การเปลี่ยนรหัสผ่านต้องยืนยันรหัสเดิม

ผู้ดูแล: เข้าเมนูหลังบ้านเพื่อจัดการสมาชิก บทบาท การระงับ และการลบ (Prompt/รายการโปรดของสมาชิกจะถูกลบตาม) จัดการหมวดหมู่/แท็ก/เครื่องมือ ตรวจรายการรอตรวจ เปิดรายละเอียดเพื่ออนุมัติหรือระบุเหตุผลไม่อนุมัติ ตั้งค่าชื่อ/สโลแกน/คำอธิบาย และดูบันทึกกิจกรรม ห้ามระงับ/ลดสิทธิ์บัญชีตัวเอง หรือทำให้ไม่มีผู้ดูแลที่ใช้งานได้ หมวดหมู่ปิดไม่ปรากฏในฟอร์มเพิ่มใหม่ แต่ข้อมูลเดิมอ่านได้

สโลแกนเริ่มต้นใช้ “ช่วยกันคิด” ตามข้อกำหนดออกแบบ เปลี่ยนเป็น “คิดให้ด้วย” ได้ที่หลังบ้าน > ตั้งค่า

## Docker สำหรับการพัฒนา

```sh
docker build -t prompt-hub-php .
docker network create prompt-hub-net
docker run -d --name prompt-hub-db --network prompt-hub-net -e MARIADB_RANDOM_ROOT_PASSWORD=1 -e MARIADB_DATABASE=prompt_hub -e MARIADB_USER=prompt_hub -e MARIADB_PASSWORD=YOUR_LOCAL_PASSWORD mariadb:11.4
```

สร้าง config/local.php โดย host=`prompt-hub-db`, username=`prompt_hub`, password ตรงกับที่กำหนด และ base_path=`''` รอ DB พร้อม แล้วรัน:

```sh
docker run --rm --network prompt-hub-net -v "$PWD:/app" prompt-hub-php php bin/install.php
# นำเข้า database/demo.sql ผ่าน client ที่ตั้งรหัสผ่านอย่างปลอดภัย
docker run --rm -it --network prompt-hub-net -v "$PWD:/app" prompt-hub-php php bin/create-admin.php
docker run -d --name prompt-hub-web --network prompt-hub-net -p 127.0.0.1:8080:8080 -v "$PWD:/app" prompt-hub-php
```

สำหรับนำเข้า demo แบบไม่ interactive ให้ใช้ client defaults file ที่สิทธิ์จำกัดหรือเครื่องมือ DB ของคุณ ไม่ใส่รหัสผ่านใน command history ตัว server `php -S` ใช้เฉพาะ development ห้ามใช้ production

## ทดสอบ

อ่านผลการรันจริงและข้อจำกัดที่ [TESTING.md](TESTING.md) ชุด HTTP integration ใช้ฐานข้อมูลสำหรับทดสอบแยกต่างหากเท่านั้น ดู `tests/README.md`

## เริ่มคลาวด์ซ้ำ

`bash bin/dev-setup.sh` สร้าง image/network/DB/เว็บแบบทำซ้ำได้ และตรวจหน้ารายการ สคริปต์รักษา config/local.php เดิม ไม่สร้างบัญชีผู้ดูแลอัตโนมัติ เมื่อเริ่มใหม่ที่ยังไม่มี config จะสร้างรหัสผ่าน DB สุ่มและเก็บในไฟล์ local ที่ Git ignore

Cloud task แยกสภาพแวดล้อมให้อยู่แล้ว ให้ใช้ checkout เดิมใน `/workspace/Prompt-Hub` ไม่ต้องสร้าง Git worktree กระบวนการเว็บและฐานข้อมูลต้องเริ่มใหม่เมื่อ process ไม่ได้คงอยู่จาก snapshot
