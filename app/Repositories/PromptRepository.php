<?php
declare(strict_types=1);

namespace PromptHub\Repositories;

use PromptHub\Core\Database;

/**
 * การเข้าถึงข้อมูล Prompt
 * กฎการมองเห็นต่อสาธารณะอยู่ที่ PUBLIC_CONDITION จุดเดียว ใช้ร่วมกันทุกหน้าที่แสดงต่อสาธารณะ
 */
final class PromptRepository
{
    public const PUBLIC_CONDITION = "p.visibility = 'public' AND p.status = 'published'";

    /** รายการเรียงลำดับที่อนุญาต (ห้ามนำค่าจากผู้ใช้ไปต่อ SQL โดยตรง) */
    private const SORTS = [
        'latest' => 'COALESCE(p.published_at, p.created_at) DESC, p.id DESC',
        'copies' => 'p.copy_count DESC, p.id DESC',
        'updated' => 'p.updated_at DESC, p.id DESC',
    ];

    private const SELECT = 'SELECT p.*, c.name AS category_name, c.icon AS category_icon, c.is_active AS category_active,
            c.allow_images AS category_allow_images,
            (SELECT pi.id FROM prompt_images pi WHERE pi.prompt_id = p.id ORDER BY pi.sort_order, pi.id LIMIT 1) AS first_image_id,
            (SELECT pi.drive_file_id FROM prompt_images pi WHERE pi.prompt_id = p.id ORDER BY pi.sort_order, pi.id LIMIT 1) AS first_image_drive,
            u.display_name AS author_name, u.username AS author_username, u.role AS author_role, u.avatar_file AS author_avatar
        FROM prompts p
        JOIN categories c ON c.id = p.category_id
        JOIN users u ON u.id = p.user_id';

    public function __construct(private Database $db)
    {
    }

    public static function sortKeys(): array
    {
        return array_keys(self::SORTS);
    }

    public function find(int $id, bool $forUpdate = false): ?array
    {
        $row = $this->db->one(self::SELECT . ' WHERE p.id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [$id]);
        if ($row) {
            $row = $this->withRelations([$row])[0];
            $row['images'] = $this->db->all(
                'SELECT id, mime, width, height, drive_file_id FROM prompt_images WHERE prompt_id = ? ORDER BY sort_order, id',
                [(int) $row['id']]
            );
        }
        return $row;
    }

    /**
     * ค้นหา + กรอง + เรียง + แบ่งหน้า
     * $scope: public (เฉพาะเผยแพร่) | owner (ของสมาชิก $viewerId) | favorites (รายการโปรดที่ยังเผยแพร่) | admin (ทั้งหมด)
     * $f: q, category, tag, tool, sort, status, visibility (ค่าผ่านการตรวจสอบมาแล้วจาก controller)
     */
    public function search(array $f, string $scope, int $viewerId, int $page, int $perPage): array
    {
        $where = [];
        $args = [];
        switch ($scope) {
            case 'owner':
                $where[] = 'p.user_id = ?';
                $args[] = $viewerId;
                break;
            case 'favorites':
                $where[] = self::PUBLIC_CONDITION;
                $where[] = 'EXISTS (SELECT 1 FROM favorites f WHERE f.prompt_id = p.id AND f.user_id = ?)';
                $args[] = $viewerId;
                break;
            case 'admin':
                $where[] = '1=1';
                break;
            default:
                $where[] = self::PUBLIC_CONDITION;
        }

        $q = (string) ($f['q'] ?? '');
        if ($q !== '') {
            $like = Database::like($q);
            $where[] = '(p.title LIKE ? OR p.description LIKE ? OR p.content LIKE ? OR p.content_th LIKE ?
                OR EXISTS (SELECT 1 FROM prompt_tags pt JOIN tags t ON t.id = pt.tag_id WHERE pt.prompt_id = p.id AND t.name LIKE ?))';
            array_push($args, $like, $like, $like, $like, $like);
        }
        if (!empty($f['category'])) {
            $where[] = 'p.category_id = ?';
            $args[] = (int) $f['category'];
        }
        if (!empty($f['tag'])) {
            $where[] = 'EXISTS (SELECT 1 FROM prompt_tags pt2 WHERE pt2.prompt_id = p.id AND pt2.tag_id = ?)';
            $args[] = (int) $f['tag'];
        }
        if (!empty($f['tool'])) {
            $where[] = 'EXISTS (SELECT 1 FROM prompt_ai_tools pa WHERE pa.prompt_id = p.id AND pa.ai_tool_id = ?)';
            $args[] = (int) $f['tool'];
        }
        if ($scope !== 'public' && $scope !== 'favorites') {
            if (!empty($f['status'])) {
                $where[] = 'p.status = ?';
                $args[] = (string) $f['status'];
            }
            if (!empty($f['visibility'])) {
                $where[] = 'p.visibility = ?';
                $args[] = (string) $f['visibility'];
            }
        }

        $cond = implode(' AND ', $where);
        $total = (int) $this->db->value(
            "SELECT COUNT(*) FROM prompts p JOIN categories c ON c.id = p.category_id JOIN users u ON u.id = p.user_id WHERE $cond",
            $args
        );
        $sortKey = (string) ($f['sort'] ?? 'latest');
        $order = self::SORTS[$sortKey] ?? self::SORTS['latest'];
        $rows = $this->db->all(
            self::SELECT . " WHERE $cond ORDER BY $order LIMIT ? OFFSET ?",
            array_merge($args, [$perPage, max(0, ($page - 1) * $perPage)])
        );
        return [$this->withRelations($rows), $total];
    }

