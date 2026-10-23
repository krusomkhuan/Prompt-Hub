<?php
declare(strict_types=1);

namespace PromptHub\Controllers;

use PromptHub\Repositories\CategoryRepository;
use PromptHub\Repositories\PromptRepository;
use PromptHub\Repositories\TagRepository;
use PromptHub\Core\HttpException;
use PromptHub\Services\AvatarService;
use PromptHub\Services\BadgeService;

final class HomeController extends Controller
{
    public function index(): void
    {
        $prompts = new PromptRepository($this->db);
        $categories = (new CategoryRepository($this->db))->activeWithPublicCounts();
        [$latest] = $prompts->search(['sort' => 'latest'], 'public', 0, 1, 6);
        [$popular] = $prompts->search(['sort' => 'copies'], 'public', 0, 1, 4);
        $stats = $prompts->publicStats();
        $stats['categories'] = count($categories);
        $this->render('public/home', [
            'title' => '',
            'stats' => $stats,
            'categories' => $categories,
            'latest' => $latest,
            'popular' => $popular,
            'tags' => (new TagRepository($this->db))->popularPublic(12),
        ]);
    }

    /** ทำเนียบผู้สร้าง Prompt: สมาชิกที่สะสมแต้มจาก Prompt ที่เผยแพร่ */
    public function leaders(): void
    {
        $this->render('public/leaders', [
            'title' => 'ทำเนียบผู้สร้าง Prompt',
            'rows' => (new BadgeService($this->db))->leaderboard(50),
        ]);
    }

    /** ภาพโปรไฟล์ของผู้ใช้ตามรหัสผู้ใช้ (แสดงข้างชื่อผู้สร้าง Prompt จึงเปิดได้ทุกคน) */
    public function avatar(): void
    {
        $id = $this->queryId('id');
        $file = $id ? $this->db->value('SELECT avatar_file FROM users WHERE id = ?', [$id]) : null;
        $file = $file === null ? null : (string) $file;
        $path = AvatarService::validName($file) ? AvatarService::dir() . '/' . $file : '';
        if ($path === '' || !is_file($path)) {
            throw new HttpException(404, 'ไม่พบภาพโปรไฟล์');
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . AvatarService::MIME[pathinfo($path, PATHINFO_EXTENSION)]);
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        // URL มีส่วนของชื่อไฟล์ (v=...) เปลี่ยนภาพแล้ว URL เปลี่ยน จึงเก็บแคชได้
        header('Cache-Control: public, max-age=86400');
        readfile($path);
        exit;
    }

    public function about(): void
    {
        $this->render('public/about', ['title' => 'เกี่ยวกับระบบ']);
    }

    /** นโยบายความเป็นส่วนตัวตาม พ.ร.บ.คุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562 (PDPA) */
    public function privacy(): void
    {
        $this->render('public/privacy', [
            'title' => 'นโยบายความเป็นส่วนตัว',
            'mailEnabled' => (new \PromptHub\Core\Mailer((array) ($this->app->config['mail'] ?? [])))->enabled(),
        ]);
    }
}
