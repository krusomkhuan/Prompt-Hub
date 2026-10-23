<?php
declare(strict_types=1);

namespace PromptHub\Controllers;

use PromptHub\Core\HttpException;
use PromptHub\Repositories\AiToolRepository;
use PromptHub\Repositories\CategoryRepository;
use PromptHub\Repositories\FavoriteRepository;
use PromptHub\Repositories\PromptRepository;
use PromptHub\Repositories\TagRepository;
use PromptHub\Services\BadgeService;
use PromptHub\Services\GoogleDriveService;
use PromptHub\Services\PromptPolicy;
use PromptHub\Services\ImageService;

/** หน้าบ้าน: รายการ ค้นหา รายละเอียด หมวดหมู่ และการคัดลอก */
final class PromptController extends Controller
{
    public const PER_PAGE_OPTIONS = [12, 24, 48];

    public function index(): void
    {
        $this->listing(null);
    }

    /** รายการตามหมวดหมู่ */
    public function category(): void
    {
        $id = $this->queryId('id');
        $cat = $id ? (new CategoryRepository($this->db))->find($id) : null;
        if (!$cat) {
            throw new HttpException(404, 'ไม่พบหมวดหมู่ที่ต้องการ');
        }
        $this->listing($cat);
    }

    private function listing(?array $category): void
    {
        $filters = [
            'q' => $this->query('q', 100),
            'category' => $category ? (int) $category['id'] : $this->queryId('category'),
            'tag' => $this->queryId('tag'),
            'tool' => $this->queryId('tool'),
            'sort' => $this->queryIn('sort', ['latest', 'copies'], 'latest'),
        ];
        $perPage = (int) $this->queryIn('per_page', array_map('strval', self::PER_PAGE_OPTIONS), '12');
        $page = $this->page();
        $repo = new PromptRepository($this->db);
        [$rows, $total] = $repo->search($filters, 'public', 0, $page, $perPage);
        $pager = self::pagination($total, $page, $perPage);
        if (!$rows && $total > 0 && $page > $pager['pages']) {
            [$rows] = $repo->search($filters, 'public', 0, $pager['pages'], $perPage);
        }
        $tagName = '';
        if ($filters['tag']) {
            $tagName = (string) ((new TagRepository($this->db))->find($filters['tag'])['name'] ?? '');
        }
        $this->render('public/prompts', [
            'title' => $category ? $category['name'] : 'สำรวจ Prompt',
            'category' => $category,
            'filters' => $filters,
            'perPage' => $perPage,
            'rows' => $rows,
            'pager' => $pager,
            'tagName' => $tagName,
            'categories' => (new CategoryRepository($this->db))->activeWithPublicCounts(),
            'tools' => (new AiToolRepository($this->db))->active(),
            'routeName' => $category ? 'category' : 'prompts',
        ]);
    }

    public function categories(): void
    {
        $this->render('public/categories', [
            'title' => 'หมวดหมู่',
            'categories' => (new CategoryRepository($this->db))->activeWithPublicCounts(),
        ]);
    }

    public function show(): void
    {
        $id = $this->queryId('id');
        $repo = new PromptRepository($this->db);
        $prompt = $id ? $repo->find($id) : null;
        $user = $this->auth->user();
        // ไม่มีสิทธิ์ดู → ตอบ 404 เหมือนไม่มีรายการ เพื่อไม่เปิดเผยว่ามี Prompt ส่วนตัวนี้อยู่
        if (!$prompt || !PromptPolicy::canView($user, $prompt)) {
            throw new HttpException(404, 'ไม่พบ Prompt ที่ต้องการ หรือ Prompt นี้ยังไม่เผยแพร่');
        }
        if (PromptPolicy::isPublic($prompt) && !PromptPolicy::isOwner($user, $prompt)) {
            $seen = $this->session->get('seen', []);
            if (!isset($seen[$id])) {
                $repo->incrementView($id);
                $prompt['view_count']++;
                $seen[$id] = 1;
                $this->session->set('seen', array_slice($seen, -200, null, true));
            }
        }
        $isFavorite = $user ? (new FavoriteRepository($this->db))->exists((int) $user['id'], $id) : false;
        $this->render('public/prompt', [
            'title' => $prompt['title'],
            'prompt' => $prompt,
            'isFavorite' => $isFavorite,
            'canEdit' => PromptPolicy::canEdit($user, $prompt),
            'isOwner' => PromptPolicy::isOwner($user, $prompt),
            'isAdmin' => $this->auth->isAdmin(),
            'authorPoints' => $prompt['author_role'] === 'member' ? (new BadgeService($this->db))->points((int) $prompt['user_id']) : 0,
        ]);
    }

    /** ส่งไฟล์ภาพตัวอย่าง: ตรวจสิทธิ์ตาม Prompt ก่อนเสมอ (ภาพของ Prompt ส่วนตัว/รอตรวจสอบ เปิดตรงไม่ได้) */
    public function media(): void
    {
        $id = $this->queryId('id');
        $img = $id ? (new ImageService($this->db))->find($id) : null;
        $prompt = $img ? (new PromptRepository($this->db))->find((int) $img['prompt_id']) : null;
        $path = $img ? ImageService::dir() . '/' . $img['file_name'] : '';
        if (!$img || !$prompt || !PromptPolicy::canView($this->auth->user(), $prompt)) {
            throw new HttpException(404, 'ไม่พบภาพที่ต้องการ');
        }
        if (!is_file($path)) {
            // ไม่มีสำเนาบนเซิร์ฟเวอร์ (เช่น ย้ายเครื่อง/กู้คืนเฉพาะฐานข้อมูล) แต่มีบน Google Drive → ส่งไปภาพบน Drive
            if (!empty($img['drive_file_id'])) {
                header('Location: ' . GoogleDriveService::imageUrl((string) $img['drive_file_id'], 1600), true, 302);
                exit;
            }
            throw new HttpException(404, 'ไม่พบภาพที่ต้องการ');
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $img['mime']);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="prompt-image-' . (int) $img['id'] . '.' . pathinfo($img['file_name'], PATHINFO_EXTENSION) . '"');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cache-Control: private, max-age=300');
        readfile($path);
        exit;
    }

    /** นับจำนวนครั้งที่คัดลอกผ่านปุ่มของระบบ (POST + CSRF) ตอบเป็น JSON */
    public function copy(): void
    {
        $id = $this->postId();
        $repo = new PromptRepository($this->db);
        $prompt = $repo->find($id);
        if (!$prompt || !PromptPolicy::canView($this->auth->user(), $prompt)) {
            $this->json(['ok' => false, 'message' => 'ไม่พบ Prompt'], 404);
            return;
        }
        $count = $repo->incrementCopy($id);
        $this->json(['ok' => true, 'copy_count' => $count]);
    }
}
