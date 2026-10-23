<div class="container py-4">
    <?= $view->partial('partials/admin_nav') ?>
    <h1 class="h3 mb-3">จัดการเครื่องมือ AI</h1>
    <div class="row g-4">
        <div class="col-lg-4">
            <form class="card card-body" method="post" action="<?= e(url('admin.tool.save')) ?>">
                <?= csrf_field() ?>
                <h2 class="h5"><?= $edit ? 'แก้ไขเครื่องมือ' : 'เพิ่มเครื่องมือ' ?></h2>
                <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
                <label class="form-label" for="name">ชื่อเครื่องมือ</label>
                <input class="form-control mb-2" id="name" name="name" required maxlength="80" value="<?= e($edit['name'] ?? '') ?>">
                <label class="form-label" for="sort_order">ลำดับแสดง</label>
                <input class="form-control" id="sort_order" name="sort_order" type="number" min="-100000" max="100000" value="<?= (int) ($edit['sort_order'] ?? 0) ?>">
                <div class="form-check form-switch mt-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"<?= !$edit || (int) $edit['is_active'] ? ' checked' : '' ?>>
                    <label class="form-check-label" for="is_active">เปิดให้เลือกในแบบฟอร์ม</label>
                </div>
                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>บันทึก</button>
                    <?php if ($edit): ?><a class="btn btn-outline-secondary" href="<?= e(url('admin.tools')) ?>">ยกเลิก</a><?php endif; ?>
                </div>
            </form>
        </div>
        <div class="col-lg-8">
            <?php if (!$rows): ?>
                <?= $view->partial('partials/empty', ['icon' => 'cpu', 'heading' => 'ยังไม่มีเครื่องมือ AI']) ?>
            <?php else: ?>
                <div class="table-responsive card">
                    <table class="table align-middle mb-0">
                        <thead class="table-light"><tr><th>ลำดับ</th><th>เครื่องมือ</th><th>ใช้ใน Prompt</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $t): ?>
                            <tr>
                                <td><?= (int) $t['sort_order'] ?></td>
                                <td class="fw-semibold"><?= e($t['name']) ?></td>
                                <td><?= (int) $t['prompt_count'] ?></td>
                                <td><span class="badge <?= (int) $t['is_active'] ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= (int) $t['is_active'] ? 'เปิด' : 'ปิด' ?></span></td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin.tools', ['edit' => $t['id']])) ?>"><i class="bi bi-pencil"></i><span class="visually-hidden">แก้ไข</span></a>
                                    <form class="d-inline" method="post" action="<?= e(url('admin.tool.delete')) ?>" data-confirm="ลบเครื่องมือ “<?= e($t['name']) ?>”? จะถูกนำออกจาก Prompt <?= (int) $t['prompt_count'] ?> รายการ (หากต้องการซ่อนเฉย ๆ ให้ปิดใช้งานแทน)">
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
