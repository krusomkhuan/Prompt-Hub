<?php
declare(strict_types=1);

namespace PromptHub\Services;

/**
 * ย่อขนาดภาพที่อัปโหลดให้เหมาะกับการแสดงผล (ใช้ extension GD)
 *  - ภาพตัวอย่างผลลัพธ์: ด้านยาวไม่เกิน 1600 px คงสัดส่วนเดิม
 *  - ภาพโปรไฟล์: ตัดกลางเป็นสี่เหลี่ยมจัตุรัส 256×256 px
 *  - หมุนภาพ JPEG ตามค่า Orientation ใน EXIF ก่อน (เพราะ EXIF จะถูกตัดออกตอนบันทึก)
 *  - ภาพตัวอย่าง PNG ทึบ (ไม่มีส่วนโปร่งใส) ที่ใหญ่เกิน 1 MB เช่น ภาพจาก AI ที่บันทึกเป็น PNG แปลงเป็น JPEG ถ้าไฟล์เล็กลง
 *  - ภาพที่เล็กกว่าเกณฑ์และไม่ต้องหมุน เก็บไฟล์เดิม ไม่บีบอัดซ้ำ
 *  - ถ้าเซิร์ฟเวอร์ไม่มี GD จะเก็บไฟล์เดิม (ฟอร์มในเบราว์เซอร์ย่อภาพให้ก่อนอัปโหลดอยู่แล้ว)
 */
final class ImageResizer
{
    public const PROMPT_MAX_SIDE = 1600;
    public const AVATAR_SIZE = 256;
    private const JPEG_QUALITY = 85;
    private const WEBP_QUALITY = 85;
    /** PNG ทึบที่ใหญ่กว่านี้ลองแปลงเป็น JPEG */
    public const PNG_TO_JPEG_BYTES = 1024 * 1024;

