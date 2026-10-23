# การทดสอบ

ทดสอบกับเว็บจริงผ่าน Apache (HTTP) แล้วตรวจผลในฐานข้อมูล — ใช้กับเครื่องทดลองเท่านั้น ทุกชุดสร้างบัญชีชั่วคราว `t_admin_xxxx`, `t_member_xxxx`, `t_other_xxxx` (รหัสผ่านสุ่ม) และลบเองเมื่อจบ

```bash
python tests\integration.py --allow-test-data      # 150 ข้อ: ระบบหลัก สิทธิ์ CSRF ค้นหา หลังบ้าน PDPA เวอร์ชัน
python tests\password_reset.py --allow-test-data   # 27 ข้อ: ลืมรหัสผ่าน (SMTP จำลอง 127.0.0.1:2525)
python tests\features.py --allow-test-data         # 73 ข้อ: ภาพโปรไฟล์ ภาพตัวอย่าง คัดลอก แต้ม Google (จำลอง 127.0.0.1:2526)
python tests\browser_resize.py --allow-test-data   # 8 ข้อ: ย่อภาพใน Chrome headless แล้วส่งฟอร์มจริง
python tests\server_resize.py --allow-test-data    # 6 ข้อ: ย่อภาพด้วย GD ของ Apache (ต้องเปิด GD)
python tests\drive.py --allow-test-data            # 34 ข้อ: Google Drive (เซิร์ฟเวอร์จำลอง 127.0.0.1:2527)
C:\xampp\php\php.exe tests\image_resize.php      # 19 ข้อเมื่อมี GD / 6 ข้อเมื่อไม่มี GD (ใช้ -c ini ที่ปิด gd)
```
ตั้ง `PROMPT_HUB_BASE` (ค่าเริ่มต้น `http://localhost/prompt_hub/public/index.php`) และ `PROMPT_HUB_PHP` ถ้าต่างจากเครื่องนี้

## ตัวช่วย

- `tests/fixtures.php create` → JSON `{suffix, password, admin:{id,username}, member:{...}, other:{...}}`
- `tests/fixtures.php query` ← JSON `{"sql": "SELECT ...", "params": [...]}` (SELECT เท่านั้น)
- `tests/fixtures.php cleanup SUFFIX` ลบบัญชี ข้อมูล และไฟล์ภาพที่ชุดทดสอบสร้าง
- ชุดที่ต้องเปิด SMTP/Google แก้ `config/config.php` ชั่วคราวและคืนค่าใน `finally` (ตรวจด้วย `cmp` หลังรัน)

## รูปแบบข้อทดสอบ (Python, urllib ล้วน)

```python
guest, member, admin = Client(), Client(), Client()
member.login(fx['member']['username'], PW)
code, html, url, headers = member.post('profile.avatar.delete', {})          # ใส่ CSRF ให้อัตโนมัติ
code, html, url, _ = member.multipart('profile.avatar', [], [('avatar', 'a.png', png_bytes, 'image/png')])
check(code == 422 and 'เกิน 2 MB' in html, 'ปฏิเสธภาพโปรไฟล์เกิน 2 MB')
check(scalar('SELECT avatar_file FROM users WHERE id = ?', uid) is None, '...')
```
`check(ok, 'ชื่อข้อทดสอบภาษาไทย')` หยุดทันทีเมื่อไม่ผ่าน · ทดสอบทั้งเส้นทางสำเร็จ เส้นทางผิด (422/403/404/405) และผลในฐานข้อมูล/ไฟล์

## สิ่งที่ต้องตรวจทุกครั้งหลังรัน

1. ทุกชุดผ่านครบ (รายงานตัวเลขจริง เช่น “150/150”)
2. `storage/logs/` ไม่มีข้อผิดพลาดใหม่ (ยกเว้นรายการ SMTP 535 ที่ `password_reset.py` ตั้งใจให้ล้มเหลว)
3. ข้อมูลทดสอบถูกลบ: จำนวนผู้ใช้/Prompt เท่าก่อนรัน, `storage/uploads/*` ไม่มีไฟล์ค้าง
4. `config/config.php` เหมือนเดิม
5. หน้าที่แก้ ตรวจในเบราว์เซอร์ที่ 375px และเดสก์ท็อป (ไม่ล้นแนวนอน ไม่มี error ใน console)

## ยังไม่ได้ทดสอบ (ณ 1.9.0)

PHP 8.2 จริง (ทดสอบบน 8.0.30) · MySQL 8 แท้ (ทดสอบบน MariaDB 10.4) · Google OAuth และ Google Drive จริง (ทดสอบกับเซิร์ฟเวอร์จำลอง) · SMTP จริง/TLS · Safari/Firefox/มือถือจริง · โหลดพร้อมกันจำนวนมาก
