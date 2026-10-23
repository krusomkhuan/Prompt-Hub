<?php
/** Digital Shop — รายละเอียดสินค้า: ปก รายละเอียด ตัวอย่าง รีวิว และกล่องรับสินค้า/ชำระเงิน */
use PromptHub\Repositories\ShopRepository;
use PromptHub\Services\ShopService;

$p = $product;
$price = (float) $p['price'];
$compare = (float) ($p['compare_price'] ?? 0);
$rating = (float) ($p['rating_avg'] ?? 0);
$ratingCount = (int) ($p['rating_count'] ?? 0);
$gives = ShopService::deliverables($p);
$typeLong = ['ebook' => 'eBook', 'prompt' => 'ชุด Prompt', 'code' => 'ชุดโค้ด'][$p['product_type']] ?? '';
$stars = static function (float $r): string {
    $html = '<span class="shop-stars" aria-hidden="true">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= '<i class="bi bi-star' . ($r >= $i ? '-fill' : ($r >= $i - 0.5 ? '-half' : '')) . '"></i>';
    }
    return $html . '</span>';
};
?>
<div class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="<?= e(url('home')) ?>">หน้าแรก</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('shop')) ?>">Digital Shop</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= e(str_limit($p['title'], 40)) ?></li>
        </ol>
    </nav>

    <?php if (!(int) $p['is_active']): ?>
        <div class="alert alert-warning small"><i class="bi bi-eye-slash me-1"></i>สินค้านี้ปิดการขายแล้ว<?= $owned ? ' — คุณยังเปิดใช้งานสินค้าที่ได้รับแล้วได้ตามปกติ' : '' ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <span class="badge rounded-pill shop-type mb-2"><?= e($typeLong) ?></span>
            <h1 class="h3 mb-1"><?= e($p['title']) ?></h1>
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div class="small text-secondary d-flex flex-wrap align-items-center gap-3">
                    <span><?= $stars($rating) ?> <strong class="text-body"><?= e(number_format($rating, 1)) ?></strong> (<?= $ratingCount ?>)</span>
                    <span><i class="bi bi-eye me-1"></i><?= number_th((int) $p['view_count']) ?> ครั้ง</span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-share-url="<?= e($shareUrl) ?>" data-share-title="<?= e($p['title']) ?>">
                    <i class="bi bi-share me-1"></i><span>แชร์</span>
                </button>
            </div>

            <div class="shop-hero mb-4">
                <?php if ($p['cover_file']): ?>
                    <img src="<?= e(url('shop.cover', ['id' => $p['id'], 'v' => substr((string) $p['cover_file'], 0, 8)])) ?>" alt="ภาพปก <?= e($p['title']) ?>">
                <?php else: ?>
                    <span class="shop-cover-empty"><i class="bi bi-bag"></i></span>
                <?php endif; ?>
            </div>

            <?php if ($p['summary'] !== ''): ?><p class="lead fs-6"><?= e($p['summary']) ?></p><?php endif; ?>
            <?php if (trim((string) $p['description']) !== ''): ?>
                <h2 class="h5 mt-4">รายละเอียด</h2>
                <div class="preserve-lines text-secondary"><?= e($p['description']) ?></div>
            <?php endif; ?>

            <?php if (trim((string) $p['sample_text']) !== ''): ?>
                <h2 class="h5 mt-4">ตัวอย่าง<?= $p['product_type'] === 'ebook' ? 'เนื้อหา' : ($p['product_type'] === 'code' ? 'โค้ด' : ' prompt') ?></h2>
                <pre class="prompt-source shop-sample"><?= e($p['sample_text']) ?></pre>
            <?php endif; ?>

            <h2 class="h5 mt-4">สิ่งที่จะได้รับ</h2>
            <ul class="list-unstyled shop-gives">
                <?php if ($gives['content']): ?><li><i class="bi bi-file-earmark-code me-2"></i><?= $p['product_type'] === 'prompt' ? 'Prompt ฉบับเต็ม' : 'เนื้อหาเต็ม/โค้ด' ?> (เปิดดูและคัดลอกได้ในคลังของฉัน)</li><?php endif; ?>
                <?php if ($gives['file']): ?><li><i class="bi bi-file-earmark-arrow-down me-2"></i>ไฟล์ดาวน์โหลด <?= e(strtoupper(pathinfo((string) $p['file_original'], PATHINFO_EXTENSION))) ?> · <?= e(number_format($p['file_size'] / 1048576, 1)) ?> MB</li><?php endif; ?>
                <?php if ($gives['code']): ?><li><i class="bi bi-file-earmark-zip me-2"></i>ไฟล์โค้ด (.zip) · <?= e(number_format($p['code_size'] / 1048576, 1)) ?> MB</li><?php endif; ?>
                <?php if ($gives['link']): ?><li><i class="bi bi-link-45deg me-2"></i>ลิงก์รับสินค้าเพิ่มเติม</li><?php endif; ?>
            </ul>

            <h2 class="h5 mt-4" id="reviews">รีวิวจากผู้ใช้ (<?= $ratingCount ?>)</h2>
            <?php if ($owned): ?>
                <form method="post" action="<?= e(url('shop.review')) ?>" class="card card-body mb-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                    <fieldset class="mb-2">
                        <legend class="form-label fs-6 mb-1"><?= $myReview ? 'แก้ไขรีวิวของคุณ' : 'ให้คะแนนสินค้านี้' ?></legend>
                        <div class="star-input">
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                <input type="radio" class="btn-check" name="rating" id="rate<?= $i ?>" value="<?= $i ?>"<?= (int) ($myReview['rating'] ?? 5) === $i ? ' checked' : '' ?> required>
                                <label for="rate<?= $i ?>" title="<?= $i ?> ดาว"><i class="bi bi-star-fill"></i><span class="visually-hidden"><?= $i ?> ดาว</span></label>
                            <?php endfor; ?>
                        </div>
                    </fieldset>
                    <label class="visually-hidden" for="comment">ความคิดเห็น</label>
                    <textarea class="form-control mb-2" id="comment" name="comment" rows="2" maxlength="1000" placeholder="เล่าประสบการณ์การใช้งาน (ไม่บังคับ)"><?= e($myReview['comment'] ?? '') ?></textarea>
                    <div><button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-send me-1"></i>บันทึกรีวิว</button></div>
                </form>
            <?php endif; ?>
            <?php if (!$reviews): ?>
                <p class="text-secondary small">ยังไม่มีรีวิว<?= $owned ? '' : ' — รีวิวได้หลังจากได้รับสินค้า' ?></p>
            <?php else: ?>
                <div class="d-flex flex-column gap-2">
                    <?php foreach ($reviews as $r): ?>
                        <div class="card card-body py-2">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                                <span class="d-inline-flex align-items-center gap-2 small"><?= avatar((int) $r['user_id'], $r['display_name'], $r['avatar_file'], 'sm') ?><strong><?= e($r['display_name']) ?></strong> <?= $stars((float) $r['rating']) ?></span>
                                <span class="small text-secondary"><?= e(thai_date($r['updated_at'], false)) ?>
                                    <?php if ($auth->isAdmin()): ?>
                                        <form method="post" action="<?= e(url('shop.review')) ?>" class="d-inline" data-confirm="ลบรีวิวของ “<?= e($r['display_name']) ?>”?">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                            <input type="hidden" name="review_id" value="<?= (int) $r['id'] ?>">
                                            <input type="hidden" name="op" value="delete">
                                            <button class="btn btn-link btn-sm text-danger p-0 ms-2" type="submit"><i class="bi bi-trash"></i><span class="visually-hidden">ลบรีวิว</span></button>
                                        </form>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php if ($r['comment'] !== ''): ?><div class="small mt-1 preserve-lines"><?= e($r['comment']) ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <aside class="col-lg-4">
            <div class="card shop-buy sticky-lg-top">
                <div class="card-body p-4">
                    <div class="mb-3">
                        <span class="shop-price fs-2"><?= e(ShopService::priceText($price)) ?></span>
                        <?php if ($compare > $price): ?><del class="shop-compare ms-2"><?= e(ShopService::priceText($compare)) ?></del><?php endif; ?>
                    </div>

                    <?php if ($owned): ?>
                        <a class="btn btn-primary w-100 btn-lg" href="<?= e(url('library')) ?>"><i class="bi bi-box-seam me-1"></i>เปิดในคลังของฉัน</a>
                        <p class="small text-success text-center mt-2 mb-0"><i class="bi bi-check-circle me-1"></i>คุณได้รับสินค้านี้แล้ว</p>
                    <?php elseif (!(int) $p['is_active']): ?>
                        <button class="btn btn-secondary w-100" type="button" disabled>ปิดการขายแล้ว</button>
                    <?php elseif (!$auth->check()): ?>
                        <a class="btn btn-primary w-100 btn-lg" href="<?= e(url('login')) ?>"><i class="bi bi-box-arrow-in-right me-1"></i><?= $price > 0 ? 'เข้าสู่ระบบเพื่อซื้อ' : 'เข้าสู่ระบบเพื่อรับฟรี' ?></a>
                        <p class="small text-secondary text-center mt-2 mb-0">ยังไม่เป็นสมาชิก? <a href="<?= e(url('register')) ?>">สมัครฟรี</a></p>
                    <?php elseif ($price <= 0): ?>
                        <form method="post" action="<?= e(url('shop.claim')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <button class="btn btn-primary w-100 btn-lg" type="submit">รับฟรี</button>
                        </form>
                        <p class="small text-secondary text-center mt-2 mb-0">ดาวน์โหลดได้ทันทีในคลังของฉัน</p>
                    <?php elseif ($order && $order['status'] === 'pending'): ?>
                        <button class="btn btn-warning w-100" type="button" disabled><i class="bi bi-hourglass-split me-1"></i>รอตรวจสอบการชำระเงิน</button>
                        <p class="small text-secondary text-center mt-2 mb-0">เลขที่ <?= e($order['order_no']) ?> · <a href="<?= e(url('library', ['tab' => 'orders'])) ?>">ดูคำสั่งซื้อ</a></p>
                    <?php else: ?>
                        <a class="btn btn-primary w-100 btn-lg" href="<?= e(url('shop.checkout', ['id' => $p['id']])) ?>"><i class="bi bi-cart-check me-1"></i>ซื้อเลย</a>
                        <?php if ($order && $order['status'] === 'rejected'): ?>
                            <p class="small text-danger mt-2 mb-0"><i class="bi bi-x-circle me-1"></i>คำสั่งซื้อก่อนหน้า (<?= e($order['order_no']) ?>) ไม่ผ่านการตรวจสอบ<?= $order['admin_note'] !== '' ? ': ' . e($order['admin_note']) : '' ?> — แจ้งชำระเงินใหม่ได้</p>
                        <?php else: ?>
                            <p class="small text-secondary text-center mt-2 mb-0">ดาวน์โหลดได้ทันทีหลังชำระเงินได้รับการยืนยัน</p>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($auth->check() && (int) $p['is_active']): ?>
                        <form method="post" action="<?= e(url('shop.favorite')) ?>" class="mt-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <button class="btn btn-sm w-100 <?= $isFavorite ? 'btn-danger' : 'btn-outline-danger' ?>" type="submit">
                                <i class="bi bi-heart<?= $isFavorite ? '-fill' : '' ?> me-1"></i><?= $isFavorite ? 'อยู่ในรายการโปรด' : 'บันทึกเป็นรายการโปรด' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if ($auth->isAdmin()): ?>
                        <a class="btn btn-sm btn-outline-secondary w-100 mt-2" href="<?= e(url('admin.shop.form', ['id' => $p['id']])) ?>"><i class="bi bi-pencil me-1"></i>แก้ไขสินค้า (ผู้ดูแล)</a>
                    <?php endif; ?>
                </div>
            </div>
        </aside>
    </div>
</div>
