<?php
declare(strict_types=1);

namespace PromptHub\Controllers;

use PromptHub\Core\HttpException;
use PromptHub\Core\ValidationException;
use PromptHub\Core\Validator;
use PromptHub\Repositories\ShopRepository;
use PromptHub\Services\ShopService;

/** Digital Shop หน้าบ้าน: รายการสินค้า รายละเอียด รับฟรี/แจ้งชำระเงิน คลังของฉัน ดาวน์โหลด */
final class ShopController extends Controller
{
    public const PER_PAGE = 24;

    private function repo(): ShopRepository
    {
        return new ShopRepository($this->db);
    }

    /** ร้านปิดอยู่ (หลังบ้าน › Digital Shop) เปิดดูได้เฉพาะผู้ดูแล */
    private function ensureOpen(): void
    {
        if ($this->app->setting('shop_enabled', '1') !== '1' && !$this->auth->isAdmin()) {
            throw new HttpException(404, 'Digital Shop ยังไม่เปิดให้บริการ');
        }
    }

    /** สินค้าที่เปิดขาย (ผู้ดูแลเปิดดูสินค้าที่ซ่อนได้ / ผู้ที่ได้รับสินค้าแล้วยังเปิดได้) */
    private function product(int $id): array
    {
        $p = $id ? $this->repo()->find($id) : null;
        $uid = $this->auth->id();
        if (!$p || (!(int) $p['is_active'] && !$this->auth->isAdmin() && !($uid && $this->repo()->owns($uid, $id)))) {
            throw new HttpException(404, 'ไม่พบสินค้า หรือสินค้านี้ปิดการขายแล้ว');
        }
        return $p;
    }

    public function index(): void
    {
        $this->ensureOpen();
        $filters = [
            'type' => $this->queryIn('type', array_keys(ShopRepository::TYPES)),
            'q' => $this->query('q', 100),
            'sort' => $this->queryIn('sort', ['latest', 'popular', 'price_asc', 'price_desc'], 'latest'),
        ];
        $page = $this->page();
        [$rows, $total] = $this->repo()->search($filters, $page, self::PER_PAGE);
        $uid = $this->auth->id();
        $this->render('shop/index', [
            'title' => 'Digital Shop',
            'filters' => $filters,
            'rows' => $rows,
            'pager' => self::pagination($total, $page, self::PER_PAGE),
            'typeCounts' => $this->repo()->typeCounts(),
            'favoriteIds' => $uid ? $this->repo()->favoriteIds($uid) : [],
        ]);
    }

    public function show(): void
    {
        $this->ensureOpen();
        $id = $this->queryId('id');
        $p = $this->product($id);
        $uid = $this->auth->id();
        if ((int) $p['is_active']) {
            $seen = $this->session->get('shop_seen', []);
            if (!isset($seen[$id])) {
                $this->repo()->incrementView($id);
                $p['view_count']++;
                $seen[$id] = 1;
                $this->session->set('shop_seen', array_slice($seen, -200, null, true));
            }
        }
        $order = $uid ? $this->repo()->userOrder($uid, $id) : null;
        $this->render('shop/show', [
            'title' => $p['title'],
            'product' => $p,
            'order' => $order,
            'owned' => $order && $order['status'] === 'paid',
            'isFavorite' => $uid && in_array($id, $this->repo()->favoriteIds($uid), true),
            'reviews' => $this->repo()->reviews($id),
            'myReview' => $uid ? $this->repo()->userReview($uid, $id) : null,
            'shareUrl' => absolute_url('shop.item', ['id' => $id]),
        ]);
    }

    /** ภาพปกสินค้า (สินค้าที่เปิดขาย หรือผู้ดูแล/ผู้ที่ได้รับสินค้าแล้ว) */
    public function cover(): void
    {
        $p = $this->product($this->queryId('id'));
        $this->sendFile((string) $p['cover_file'], 'cover', true);
    }

