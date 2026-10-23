<?php
declare(strict_types=1);

namespace PromptHub\Services;

use PromptHub\Core\Database;
use PromptHub\Core\HttpException;
use PromptHub\Core\ValidationException;
use PromptHub\Core\Validator;
use PromptHub\Repositories\ActivityLogRepository;
use PromptHub\Repositories\AiToolRepository;
use PromptHub\Repositories\CategoryRepository;
use PromptHub\Repositories\PromptRepository;
use PromptHub\Repositories\TagRepository;

/**
 * วงจรการเผยแพร่ Prompt
 *  - ส่วนตัว                          → draft (เห็นเฉพาะเจ้าของและผู้ดูแล)
 *  - สาธารณะ + บันทึกร่าง             → draft
 *  - สาธารณะ + ส่งตรวจ                → pending
 *  - แก้ไขรายการที่ pending/published  → pending (หยุดแสดงต่อสาธารณะจนกว่าจะอนุมัติใหม่)
 *  - ผู้ดูแลแก้ไขรายการที่ published   → คงสถานะ published (บันทึกกิจกรรม)
 *  - ผู้ดูแลอนุมัติ                    → published / ไม่อนุมัติ → rejected พร้อมเหตุผล
 */
final class PromptService
{
    public const MAX_TAGS = 10;
    public const MAX_TOOLS = 20;

    private PromptRepository $prompts;
    private TagRepository $tags;
    private AiToolRepository $tools;
    private CategoryRepository $categories;
    private ActivityLogRepository $logs;

    public function __construct(private Database $db)
    {
        $this->prompts = new PromptRepository($db);
        $this->tags = new TagRepository($db);
        $this->tools = new AiToolRepository($db);
        $this->categories = new CategoryRepository($db);
        $this->logs = new ActivityLogRepository($db);
    }

    /** โหลด Prompt ที่ผู้ใช้มีสิทธิ์แก้ไข ไม่มีสิทธิ์ดู → 404 (ไม่เปิดเผยว่ามีอยู่) มีสิทธิ์ดูแต่แก้ไม่ได้ → 403 */
    public function editable(array $user, int $id): array
    {
        $p = $this->prompts->find($id);
        if (!$p || !PromptPolicy::canView($user, $p)) {
            throw new HttpException(404, 'ไม่พบ Prompt ที่ต้องการ');
        }
        if (!PromptPolicy::canEdit($user, $p)) {
            throw new HttpException(403, 'คุณไม่มีสิทธิ์แก้ไข Prompt นี้');
        }
        return $p;
    }

    /** แยกแท็กจากข้อความคั่นด้วยจุลภาค */
    public static function parseTags(string $raw, Validator $v): array
    {
        $out = [];
        foreach (preg_split('/[,\n]+/u', $raw) ?: [] as $t) {
            $t = trim(preg_replace('/\s+/u', ' ', ltrim(trim($t), '#')) ?? '');
            if ($t === '') {
                continue;
            }
            if (mb_strlen($t) > 40) {
                $v->addError('tags', 'แต่ละแท็กต้องไม่เกิน 40 ตัวอักษร');
                return [];
            }
            $out[mb_strtolower($t)] = $t;
        }
        if (count($out) > self::MAX_TAGS) {
            $v->addError('tags', 'ใส่แท็กได้ไม่เกิน ' . self::MAX_TAGS . ' รายการ');
            return [];
        }
        return array_values($out);
    }

