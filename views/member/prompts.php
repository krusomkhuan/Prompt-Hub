<?php
$keep = ['q' => $filters['q'], 'status' => $filters['status'], 'visibility' => $filters['visibility']];
$tabs = ['' => 'ทั้งหมด', 'draft' => 'ฉบับร่าง', 'pending' => 'รอตรวจสอบ', 'published' => 'เผยแพร่', 'rejected' => 'ไม่อนุมัติ'];
?>
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">Prompt ของฉัน</h1>
        <a class="btn btn-primary" href="<?= e(url('my.prompt.form')) ?>"><i class="bi bi-plus-circle me-1"></i>เพิ่ม Prompt</a>
    </div>

    <ul class="nav nav-tabs flex-nowrap overflow-auto mb-3">
        <?php foreach ($tabs as $k => $label): ?>
            <li class="nav-item"><a class="nav-link text-nowrap<?= $filters['status'] === $k ? ' active' : '' ?>" href="<?= e(url('my.prompts', ['status' => $k, 'q' => $filters['q']])) ?>"><?= e($label) ?></a></li>
        <?php endforeach; ?>
    </ul>

    <form class="row g-2 mb-3" method="get" action="<?= e($app->baseUrl() . '/index.php') ?>">
        <input type="hidden" name="r" value="my.prompts">
        <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
        <div class="col-sm-6 col-lg-4"><input class="form-control" type="search" name="q" maxlength="100" value="<?= e($filters['q']) ?>" placeholder="ค้นหา Prompt ของฉัน" aria-label="ค้นหา"></div>
        <div class="col-sm-4 col-lg-3">
            <select class="form-select" name="visibility" aria-label="การมองเห็น">
                <option value="">ทุกการมองเห็น</option>
                <option value="private"<?= $filters['visibility'] === 'private' ? ' selected' : '' ?>>ส่วนตัว</option>
                <option value="public"<?= $filters['visibility'] === 'public' ? ' selected' : '' ?>>สาธารณะ</option>
            </select>
        </div>
        <div class="col-sm-2 col-lg-1 d-grid"><button class="btn btn-outline-primary" type="submit"><i class="bi bi-search"></i></button></div>
    </form>

    <?php if (!$rows): ?>
        <?= $view->partial('partials/empty', $filters['q'] !== '' || $filters['status'] !== '' || $filters['visibility'] !== ''
            ? ['icon' => 'search', 'heading' => 'ไม่พบ Prompt ตามเงื่อนไข', 'actionUrl' => url('my.prompts'), 'actionLabel' => 'ดูทั้งหมด']
            : ['icon' => 'journal-plus', 'heading' => 'คุณยังไม่มี Prompt', 'actionUrl' => url('my.prompt.form'), 'actionLabel' => 'เพิ่ม Prompt แรก']) ?>
    <?php else: ?>
        <div class="table-responsive card">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr><th>ชื่อ Prompt</th><th class="d-none d-md-table-cell">หมวดหมู่</th><th>สถานะ</th><th class="d-none d-lg-table-cell">แก้ไขล่าสุด</th><th class="text-end">จัดการ</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $p): ?>
                    <tr>
                        <td>
                            <a class="fw-semibold" href="<?= e(url('prompt', ['id' => $p['id']])) ?>"><?= e($p['title']) ?></a>
                            <?php if ($p['status'] === 'rejected' && $p['rejection_reason']): ?>
                                <div class="small text-danger"><i class="bi bi-chat-left-text me-1"></i>เหตุผล: <?= e(str_limit($p['rejection_reason'], 100)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="d-none d-md-table-cell small"><?= e($p['category_name']) ?></td>
                        <td><?= status_badge($p['status']) ?> <span class="badge text-bg-light border"><?= e(visibility_label($p['visibility'])) ?></span></td>
                        <td class="d-none d-lg-table-cell small text-secondary"><?= e(thai_date($p['updated_at'])) ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('my.prompt.form', ['id' => $p['id']])) ?>" title="แก้ไข"><i class="bi bi-pencil"></i><span class="visually-hidden">แก้ไข</span></a>
                            <form class="d-inline" method="post" action="<?= e(url('my.prompt.delete')) ?>" data-confirm="ต้องการลบ “<?= e($p['title']) ?>” ใช่หรือไม่? ลบแล้วกู้คืนไม่ได้">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit" title="ลบ"><i class="bi bi-trash"></i><span class="visually-hidden">ลบ</span></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $view->partial('partials/pagination', ['pager' => $pager, 'routeName' => 'my.prompts', 'params' => $keep]) ?>
    <?php endif; ?>
</div>
