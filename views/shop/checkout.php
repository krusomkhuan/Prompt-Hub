<?php
/** Digital Shop — ชำระเงิน: QR พร้อมเพย์ระบุยอด (สร้างในเบราว์เซอร์) ข้อมูลบัญชี และแนบสลิป */
use PromptHub\Services\ShopService;

$p = $product;
$price = (float) $p['price'];
$accountName = $app->setting('shop_account_name');
$payNote = $app->setting('shop_payment_note');
?>
<div class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="<?= e(url('shop')) ?>">Digital Shop</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('shop.item', ['id' => $p['id']])) ?>"><?= e(str_limit($p['title'], 40)) ?></a></li>
            <li class="breadcrumb-item active" aria-current="page">ชำระเงิน</li>
        </ol>
    </nav>
    <h1 class="h3 mb-4"><i class="bi bi-cart-check me-2"></i>ชำระเงิน</h1>

    <?php if ($order && $order['status'] === 'pending'): ?>
        <div class="alert alert-warning">
            <i class="bi bi-hourglass-split me-1"></i>คุณแจ้งชำระเงินสินค้านี้แล้ว (เลขที่ <?= e($order['order_no']) ?>) กำลังรอผู้ดูแลตรวจสอบ
            <a href="<?= e(url('library', ['tab' => 'orders'])) ?>" class="alert-link ms-1">ดูคำสั่งซื้อ</a>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-5 order-lg-2">
            <div class="card">
                <div class="card-body d-flex gap-3 align-items-center">
                    <?php if ($p['cover_file']): ?>
                        <img class="shop-thumb" src="<?= e(url('shop.cover', ['id' => $p['id'], 'v' => substr((string) $p['cover_file'], 0, 8)])) ?>" alt="">
                    <?php endif; ?>
                    <div class="min-w-0">
                        <div class="fw-semibold"><?= e($p['title']) ?></div>
                        <div class="small text-secondary"><?= e(\PromptHub\Repositories\ShopRepository::TYPES[$p['product_type']] ?? '') ?></div>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <span>ยอดชำระ</span><span class="shop-price fs-4"><?= e(ShopService::priceText($price)) ?></span>
                </div>
            </div>
        </div>

        <div class="col-lg-7 order-lg-1">
            <div class="card mb-3">
                <div class="card-body">
                    <h2 class="h5"><span class="step-no">1</span>สแกนจ่ายด้วยพร้อมเพย์ หรือโอนเงิน</h2>
                    <?php if ($promptPay !== ''): ?>
                        <div class="text-center my-3">
                            <div class="promptpay-qr" id="promptpayQr" data-payload="<?= e($promptPay) ?>" role="img" aria-label="QR Code พร้อมเพย์ ยอด <?= e(number_format($price, 2)) ?> บาท">
                                <span class="spinner-border text-secondary" aria-hidden="true"></span>
                            </div>
                            <div class="small text-secondary mt-2">ยอด <strong class="text-body"><?= e(number_format($price, 2)) ?> บาท</strong> (ระบุยอดใน QR แล้ว)</div>
                        </div>
                        <dl class="row small mb-0">
                            <dt class="col-sm-4 text-secondary fw-normal">พร้อมเพย์</dt>
                            <dd class="col-sm-8"><?= e(strlen($promptPayId) === 10 ? substr($promptPayId, 0, 3) . '-' . substr($promptPayId, 3, 3) . '-' . substr($promptPayId, 6) : $promptPayId) ?></dd>
                            <?php if ($accountName !== ''): ?>
                                <dt class="col-sm-4 text-secondary fw-normal">ชื่อบัญชี</dt>
                                <dd class="col-sm-8"><?= e($accountName) ?></dd>
                            <?php endif; ?>
                        </dl>
                    <?php elseif ($payNote === ''): ?>
                        <div class="alert alert-secondary small mb-0">ผู้ดูแลยังไม่ได้ตั้งช่องทางชำระเงิน กรุณาติดต่อผู้ดูแลระบบ</div>
                    <?php endif; ?>
                    <?php if ($payNote !== ''): ?>
                        <div class="example-box preserve-lines small mt-3"><?= e($payNote) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <form class="card" method="post" action="<?= e(url('shop.order')) ?>" enctype="multipart/form-data" novalidate>
                <div class="card-body">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                    <h2 class="h5"><span class="step-no">2</span>แนบสลิปการโอนเงิน</h2>
                    <label class="form-label" for="slip">ภาพสลิป (JPEG, PNG หรือ WebP ไม่เกิน 5 MB)</label>
                    <input class="form-control<?= invalid($errors, 'slip') ?>" type="file" id="slip" name="slip" accept="image/jpeg,image/png,image/webp" required data-resize-max="1600">
                    <div class="small text-secondary" data-resize-status></div>
                    <?= field_error($errors, 'slip') ?>
                    <label class="form-label mt-3" for="buyer_note">หมายเหตุถึงผู้ดูแล (ไม่บังคับ)</label>
                    <input class="form-control<?= invalid($errors, 'buyer_note') ?>" id="buyer_note" name="buyer_note" maxlength="500" value="<?= e(old($old, 'buyer_note')) ?>" placeholder="เช่น โอนจากบัญชีชื่อ… เวลา…">
                    <?= field_error($errors, 'buyer_note') ?>
                    <button class="btn btn-primary btn-lg w-100 mt-4" type="submit"<?= $order && $order['status'] === 'pending' ? ' disabled' : '' ?>><i class="bi bi-send-check me-1"></i>ยืนยันการชำระเงิน</button>
                    <p class="small text-secondary text-center mt-2 mb-0">ผู้ดูแลตรวจสอบสลิปแล้วสินค้าจะปรากฏใน <a href="<?= e(url('library')) ?>">คลังของฉัน</a></p>
                </div>
            </form>
        </div>
    </div>
</div>
<?php if ($promptPay !== ''): ?>
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js" integrity="sha384-8FWZA6BGMXhsfO+BLtrJK0We6gg5o1JyO8xQm6peWDEUs17ACA5ziE/NIAkl9z2k" crossorigin="anonymous"></script>
<?php endif; ?>
