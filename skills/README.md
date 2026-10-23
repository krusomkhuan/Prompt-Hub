# สกิล Prompt Hub สำหรับ ChatGPT

สกิลนี้สอนให้ ChatGPT รู้จักระบบ Prompt Hub ทั้งโครงสร้าง กฎความปลอดภัย กฎธุรกิจ ฐานข้อมูล การทดสอบ และการติดตั้ง พร้อมซอร์สโค้ดทั้งระบบ เพื่อให้ช่วย **เพิ่มฟีเจอร์ แก้บั๊ก ตรวจความปลอดภัย ติดตั้ง หรือสร้างระบบใหม่** ได้ตรงกับโค้ดจริง

| ไฟล์ | ใช้ทำอะไร |
|---|---|
| `prompt-hub-skill.zip` | **ไฟล์สำหรับอัปโหลดเข้า ChatGPT** (ภายในคือโฟลเดอร์ `prompt-hub/`) |
| `prompt-hub/SKILL.md` | คำสั่งหลักของสกิล |
| `prompt-hub/references/*.md` | เอกสารอ้างอิงที่ ChatGPT เปิดอ่านเมื่อจำเป็น |
| `prompt-hub/assets/prompt-hub-source.zip` | ซอร์สโค้ดเวอร์ชันปัจจุบัน (ไม่มี `config.php`, รหัสผ่าน, log, ภาพที่อัปโหลด, ข้อมูลสำรอง) |
| `build_skill.py` | สร้าง zip ใหม่หลังแก้โค้ด |

## วิธีที่ 1 — อัปโหลดเป็น Skill (ChatGPT Business / Enterprise / Edu)

1. เปิด ChatGPT → **Plugins → Skills** → **Create** → **Upload from your computer** (ชื่อเมนูอาจต่างกันเล็กน้อยตามรุ่นของ ChatGPT)
2. เลือกไฟล์ `prompt-hub-skill.zip`
3. ตรวจชื่อ `prompt-hub` และคำอธิบาย แล้วกด **Install** (ระบบอาจสแกนไฟล์ก่อน บางครั้งขึ้น *Needs review*)
4. ทดสอบ เช่น พิมพ์ “ใช้สกิล prompt-hub ช่วยเพิ่มหน้าเปลี่ยนอีเมลในโปรไฟล์”

เมนูและสิทธิ์การใช้ Skills ขึ้นกับแผนบัญชีและการตั้งค่าของผู้ดูแล workspace ถ้าไม่พบเมนู ให้ใช้วิธีที่ 2

## วิธีที่ 2 — ใช้ใน Project (ChatGPT Plus / Pro / Free)

1. ChatGPT → **Projects** → **New project** ตั้งชื่อ “Prompt Hub”
2. เปิด **Instructions** ของ Project แล้ววางเนื้อหาทั้งหมดของ `prompt-hub/SKILL.md` (ตั้งแต่บรรทัด `# Prompt Hub — ...` ลงไป ไม่ต้องใส่ส่วน `---name/description---`)
3. **Add files**: อัปโหลดไฟล์ในโฟลเดอร์ `prompt-hub/references/` ทั้งหมด และ `prompt-hub/assets/prompt-hub-source.zip`
4. แชตใหม่ใน Project นั้น ChatGPT จะอ่านคำสั่งและไฟล์อ้างอิงอัตโนมัติ

## ตัวอย่างคำสั่งที่ใช้ได้

- “เพิ่มระบบแจ้งเตือนเมื่อ Prompt ได้รับการอนุมัติ พร้อม migration และชุดทดสอบ”
- “ตรวจความปลอดภัยของ `AvatarService.php` ตามเช็กลิสต์ใน security.md”
- “อธิบายวิธีติดตั้ง Prompt Hub บน XAMPP เครื่องใหม่ทีละขั้น”
- “แตกไฟล์ซอร์สแล้วหาว่าหน้าไหนแสดงจำนวนครั้งที่คัดลอก”

## อัปเดตสกิลหลังแก้ระบบ

```
python skills\build_skill.py
```
แล้วอัปโหลด `prompt-hub-skill.zip` ใหม่ (หรือแทนที่ไฟล์ใน Project) และอัปเดตเลขเวอร์ชัน/ฟีเจอร์ใน `SKILL.md` และ `references/features.md` ถ้ามีฟีเจอร์ใหม่

> สคริปต์จะหยุดถ้าพบไฟล์ต้องห้าม (เช่น `config/config.php`, `storage/initial-accounts.txt`) หรือข้อความที่ดูเหมือนรหัสผ่าน/คีย์ลับ ก่อนอัปโหลดสกิลจากแหล่งอื่น ควรเปิดอ่านเนื้อหาทุกครั้ง
