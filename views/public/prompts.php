<?php
/** หน้ารวม Prompt / รายการตามหมวดหมู่ พร้อมค้นหา กรอง เรียง แบ่งหน้า */
$keep = [
    'id' => $category ? $category['id'] : null,
    'q' => $filters['q'],
    'category' => $category ? null : ($filters['category'] ?: null),
    'tag' => $filters['tag'] ?: null,
    'tool' => $filters['tool'] ?: null,
    'sort' => $filters['sort'] !== 'latest' ? $filters['sort'] : null,
    'per_page' => $perPage !== 12 ? $perPage : null,
];
$hasFilter = $filters['q'] !== '' || $filters['tag'] || $filters['tool'] || (!$category && $filters['category']);
$action = $app->baseUrl() . '/index.php';
?>
<div class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="<?= e(url('home')) ?>">หน้าแรก</a></li>
            <?php if ($category): ?>
                <li class="breadcrumb-item"><a href="<?= e(url('categories')) ?>">หมวดหมู่</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?= e($category['name']) ?></li>
            <?php else: ?>
                <li class="breadcrumb-item active" aria-current="page">สำรวจ Prompt</li>
            <?php endif; ?>
        </ol>
    </nav>

    <div class="page-head mb-4">
        <?php if ($category): ?>
            <div class="d-flex align-items-center gap-3">
                <span class="category-icon category-icon-lg"><i class="bi bi-<?= e(icon_name($category['icon'])) ?>"></i></span>
                <div>
                    <h1 class="h3 mb-1"><?= e($category['name']) ?></h1>
                    <p class="text-secondary mb-0"><?= e($category['description']) ?></p>
                    <?php if (!(int) $category['is_active']): ?><span class="badge text-bg-secondary mt-1">หมวดหมู่นี้ปิดรับ Prompt ใหม่แล้ว</span><?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <h1 class="h3 mb-1">สำรวจ Prompt</h1>
            <p class="text-secondary mb-0">ค้นหาจากชื่อ คำอธิบาย เนื้อหา และแท็ก แล้วกรองตามหมวดหมู่หรือเครื่องมือ AI</p>
        <?php endif; ?>
    </div>

    <form class="filter-bar card card-body mb-4" method="get" action="<?= e($action) ?>" role="search">
        <input type="hidden" name="r" value="<?= e($routeName) ?>">
        <?php if ($category): ?><input type="hidden" name="id" value="<?= (int) $category['id'] ?>"><?php endif; ?>
        <?php if ($filters['tag']): ?><input type="hidden" name="tag" value="<?= (int) $filters['tag'] ?>"><?php endif; ?>
        <div class="row g-2 align-items-end">
            <div class="col-lg-4">
                <label class="form-label small" for="q">คำค้นหา</label>
                <input class="form-control" id="q" type="search" name="q" maxlength="100" value="<?= e($filters['q']) ?>" placeholder="พิมพ์คำที่ต้องการ">
            </div>
            <?php if (!$category): ?>
                <div class="col-6 col-lg-2">
                    <label class="form-label small" for="category">หมวดหมู่</label>
                    <select class="form-select" id="category" name="category">
                        <option value="">ทั้งหมด</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"<?= (int) $filters['category'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-6 col-lg-2">
                <label class="form-label small" for="tool">เครื่องมือ AI</label>
                <select class="form-select" id="tool" name="tool">
                    <option value="">ทั้งหมด</option>
                    <?php foreach ($tools as $t): ?>
                        <option value="<?= (int) $t['id'] ?>"<?= (int) $filters['tool'] === (int) $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small" for="sort">เรียงตาม</label>
                <select class="form-select" id="sort" name="sort">
                    <option value="latest"<?= $filters['sort'] === 'latest' ? ' selected' : '' ?>>ล่าสุด</option>
                    <option value="copies"<?= $filters['sort'] === 'copies' ? ' selected' : '' ?>>คัดลอกมากที่สุด</option>
                </select>
            </div>
            <div class="col-6 col-lg-1">
                <label class="form-label small" for="per_page">ต่อหน้า</label>
                <select class="form-select" id="per_page" name="per_page">
                    <?php foreach (\PromptHub\Controllers\PromptController::PER_PAGE_OPTIONS as $n): ?>
                        <option value="<?= $n ?>"<?= $perPage === $n ? ' selected' : '' ?>><?= $n ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-1 d-grid">
                <button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i><span class="d-lg-none ms-1">กรอง</span></button>
            </div>
        </div>
        <?php if ($hasFilter): ?>
            <div class="d-flex flex-wrap gap-2 align-items-center mt-3 small">
                <span class="text-secondary">ตัวกรอง:</span>
                <?php if ($filters['q'] !== ''): ?><span class="badge text-bg-light border">คำค้น “<?= e($filters['q']) ?>”</span><?php endif; ?>
                <?php if ($tagName !== ''): ?><span class="badge text-bg-light border">แท็ก #<?= e($tagName) ?></span><?php endif; ?>
                <a class="ms-1" href="<?= e($category ? url('category', ['id' => $category['id']]) : url('prompts')) ?>"><i class="bi bi-x-circle me-1"></i>ล้างตัวกรอง</a>
            </div>
        <?php endif; ?>
    </form>

    <p class="text-secondary small mb-3" aria-live="polite">พบ <?= number_th($pager['total']) ?> รายการ</p>

    <?php if (!$rows): ?>
        <?php if ($hasFilter): ?>
            <?= $view->partial('partials/empty', [
                'icon' => 'search',
                'heading' => 'ไม่พบ Prompt ที่ตรงกับการค้นหา',
                'text' => 'ลองใช้คำค้นที่สั้นลง เปลี่ยนตัวกรอง หรือดู Prompt ทั้งหมด',
                'actionUrl' => $category ? url('category', ['id' => $category['id']]) : url('prompts'),
                'actionLabel' => 'ล้างตัวกรอง',
            ]) ?>
        <?php else: ?>
            <?= $view->partial('partials/empty', ['icon' => 'inbox', 'heading' => 'ยังไม่มี Prompt ที่เผยแพร่ในส่วนนี้', 'text' => 'กลับมาดูอีกครั้งเร็ว ๆ นี้']) ?>
        <?php endif; ?>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($rows as $p): ?>
                <div class="col-md-6 col-lg-4"><?= $view->partial('partials/prompt_card', ['p' => $p]) ?></div>
            <?php endforeach; ?>
        </div>
        <?= $view->partial('partials/pagination', ['pager' => $pager, 'routeName' => $routeName, 'params' => $keep]) ?>
    <?php endif; ?>
</div>
