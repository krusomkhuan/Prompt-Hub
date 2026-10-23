<?php
/** Digital Shop — รายการสินค้า กรองตามประเภท ค้นหา และเรียงลำดับ */
use PromptHub\Repositories\ShopRepository;

$keep = ['q' => $filters['q'], 'sort' => $filters['sort'] !== 'latest' ? $filters['sort'] : null];
$sorts = ['latest' => 'แนะนำ / ล่าสุด', 'popular' => 'ยอดนิยม', 'price_asc' => 'ราคาต่ำ → สูง', 'price_desc' => 'ราคาสูง → ต่ำ'];
?>
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
        <div>
            <h1 class="h2 mb-1">Digital Shop</h1>
            <p class="text-secondary mb-0">eBook และชุด Prompt พร้อมใช้งาน</p>
        </div>
        <?php if ($auth->check()): ?>
            <a class="btn btn-outline-primary" href="<?= e(url('library')) ?>"><i class="bi bi-box-seam me-1"></i>คลังของฉัน</a>
        <?php endif; ?>
    </div>

    <?php if ($app->setting('shop_enabled', '1') !== '1'): ?>
        <div class="alert alert-warning small"><i class="bi bi-eye-slash me-1"></i>ร้านปิดอยู่ — หน้านี้เห็นเฉพาะผู้ดูแล (เปิดร้านที่ หลังบ้าน › คำสั่งซื้อ › ตั้งค่าร้าน)</div>
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div class="d-flex flex-wrap gap-2" role="tablist" aria-label="ประเภทสินค้า">
            <a class="btn btn-sm shop-pill<?= $filters['type'] === '' ? ' active' : '' ?>" href="<?= e(url('shop', $keep)) ?>"<?= $filters['type'] === '' ? ' aria-current="page"' : '' ?>>ทั้งหมด</a>
            <?php foreach (ShopRepository::TYPES as $k => $label): ?>
                <a class="btn btn-sm shop-pill<?= $filters['type'] === $k ? ' active' : '' ?>" href="<?= e(url('shop', ['type' => $k] + $keep)) ?>"<?= $filters['type'] === $k ? ' aria-current="page"' : '' ?>>
                    <?= e($label) ?> <span class="opacity-75">(<?= (int) ($typeCounts[$k] ?? 0) ?>)</span>
                </a>
            <?php endforeach; ?>
        </div>
        <form class="d-flex gap-2 shop-search" method="get" action="<?= e(url('shop')) ?>" role="search">
            <input type="hidden" name="r" value="shop">
            <?php if ($filters['type'] !== ''): ?><input type="hidden" name="type" value="<?= e($filters['type']) ?>"><?php endif; ?>
            <input class="form-control form-control-sm" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="ค้นหาสินค้า" aria-label="ค้นหาสินค้า" maxlength="100">
            <select class="form-select form-select-sm" name="sort" aria-label="เรียงลำดับ">
                <?php foreach ($sorts as $k => $label): ?>
                    <option value="<?= e($k) ?>"<?= $filters['sort'] === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-search"></i><span class="visually-hidden">ค้นหา</span></button>
        </form>
    </div>

    <?php if (!$rows): ?>
        <?= $view->partial('partials/empty', [
            'icon' => 'bag',
            'heading' => $filters['q'] !== '' ? 'ไม่พบสินค้าที่ตรงกับ “' . $filters['q'] . '”' : 'ยังไม่มีสินค้าในหมวดนี้',
            'text' => 'ลองเลือกประเภทอื่น หรือกลับมาใหม่เร็ว ๆ นี้',
        ]) ?>
    <?php else: ?>
        <div class="row g-3 g-lg-4">
            <?php foreach ($rows as $p): ?>
                <div class="col-sm-6 col-lg-4 col-xl-3">
                    <?= $view->partial('partials/shop_card', ['p' => $p, 'isFavorite' => in_array((int) $p['id'], $favoriteIds, true)]) ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?= $view->partial('partials/pagination', ['pager' => $pager, 'routeName' => 'shop', 'params' => ['type' => $filters['type']] + $keep]) ?>
    <?php endif; ?>
</div>
