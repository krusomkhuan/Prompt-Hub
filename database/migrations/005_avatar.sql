-- ภาพโปรไฟล์ของผู้ใช้ (สำหรับฐานข้อมูลที่ติดตั้งก่อนมีฟีเจอร์นี้)
-- ติดตั้งใหม่ไม่ต้องรันไฟล์นี้ เพราะรวมอยู่ใน schema.sql แล้ว
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN avatar_file VARCHAR(64) NULL AFTER google_sub;
