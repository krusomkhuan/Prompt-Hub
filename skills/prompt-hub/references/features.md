# กฎธุรกิจและฟีเจอร์ (เวอร์ชัน 1.11.0)

## บทบาท

| บทบาท | ทำได้ |
|---|---|
| ผู้เยี่ยมชม | หน้าแรก ค้นหา/กรอง/เรียง/แบ่งหน้า ดู Prompt ที่เผยแพร่ **เพียงบางส่วน** (40% ของเนื้อหา ไม่เกิน 120 ตัวอักษร) ทำเนียบ หมวดหมู่ เกี่ยวกับ นโยบายความเป็นส่วนตัว สมัคร/เข้าสู่ระบบ (รวม Google) ลืมรหัสผ่าน |
| สมาชิก | ดู Prompt เต็ม เติมตัวแปร **คัดลอก** (นับ `copy_count` ผ่าน POST `prompt.copy`) รายการโปรด สร้าง/แก้/ลบ Prompt ของตน ส่งตรวจ แดชบอร์ด แต้ม/เหรียญ โปรไฟล์ (ชื่อ ภาพ รหัสผ่าน) |
| ผู้ดูแล | ทุกอย่างของสมาชิก + หลังบ้าน: ภาพรวม, Prompt (อนุมัติ/ไม่อนุมัติพร้อมเหตุผล/ถอนการเผยแพร่/แก้/ลบของทุกคน), สมาชิก (ระงับ/เปิด/ตั้งเป็นผู้ดูแล/ลดสิทธิ์/ลบภาพโปรไฟล์/ลบ), หมวดหมู่ (เปิดรับภาพ), แท็ก, เครื่องมือ AI, ตั้งค่าเว็บไซต์, บันทึกกิจกรรม |

ผู้ดูแลระงับ/ลบ/ลดสิทธิ์บัญชีตนเองไม่ได้ และระบบต้องเหลือผู้ดูแลที่ใช้งานได้อย่างน้อย 1 บัญชี (ตรวจใน transaction พร้อม `FOR UPDATE`)

## วงจรสถานะ Prompt (`prompts.status`)

| การกระทำ | ผล |
|---|---|
| บันทึกเป็น “ส่วนตัว” | `draft` เห็นเฉพาะเจ้าของและผู้ดูแล |
| “สาธารณะ” + บันทึกฉบับร่าง | `draft` |
| “สาธารณะ” + ส่งตรวจ | `pending` |
| ผู้ดูแลอนุมัติ | `published` (`published_at`, `reviewed_by`, `reviewed_at`) |
| ผู้ดูแลไม่อนุมัติ | `rejected` ต้องมี `rejection_reason` เจ้าของเห็นเหตุผล |
| สมาชิกแก้ไข Prompt ที่ `pending`/`published` (รวมแก้/ลบภาพ) | กลับเป็น `pending` หยุดแสดงสาธารณะจนอนุมัติใหม่ |
| ผู้ดูแลแก้ไข Prompt ที่ `published` | คง `published` และบันทึกกิจกรรม |

สาธารณะ = `visibility='public' AND status='published'` เท่านั้น (`PromptPolicy::isPublic`) สิ่งอื่นเปิดได้เฉพาะเจ้าของ/ผู้ดูแล ไม่มีสิทธิ์ → 404

## คำแปลภาษาไทย (prompts.content_th)

ไม่บังคับ ≤ 20,000 ตัวอักษร กรอกในฟอร์มใต้ “เนื้อหา Prompt” แสดงใต้ต้นฉบับในหน้ารายละเอียดเฉพาะผู้ที่เห็นเนื้อหาเต็ม (สมาชิก) ค้นหาได้ ค่าว่างเก็บเป็น NULL ปุ่มคัดลอก/ตัวแปรใช้เฉพาะต้นฉบับ

## ตัวแปรใน Prompt

รูปแบบ `[ชื่อตัวแปร]` (1–60 ตัวอักษร ไม่ขึ้นบรรทัด สูงสุด 30 ตัว) หน้ารายละเอียดสร้างช่องกรอกให้ JavaScript สร้างข้อความใหม่จากต้นฉบับทุกครั้ง ช่องที่ไม่กรอกคงข้อความเดิม

## ค้นหา

ค้นจากชื่อ คำอธิบาย เนื้อหา คำแปลภาษาไทย และแท็ก (LIKE โดย escape `%` `_`) กรองหมวด แท็ก เครื่องมือ AI เรียง `latest | copies` (whitelist `PromptRepository::SORTS` ซึ่งมี `updated` สำหรับหน้าของสมาชิก) แบ่งหน้าตาม `PER_PAGE_OPTIONS` (12/24/48) ค่าผิดรูปแบบใช้ค่าเริ่มต้น

## แต้มสะสมและเหรียญ (BadgeService)

