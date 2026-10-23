<?php
declare(strict_types=1);

namespace PromptHub\Repositories;

use PromptHub\Core\Database;

final class AiToolRepository
{
    public function __construct(private Database $db)
    {
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM ai_tools WHERE id = ?', [$id]);
    }

    public function allWithCounts(): array
    {
        return $this->db->all(
            'SELECT a.*, (SELECT COUNT(*) FROM prompt_ai_tools pa WHERE pa.ai_tool_id = a.id) AS prompt_count
             FROM ai_tools a ORDER BY a.sort_order, a.name'
        );
    }

    public function active(): array
    {
        return $this->db->all('SELECT id, name FROM ai_tools WHERE is_active = 1 ORDER BY sort_order, name');
    }

    /** ตัวเลือกในฟอร์ม: เครื่องมือที่เปิดใช้ + ที่ Prompt นี้เลือกไว้เดิม */
    public function optionsForForm(array $currentIds): array
    {
        if (!$currentIds) {
            return $this->active();
        }
        return $this->db->all(
            'SELECT id, name FROM ai_tools WHERE is_active = 1 OR id IN (' . Database::placeholders(count($currentIds)) . ')
             ORDER BY sort_order, name',
            $currentIds
        );
    }

    /** กรองรหัสที่ส่งมาให้เหลือเฉพาะที่มีอยู่จริงและอนุญาต */
    public function validIds(array $ids, array $alsoAllowed = []): array
    {
        if (!$ids) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT id, is_active FROM ai_tools WHERE id IN (' . Database::placeholders(count($ids)) . ')',
            $ids
        );
        $ok = [];
        foreach ($rows as $r) {
            if ((int) $r['is_active'] === 1 || in_array((int) $r['id'], $alsoAllowed, true)) {
                $ok[] = (int) $r['id'];
            }
        }
        return $ok;
    }

    public function create(string $name, bool $active, int $sort): int
    {
        $this->db->execute('INSERT INTO ai_tools (name, is_active, sort_order) VALUES (?, ?, ?)', [$name, $active ? 1 : 0, $sort]);
        return $this->db->lastId();
    }

    public function update(int $id, string $name, bool $active, int $sort): void
    {
        $this->db->execute('UPDATE ai_tools SET name = ?, is_active = ?, sort_order = ? WHERE id = ?', [$name, $active ? 1 : 0, $sort, $id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM ai_tools WHERE id = ?', [$id]);
    }
}
