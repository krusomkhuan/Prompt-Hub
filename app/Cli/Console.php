<?php
declare(strict_types=1);

namespace PromptHub\Cli;

use PromptHub\Core\Database;
use PromptHub\Core\Validator;
use PromptHub\Repositories\ActivityLogRepository;
use PromptHub\Repositories\UserRepository;

/** เครื่องมือ CLI สำหรับติดตั้งและสร้างบัญชีผู้ดูแล */
final class Console
{
    public const ADMIN_MIN_PASSWORD = 10;
    private static ?bool $tty = null;

    /** ตรวจครั้งเดียวก่อนอ่าน STDIN (ตรวจหลังอ่านแล้วจะทำให้ข้อมูลใน buffer หาย) */
    public static function interactive(): bool
    {
        if (self::$tty === null) {
            self::$tty = function_exists('stream_isatty') && @stream_isatty(STDIN);
        }
        return self::$tty;
    }

    public static function line(string $text = ''): void
    {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    public static function error(string $text): void
    {
        fwrite(STDERR, '[ผิดพลาด] ' . $text . PHP_EOL);
    }

    public static function ask(string $label): string
    {
        fwrite(STDOUT, $label . ': ');
        $line = fgets(STDIN);
        return $line === false ? '' : trim($line);
    }

    /** อ่านรหัสผ่านแบบไม่แสดงตัวอักษรเมื่อทำได้ (Windows ใช้ PowerShell, Linux/macOS ใช้ stty) */
    public static function askHidden(string $label): string
    {
        $interactive = self::interactive();
        if ($interactive && PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
            fwrite(STDOUT, $label . ' (ตัวอักษรจะไม่แสดง): ');
            $cmd = 'powershell -NoProfile -Command "$p = Read-Host -AsSecureString; '
                . '[Runtime.InteropServices.Marshal]::PtrToStringBSTR([Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';
            $out = shell_exec($cmd);
            if (is_string($out)) {
                return rtrim($out, "\r\n");
            }
        } elseif ($interactive && PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec')) {
            fwrite(STDOUT, $label . ' (ตัวอักษรจะไม่แสดง): ');
            shell_exec('stty -echo');
            $line = fgets(STDIN);
            shell_exec('stty echo');
            fwrite(STDOUT, PHP_EOL);
            return $line === false ? '' : rtrim($line, "\r\n");
        }
        fwrite(STDOUT, $label . ': ');
        $line = fgets(STDIN);
        return $line === false ? '' : rtrim($line, "\r\n");
    }

    /** ถามข้อมูลและสร้างบัญชีผู้ดูแล รหัสผ่านกำหนดโดยผู้ติดตั้งเท่านั้น */
    public static function createAdmin(Database $db): int
    {
        self::line('');
        self::interactive();
        self::line('== สร้างบัญชีผู้ดูแลระบบ ==');
        $input = [
            'username' => self::ask('ชื่อผู้ใช้ (a-z, 0-9, _ ยาว 3-30)'),
            'email' => self::ask('อีเมล'),
            'display_name' => self::ask('ชื่อที่แสดง'),
        ];
        $input['password'] = self::askHidden('รหัสผ่าน (อย่างน้อย ' . self::ADMIN_MIN_PASSWORD . ' ตัวอักษร)');
        $confirm = self::askHidden('ยืนยันรหัสผ่าน');

        $v = new Validator($input);
        $username = $v->username('username');
        $email = $v->email('email');
        $name = $v->string('display_name', 'ชื่อที่แสดง', 1, 100);
        $password = $v->password('password', 'รหัสผ่าน', self::ADMIN_MIN_PASSWORD);
        if ($confirm !== $password) {
            $v->addError('confirm', 'ยืนยันรหัสผ่านไม่ตรงกัน');
        }
        $users = new UserRepository($db);
        if (!$v->hasError('username') && $users->usernameExists($username)) {
            $v->addError('username', 'ชื่อผู้ใช้นี้มีอยู่แล้ว');
        }
        if (!$v->hasError('email') && $users->emailExists($email)) {
            $v->addError('email', 'อีเมลนี้มีอยู่แล้ว');
        }
        $v->validate();

        return $db->transaction(function () use ($db, $users, $username, $email, $name, $password): int {
            $id = $users->create($username, $email, $name, password_hash($password, PASSWORD_DEFAULT), 'admin');
            (new ActivityLogRepository($db))->log(['id' => $id, 'username' => $username], 'admin.create', 'user', $id, 'สร้างผ่าน CLI');
            return $id;
        });
    }

    /** รันไฟล์ SQL หลายคำสั่ง (ไฟล์ที่มากับระบบเท่านั้น ไม่มีค่าจากผู้ใช้) */
    public static function runSqlFile(\mysqli $conn, string $file): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new \RuntimeException('อ่านไฟล์ไม่ได้: ' . $file);
        }
        $conn->multi_query($sql);
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
    }
}
