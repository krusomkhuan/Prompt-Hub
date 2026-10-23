-- เก็บภาพตัวอย่างผลลัพธ์ใน Google Drive (สำหรับฐานข้อมูลที่ติดตั้งก่อนมีฟีเจอร์นี้)
-- ติดตั้งใหม่ไม่ต้องรันไฟล์นี้ เพราะรวมอยู่ใน schema.sql แล้ว
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE prompt_images ADD COLUMN drive_file_id VARCHAR(128) NULL AFTER file_name;
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('drive_folder_id', '');