- 1 แต้ม ต่อ Prompt ที่ `visibility='public' AND status='published'` ของบัญชี `role='member'` (ผู้ดูแลไม่ร่วมสะสม)
- คำนวณสดจากฐานข้อมูล (ไม่มีคอลัมน์แต้ม) แก้แล้วกลับไปรอตรวจ/ลบ → แต้มลดทันที
- เหรียญ: 5–10 ทองแดง · 11–20 เงิน · 21+ ทอง (`BadgeService::TIERS`)
- แสดง: การ์ดในแดชบอร์ด (แต้ม เหรียญ แถบความคืบหน้า แต้มที่ขาด), ข้างชื่อผู้สร้างในหน้ารายละเอียด, หน้า `leaders` (ทำเนียบ เรียงแต้ม แล้วยอดคัดลอกรวม, เฉพาะบัญชี active), หลังบ้าน › สมาชิก

## ภาพตัวอย่างผลลัพธ์ (ImageService)

- เฉพาะหมวดที่ `categories.allow_images = 1` (ค่าเริ่มต้น: “การสร้างภาพ”) สูงสุด 4 ภาพ/Prompt, JPEG/PNG/WebP ≤ 5 MB, ด้านยาว ≤ 8000 px, ≤ 40 ล้านพิกเซล
- ตาราง `prompt_images` ไฟล์ใน `storage/uploads/prompts/` ชื่อ `[a-f0-9]{32}.(jpg|png|webp)`
- แสดงผ่าน `index.php?r=media&id=` ตรวจสิทธิ์ตาม Prompt (`PromptPolicy::canView`)
- ลบภาพ / ลบ Prompt / ลบสมาชิก → ลบไฟล์หลัง commit

## เก็บภาพตัวอย่างใน Google Drive (GoogleDriveService)

- หลังบ้าน › Google Drive (`admin.drive*`): ตั้งโฟลเดอร์ (`site_settings.drive_folder_id`) → เชื่อมต่อด้วย OAuth (scope `auth/drive`, offline, PKCE) โดยใช้ client_id/secret ในส่วน `drive` ของ config (ถ้าไม่ตั้งใช้ส่วน `google`) — Sign-In สำหรับสมาชิกทั่วไปต้องเป็น External จึงควรแยก Client ของ Drive (Internal) → refresh token + โฟลเดอร์ย่อย “Prompt Hub - ภาพตัวอย่างผลลัพธ์” เก็บใน `storage/google-drive.json`
- หลังบันทึก Prompt (หลัง commit) อัปโหลดภาพใหม่ (`syncPending($promptId)`) ตั้งสิทธิ์ anyone-reader และบันทึก `prompt_images.drive_file_id` · ล้มเหลวไม่ขัดการบันทึก (log `drive-upload`) อัปโหลดภายหลังด้วยปุ่มหรือ `bin/drive-sync.php`
- แสดงผล: `prompt_image_attrs($id, $driveId, $width)` → `src` เป็น `lh3.googleusercontent.com/d/{id}=w…` + `data-fallback` (ภาพในเครื่อง) เมื่อ `GoogleDriveService::active()`; app.js สลับไปภาพในเครื่องเมื่อโหลดไม่ได้ · CSP img-src เพิ่มโดเมนจาก `drive.image_base`
- ลบ: `ImageService` เก็บคิว drive_file_id ตอนเลือกไฟล์ที่จะลบ แล้ว `deleteFiles()` ย้ายไฟล์บน Drive ไปถังขยะหลัง commit (`forgetDriveQueue()` เมื่อ rollback)
- invalid_grant → `needs_reconnect` (หลังบ้านแจ้ง หน้าเว็บกลับไปใช้ภาพในเครื่อง) · ยกเลิกการเชื่อมต่อ → revoke + ลบไฟล์ token · สำเนาในเครื่องเก็บไว้เสมอ
- route `media` (ตรวจสิทธิ์) ยังใช้กับสำเนาในเครื่อง แต่ลิงก์ Drive เปิดได้ทุกคนที่มีลิงก์ (ผู้ใช้เลือกแลกความเป็นส่วนตัวกับความเร็ว)

## ภาพโปรไฟล์ (AvatarService)

- ทุกบัญชี (สมาชิกและผู้ดูแล) ที่หน้าโปรไฟล์ JPEG/PNG/WebP ≤ 2 MB คอลัมน์ `users.avatar_file` ไฟล์ใน `storage/uploads/avatars/`
- แสดงผ่าน `index.php?r=avatar&id={userId}&v={8 ตัวแรกของชื่อไฟล์}` (cache 1 วัน, URL เปลี่ยนเมื่อเปลี่ยนภาพ) ไม่มีภาพ → วงกลมอักษรตัวแรกของชื่อ
- ผู้ดูแลลบภาพที่ไม่เหมาะสมได้ (`admin.user.action` op `remove_avatar`, บันทึก `user.avatar_remove`)

