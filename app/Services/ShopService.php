<?php
declare(strict_types=1);

namespace PromptHub\Services;

use PromptHub\Core\Database;
use PromptHub\Core\HttpException;
use PromptHub\Core\ValidationException;
use PromptHub\Core\Validator;
use PromptHub\Repositories\ShopRepository;

/**
 * Digital Shop — กฎธุรกิจของร้านค้า eBook / ชุด Prompt / ชุดโค้ด
 *  - ทุกคนดูสินค้าและตัวอย่างได้ · รับสินค้าได้เฉพาะสมาชิก
 *  - สินค้าฟรี: กด “รับฟรี” ได้ทันที · มีราคา: สแกน QR พร้อมเพย์/โอนเงิน แนบสลิป → ผู้ดูแลตรวจสอบ → ปลดล็อก
 *  - สิ่งที่ผู้ได้รับสินค้าเปิดได้: เนื้อหาเต็ม/โค้ด · ไฟล์ดาวน์โหลด · ไฟล์โค้ด .zip · ลิงก์รับสินค้า
 *  - ไฟล์ทั้งหมดเก็บใน storage/uploads/shop (นอก public) ชื่อไฟล์สุ่ม ส่งผ่านเส้นทางที่ตรวจสิทธิ์ทุกครั้ง
 *    ภาพปก/สลิปผ่านการตรวจแบบเดียวกับภาพตัวอย่าง (ตรวจเนื้อไฟล์จริง ย่อขนาด ตัด EXIF/GPS)
 */
final class ShopService
{
    public const COVER_MAX_SIDE = 1600;
    public const SLIP_MAX_BYTES = 5 * 1024 * 1024;
    public const FILE_MAX_BYTES = 100 * 1024 * 1024;
    /**
     * ไฟล์ดาวน์โหลด: นามสกุล => MIME ที่ยอมรับ (ตรวจจากเนื้อไฟล์ด้วย finfo)
     * ไฟล์ตระกูล ZIP: libmagic บางรุ่น (เช่น XAMPP) รายงาน ZIP ขนาดเล็กเป็น octet-stream จึงรับไว้ แต่บังคับลายเซ็น PK\x03\x04 อีกชั้น
     */
    public const FILE_TYPES = [
        'pdf' => ['application/pdf'],
        'epub' => ['application/epub+zip', 'application/zip', 'application/octet-stream'],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'txt' => ['text/plain'],
        'md' => ['text/plain', 'text/markdown'],
    ];
    public const DOWNLOAD_MIME = [
        'pdf' => 'application/pdf', 'epub' => 'application/epub+zip', 'zip' => 'application/zip',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'txt' => 'text/plain; charset=utf-8', 'md' => 'text/markdown; charset=utf-8',
    ];

    private ShopRepository $repo;

    public function __construct(private Database $db)
    {
        $this->repo = new ShopRepository($db);
    }

    public static function dir(): string
    {
        return APP_ROOT . '/storage/uploads/shop';
    }

    /** ชื่อไฟล์ที่ระบบตั้ง (32 hex + นามสกุลที่รู้จัก) — กัน path traversal */
    public static function validName(?string $name): bool
    {
        $ext = implode('|', array_merge(array_keys(self::FILE_TYPES), ['jpg', 'png', 'webp']));
        return $name !== null && preg_match('/^[a-f0-9]{32}\.(' . $ext . ')$/D', $name) === 1;
    }

    public static function deleteFiles(array $names): void
    {
        foreach ($names as $n) {
            if (self::validName($n)) {
                @unlink(self::dir() . '/' . $n);
            }
        }
    }

    public static function priceText(float $price): string
    {
        if ($price <= 0) {
            return 'ฟรี';
        }
        return '฿' . (floor($price) == $price ? number_format($price) : number_format($price, 2));
    }

    /** สิ่งที่ผู้ได้รับสินค้าเปิดได้ */
    public static function deliverables(array $p): array
    {
        return [
            'content' => trim((string) ($p['full_content'] ?? '')) !== '',
            'file' => !empty($p['file_name']),
            'code' => !empty($p['code_name']),
            'link' => (string) ($p['external_url'] ?? '') !== '',
        ];
    }

