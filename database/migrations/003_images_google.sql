-- เพิ่มภาพตัวอย่างใน Prompt (เฉพาะหมวดที่เปิดรับภาพ) และเข้าสู่ระบบด้วย Google
-- ติดตั้งใหม่ไม่ต้องรันไฟล์นี้ เพราะรวมอยู่ใน schema.sql แล้ว
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE categories ADD COLUMN allow_images TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;
UPDATE categories SET allow_images = 1 WHERE name = 'การสร้างภาพ';

ALTER TABLE users ADD COLUMN google_sub VARCHAR(64) NULL AFTER email,
    ADD UNIQUE KEY uq_users_google_sub (google_sub);

-- ไฟล์ภาพเก็บใน storage/uploads/prompts (นอก public) และส่งผ่าน index.php?r=media ซึ่งตรวจสิทธิ์ตาม Prompt
CREATE TABLE IF NOT EXISTS prompt_images (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    prompt_id INT UNSIGNED NOT NULL,
    file_name VARCHAR(80) NOT NULL,
    mime VARCHAR(20) NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    bytes INT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_prompt_images_file (file_name),
    KEY idx_prompt_images_prompt (prompt_id, sort_order),
    CONSTRAINT fk_prompt_images_prompt FOREIGN KEY (prompt_id) REFERENCES prompts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