## การย่อขนาดภาพ (ImageResizer + app.js)

- ภาพตัวอย่าง: ด้านยาว ≤ 1600 px คงสัดส่วน · ภาพโปรไฟล์: ตัดกลางเป็นจัตุรัส 256×256 (ไม่ขยายภาพเล็ก)
- หมุนภาพ JPEG ตาม EXIF Orientation ก่อนตัด metadata
- PNG ทึบ (ไม่มีส่วนโปร่งใส) ที่ใหญ่เกิน 1 MB แปลงเป็น JPEG คุณภาพ 85 ถ้าเล็กกว่า (เฉพาะภาพตัวอย่าง)
- ภาพที่ไม่ต้องย่อ/หมุน เก็บไฟล์เดิม (ไม่บีบอัดซ้ำ)
- 2 ชั้น: เบราว์เซอร์ย่อก่อนอัปโหลดด้วย canvas (`input[data-resize-max]`, `[data-resize-square]`) และเซิร์ฟเวอร์ย่อซ้ำด้วย GD ถ้า `extension_loaded('gd')` — ไม่มี GD ระบบยังทำงาน (เก็บไฟล์ตามที่ได้รับ)
- `bin/resize-images.php [--dry-run]` ย่อไฟล์เก่า (ต้องมี GD หรือรันด้วย `php -d extension=gd`)

## สมัคร/เข้าสู่ระบบด้วย Google (GoogleAuthService)

- OAuth 2.0 Authorization Code + **PKCE** + `state` + `nonce` (หมดอายุ 10 นาที) ตรวจ id_token `iss/aud/exp/nonce` และ userinfo ต้อง `email_verified = true`
- พบ `google_sub` → เข้าสู่ระบบ · ไม่พบแต่อีเมลตรงบัญชีเดิม → เชื่อมบัญชี · ใหม่ → หน้า `google.confirm` ให้ตั้งชื่อผู้ใช้และยอมรับนโยบายก่อนสร้าง (`password_hash = '!google'` เข้าด้วยรหัสผ่านไม่ได้จนกว่าจะใช้ “ลืมรหัสผ่าน”)
- บัญชีถูกระงับเข้าไม่ได้ · ปิดใน config → ไม่มีปุ่ม และ `google.start` ตอบ 404

## ลืมรหัสผ่าน (PasswordResetService + Mailer SMTP)

token สุ่ม 256 บิต เก็บเฉพาะ SHA-256 ใช้ครั้งเดียว อายุ 60 นาที ขอใหม่แล้วลิงก์เก่าหมดสิทธิ์ ตอบข้อความเดียวกันไม่ว่ามีบัญชีหรือไม่ จำกัด 3 ครั้ง/อีเมล และ 10 ครั้ง/IP ต่อชั่วโมง ลิงก์สร้างจาก URL เว็บไซต์ (`absolute_url`, ไม่ใช้ Host header) token ถูกย้ายออกจาก URL ทันที ตั้งรหัสใหม่แล้ว session อื่นหมดสิทธิ์

## PDPA

หน้า `privacy` (ข้อมูลที่เก็บ วัตถุประสงค์ ฐานกฎหมาย ผู้ให้บริการภายนอก ระยะเวลา สิทธิ์ คุกกี้) · สมัครต้องติ๊กยอมรับ (`users.privacy_accepted_at`) · แถบแจ้งคุกกี้ (คุกกี้ session จำเป็นตัวเดียว `PROMPTHUB_SID`) · ผู้ควบคุมข้อมูล/ช่องทางติดต่อแก้ในตั้งค่าเว็บไซต์

## URL เว็บไซต์ (site_url)

ตั้งในหลังบ้าน › ตั้งค่าเว็บไซต์ (ว่าง = `app_url` + `base_url`) ใช้กับ: ลิงก์ในอีเมลลืมรหัสผ่าน, Google redirect URI, canonical + Open Graph ของหน้าสาธารณะ (home, prompts, categories, category, leaders, about, privacy, prompt ที่เผยแพร่ + og:image ภาพแรก) · `normalize_site_url()` รับเฉพาะ http(s) ไม่มี query/#/userinfo/`index.php` ทำโดเมนเป็นตัวพิมพ์เล็กและตัด / ท้าย · หน้าตั้งค่าแสดงตัวอย่างลิงก์สดและคำเตือน http / secure_cookie / path ต่างจาก base_url

## ส่วนท้ายเว็บ

ชื่อเว็บ + สโลแกน + คำอธิบาย · “พัฒนาระบบโดย {developer_name}” · ลิงก์ PDPA · `© {ปีเริ่ม}[–{ปีปัจจุบัน}] {copyright_owner}. All rights reserved.` · `เวอร์ชัน {APP_VERSION}`
