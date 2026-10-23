<?php
declare(strict_types=1);

namespace PromptHub\Core;

/**
 * สถานะการเข้าสู่ระบบ โหลดข้อมูลผู้ใช้จากฐานข้อมูลใหม่ทุกคำขอ
 * จึงมีผลทันทีเมื่อบัญชีถูกระงับ ลบ หรือเปลี่ยนบทบาท
 */
final class Auth
{
    private ?array $user = null;

    public function __construct(private Database $db, private Session $session)
    {
        $uid = $session->get('uid');
        if ($uid === null) {
            return;
        }
        $user = $db->one(
            'SELECT id, username, email, google_sub, avatar_file, display_name, password_hash, role, status, created_at FROM users WHERE id = ?',
            [(int) $uid]
        );
        $version = (string) $session->get('pwv', '');
        if (!$user || !hash_equals(self::passwordVersion($user['password_hash']), $version)) {
            // บัญชีถูกลบ หรือรหัสผ่านถูกเปลี่ยนจากอุปกรณ์อื่น
            $session->destroy();
            $session->start();
            return;
        }
        if ($user['status'] !== 'active') {
            $session->destroy();
            $session->start();
            $session->flash('danger', 'บัญชีของคุณถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ');
            return;
        }
        $this->user = $user;
    }

    /** ค่าอ้างอิงรหัสผ่านใน session เพื่อให้ session อื่นหมดสิทธิ์เมื่อเปลี่ยนรหัสผ่าน */
    public static function passwordVersion(string $hash): string
    {
        return substr(hash('sha256', $hash), 0, 16);
    }

    public function user(): ?array
    {
        return $this->user;
    }

    public function id(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }

    public function check(): bool
    {
        return $this->user !== null;
    }

    public function isAdmin(): bool
    {
        return ($this->user['role'] ?? '') === 'admin';
    }

    public function login(array $user): void
    {
        $this->session->regenerate();
        $this->session->set('uid', (int) $user['id']);
        $this->session->set('pwv', self::passwordVersion($user['password_hash']));
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        $this->db->execute('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [(int) $user['id']]);
        $this->user = $user;
    }

    /** หลังเปลี่ยนรหัสผ่าน: ออก Session ID ใหม่และอัปเดตค่าอ้างอิง */
    public function refreshPassword(string $newHash): void
    {
        $this->session->regenerate();
        $this->session->set('pwv', self::passwordVersion($newHash));
        if ($this->user) {
            $this->user['password_hash'] = $newHash;
        }
    }

    public function logout(): void
    {
        $this->session->destroy();
        $this->user = null;
    }

    public function requireMember(): array
    {
        if (!$this->user) {
            throw new HttpException(401, 'กรุณาเข้าสู่ระบบก่อนใช้งานหน้านี้');
        }
        return $this->user;
    }

    public function requireAdmin(): array
    {
        $u = $this->requireMember();
        if ($u['role'] !== 'admin') {
            throw new HttpException(403, 'หน้านี้สำหรับผู้ดูแลระบบเท่านั้น');
        }
        return $u;
    }
}
