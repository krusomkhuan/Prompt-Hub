<?php
declare(strict_types=1);

/**
 * ตัวช่วยสำหรับชุดทดสอบ (CLI เท่านั้น) — ใช้กับฐานข้อมูลทดสอบ/ทดลองเท่านั้น
 *   php tests/fixtures.php create          สร้างบัญชีทดสอบ admin/member/other (รหัสผ่านสุ่ม) พิมพ์ JSON
 *   php tests/fixtures.php query            อ่าน JSON {"sql": "...", "params": [...]} จาก STDIN (SELECT เท่านั้น)
 *   php tests/fixtures.php cleanup SUFFIX   ลบบัญชีและข้อมูลที่ชุดทดสอบสร้าง
 *   php tests/fixtures.php settings-restore  อ่าน JSON {key: value} จาก STDIN คืนค่า google_login_enabled / google_client_id / google_client_secret
 *   php tests/fixtures.php drive-reset IDS  ล้างรหัสไฟล์ Drive จำลอง (drivefileNNNNNN) ของภาพเดิมที่ชุดทดสอบ drive.py อัปโหลดขึ้นเซิร์ฟเวอร์จำลอง
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use PromptHub\Core\Database;

$db = new Database(load_config()['db']);
$cmd = $argv[1] ?? '';

if ($cmd === 'create') {
    $suffix = bin2hex(random_bytes(4));
    $password = 'T' . bin2hex(random_bytes(10)) . '!';
    $out = ['suffix' => $suffix, 'password' => $password];
    foreach (['admin' => 'admin', 'member' => 'member', 'other' => 'member'] as $key => $role) {
        $username = 't_' . $key . '_' . $suffix;
        $db->execute(
            'INSERT INTO users (username, email, display_name, password_hash, role) VALUES (?, ?, ?, ?, ?)',
            [$username, $username . '@example.invalid', 'ทดสอบ ' . $key, password_hash($password, PASSWORD_DEFAULT), $role]
        );
        $out[$key] = ['id' => $db->lastId(), 'username' => $username];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} elseif ($cmd === 'query') {
    $req = json_decode((string) stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    if (!preg_match('/^\s*SELECT\s/i', (string) $req['sql'])) {
        fwrite(STDERR, "SELECT only\n");
        exit(2);
    }
    echo json_encode($db->all($req['sql'], $req['params'] ?? []), JSON_UNESCAPED_UNICODE);
} elseif ($cmd === 'backup-settings') {
    // คืนค่าตั้งค่าสำรองข้อมูลหลังทดสอบ (เฉพาะ 2 คีย์นี้) — ค่า null = ลบแถว
    $req = json_decode((string) stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
    foreach (['drive_backup_folder_id', 'drive_backup_last'] as $k) {
        $db->execute('DELETE FROM site_settings WHERE setting_key = ?', [$k]);
        if (isset($req[$k]) && is_string($req[$k])) {
            $db->execute('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)', [$k, $req[$k]]);
        }
    }
    echo "ok";
} elseif ($cmd === 'expire-resets') {
    // ทดสอบลิงก์หมดอายุ: เลื่อนเวลาหมดอายุของลิงก์ตั้งรหัสผ่านของผู้ใช้ทดสอบไปในอดีต
    $username = $argv[2] ?? '';
    if (!preg_match('/^t_[a-z]+_[a-f0-9]{8}$/D', $username)) {
        exit(1);
    }
    echo $db->execute(
        'UPDATE password_resets r JOIN users u ON u.id = r.user_id SET r.expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE u.username = ?',
        [$username]
    );
} elseif ($cmd === 'settings-restore') {
    // คืนค่าการตั้งค่า Google Login ตามที่ชุดทดสอบบันทึกไว้ก่อนเริ่ม (เฉพาะ 3 คีย์นี้)
    $values = json_decode((string) stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
    foreach (['google_login_enabled', 'google_client_id', 'google_client_secret'] as $key) {
        if (array_key_exists($key, $values) && $values[$key] !== null) {
            $db->execute('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, (string) $values[$key]]);
        } else {
            $db->execute('DELETE FROM site_settings WHERE setting_key = ?', [$key]);
        }
    }
    echo 'ok';
} elseif ($cmd === 'drive-reset') {
    // ใช้เฉพาะรหัสจากเซิร์ฟเวอร์จำลองเท่านั้น ไม่แตะรหัสไฟล์ Drive จริง
    $ids = array_values(array_filter(array_map('intval', explode(',', $argv[2] ?? '')), static fn($i) => $i > 0));
    if ($ids) {
        echo $db->execute(
            "UPDATE prompt_images SET drive_file_id = NULL WHERE drive_file_id REGEXP '^drivefile[0-9]{6}$' AND id IN (" . Database::placeholders(count($ids)) . ')',
            $ids
        );
    }
} elseif ($cmd === 'cleanup') {
    $suffix = $argv[2] ?? '';
    if (!preg_match('/^[a-f0-9]{8}$/D', $suffix)) {
        exit(1);
    }
    $db->transaction(function () use ($db, $suffix) {
        $like = '%\_' . $suffix;
        $ids = array_column($db->all('SELECT id FROM users WHERE username LIKE ?', [$like]), 'id');
        $images = new PromptHub\Services\ImageService($db);
        // ถ้าเชื่อม Google Drive จริงไว้ ไฟล์ภาพทดสอบบน Drive ถูกย้ายไปถังขยะด้วย (ไม่ทิ้งไฟล์ค้าง)
        $driveIds = $ids ? array_column($db->all(
            'SELECT i.drive_file_id FROM prompt_images i JOIN prompts p ON p.id = i.prompt_id WHERE i.drive_file_id IS NOT NULL AND p.user_id IN ('
            . Database::placeholders(count($ids)) . ')', array_map('intval', $ids)), 'drive_file_id') : [];
        if ($driveIds && PromptHub\Services\GoogleDriveService::active()) {
            (new PromptHub\Services\GoogleDriveService($db, load_config()))->trash($driveIds);
        }
        foreach ($ids as $id) {
            PromptHub\Services\ImageService::deleteFiles($images->filesForUser((int) $id));
            $avatar = $db->value('SELECT avatar_file FROM users WHERE id = ?', [(int) $id]);
            PromptHub\Services\AvatarService::deleteFile($avatar === null ? null : (string) $avatar);
            $db->execute('DELETE FROM admin_activity_logs WHERE admin_id = ?', [(int) $id]);
            $db->execute('DELETE FROM users WHERE id = ?', [(int) $id]);
        }
        $db->execute('DELETE FROM categories WHERE name LIKE ?', ['%' . $suffix . '%']);
        $db->execute('DELETE FROM tags WHERE name LIKE ?', ['%' . $suffix . '%']);
        $db->execute('DELETE FROM ai_tools WHERE name LIKE ?', ['%' . $suffix . '%']);
        $db->execute('DELETE FROM login_attempts');
        echo json_encode(['removed_users' => count($ids)]);
    });
} else {
    fwrite(STDERR, "usage: create | query | cleanup SUFFIX\n");
    exit(1);
}
