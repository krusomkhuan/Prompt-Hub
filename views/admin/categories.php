<?php $e = $edit; ?>
<div class="container py-4">
    <?= $view->partial('partials/admin_nav') ?>
    <h1 class="h3 mb-3">จัดการหมวดหมู่</h1>
    <div class="row g-4">
        <div class="col-lg-4">
            <form class="card card-body" method="post" action="<?= e(url('admin.category.save')) ?>">
                <?= csrf_field() ?>
                <h2 class="h5"><?= $e ? 'แก้ไขหมวดหมู่' : 'เพิ่มหมวดหมู่' ?></h2>
                <?php if ($e): ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><?php endif; ?>
                <label class="form-label" for="name">ชื่อหมวดหมู่</label>
                <input class="form-control mb-2" id="name" name="name" required minlength="2" maxlength="100" value="<?= e($e['name'] ?? '') ?>">
                <label class="form-label" for="description">คำอธิบาย</label>
                <input class="form-control mb-2" id="description" name="description" maxlength="255" value="<?= e($e['description'] ?? '') ?>">
                <div class="row g-2">
                    <div class="col-7">
                        <label class="form-label" for="icon">ไอคอน (Bootstrap Icons)</label>
                        <input class="form-control" id="icon" name="icon" maxlength="50" pattern="[a-z0-9-]+" value="<?= e($e['icon'] ?? 'folder') ?>">
                    </div>
                    <div class="col-5">
                        <label class="form-label" for="sort_order">ลำดับแสดง</label>
                        <input class="form-control" id="sort_order" name="sort_order" type="number" min="-100000" max="100000" value="<?= (int) ($e['sort_order'] ?? 0) ?>">
                    </div>
                </div>
                <div class="form-check form-switch mt-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"<?= !$e || (int) $e['is_active'] ? ' checked' : '' ?>>
                    <label class="form-check-label" for="is_active">เปิดใช้งาน (ปิดแล้วจะไม่แสดงในตัวเลือกเพิ่ม Prompt ใหม่ แต่ Prompt เดิมยังแสดงได้)</label>
                </div>
                <div class="form-check form-switch mt-2">
                    <input class="form-check-input" type="checkbox" role="switch" id="allow_images" name="allow_images" value="1"<?= $e && (int) $e['allow_images'] ? ' checked' : '' ?>>
                    <label class="form-check-label" for="allow_images">รับภาพตัวอย่าง (สำหรับหมวดที่เกี่ยวกับการสร้างภาพ สมาชิกแนบภาพผลลัพธ์ได้ไม่เกิน 4 ภาพ)</label>
                </div>
                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>บันทึก</button>
                    <?php if ($e): ?><a class="btn btn-outline-secondary" href="<?= e(url('admin.categories')) ?>">ยกเลิก</a><?php endif; ?>
                </div>
            </form>
        </div>
        <div class="col-lg-8">
            <?php if (!$rows): ?>
                <?= $view->partial('partials/empty', ['icon' => 'grid', 'heading' => 'ยังไม่มีหมวดหมู่']) ?>
            <?php else: ?>
                <div class="table-responsive card">
                    <table class="table align-middle mb-0">
                        <thead class="table-light"><tr><th>ลำดับ</th><th>หมวดหมู่</th><th>Prompt</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $c): $count = (int) $c['prompt_count']; ?>
                            <tr>
                                <td><?= (int) $c['sort_order'] ?></td>
                                <td><i class="bi bi-<?= e(icon_name($c['icon'])) ?> text-primary me-1"></i><span class="fw-semibold"><?= e($c['name']) ?></span><div class="small text-secondary"><?= e($c['description']) ?></div></td>
                                <td class="small"><?= $count ?> <span class="text-secondary">(เผยแพร่ <?= (int) $c['public_count'] ?>)</span></td>
                                <td><span class="badge <?= (int) $c['is_active'] ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= (int) $c['is_active'] ? 'เปิด' : 'ปิด' ?></span><?php if ((int) $c['allow_images']): ?> <span class="badge text-bg-info" title="รับภาพตัวอย่าง"><i class="bi bi-image"></i> ภาพ</span><?php endif; ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin.categories', ['edit' => $c['id']])) ?>"><i class="bi bi-pencil"></i><span class="visually-hidden">แก้ไข</span></a>
                                    <form method="post" action="<?= e(url('admin.category.delete')) ?>" class="d-inline-flex gap-1 align-items-center mt-1"
                                          data-confirm="ลบหมวดหมู่ “<?= e($c['name']) ?>”?<?= $count ? ' Prompt ' . $count . ' รายการจะถูกย้ายไปหมวดหมู่ที่เลือก' : '' ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                        <?php if ($count > 0): ?>
                                            <select class="form-select form-select-sm" name="move_to" required aria-label="ย้าย Prompt ไปหมวดหมู่">
                                                <option value="">ย้ายไป…</option>
                                                <?php foreach ($rows as $t): if ((int) $t['id'] === (int) $c['id']) { continue; } ?>
                                                    <option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-danger text-nowrap" type="submit"><i class="bi bi-trash"></i><?= $count ? ' ย้ายและลบ' : '' ?><span class="visually-hidden">ลบ</span></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="small text-secondary mt-2">หมวดหมู่ที่มี Prompt ต้องเลือกหมวดหมู่ปลายทางเพื่อย้าย Prompt ก่อนลบ</p>
            <?php endif; ?>
        </div>
    </div>
</div>
