<?php
declare(strict_types=1);

namespace PromptHub\Repositories;

use PromptHub\Core\Database;

final class TagRepository
{
    public function __construct(private Database $db)
    {
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM tags WHERE id = ?', [$id]);
    }

    public function allWithCounts(string $q = ''): array
    {
        $args = [];
        $where = '';
        if ($q !== '') {
            $where = 'WHERE t.name LIKE ?';
            $args[] = Database::like($q);
        }
        return $this->db->all(
            "SELECT t.*, (SELECT COUNT(*) FROM prompt_tags pt WHERE pt.tag_id = t.id) AS prompt_count
             FROM tags t $where ORDER BY t.name",
            $args
        );
    }

    /** แท็กยอดนิยมจาก Prompt สาธารณะที่เผยแพร่แล้ว */
    public function popularPublic(int $limit): array
    {
        return $this->db->all(
            "SELECT t.id, t.name, COUNT(*) AS n
             FROM tags t JOIN prompt_tags pt ON pt.tag_id = t.id
             JOIN prompts p ON p.id = pt.prompt_id AND p.visibility = 'public' AND p.status = 'published'
             GROUP BY t.id, t.name ORDER BY n DESC, t.name LIMIT ?",
            [$limit]
        );
    }

    /** คืนรหัสแท็กตามชื่อ สร้างแท็กใหม่เมื่อยังไม่มี (เรียกภายใน Transaction) */
    public function idsForNames(array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $this->db->execute('INSERT IGNORE INTO tags (name) VALUES (?)', [$name]);
            $id = $this->db->value('SELECT id FROM tags WHERE name = ?', [$name]);
            if ($id !== null) {
                $ids[(int) $id] = (int) $id;
            }
        }
        return array_values($ids);
    }

    public function create(string $name): int
    {
        $this->db->execute('INSERT INTO tags (name) VALUES (?)', [$name]);
        return $this->db->lastId();
    }

    public function rename(int $id, string $name): void
    {
        $this->db->execute('UPDATE tags SET name = ? WHERE id = ?', [$name, $id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM tags WHERE id = ?', [$id]);
    }
}
