<?php
$isEdit = $prompt !== null;
$visibility = (string) old($old, 'visibility', 'private');
$selectedTools = array_map('strval', (array) old($old, 'ai_tools', []));
$catId = (string) old($old, 'category_id', '');
?>
<div class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="<?= e(url('my.prompts')) ?>">Prompt ของฉัน</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= $isEdit ? 'แก้ไข' : 'เพิ่มใหม่' ?></li>
        </ol>
    </nav>
    <h1 class="h3 mb-3"><?= $isEdit ? 'แก้ไข Prompt' : 'เพิ่ม Prompt ใหม่' ?></h1>

    <?php if ($isEdit): ?>
        <div class="alert alert-light border small">
            สถานะปัจจุบัน: <?= status_badge($prompt['status']) ?> · <?= e(visibility_label($prompt['visibility'])) ?>
            <?php if ($prompt['status'] === 'published' && !$isAdmin): ?>
                <div class="text-warning-emphasis mt-1"><i class="bi bi-exclamation-triangle me-1"></i>Prompt นี้เผยแพร่อยู่ เมื่อบันทึกการแก้ไข จะถูกพักการเผยแพร่และรอผู้ดูแลตรวจสอบใหม่</div>
            <?php endif; ?>
            <?php if ($prompt['status'] === 'rejected' && $prompt['rejection_reason']): ?>
                <div class="text-danger mt-1"><i class="bi bi-chat-left-text me-1"></i>เหตุผลที่ไม่อนุมัติ: <span class="preserve-lines"><?= e($prompt['rejection_reason']) ?></span></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
            <strong><i class="bi bi-exclamation-octagon me-1"></i>บันทึกไม่สำเร็จ</strong> กรุณาแก้ไขข้อมูลที่ไม่ถูกต้อง
            <ul class="mb-0 small mt-1"><?php foreach ($errors as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('my.prompt.save')) ?>" class="card card-body" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $prompt['id'] ?>"><?php endif; ?>
        <div class="row g-3">
            <div class="col-lg-8">
                <label class="form-label" for="title">ชื่อ Prompt <span class="text-danger">*</span></label>
                <input class="form-control<?= invalid($errors, 'title') ?>" id="title" name="title" required minlength="3" maxlength="200" value="<?= e(old($old, 'title')) ?>">
                <?= field_error($errors, 'title') ?>
            </div>
            <div class="col-lg-4">
                <label class="form-label" for="category_id">หมวดหมู่หลัก <span class="text-danger">*</span></label>
                <select class="form-select<?= invalid($errors, 'category_id') ?>" id="category_id" name="category_id" required>
                    <option value="">— เลือกหมวดหมู่ —</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" data-images="<?= (int) $c['allow_images'] ?>"<?= $catId === (string) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?><?= (int) $c['allow_images'] ? ' · รับภาพ' : '' ?><?= (int) $c['is_active'] ? '' : ' (ปิดใช้งาน)' ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'category_id') ?>
            </div>
            <div class="col-12">
                <label class="form-label" for="description">คำอธิบายสั้น <span class="text-danger">*</span></label>
                <textarea class="form-control<?= invalid($errors, 'description') ?>" id="description" name="description" rows="2" required minlength="10" maxlength="500"><?= e(old($old, 'description')) ?></textarea>
                <?= field_error($errors, 'description') ?>
            </div>
            <div class="col-12">
                <label class="form-label" for="content">เนื้อหา Prompt <span class="text-danger">*</span></label>
                <textarea class="form-control font-prompt<?= invalid($errors, 'content') ?>" id="content" name="content" rows="10" required minlength="10" maxlength="20000" data-counter="contentCounter"><?= e(old($old, 'content')) ?></textarea>
                <div class="d-flex justify-content-between form-text">
                    <span>ใส่ตัวแปรในวงเล็บเหลี่ยม เช่น [ชื่อสินค้า] [กลุ่มเป้าหมาย] [น้ำเสียง] ผู้ใช้จะได้ช่องกรอกค่าในหน้ารายละเอียด</span>
                    <span id="contentCounter" class="text-nowrap ms-2"></span>
                </div>
                <?= field_error($errors, 'content') ?>
            </div>
            <div class="col-12">
                <label class="form-label" for="content_th">คำแปลภาษาไทย <span class="text-secondary small">(ไม่บังคับ)</span></label>
                <textarea class="form-control<?= invalid($errors, 'content_th') ?>" id="content_th" name="content_th" rows="5" maxlength="20000" data-counter="contentThCounter"><?= e(old($old, 'content_th')) ?></textarea>
                <div class="d-flex justify-content-between form-text">
                    <span>แสดงใต้เนื้อหา Prompt (ต้นฉบับ) เพื่อช่วยให้เข้าใจความหมาย และค้นหาด้วยคำภาษาไทยได้ — ปุ่มคัดลอกยังคัดลอกต้นฉบับเหมือนเดิม</span>
                    <span id="contentThCounter" class="text-nowrap ms-2"></span>
                </div>
                <?= field_error($errors, 'content_th') ?>
            </div>
            <div class="col-12" id="imageSection">
                <div class="image-upload card card-body">
                    <span class="form-label d-block mb-1"><i class="bi bi-images me-1"></i>ภาพตัวอย่างผลลัพธ์</span>
                    <?php if ($images): ?>
                        <div class="row g-2 mb-2">
                            <?php foreach ($images as $img): ?>
                                <div class="col-6 col-md-3">
                                    <div class="image-thumb">
                                        <img src="<?= e(url('media', ['id' => $img['id']])) ?>" alt="ภาพตัวอย่าง <?= (int) $img['id'] ?>" loading="lazy">
                                        <?php if (!empty($img['drive_file_id'])): ?><span class="badge text-bg-success drive-badge" title="มีสำเนาบน Google Drive"><i class="bi bi-google"></i> Drive</span><?php endif; ?>
                                        <div class="form-check small mt-1">
                                            <input class="form-check-input" type="checkbox" name="remove_images[]" value="<?= (int) $img['id'] ?>" id="rm<?= (int) $img['id'] ?>">
                                            <label class="form-check-label text-danger" for="rm<?= (int) $img['id'] ?>">ลบภาพนี้</label>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div id="imageInputWrap">
                        <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int) \PromptHub\Services\ImageService::MAX_BYTES ?>">
                        <input class="form-control<?= invalid($errors, 'images') ?>" type="file" id="images" name="images[]" accept="image/jpeg,image/png,image/webp" multiple data-resize-max="<?= (int) \PromptHub\Services\ImageResizer::PROMPT_MAX_SIDE ?>">
                        <div class="form-text text-info" data-resize-status aria-live="polite"></div>
                        <div class="form-text">ภาพที่ได้จากการสร้างด้วย AI · JPEG, PNG หรือ WebP ไม่เกิน 5 MB ต่อภาพ รวมไม่เกิน <?= (int) \PromptHub\Services\ImageService::MAX_IMAGES ?> ภาพ · ระบบย่อภาพให้ด้านยาวไม่เกิน <?= (int) \PromptHub\Services\ImageResizer::PROMPT_MAX_SIDE ?> px และลบข้อมูลแฝงในไฟล์ (เช่น ตำแหน่ง GPS) ให้อัตโนมัติ</div>
                        <div class="row g-2 mt-1" id="imagePreview"></div>
                    </div>
                    <div class="form-text text-warning d-none" id="imageNotAllowed"><i class="bi bi-info-circle me-1"></i>หมวดหมู่ที่เลือกไม่รับภาพตัวอย่าง เลือกหมวดหมู่ที่เกี่ยวกับภาพ (เช่น การสร้างภาพ) เพื่อแนบภาพ</div>
                    <?= field_error($errors, 'images') ?>
                </div>
            </div>
            <div class="col-lg-6">
                <label class="form-label" for="tags">แท็ก</label>
                <input class="form-control<?= invalid($errors, 'tags') ?>" id="tags" name="tags" maxlength="500" value="<?= e(old($old, 'tags')) ?>" placeholder="คั่นด้วยจุลภาค เช่น การตลาด, โซเชียลมีเดีย">
                <div class="form-text">ไม่เกิน 10 แท็ก แต่ละแท็กไม่เกิน 40 ตัวอักษร</div>
                <?= field_error($errors, 'tags') ?>
            </div>
            <div class="col-lg-6">
                <span class="form-label d-block">เครื่องมือ AI ที่เหมาะสม</span>
                <div class="tool-options<?= invalid($errors, 'ai_tools') ?>">
                    <?php foreach ($tools as $t): ?>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="ai_tools[]" id="tool<?= (int) $t['id'] ?>" value="<?= (int) $t['id'] ?>"<?= in_array((string) $t['id'], $selectedTools, true) ? ' checked' : '' ?>>
                            <label class="form-check-label" for="tool<?= (int) $t['id'] ?>"><?= e($t['name']) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?= field_error($errors, 'ai_tools') ?>
            </div>
            <div class="col-12">
                <fieldset>
                    <legend class="form-label fs-6">การมองเห็น</legend>
                    <div class="visibility-options">
                        <input class="btn-check" type="radio" name="visibility" id="visPrivate" value="private"<?= $visibility !== 'public' ? ' checked' : '' ?>>
                        <label class="btn btn-outline-secondary" for="visPrivate"><i class="bi bi-lock me-1"></i>ส่วนตัว <small class="d-block fw-normal">เห็นเฉพาะคุณ</small></label>
                        <input class="btn-check" type="radio" name="visibility" id="visPublic" value="public"<?= $visibility === 'public' ? ' checked' : '' ?>>
                        <label class="btn btn-outline-primary" for="visPublic"><i class="bi bi-globe me-1"></i>สาธารณะ <small class="d-block fw-normal">ต้องผ่านการตรวจก่อนเผยแพร่</small></label>
                    </div>
                    <?= field_error($errors, 'visibility') ?>
                </fieldset>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-4 pt-3 border-top">
            <button class="btn btn-outline-primary" type="submit" name="intent" value="draft"><i class="bi bi-save me-1"></i><span>บันทึกฉบับร่าง</span></button>
            <button class="btn btn-primary" type="submit" name="intent" value="submit" id="submitReview"><i class="bi bi-send me-1"></i><span>ส่งตรวจเพื่อเผยแพร่</span></button>
            <a class="btn btn-link ms-auto" href="<?= e($isEdit ? url('prompt', ['id' => $prompt['id']]) : url('my.prompts')) ?>">ยกเลิก</a>
        </div>
        <p class="small text-secondary mb-0 mt-2" id="visibilityHint"></p>
    </form>
</div>
