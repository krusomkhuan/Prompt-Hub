<?php
declare(strict_types=1);

namespace PromptHub\Repositories;

use PromptHub\Core\Database;

/** Digital Shop: สินค้า คำสั่งซื้อ รายการโปรด และรีวิว (SQL ทั้งหมดของร้านค้าอยู่ที่นี่) */
final class ShopRepository
{
    public const TYPES = ['ebook' => 'eBook', 'prompt' => 'Prompt', 'code' => 'ชุดโค้ด'];

    private const PRODUCT_SELECT = 'SELECT p.*,
            (SELECT ROUND(AVG(r.rating), 1) FROM shop_reviews r WHERE r.product_id = p.id) AS rating_avg,
            (SELECT COUNT(*) FROM shop_reviews r WHERE r.product_id = p.id) AS rating_count
        FROM shop_products p';

    public function __construct(private Database $db)
    {
    }

    // ---------- สินค้า ----------

    /**
     * รายการสินค้าหน้าร้าน (เฉพาะที่เปิดขาย) หรือหลังบ้าน ($admin = true ทุกสถานะ)
     * $f: type, q, sort (latest|popular|price_asc|price_desc), status (admin: active|hidden)
     */
    public function search(array $f, int $page, int $perPage, bool $admin = false): array
    {
        $where = [$admin ? '1=1' : 'p.is_active = 1'];
        $args = [];
        if (($f['type'] ?? '') !== '' && isset(self::TYPES[$f['type']])) {
            $where[] = 'p.product_type = ?';
            $args[] = $f['type'];
        }
        if (($f['q'] ?? '') !== '') {
            $like = Database::like($f['q']);
            $where[] = '(p.title LIKE ? OR p.summary LIKE ? OR p.description LIKE ?)';
            array_push($args, $like, $like, $like);
        }
        if ($admin && in_array($f['status'] ?? '', ['active', 'hidden'], true)) {
            $where[] = 'p.is_active = ' . ($f['status'] === 'active' ? 1 : 0);
        }
        $order = match ($f['sort'] ?? 'latest') {
            'popular' => 'p.view_count DESC, p.id DESC',
            'price_asc' => 'p.price ASC, p.id DESC',
            'price_desc' => 'p.price DESC, p.id DESC',
            default => 'p.sort_order DESC, p.id DESC',
        };
        $w = implode(' AND ', $where);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM shop_products p WHERE $w", $args);
        $extra = $admin
            ? ", (SELECT COUNT(*) FROM shop_orders o WHERE o.product_id = p.id AND o.status = 'paid') AS paid_count,
                 (SELECT COALESCE(SUM(o.amount), 0) FROM shop_orders o WHERE o.product_id = p.id AND o.status = 'paid') AS revenue"
            : '';
        $rows = $this->db->all(
            str_replace(' FROM shop_products p', $extra . ' FROM shop_products p', self::PRODUCT_SELECT)
            . " WHERE $w ORDER BY $order LIMIT ? OFFSET ?",
            array_merge($args, [$perPage, ($page - 1) * $perPage])
        );
        return [$rows, $total];
    }

    /** จำนวนสินค้าที่เปิดขายแยกตามประเภท [type => count] */
    public function typeCounts(): array
    {
        $out = array_fill_keys(array_keys(self::TYPES), 0);
        foreach ($this->db->all('SELECT product_type, COUNT(*) AS c FROM shop_products WHERE is_active = 1 GROUP BY product_type') as $r) {
            $out[$r['product_type']] = (int) $r['c'];
        }
        return $out;
    }

    public function find(int $id, bool $activeOnly = false): ?array
    {
        return $this->db->one(
            self::PRODUCT_SELECT . ' WHERE p.id = ?' . ($activeOnly ? ' AND p.is_active = 1' : ''),
            [$id]
        );
    }

    public function findForUpdate(int $id): ?array
    {
        return $this->db->one('SELECT * FROM shop_products WHERE id = ? FOR UPDATE', [$id]);
    }

    /** บันทึกสินค้า (คอลัมน์ที่อนุญาตเท่านั้น) คืน id */
    public function saveProduct(?int $id, array $data, int $adminId): int
    {
        $cols = ['product_type', 'title', 'summary', 'description', 'sample_text', 'full_content', 'price', 'compare_price',
            'cover_file', 'file_name', 'file_original', 'file_size', 'code_name', 'code_original', 'code_size',
            'external_url', 'is_active', 'sort_order'];
        $data = array_intersect_key($data, array_flip($cols));
        if ($id) {
            $set = implode(', ', array_map(static fn($c) => "$c = ?", array_keys($data)));
            $this->db->execute("UPDATE shop_products SET $set WHERE id = ?", array_merge(array_values($data), [$id]));
            return $id;
        }
        $data['created_by'] = $adminId;
        $this->db->execute(
            'INSERT INTO shop_products (' . implode(', ', array_keys($data)) . ') VALUES (' . Database::placeholders(count($data)) . ')',
            array_values($data)
        );
        return $this->db->lastId();
    }

