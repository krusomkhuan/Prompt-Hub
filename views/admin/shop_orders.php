<?php
/** หลังบ้าน Digital Shop: ตรวจคำสั่งซื้อ (ดูสลิป อนุมัติ/ปฏิเสธ) และตั้งค่าร้าน/ช่องทางชำระเงิน */
use PromptHub\Services\ShopService;

$tabs = ['' => ['ทั้งหมด', null], 'pending' => ['รอตรวจสอบ', $stats['pending']], 'paid' => ['สำเร็จ', $stats['paid']], 'rejected' => ['ไม่ผ่าน', $stats['rejected']]];
?>
<div class="container py-4">
    <?= $view->partial('partials/admin_nav') ?>
    <h1 class="h3 mb-3">คำสั่งซื้อ Digital Shop</h1>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
                <ul class="nav nav-pills">
                    <?php foreach ($tabs as $k => [$label, $count]): ?>
                        <li class="nav-item"><a class="nav-link py-1<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('admin.orders', ['status' => $k, 'q' => $q])) ?>"><?= e($label) ?><?php if ($count): ?> <span class="badge rounded-pill <?= $k === 'pending' ? 'text-bg-warning' : 'text-bg-secondary' ?>"><?= (int) $count ?></span><?php endif; ?></a></li>
                    <?php endforeach; ?>
                </ul>
                <form class="d-flex gap-2" method="get" action="<?= e(url('admin.orders')) ?>" role="search">
                    <input type="hidden" name="r" value="admin.orders">
                    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
                    <input class="form-control form-control-sm" type="search" name="q" value="<?= e($q) ?>" placeholder="เลขที่ / สินค้า / ผู้ซื้อ" aria-label="ค้นหาคำสั่งซื้อ">
                    <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-search"></i><span class="visually-hidden">ค้นหา</span></button>
                </form>
            </div>

            <?php if (!$rows): ?>
                <?= $view->partial('partials/empty', ['icon' => 'receipt', 'heading' => 'ไม่มีคำสั่งซื้อ']) ?>
            <?php else: ?>
                <div class="d-flex flex-column gap-2">
                    <?php foreach ($rows as $o): ?>
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex flex-wrap justify-content-between gap-2">
                                    <div class="min-w-0">
                                        <div class="small text-secondary"><?= e($o['order_no']) ?> · <?= e(thai_date($o['created_at'])) ?></div>
                                        <a class="fw-semibold" href="<?= e(url('shop.item', ['id' => $o['product_id']])) ?>"><?= e($o['product_title']) ?></a>
                                        <div class="small"><i class="bi bi-person me-1"></i><?= e($o['display_name']) ?> <span class="text-secondary">(@<?= e($o['username']) ?> · <?= e($o['email']) ?>)</span></div>
                                        <?php if ($o['buyer_note'] !== ''): ?><div class="small mt-1"><i class="bi bi-chat-left-text me-1"></i><?= e($o['buyer_note']) ?></div><?php endif; ?>
                                        <?php if ($o['status'] !== 'pending' && $o['reviewer']): ?>
                                            <div class="small text-secondary mt-1">ตรวจโดย @<?= e($o['reviewer']) ?> <?= e(thai_date($o['reviewed_at'])) ?><?= $o['admin_note'] !== '' ? ' · ' . e($o['admin_note']) : '' ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-end">
                                        <div class="shop-price fs-5"><?= e(ShopService::priceText((float) $o['amount'])) ?></div>
                                        <?= shop_order_badge($o['status']) ?>
                                        <?php if ($o['slip_file']): ?>
                                            <div class="mt-2"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('shop.slip', ['id' => $o['id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-image me-1"></i>ดูสลิป</a></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($o['status'] === 'pending'): ?>
                                    <div class="d-flex flex-wrap gap-2 mt-3 pt-3 border-top">
                                        <form method="post" action="<?= e(url('admin.order.review', ['status' => $status])) ?>">
                                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="decision" value="approve">
                                            <button class="btn btn-sm btn-success" type="submit"><i class="bi bi-check2-circle me-1"></i>ยืนยันการชำระเงิน</button>
                                        </form>
                                        <form method="post" action="<?= e(url('admin.order.review', ['status' => $status])) ?>" class="d-flex gap-2 flex-grow-1">
                                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="decision" value="reject">
                                            <input class="form-control form-control-sm" name="note" maxlength="500" minlength="3" required placeholder="เหตุผลที่ไม่ผ่าน (แสดงให้ผู้ซื้อเห็น)" aria-label="เหตุผลที่ไม่ผ่าน">
                                            <button class="btn btn-sm btn-outline-danger text-nowrap" type="submit"><i class="bi bi-x-circle me-1"></i>ไม่ผ่าน</button>
                                        </form>
                                    </div>
                                <?php elseif ($o['status'] === 'rejected'): ?>
                                    <form method="post" action="<?= e(url('admin.order.review', ['status' => $status])) ?>" class="mt-2" data-confirm="ยืนยันการชำระเงินของคำสั่งซื้อ <?= e($o['order_no']) ?> ที่เคยปฏิเสธไว้?">
                                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="decision" value="approve">
                                        <button class="btn btn-sm btn-link px-0" type="submit">เปลี่ยนเป็นยืนยันการชำระเงิน</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?= $view->partial('partials/pagination', ['pager' => $pager, 'routeName' => 'admin.orders', 'params' => ['status' => $status, 'q' => $q]]) ?>
            <?php endif; ?>
        </div>

        <div class="col-lg-4">
            <form class="card card-body" method="post" action="<?= e(url('admin.shop.settings')) ?>" id="shop-settings">
                <?= csrf_field() ?>
                <h2 class="h5"><i class="bi bi-gear me-1"></i>ตั้งค่าร้าน</h2>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="shop_enabled" name="shop_enabled" value="1"<?= $app->setting('shop_enabled', '1') === '1' ? ' checked' : '' ?>>
                    <label class="form-check-label" for="shop_enabled">เปิด Digital Shop ให้ทุกคนเห็น</label>
                    <div class="form-text">ปิด = หน้าร้านเห็นเฉพาะผู้ดูแล (ผู้ที่ได้รับสินค้าแล้วยังเปิดคลังของฉันได้)</div>
                </div>
                <label class="form-label" for="shop_promptpay">หมายเลขพร้อมเพย์</label>
                <input class="form-control" id="shop_promptpay" name="shop_promptpay" maxlength="20" inputmode="numeric" value="<?= e($app->setting('shop_promptpay')) ?>" placeholder="เบอร์โทร 10 หลัก หรือเลข 13 หลัก">
                <div class="form-text mb-2">ใช้สร้าง QR พร้อมเพย์ระบุยอดเงินอัตโนมัติในหน้าชำระเงิน</div>
                <label class="form-label" for="shop_account_name">ชื่อบัญชีผู้รับเงิน</label>
                <input class="form-control mb-2" id="shop_account_name" name="shop_account_name" maxlength="120" value="<?= e($app->setting('shop_account_name')) ?>">
                <label class="form-label" for="shop_payment_note">คำแนะนำการชำระเงิน / บัญชีธนาคารอื่น</label>
                <textarea class="form-control" id="shop_payment_note" name="shop_payment_note" rows="4" maxlength="1000" placeholder="เช่น ธนาคาร… เลขที่บัญชี… โอนแล้วแนบสลิป ตรวจสอบภายใน 24 ชั่วโมง"><?= e($app->setting('shop_payment_note')) ?></textarea>
                <button class="btn btn-primary mt-3" type="submit"><i class="bi bi-save me-1"></i>บันทึกการตั้งค่า</button>
            </form>
        </div>
    </div>
</div>
