<?php
declare(strict_types=1);

namespace PromptHub\Services;

use PromptHub\Core\Database;

/**
 * สำรองข้อมูลทั้งระบบเป็นไฟล์ zip เดียว แล้วส่งขึ้น Google Drive
 *  - database.sql : โครงสร้าง + ข้อมูลทุกตาราง (นำเข้าใน phpMyAdmin ได้)
 *  - uploads/     : ไฟล์ทั้งหมดใน storage/uploads (ภาพตัวอย่าง ภาพโปรไฟล์ ไฟล์ร้านค้า/สลิป)
 *  - ไม่รวมความลับ: config/config.php, storage/google-drive.json, storage/initial-accounts.txt, storage/logs
 *  - เก็บไฟล์ในเครื่องที่ storage/backups (เว็บเข้าไม่ได้) ไว้ล่าสุด KEEP_LOCAL ไฟล์
 * ไฟล์สำรองมีข้อมูลสมาชิก (อีเมล hash รหัสผ่าน) — ไฟล์บน Drive ไม่ตั้งสิทธิ์แชร์ (เห็นเฉพาะเจ้าของโฟลเดอร์และผู้ที่โฟลเดอร์แชร์ให้)
 */
final class BackupService
{
    public const KEEP_LOCAL = 5;
    private const ROWS_PER_QUERY = 500;
    private const ROWS_PER_INSERT = 100;

    public function __construct(private Database $db)
    {
    }

    public static function dir(): string
    {
        return APP_ROOT . '/storage/backups';
    }

    public static function validName(string $name): bool
    {
        return preg_match('/^prompthub-backup-\d{8}-\d{6}\.zip$/D', $name) === 1;
    }

    /** ไฟล์สำรองในเครื่อง ล่าสุดก่อน [['name','size','time']] */
    public static function localFiles(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/prompthub-backup-*.zip') ?: [] as $path) {
            if (self::validName(basename($path))) {
                $out[] = ['name' => basename($path), 'size' => (int) filesize($path), 'time' => (int) filemtime($path)];
            }
        }
        usort($out, static fn($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    /** สร้างไฟล์สำรอง คืน ['path','name','size','tables','files'] */
    public function create(): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP ไม่มี extension zip — เปิดใช้ extension=zip ใน php.ini');
        }
        @set_time_limit(900);
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create backup directory');
        }
        $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Bangkok')))->format('Ymd-His');
        $name = 'prompthub-backup-' . $stamp . '.zip';
        $path = $dir . '/' . $name;
        $sqlTmp = $dir . '/.dump-' . bin2hex(random_bytes(6)) . '.sql';
        $files = 0;
        try {
            $tables = $this->dumpDatabase($sqlTmp);
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
                throw new \RuntimeException('Cannot create zip ' . $name);
            }
            $zip->addFile($sqlTmp, 'database.sql');
            $uploads = APP_ROOT . '/storage/uploads';
            if (is_dir($uploads)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($uploads, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    /** @var \SplFileInfo $f */
                    if (!$f->isFile() || str_starts_with($f->getFilename(), '.')) {
                        continue;
                    }
                    $rel = 'uploads/' . str_replace('\\', '/', substr($f->getPathname(), strlen($uploads) + 1));
                    $zip->addFile($f->getPathname(), $rel);
                    // ภาพ/zip บีบอัดมาแล้ว เก็บแบบไม่บีบซ้ำ ประหยัดเวลา CPU
                    if (preg_match('/\.(jpe?g|png|webp|zip|epub|docx|pptx|xlsx|pdf)$/i', $rel)) {
                        $zip->setCompressionName($rel, \ZipArchive::CM_STORE);
                    }
                    $files++;
                }
            }
            $zip->setArchiveComment('Prompt Hub ' . APP_VERSION . ' backup ' . $stamp . ' · ' . $tables . ' tables · ' . $files . ' files');
            if (!$zip->close()) {
                throw new \RuntimeException('Cannot write zip ' . $name);
            }
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        } finally {
            @unlink($sqlTmp);
        }
        $this->prune();
        return ['path' => $path, 'name' => $name, 'size' => (int) filesize($path), 'tables' => $tables, 'files' => $files];
    }

    /** ลบไฟล์สำรองในเครื่องที่เก่ากว่า KEEP_LOCAL ไฟล์ล่าสุด */
    public function prune(): void
    {
        foreach (array_slice(self::localFiles(), self::KEEP_LOCAL) as $old) {
            @unlink(self::dir() . '/' . $old['name']);
        }
    }

    /** เขียน SQL ของทุกตาราง (โครงสร้าง + ข้อมูล) ลงไฟล์ คืนจำนวนตาราง */
    private function dumpDatabase(string $file): int
    {
        $fh = fopen($file, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Cannot write SQL dump');
        }
        try {
            $now = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Bangkok')))->format('Y-m-d H:i:s');
            fwrite($fh, "-- Prompt Hub " . APP_VERSION . " — สำรองฐานข้อมูล {$now} (Asia/Bangkok)\n"
                . "-- มีข้อมูลสมาชิก ห้ามเผยแพร่ · นำเข้าในฐานข้อมูลว่างผ่าน phpMyAdmin › Import\n"
                . "SET NAMES utf8mb4;\nSET time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");
            $tables = [];
            foreach ($this->db->all('SHOW FULL TABLES') as $row) {
                $vals = array_values($row);
                // ข้ามตารางชั่วคราวที่ขึ้นต้นด้วย _ (เช่น ตารางพักข้อมูลระหว่างทดสอบ) และ view
                if (($vals[1] ?? '') === 'BASE TABLE' && !str_starts_with((string) $vals[0], '_')) {
                    $tables[] = (string) $vals[0];
                }
            }
            foreach ($tables as $t) {
                $q = '`' . str_replace('`', '``', $t) . '`';
                $create = array_values($this->db->one("SHOW CREATE TABLE $q") ?? [])[1] ?? '';
                fwrite($fh, "-- --------------------------------------------------------\n-- ตาราง $t\n"
                    . "DROP TABLE IF EXISTS $q;\n$create;\n\n");
                for ($offset = 0; ; $offset += self::ROWS_PER_QUERY) {
                    $rows = $this->db->all("SELECT * FROM $q LIMIT ? OFFSET ?", [self::ROWS_PER_QUERY, $offset]);
                    if (!$rows) {
                        break;
                    }
                    $cols = '(' . implode(', ', array_map(static fn($c) => '`' . str_replace('`', '``', (string) $c) . '`', array_keys($rows[0]))) . ')';
                    foreach (array_chunk($rows, self::ROWS_PER_INSERT) as $chunk) {
                        $values = array_map(fn($r) => '(' . implode(', ', array_map([$this, 'sqlValue'], array_values($r))) . ')', $chunk);
                        fwrite($fh, "INSERT INTO $q $cols VALUES\n" . implode(",\n", $values) . ";\n");
                    }
                    if (count($rows) < self::ROWS_PER_QUERY) {
                        break;
                    }
                }
                fwrite($fh, "\n");
            }
            fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
            return count($tables);
        } finally {
            fclose($fh);
        }
    }

    private function sqlValue($v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        $s = (string) $v;
        if (!mb_check_encoding($s, 'UTF-8')) {
            return '0x' . bin2hex($s); // ข้อมูลไบนารี
        }
        return "'" . str_replace(['\\', "\0", "\n", "\r", "'", '"', "\x1a"], ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'], $s) . "'";
    }
}