    // ---------- หลังบ้าน: บันทึก/ลบสินค้า ----------

    /** ตรวจฟอร์มสินค้า + ไฟล์ แล้วบันทึก คืน id (ไฟล์เดิมที่ถูกแทนที่ถูกลบหลัง commit) */
    public function saveProduct(array $admin, array $input, array $files, ?array $existing): int
    {
        $v = new Validator($input);
        $type = $v->in('product_type', 'ประเภทสินค้า', array_keys(ShopRepository::TYPES));
        $title = $v->string('title', 'ชื่อสินค้า', 3, 200);
        $summary = $v->string('summary', 'คำอธิบายสั้น', 0, 300);
        $description = $v->text('description', 'รายละเอียดสินค้า', 0, 20000);
        $sample = $v->text('sample_text', 'ตัวอย่างเนื้อหา', 0, 5000);
        $full = $v->text('full_content', 'เนื้อหาเต็ม/โค้ด', 0, 500000);
        $price = $this->money($v, $input, 'price', 'ราคาขาย', false);
        $compare = $this->money($v, $input, 'compare_price', 'ราคาปกติ', true);
        $sort = $v->int('sort_order', 'ลำดับแสดง', -100000, 100000);
        $active = $v->bool('is_active');
        $link = trim((string) ($input['external_url'] ?? ''));
        if ($link !== '' && (mb_strlen($link) > 500 || !filter_var($link, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $link))) {
            $v->addError('external_url', 'ลิงก์รับสินค้าต้องขึ้นต้นด้วย http:// หรือ https://');
        }
        if ($compare !== null && $compare <= $price) {
            $compare = null; // ราคาปกติต้องสูงกว่าราคาขายจึงแสดงขีดฆ่า
        }

        $cover = $this->validateImage($files['cover'] ?? null, 5 * 1024 * 1024, 'cover', $v);
        $file = $this->validateFile($files['file'] ?? null, array_keys(self::FILE_TYPES), 'file', $v);
        $code = $this->validateFile($files['code_file'] ?? null, ['zip'], 'code_file', $v);
        $removeFile = !empty($input['remove_file']);
        $removeCode = !empty($input['remove_code']);
        $removeCover = !empty($input['remove_cover']);

        $hasFile = $file || (!$removeFile && !empty($existing['file_name']));
        $hasCode = $code || (!$removeCode && !empty($existing['code_name']));
        if (!$v->hasError('full_content') && trim($full) === '' && !$hasFile && !$hasCode && $link === '') {
            $v->addError('full_content', 'สินค้าต้องมีสิ่งที่ผู้ซื้อได้รับอย่างน้อย 1 อย่าง: เนื้อหาเต็ม/โค้ด ไฟล์ดาวน์โหลด ไฟล์โค้ด .zip หรือลิงก์รับสินค้า');
        }
        $v->validate();

        $data = [
            'product_type' => $type, 'title' => $title, 'summary' => $summary,
            'description' => $description !== '' ? $description : null,
            'sample_text' => $sample !== '' ? $sample : null,
            'full_content' => trim($full) !== '' ? $full : null,
            'price' => $price, 'compare_price' => $compare, 'external_url' => $link,
            'is_active' => $active ? 1 : 0, 'sort_order' => $sort,
        ];
        $written = [];
        $old = [];
        try {
            if ($cover) {
                $data['cover_file'] = $written[] = $this->storeImage($cover, self::COVER_MAX_SIDE);
                $old[] = $existing['cover_file'] ?? null;
            } elseif ($removeCover && $existing) {
                $data['cover_file'] = null;
                $old[] = $existing['cover_file'];
            }
            if ($file) {
                $data['file_name'] = $written[] = $this->storeFile($file);
                $data['file_original'] = $file['original'];
                $data['file_size'] = $file['size'];
                $old[] = $existing['file_name'] ?? null;
            } elseif ($removeFile && $existing) {
                [$data['file_name'], $data['file_original'], $data['file_size']] = [null, null, 0];
                $old[] = $existing['file_name'];
            }
            if ($code) {
                $data['code_name'] = $written[] = $this->storeFile($code);
                $data['code_original'] = $code['original'];
                $data['code_size'] = $code['size'];
                $old[] = $existing['code_name'] ?? null;
            } elseif ($removeCode && $existing) {
                [$data['code_name'], $data['code_original'], $data['code_size']] = [null, null, 0];
                $old[] = $existing['code_name'];
            }
            $id = $this->db->transaction(function () use ($existing, $data, $admin, $title) {
                $id = $this->repo->saveProduct($existing ? (int) $existing['id'] : null, $data, (int) $admin['id']);
                (new \PromptHub\Repositories\ActivityLogRepository($this->db))
                    ->log($admin, $existing ? 'shop.product.update' : 'shop.product.create', 'shop_product', $id, $title);
                return $id;
            });
        } catch (\Throwable $e) {
            self::deleteFiles($written);
            throw $e;
        }
        self::deleteFiles(array_filter($old));
        return $id;
    }

