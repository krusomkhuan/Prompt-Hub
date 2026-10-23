<?php
declare(strict_types=1);

namespace PromptHub\Repositories;

use PromptHub\Core\Database;

final class UserRepository
{
    public function __construct(private Database $db)
    {
    }

    public function find(int $id, bool $forUpdate = false): ?array
    {
        return $this->db->one(
            'SELECT * FROM users WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$id]
        );
    }

    /** ค้นหาด้วยชื่อผู้ใช้หรืออีเมล */
    public function findByLogin(string $login): ?array
    {
        return $this->db->one(
            'SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1',
            [$login, strtolower($login)]
        );
    }

    public function usernameExists(string $username): bool
    {
        return (bool) $this->db->value('SELECT COUNT(*) FROM users WHERE username = ?', [$username]);
    }

    public function emailExists(string $email): bool
    {
        return (bool) $this->db->value('SELECT COUNT(*) FROM users WHERE email = ?', [$email]);
    }

    /** $privacyAccepted = true บันทึกเวลาที่ยอมรับนโยบายความเป็นส่วนตัว (สมัครผ่านเว็บ) */
    public function create(string $username, string $email, string $displayName, string $hash, string $role = 'member', bool $privacyAccepted = false): int
    {
        $this->db->execute(
            'INSERT INTO users (username, email, display_name, password_hash, role, privacy_accepted_at)
             VALUES (?, ?, ?, ?, ?, IF(? = 1, UTC_TIMESTAMP(), NULL))',
            [$username, $email, $displayName, $hash, $role, $privacyAccepted ? 1 : 0]
        );
        return $this->db->lastId();
    }

    /** ชื่อผู้ใช้/อีเมลนี้ถูกใช้โดยบัญชีอื่นแล้วหรือไม่ (ไม่นับบัญชีตัวเอง) */
    public function usernameTaken(string $username, int $exceptId): bool
    {
        return (bool) $this->db->value('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?', [$username, $exceptId]);
    }

    public function emailTaken(string $email, int $exceptId): bool
    {
        return (bool) $this->db->value('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [$email, $exceptId]);
    }

    public function updateIdentity(int $id, string $username, string $email, string $displayName): void
    {
        $this->db->execute('UPDATE users SET username = ?, email = ?, display_name = ? WHERE id = ?', [$username, $email, $displayName, $id]);
    }

    public function updateDisplayName(int $id, string $name): void
    {
        $this->db->execute('UPDATE users SET display_name = ? WHERE id = ?', [$name, $id]);
    }

    public function updatePassword(int $id, string $hash): void
    {
        $this->db->execute('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $id]);
    }

    public function clearAvatar(int $id): void
    {
        $this->db->execute('UPDATE users SET avatar_file = NULL WHERE id = ?', [$id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->execute('UPDATE users SET status = ? WHERE id = ?', [$status, $id]);
    }

    public function setRole(int $id, string $role): void
    {
        $this->db->execute('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM users WHERE id = ?', [$id]);
    }

    /** ล็อกแถวผู้ดูแลที่ใช้งานอยู่ทั้งหมด เพื่อป้องกันการแข่งกันลดจำนวนผู้ดูแล */
    public function lockActiveAdmins(): array
    {
        return $this->db->all("SELECT id FROM users WHERE role = 'admin' AND status = 'active' FOR UPDATE");
    }

    public function search(string $q, string $role, string $status, int $page, int $perPage): array
    {
        $where = ['1=1'];
        $args = [];
        if ($q !== '') {
            $where[] = '(u.username LIKE ? OR u.email LIKE ? OR u.display_name LIKE ?)';
            $like = Database::like($q);
            array_push($args, $like, $like, $like);
        }
        if ($role !== '') {
            $where[] = 'u.role = ?';
            $args[] = $role;
        }
        if ($status !== '') {
            $where[] = 'u.status = ?';
            $args[] = $status;
        }
        $cond = implode(' AND ', $where);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM users u WHERE $cond", $args);
        $rows = $this->db->all(
            "SELECT u.id, u.username, u.email, u.google_sub, u.avatar_file, u.display_name, u.role, u.status, u.created_at, u.last_login_at,
                    (SELECT COUNT(*) FROM prompts p WHERE p.user_id = u.id) AS prompt_count,
                    (SELECT COUNT(*) FROM prompts p WHERE p.user_id = u.id AND p.status = 'published' AND p.visibility = 'public') AS published_count
             FROM users u WHERE $cond ORDER BY u.created_at DESC, u.id DESC LIMIT ? OFFSET ?",
            array_merge($args, [$perPage, ($page - 1) * $perPage])
        );
        return [$rows, $total];
    }

    public function counts(): array
    {
        return $this->db->one(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'active') AS active,
                    SUM(status = 'suspended') AS suspended,
                    SUM(role = 'admin') AS admins
             FROM users"
        ) ?? [];
    }
}