    public function favorite(): void
    {
        $user = $this->auth->requireMember();
        $id = $this->postId();
        if (!$this->repo()->find($id, true)) {
            throw new HttpException(404, 'ไม่พบสินค้า');
        }
        $on = $this->repo()->toggleFavorite((int) $user['id'], $id);
        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            $this->json(['ok' => true, 'favorite' => $on]);
            return;
        }
        $this->flash($on ? 'success' : 'info', $on ? 'เพิ่มในรายการโปรดแล้ว' : 'นำออกจากรายการโปรดแล้ว');
        $this->postString('back') === 'library' ? redirect('library', ['tab' => 'favorites']) : redirect('shop.item', ['id' => $id]);
    }

    public function claim(): void
    {
        $user = $this->auth->requireMember();
        $this->ensureOpen();
        $id = $this->postId();
        $orderId = (new ShopService($this->db))->claimFree($user, $id);
        $this->flash('success', $orderId ? 'รับสินค้าแล้ว เปิดใช้งานได้ที่คลังของฉัน' : 'คุณได้รับสินค้านี้แล้ว');
        redirect('library');
    }

    /** หน้าชำระเงิน: QR พร้อมเพย์ระบุยอด + ข้อมูลบัญชี + แนบสลิป */
    public function checkout(): void
    {
        $user = $this->auth->requireMember();
        $this->ensureOpen();
        $this->renderCheckout($user, $this->queryId('id'), [], []);
    }

    private function renderCheckout(array $user, int $id, array $errors, array $old, int $status = 200): void
    {
        $p = $this->product($id);
        if (!(int) $p['is_active']) {
            throw new HttpException(404, 'สินค้านี้ปิดการขายแล้ว');
        }
        if ((float) $p['price'] <= 0) {
            redirect('shop.item', ['id' => $id]);
        }
        $order = $this->repo()->userOrder((int) $user['id'], $id);
        if ($order && $order['status'] === 'paid') {
            $this->flash('info', 'คุณได้รับสินค้านี้แล้ว');
            redirect('library');
        }
        $ppId = ShopService::promptPayId($this->app->setting('shop_promptpay'));
        $this->render('shop/checkout', [
            'title' => 'ชำระเงิน · ' . $p['title'],
            'product' => $p,
            'order' => $order,
            'promptPay' => $ppId !== '' ? ShopService::promptPayPayload($ppId, (float) $p['price']) : '',
            'promptPayId' => $ppId,
            'errors' => $errors,
            'old' => $old,
        ], $status);
    }

    public function order(): void
    {
        $user = $this->auth->requireMember();
        $this->ensureOpen();
        $id = $this->postId();
        try {
            (new ShopService($this->db))->placeOrder($user, $id, $_FILES['slip'] ?? null, $this->postString('buyer_note'));
        } catch (ValidationException $e) {
            $this->renderCheckout($user, $id, $e->errors(), ['buyer_note' => $this->postString('buyer_note')], 422);
            return;
        }
        $this->flash('success', 'ส่งหลักฐานการชำระเงินแล้ว ผู้ดูแลจะตรวจสอบและปลดล็อกสินค้าให้ในคลังของฉัน');
        redirect('library', ['tab' => 'orders']);
    }

    /** รีวิวได้เฉพาะผู้ที่ได้รับสินค้าแล้ว (คนละ 1 รีวิว แก้ไขได้) / ผู้ดูแลลบรีวิวได้ */
    public function review(): void
    {
        $user = $this->auth->requireMember();
        $id = $this->postId();
        $repo = $this->repo();
        if ($this->postString('op') === 'delete' && $this->auth->isAdmin()) {
            $repo->deleteReview($this->postId('review_id'));
            $this->flash('info', 'ลบรีวิวแล้ว');
            redirect('shop.item', ['id' => $id]);
        }
        if (!$repo->owns((int) $user['id'], $id)) {
            throw new HttpException(403, 'รีวิวได้เฉพาะผู้ที่ได้รับสินค้านี้แล้ว');
        }
        $v = new Validator($_POST);
        $rating = $v->int('rating', 'คะแนน', 1, 5);
        $comment = $v->text('comment', 'ความคิดเห็น', 0, 1000);
        try {
            $v->validate();
        } catch (ValidationException $e) {
            $this->flash('danger', implode(' ', $e->errors()));
            redirect('shop.item', ['id' => $id]);
        }
        $repo->saveReview((int) $user['id'], $id, $rating, $comment);
        $this->flash('success', 'บันทึกรีวิวแล้ว ขอบคุณสำหรับความคิดเห็น');
        redirect('shop.item', ['id' => $id]);
    }

    /** คลังของฉัน: สินค้าดิจิทัล / คำสั่งซื้อ / รายการโปรด */
    public function library(): void
    {
        $user = $this->auth->requireMember();
        $uid = (int) $user['id'];
        $repo = $this->repo();
        $items = $repo->library($uid);
        $orders = $repo->userOrders($uid);
        $favorites = $repo->favorites($uid);
        $this->render('shop/library', [
            'title' => 'คลังของฉัน',
            'tab' => $this->queryIn('tab', ['products', 'orders', 'favorites'], 'products'),
            'items' => $items,
            'orders' => $orders,
            'favorites' => $favorites,
            'pendingOrders' => count(array_filter($orders, static fn($o) => $o['status'] === 'pending')),
        ]);
    }

    /** ดาวน์โหลดไฟล์สินค้า (k=file|code) — เฉพาะผู้ที่ได้รับสินค้าแล้วและผู้ดูแล */
    public function download(): void
    {
        $user = $this->auth->requireMember();
        $id = $this->queryId('id');
        $p = $id ? $this->repo()->find($id) : null;
        $order = $p ? $this->repo()->userOrder((int) $user['id'], $id) : null;
        $isOwner = $order && $order['status'] === 'paid';
        if (!$p || (!$isOwner && !$this->auth->isAdmin())) {
            throw new HttpException(404, 'ไม่พบไฟล์ หรือคุณยังไม่ได้รับสินค้านี้');
        }
        $kind = $this->queryIn('k', ['file', 'code'], 'file');
        $name = (string) ($kind === 'code' ? $p['code_name'] : $p['file_name']);
        $original = (string) ($kind === 'code' ? $p['code_original'] : $p['file_original']);
        if ($isOwner) {
            $this->repo()->incrementDownload((int) $order['id']);
        }
        $this->sendFile($name, $original !== '' ? $original : 'download.' . pathinfo($name, PATHINFO_EXTENSION), false);
    }

    /** ภาพสลิป: เจ้าของคำสั่งซื้อหรือผู้ดูแล */
    public function slip(): void
    {
        $user = $this->auth->requireMember();
        $o = $this->repo()->findOrder($this->queryId('id'));
        if (!$o || ((int) $o['user_id'] !== (int) $user['id'] && !$this->auth->isAdmin())) {
            throw new HttpException(404, 'ไม่พบสลิป');
        }
        $this->sendFile((string) $o['slip_file'], 'slip-' . $o['order_no'], true);
    }

    private function sendFile(string $name, string $downloadName, bool $inline): void
    {
        $path = ShopService::dir() . '/' . $name;
        if (!ShopService::validName($name) || !is_file($path)) {
            throw new HttpException(404, 'ไม่พบไฟล์');
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? (ShopService::DOWNLOAD_MIME[$ext] ?? 'application/octet-stream');
        if ($inline && !str_contains($downloadName, '.')) {
            $downloadName .= '.' . $ext;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cache-Control: private, max-age=' . ($inline ? '300' : '0'));
        readfile($path);
        exit;
    }
}
