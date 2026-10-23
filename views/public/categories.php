<div class="container py-4">
    <h1 class="h3 mb-1">หมวดหมู่</h1>
    <p class="text-secondary mb-4">เลือกหมวดหมู่เพื่อดู Prompt ที่เผยแพร่ในหมวดนั้น</p>
    <?php if (!$categories): ?>
        <?= $view->partial('partials/empty', ['icon' => 'grid', 'heading' => 'ยังไม่มีหมวดหมู่ที่เปิดใช้งาน']) ?>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($categories as $c): ?>
                <div class="col-sm-6 col-lg-4">
                    <a class="card category-card h-100 text-decoration-none" href="<?= e(url('category', ['id' => $c['id']])) ?>">
                        <div class="card-body d-flex gap-3">
                            <span class="category-icon category-icon-lg"><i class="bi bi-<?= e(icon_name($c['icon'])) ?>"></i></span>
                            <span>
                                <span class="d-block h5 mb-1 text-body"><?= e($c['name']) ?></span>
                                <span class="d-block small text-secondary mb-2"><?= e($c['description']) ?></span>
                                <span class="badge rounded-pill category-pill"><?= number_th((int) $c['public_count']) ?> Prompt</span>
                            </span>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