    /** บันทึก Prompt ใหม่หรือแก้ไข พร้อมแท็กและเครื่องมือ AI ใน Transaction เดียว คืนรหัส Prompt */
    public function save(array $user, array $input, ?array $existing, $files = null): int
    {
        $v = new Validator($input);
        $data = [
            'title' => $v->string('title', 'ชื่อ Prompt', 3, 200),
            'description' => $v->text('description', 'คำอธิบายสั้น', 10, 500),
            'content' => $v->text('content', 'เนื้อหา Prompt', 10, 20000),
            // คำแปลภาษาไทย (ไม่บังคับ) ช่วยให้ผู้ใช้เข้าใจ Prompt ภาษาอังกฤษ
            'content_th' => $v->text('content_th', 'คำแปลภาษาไทย', 0, 20000),
            // ฟอร์มไม่มีช่องตัวอย่างวิธีใช้งาน/ผลลัพธ์แล้ว — คงค่าเดิมไว้เมื่อแก้ไข ไม่ให้ข้อมูลเก่าหาย
            'usage_example' => (string) ($existing['usage_example'] ?? ''),
            'output_example' => (string) ($existing['output_example'] ?? ''),
            'category_id' => $v->int('category_id', 'หมวดหมู่'),
            'visibility' => $v->in('visibility', 'การมองเห็น', ['private', 'public']),
        ];
        $intent = $v->in('intent', 'การดำเนินการ', ['draft', 'submit']);
        $tagNames = self::parseTags(is_string($input['tags'] ?? null) ? $input['tags'] : '', $v);
        $toolIds = $v->ids('ai_tools', 'เครื่องมือ AI', self::MAX_TOOLS);

        // หมวดหมู่ต้องมีอยู่และเปิดใช้งาน ยกเว้นเป็นหมวดเดิมของ Prompt ที่กำลังแก้ไข
        if (!$v->hasError('category_id')) {
            $cat = $this->categories->find($data['category_id']);
            $keepOld = $existing && (int) $existing['category_id'] === $data['category_id'];
            if (!$cat || (!(int) $cat['is_active'] && !$keepOld)) {
                $v->addError('category_id', 'กรุณาเลือกหมวดหมู่ที่เปิดใช้งาน');
            }
        }
        // ภาพตัวอย่าง: เพิ่มได้เฉพาะหมวดหมู่ที่เปิดรับภาพ รวมแล้วไม่เกิน MAX_IMAGES
        $imageSvc = new ImageService($this->db);
        [$newImages, $imageError] = $imageSvc->validate($files);
        $removeIds = $v->ids('remove_images', 'ภาพที่เลือกลบ', 20);
        $currentIds = $existing ? array_map('intval', array_column($imageSvc->forPrompt((int) $existing['id']), 'id')) : [];
        $removeIds = array_values(array_intersect($removeIds, $currentIds));
        if ($imageError !== null) {
            $v->addError('images', $imageError);
        } elseif ($newImages) {
            if (isset($cat) && $cat && !(int) $cat['allow_images']) {
                $v->addError('images', 'หมวดหมู่นี้ไม่รับภาพตัวอย่าง เลือกหมวดหมู่ที่เกี่ยวกับภาพก่อนอัปโหลด');
            } elseif (count($currentIds) - count($removeIds) + count($newImages) > ImageService::MAX_IMAGES) {
                $v->addError('images', 'แนบภาพได้ไม่เกิน ' . ImageService::MAX_IMAGES . ' ภาพต่อ Prompt');
            }
        }
        $oldToolIds = $existing ? array_column($existing['tools'], 'id') : [];
        $validTools = $this->tools->validIds($toolIds, $oldToolIds);
        if (count($validTools) !== count($toolIds)) {
            $v->addError('ai_tools', 'มีเครื่องมือ AI ที่ไม่ถูกต้องหรือปิดใช้งาน');
        }
        $v->validate();

        $isAdmin = PromptPolicy::isAdmin($user);
        $data['status'] = $this->nextStatus($data['visibility'], $intent, $existing, $isAdmin);

        $written = [];
        $removedFiles = [];
        try {
            $id = $this->db->transaction(function () use ($user, $data, $existing, $tagNames, $validTools, $isAdmin, $imageSvc, $newImages, $removeIds, &$written, &$removedFiles): int {
            if ($existing) {
                // ล็อกแถวก่อนแก้ไข ป้องกันการแข่งกับการอนุมัติ
                $locked = $this->prompts->find((int) $existing['id'], true);
                if (!$locked) {
                    throw new HttpException(404, 'ไม่พบ Prompt ที่ต้องการ');
                }
                $id = (int) $existing['id'];
                $this->prompts->update($id, $data);
            } else {
                $data['user_id'] = (int) $user['id'];
                $id = $this->prompts->insert($data);
            }
            $this->prompts->syncTags($id, $this->tags->idsForNames($tagNames));
            $this->prompts->syncTools($id, $validTools);
            $removedFiles = $imageSvc->removeFromPrompt($id, $removeIds);
            $written = $imageSvc->store($id, $newImages);
            if ($isAdmin && $existing && (int) $existing['user_id'] !== (int) $user['id']) {
                $this->logs->log($user, 'prompt.update', 'prompt', $id, $data['title'] . ' → ' . $data['status']);
            }
            return $id;
            });
        } catch (\Throwable $e) {
            // ฐานข้อมูล rollback แล้ว: ลบไฟล์ที่เพิ่งเขียนลงดิสก์ เพื่อไม่ให้เหลือไฟล์ค้าง (ภาพเดิมบน Drive ยังอยู่)
            ImageService::forgetDriveQueue();
            ImageService::deleteFiles($written);
            throw $e;
        }
        ImageService::deleteFiles($removedFiles);
        // อัปโหลดภาพใหม่ขึ้น Google Drive (ถ้าเชื่อมต่อไว้) — ล้มเหลวก็ยังใช้ภาพในเครื่องได้ และอัปโหลดซ้ำได้ภายหลังจากหลังบ้าน
        if ($written && GoogleDriveService::active() && \PromptHub\Core\App::booted()) {
            (new GoogleDriveService($this->db, \PromptHub\Core\App::get()->config))->syncPending($id);
        }
        return $id;
    }

