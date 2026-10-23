-- เพิ่มข้อมูลผู้พัฒนาระบบและ PDPA (สำหรับฐานข้อมูลที่ติดตั้งก่อนมีฟีเจอร์นี้)
-- ติดตั้งใหม่ไม่ต้องรันไฟล์นี้ เพราะรวมอยู่ใน schema.sql แล้ว
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN privacy_accepted_at DATETIME NULL AFTER status;

INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
('developer_name', 'ครูสมควร สากำ'),
('privacy_controller', ''),
('privacy_contact', '');
