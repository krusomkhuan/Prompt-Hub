-- เพิ่มระบบลืมรหัสผ่าน (สำหรับฐานข้อมูลที่ติดตั้งก่อนมีฟีเจอร์นี้)
-- ติดตั้งใหม่ไม่ต้องรันไฟล์นี้ เพราะรวมอยู่ใน schema.sql แล้ว
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- เก็บเฉพาะ SHA-256 ของ token (ตัว token จริงอยู่ในลิงก์อีเมลเท่านั้น) ใช้ได้ครั้งเดียวและมีวันหมดอายุ
CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_resets_token (token_hash),
    KEY idx_password_resets_user (user_id, used_at),
    KEY idx_password_resets_expires (expires_at),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
