<?php
declare(strict_types=1);

/**
 * ทดสอบการย่อขนาดภาพ (ImageResizer) — CLI เท่านั้น ไม่แตะฐานข้อมูล
 *   php tests/image_resize.php                   ทดสอบการย่อ/ตัด/หมุนภาพด้วย GD (ถ้ายังไม่เปิดใน php.ini: php -d extension=gd ...)
 *   php -c ini-ที่ปิด-gd tests/image_resize.php   (ไม่มี GD) ทดสอบว่าเก็บไฟล์เดิมโดยไม่เกิดข้อผิดพลาด
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use PromptHub\Services\ImageResizer as R;
use PromptHub\Services\ImageService;

$passed = 0;
function check(bool $ok, string $name): void
{
    global $passed;
    if (!$ok) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }
    $passed++;
    echo "PASS $name\n";
}

/** ใส่ APP1 (EXIF) ที่มีเฉพาะ Orientation ลงใน JPEG */
function with_orientation(string $jpeg, int $o, bool $littleEndian): string
{
    $tiff = $littleEndian
        ? 'II' . pack('v', 42) . pack('V', 8) . pack('v', 1) . pack('vvV', 0x0112, 3, 1) . pack('v', $o) . "\0\0" . pack('V', 0)
        : 'MM' . pack('n', 42) . pack('N', 8) . pack('n', 1) . pack('nnN', 0x0112, 3, 1) . pack('n', $o) . "\0\0" . pack('N', 0);
    $payload = "Exif\0\0" . $tiff;
    return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
}

// ---------- ตัวอ่าน Orientation (ไม่ต้องใช้ GD) ----------
$tinyJpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
check(R::jpegOrientation($tinyJpeg) === 1, 'JPEG ที่ไม่มี EXIF อ่าน Orientation ได้ 1');
check(R::jpegOrientation(with_orientation($tinyJpeg, 6, true)) === 6, 'อ่าน Orientation 6 แบบ little-endian (II)');
check(R::jpegOrientation(with_orientation($tinyJpeg, 8, false)) === 8, 'อ่าน Orientation 8 แบบ big-endian (MM)');
check(R::jpegOrientation("\xFF\xD8\xFF\xE1\x00\x08Exif\0\0") === 1, 'ข้อมูล EXIF ขาด/เสีย ไม่ทำให้ล้ม (คืน 1)');

if (!R::available()) {
    $data = random_bytes(64);
    $r = R::fit($data, 'image/jpeg', 4000, 3000, R::PROMPT_MAX_SIDE);
    check($r['data'] === $data && $r['width'] === 4000 && !$r['resized'], 'ไม่มี GD: เก็บไฟล์เดิมและขนาดเดิม');
    $r = R::square($data, 'image/png', 900, 600, R::AVATAR_SIZE);
    check($r['data'] === $data && !$r['resized'], 'ไม่มี GD: ภาพโปรไฟล์เก็บไฟล์เดิม');
    echo "\nTOTAL $passed passed (ไม่มี GD)\n";
    exit(0);
}

function png_of($im): string
{
    ob_start();
    imagepng($im);
    return (string) ob_get_clean();
}

function jpeg_of($im): string
{
    ob_start();
    imagejpeg($im, null, 92);
    return (string) ob_get_clean();
}