    public static function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring');
    }

    /**
     * ย่อให้ด้านยาวไม่เกิน $maxSide คืน ['data','mime','width','height','resized']
     * $allowJpeg: อนุญาตให้แปลง PNG ทึบขนาดใหญ่เป็น JPEG (mime ที่คืนอาจเปลี่ยน)
     */
    public static function fit(string $data, string $mime, int $width, int $height, int $maxSide, bool $allowJpeg = false): array
    {
        return self::process($data, $mime, $width, $height, $maxSide, false, $allowJpeg);
    }

    /** ตัดกลางเป็นสี่เหลี่ยมจัตุรัสขนาดไม่เกิน $size */
    public static function square(string $data, string $mime, int $width, int $height, int $size): array
    {
        return self::process($data, $mime, $width, $height, $size, true, false);
    }

    private static function process(string $data, string $mime, int $w, int $h, int $max, bool $square, bool $allowJpeg): array
    {
        $orientation = $mime === 'image/jpeg' ? self::jpegOrientation($data) : 1;
        // หมุน 90° (5–8) ด้านกว้าง/สูงสลับกัน
        [$dw, $dh] = $orientation >= 5 ? [$h, $w] : [$w, $h];
        $needsResize = $square ? ($dw !== $dh || $dw > $max) : max($dw, $dh) > $max;
        // ไม่ได้แปลงภาพ: คืนไฟล์เดิมพร้อมขนาดเดิม
        $original = ['data' => $data, 'mime' => $mime, 'width' => $w, 'height' => $h, 'resized' => false];
        $tryJpeg = $allowJpeg && $mime === 'image/png' && function_exists('imagejpeg');
        $heavyPng = $tryJpeg && strlen($data) > self::PNG_TO_JPEG_BYTES;
        if ((!$needsResize && $orientation === 1 && !$heavyPng) || !self::available() || !self::canEncode($mime)) {
            return $original;
        }
        $src = @imagecreatefromstring($data);
        if ($src === false) {
            return $original;
        }
        try {
            $src = self::orient($src, $orientation);
            $sw = imagesx($src);
            $sh = imagesy($src);
            $sx = $sy = 0;
            if ($square) {
                $side = min($sw, $sh);
                $sx = intdiv($sw - $side, 2);
                $sy = intdiv($sh - $side, 2);
                $sw = $sh = $side;
                $tw = $th = min($side, $max);
            } else {
                $scale = min(1, $max / max($sw, $sh));
                $tw = max(1, (int) round($sw * $scale));
                $th = max(1, (int) round($sh * $scale));
            }
            $dst = imagecreatetruecolor($tw, $th);
            if ($mime !== 'image/jpeg') {
                // คงความโปร่งใสของ PNG / WebP
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            }
            imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $tw, $th, $sw, $sh);
            $outMime = $mime;
            // ไม่ต้องย่อ/หมุน (มาถึงตรงนี้เพราะเป็น PNG หนัก) ใช้ไฟล์เดิมเป็นตัวเทียบ
            $out = $needsResize || $orientation !== 1 ? self::encode($dst, $mime) : $data;
            if ($tryJpeg && $out !== null && strlen($out) > self::PNG_TO_JPEG_BYTES && self::isOpaque($dst, $data)) {
                $jpeg = self::encode($dst, 'image/jpeg');
                if ($jpeg !== null && strlen($jpeg) < strlen($out)) {
                    [$out, $outMime] = [$jpeg, 'image/jpeg'];
                }
            }
            imagedestroy($dst);
        } finally {
            imagedestroy($src);
        }
        if ($out === null || $out === $data) {
            return $original;
        }
        return ['data' => $out, 'mime' => $outMime, 'width' => $tw, 'height' => $th, 'resized' => true];
    }

    /** ภาพไม่มีส่วนโปร่งใส: PNG ชนิด RGB/เทา ที่ไม่มี tRNS ถือว่าทึบ ส่วนชนิดที่มี alpha สุ่มตรวจราว 10,000 จุด */
    private static function isOpaque($im, string $png): bool
    {
        $colorType = strlen($png) > 25 ? ord($png[25]) : -1;
        $hasTrns = strpos($png, 'tRNS') !== false;
        if (in_array($colorType, [0, 2, 3], true)) {
            return !$hasTrns;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        $step = max(1, (int) floor(sqrt($w * $h / 10000)));
        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) !== 0) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function canEncode(string $mime): bool
    {
        return match ($mime) {
            'image/jpeg' => function_exists('imagejpeg'),
            'image/png' => function_exists('imagepng'),
            'image/webp' => function_exists('imagewebp'),
            default => false,
        };
    }

    private static function encode($im, string $mime): ?string
    {
        if ($mime === 'image/jpeg') {
            imageinterlace($im, true); // progressive JPEG แสดงผลเร็วขึ้นบนเน็ตช้า
        }
        ob_start();
        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($im, null, self::JPEG_QUALITY),
            'image/png' => imagepng($im, null, 6),
            'image/webp' => imagewebp($im, null, self::WEBP_QUALITY),
            default => false,
        };
        $data = (string) ob_get_clean();
        return $ok && $data !== '' ? $data : null;
    }

    /** หมุน/กลับภาพตามค่า Orientation ของ EXIF (1 = ปกติ) */
    private static function orient($im, int $o)
    {
        $rotate = static function ($im, int $deg) {
            $r = imagerotate($im, $deg, 0);
            if ($r === false) {
                return $im;
            }
            imagedestroy($im);
            return $r;
        };
        switch ($o) {
            case 2: imageflip($im, IMG_FLIP_HORIZONTAL); break;
            case 3: $im = $rotate($im, 180); break;
            case 4: imageflip($im, IMG_FLIP_VERTICAL); break;
            case 5: $im = $rotate($im, -90); imageflip($im, IMG_FLIP_HORIZONTAL); break;
            case 6: $im = $rotate($im, -90); break;
            case 7: $im = $rotate($im, 90); imageflip($im, IMG_FLIP_HORIZONTAL); break;
            case 8: $im = $rotate($im, 90); break;
        }
        return $im;
    }

    /** อ่านค่า Orientation (1–8) จาก EXIF ของ JPEG โดยตรง ไม่ต้องพึ่ง extension exif */
    public static function jpegOrientation(string $data): int
    {
        $len = strlen($data);
        $p = 2;
        while ($p + 4 <= $len && $data[$p] === "\xFF") {
            $marker = ord($data[$p + 1]);
            $size = unpack('n', substr($data, $p + 2, 2))[1];
            if ($marker === 0xE1 && substr($data, $p + 4, 6) === "Exif\0\0") {
                $tiff = $p + 10;
                $le = substr($data, $tiff, 2) === 'II';
                $u16 = static fn(int $o): int => unpack($le ? 'v' : 'n', substr($data, $o, 2))[1] ?? 0;
                $u32 = static fn(int $o): int => unpack($le ? 'V' : 'N', substr($data, $o, 4))[1] ?? 0;
                if ($tiff + 8 > $len) {
                    return 1;
                }
                $ifd = $tiff + $u32($tiff + 4);
                $count = $ifd + 2 <= $len ? $u16($ifd) : 0;
                for ($i = 0; $i < $count && $ifd + 2 + ($i + 1) * 12 <= $len; $i++) {
                    $entry = $ifd + 2 + $i * 12;
                    if ($u16($entry) === 0x0112) {
                        $v = $u16($entry + 8);
                        return $v >= 1 && $v <= 8 ? $v : 1;
                    }
                }
                return 1;
            }
            if ($marker === 0xDA || $size < 2) {
                break;
            }
            $p += 2 + $size;
        }
        return 1;
    }
}
