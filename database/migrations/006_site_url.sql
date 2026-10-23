-- URL จริงของเว็บไซต์ (ตั้งในหลังบ้าน) สำหรับฐานข้อมูลที่ติดตั้งก่อนมีฟีเจอร์นี้
-- ติดตั้งใหม่ไม่ต้องรันไฟล์นี้ เพราะรวมอยู่ใน schema.sql แล้ว
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('site_url', '');
