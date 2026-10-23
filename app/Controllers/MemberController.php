<?php
declare(strict_types=1);

namespace PromptHub\Controllers;

use PromptHub\Core\HttpException;
use PromptHub\Core\ValidationException;
use PromptHub\Core\Validator;
use PromptHub\Repositories\AiToolRepository;
use PromptHub\Repositories\CategoryRepository;
use PromptHub\Repositories\FavoriteRepository;
use PromptHub\Repositories\PromptRepository;
use PromptHub\Repositories\UserRepository;
use PromptHub\Services\AuthService;
use PromptHub\Services\AvatarService;
use PromptHub\Services\BadgeService;
use PromptHub\Services\PromptPolicy;
use PromptHub\Services\PromptService;

/** พื้นที่สมาชิก (Router ตรวจการเข้าสู่ระบบแล้วก่อนเรียก) */
final class MemberController extends Controller
{
    public function dashboard(): void
    {
        $user = $this->auth->requireMember();
        $repo = new PromptRepository($this->db);
        [$recent] = $repo->search(['sort' => 'updated'], 'owner', (int) $user['id'], 1, 5);
        $this->render('member/dashboard', [
            'title' => 'แดชบอร์ดของฉัน',
            'counts' => $repo->statusCountsForUser((int) $user['id']),
            'recent' => $recent,
            'badge' => $user['role'] === 'member' ? BadgeService::progress((new BadgeService($this->db))->points((int) $user['id'])) : null,
        ]);
    }

    public function myPrompts(): void
    {
        $user = $this->auth->requireMember();
        $filters = [
            'q' => $this->query('q', 100),
            'status' => $this->queryIn('status', ['draft', 'pending', 'published', 'rejected']),
            'visibility' => $this->queryIn('visibility', ['private', 'public']),
            'sort' => 'updated',
        ];
        $page = $this->page();
        [$rows, $total] = (new PromptRepository($this->db))->search($filters, 'owner', (int) $user['id'], $page, 15);
        $this->render('member/prompts', [
            'title' => 'Prompt ของฉัน',
            'filters' => $filters,
            'rows' => $rows,
            'pager' => self::pagination($total, $page, 15),
        ]);
    }

    public function form(): void
    {
        $user = $this->auth->requireMember();
        $id = $this->queryId('id');
        $prompt = $id ? (new PromptService($this->db))->editable($user, $id) : null;
        $this->renderForm($prompt, [], [], 200);
    }

    private function renderForm(?array $prompt, array $old, array $errors, int $status): void
    {
        $catId = (int) ($prompt['category_id'] ?? 0);
        $toolIds = $prompt ? array_column($prompt['tools'], 'id') : [];
        if (!$old && $prompt) {
            $old = $prompt;
            $old['tags'] = implode(', ', array_column($prompt['tags'], 'name'));
            $old['ai_tools'] = array_map('strval', $toolIds);
        }
        $this->render('member/prompt_form', [
            'title' => $prompt ? 'แก้ไข Prompt' : 'เพิ่ม Prompt ใหม่',
            'prompt' => $prompt,
            'images' => $prompt ? $prompt['images'] : [],
            'old' => $old,
            'errors' => $errors,
            'categories' => (new CategoryRepository($this->db))->optionsForForm($catId),
            'tools' => (new AiToolRepository($this->db))->optionsForForm($toolIds),
            'isAdmin' => $this->auth->isAdmin(),
        ], $status);
    }

    public function save(): void
    {
        $user = $this->auth->requireMember();
        $service = new PromptService($this->db);
        $existing = !empty($_POST['id']) ? $service->editable($user, $this->postId()) : null;
        try {
            $id = $service->save($user, $_POST, $existing, $_FILES['images'] ?? null);
        } catch (ValidationException $e) {
            $old = $_POST;
            $old['ai_tools'] = is_array($_POST['ai_tools'] ?? null) ? $_POST['ai_tools'] : [];
            $this->renderForm($existing, $old, $e->errors(), 422);
            return;
        }
        $saved = (new PromptRepository($this->db))->find($id);
        $msg = match ($saved['status']) {
            'pending' => 'บันทึกแล้ว และส่งให้ผู้ดูแลตรวจสอบก่อนเผยแพร่',
            'published' => 'บันทึกแล้ว Prompt ยังคงเผยแพร่อยู่',
            default => $saved['visibility'] === 'private' ? 'บันทึก Prompt ส่วนตัวแล้ว' : 'บันทึกฉบับร่างแล้ว',
        };
        if ($existing && $existing['status'] === 'published' && $saved['status'] === 'pending') {
            $msg = 'บันทึกการแก้ไขแล้ว Prompt ถูกพักการเผยแพร่และรอผู้ดูแลตรวจสอบอีกครั้ง';
        }
        $this->flash('success', $msg);
        redirect('prompt', ['id' => $id]);
    }