    public function deleteProduct(array $admin, int $id): string
    {
        $p = $this->repo->find($id);
        if (!$p) {
            throw new HttpException(404, 'ไม่พบสินค้า');
        }
        $files = array_merge([$p['cover_file'], $p['file_name'], $p['code_name']], $this->repo->slipFilesForProduct($id));
        $this->db->transaction(function () use ($admin, $p, $id) {
            $this->repo->deleteProduct($id);
            (new \PromptHub\Repositories\ActivityLogRepository($this->db))->log($admin, 'shop.product.delete', 'shop_product', $id, $p['title']);
        });
        self::deleteFiles(array_filter($files));
        return (string) $p['title'];
    }

    // ---------- หน้าบ้าน: รับสินค้า / สั่งซื้อ ----------

    /** รับสินค้าฟรี คืน id คำสั่งซื้อ (มีอยู่แล้วคืน 0) */
    public function claimFree(array $user, int $productId): int
    {
        return $this->db->transaction(function () use ($user, $productId) {
            $p = $this->repo->findForUpdate($productId);
            if (!$p || !(int) $p['is_active']) {
                throw new HttpException(404, 'ไม่พบสินค้า หรือสินค้านี้ปิดการขายแล้ว');
            }
            if ((float) $p['price'] > 0) {
                throw new HttpException(400, 'สินค้านี้มีราคา กรุณาชำระเงินก่อนรับสินค้า');
            }
            if ($this->repo->owns((int) $user['id'], $productId)) {
                return 0;
            }
            return $this->repo->createOrder((int) $user['id'], $p, 'paid', null, '');
        });
    }

    /** แจ้งชำระเงิน (แนบสลิป) สร้างคำสั่งซื้อสถานะรอตรวจสอบ */
    public function placeOrder(array $user, int $productId, $slipFile, string $note): int
    {
        $v = new Validator(['buyer_note' => $note]);
        $note = $v->string('buyer_note', 'หมายเหตุถึงผู้ขาย', 0, 500);
        $slip = $this->validateImage($slipFile, self::SLIP_MAX_BYTES, 'slip', $v);
        if (!$slip && !$v->hasError('slip')) {
            $v->addError('slip', 'กรุณาแนบภาพสลิปการโอนเงิน');
        }
        $v->validate();
        $name = $this->storeImage($slip, 1600);
        try {
            return $this->db->transaction(function () use ($user, $productId, $name, $note) {
                $p = $this->repo->findForUpdate($productId);
                if (!$p || !(int) $p['is_active']) {
                    throw new HttpException(404, 'ไม่พบสินค้า หรือสินค้านี้ปิดการขายแล้ว');
                }
                if ((float) $p['price'] <= 0) {
                    throw new HttpException(400, 'สินค้านี้ฟรี กด “รับฟรี” ได้เลย');
                }
                $current = $this->repo->userOrder((int) $user['id'], $productId);
                if ($current && $current['status'] !== 'rejected') {
                    throw new ValidationException(['slip' => $current['status'] === 'paid'
                        ? 'คุณได้รับสินค้านี้แล้ว ดูได้ที่คลังของฉัน'
                        : 'คุณแจ้งชำระเงินสินค้านี้แล้ว (เลขที่ ' . $current['order_no'] . ') กรุณารอผู้ดูแลตรวจสอบ']);
                }
                return $this->repo->createOrder((int) $user['id'], $p, 'pending', $name, $note);
            });
        } catch (\Throwable $e) {
            self::deleteFiles([$name]);
            throw $e;
        }
    }

