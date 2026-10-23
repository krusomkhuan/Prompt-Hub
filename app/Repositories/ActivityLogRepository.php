<?php
declare(strict_types=1);

namespace PromptHub\Repositories;

use PromptHub\Core\Database;

final class ActivityLogRepository
{
    public const ACTIONS = [
        'prompt.approve' => 'อนุมัติ Prompt',
        'prompt.reject' => 'ไม่อนุมัติ Prompt',
        'prompt.update' => 'แก้ไข Prompt',
        'prompt.delete' => 'ลบ Prompt',
        'user.suspend' => 'ระงับสมาชิก',
        'user.activate' => 'เปิดใช้งานสมาชิก',
        'user.role' => 'เปลี่ยนบทบาท',
        'user.delete' => 'ลบสมาชิก',
        'user.avatar_remove' => 'ลบภาพโปรไฟล์สมาชิก',
        'category.create' => 'เพิ่มหมวดหมู่',
        'category.update' => 'แก้ไขหมวดหมู่',
        'category.delete' => 'ลบหมวดหมู่',
        'tag.create' => 'เพิ่มแท็ก',
        'tag.update' => 'แก้ไขแท็ก',
        'tag.delete' => 'ลบแท็ก',
        'tool.create' => 'เพิ่มเครื่องมือ AI',
        'tool.update' => 'แก้ไขเครื่องมือ AI',
        'tool.delete' => 'ลบเครื่องมือ AI',
        'settings.update' => 'ตั้งค่าเว็บไซต์',
        'google.settings' => 'ตั้งค่าเข้าสู่ระบบด้วย Google',
        'drive.folder' => 'ตั้งโฟลเดอร์ Google Drive',
        'drive.connect' => 'เชื่อมต่อ Google Drive',
        'drive.disconnect' => 'ยกเลิกการเชื่อมต่อ Google Drive',
        'drive.sync' => 'อัปโหลดภาพขึ้น Google Drive',
        'shop.product.create' => 'เพิ่มสินค้า Digital Shop',
        'shop.product.update' => 'แก้ไขสินค้า Digital Shop',
        'shop.product.delete' => 'ลบสินค้า Digital Shop',
        'shop.order.approve' => 'อนุมัติคำสั่งซื้อ',
        'shop.order.reject' => 'ปฏิเสธคำสั่งซื้อ',
        'shop.settings' => 'ตั้งค่า Digital Shop',
        'admin.create' => 'สร้างผู้ดูแล (CLI)',
    ];

    public function __construct(private Database $db)
    {
    }

    public function log(array $admin, string $action, string $targetType = '', ?int $targetId = null, string $details = ''): void
    {
        $this->db->execute(
            'INSERT INTO admin_activity_logs (admin_id, admin_username, action, target_type, target_id, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $admin['id'], (string) $admin['username'], $action, $targetType, $targetId,
                mb_substr($details, 0, 1000), PHP_SAPI === 'cli' ? 'cli' : client_ip(),
            ]
        );
    }

    public function search(string $action, int $page, int $perPage): array
    {
        $where = '1=1';
        $args = [];
        if ($action !== '') {
            $where = 'action = ?';
            $args[] = $action;
        }
        $total = (int) $this->db->value("SELECT COUNT(*) FROM admin_activity_logs WHERE $where", $args);
        $rows = $this->db->all(
            "SELECT * FROM admin_activity_logs WHERE $where ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?",
            array_merge($args, [$perPage, ($page - 1) * $perPage])
        );
        return [$rows, $total];
    }
}
