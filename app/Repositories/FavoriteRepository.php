<?php
declare(strict_types=1);

namespace PromptHub\Repositories;

use PromptHub\Core\Database;

final class FavoriteRepository
{
    public function __construct(private Database $db)
    {
    }

    /** เพิ่มรายการโปรด คืน true ถ้าเพิ่มใหม่ false ถ้ามีอยู่แล้ว (Primary Key ป้องกันซ้ำ) */
    public function add(int $userId, int $promptId): bool
    {
        return $this->db->execute('INSERT IGNORE INTO favorites (user_id, prompt_id) VALUES (?, ?)', [$userId, $promptId]) > 0;
    }

    public function remove(int $userId, int $promptId): void
    {
        $this->db->execute('DELETE FROM favorites WHERE user_id = ? AND prompt_id = ?', [$userId, $promptId]);
    }

    public function exists(int $userId, int $promptId): bool
    {
        return (bool) $this->db->value('SELECT COUNT(*) FROM favorites WHERE user_id = ? AND prompt_id = ?', [$userId, $promptId]);
    }

    /** รายการโปรดที่ถูกซ่อนเพราะ Prompt ไม่ได้เผยแพร่แล้ว */
    public function countHidden(int $userId): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM favorites f JOIN prompts p ON p.id = f.prompt_id
             WHERE f.user_id = ? AND NOT (p.visibility = 'public' AND p.status = 'published')",
            [$userId]
        );
    }
}