    /** เติมรายการแท็กและเครื่องมือ AI ให้หลายแถวด้วยคำสั่งเดียวต่อประเภท */
    private function withRelations(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        $ph = Database::placeholders(count($ids));
        $tags = [];
        foreach ($this->db->all(
            "SELECT pt.prompt_id, t.id, t.name FROM prompt_tags pt JOIN tags t ON t.id = pt.tag_id WHERE pt.prompt_id IN ($ph) ORDER BY t.name",
            $ids
        ) as $t) {
            $tags[(int) $t['prompt_id']][] = ['id' => (int) $t['id'], 'name' => $t['name']];
        }
        $tools = [];
        foreach ($this->db->all(
            "SELECT pa.prompt_id, a.id, a.name FROM prompt_ai_tools pa JOIN ai_tools a ON a.id = pa.ai_tool_id WHERE pa.prompt_id IN ($ph) ORDER BY a.sort_order, a.name",
            $ids
        ) as $a) {
            $tools[(int) $a['prompt_id']][] = ['id' => (int) $a['id'], 'name' => $a['name']];
        }
        foreach ($rows as &$r) {
            $r['tags'] = $tags[(int) $r['id']] ?? [];
            $r['tools'] = $tools[(int) $r['id']] ?? [];
        }
        unset($r);
        return $rows;
    }

    public function insert(array $d): int
    {
        $this->db->execute(
            'INSERT INTO prompts (user_id, category_id, title, description, content, content_th, usage_example, output_example,
                visibility, status, published_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NULLIF(?, \'\'), ?, ?, ?, ?, IF(? = \'published\', UTC_TIMESTAMP(), NULL), UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                $d['user_id'], $d['category_id'], $d['title'], $d['description'], $d['content'], $d['content_th'],
                $d['usage_example'], $d['output_example'], $d['visibility'], $d['status'], $d['status'],
            ]
        );
        return $this->db->lastId();
    }