    public function deleteProduct(int $id): void
    {
        $this->db->execute('DELETE FROM shop_products WHERE id = ?', [$id]);
    }

    public function incrementView(int $id): void
    {
        $this->db->execute('UPDATE shop_products SET view_count = view_count + 1 WHERE id = ?', [$id]);
    }

    /** ชื่อไฟล์ของสลิปทั้งหมดของสินค้า (ใช้ลบจากดิสก์หลังลบสินค้า) */
    public function slipFilesForProduct(int $productId): array
    {
        return array_column($this->db->all('SELECT slip_file FROM shop_orders WHERE product_id = ? AND slip_file IS NOT NULL', [$productId]), 'slip_file');
    }

    // ---------- คำสั่งซื้อ ----------

    /** คำสั่งซื้อที่สำคัญที่สุดของผู้ใช้กับสินค้านี้: ได้รับแล้ว > รอตรวจสอบ > ไม่ผ่าน (ล่าสุด) */
    public function userOrder(int $userId, int $productId): ?array
    {
        return $this->db->one(
            "SELECT * FROM shop_orders WHERE user_id = ? AND product_id = ?
             ORDER BY FIELD(status, 'paid', 'pending', 'rejected'), id DESC LIMIT 1",
            [$userId, $productId]
        );
    }

    public function owns(int $userId, int $productId): bool
    {
        return (bool) $this->db->value("SELECT COUNT(*) FROM shop_orders WHERE user_id = ? AND product_id = ? AND status = 'paid'", [$userId, $productId]);
    }

    /** สร้างคำสั่งซื้อ (เรียกภายใน Transaction) — เลขที่ DS + ปีเดือนวัน (เวลาไทย) + ลำดับของวัน */
    public function createOrder(int $userId, array $product, string $status, ?string $slip, string $note): int
    {
        $prefix = 'DS' . (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Bangkok')))->format('ymd');
        $last = (string) $this->db->value('SELECT order_no FROM shop_orders WHERE order_no LIKE ? ORDER BY order_no DESC LIMIT 1 FOR UPDATE', [$prefix . '%']);
        $seq = $last !== '' ? (int) substr($last, strlen($prefix)) + 1 : 1;
        $this->db->execute(
            'INSERT INTO shop_orders (order_no, user_id, product_id, amount, status, slip_file, buyer_note, reviewed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ' . ($status === 'paid' ? 'UTC_TIMESTAMP()' : 'NULL') . ')',
            [$prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT), $userId, (int) $product['id'], (float) $product['price'], $status, $slip, $note]
        );
        return $this->db->lastId();
    }

    public function findOrder(int $id, bool $forUpdate = false): ?array
    {
        return $this->db->one(
            'SELECT o.*, p.title AS product_title, p.product_type, u.display_name, u.username, u.email
             FROM shop_orders o JOIN shop_products p ON p.id = o.product_id JOIN users u ON u.id = o.user_id
             WHERE o.id = ?' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$id]
        );
    }

    public function reviewOrder(int $id, string $status, int $adminId, string $note): void
    {
        $this->db->execute(
            'UPDATE shop_orders SET status = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(), admin_note = ? WHERE id = ?',
            [$status, $adminId, $note, $id]
        );
    }

    public function incrementDownload(int $orderId): void
    {
        $this->db->execute('UPDATE shop_orders SET download_count = download_count + 1 WHERE id = ?', [$orderId]);
    }

    /** คำสั่งซื้อหลังบ้าน กรองสถานะ/คำค้น (เลขที่ ชื่อสินค้า ชื่อผู้ซื้อ) */
    public function searchOrders(string $status, string $q, int $page, int $perPage): array
    {
        $where = ['1=1'];
        $args = [];
        if (in_array($status, ['pending', 'paid', 'rejected'], true)) {
            $where[] = 'o.status = ?';
            $args[] = $status;
        }
        if ($q !== '') {
            $like = Database::like($q);
            $where[] = '(o.order_no LIKE ? OR p.title LIKE ? OR u.display_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
            array_push($args, $like, $like, $like, $like, $like);
        }
        $from = 'FROM shop_orders o JOIN shop_products p ON p.id = o.product_id JOIN users u ON u.id = o.user_id';
        $w = ' WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->value("SELECT COUNT(*) $from $w", $args);
        $rows = $this->db->all(
            "SELECT o.*, p.title AS product_title, p.product_type, u.display_name, u.username, u.email, rv.username AS reviewer
             $from LEFT JOIN users rv ON rv.id = o.reviewed_by $w
             ORDER BY FIELD(o.status, 'pending', 'paid', 'rejected'), o.created_at DESC, o.id DESC LIMIT ? OFFSET ?",
            array_merge($args, [$perPage, ($page - 1) * $perPage])
        );
        return [$rows, $total];
    }