// ภาพถ่ายใหญ่ 3000×2000 → ด้านยาว 1600
$im = imagecreatetruecolor(3000, 2000);
for ($i = 0; $i < 400; $i++) {
    imagefilledellipse($im, random_int(0, 3000), random_int(0, 2000), random_int(20, 300), random_int(20, 300), imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
}
$big = jpeg_of($im);
imagedestroy($im);
$r = R::fit($big, 'image/jpeg', 3000, 2000, R::PROMPT_MAX_SIDE);
$info = getimagesizefromstring($r['data']);
check($r['resized'] && $r['width'] === 1600 && $r['height'] === 1067 && $info[0] === 1600 && $info[1] === 1067 && $info[2] === IMAGETYPE_JPEG,
    'JPEG 3000×2000 ย่อเป็น 1600×1067 (คงสัดส่วน)');
check(strlen($r['data']) < strlen($big), sprintf('ไฟล์เล็กลง %.0f KB → %.0f KB', strlen($big) / 1024, strlen($r['data']) / 1024));

// ภาพแนวตั้ง
$im = imagecreatetruecolor(1000, 2500);
$r = R::fit(png_of($im), 'image/png', 1000, 2500, R::PROMPT_MAX_SIDE);
check($r['width'] === 640 && $r['height'] === 1600, 'ภาพแนวตั้ง 1000×2500 ย่อเป็น 640×1600');
imagedestroy($im);

// ภาพเล็กกว่าเกณฑ์: เก็บไฟล์เดิมทุกไบต์
$im = imagecreatetruecolor(800, 600);
$small = png_of($im);
imagedestroy($im);
$r = R::fit($small, 'image/png', 800, 600, R::PROMPT_MAX_SIDE);
check(!$r['resized'] && $r['data'] === $small && $r['width'] === 800, 'ภาพ 800×600 ไม่ถูกบีบอัดซ้ำ');

// PNG โปร่งใสต้องยังโปร่งใส
$im = imagecreatetruecolor(2400, 1200);
imagealphablending($im, false);
imagesavealpha($im, true);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagefilledrectangle($im, 800, 300, 1600, 900, imagecolorallocatealpha($im, 147, 51, 234, 0));
$r = R::fit(png_of($im), 'image/png', 2400, 1200, R::PROMPT_MAX_SIDE);
imagedestroy($im);
$out = imagecreatefromstring($r['data']);
$corner = imagecolorsforindex($out, imagecolorat($out, 2, 2));
$center = imagecolorsforindex($out, imagecolorat($out, 800, 400));
check($r['width'] === 1600 && $r['height'] === 800 && $corner['alpha'] === 127 && $center['alpha'] === 0 && $center['red'] > 130,
    'PNG โปร่งใส 2400×1200 ย่อแล้วยังโปร่งใสและสีถูกต้อง');
imagedestroy($out);

// WebP
if (function_exists('imagewebp')) {
    $im = imagecreatetruecolor(2000, 2000);
    ob_start();
    imagewebp($im);
    $webp = (string) ob_get_clean();
    imagedestroy($im);
    $r = R::fit($webp, 'image/webp', 2000, 2000, R::PROMPT_MAX_SIDE);
    $info = getimagesizefromstring($r['data']);
    check($r['resized'] && $info[0] === 1600 && $info[2] === IMAGETYPE_WEBP, 'WebP 2000×2000 ย่อเป็น 1600×1600 และยังเป็น WebP');
}

// JPEG จากมือถือ: Orientation 6 (หมุน 90° ตามเข็ม) แม้ภาพเล็กก็ต้องหมุนให้ตั้งตรง
$im = imagecreatetruecolor(400, 200);
imagefilledrectangle($im, 0, 0, 199, 199, imagecolorallocate($im, 255, 0, 0));    // ซ้าย: แดง
imagefilledrectangle($im, 200, 0, 399, 199, imagecolorallocate($im, 0, 0, 255));  // ขวา: น้ำเงิน
$rotated = with_orientation(jpeg_of($im), 6, true);
imagedestroy($im);
$r = R::fit($rotated, 'image/jpeg', 400, 200, R::PROMPT_MAX_SIDE);
$out = imagecreatefromstring($r['data']);
$top = imagecolorsforindex($out, imagecolorat($out, 100, 50));
$bottom = imagecolorsforindex($out, imagecolorat($out, 100, 350));
check($r['resized'] && $r['width'] === 200 && $r['height'] === 400 && $top['red'] > 200 && $bottom['blue'] > 200,
    'JPEG Orientation 6 หมุนเป็นแนวตั้ง 200×400 (ด้านซ้ายเดิมอยู่บน)');
check(R::jpegOrientation($r['data']) === 1, 'ไฟล์ที่หมุนแล้วไม่มีค่า Orientation ค้าง');
imagedestroy($out);

// ภาพโปรไฟล์: ตัดกลางเป็นสี่เหลี่ยมจัตุรัส 256×256
$im = imagecreatetruecolor(1200, 800);
imagefilledrectangle($im, 0, 0, 199, 799, imagecolorallocate($im, 255, 0, 0));
imagefilledrectangle($im, 200, 0, 999, 799, imagecolorallocate($im, 0, 200, 0));
imagefilledrectangle($im, 1000, 0, 1199, 799, imagecolorallocate($im, 0, 0, 255));
$r = R::square(png_of($im), 'image/png', 1200, 800, R::AVATAR_SIZE);
imagedestroy($im);
$out = imagecreatefromstring($r['data']);
$left = imagecolorsforindex($out, imagecolorat($out, 3, 128));
$right = imagecolorsforindex($out, imagecolorat($out, 252, 128));
check($r['width'] === 256 && $r['height'] === 256 && imagesx($out) === 256 && $left['green'] > 150 && $left['red'] < 60 && $right['green'] > 150 && $right['blue'] < 60,
    'ภาพโปรไฟล์ 1200×800 ตัดกลางเป็น 256×256 (ขอบแดง/น้ำเงินถูกตัดออก)');
imagedestroy($out);

$im = imagecreatetruecolor(180, 120);
$r = R::square(png_of($im), 'image/png', 180, 120, R::AVATAR_SIZE);
check($r['width'] === 120 && $r['height'] === 120, 'ภาพโปรไฟล์เล็ก 180×120 ตัดเป็น 120×120 (ไม่ขยายภาพ)');
imagedestroy($im);

// PNG ทึบขนาดใหญ่ (เช่น ภาพจาก AI) แปลงเป็น JPEG เมื่ออนุญาต
$im = imagecreatetruecolor(1400, 1400);
for ($y = 0; $y < 1400; $y += 2) {
    for ($x = 0; $x < 1400; $x += 2) {
        imagefilledrectangle($im, $x, $y, $x + 1, $y + 1, imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    }
}
$heavy = png_of($im);
imagedestroy($im);
check(strlen($heavy) > R::PNG_TO_JPEG_BYTES, sprintf('สร้าง PNG ทึบขนาด %.1f MB สำหรับทดสอบ', strlen($heavy) / 1048576));
$r = R::fit($heavy, 'image/png', 1400, 1400, R::PROMPT_MAX_SIDE, true);
$info = getimagesizefromstring($r['data']);
check($r['resized'] && $r['mime'] === 'image/jpeg' && $info[2] === IMAGETYPE_JPEG && $info[0] === 1400 && strlen($r['data']) < strlen($heavy),
    sprintf('PNG ทึบ 1400×1400 (ไม่ต้องย่อ) แปลงเป็น JPEG %.1f MB → %.1f MB', strlen($heavy) / 1048576, strlen($r['data']) / 1048576));
$r = R::fit($heavy, 'image/png', 1400, 1400, R::PROMPT_MAX_SIDE);
check(!$r['resized'] && $r['mime'] === 'image/png' && $r['data'] === $heavy, 'ไม่อนุญาตแปลง (เช่น ภาพโปรไฟล์) PNG คงเดิม');

// PNG ที่มีส่วนโปร่งใสขนาดใหญ่ ต้องคงเป็น PNG
$im = imagecreatetruecolor(1400, 1400);
imagealphablending($im, false);
imagesavealpha($im, true);
for ($y = 0; $y < 1400; $y += 2) {
    for ($x = 0; $x < 1400; $x += 2) {
        imagefilledrectangle($im, $x, $y, $x + 1, $y + 1, imagecolorallocatealpha($im, random_int(0, 255), random_int(0, 255), 90, $x < 100 ? 127 : 0));
    }
}
$alphaPng = png_of($im);
imagedestroy($im);
$r = R::fit($alphaPng, 'image/png', 1400, 1400, R::PROMPT_MAX_SIDE, true);
check($r['mime'] === 'image/png' && !$r['resized'], 'PNG ที่มีส่วนโปร่งใสไม่ถูกแปลงเป็น JPEG');

// ย่อแล้วยังผ่านการตัดข้อมูลแฝงตามปกติ
$stripped = ImageService::stripMetadata(with_orientation($tinyJpeg, 3, true), 'image/jpeg');
check(strpos($stripped, 'Exif') === false, 'stripMetadata ตัด EXIF ออกตามเดิม');

echo "\nTOTAL $passed passed (GD " . GD_VERSION . ")\n";
