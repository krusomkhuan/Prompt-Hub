<?php
/** คลังของฉัน: สินค้าดิจิทัลที่ได้รับแล้ว (เปิดโค้ด/ดาวน์โหลด) · คำสั่งซื้อ · รายการโปรด */
use PromptHub\Repositories\ShopRepository;
use PromptHub\Services\ShopService;

$tabs = [
    'products' => ['สินค้าดิจิทัล', 'box-seam', count($items)],
    'orders' => ['คำสั่งซื้อ', 'receipt', $pendingOrders],
    'favorites' => ['รายการโปรด', 'heart', count($favorites)],
];
$thumb = static function (array $p): string {
    if (!empty($p['cover_file'])) {
        return '<img class="shop-thumb" src="' . e(url('shop.cover', ['id' => $p['id'] ?? $p['product_id'], 'v' => substr((string) $p['cover_file'], 0, 8)])) . '" alt="" loading="lazy">';
    }
    return '<span class="shop-thumb shop-cover-empty"><i class="bi bi-bag"></i></span>';
};
?>
<div class="container py-4">
    <h1 class="h2 mb-3">คลังของฉัน</h1>
    <ul class="nav shop-tabs mb-4">
        <?php foreach ($tabs as $k => [$label, $icon, $count]): ?>
            <li class="nav-item">
                <a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="<?= e(url('library', ['tab' => $k !== 'products' ? $k : null])) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>>
                    <i class="bi bi-<?= e($icon) ?> me-1"></i><?= e($label) ?>
                    <?php if ($count > 0): ?><span class="badge rounded-pill ms-1<?= $k === 'orders' ? ' text-bg-warning' : ' shop-count' ?>"><?= (int) $count ?></span><?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($tab === 'products'): ?>
        <?php if (!$items): ?>
            <?= $view->partial('partials/empty', ['icon' => 'box-seam', 'heading' => 'ยังไม่มีสินค้าในคลัง', 'text' => 'สินค้าที่รับฟรีหรือชำระเงินแล้วจะแสดงที่นี่', 'actionUrl' => url('shop'), 'actionLabel' => 'ไปที่ Digital Shop']) ?>
        <?php else: ?>
            <div class="d-flex flex-column gap-3">
                <?php foreach ($items as $p): $g = ShopService::deliverables($p); ?>
                    <div class="card library-item">
                        <div class="card-body d-flex flex-wrap align-items-center gap-3">
                            <a href="<?= e(url('shop.item', ['id' => $p['id']])) ?>" tabindex="-1" aria-hidden="true"><?= $thumb($p) ?></a>
                            <div class="flex-grow-1 min-w-0">
                                <a class="fw-semibold text-body text-decoration-none" href="<?= e(url('shop.item', ['id' => $p['id']])) ?>"><?= e($p['title']) ?></a>
                                <div class="mt-1"><span class="badge rounded-pill shop-type"><?= e(ShopRepository::TYPES[$p['product_type']] ?? '') ?></span></div>
                            </div>
                            <div class="d-flex flex-wrap gap-2 ms-auto">
                                <?php if ($g['file']): ?>
                                    <a class="btn btn-primary btn-sm" href="<?= e(url('shop.download', ['id' => $p['id']])) ?>"><i class="bi bi-download me-1"></i>ดาวน์โหลด</a>
                                <?php endif; ?>
                                <?php if ($g['code']): ?>
                                    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('shop.download', ['id' => $p['id'], 'k' => 'code'])) ?>"><i class="bi bi-file-earmark-zip me-1"></i>ไฟล์โค้ด (.zip)</a>
                                <?php endif; ?>
                                <?php if ($g['content']): ?>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#content<?= (int) $p['id'] ?>"><i class="bi bi-file-earmark-code me-1"></i>โค้ด</button>
                                <?php endif; ?>
                                <?php if ($g['link']): ?>
                                    <a class="btn btn-outline-secondary btn-sm" href="<?= e($p['external_url']) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right me-1"></i>ลิงก์</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php if ($g['content']): ?>
                        <div class="modal fade" id="content<?= (int) $p['id'] ?>" tabindex="-1" aria-labelledby="contentTitle<?= (int) $p['id'] ?>" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h2 class="modal-title fs-5" id="contentTitle<?= (int) $p['id'] ?>"><?= e($p['title']) ?></h2>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                                    </div>
                                    <div class="modal-body">
                                        <pre class="prompt-source mb-0" id="contentText<?= (int) $p['id'] ?>"><?= e($p['full_content']) ?></pre>
                                    </div>
                                    <div class="modal-footer">
                                        <span class="small me-auto" role="status" aria-live="polite" data-copy-status></span>
                                        <button type="button" class="btn btn-primary" data-copy-target="#contentText<?= (int) $p['id'] ?>"><i class="bi bi-clipboard me-1"></i>คัดลอกทั้งหมด</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php elseif ($tab === 'orders'): ?>
        <?php if (!$orders): ?>
            <?= $view->partial('partials/empty', ['icon' => 'receipt', 'heading' => 'ยังไม่มีคำสั่งซื้อ', 'actionUrl' => url('shop'), 'actionLabel' => 'ไปที่ Digital Shop']) ?>
        <?php else: ?>
            <div class="table-responsive card">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>เลขที่</th><th>สินค้า</th><th class="text-end">ยอด</th><th>สถานะ</th><th>วันที่</th></tr></thead>
                    <tbody>
                    <?php foreach ($orders as $o): ?>
                        <tr>
                            <td class="text-nowrap small"><?= e($o['order_no']) ?></td>
                            <td><a href="<?= e(url('shop.item', ['id' => $o['product_id']])) ?>"><?= e($o['product_title']) ?></a>
                                <?php if ($o['status'] === 'rejected' && $o['admin_note'] !== ''): ?><div class="small text-danger"><?= e($o['admin_note']) ?></div><?php endif; ?>
                                <?php if ($o['slip_file']): ?><div class="small"><a href="<?= e(url('shop.slip', ['id' => $o['id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-image me-1"></i>สลิป</a></div><?php endif; ?>
                            </td>
                            <td class="text-end"><?= e(ShopService::priceText((float) $o['amount'])) ?></td>
                            <td><?= shop_order_badge($o['status']) ?></td>
                            <td class="small text-nowrap"><?= e(thai_date($o['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <?php if (!$favorites): ?>
            <?= $view->partial('partials/empty', ['icon' => 'heart', 'heading' => 'ยังไม่มีรายการโปรด', 'text' => 'กดรูปหัวใจที่สินค้าเพื่อบันทึกไว้ดูภายหลัง', 'actionUrl' => url('shop'), 'actionLabel' => 'ไปที่ Digital Shop']) ?>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($favorites as $p): ?>
                    <div class="col-sm-6 col-lg-4 col-xl-3"><?= $view->partial('partials/shop_card', ['p' => $p, 'isFavorite' => true]) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