    public function delete(): void
    {
        $user = $this->auth->requireMember();
        (new PromptService($this->db))->delete($user, $this->postId());
        $this->flash('success', 'ลบ Prompt แล้ว');
        $back = $this->postString('back');
        redirect($back === 'admin' && $this->auth->isAdmin() ? 'admin.prompts' : 'my.prompts');
    }

    public function favorites(): void
    {
        $user = $this->auth->requireMember();
        $page = $this->page();
        [$rows, $total] = (new PromptRepository($this->db))->search(['sort' => 'latest'], 'favorites', (int) $user['id'], $page, 12);
        $this->render('member/favorites', [
            'title' => 'รายการโปรด',
            'rows' => $rows,
            'pager' => self::pagination($total, $page, 12),
            'hidden' => (new FavoriteRepository($this->db))->countHidden((int) $user['id']),
        ]);
    }

    /** เพิ่ม/ยกเลิกรายการโปรด เพิ่มได้เฉพาะ Prompt ที่เผยแพร่สาธารณะ */
    public function favorite(): void
    {
        $user = $this->auth->requireMember();
        $id = $this->postId();
        $op = $this->postString('op') === 'remove' ? 'remove' : 'add';
        $favorites = new FavoriteRepository($this->db);
        if ($op === 'remove') {
            $favorites->remove((int) $user['id'], $id);
            $this->flash('info', 'นำออกจากรายการโปรดแล้ว');
        } else {
            $prompt = (new PromptRepository($this->db))->find($id);
            if (!$prompt || !PromptPolicy::isPublic($prompt)) {
                throw new HttpException(404, 'ไม่พบ Prompt สาธารณะที่ต้องการ');
            }
            $added = $favorites->add((int) $user['id'], $id);
            $this->flash($added ? 'success' : 'info', $added ? 'เพิ่มในรายการโปรดแล้ว' : 'Prompt นี้อยู่ในรายการโปรดอยู่แล้ว');
        }
        $this->postString('back') === 'favorites' ? redirect('favorites') : redirect('prompt', ['id' => $id]);
    }

    public function profile(): void
    {
        $this->auth->requireMember();
        $this->render('member/profile', ['title' => 'โปรไฟล์', 'errors' => [], 'old' => []]);
    }

    public function profileUpdate(): void
    {
        $user = $this->auth->requireMember();
        try {
            $changed = (new AuthService($this->db))->updateProfile($user, $_POST);
        } catch (ValidationException $e) {
            $old = $_POST;
            unset($old['profile_password']);
            $this->render('member/profile', ['title' => 'โปรไฟล์', 'errors' => $e->errors(), 'old' => $old], 422);
            return;
        }
        $this->flash('success', $changed ? 'บันทึก' . implode(' ', $changed) . 'แล้ว' : 'ไม่มีข้อมูลที่เปลี่ยน');
        redirect('profile');
    }

    public function avatarUpdate(): void
    {
        $user = $this->auth->requireMember();
        try {
            (new AvatarService($this->db))->update((int) $user['id'], $_FILES['avatar'] ?? null);
        } catch (ValidationException $e) {
            $this->render('member/profile', ['title' => 'โปรไฟล์', 'errors' => $e->errors(), 'old' => []], 422);
            return;
        }
        $this->flash('success', 'เปลี่ยนภาพโปรไฟล์แล้ว');
        redirect('profile');
    }

    public function avatarDelete(): void
    {
        $user = $this->auth->requireMember();
        (new AvatarService($this->db))->remove((int) $user['id']);
        $this->flash('success', 'ลบภาพโปรไฟล์แล้ว');
        redirect('profile');
    }

    public function passwordUpdate(): void
    {
        $user = $this->auth->requireMember();
        try {
            $hash = (new AuthService($this->db))->changePassword($user, $_POST);
        } catch (ValidationException $e) {
            $this->render('member/profile', ['title' => 'โปรไฟล์', 'errors' => $e->errors(), 'old' => []], 422);
            return;
        }
        $this->auth->refreshPassword($hash);
        $this->flash('success', 'เปลี่ยนรหัสผ่านแล้ว อุปกรณ์อื่นที่เข้าสู่ระบบไว้จะต้องเข้าสู่ระบบใหม่');
        redirect('profile');
    }
}
