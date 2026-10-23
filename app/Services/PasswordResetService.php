<?php
declare(strict_types=1);

namespace PromptHub\Services;

use PromptHub\Core\Database;
use PromptHub\Core\HttpException;
use PromptHub\Core\Logger;
use PromptHub\Core\Mailer;
use PromptHub\Core\ValidationException;
use PromptHub\Core\Validator;
use PromptHub\Repositories\LoginAttemptRepository;
use PromptHub\Repositories\UserRepository;

/**
 * ลืมรหัสผ่าน: ส่งลิงก์ตั้งรหัสผ่านใหม่ทางอีเมล
 *  - token สุ่ม 256 บิต เก็บในฐานข้อมูลเฉพาะ SHA-256 ใช้ได้ครั้งเดียว หมดอายุใน 60 นาที
 *  - ตอบข้อความเดียวกันเสมอ ไม่บอกว่าอีเมลนี้มีบัญชีหรือไม่
 *  - จำกัดคำขอ 3 ครั้ง/อีเมล และ 10 ครั้ง/IP ต่อชั่วโมง
 */
final class PasswordResetService
{
    public const TTL_MINUTES = 60;
    public const MAX_PER_EMAIL = 3;
    public const MAX_PER_IP = 10;

    private UserRepository $users;
    private LoginAttemptRepository $attempts;

    public function __construct(private Database $db, private Mailer $mailer)
    {
        $this->users = new UserRepository($db);
        $this->attempts = new LoginAttemptRepository($db);
    }

    /** รับคำขอลืมรหัสผ่าน (เรียกได้ทั้งกรณีมีและไม่มีบัญชี ผลต่อผู้ใช้เหมือนกัน) */
    public function request(array $input, string $ip, string $resetBaseUrl, string $siteName): void
    {
        $v = new Validator($input);
        $email = $v->email('email');
        $v->validate();

        $emailKey = hash('sha256', 'reset-email|' . $email);
        $ipKey = hash('sha256', 'reset-ip|' . $ip);
        $this->attempts->purgeOlderThanDay();
        // ลบคำขอที่ใช้แล้วหรือหมดอายุเกิน 1 วัน (ระยะเวลาเก็บตามนโยบายความเป็นส่วนตัว)
        $this->db->execute(
            'DELETE FROM password_resets WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY OR used_at < UTC_TIMESTAMP() - INTERVAL 1 DAY'
        );
        if ($this->attempts->countForIdentifier($emailKey, 60) >= self::MAX_PER_EMAIL
            || $this->attempts->countForIdentifier($ipKey, 60) >= self::MAX_PER_IP) {
            throw new HttpException(429, 'ขอรีเซ็ตรหัสผ่านบ่อยเกินไป กรุณารอ 1 ชั่วโมงแล้วลองใหม่');
        }
        $this->attempts->record($emailKey, $ip);
        $this->attempts->record($ipKey, $ip);

        $user = $this->db->one('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
        if (!$user || $user['status'] !== 'active' || !$this->mailer->enabled()) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $resetId = $this->db->transaction(function () use ($user, $token, $ip): int {
            // คำขอใหม่ทำให้ลิงก์เก่าที่ยังไม่ใช้หมดสิทธิ์
            $this->db->execute('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL', [(int) $user['id']]);
            $this->db->execute(
                'INSERT INTO password_resets (user_id, token_hash, expires_at, ip_address)
                 VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL ? MINUTE, ?)',
                [(int) $user['id'], hash('sha256', $token), self::TTL_MINUTES, $ip]
            );
            return $this->db->lastId();
        });

        $link = $resetBaseUrl . '&token=' . $token;
        $body = "สวัสดี {$user['display_name']}\n\n"
            . "มีคำขอตั้งรหัสผ่านใหม่สำหรับบัญชี {$user['username']} ที่ {$siteName}\n"
            . 'กดลิงก์ด้านล่างเพื่อตั้งรหัสผ่านใหม่ ลิงก์ใช้ได้ครั้งเดียวและหมดอายุใน ' . self::TTL_MINUTES . " นาที\n\n"
            . $link . "\n\n"
            . "หากคุณไม่ได้ขอตั้งรหัสผ่านใหม่ ไม่ต้องทำอะไร รหัสผ่านเดิมยังใช้ได้ตามปกติ\n\n"
            . "— {$siteName}\n";
        try {
            $this->mailer->send($user['email'], $user['display_name'], "ตั้งรหัสผ่านใหม่ — {$siteName}", $body);
        } catch (\Throwable $e) {
            // ส่งไม่สำเร็จ: ลบ token ทิ้ง บันทึก log ฝั่งเซิร์ฟเวอร์ ผู้ใช้ยังเห็นข้อความเดิม
            $this->db->execute('DELETE FROM password_resets WHERE id = ?', [$resetId]);
            Logger::error($e, 'password-reset-mail');
        }
    }

    /** ตรวจ token คืนข้อมูลผู้ใช้ หรือ null ถ้าไม่ถูกต้อง/หมดอายุ/ใช้แล้ว/บัญชีถูกระงับ */
    public function findValid(string $token, bool $forUpdate = false): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }
        return $this->db->one(
            'SELECT r.id AS reset_id, u.id, u.username, u.display_name, u.password_hash
             FROM password_resets r JOIN users u ON u.id = r.user_id
             WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > UTC_TIMESTAMP() AND u.status = \'active\''
                . ($forUpdate ? ' FOR UPDATE' : ''),
            [hash('sha256', $token)]
        );
    }

    /** ตั้งรหัสผ่านใหม่ด้วย token แล้วทำให้ลิงก์ทั้งหมดของบัญชีนั้นใช้ไม่ได้ */
    public function reset(string $token, array $input): array
    {
        $v = new Validator($input);
        $password = $v->password('password', 'รหัสผ่านใหม่', 8);
        if (($input['password_confirm'] ?? null) !== $password) {
            $v->addError('password_confirm', 'ยืนยันรหัสผ่านไม่ตรงกัน');
        }
        $v->validate();

        return $this->db->transaction(function () use ($token, $password): array {
            $row = $this->findValid($token, true);
            if (!$row) {
                throw new ValidationException(['token' => 'ลิงก์ตั้งรหัสผ่านไม่ถูกต้อง หมดอายุ หรือถูกใช้ไปแล้ว กรุณาขอลิงก์ใหม่']);
            }
            $this->users->updatePassword((int) $row['id'], password_hash($password, PASSWORD_DEFAULT));
            $this->db->execute('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE id = ?', [(int) $row['reset_id']]);
            $this->db->execute('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL', [(int) $row['id']]);
            // ล้างตัวนับ login ผิดของบัญชีนี้ เพื่อให้เข้าสู่ระบบด้วยรหัสใหม่ได้ทันที
            $this->db->execute(
                'DELETE FROM login_attempts WHERE identifier_hash IN (?, ?)',
                [hash('sha256', mb_strtolower($row['username']) . '|' . client_ip()), hash('sha256', mb_strtolower((string) $this->db->value('SELECT email FROM users WHERE id = ?', [(int) $row['id']])) . '|' . client_ip())]
            );
            return $row;
        });
    }
}
