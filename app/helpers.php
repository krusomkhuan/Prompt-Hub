<?php
declare(strict_types=1);

use PromptHub\Core\App;
use PromptHub\Core\Csrf;

/** escape สำหรับแสดงใน HTML (ข้อความและค่าใน attribute) */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** สร้าง URL ของเส้นทาง พร้อมพารามิเตอร์ (ค่าว่างถูกตัดทิ้ง) */
function url(string $route = 'home', array $params = []): string
{
    $base = App::booted() ? App::get()->baseUrl() : '';
    $params = array_filter($params, static fn($v) => $v !== null && $v !== '' && $v !== []);
    if ($route === 'home' && !$params) {
        return $base . '/';
    }
    return $base . '/index.php?' . http_build_query(['r' => $route] + $params);
}

/**
 * แอตทริบิวต์ src ของภาพตัวอย่าง: ใช้ลิงก์ตรงของ Google Drive เมื่อเชื่อมต่อไว้ และมีภาพในเครื่องเป็นทางสำรอง (data-fallback)
 * คืนสตริง HTML attribute ที่ escape แล้ว เช่น src="..." data-fallback="..."
 */
function prompt_image_attrs(int $imageId, ?string $driveId, int $width = 1600): string
{
    $local = url('media', ['id' => $imageId]);
    if ($driveId !== null && $driveId !== '' && \PromptHub\Services\GoogleDriveService::active()) {
        return 'src="' . e(\PromptHub\Services\GoogleDriveService::imageUrl($driveId, $width)) . '" data-fallback="' . e($local) . '" referrerpolicy="no-referrer"';
    }
    return 'src="' . e($local) . '"';
}

/** ลิงก์แบบเต็ม (มีโดเมน) ตาม URL เว็บไซต์ที่ตั้งไว้ สำหรับอีเมล ลิงก์แชร์ และ OAuth */
function absolute_url(string $route = 'home', array $params = []): string
{
    $app = App::get();
    return $app->publicUrl() . substr(url($route, $params), strlen($app->baseUrl()));
}

/**
 * ตรวจและจัดรูป URL เว็บไซต์: ต้องเป็น http(s) มีโดเมน ไม่มีชื่อผู้ใช้/รหัสผ่าน query หรือ # คืน null ถ้าไม่ถูกต้อง
 * เช่น "https://Prompt.Example.ac.th/hub/" → "https://prompt.example.ac.th/hub"
 */
function normalize_site_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '' || preg_match('/\s/u', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }
    $p = parse_url($url);
    $scheme = strtolower((string) ($p['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass'])
        || isset($p['query']) || isset($p['fragment']) || str_contains($url, '?') || str_contains($url, '#')) {
        return null;
    }
    $path = rtrim((string) ($p['path'] ?? ''), '/');
    if (preg_match('#/index\.php$#i', $path) || str_contains($path, '..')) {
        return null;
    }
    return $scheme . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . (int) $p['port'] : '') . $path;
}

function asset(string $path): string
{
    $file = APP_ROOT . '/public/assets/' . $path;
    $v = is_file($file) ? (string) filemtime($file) : '1';
    return (App::booted() ? App::get()->baseUrl() : '') . '/assets/' . $path . '?v=' . $v;
}

function redirect(string $route = 'home', array $params = []): void
{
    header('Location: ' . url($route, $params), true, 303);
    exit;
}

function csrf_field(): string
{
    return Csrf::field();
}

function csrf_token(): string
{
    return Csrf::token();
}

/** แปลงเวลา UTC จากฐานข้อมูลเป็นเวลาไทย */
function thai_date(?string $utc, bool $withTime = true): string
{
    if ($utc === null || $utc === '') {
        return '-';
    }
    static $months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $tz = App::booted() ? (string) (App::get()->config['app']['display_timezone'] ?? 'Asia/Bangkok') : 'Asia/Bangkok';
    $d = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz));
    $s = (int) $d->format('j') . ' ' . $months[(int) $d->format('n')] . ' ' . ((int) $d->format('Y') + 543);
    return $withTime ? $s . ' ' . $d->format('H:i') . ' น.' : $s;
}

