<?php
declare(strict_types=1);

namespace PromptHub\Services;

use PromptHub\Core\Database;

/**
 * ภาพตัวอย่างผลลัพธ์ของ Prompt (เฉพาะหมวดหมู่ที่เปิดรับภาพ)
 *  - รับเฉพาะ JPEG / PNG / WebP ตรวจจากเนื้อไฟล์จริง (finfo + getimagesize) ไม่เชื่อนามสกุลหรือ MIME ที่เบราว์เซอร์ส่งมา
 *  - ไม่เกิน 5 MB ต่อภาพ, 4 ภาพต่อ Prompt, ด้านยาวไม่เกิน 8000 px
 *  - ย่อให้ด้านยาวไม่เกิน 1600 px (ImageResizer, ต้องมี GD) และตัดข้อมูลแฝงในไฟล์ (EXIF/GPS, XMP, ข้อความ) ออกก่อนบันทึก
 *  - เก็บใน storage/uploads/prompts (นอก public) ชื่อไฟล์สุ่ม และส่งผ่าน index.php?r=media ที่ตรวจสิทธิ์ตาม Prompt
 *  - เมื่อเชื่อมต่อ Google Drive แล้ว อัปโหลดสำเนาขึ้น Drive และหน้าเว็บแสดงภาพจาก Drive (ไฟล์ในเครื่องเป็นข้อมูลสำรอง)
 */
final class ImageService
{
    public const MAX_IMAGES = 4;
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_SIDE = 8000;
    /** จำกัดจำนวนพิกเซล เพื่อให้ถอดรหัสภาพเพื่อย่อขนาดได้ภายใน memory_limit */
    public const MAX_PIXELS = 40_000_000;
    private const TYPES = [
        'image/jpeg' => [IMAGETYPE_JPEG, 'jpg'],
        'image/png' => [IMAGETYPE_PNG, 'png'],
        'image/webp' => [IMAGETYPE_WEBP, 'webp'],
    ];

    /** รหัสไฟล์บน Drive ของภาพที่กำลังจะถูกลบ — ย้ายไปถังขยะพร้อม deleteFiles() หลัง commit */
    private static array $driveQueue = [];

    public function __construct(private Database $db)
    {
    }

    private static function queueDrive(array $rows): void
    {
        foreach ($rows as $r) {
            if (!empty($r['drive_file_id'])) {
                self::$driveQueue[] = (string) $r['drive_file_id'];
            }
        }
    }

    /** transaction ล้มเหลว: ภาพยังอยู่ ไม่ต้องลบไฟล์บน Drive */
    public static function forgetDriveQueue(): void
    {
        self::$driveQueue = [];
    }

    public static function dir(): string
    {
        return APP_ROOT . '/storage/uploads/prompts';
    }

    public function forPrompt(int $promptId): array
    {
        return $this->db->all('SELECT * FROM prompt_images WHERE prompt_id = ? ORDER BY sort_order, id', [$promptId]);
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM prompt_images WHERE id = ?', [$id]);
    }

