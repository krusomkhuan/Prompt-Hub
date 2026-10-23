<div class="container py-4">
    <?= $view->partial('partials/admin_nav') ?>
    <h1 class="h3 mb-3">จัดการแท็ก</h1>
    <div class="row g-4">
        <div class="col-lg-4">
            <form class="card card-body" method="post" action="<?= e(url('admin.tag.save')) ?>">
                <?= csrf_field() ?>
                <h2 class="h5"><?= $edit ? 'แก้ไขแท็ก' : 'เพิ่มแท็ก' ?></h2>
                <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
                <label class="form-label" for="name">ชื่อแท็ก</label>
                <input class="form-control" id="name" name="name" required maxlength="40" value="<?= e($edit['name'] ?? '') ?>">
                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>บันทึก</button>
                    <?php if ($edit): ?><a class="btn btn-outline-secondary" href="<?= e(url('admin.tags')) ?>">ยกเลิก</a><?php endif; ?>
                </div>
                <p class="small text-secondary mt-3 mb-0">สมาชิกเพิ่มแท็กใหม่ได้เองขณะบันทึก Prompt ผู้ดูแลใช้หน้านี้แก้ชื่อหรือลบแท็กที่ไม่เหมาะสม</p>
            </form>
        </div>
        <div class="col-lg-8">
            <form class="d-flex gap-2 mb-3" method="get" action="<?= e($app->baseUrl() . '/index.php') ?>">
                <input type="hidden" name="r" value="admin.tags">
                <input class="form-control" type="search" name="q" maxlength="40" value="<?= e($q) ?>" placeholder="ค้นหาแท็ก" aria-label="ค้นหาแท็ก">
                <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search"></i></button>
            </form>
            <?php if (!$rows): ?>
                <?= $view->partial('partials/empty', ['icon' => 'tags', 'heading' => 'ไม่พบแท็ก']) ?>
            <?php else: ?>
                <div class="table-responsive card">
                    <table class="table align-middle mb-0">
                        <thead class="table-light"><tr><th>แท็ก</th><th>ใช้ใน Prompt</th><th class="text-end">จัดการ</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $t): ?>
                            <tr>
                                <td>#<?= e($t['name']) ?></td>
                                <td><?= (int) $t['prompt_count'] ?></td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin.tags', ['edit' => $t['id']])) ?>"><i class="bi bi-pencil"></i><span class="visually-hidden">แก้ไข</span></a>
                                    <form class="d-inline" method="post" action="<?= e(url('admin.tag.delete')) ?>" data-confirm="ลบแท็ก “<?= e($t['name']) ?>”? แท็กจะถูกนำออกจาก Prompt <?= (int) $t['prompt_count'] ?> รายการ">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i><span class="visually-hidden">ลบ</span></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
