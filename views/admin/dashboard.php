<div class="container py-4">
    <?= $view->partial('partials/admin_nav') ?>
    <h1 class="h3 mb-3">ภาพรวมหลังบ้าน</h1>
    <div class="row g-3 mb-4">
        <?php foreach ([
            ['สมาชิกทั้งหมด', $users['total'] ?? 0, 'people', 'primary', url('admin.users'), 'ระงับ ' . (int) ($users['suspended'] ?? 0) . ' · ผู้ดูแล ' . (int) ($users['admins'] ?? 0)],
            ['Prompt ทั้งหมด', $prompts['total'], 'collection', 'info', url('admin.prompts'), 'เผยแพร่ ' . $prompts['published']],
            ['หมวดหมู่', $categoryCount, 'grid', 'success', url('admin.categories'), 'จัดการลำดับและสถานะ'],
            ['รอตรวจสอบ', $prompts['pending'], 'hourglass-split', 'warning', url('admin.prompts', ['status' => 'pending']), 'ไม่อนุมัติ ' . $prompts['rejected']],
        ] as [$label, $n, $icon, $color, $href, $sub]): ?>
            <div class="col-6 col-lg-3">
                <a class="card metric-card text-decoration-none h-100" href="<?= e($href) ?>">
                    <div class="card-body">
                        <i class="bi bi-<?= e($icon) ?> text-<?= e($color) ?> fs-4"></i>
                        <div class="metric-value"><?= number_th((int) $n) ?></div>
                        <div class="fw-semibold small text-body"><?= e($label) ?></div>
                        <div class="small text-secondary"><?= e($sub) ?></div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h5 mb-0">รายการรอตรวจสอบ</h2>
                <a href="<?= e(url('admin.prompts', ['status' => 'pending'])) ?>">ดูทั้งหมด</a>
            </div>
            <?php if (!$pending): ?>
                <?= $view->partial('partials/empty', ['icon' => 'check2-all', 'heading' => 'ไม่มีรายการรอตรวจสอบ']) ?>
            <?php else: ?>
                <div class="list-group">
                    <?php foreach ($pending as $p): ?>
                        <a class="list-group-item list-group-item-action" href="<?= e(url('prompt', ['id' => $p['id']])) ?>">
                            <div class="fw-semibold"><?= e($p['title']) ?></div>
                            <div class="small text-secondary"><?= e($p['author_name']) ?> · <?= e($p['category_name']) ?> · <?= e(thai_date($p['updated_at'])) ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="col-lg-5">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h5 mb-0">กิจกรรมล่าสุด</h2>
                <a href="<?= e(url('admin.logs')) ?>">ดูทั้งหมด</a>
            </div>
            <?php if (!$logs): ?>
                <?= $view->partial('partials/empty', ['icon' => 'journal', 'heading' => 'ยังไม่มีบันทึกกิจกรรม']) ?>
            <?php else: ?>
                <ul class="list-group small">
                    <?php foreach ($logs as $l): ?>
                        <li class="list-group-item">
                            <strong><?= e(\PromptHub\Repositories\ActivityLogRepository::ACTIONS[$l['action']] ?? $l['action']) ?></strong>
                            <span class="text-secondary">โดย <?= e($l['admin_username']) ?></span>
                            <div class="text-secondary text-truncate"><?= e($l['details']) ?></div>
                            <div class="text-secondary"><?= e(thai_date($l['created_at'])) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
