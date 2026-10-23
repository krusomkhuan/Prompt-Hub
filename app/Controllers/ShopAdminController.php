<?php
declare(strict_types=1);

namespace PromptHub\Controllers;

use PromptHub\Core\HttpException;
use PromptHub\Core\ValidationException;
use PromptHub\Core\Validator;
use PromptHub\Repositories\ActivityLogRepository;
use PromptHub\Repositories\SettingRepository;
use PromptHub\Repositories\ShopRepository;
use PromptHub\Services\ShopService;

/** หลังบ้าน Digital Shop: สินค้า คำสั่งซื้อ และช่องทางชำระเงิน (Router ตรวจสิทธิ์ผู้ดูแลก่อนเรียก) */
final class ShopAdminController extends Controller
{
    private function admin(): array
    {
        return $this->auth->requireAdmin();
    }

    public function products(): void
    {
        $this->admin();
        $repo = new ShopRepository($this->db);
        $filters = [
            'type' => $this->queryIn('type', array_keys(ShopRepository::TYPES)),
            'q' => $this->query('q', 100),
            'status' => $this->queryIn('status', ['active', 'hidden']),
            'sort' => 'latest',
        ];
        $page = $this->page();
        [$rows, $total] = $repo->search($filters, $page, 20, true);
        $this->render('admin/shop', [
            'title' => 'Digital Shop',
            'filters' => $filters,
            'rows' => $rows,
            'pager' => self::pagination($total, $page, 20),
            'stats' => $repo->orderStats(),
        ]);
    }

    public function form(): void
    {
        $this->admin();
        $id = $this->queryId('id');
        $p = $id ? (new ShopRepository($this->db))->find($id) : null;
        if ($id && !$p) {
            throw new HttpException(404, 'ไม่พบสินค้า');
        }
        $this->renderForm($p, $p ?? [], []);
    }

    private function renderForm(?array $p, array $old, array $errors, int $status = 200): void
    {
        $this->render('admin/shop_form', [
            'title' => $p ? 'แก้ไขสินค้า' : 'เพิ่มสินค้า',
            'product' => $p,
            'old' => $old,
            'errors' => $errors,
        ], $status);
    }

    public function save(): void
    {
        $admin = $this->admin();
        $repo = new ShopRepository($this->db);
        $existing = !empty($_POST['id']) ? $repo->find($this->postId()) : null;
        if (!empty($_POST['id']) && !$existing) {
            throw new HttpException(404, 'ไม่พบสินค้า');
        }
        try {
            $id = (new ShopService($this->db))->saveProduct($admin, $_POST, $_FILES, $existing);
        } catch (ValidationException $e) {
            $this->renderForm($existing, $_POST, $e->errors(), 422);
            return;
        }
        $this->flash('success', ($existing ? 'บันทึกสินค้า' : 'เพิ่มสินค้า') . ' “' . str_limit($this->postString('title'), 60) . '” แล้ว');
        redirect('admin.shop.form', ['id' => $id]);
    }

    public function delete(): void
    {
        $admin = $this->admin();
        $title = (new ShopService($this->db))->deleteProduct($admin, $this->postId());
        $this->flash('success', "ลบสินค้า “{$title}” แล้ว");
        redirect('admin.shop');
    }

    /** สลับเปิด/ปิดการขาย */
    public function toggle(): void
    {
        $admin = $this->admin();
        $id = $this->postId();
        $p = (new ShopRepository($this->db))->find($id);
        if (!$p) {
            throw new HttpException(404, 'ไม่พบสินค้า');
        }
        $active = (int) $p['is_active'] ? 0 : 1;
        $this->db->transaction(function () use ($admin, $id, $active, $p) {
            $this->db->execute('UPDATE shop_products SET is_active = ? WHERE id = ?', [$active, $id]);
            (new ActivityLogRepository($this->db))->log($admin, 'shop.product.update', 'shop_product', $id, $p['title'] . ($active ? ' · เปิดขาย' : ' · ปิดการขาย'));
        });
        $this->flash('success', ($active ? 'เปิดขาย' : 'ปิดการขาย') . ' “' . $p['title'] . '” แล้ว');
        redirect('admin.shop');
    }

    public function orders(): void
    {
        $this->admin();
        $repo = new ShopRepository($this->db);
        $status = $this->queryIn('status', ['pending', 'paid', 'rejected']);
        $q = $this->query('q', 100);
        $page = $this->page();
        [$rows, $total] = $repo->searchOrders($status, $q, $page, 20);
        $this->render('admin/shop_orders', [
            'title' => 'คำสั่งซื้อ Digital Shop',
            'status' => $status,
            'q' => $q,
            'rows' => $rows,
            'pager' => self::pagination($total, $page, 20),
            'stats' => $repo->orderStats(),
        ]);
    }

    public function review(): void
    {
        $admin = $this->admin();
        $decision = $this->postString('decision') === 'reject' ? 'reject' : 'approve';
        try {
            $o = (new ShopService($this->db))->reviewOrder($admin, $this->postId(), $decision, $this->postString('note'));
            $this->flash('success', ($decision === 'approve' ? 'อนุมัติคำสั่งซื้อ ' : 'ปฏิเสธคำสั่งซื้อ ') . $o['order_no'] . ' แล้ว');
        } catch (ValidationException $e) {
            $this->flash('danger', implode(' ', $e->errors()));
        }
        redirect('admin.orders', ['status' => $this->queryIn('status', ['pending', 'paid', 'rejected'])]);
    }

    /** เปิด/ปิดร้าน และช่องทางชำระเงิน (พร้อมเพย์ ชื่อบัญชี คำแนะนำการโอน) */
    public function settings(): void
    {
        $admin = $this->admin();
        $v = new Validator($_POST);
        $enabled = $v->bool('shop_enabled');
        $pp = $v->string('shop_promptpay', 'หมายเลขพร้อมเพย์', 0, 20);
        $name = $v->string('shop_account_name', 'ชื่อบัญชี', 0, 120);
        $note = $v->text('shop_payment_note', 'คำแนะนำการชำระเงิน', 0, 1000);
        if ($pp !== '' && ShopService::promptPayId($pp) === '') {
            $v->addError('shop_promptpay', 'หมายเลขพร้อมเพย์ต้องเป็นเบอร์โทร 10 หลัก เลขประจำตัว 13 หลัก หรือ e-Wallet 15 หลัก');
        }
        try {
            $v->validate();
        } catch (ValidationException $e) {
            $this->flash('danger', implode(' ', $e->errors()));
            redirect('admin.orders');
        }
        $this->db->transaction(function () use ($admin, $enabled, $pp, $name, $note) {
            (new SettingRepository($this->db))->save([
                'shop_enabled' => $enabled ? '1' : '0',
                'shop_promptpay' => ShopService::promptPayId($pp),
                'shop_account_name' => $name,
                'shop_payment_note' => $note,
            ]);
            (new ActivityLogRepository($this->db))->log($admin, 'shop.settings', 'site_settings', null, $enabled ? 'เปิดร้าน' : 'ปิดร้าน');
        });
        $this->flash('success', 'บันทึกการตั้งค่า Digital Shop แล้ว');
        redirect('admin.orders');
    }
}
