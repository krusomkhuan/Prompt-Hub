<?php
declare(strict_types=1);

/**
 * ย่อขนาดภาพที่อัปโหลดไว้ก่อนมีระบบย่อภาพ (ภาพตัวอย่าง ≤ 1600 px, ภาพโปรไฟล์ 256×256)
 *   php bin/resize-images.php --dry-run        ดูว่าจะย่อไฟล์ใดบ้าง (ไม่แก้ไข)
 *   php bin/resize-images.php                  ย่อจริง
 * ต้องมี extension GD — ถ้า php.ini ยังไม่เปิด ให้รันแบบ: php -d extension=gd bin/resize-images.php
 * ไฟล์ใหม่ได้ชื่อใหม่ (เบราว์เซอร์ไม่ใช้ภาพเก่าจากแคช) และลบไฟล์เดิมหลังบันทึกฐานข้อมูลแล้ว
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use PromptHub\Cli\Console;
use PromptHub\Core\Database;
use PromptHub\Services\AvatarService;
use PromptHub\Services\ImageResizer;
use PromptHub\Services\ImageService;

if (!ImageResizer::available()) {
    Console::error('ไม่พบ extension GD — รันใหม่ด้วย: php -d extension=gd bin/resize-images.php');
    exit(1);
}
$dry = in_array('--dry-run', $argv, true);
$db = new Database(load_config()['db']);
$kb = static fn(int $b): string => number_format($b / 1024) . ' KB';
$changed = 0;
$saved = 0;

/** ย่อไฟล์หนึ่งไฟล์ เขียนเป็นชื่อใหม่ คืน [ชื่อใหม่, ผลลัพธ์] หรือ null ถ้าไม่ต้องย่อ */
$process = static function (string $dir, string $file, bool $square) use ($dry): ?array {
    $path = $dir . '/' . $file;
    $data = is_file($path) ? (string) file_get_contents($path) : '';
    $info = $data !== '' ? @getimagesizefromstring($data) : false;
    if (!$info) {
        return null;
    }
    $mime = (string) $info['mime'];
    $r = $square
        ? ImageResizer::square($data, $mime, $info[0], $info[1], ImageResizer::AVATAR_SIZE)
        : ImageResizer::fit($data, $mime, $info[0], $info[1], ImageResizer::PROMPT_MAX_SIDE, true);
    if (!$r['resized']) {
        return null;
    }
    $r['data'] = ImageService::stripMetadata($r['data'], $r['mime']);
    $r['before'] = strlen($data);
    $r['from'] = $info[0] . '×' . $info[1];
    $name = bin2hex(random_bytes(16)) . '.' . array_search($r['mime'], AvatarService::MIME, true);
    if (!$dry && file_put_contents($dir . '/' . $name, $r['data'], LOCK_EX) === false) {
        throw new RuntimeException('เขียนไฟล์ไม่ได้: ' . $name);
    }
    return [$name, $r];
};

foreach ($db->all('SELECT id, file_name FROM prompt_images ORDER BY id') as $row) {
    $res = $process(ImageService::dir(), $row['file_name'], false);
    if ($res === null) {
        continue;
    }
    [$name, $r] = $res;
    Console::line(sprintf('ภาพตัวอย่าง #%d: %s → %d×%d %s, %s → %s', $row['id'], $r['from'], $r['width'], $r['height'], $r['mime'], $kb($r['before']), $kb(strlen($r['data']))));
    if (!$dry) {
        $db->execute('UPDATE prompt_images SET file_name = ?, mime = ?, width = ?, height = ?, bytes = ? WHERE id = ?',
            [$name, $r['mime'], $r['width'], $r['height'], strlen($r['data']), (int) $row['id']]);
        ImageService::deleteFiles([$row['file_name']]);
    }
    $changed++;
    $saved += $r['before'] - strlen($r['data']);
}

foreach ($db->all('SELECT id, username, avatar_file FROM users WHERE avatar_file IS NOT NULL ORDER BY id') as $row) {
    if (!AvatarService::validName($row['avatar_file'])) {
        continue;
    }
    $res = $process(AvatarService::dir(), $row['avatar_file'], true);
    if ($res === null) {
        continue;
    }
    [$name, $r] = $res;
    Console::line(sprintf('ภาพโปรไฟล์ %s: %s → %d×%d, %s → %s', $row['username'], $r['from'], $r['width'], $r['height'], $kb($r['before']), $kb(strlen($r['data']))));
    if (!$dry) {
        $db->execute('UPDATE users SET avatar_file = ? WHERE id = ?', [$name, (int) $row['id']]);
        AvatarService::deleteFile($row['avatar_file']);
    }
    $changed++;
    $saved += $r['before'] - strlen($r['data']);
}

Console::line(($dry ? '[ทดลอง ไม่ได้แก้ไข] ' : '') . "ย่อ {$changed} ไฟล์ ลดพื้นที่ " . $kb(max(0, $saved)));
