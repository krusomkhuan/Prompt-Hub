<?php
declare(strict_types=1);

namespace PromptHub\Services;

use PromptHub\Core\Database;
use PromptHub\Core\ValidationException;

/**
 * ภาพโปรไฟล์ของผู้ใช้ (ทั้งสมาชิกและผู้ดูแล)
 *  - ตรวจไฟล์ด้วยกฎเดียวกับภาพตัวอย่าง (JPEG / PNG / WebP ตรวจจากเนื้อไฟล์, ตัด EXIF/GPS) แต่จำกัด 2 MB
 *  - ตัดกลางและย่อเป็นสี่เหลี่ยมจัตุรัส 256×256 px (ImageResizer, ต้องมี GD)
 *  - เก็บใน storage/uploads/avatars (นอก public) ชื่อไฟล์สุ่ม และส่งผ่าน index.php?r=avatar
 *  - เปลี่ยนภาพใหม่หรือลบภาพ ไฟล์เดิมถูกลบจากดิสก์หลังบันทึกฐานข้อมูลสำเร็จ
 */
final class AvatarService
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MIME = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public function __construct(private Database $db)
    {
    }

    public static function dir(): string
    {
        return APP_ROOT . '/storage/uploads/avatars';
    }

    public static function validName(?string $name): bool
    {
        return $name !== null && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D', $name) === 1;
    }

    /** ตรวจและบันทึกภาพใหม่จาก $_FILES['avatar'] แล้วลบภาพเดิม */
    public function update(int $userId, $file): void
    {
        if (!is_array($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            throw new ValidationException(['avatar' => 'กรุณาเลือกไฟล์ภาพ']);
        }
        // แปลงเป็นรูปแบบหลายไฟล์ เพื่อใช้การตรวจเดียวกับภาพตัวอย่าง
        $multi = [];
        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $k) {
            $multi[$k] = [$file[$k] ?? null];
        }
        [$valid, $error] = (new ImageService($this->db))->validate($multi, self::MAX_BYTES);
        if ($error !== null || count($valid) !== 1) {
            throw new ValidationException(['avatar' => $error ?? 'กรุณาเลือกไฟล์ภาพ']);
        }
        $f = $valid[0];
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create avatar directory');
        }
        $name = bin2hex(random_bytes(16)) . '.' . $f['ext'];
        $img = ImageResizer::square((string) file_get_contents($f['tmp']), $f['mime'], $f['width'], $f['height'], ImageResizer::AVATAR_SIZE);
        $data = ImageService::stripMetadata($img['data'], $f['mime']);
        if (file_put_contents($dir . '/' . $name, $data, LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write avatar');
        }
        try {
            $old = $this->db->transaction(function () use ($userId, $name) {
                $old = $this->db->value('SELECT avatar_file FROM users WHERE id = ? FOR UPDATE', [$userId]);
                $this->db->execute('UPDATE users SET avatar_file = ? WHERE id = ?', [$name, $userId]);
                return $old;
            });
        } catch (\Throwable $e) {
            self::deleteFile($name);
            throw $e;
        }
        self::deleteFile($old === null ? null : (string) $old);
    }

    /** ลบภาพโปรไฟล์ (กลับไปใช้ตัวอักษรย่อ) คืน true ถ้ามีภาพให้ลบ */
    public function remove(int $userId): bool
    {
        $old = $this->db->transaction(function () use ($userId) {
            $old = $this->db->value('SELECT avatar_file FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $this->db->execute('UPDATE users SET avatar_file = NULL WHERE id = ?', [$userId]);
            return $old;
        });
        self::deleteFile($old === null ? null : (string) $old);
        return $old !== null;
    }

    public static function deleteFile(?string $name): void
    {
        if (self::validName($name)) {
            @unlink(self::dir() . '/' . $name);
        }
    }
}
