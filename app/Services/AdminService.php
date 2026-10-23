<?php
declare(strict_types=1);

namespace PromptHub\Services;

use PromptHub\Core\Database;
use PromptHub\Core\HttpException;
use PromptHub\Core\ValidationException;
use PromptHub\Repositories\ActivityLogRepository;
use PromptHub\Repositories\CategoryRepository;
use PromptHub\Repositories\UserRepository;

/** งานหลังบ้านที่ต้องมีกฎป้องกัน: จัดการบัญชีสมาชิกและการลบหมวดหมู่ */
final class AdminService
{
    private UserRepository $users;
    private CategoryRepository $categories;
    private ActivityLogRepository $logs;

    public function __construct(private Database $db)
    {
        $this->users = new UserRepository($db);
        $this->categories = new CategoryRepository($db);
        $this->logs = new ActivityLogRepository($db);
    }

    /**
     * $op: suspend | activate | make_admin | make_member | remove_avatar | delete
     * ห้ามทำกับบัญชีตัวเองในทางที่ลดสิทธิ์ และห้ามทำให้ไม่เหลือผู้ดูแลที่ใช้งานได้
     */
    public function userAction(array $admin, int $targetId, string $op): string
    {
        $files = [];
        $avatar = null;
        $message = $this->db->transaction(function () use ($admin, $targetId, $op, &$files, &$avatar): string {
            $activeAdmins = array_map(static fn($r) => (int) $r['id'], $this->users->lockActiveAdmins());
            $target = $this->users->find($targetId, true);
            if (!$target) {
                throw new HttpException(404, 'ไม่พบสมาชิก');
            }
            $self = (int) $target['id'] === (int) $admin['id'];
            $removesAdmin = in_array($op, ['suspend', 'make_member', 'delete'], true);
            if ($self && $removesAdmin) {
                throw new ValidationException(['user' => 'ไม่สามารถระงับ ลบ หรือลดสิทธิ์บัญชีที่คุณกำลังใช้งานอยู่']);
            }
            $isActiveAdmin = in_array((int) $target['id'], $activeAdmins, true);
            if ($removesAdmin && $isActiveAdmin && count($activeAdmins) <= 1) {
                throw new ValidationException(['user' => 'ระบบต้องมีผู้ดูแลที่ใช้งานได้อย่างน้อย 1 บัญชี']);
            }
            $label = $target['username'];
            switch ($op) {
                case 'suspend':
                    $this->users->setStatus($targetId, 'suspended');
                    $this->logs->log($admin, 'user.suspend', 'user', $targetId, $label);
                    return "ระงับบัญชี {$label} แล้ว";
                case 'activate':
                    $this->users->setStatus($targetId, 'active');
                    $this->logs->log($admin, 'user.activate', 'user', $targetId, $label);
                    return "เปิดใช้งานบัญชี {$label} แล้ว";
                case 'make_admin':
                    $this->users->setRole($targetId, 'admin');
                    $this->logs->log($admin, 'user.role', 'user', $targetId, $label . ' → admin');
                    return "กำหนดให้ {$label} เป็นผู้ดูแลแล้ว";
                case 'make_member':
                    $this->users->setRole($targetId, 'member');
                    $this->logs->log($admin, 'user.role', 'user', $targetId, $label . ' → member');
                    return "เปลี่ยน {$label} เป็นสมาชิกทั่วไปแล้ว";
                case 'remove_avatar':
                    $avatar = $target['avatar_file'];
                    $this->users->clearAvatar($targetId);
                    $this->logs->log($admin, 'user.avatar_remove', 'user', $targetId, $label);
                    return "ลบภาพโปรไฟล์ของ {$label} แล้ว";
                case 'delete':
                    $this->logs->log($admin, 'user.delete', 'user', $targetId, $label . ' (ลบ Prompt ของสมาชิกนี้ด้วย)');
                    $files = (new ImageService($this->db))->filesForUser($targetId);
                    $avatar = $target['avatar_file'];
                    $this->users->delete($targetId);
                    return "ลบบัญชี {$label} แล้ว";
            }
            throw new HttpException(400, 'คำสั่งไม่ถูกต้อง');
        });
        // ลบไฟล์ภาพของสมาชิกที่ถูกลบ หลัง commit แล้วเท่านั้น
        ImageService::deleteFiles($files);
        AvatarService::deleteFile($avatar === null ? null : (string) $avatar);
        return $message;
    }

    /** ลบหมวดหมู่ ถ้ามี Prompt ต้องระบุหมวดปลายทางเพื่อย้ายก่อน (ทำใน Transaction เดียว) */
    public function deleteCategory(array $admin, int $id, int $moveTo): string
    {
        return $this->db->transaction(function () use ($admin, $id, $moveTo): string {
            $cat = $this->db->one('SELECT * FROM categories WHERE id = ? FOR UPDATE', [$id]);
            if (!$cat) {
                throw new HttpException(404, 'ไม่พบหมวดหมู่');
            }
            $count = $this->categories->countPrompts($id);
            $moved = 0;
            if ($count > 0) {
                if ($moveTo <= 0) {
                    throw new ValidationException(['move_to' => "หมวดหมู่ “{$cat['name']}” มี Prompt {$count} รายการ กรุณาเลือกหมวดหมู่ปลายทางเพื่อย้ายก่อนลบ"]);
                }
                $target = $this->categories->find($moveTo);
                if (!$target || $moveTo === $id) {
                    throw new ValidationException(['move_to' => 'หมวดหมู่ปลายทางไม่ถูกต้อง']);
                }
                $moved = $this->categories->movePrompts($id, $moveTo);
            }
            $this->categories->delete($id);
            $this->logs->log($admin, 'category.delete', 'category', $id, $cat['name'] . ($moved ? " (ย้าย {$moved} Prompt)" : ''));
            return $moved ? "ย้าย Prompt {$moved} รายการ และลบหมวดหมู่ “{$cat['name']}” แล้ว" : "ลบหมวดหมู่ “{$cat['name']}” แล้ว";
        });
    }
}
