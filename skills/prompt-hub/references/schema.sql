-- Prompt Hub — โครงสร้างฐานข้อมูล
-- MySQL 8.0+ / MariaDB 10.4+  · InnoDB · utf8mb4
-- เวลาทุกคอลัมน์เก็บเป็น UTC (แอปตั้ง time_zone = '+00:00' ทุก connection) และแสดงผลเป็น Asia/Bangkok
-- ใช้กับฐานข้อมูลที่สร้างไว้แล้ว: CREATE DATABASE prompt_hub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(30) NOT NULL,
    email VARCHAR(190) NOT NULL,
    google_sub VARCHAR(64) NULL,
    avatar_file VARCHAR(64) NULL,
    display_name VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('member','admin') NOT NULL DEFAULT 'member',
    status ENUM('active','suspended') NOT NULL DEFAULT 'active',
    privacy_accepted_at DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_google_sub (google_sub),
    KEY idx_users_role_status (role, status),
    KEY idx_users_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    icon VARCHAR(50) NOT NULL DEFAULT 'folder',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    allow_images TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_name (name),
    KEY idx_categories_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tags (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tags_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_tools (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ai_tools_name (name),
    KEY idx_ai_tools_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ลบสมาชิก  → ลบ Prompt ของสมาชิกนั้น (CASCADE) และความสัมพันธ์ทั้งหมดตามไปด้วย
-- ลบหมวดหมู่ → ถูกปฏิเสธถ้ายังมี Prompt (RESTRICT) ต้องย้าย Prompt ก่อน
-- ลบผู้ตรวจ  → reviewed_by เป็น NULL
CREATE TABLE prompts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description VARCHAR(500) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    content_th MEDIUMTEXT NULL,
    usage_example TEXT NULL,
    output_example TEXT NULL,
    visibility ENUM('private','public') NOT NULL DEFAULT 'private',
    status ENUM('draft','pending','published','rejected') NOT NULL DEFAULT 'draft',
    rejection_reason VARCHAR(1000) NULL,
    copy_count INT UNSIGNED NOT NULL DEFAULT 0,
    view_count INT UNSIGNED NOT NULL DEFAULT 0,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_prompts_public_latest (visibility, status, created_at),
    KEY idx_prompts_public_copies (visibility, status, copy_count),
    KEY idx_prompts_category (category_id, visibility, status),
    KEY idx_prompts_owner (user_id, status),
    KEY idx_prompts_status (status, updated_at),
    CONSTRAINT fk_prompts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_prompts_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_prompts_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ไฟล์ภาพเก็บใน storage/uploads/prompts (นอก public) และส่งผ่าน index.php?r=media ซึ่งตรวจสิทธิ์ตาม Prompt
CREATE TABLE IF NOT EXISTS prompt_images (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    prompt_id INT UNSIGNED NOT NULL,
    file_name VARCHAR(80) NOT NULL,
    drive_file_id VARCHAR(128) NULL,
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

-- ตารางเชื่อมใช้ Composite Primary Key ป้องกันคู่ซ้ำ ลบ Prompt/แท็ก/เครื่องมือ แล้วลบความสัมพันธ์ตาม
CREATE TABLE prompt_tags (
    prompt_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (prompt_id, tag_id),
    KEY idx_prompt_tags_tag (tag_id),
    CONSTRAINT fk_prompt_tags_prompt FOREIGN KEY (prompt_id) REFERENCES prompts (id) ON DELETE CASCADE,
    CONSTRAINT fk_prompt_tags_tag FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prompt_ai_tools (
    prompt_id INT UNSIGNED NOT NULL,
    ai_tool_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (prompt_id, ai_tool_id),
    KEY idx_prompt_ai_tools_tool (ai_tool_id),
    CONSTRAINT fk_prompt_ai_tools_prompt FOREIGN KEY (prompt_id) REFERENCES prompts (id) ON DELETE CASCADE,
    CONSTRAINT fk_prompt_ai_tools_tool FOREIGN KEY (ai_tool_id) REFERENCES ai_tools (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE favorites (
    user_id INT UNSIGNED NOT NULL,
    prompt_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, prompt_id),
    KEY idx_favorites_prompt (prompt_id),
    CONSTRAINT fk_favorites_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_favorites_prompt FOREIGN KEY (prompt_id) REFERENCES prompts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE site_settings (
    setting_key VARCHAR(50) NOT NULL,
    setting_value TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- admin_username เก็บสำเนาชื่อไว้ เพื่อให้บันทึกยังอ่านได้แม้บัญชีผู้ดูแลถูกลบ
CREATE TABLE admin_activity_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id INT UNSIGNED NULL,
    admin_username VARCHAR(30) NOT NULL,
    action VARCHAR(60) NOT NULL,
    target_type VARCHAR(30) NOT NULL DEFAULT '',
    target_id INT UNSIGNED NULL,
    details VARCHAR(1000) NOT NULL DEFAULT '',
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_logs_created (created_at),
    KEY idx_logs_admin (admin_id, created_at),
    KEY idx_logs_action (action, created_at),
    CONSTRAINT fk_logs_admin FOREIGN KEY (admin_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- บันทึกการเข้าสู่ระบบที่ล้มเหลว ใช้จำกัดความถี่ (เก็บ hash ของชื่อผู้ใช้ ไม่เก็บรหัสผ่าน)
CREATE TABLE login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identifier_hash CHAR(64) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_attempts_identifier (identifier_hash, attempted_at),
    KEY idx_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

INSERT INTO site_settings (setting_key, setting_value) VALUES
('site_name', 'Prompt Hub'),
('site_url', ''),
('drive_folder_id', ''),
('site_slogan', 'เปลี่ยนไอเดียให้เป็นจริงด้วย AI'),
('site_description', 'ศูนย์รวมคำสั่ง AI ภาษาไทย ค้นหา ปรับแต่ง และแบ่งปัน Prompt ที่ใช้ได้จริงสำหรับงานสอน ธุรกิจ เอกสาร ภาพ วิดีโอ โค้ด และข้อมูล'),
('hero_badge', 'คลัง Prompt เพื่อการศึกษาและการทำงาน'),
('developer_name', 'ครูสมควร สากำ'),
('copyright_owner', 'ครูสมควร สากำ'),
('copyright_start_year', '2026'),
('privacy_controller', ''),
('privacy_contact', '');

INSERT INTO categories (name, description, icon, sort_order) VALUES
('การศึกษาและการสอน', 'แผนการสอน กิจกรรม ข้อสอบ และสื่อการเรียนรู้', 'mortarboard', 10),
('ธุรกิจและการตลาด', 'คอนเทนต์ขาย แคมเปญ และการวิเคราะห์ลูกค้า', 'graph-up-arrow', 20),
('งานเอกสาร', 'หนังสือราชการ รายงาน สรุปประชุม และอีเมล', 'file-earmark-text', 30),
('การสร้างภาพ', 'คำสั่งสำหรับเครื่องมือสร้างภาพด้วย AI', 'image', 40),
('การสร้างวิดีโอ', 'สคริปต์ สตอรีบอร์ด และคำสั่งสร้างวิดีโอ', 'camera-reels', 50),
('การเขียนโปรแกรม', 'เขียน อธิบาย ทดสอบ และปรับปรุงโค้ด', 'code-slash', 60),
('การวิเคราะห์ข้อมูล', 'สรุปข้อมูล สร้างตาราง และตีความผลลัพธ์', 'bar-chart', 70),
('งานทั่วไป', 'ผู้ช่วยในชีวิตประจำวันและงานจิปาถะ', 'lightbulb', 80);

INSERT INTO ai_tools (name, sort_order) VALUES
('ChatGPT', 10), ('Claude', 20), ('Gemini', 30), ('Microsoft Copilot', 40),
('Midjourney', 50), ('Leonardo AI', 60), ('Runway', 70), ('GitHub Copilot', 80);

UPDATE categories SET allow_images = 1 WHERE name = 'การสร้างภาพ';
