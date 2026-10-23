-- Digital Shop: ร้านค้า eBook / ชุด Prompt / ชุดโค้ด (สำหรับฐานข้อมูลที่ติดตั้งก่อนมีฟีเจอร์นี้)
-- ติดตั้งใหม่ไม่ต้องรันไฟล์นี้ เพราะรวมอยู่ใน schema.sql แล้ว
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

-- สินค้า: ไฟล์ทั้งหมดเก็บใน storage/uploads/shop (นอก public) ชื่อไฟล์สุ่ม ส่งผ่านเส้นทางที่ตรวจสิทธิ์
CREATE TABLE IF NOT EXISTS shop_products (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_type ENUM('ebook','prompt','code') NOT NULL DEFAULT 'prompt',
    title VARCHAR(200) NOT NULL,
    summary VARCHAR(300) NOT NULL DEFAULT '',
    description TEXT NULL,
    sample_text TEXT NULL,
    full_content MEDIUMTEXT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    compare_price DECIMAL(10,2) NULL,
    cover_file VARCHAR(64) NULL,
    file_name VARCHAR(64) NULL,
    file_original VARCHAR(200) NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    code_name VARCHAR(64) NULL,
    code_original VARCHAR(200) NULL,
    code_size INT UNSIGNED NOT NULL DEFAULT 0,
    external_url VARCHAR(500) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    view_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_shop_products_list (is_active, product_type, sort_order),
    CONSTRAINT fk_shop_products_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- คำสั่งซื้อ: ฟรี = paid ทันที · มีราคา = pending + สลิป → ผู้ดูแลอนุมัติ (paid) หรือปฏิเสธ (rejected)
CREATE TABLE IF NOT EXISTS shop_orders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_no VARCHAR(20) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('pending','paid','rejected') NOT NULL DEFAULT 'pending',
    slip_file VARCHAR(64) NULL,
    buyer_note VARCHAR(500) NOT NULL DEFAULT '',
    admin_note VARCHAR(500) NOT NULL DEFAULT '',
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    download_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shop_orders_no (order_no),
    KEY idx_shop_orders_user (user_id, status),
    KEY idx_shop_orders_product (product_id, status),
    KEY idx_shop_orders_status (status, created_at),
    CONSTRAINT fk_shop_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_shop_orders_product FOREIGN KEY (product_id) REFERENCES shop_products (id) ON DELETE CASCADE,
    CONSTRAINT fk_shop_orders_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_favorites (
    user_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, product_id),
    KEY idx_shop_favorites_product (product_id),
    CONSTRAINT fk_shop_favorites_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_shop_favorites_product FOREIGN KEY (product_id) REFERENCES shop_products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- รีวิวได้เฉพาะผู้ที่ได้รับสินค้าแล้ว คนละ 1 รีวิวต่อสินค้า (แก้ไขได้)
CREATE TABLE IF NOT EXISTS shop_reviews (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comment VARCHAR(1000) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shop_reviews (product_id, user_id),
    KEY idx_shop_reviews_user (user_id),
    CONSTRAINT fk_shop_reviews_product FOREIGN KEY (product_id) REFERENCES shop_products (id) ON DELETE CASCADE,
    CONSTRAINT fk_shop_reviews_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
('shop_enabled', '1'),
('shop_promptpay', ''),
('shop_account_name', ''),
('shop_payment_note', ''),
('google_redirect_uri', '');