/** ข้อความลิขสิทธิ์ เช่น "© 2026 ครูสมควร สากำ. All rights reserved." ปีปัจจุบันมากกว่าปีเริ่มจะแสดงเป็นช่วงปี */
function copyright_notice(string $owner, string $startYear): string
{
    $tz = App::booted() ? (string) (App::get()->config['app']['display_timezone'] ?? 'Asia/Bangkok') : 'Asia/Bangkok';
    $now = (int) (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y');
    $start = preg_match('/^\d{4}$/D', $startYear) ? (int) $startYear : $now;
    $years = $start < $now ? $start . '–' . $now : (string) $start;
    return '© ' . $years . ($owner !== '' ? ' ' . rtrim($owner, '.') : '') . '. All rights reserved.';
}

function number_th(int $n): string
{
    return number_format($n);
}

function status_label(string $status): string
{
    return [
        'draft' => 'ฉบับร่าง',
        'pending' => 'รอตรวจสอบ',
        'published' => 'เผยแพร่',
        'rejected' => 'ไม่อนุมัติ',
    ][$status] ?? $status;
}

function status_badge(string $status): string
{
    $cls = [
        'draft' => 'text-bg-secondary',
        'pending' => 'text-bg-warning',
        'published' => 'text-bg-success',
        'rejected' => 'text-bg-danger',
    ][$status] ?? 'text-bg-light';
    return '<span class="badge ' . $cls . '">' . e(status_label($status)) . '</span>';
}

/**
 * ภาพโปรไฟล์ขนาด sm (28px) md (40px) lg (112px) ถ้าไม่มีภาพแสดงอักษรตัวแรกของชื่อ
 * URL มี v=ส่วนหนึ่งของชื่อไฟล์ เพื่อให้เบราว์เซอร์โหลดใหม่เมื่อเปลี่ยนภาพ
 */
function avatar(int $userId, string $name, ?string $file, string $size = 'sm'): string
{
    $px = ['sm' => 28, 'md' => 40, 'lg' => 112][$size] ?? 28;
    if ($file !== null && $file !== '') {
        return '<img class="avatar avatar-' . e($size) . '" src="' . e(url('avatar', ['id' => $userId, 'v' => substr($file, 0, 8)])) . '" alt="" width="' . $px . '" height="' . $px . '" loading="lazy">';
    }
    $initial = mb_strtoupper(mb_substr(trim($name), 0, 1)) ?: '?';
    return '<span class="avatar avatar-' . e($size) . ' avatar-initial" aria-hidden="true">' . e($initial) . '</span>';
}

/** ป้ายเหรียญตามแต้มสะสม (ว่างถ้ายังไม่ถึงเหรียญทองแดง) */
function medal_badge(int $points, bool $withLabel = true): string
{
    $tier = \PromptHub\Services\BadgeService::tierFor($points);
    if (!$tier) {
        return '';
    }
    $title = $tier['label'] . ' · ' . number_format($points) . ' แต้ม';
    return '<span class="medal medal-' . $tier['key'] . '" title="' . e($title) . '"><i class="bi bi-award-fill" aria-hidden="true"></i>'
        . ($withLabel ? e($tier['label']) : '<span class="visually-hidden">' . e($tier['label']) . '</span>') . '</span>';
}

function visibility_label(string $v): string
{
    return $v === 'public' ? 'สาธารณะ' : 'ส่วนตัว';
}

/** ชื่อไอคอน Bootstrap Icons ที่ปลอดภัย (a-z 0-9 -) */
function icon_name(string $name): string
{
    return preg_match('/^[a-z0-9-]{1,50}$/D', $name) ? $name : 'folder';
}

/** ค่าเดิมในฟอร์ม */
function old(array $old, string $key, $default = '')
{
    return $old[$key] ?? $default;
}

function field_error(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<div class="invalid-feedback d-block">' . e($errors[$key]) . '</div>' : '';
}

function invalid(array $errors, string $key): string
{
    return isset($errors[$key]) ? ' is-invalid' : '';
}

function str_limit(string $s, int $len): string
{
    return mb_strlen($s) > $len ? mb_substr($s, 0, $len) . '…' : $s;
}

/** เปิดใช้การเข้าสู่ระบบด้วย Google หรือไม่ (มี client_id และ client_secret ในไฟล์ตั้งค่า) */
function google_enabled(): bool
{
    if (!App::booted()) {
        return false;
    }
    $g = (array) (App::get()->config['google'] ?? []);
    return !empty($g['enabled']) && (string) ($g['client_id'] ?? '') !== '' && (string) ($g['client_secret'] ?? '') !== '';
}

function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/** ป้ายสถานะคำสั่งซื้อ Digital Shop */
function shop_order_badge(string $status): string
{
    [$label, $cls] = [
        'pending' => ['รอตรวจสอบการชำระเงิน', 'text-bg-warning'],
        'paid' => ['ได้รับสินค้าแล้ว', 'text-bg-success'],
        'rejected' => ['ไม่ผ่านการตรวจสอบ', 'text-bg-danger'],
    ][$status] ?? [$status, 'text-bg-light'];
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}
