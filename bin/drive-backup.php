<?php
declare(strict_types=1);

/**
 * สำรองข้อมูลทั้งระบบ (ฐานข้อมูล + storage/uploads) เป็น zip แล้วอัปโหลดขึ้นโฟลเดอร์สำรองบน Google Drive
 *   php bin/drive-backup.php             สร้างไฟล์และอัปโหลด
 *   php bin/drive-backup.php --local     สร้างไฟล์ใน storage/backups อย่างเดียว ไม่อัปโหลด
 * ตั้ง cron ให้รันวันละครั้งได้ เช่น  0 2 * * * php /path/prompthub/bin/drive-backup.php
 * ต้องเชื่อมต่อ Google Drive ที่ หลังบ้าน › Google Drive ก่อน · ไม่ได้ตั้งโฟลเดอร์สำรองแยก = โฟลเดอร์หลัก/storage/backups
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use PromptHub\Cli\Console;
use PromptHub\Core\Database;
use PromptHub\Repositories\SettingRepository;
use PromptHub\Services\BackupService;
use PromptHub\Services\GoogleDriveService;

try {
    $config = load_config();
    $db = new Database($config['db']);
    $settings = (new SettingRepository($db))->all();
    $folderId = (string) ($settings['drive_backup_folder_id'] ?? '');
    $localOnly = in_array('--local', $argv, true);
    if (!$localOnly && !GoogleDriveService::active()) {
        Console::error('ยังไม่ได้เชื่อมต่อ Google Drive หรือต้องเชื่อมต่อใหม่ (หลังบ้าน › Google Drive)');
        exit(1);
    }
    $backup = (new BackupService($db))->create();
    Console::line(sprintf('สร้างไฟล์ %s (%.1f MB · %d ตาราง · %d ไฟล์)', $backup['name'], $backup['size'] / 1048576, $backup['tables'], $backup['files']));
    if ($localOnly) {
        exit(0);
    }
    $drive = new GoogleDriveService($db, $config);
    // ไม่ได้ตั้งโฟลเดอร์สำรองแยก → โฟลเดอร์หลัก/storage/backups (ตรงกับเว็บ)
    $up = $drive->uploadBackup($backup['path'], $folderId !== '' ? $folderId : $drive->backupFolderId());
    (new SettingRepository($db))->save(['drive_backup_last' => json_encode([
        'at' => gmdate('Y-m-d H:i:s'), 'name' => $up['name'], 'size' => $up['size'], 'file_id' => $up['id'], 'folder_name' => $up['folder_name'],
        'tables' => $backup['tables'], 'files' => $backup['files'], 'by' => 'cli',
    ], JSON_UNESCAPED_UNICODE)]);
    Console::line('อัปโหลดขึ้น Google Drive แล้ว → ' . $up['folder_name']);
    exit(0);
} catch (Throwable $e) {
    PromptHub\Core\Logger::error($e, 'drive-backup');
    Console::error('สำรองข้อมูลไม่สำเร็จ: ' . $e->getMessage());
    exit(1);
}
