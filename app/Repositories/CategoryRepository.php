<?php
declare(strict_types=1);

namespace PromptHub\Repositories;

use PromptHub\Core\Database;

final class CategoryRepository
{
    public function __construct(private Database $db)
    {
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM categories WHERE id = ?', [$id]);
    }

    /** ทุกหมวด (หลังบ้าน) พร้อมจำนวน Prompt ทั้งหมดและที่เผยแพร่ */
    public function allWithCounts(): array
    {
        return $this->db->all(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM prompts p WHERE p.category_id = c.id) AS prompt_count,
                    (SELECT COUNT(*) FROM prompts p WHERE p.category_id = c.id AND p.visibility = 'public' AND p.status = 'published') AS public_count
             FROM categories c ORDER BY c.sort_order, c.name"
        );
    }

    /** หมวดที่เปิดใช้งานสำหรับหน้าบ้านและตัวกรอง พร้อมจำนวน Prompt สาธารณะ */
    public function activeWithPublicCounts(): array
    {
        return $this->db->all(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM prompts p WHERE p.category_id = c.id AND p.visibility = 'public' AND p.status = 'published') AS public_count
             FROM categories c WHERE c.is_active = 1 ORDER BY c.sort_order, c.name"
        );
    }

    /** ตัวเลือกในฟอร์ม Prompt: หมวดที่เปิดใช้งาน + หมวดปัจจุบันของ Prompt ที่กำลังแก้ไข (แม้ถูกปิด) */
    public function optionsForForm(int $currentId = 0): array
    {
        return $this->db->all(
            'SELECT id, name, is_active, allow_images FROM categories WHERE is_active = 1 OR id = ? ORDER BY sort_order, name',
            [$currentId]
        );
    }

    public function create(string $name, string $description, string $icon, bool $active, int $sort, bool $allowImages = false): int
    {
        $this->db->execute(
            'INSERT INTO categories (name, description, icon, is_active, sort_order, allow_images) VALUES (?, ?, ?, ?, ?, ?)',
            [$name, $description, $icon, $active ? 1 : 0, $sort, $allowImages ? 1 : 0]
        );
        return $this->db->lastId();
    }

    public function update(int $id, string $name, string $description, string $icon, bool $active, int $sort, bool $allowImages = false): void
    {
        $this->db->execute(
            'UPDATE categories SET name = ?, description = ?, icon = ?, is_active = ?, sort_order = ?, allow_images = ? WHERE id = ?',
            [$name, $description, $icon, $active ? 1 : 0, $sort, $allowImages ? 1 : 0, $id]
        );
    }

    public function countPrompts(int $id): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM prompts WHERE category_id = ?', [$id]);
    }

    public function movePrompts(int $from, int $to): int
    {
        return $this->db->execute('UPDATE prompts SET category_id = ? WHERE category_id = ?', [$to, $from]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM categories WHERE id = ?', [$id]);
    }

    public function count(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM categories');
    }
}