    /** ผู้ดูแลตรวจคำสั่งซื้อ: approve | reject */
    public function reviewOrder(array $admin, int $orderId, string $decision, string $note): array
    {
        $v = new Validator(['note' => $note]);
        $note = $v->string('note', 'หมายเหตุ', $decision === 'reject' ? 3 : 0, 500);
        $v->validate();
        return $this->db->transaction(function () use ($admin, $orderId, $decision, $note) {
            $o = $this->repo->findOrder($orderId, true);
            if (!$o) {
                throw new HttpException(404, 'ไม่พบคำสั่งซื้อ');
            }
            if ($decision === 'approve' && $o['status'] !== 'paid' && $this->repo->owns((int) $o['user_id'], (int) $o['product_id'])) {
                throw new ValidationException(['note' => 'ผู้ซื้อได้รับสินค้านี้แล้วจากคำสั่งซื้ออื่น']);
            }
            $status = $decision === 'approve' ? 'paid' : 'rejected';
            $this->repo->reviewOrder($orderId, $status, (int) $admin['id'], $note);
            (new \PromptHub\Repositories\ActivityLogRepository($this->db))->log(
                $admin, $decision === 'approve' ? 'shop.order.approve' : 'shop.order.reject', 'shop_order', $orderId,
                $o['order_no'] . ' · ' . $o['product_title'] . ' · ' . $o['username'] . ($note !== '' ? ' · ' . $note : '')
            );
            return $o;
        });
    }

    // ---------- ไฟล์ ----------

