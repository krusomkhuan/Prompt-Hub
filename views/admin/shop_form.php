<?php
/** หลังบ้าน Digital Shop: เพิ่ม/แก้ไขสินค้า */
use PromptHub\Repositories\ShopRepository;

$p = $product;
$val = static fn(string $k, $d = '') => (string) ($old[$k] ?? $d);
$active = $old ? !empty($old['is_active']) : true;
$mb = static fn($bytes) => number_format((int) $bytes / 1048576, 1) . ' MB';
?>
<div class="container py-4">
    <?= $view->partial('partials/admin_nav') ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0"><?= $p ? 'แก้ไขสินค้า' : 'เพิ่มสินค้า' ?></h1>
        <div class="d-flex gap-2">
            <?php if ($p): ?><a class="btn btn-outline-secondary" href="<?= e(url('shop.item', ['id' => $p['id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-eye me-1"></i>ดูหน้าสินค้า</a><?php endif; ?>
            <a class="btn btn-outline-secondary" href="<?= e(url('admin.shop')) ?>"><i class="bi bi-arrow-left me-1"></i>รายการสินค้า</a>
        </div>
    </div>
    <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-octagon me-1"></i>กรุณาแก้ไขข้อมูลที่ไม่ถูกต้อง (ไฟล์ที่เลือกไว้ต้องเลือกใหม่)</div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('admin.shop.save')) ?>" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <?php if ($p): ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><?php endif; ?>
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card card-body mb-4">
                    <h2 class="h5">ข้อมูลสินค้า</h2>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label" for="title">ชื่อสินค้า *</label>
                            <input class="form-control<?= invalid($errors, 'title') ?>" id="title" name="title" maxlength="200" required value="<?= e($val('title')) ?>">
                            <?= field_error($errors, 'title') ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="product_type">ประเภท *</label>
                            <select class="form-select<?= invalid($errors, 'product_type') ?>" id="product_type" name="product_type">
                                <?php foreach (ShopRepository::TYPES as $k => $label): ?><option value="<?= e($k) ?>"<?= $val('product_type', 'prompt') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="summary">คำอธิบายสั้น (แสดงบนการ์ดสินค้า)</label>
                            <input class="form-control<?= invalid($errors, 'summary') ?>" id="summary" name="summary" maxlength="300" value="<?= e($val('summary')) ?>">
                            <?= field_error($errors, 'summary') ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="description">รายละเอียดสินค้า</label>
                            <textarea class="form-control<?= invalid($errors, 'description') ?>" id="description" name="description" rows="5" maxlength="20000"><?= e($val('description')) ?></textarea>
                            <?= field_error($errors, 'description') ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="sample_text">ตัวอย่างเนื้อหา / ตัวอย่าง prompt <span class="text-secondary small">(ทุกคนเห็น)</span></label>
                            <textarea class="form-control font-monospace<?= invalid($errors, 'sample_text') ?>" id="sample_text" name="sample_text" rows="4" maxlength="5000"><?= e($val('sample_text')) ?></textarea>
                            <?= field_error($errors, 'sample_text') ?>
                        </div>
                    </div>
                </div>

                <div class="card card-body mb-4">
                    <h2 class="h5 mb-1">สิ่งที่ผู้ซื้อได้รับ</h2>
                    <p class="small text-secondary">ใส่อย่างน้อย 1 อย่าง — เปิดได้เฉพาะผู้ที่รับฟรีหรือชำระเงินแล้ว (ที่ “คลังของฉัน”)</p>
                    <label class="form-label" for="full_content">เนื้อหาเต็ม / Prompt ฉบับเต็ม / โค้ด <span class="text-secondary small">(ปุ่ม “โค้ด” ในคลัง — เปิดดูและคัดลอก)</span></label>
                    <textarea class="form-control font-monospace<?= invalid($errors, 'full_content') ?>" id="full_content" name="full_content" rows="10" maxlength="500000"><?= e($val('full_content')) ?></textarea>
                    <?= field_error($errors, 'full_content') ?>

                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label" for="file">ไฟล์ดาวน์โหลด <span class="text-secondary small">(PDF, EPUB, ZIP, DOCX, PPTX, XLSX, TXT, MD)</span></label>
                            <input class="form-control<?= invalid($errors, 'file') ?>" type="file" id="file" name="file" accept=".pdf,.epub,.zip,.docx,.pptx,.xlsx,.txt,.md">
                            <?= field_error($errors, 'file') ?>
                            <?php if (!empty($p['file_name'])): ?>
                                <div class="small mt-1"><i class="bi bi-paperclip me-1"></i><?= e($p['file_original']) ?> · <?= e($mb($p['file_size'])) ?>
                                    <div class="form-check form-check-inline ms-2"><input class="form-check-input" type="checkbox" id="remove_file" name="remove_file" value="1"><label class="form-check-label text-danger" for="remove_file">ลบไฟล์</label></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="code_file">ไฟล์โค้ด (.zip)</label>
                            <input class="form-control<?= invalid($errors, 'code_file') ?>" type="file" id="code_file" name="code_file" accept=".zip">
                            <?= field_error($errors, 'code_file') ?>
                            <?php if (!empty($p['code_name'])): ?>
                                <div class="small mt-1"><i class="bi bi-file-earmark-zip me-1"></i><?= e($p['code_original']) ?> · <?= e($mb($p['code_size'])) ?>
                                    <div class="form-check form-check-inline ms-2"><input class="form-check-input" type="checkbox" id="remove_code" name="remove_code" value="1"><label class="form-check-label text-danger" for="remove_code">ลบไฟล์</label></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="external_url">ลิงก์รับสินค้า <span class="text-secondary small">(เช่น Google Drive, Canva — เห็นเฉพาะผู้ที่ได้รับสินค้า)</span></label>
                            <input class="form-control<?= invalid($errors, 'external_url') ?>" type="url" id="external_url" name="external_url" maxlength="500" value="<?= e($val('external_url')) ?>" placeholder="https://">
                            <?= field_error($errors, 'external_url') ?>
                        </div>
                    </div>
                    <p class="small text-secondary mt-2 mb-0">ไฟล์ละไม่เกิน 100 MB และไม่เกินค่า upload_max_filesize ของเซิร์ฟเวอร์ (<?= e((string) ini_get('upload_max_filesize')) ?>)</p>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card card-body mb-4">
                    <h2 class="h5">ราคา</h2>
                    <label class="form-label" for="price">ราคาขาย (บาท) <span class="text-secondary small">0 = ฟรี</span></label>
                    <input class="form-control<?= invalid($errors, 'price') ?>" id="price" name="price" inputmode="decimal" value="<?= e($val('price', '0')) ?>">
                    <?= field_error($errors, 'price') ?>
                    <label class="form-label mt-2" for="compare_price">ราคาปกติ (แสดงขีดฆ่า)</label>
                    <input class="form-control<?= invalid($errors, 'compare_price') ?>" id="compare_price" name="compare_price" inputmode="decimal" value="<?= e($val('compare_price')) ?>" placeholder="เช่น 199">
                    <?= field_error($errors, 'compare_price') ?>
                </div>

                <div class="card card-body mb-4">
                    <h2 class="h5">ภาพปก</h2>
                    <?php if (!empty($p['cover_file'])): ?>
                        <img class="shop-cover rounded mb-2" src="<?= e(url('shop.cover', ['id' => $p['id'], 'v' => substr((string) $p['cover_file'], 0, 8)])) ?>" alt="ภาพปกปัจจุบัน">
                        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="remove_cover" name="remove_cover" value="1"><label class="form-check-label text-danger" for="remove_cover">ลบภาพปก</label></div>
                    <?php endif; ?>
                    <label class="form-label" for="cover"><?= !empty($p['cover_file']) ? 'เปลี่ยนภาพปก' : 'เลือกภาพปก' ?> <span class="text-secondary small">(JPEG/PNG/WebP ≤ 5 MB แนะนำ 16:9)</span></label>
                    <input class="form-control<?= invalid($errors, 'cover') ?>" type="file" id="cover" name="cover" accept="image/jpeg,image/png,image/webp" data-resize-max="1600">
                    <div class="small text-secondary" data-resize-status></div>
                    <?= field_error($errors, 'cover') ?>
                </div>

                <div class="card card-body mb-4">
                    <h2 class="h5">การแสดงผล</h2>
                    <label class="form-label" for="sort_order">ลำดับแนะนำ <span class="text-secondary small">(มาก = แสดงก่อน)</span></label>
                    <input class="form-control<?= invalid($errors, 'sort_order') ?>" type="number" id="sort_order" name="sort_order" min="-100000" max="100000" value="<?= e($val('sort_order', '0')) ?>">
                    <?= field_error($errors, 'sort_order') ?>
                    <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"<?= $active ? ' checked' : '' ?>>
                        <label class="form-check-label" for="is_active">เปิดขายที่หน้าร้าน</label>
                    </div>
                </div>

                <button class="btn btn-primary btn-lg w-100" type="submit"><i class="bi bi-save me-1"></i>บันทึกสินค้า</button>
            </div>
        </div>
    </form>
</div>
