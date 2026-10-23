<?php
declare(strict_types=1);

namespace PromptHub\Services;

use PromptHub\Core\Database;
use PromptHub\Core\HttpException;
use PromptHub\Core\ValidationException;
use PromptHub\Core\Validator;
use PromptHub\Repositories\LoginAttemptRepository;
use PromptHub\Repositories\UserRepository;

final class AuthService
{
    /** ล้มเหลวได้ไม่เกิน 5 ครั้งต่อบัญชี+IP และ 20 ครั้งต่อ IP ภายใน 15 นาที */
    public const MAX_PER_IDENTIFIER = 5;
    public const MAX_PER_IP = 20;
    public const WINDOW_MINUTES = 15;

    private UserRepository $users;
    private LoginAttemptRepository $attempts;

    public function __construct(private Database $db)
    {
        $this->users = new UserRepository($db);
        $this->attempts = new LoginAttemptRepository($db);
    }

    public function register(array $input): int
    {
        $v = new Validator($input);
        $username = $v->username('username');
        $email = $v->email('email');
        $name = $v->string('display_name', 'ชื่อที่แสดง', 1, 100);
        $password = $v->password('password', 'รหัสผ่าน', 8);
        if (($input['password_confirm'] ?? null) !== $password) {
            $v->addError('password_confirm', 'ยืนยันรหัสผ่านไม่ตรงกัน');
        }
        if (($input['accept_privacy'] ?? '') !== '1') {
            $v->addError('accept_privacy', 'กรุณาอ่านและยอมรับนโยบายความเป็นส่วนตัวก่อนสมัครสมาชิก');
        }
        if (!$v->hasError('username') && $this->users->usernameExists($username)) {
            $v->addError('username', 'ชื่อผู้ใช้นี้ถูกใช้แล้ว');
        }
        if (!$v->hasError('email') && $this->users->emailExists($email)) {
            $v->addError('email', 'อีเมลนี้ถูกใช้แล้ว');
        }
        $v->validate();
        try {
            return $this->users->create($username, $email, $name, password_hash($password, PASSWORD_DEFAULT), 'member', true);
        } catch (\mysqli_sql_exception $e) {
            if ($e->getCode() === 1062) {
                // กรณีสมัครพร้อมกัน Unique Constraint ในฐานข้อมูลเป็นด่านสุดท้าย
                throw new ValidationException(['username' => 'ชื่อผู้ใช้หรืออีเมลนี้ถูกใช้แล้ว']);
            }
            throw $e;
        }
    }

    /** ตรวจรหัสผ่านพร้อมจำกัดความถี่ คืนข้อมูลผู้ใช้เมื่อสำเร็จ */
    public function attempt(string $login, string $password, string $ip): array
    {
        $login = trim($login);
        $key = hash('sha256', mb_strtolower($login) . '|' . $ip);
        $this->attempts->purgeOlderThanDay();
        if (
            $this->attempts->countForIdentifier($key, self::WINDOW_MINUTES) >= self::MAX_PER_IDENTIFIER
            || $this->attempts->countForIp($ip, self::WINDOW_MINUTES) >= self::MAX_PER_IP
        ) {
            throw new HttpException(429, 'เข้าสู่ระบบผิดพลาดหลายครั้งเกินไป กรุณารอ ' . self::WINDOW_MINUTES . ' นาทีแล้วลองใหม่');
        }
        $user = $login === '' ? null : $this->users->findByLogin($login);
        // เรียก password_verify เสมอ เพื่อให้เวลาตอบสนองใกล้เคียงกันแม้ไม่มีบัญชีนี้
        $hash = $user['password_hash'] ?? '$2y$10$i3sq2.AQ1yGtMXrz83MV7Ojdnhir3AmIZUo2DQig6hmZu96UNzZjS';
        $ok = password_verify($password, $hash) && $user !== null;
        if (!$ok) {
            $this->attempts->record($key, $ip);
            throw new ValidationException(['login' => 'ชื่อผู้ใช้/อีเมล หรือรหัสผ่านไม่ถูกต้อง']);
        }
        $this->attempts->clear($key);
        if ($user['status'] !== 'active') {
            throw new ValidationException(['login' => 'บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ']);
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $this->users->updatePassword((int) $user['id'], $user['password_hash']);
        }
        return $user;
    }

