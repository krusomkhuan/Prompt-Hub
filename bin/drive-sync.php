<?php
declare(strict_types=1);

/**
 * อัปโหลดภาพตัวอย่างที่ยังไม่อยู่บน Google Drive (เช่น ภาพเดิมก่อนเชื่อมต่อ หรือรอบที่อัปโหลดล้มเหลว)
 *   php bin/drive-sync.php            อัปโหลดทั้งหมดทีละ 50 ภาพจนครบ
 *   php bin/drive-sync.php --dry-run  นับจำนวนภาพที่รออัปโหลด
 * ต้องเชื่อมต่อ Google Drive ที่ หลังบ้าน › Google Drive ก่อน — ไฟล์ในเครื่องไม่ถูกลบ (เก็บเป็นสำรอง)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use PromptHub\Cli\Console;
use PromptHub\Core\Database;
use PromptHub\Services\GoogleDriveService;

$config = load_config();
$db = new Database($config['db']);
$pending = (int) $db->value('SELECT COUNT(*) FROM prompt_images WHERE drive_file_id IS NULL');
if (in_array('--dry-run', $argv, true)) {
    Console::line("รออัปโหลด {$pending} ภาพ");
    exit(0);
}
if (!GoogleDriveService::active()) {
    Console::error('ยังไม่ได้เชื่อมต่อ Google Drive หรือต้องเชื่อมต่อใหม่ (หลังบ้าน › Google Drive)');
    exit(1);
}
$drive = new GoogleDriveService($db, $config);
// ย้ายภาพที่อยู่ในโฟลเดอร์รุ่นเก่าเข้า storage/uploads/prompts ก่อน
do {
    $m = $drive->relocateLegacy(100);
    if ($m['moved'] || $m['remaining']) {
        Console::line("ย้ายภาพเดิม {$m['moved']} ภาพ · เหลือ {$m['remaining']}");
    }
} while ($m['remaining'] > 0 && $m['failed'] === 0);
$total = ['uploaded' => 0, 'failed' => 0];
do {
    $r = $drive->syncPending(null, 50);
    $total['uploaded'] += $r['uploaded'];
    $total['failed'] += $r['failed'];
    Console::line("อัปโหลด {$r['uploaded']} ภาพ · เหลือ {$r['remaining']}");
} while ($r['uploaded'] > 0 && $r['remaining'] > 0 && $r['failed'] === 0);
Console::line("เสร็จ: อัปโหลด {$total['uploaded']} ภาพ" . ($total['failed'] ? " ไม่สำเร็จ {$total['failed']} ภาพ (ดู storage/logs)" : ''));
exit($total['failed'] ? 1 : 0);
