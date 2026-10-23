<?php
/** หลังบ้าน Digital Shop: รายการสินค้า + สรุปยอด */
use PromptHub\Repositories\ShopRepository;
use PromptHub\Services\ShopService;

$keep = ['type' => $filters['type'], 'q' => $filters['q'], 'status' => $filters['status']];
?>
<div class="container py-4">
    <?= $view->partial('partials/admin_nav') ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">Digital Shop — สินค้า</h1>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="<?= e(url('shop')) ?>" target="_blank" rel="noopener"><i class="bi bi-shop me-1"></i>ดูหน้าร้าน</a>
            <a class="btn btn-primary" href="<?= e(url('admin.shop.form')) ?>"><i class="bi bi-plus-lg me-1"></i>เพิ่มสินค้า</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <?php foreach ([
            ['สินค้าทั้งหมด', number_th($stats['products']), 'bag', null],
            ['รอตรวจสอบการชำระเงิน', number_th($stats['pending']), 'hourglass-split', url('admin.orders', ['status' => 'pending'])],
            ['คำสั่งซื้อสำเร็จ', number_th($stats['paid']), 'check2-circle', url('admin.orders', ['status' => 'paid'])],
            ['ยอดรับเงิน', ShopService::priceText($stats['revenue']) === 'ฟรี' ? '฿0' : ShopService::priceText($stats['revenue']), 'cash-coin', null],
        ] as [$label, $value, $icon, $link]): ?>
            <div class="col-6 col-lg-3">
                <div class="card h-100"><div class="card-body">
                    <div class="small text-secondary"><i class="bi bi-<?= e($icon) ?> me-1"></i><?= e($label) ?></div>
                    <div class="fs-3 fw-bold"><?= $link ? '<a class="text-body text-decoration-none" href="' . e($link) . '">' . e($value) . '</a>' : e($value) ?></div>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>

    <form class="row g-2 mb-3" method="get" action="<?= e(url('admin.shop')) ?>" role="search">
        <input type="hidden" name="r" value="admin.shop">
        <div class="col-md-5"><input class="form-control" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="ค้นหาชื่อสินค้า" aria-label="ค้นหาชื่อสินค้า"></div>
        <div class="col-6 col-md-3">
            <select class="form-select" name="type" aria-label="ประเภท">
                <option value="">ทุกประเภท</option>
                <?php foreach (ShopRepository::TYPES as $k => $label): ?><option value="<?= e($k) ?>"<?= $filters['type'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <select class="form-select" name="status" aria-label="สถานะ">
                <option value="">ทุกสถานะ</option>
                <option value="active"<?= $filters['status'] === 'active' ? ' selected' : '' ?>>เปิดขาย</option>
                <option value="hidden"<?= $filters['status'] === 'hidden' ? ' selected' : '' ?>>ปิดการขาย</option>
            </select>
        </div>
        <div class="col-md-2 d-grid"><button class="btn btn-outline-primary" type="submit"><i class="bi bi-funnel me-1"></i>กรอง</button></div>
    </form>

    <?php if (!$rows): ?>
        <?= $view->partial('partials/empty', ['icon' => 'bag', 'heading' => 'ยังไม่มีสินค้า', 'actionUrl' => url('admin.shop.form'), 'actionLabel' => 'เพิ่มสินค้าแรก']) ?>
    <?php else: ?>
        <div class="table-responsive card">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>สินค้า</th><th>ประเภท</th><th class="text-end">ราคา</th><th class="text-end">ผู้ได้รับ</th><th class="text-end">ยอดเงิน</th><th class="text-end">เข้าชม</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $p): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <?php if ($p['cover_file']): ?><img class="shop-thumb shop-thumb-sm" src="<?= e(url('shop.cover', ['id' => $p['id'], 'v' => substr((string) $p['cover_file'], 0, 8)])) ?>" alt=""><?php endif; ?>
                                <a class="fw-semibold" href="<?= e(url('shop.item', ['id' => $p['id']])) ?>"><?= e(str_limit($p['title'], 60)) ?></a>
                            </div>
                        </td>
                        <td><span class="badge rounded-pill shop-type"><?= e(ShopRepository::TYPES[$p['product_type']]) ?></span></td>
                        <td class="text-end text-nowrap"><?= e(ShopService::priceText((float) $p['price'])) ?></td>
                        <td class="text-end"><?= number_th((int) $p['paid_count']) ?></td>
                        <td class="text-end text-nowrap">฿<?= e(number_format((float) $p['revenue'], 2)) ?></td>
                        <td class="text-end"><?= number_th((int) $p['view_count']) ?></td>
                        <td><span class="badge <?= (int) $p['is_active'] ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= (int) $p['is_active'] ? 'เปิดขาย' : 'ปิด' ?></span></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin.shop.form', ['id' => $p['id']])) ?>"><i class="bi bi-pencil"></i><span class="visually-hidden">แก้ไข</span></a>
                            <form class="d-inline" method="post" action="<?= e(url('admin.shop.toggle')) ?>">
                                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" type="submit" title="<?= (int) $p['is_active'] ? 'ปิดการขาย' : 'เปิดขาย' ?>"><i class="bi bi-<?= (int) $p['is_active'] ? 'eye-slash' : 'eye' ?>"></i><span class="visually-hidden"><?= (int) $p['is_active'] ? 'ปิดการขาย' : 'เปิดขาย' ?></span></button>
                            </form>
                            <form class="d-inline" method="post" action="<?= e(url('admin.shop.delete')) ?>" data-confirm="ลบสินค้า “<?= e($p['title']) ?>”? ไฟล์ คำสั่งซื้อ <?= (int) $p['paid_count'] ?> รายการ และรีวิวของสินค้านี้จะถูกลบด้วย ผู้ที่ได้รับแล้วจะเปิดไม่ได้อีก (หากต้องการเลิกขายเฉย ๆ ให้ปิดการขายแทน)">
                                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i><span class="visually-hidden">ลบ</span></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $view->partial('partials/pagination', ['pager' => $pager, 'routeName' => 'admin.shop', 'params' => $keep]) ?>
    <?php endif; ?>
</div>
