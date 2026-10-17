# ผลการตรวจสอบจริง

ทดสอบวันที่ 17 ตุลาคม 2026 (Asia/Bangkok) ด้วย PHP 8.2.34, MariaDB 11.4, Chromium/Playwright บน Linux Docker

| การตรวจ | ผล |
|---|---|
| HTTP integration `python3 tests/integration.py --allow-test-data` | ผ่าน 88 ข้อ |
| Browser `node tests/browser.cjs` | ผ่าน 10 ข้อ |
| PHP syntax ของไฟล์แอป/CLI/views/tests และ config ตัวอย่าง | ผ่านทุกไฟล์ |
| JavaScript syntax `node --check public/assets/app.js` | ผ่าน |
| Apache 2 / PHP 8.2 เสิร์ฟ `/public/index.php` และ Bootstrap ในเครื่อง | HTTP 200 |
| Apache ป้องกัน `/config/local.php`, `/database/schema.sql`, `/storage/error.log`, `/app/Core.php` | HTTP 403 ทั้ง 4 เส้นทาง |
| ติดตั้ง schema + demo ผ่าน dev-init บน MariaDB ว่าง | สำเร็จ 16 Prompt / บัญชี demo ที่ปิดใช้งาน 1 บัญชี |
| รัน dev-init ซ้ำบนฐานข้อมูลเดียวกัน | จำนวนเดิม 16 / 1 ไม่ import ซ้ำ |
| รัน bin/dev-setup.sh กับ instance ที่ติดตั้งแล้ว | สำเร็จ ตรวจพบหน้ารายการและข้อมูลภาษาไทย |

HTTP ครอบคลุม: สมัคร/login/logout, username/email ซ้ำ, สร้างผู้ดูแล CLI และเข้าใช้งาน, สิทธิ์สมาชิกกับหลังบ้าน, ownership ของการแก้ไข/ลบ, private/pending detail และ copy/favorite endpoints, CSRF, XSS escaping, ภาษาไทย/บรรทัดเดิม, pending → rejected พร้อมเหตุผล → resubmit → published → edit → pending, รายการโปรดซ้ำและการซ่อนหลังพักเผยแพร่, ค้นหา/หมวด/แท็ก/AI/เรียงร่วมกัน, pagination และพารามิเตอร์ไม่ถูกต้อง, สถิติคัดลอก, ระงับ/เปิดใช้/ลบสมาชิกและ session เดิม, การป้องกันระงับ/ลบผู้ดูแลตัวเอง, เปลี่ยนรหัสผ่านพร้อมยืนยันเดิม, login rate limit, taxonomy CRUD, ย้าย Prompt ก่อนลบหมวดหมู่, หมวดหมู่ปิด, settings และ admin logs, cascade favorites

Browser ครอบคลุม: responsive หน้าแรก 390/768/1440, CSS ในเครื่องโดยบล็อก network ภายนอก, ตัวแปรและ HTML เป็นข้อความโดยไม่เปลี่ยนต้นฉบับ, clipboard จริง, รายละเอียดมือถือไม่ล้นแนวนอน และไม่มี JavaScript runtime error

## ยังไม่ได้ทดสอบ

- XAMPP/Windows ที่ `C:\xampp\htdocs\prompt_hub` บนเครื่องผู้ใช้โดยตรง และ MySQL 8 แท้ (ใช้ MariaDB ที่รองรับ MySQL protocol)
- Safari, Firefox, อุปกรณ์มือถือจริง และการตรวจ accessibility แบบเต็ม
- HTTPS และ secure cookies บน production domain
- concurrent load, deadlock recovery และการแข่งขันเปลี่ยนผู้ดูแลพร้อมกัน (มี transaction + row locks และกฎผู้ดูแลคนสุดท้ายในโค้ด แต่ยังไม่มี stress test)
- การกู้ backup, dataset ขนาดใหญ่ และพฤติกรรมเมื่อเครือข่าย/DB ล่มระหว่าง transaction

ไฟล์ TESTING นี้บันทึกผลใน instance ปัจจุบัน ไม่ได้หมายความว่าระบบถูก deploy บนเครื่องผู้ใช้หรือเผยแพร่ cloud snapshot แล้ว