    /** สรุปคำสั่งซื้อ: จำนวนตามสถานะ + ยอดรับเงิน */
    public function orderStats(): array
    {
        $out = ['pending' => 0, 'paid' => 0, 'rejected' => 0, 'revenue' => 0.0, 'products' => 0];
        foreach ($this->db->all('SELECT status, COUNT(*) AS c, COALESCE(SUM(amount), 0) AS s FROM shop_orders GROUP BY status') as $r) {
            $out[$r['status']] = (int) $r['c'];
            if ($r['status'] === 'paid') {
                $out['revenue'] = (float) $r['s'];
            }
        }
        $out['products'] = (int) $this->db->value('SELECT COUNT(*) FROM shop_products');
        return $out;
    }

    public function pendingCount(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM shop_orders WHERE status = 'pending'");
    }

    // ---------- คลังของฉัน ----------

    /** สินค้าที่ได้รับแล้ว (รวมสินค้าที่ถูกปิดขายภายหลัง — ผู้ซื้อยังเข้าถึงได้) */
    public function library(int $userId): array
    {
        return $this->db->all(
            "SELECT p.*, o.id AS order_id, o.order_no, o.reviewed_at AS received_at
             FROM shop_orders o JOIN shop_products p ON p.id = o.product_id
             WHERE o.user_id = ? AND o.status = 'paid'
             ORDER BY o.reviewed_at DESC, o.id DESC",
            [$userId]
        );
    }

    public function userOrders(int $userId): array
    {
        return $this->db->all(
            'SELECT o.*, p.title AS product_title, p.product_type, p.cover_file
             FROM shop_orders o JOIN shop_products p ON p.id = o.product_id
             WHERE o.user_id = ? ORDER BY o.created_at DESC, o.id DESC',
            [$userId]
        );
    }

    // ---------- รายการโปรด ----------

    public function favoriteIds(int $userId): array
    {
        return array_map('intval', array_column($this->db->all('SELECT product_id FROM shop_favorites WHERE user_id = ?', [$userId]), 'product_id'));
    }

    /** สลับรายการโปรด คืนสถานะใหม่ (true = อยู่ในรายการโปรด) */
    public function toggleFavorite(int $userId, int $productId): bool
    {
        if ($this->db->execute('DELETE FROM shop_favorites WHERE user_id = ? AND product_id = ?', [$userId, $productId]) > 0) {
            return false;
        }
        $this->db->execute('INSERT IGNORE INTO shop_favorites (user_id, product_id) VALUES (?, ?)', [$userId, $productId]);
        return true;
    }

    public function favorites(int $userId): array
    {
        return $this->db->all(
            str_replace(' FROM shop_products p', ' FROM shop_favorites f JOIN shop_products p ON p.id = f.product_id', self::PRODUCT_SELECT)
            . ' WHERE f.user_id = ? AND p.is_active = 1 ORDER BY f.created_at DESC',
            [$userId]
        );
    }

    // ---------- รีวิว ----------

    public function reviews(int $productId, int $limit = 50): array
    {
        return $this->db->all(
            'SELECT r.*, u.display_name, u.avatar_file FROM shop_reviews r JOIN users u ON u.id = r.user_id
             WHERE r.product_id = ? ORDER BY r.updated_at DESC LIMIT ?',
            [$productId, $limit]
        );
    }

    public function userReview(int $userId, int $productId): ?array
    {
        return $this->db->one('SELECT * FROM shop_reviews WHERE user_id = ? AND product_id = ?', [$userId, $productId]);
    }

    public function saveReview(int $userId, int $productId, int $rating, string $comment): void
    {
        $this->db->execute(
            'INSERT INTO shop_reviews (product_id, user_id, rating, comment) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment)',
            [$productId, $userId, $rating, $comment]
        );
    }

    public function deleteReview(int $id): void
    {
        $this->db->execute('DELETE FROM shop_reviews WHERE id = ?', [$id]);
    }
}