    /** แก้ไขเนื้อหา เมื่อสถานะไม่ใช่ published ให้ล้างผลการตรวจเดิมและเหตุผลไม่อนุมัติ */
    public function update(int $id, array $d): void
    {
        $this->db->execute(
            'UPDATE prompts SET category_id = ?, title = ?, description = ?, content = ?, content_th = NULLIF(?, \'\'), usage_example = ?, output_example = ?,
                visibility = ?, status = ?,
                rejection_reason = IF(? = \'published\', rejection_reason, NULL),
                reviewed_by = IF(? = \'published\', reviewed_by, NULL),
                reviewed_at = IF(? = \'published\', reviewed_at, NULL),
                updated_at = UTC_TIMESTAMP()
             WHERE id = ?',
            [
                $d['category_id'], $d['title'], $d['description'], $d['content'], $d['content_th'], $d['usage_example'], $d['output_example'],
                $d['visibility'], $d['status'], $d['status'], $d['status'], $d['status'], $id,
            ]
        );
    }

    public function syncTags(int $promptId, array $tagIds): void
    {
        $this->db->execute('DELETE FROM prompt_tags WHERE prompt_id = ?', [$promptId]);
        foreach ($tagIds as $tid) {
            $this->db->execute('INSERT IGNORE INTO prompt_tags (prompt_id, tag_id) VALUES (?, ?)', [$promptId, (int) $tid]);
        }
    }

    public function syncTools(int $promptId, array $toolIds): void
    {
        $this->db->execute('DELETE FROM prompt_ai_tools WHERE prompt_id = ?', [$promptId]);
        foreach ($toolIds as $tid) {
            $this->db->execute('INSERT IGNORE INTO prompt_ai_tools (prompt_id, ai_tool_id) VALUES (?, ?)', [$promptId, (int) $tid]);
        }
    }

    public function approve(int $id, int $adminId): void
    {
        $this->db->execute(
            "UPDATE prompts SET status = 'published', rejection_reason = NULL, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(),
                published_at = UTC_TIMESTAMP() WHERE id = ?",
            [$adminId, $id]
        );
    }

    public function reject(int $id, int $adminId, string $reason): void
    {
        $this->db->execute(
            "UPDATE prompts SET status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP() WHERE id = ?",
            [$reason, $adminId, $id]
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM prompts WHERE id = ?', [$id]);
    }

    public function incrementCopy(int $id): int
    {
        $this->db->execute('UPDATE prompts SET copy_count = copy_count + 1 WHERE id = ?', [$id]);
        return (int) $this->db->value('SELECT copy_count FROM prompts WHERE id = ?', [$id]);
    }

    public function incrementView(int $id): void
    {
        $this->db->execute('UPDATE prompts SET view_count = view_count + 1 WHERE id = ?', [$id]);
    }

    public function statusCountsForUser(int $userId): array
    {
        $out = ['draft' => 0, 'pending' => 0, 'published' => 0, 'rejected' => 0, 'private' => 0, 'total' => 0];
        foreach ($this->db->all(
            'SELECT status, visibility, COUNT(*) AS n FROM prompts WHERE user_id = ? GROUP BY status, visibility',
            [$userId]
        ) as $r) {
            $out[$r['status']] += (int) $r['n'];
            $out['total'] += (int) $r['n'];
            if ($r['visibility'] === 'private') {
                $out['private'] += (int) $r['n'];
            }
        }
        return $out;
    }

    /** สถิติรวมของ Prompt สาธารณะ (แสดงบนหน้าแรก) */
    public function publicStats(): array
    {
        $row = $this->db->one(
            'SELECT COUNT(*) AS prompts, COALESCE(SUM(p.view_count), 0) AS views, COALESCE(SUM(p.copy_count), 0) AS copies
             FROM prompts p WHERE ' . self::PUBLIC_CONDITION
        ) ?? [];
        return array_map('intval', $row);
    }

    public function adminCounts(): array
    {
        $out = ['total' => 0, 'draft' => 0, 'pending' => 0, 'published' => 0, 'rejected' => 0];
        foreach ($this->db->all('SELECT status, COUNT(*) AS n FROM prompts GROUP BY status') as $r) {
            $out[$r['status']] = (int) $r['n'];
            $out['total'] += (int) $r['n'];
        }
        $out['pending'] = (int) $this->db->value("SELECT COUNT(*) FROM prompts WHERE status = 'pending'");
        return $out;
    }
}