    private function money(Validator $v, array $input, string $field, string $label, bool $nullable): ?float
    {
        $raw = trim(str_replace(',', '', (string) ($input[$field] ?? '')));
        if ($raw === '') {
            if ($nullable) {
                return null;
            }
            $raw = '0';
        }
        if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/D', $raw)) {
            $v->addError($field, "{$label}ต้องเป็นตัวเลข 0–9,999,999.99");
            return $nullable ? null : 0.0;
        }
        return (float) $raw;
    }

    /** ตรวจภาพเดี่ยว (ปก/สลิป) ด้วยกฎของ ImageService คืนข้อมูลไฟล์หรือ null ถ้าไม่ได้เลือก */
    private function validateImage($file, int $maxBytes, string $field, Validator $v): ?array
    {
        if (!is_array($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $multi = [];
        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $k) {
            $multi[$k] = [$file[$k] ?? null];
        }
        [$valid, $error] = (new ImageService($this->db))->validate($multi, $maxBytes);
        if ($error !== null || count($valid) !== 1) {
            $v->addError($field, $error ?? 'ไฟล์ภาพไม่ถูกต้อง');
            return null;
        }
        return $valid[0];
    }

    private function storeImage(array $f, int $maxSide): string
    {
        $img = ImageResizer::fit((string) file_get_contents($f['tmp']), $f['mime'], $f['width'], $f['height'], $maxSide, true);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$img['mime']] ?? $f['ext'];
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $this->write($name, ImageService::stripMetadata($img['data'], $img['mime']));
        return $name;
    }

    /** ตรวจไฟล์ดาวน์โหลด: นามสกุล + MIME จากเนื้อไฟล์ + ลายเซ็น ZIP/PDF */
    private function validateFile($file, array $allowed, string $field, Validator $v): ?array
    {
        if (!is_array($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $label = 'ไฟล์ “' . mb_substr((string) $file['name'], 0, 60) . '”';
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $v->addError($field, $label . ' มีขนาดเกินที่เซิร์ฟเวอร์รับได้ (upload_max_filesize)');
            return null;
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($file['error'] !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            $v->addError($field, $label . ' อัปโหลดไม่สำเร็จ กรุณาลองใหม่');
            return null;
        }
        $size = (int) filesize($tmp);
        if ($size <= 0 || $size > self::FILE_MAX_BYTES) {
            $v->addError($field, $label . ' ต้องมีขนาดไม่เกิน ' . (self::FILE_MAX_BYTES / 1048576) . ' MB');
            return null;
        }
        $original = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]/u', '_', basename(str_replace('\\', '/', (string) $file['name']))) ?? 'file';
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $head = (string) file_get_contents($tmp, false, null, 0, 4);
        $zipLike = in_array($ext, ['zip', 'epub', 'docx', 'pptx', 'xlsx'], true);
        if (!in_array($ext, $allowed, true) || !in_array($mime, self::FILE_TYPES[$ext], true)
            || ($zipLike && $head !== "PK\x03\x04") || ($ext === 'pdf' && $head !== '%PDF')) {
            $v->addError($field, $label . ' ต้องเป็นไฟล์ ' . strtoupper(implode(', ', $allowed)) . ' ที่ถูกต้อง');
            return null;
        }
        return ['tmp' => $tmp, 'ext' => $ext, 'size' => $size, 'original' => mb_substr($original, 0, 200)];
    }

    private function storeFile(array $f): string
    {
        $name = bin2hex(random_bytes(16)) . '.' . $f['ext'];
        $this->ensureDir();
        if (!move_uploaded_file($f['tmp'], self::dir() . '/' . $name)) {
            throw new \RuntimeException('Cannot store shop file');
        }
        return $name;
    }

    private function write(string $name, string $data): void
    {
        $this->ensureDir();
        if (file_put_contents(self::dir() . '/' . $name, $data, LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write shop file');
        }
    }

    private function ensureDir(): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create shop directory');
        }
    }

    // ---------- พร้อมเพย์ ----------

    /** หมายเลขพร้อมเพย์ที่ใช้ได้: เบอร์โทร 10 หลัก / เลขประจำตัว 13 หลัก / e-Wallet 15 หลัก (ไม่ถูกต้องคืน '') */
    public static function promptPayId(string $raw): string
    {
        $id = preg_replace('/\D/', '', $raw) ?? '';
        return in_array(strlen($id), [10, 13, 15], true) ? $id : '';
    }

    /** ข้อความสำหรับ QR พร้อมเพย์ตามมาตรฐาน EMVCo ของธนาคารแห่งประเทศไทย (ระบุยอดเงิน) */
    public static function promptPayPayload(string $id, float $amount): string
    {
        $f = static fn(string $tag, string $value): string => $tag . str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT) . $value;
        $acc = match (strlen($id)) {
            10 => $f('01', '0066' . substr($id, 1)), // เบอร์โทร → 0066 + ตัด 0 นำหน้า
            13 => $f('02', $id),                     // เลขประจำตัวประชาชน/ผู้เสียภาษี
            default => $f('03', $id),                // e-Wallet
        };
        $data = $f('00', '01') . $f('01', $amount > 0 ? '12' : '11')
            . $f('29', $f('00', 'A000000677010111') . $acc)
            . $f('53', '764')
            . ($amount > 0 ? $f('54', number_format($amount, 2, '.', '')) : '')
            . $f('58', 'TH') . '6304';
        return $data . sprintf('%04X', self::crc16($data));
    }

    /** CRC-16/CCITT-FALSE (poly 0x1021, init 0xFFFF) */
    private static function crc16(string $data): int
    {
        $crc = 0xFFFF;
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($b = 0; $b < 8; $b++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }
        return $crc;
    }
}