    /** เปลี่ยนรหัสผ่านต้องยืนยันรหัสผ่านเดิม คืน hash ใหม่ */
    /**
     * แก้ไขโปรไฟล์: ชื่อที่แสดง ชื่อผู้ใช้ และอีเมล คืนรายการที่เปลี่ยน
     *  - เปลี่ยนชื่อผู้ใช้หรืออีเมลต้องยืนยันรหัสผ่านปัจจุบัน (บัญชีที่สร้างด้วย Google และยังไม่มีรหัสผ่านไม่ต้องยืนยัน)
     *  - ชื่อผู้ใช้/อีเมลต้องไม่ซ้ำกับบัญชีอื่น · เปลี่ยนอีเมลแล้วลิงก์ตั้งรหัสผ่านที่ส่งไปอีเมลเดิมใช้ไม่ได้
     */
    public function updateProfile(array $user, array $input): array
    {
        $v = new Validator($input);
        $name = $v->string('display_name', 'ชื่อที่แสดง', 1, 100);
        $username = $v->username('username');
        $email = $v->email('email');
        $id = (int) $user['id'];
        $usernameChanged = !$v->hasError('username') && $username !== $user['username'];
        $emailChanged = !$v->hasError('email') && $email !== strtolower((string) $user['email']);
        if ($usernameChanged && $this->users->usernameTaken($username, $id)) {
            $v->addError('username', 'ชื่อผู้ใช้นี้มีผู้ใช้แล้ว');
        }
        if ($emailChanged && $this->users->emailTaken($email, $id)) {
            $v->addError('email', 'อีเมลนี้ใช้กับบัญชีอื่นแล้ว');
        }
        $hasPassword = str_starts_with((string) $user['password_hash'], '$');
        if (($usernameChanged || $emailChanged) && $hasPassword) {
            $current = (string) ($input['profile_password'] ?? '');
            if ($current === '') {
                $v->addError('profile_password', 'กรอกรหัสผ่านปัจจุบันเพื่อยืนยันการเปลี่ยนชื่อผู้ใช้หรืออีเมล');
            } elseif (!password_verify($current, (string) $user['password_hash'])) {
                $v->addError('profile_password', 'รหัสผ่านปัจจุบันไม่ถูกต้อง');
            }
        }
        $v->validate();
        $this->db->transaction(function () use ($id, $username, $email, $name, $emailChanged): void {
            $this->users->updateIdentity($id, $username, $email, $name);
            if ($emailChanged) {
                $this->db->execute('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL', [$id]);
            }
        });
        $changed = [];
        if ($name !== $user['display_name']) {
            $changed[] = 'ชื่อที่แสดง';
        }
        if ($usernameChanged) {
            $changed[] = 'ชื่อผู้ใช้';
        }
        if ($emailChanged) {
            $changed[] = 'อีเมล';
        }
        return $changed;
    }

    public function changePassword(array $user, array $input): string
    {
        $v = new Validator($input);
        $current = $v->password('current_password', 'รหัสผ่านปัจจุบัน', 1);
        $new = $v->password('new_password', 'รหัสผ่านใหม่', 8);
        if (($input['new_password_confirm'] ?? null) !== $new) {
            $v->addError('new_password_confirm', 'ยืนยันรหัสผ่านใหม่ไม่ตรงกัน');
        }
        if (!$v->hasError('current_password') && !password_verify($current, $user['password_hash'])) {
            $v->addError('current_password', 'รหัสผ่านปัจจุบันไม่ถูกต้อง');
        }
        if (!$v->hasError('new_password') && $new === $current) {
            $v->addError('new_password', 'รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสผ่านเดิม');
        }
        $v->validate();
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $this->users->updatePassword((int) $user['id'], $hash);
        return $hash;
    }
}
