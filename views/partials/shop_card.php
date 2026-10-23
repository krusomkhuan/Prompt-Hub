<?php
/** การ์ดสินค้า Digital Shop: $p (แถวจาก ShopRepository), $isFavorite (bool) */
use PromptHub\Repositories\ShopRepository;
use PromptHub\Services\ShopService;

$isFavorite = $isFavorite ?? false;
$typeIcon = ['ebook' => 'book', 'prompt' => 'stars', 'code' => 'code-slash'][$p['product_type']] ?? 'bag';
$compare = (float) ($p['compare_price'] ?? 0);
?>
<article class="card shop-card h-100">
    <a href="<?= e(url('shop.item', ['id' => $p['id']])) ?>" class="shop-cover-link" tabindex="-1" aria-hidden="true">
        <?php if ($p['cover_file']): ?>
            <img class="shop-cover" src="<?= e(url('shop.cover', ['id' => $p['id'], 'v' => substr((string) $p['cover_file'], 0, 8)])) ?>" alt="" loading="lazy">
        <?php else: ?>
            <span class="shop-cover shop-cover-empty"><i class="bi bi-<?= e($typeIcon) ?>"></i></span>
        <?php endif; ?>
    </a>
    <div class="card-body d-flex flex-column">
        <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
            <a class="badge rounded-pill shop-type text-decoration-none" href="<?= e(url('shop', ['type' => $p['product_type']])) ?>"><?= e(ShopRepository::TYPES[$p['product_type']] ?? $p['product_type']) ?></a>
            <?php if ($auth->check()): ?>
                <form method="post" action="<?= e(url('shop.favorite')) ?>" class="shop-fav-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                    <button type="submit" class="shop-fav<?= $isFavorite ? ' is-on' : '' ?>" aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>" title="รายการโปรด">
                        <i class="bi bi-heart<?= $isFavorite ? '-fill' : '' ?>"></i><span class="visually-hidden">รายการโปรด</span>
                    </button>
                </form>
            <?php else: ?>
                <a class="shop-fav" href="<?= e(url('login')) ?>" title="เข้าสู่ระบบเพื่อบันทึกรายการโปรด"><i class="bi bi-heart"></i><span class="visually-hidden">รายการโปรด</span></a>
            <?php endif; ?>
        </div>
        <h3 class="h6 card-title mb-1"><a class="stretched-link-title" href="<?= e(url('shop.item', ['id' => $p['id']])) ?>"><?= e($p['title']) ?></a></h3>
        <?php if ($p['summary'] !== ''): ?><p class="card-text text-secondary small shop-summary mb-2"><?= e($p['summary']) ?></p><?php endif; ?>
        <div class="small text-secondary mb-2 d-flex gap-3">
            <span><i class="bi bi-eye me-1"></i><?= number_th((int) $p['view_count']) ?></span>
            <?php if ((int) ($p['rating_count'] ?? 0) > 0): ?>
                <span class="shop-rating"><i class="bi bi-star-fill me-1"></i><?= e(number_format((float) $p['rating_avg'], 1)) ?> (<?= (int) $p['rating_count'] ?>)</span>
            <?php endif; ?>
        </div>
        <div class="mt-auto">
            <span class="shop-price"><?= e(ShopService::priceText((float) $p['price'])) ?></span>
            <?php if ($compare > (float) $p['price']): ?><del class="shop-compare ms-1"><?= e(ShopService::priceText($compare)) ?></del><?php endif; ?>
        </div>
    </div>
</article>
