# ฐานข้อมูล

ฉบับเต็มอยู่ใน `schema.sql` (ไฟล์เดียวกับ `database/schema.sql`) — utf8mb4_unicode_ci, InnoDB, เวลาเป็น UTC

| ตาราง | หน้าที่ | ข้อกำหนดสำคัญ |
|---|---|---|
| `users` | สมาชิกและผู้ดูแล | UNIQUE `username`, `email`, `google_sub` · `role` member/admin · `status` active/suspended · `avatar_file` (ชื่อไฟล์ภาพโปรไฟล์) · `privacy_accepted_at` · `password_hash = '!google'` = บัญชี Google ที่ยังไม่ตั้งรหัสผ่าน |
| `categories` | หมวดหมู่ | UNIQUE `name` · `icon` (ชื่อ Bootstrap Icons) · `is_active`, `sort_order`, `allow_images` |
| `prompts` | Prompt | FK `user_id` CASCADE, `category_id` RESTRICT, `reviewed_by` SET NULL · `visibility` private/public · `status` draft/pending/published/rejected · `content_th` (คำแปลไทย), `rejection_reason`, `copy_count`, `view_count`, `published_at` |
| `prompt_images` | ภาพตัวอย่าง | FK `prompt_id` CASCADE · `file_name`, `drive_file_id` (ไฟล์บน Google Drive), `mime`, `width`, `height`, `bytes`, `sort_order` |
| `tags`, `prompt_tags` | แท็ก | UNIQUE `name` · PK (`prompt_id`,`tag_id`) |
| `ai_tools`, `prompt_ai_tools` | เครื่องมือ AI | UNIQUE `name` · `is_active` · PK (`prompt_id`,`ai_tool_id`) |
| `favorites` | รายการโปรด | PK (`user_id`,`prompt_id`) |
| `site_settings` | ค่าตั้งค่าเว็บไซต์ | PK `setting_key` |
| `admin_activity_logs` | บันทึกกิจกรรมผู้ดูแล | FK `admin_id` SET NULL + สำเนา `admin_username` · `action`, `target_type`, `target_id`, `details`, `ip_address` |
| `login_attempts` | จำกัดความถี่ login / ลืมรหัสผ่าน | เก็บ hash ไม่เก็บรหัสผ่าน (ลบอัตโนมัติเมื่อเกิน 1 วัน) |
| `password_resets` | ลิงก์ตั้งรหัสผ่าน | `token_hash` (SHA-256) UNIQUE · `expires_at`, `used_at` · FK `user_id` CASCADE |

## ดัชนี

`prompts`: `(visibility,status,created_at)`, `(visibility,status,copy_count)`, `(category_id,visibility,status)`, `(user_id,status)`, `(status,updated_at)` + ดัชนีฝั่งตรงข้ามของตารางเชื่อม

## พฤติกรรมเมื่อลบ

ลบสมาชิก → ลบ Prompt, รายการโปรด, ภาพ (ไฟล์ลบในโค้ดหลัง commit) · ลบ Prompt → ลบแท็ก/เครื่องมือ/รายการโปรด/ภาพที่เชื่อม · ลบหมวดหมู่ที่มี Prompt → ต้องเลือกหมวดปลายทาง ย้ายแล้วลบใน transaction เดียว

## Migration ที่มี

| ไฟล์ | เวอร์ชัน | เนื้อหา |
|---|---|---|
| `001_password_resets.sql` | 1.1.0 | ตาราง password_resets |
| `002_pdpa.sql` | 1.2.0 | `users.privacy_accepted_at` + ค่าตั้งค่าผู้พัฒนา/PDPA |
| `003_images_google.sql` | 1.4.0 | `categories.allow_images`, `users.google_sub`, ตาราง prompt_images |
| `004_copyright.sql` | 1.4.0 | ค่าตั้งค่า copyright_owner, copyright_start_year |
| `005_avatar.sql` | 1.6.0 | `users.avatar_file` |
| `006_site_url.sql` | 1.7.0 | ค่าตั้งค่า `site_url` |
| `007_content_th.sql` | 1.8.0 | `prompts.content_th` (คำแปลภาษาไทย) |
| `008_drive.sql` | 1.9.0 | `prompt_images.drive_file_id` + ค่าตั้งค่า `drive_folder_id` |

ฟีเจอร์ใหม่ที่เปลี่ยนโครงสร้าง: แก้ `schema.sql` และสร้าง `009_...sql` เสมอ (คนที่ติดตั้งแล้วต้องรัน migration ตามลำดับเลข ครั้งเดียวต่อไฟล์)

## Query ที่ใช้บ่อย

```sql
-- Prompt สาธารณะ
WHERE p.visibility = 'public' AND p.status = 'published'
-- แต้มของสมาชิก
SELECT COUNT(*) FROM prompts p JOIN users u ON u.id = p.user_id
WHERE u.role = 'member' AND p.status = 'published' AND p.visibility = 'public' AND p.user_id = ?
```
