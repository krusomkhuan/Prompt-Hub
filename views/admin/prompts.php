<?php
$keep = ['q' => $filters['q'], 'status' => $filters['status'], 'visibility' => $filters['visibility'], 'category' => $filters['category'] ?: null];
$tabs = ['' => 'ทั้งหมด', 'pending' => 'รอตรวจสอบ', 'published' => 'เผยแพร่', 'rejected' => 'ไม่อนุมัติ', 'draft' => 'ฉบับร่าง'];
?>
<div class="container py-4">
    <?= $view->partial('partials/admin_nav') ?>
    <h1 class="h3 mb-3">จัดการ Prompt</h1>
    <ul class="nav nav-tabs flex-nowrap overflow-auto mb-3">
        <?php foreach ($tabs as $k => $label): ?>
            <li class="nav-item"><a class="nav-link text-nowrap<?= $filters['status'] === $k ? ' active' : '' ?>" href="<?= e(url('admin.prompts', ['status' => $k])) ?>"><?= e($label) ?></a></li>
        <?php endforeach; ?>
    </ul>
    <form class="row g-2 mb-3" method="get" action="<?= e($app->baseUrl() . '/index.php') ?>">
        <input type="hidden" name="r" value="admin.prompts">
        <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
        <div class="col-md-5"><input class="form-control" type="search" name="q" maxlength="100" value="<?= e($filters['q']) ?>" placeholder="ค้นหาชื่อ เนื้อหา หรือแท็ก" aria-label="ค้นหา"></div>
        <div class="col-6 col-md-3">
            <select class="form-select" name="category" aria-label="หมวดหมู่">
                <option value="">ทุกหมวดหมู่</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"<?= (int) $filters['category'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <select class="form-select" name="visibility" aria-label="การมองเห็น">
                <option value="">ทุกการมองเห็น</option>
                <option value="public"<?= $filters['visibility'] === 'public' ? ' selected' : '' ?>>สาธารณะ</option>
                <option value="private"<?= $filters['visibility'] === 'private' ? ' selected' : '' ?>>ส่วนตัว</option>
            </select>
        </div>
        <div class="col-md-1 d-grid"><button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button></div>
    </form>
    <p class="small text-secondary">พบ <?= number_th($pager['total']) ?> รายการ</p>
    <?php if (!$rows): ?>
        <?= $view->partial('partials/empty', ['icon' => 'collection', 'heading' => 'ไม่พบ Prompt ตามเงื่อนไข']) ?>
    <?php else: ?>
        <div class="table-responsive card">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Prompt</th><th class="d-none d-md-table-cell">ผู้สร้าง</th><th>สถานะ</th><th class="d-none d-lg-table-cell">แก้ไขล่าสุด</th><th class="text-end">จัดการ</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $p): ?>
                    <tr>
                        <td><a class="fw-semibold" href="<?= e(url('prompt', ['id' => $p['id']])) ?>"><?= e($p['title']) ?></a><div class="small text-secondary"><?= e($p['category_name']) ?></div></td>
                        <td class="d-none d-md-table-cell small"><?= e($p['author_name']) ?></td>
                        <td><?= status_badge($p['status']) ?> <span class="badge text-bg-light border"><?= e(visibility_label($p['visibility'])) ?></span></td>
                        <td class="d-none d-lg-table-cell small text-secondary"><?= e(thai_date($p['updated_at'])) ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($p['status'] === 'pending' && $p['visibility'] === 'public'): ?>
                                <form class="d-inline" method="post" action="<?= e(url('admin.prompt.moderate')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                    <input type="hidden" name="decision" value="approve">
                                    <input type="hidden" name="back" value="list">
                                    <button class="btn btn-sm btn-success" type="submit"><i class="bi bi-check2"></i> อนุมัติ</button>
                                </form>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('prompt', ['id' => $p['id']])) ?>"><i class="bi bi-eye"></i> ตรวจ</a>
                            <form class="d-inline" method="post" action="<?= e(url('my.prompt.delete')) ?>" data-confirm="ลบ Prompt “<?= e($p['title']) ?>” ของ <?= e($p['author_name']) ?>? ลบแล้วกู้คืนไม่ได้">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <input type="hidden" name="back" value="admin">
                                <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i><span class="visually-hidden">ลบ</span></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $view->partial('partials/pagination', ['pager' => $pager, 'routeName' => 'admin.prompts', 'params' => $keep]) ?>
    <?php endif; ?>
</div>