    private function nextStatus(string $visibility, string $intent, ?array $existing, bool $isAdmin): string
    {
        if ($visibility === 'private') {
            return 'draft';
        }
        if ($existing && $existing['status'] === 'published' && $existing['visibility'] === 'public' && $isAdmin) {
            return 'published';
        }
        if ($intent === 'submit') {
            return 'pending';
        }
        if ($existing && in_array($existing['status'], ['pending', 'published'], true)) {
            return 'pending';
        }
        return 'draft';
    }

    /** ผู้ดูแลอนุมัติหรือไม่อนุมัติ (ไม่อนุมัติต้องมีเหตุผล) */
    public function moderate(array $admin, int $id, string $decision, string $reason): array
    {
        return $this->db->transaction(function () use ($admin, $id, $decision, $reason): array {
            $p = $this->prompts->find($id, true);
            if (!$p) {
                throw new HttpException(404, 'ไม่พบ Prompt ที่ต้องการ');
            }
            if ($p['visibility'] !== 'public') {
                throw new ValidationException(['decision' => 'Prompt ส่วนตัวไม่ต้องผ่านการตรวจสอบ']);
            }
            if ($decision === 'approve') {
                if ($p['status'] !== 'pending') {
                    throw new ValidationException(['decision' => 'อนุมัติได้เฉพาะรายการที่รอตรวจสอบ']);
                }
                $this->prompts->approve($id, (int) $admin['id']);
                $this->logs->log($admin, 'prompt.approve', 'prompt', $id, $p['title']);
            } else {
                if (!in_array($p['status'], ['pending', 'published'], true)) {
                    throw new ValidationException(['decision' => 'ไม่อนุมัติได้เฉพาะรายการที่รอตรวจสอบหรือเผยแพร่อยู่']);
                }
                $v = new Validator(['reason' => $reason]);
                $reason = $v->text('reason', 'เหตุผลที่ไม่อนุมัติ', 5, 1000);
                $v->validate();
                $this->prompts->reject($id, (int) $admin['id'], $reason);
                $this->logs->log($admin, 'prompt.reject', 'prompt', $id, $p['title'] . ' | ' . $reason);
            }
            return $p;
        });
    }

    public function delete(array $user, int $id): void
    {
        $p = $this->editable($user, $id);
        $files = (new ImageService($this->db))->filesForPrompt((int) $p['id']);
        $this->db->transaction(function () use ($user, $p): void {
            $this->prompts->delete((int) $p['id']);
            if (!PromptPolicy::isOwner($user, $p)) {
                $this->logs->log($user, 'prompt.delete', 'prompt', (int) $p['id'], $p['title']);
            }
        });
        ImageService::deleteFiles($files);
    }
}