    /**
     * แปลง $_FILES['images'] เป็นรายการไฟล์ที่ผ่านการตรวจ คืน [รายการ, ข้อความผิดพลาด|null]
     * ช่องที่ไม่ได้เลือกไฟล์ (UPLOAD_ERR_NO_FILE) ถูกข้าม
     */
    public function validate($files, int $maxBytes = self::MAX_BYTES): array
    {
        if (!is_array($files) || !isset($files['error'])) {
            return [[], null];
        }
        $names = (array) $files['name'];
        $tooBig = ' มีขนาดเกิน ' . round($maxBytes / 1048576, 1) . ' MB';
        $tooBig = str_replace('.0 MB', ' MB', $tooBig);
        $out = [];
        foreach ((array) $files['error'] as $i => $err) {
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $label = 'ไฟล์ “' . mb_substr((string) ($names[$i] ?? ''), 0, 60) . '”';
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                return [[], $label . $tooBig];
            }
            if ($err !== UPLOAD_ERR_OK) {
                return [[], $label . ' อัปโหลดไม่สำเร็จ กรุณาลองใหม่'];
            }
            $tmp = (string) ($files['tmp_name'][$i] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                return [[], $label . ' ไม่ถูกต้อง'];
            }
            $size = (int) filesize($tmp);
            if ($size <= 0 || $size > $maxBytes) {
                return [[], $label . $tooBig];
            }
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
            $info = @getimagesize($tmp);
            if (!isset(self::TYPES[$mime]) || !$info || $info[2] !== self::TYPES[$mime][0]) {
                return [[], $label . ' ไม่ใช่ภาพ JPEG, PNG หรือ WebP'];
            }
            [$w, $h] = $info;
            if ($w < 1 || $h < 1 || $w > self::MAX_SIDE || $h > self::MAX_SIDE || $w * $h > self::MAX_PIXELS) {
                return [[], $label . ' มีขนาดภาพไม่เหมาะสม (ด้านยาวไม่เกิน ' . self::MAX_SIDE . ' px)'];
            }
            $out[] = ['tmp' => $tmp, 'mime' => $mime, 'ext' => self::TYPES[$mime][1], 'width' => $w, 'height' => $h];
        }
        return [$out, null];
    }

    /** บันทึกไฟล์ที่ผ่านการตรวจแล้ว (เรียกภายใน Transaction) คืนรายชื่อไฟล์ที่เขียนลงดิสก์ เพื่อลบทิ้งถ้า Transaction ล้มเหลว */
    public function store(int $promptId, array $validated): array
    {
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create upload directory');
        }
        $order = (int) $this->db->value('SELECT COALESCE(MAX(sort_order), 0) FROM prompt_images WHERE prompt_id = ?', [$promptId]);
        $written = [];
        foreach ($validated as $f) {
            $img = ImageResizer::fit((string) file_get_contents($f['tmp']), $f['mime'], $f['width'], $f['height'], ImageResizer::PROMPT_MAX_SIDE, true);
            $name = bin2hex(random_bytes(16)) . '.' . self::TYPES[$img['mime']][1];
            $data = self::stripMetadata($img['data'], $img['mime']);
            if (file_put_contents($dir . '/' . $name, $data, LOCK_EX) === false) {
                throw new \RuntimeException('Cannot write upload');
            }
            $written[] = $name;
            $this->db->execute(
                'INSERT INTO prompt_images (prompt_id, file_name, mime, width, height, bytes, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$promptId, $name, $img['mime'], $img['width'], $img['height'], strlen($data), ++$order]
            );
        }
        return $written;
    }

    /** ลบภาพที่เลือกของ Prompt นี้ (ภายใน Transaction) คืนชื่อไฟล์ให้ลบจากดิสก์หลัง commit */
    public function removeFromPrompt(int $promptId, array $imageIds): array
    {
        if (!$imageIds) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT id, file_name, drive_file_id FROM prompt_images WHERE prompt_id = ? AND id IN (' . Database::placeholders(count($imageIds)) . ')',
            array_merge([$promptId], $imageIds)
        );
        self::queueDrive($rows);
        foreach ($rows as $r) {
            $this->db->execute('DELETE FROM prompt_images WHERE id = ?', [(int) $r['id']]);
        }
        return array_column($rows, 'file_name');
    }

    /** ชื่อไฟล์ทั้งหมดของ Prompt หนึ่งรายการ / ของสมาชิกหนึ่งคน (เก็บไว้ลบจากดิสก์หลังลบแถวในฐานข้อมูล) */
    public function filesForPrompt(int $promptId): array
    {
        $rows = $this->db->all('SELECT file_name, drive_file_id FROM prompt_images WHERE prompt_id = ?', [$promptId]);
        self::queueDrive($rows);
        return array_column($rows, 'file_name');
    }

    public function filesForUser(int $userId): array
    {
        $rows = $this->db->all(
            'SELECT i.file_name, i.drive_file_id FROM prompt_images i JOIN prompts p ON p.id = i.prompt_id WHERE p.user_id = ?',
            [$userId]
        );
        self::queueDrive($rows);
        return array_column($rows, 'file_name');
    }

    public static function deleteFiles(array $names): void
    {
        $drive = self::$driveQueue;
        self::$driveQueue = [];
        if ($drive && \PromptHub\Core\App::booted()) {
            $app = \PromptHub\Core\App::get();
            (new GoogleDriveService($app->db, $app->config))->trash($drive);
        }
        foreach ($names as $n) {
            if (preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D', (string) $n)) {
                @unlink(self::dir() . '/' . $n);
            }
        }
    }

    /** ตัดข้อมูลแฝง: JPEG APP1 (EXIF/XMP) และ APP13, PNG tEXt/zTXt/iTXt/eXIf/tIME, WebP EXIF/XMP */
    public static function stripMetadata(string $data, string $mime): string
    {
        try {
            if ($mime === 'image/jpeg') {
                return self::stripJpeg($data);
            }
            if ($mime === 'image/png') {
                return self::stripPng($data);
            }
            if ($mime === 'image/webp') {
                return self::stripWebp($data);
            }
        } catch (\Throwable $e) {
            // ไฟล์ผิดรูปแบบภายใน: ใช้ข้อมูลเดิม (ผ่านการตรวจชนิดภาพมาแล้ว)
        }
        return $data;
    }

    private static function stripJpeg(string $d): string
    {
        if (substr($d, 0, 2) !== "\xFF\xD8") {
            return $d;
        }
        $out = "\xFF\xD8";
        $pos = 2;
        $len = strlen($d);
        while ($pos + 4 <= $len) {
            if ($d[$pos] !== "\xFF") {
                return $d;
            }
            $marker = ord($d[$pos + 1]);
            if ($marker === 0xDA) { // Start of Scan: ส่วนที่เหลือคือข้อมูลภาพ
                return $out . substr($d, $pos);
            }
            $segLen = unpack('n', substr($d, $pos + 2, 2))[1];
            $seg = substr($d, $pos, 2 + $segLen);
            if ($marker !== 0xE1 && $marker !== 0xED && $marker !== 0xFE) { // ตัด APP1, APP13, COM
                $out .= $seg;
            }
            $pos += 2 + $segLen;
        }
        return $d;
    }

    private static function stripPng(string $d): string
    {
        $sig = "\x89PNG\r\n\x1a\n";
        if (substr($d, 0, 8) !== $sig) {
            return $d;
        }
        $out = $sig;
        $pos = 8;
        $len = strlen($d);
        while ($pos + 12 <= $len) {
            $chunkLen = unpack('N', substr($d, $pos, 4))[1];
            $type = substr($d, $pos + 4, 4);
            $whole = substr($d, $pos, 12 + $chunkLen);
            if (!in_array($type, ['tEXt', 'zTXt', 'iTXt', 'eXIf', 'tIME'], true)) {
                $out .= $whole;
            }
            $pos += 12 + $chunkLen;
            if ($type === 'IEND') {
                break;
            }
        }
        return $out;
    }

    private static function stripWebp(string $d): string
    {
        if (substr($d, 0, 4) !== 'RIFF' || substr($d, 8, 4) !== 'WEBP') {
            return $d;
        }
        $body = '';
        $pos = 12;
        $len = strlen($d);
        while ($pos + 8 <= $len) {
            $type = substr($d, $pos, 4);
            $size = unpack('V', substr($d, $pos + 4, 4))[1];
            $whole = substr($d, $pos, 8 + $size + ($size % 2));
            if ($type === 'VP8X') {
                // ล้างบิต EXIF (0x08) และ XMP (0x04) ใน flags
                $whole[8] = chr(ord($whole[8]) & ~0x0C);
            }
            if ($type !== 'EXIF' && $type !== 'XMP ') {
                $body .= $whole;
            }
            $pos += 8 + $size + ($size % 2);
        }
        return 'RIFF' . pack('V', 4 + strlen($body)) . 'WEBP' . $body;
    }
}
