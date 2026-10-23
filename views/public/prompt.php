<?php
/** รายละเอียด Prompt — เนื้อหาทุกส่วนถูก escape และแสดงเป็นข้อความ ไม่รัน HTML/JavaScript */
$p = $prompt;
$isPublic = \PromptHub\Services\PromptPolicy::isPublic($p);
?>
<div class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="<?= e(url('home')) ?>">หน้าแรก</a></li>
            <li class="breadcrumb-item"><a href="<?= e(url('category', ['id' => $p['category_id']])) ?>"><?= e($p['category_name']) ?></a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= e(str_limit($p['title'], 40)) ?></li>
        </ol>
    </nav>

    <?php if (!$isPublic): ?>
        <div class="alert alert-<?= $p['status'] === 'rejected' ? 'danger' : 'warning' ?>" role="status">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <i class="bi bi-eye-slash"></i>
                <strong>Prompt นี้ยังไม่แสดงต่อสาธารณะ</strong>
                <?= status_badge($p['status']) ?>
                <span class="badge text-bg-light border"><?= e(visibility_label($p['visibility'])) ?></span>
            </div>
            <div class="small mt-1">
                <?php if ($p['visibility'] === 'private'): ?>
                    เห็นได้เฉพาะเจ้าของและผู้ดูแลระบบ
                <?php elseif ($p['status'] === 'pending'): ?>
                    กำลังรอผู้ดูแลตรวจสอบก่อนเผยแพร่
                <?php elseif ($p['status'] === 'draft'): ?>
                    ฉบับร่าง กด “ส่งตรวจเพื่อเผยแพร่” ในหน้าแก้ไขเมื่อพร้อม
                <?php else: ?>
                    ผู้ดูแลไม่อนุมัติ แก้ไขตามเหตุผลแล้วส่งตรวจใหม่ได้
                <?php endif; ?>
            </div>
            <?php if ($p['status'] === 'rejected' && $p['rejection_reason']): ?>
                <div class="rejection-reason mt-2"><strong>เหตุผลจากผู้ดูแล:</strong> <span class="preserve-lines"><?= e($p['rejection_reason']) ?></span></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <article class="card detail-card">
                <div class="card-body p-md-4">
                    <a class="badge rounded-pill category-pill text-decoration-none mb-2" href="<?= e(url('category', ['id' => $p['category_id']])) ?>">
                        <i class="bi bi-<?= e(icon_name((string) $p['category_icon'])) ?> me-1"></i><?= e($p['category_name']) ?>
                    </a>
                    <h1 class="h3 mb-2"><?= e($p['title']) ?></h1>
                    <p class="lead fs-6 text-secondary"><?= e($p['description']) ?></p>
                    <div class="small text-secondary d-flex flex-wrap gap-3 mb-3">
                        <span class="d-inline-flex align-items-center gap-1"><?= avatar((int) $p['user_id'], $p['author_name'], $p['author_avatar'], 'sm') ?><?= e($p['author_name']) ?> <?= medal_badge((int) $authorPoints) ?></span>
                        <span><i class="bi bi-calendar3 me-1"></i>สร้าง <?= e(thai_date($p['created_at'])) ?></span>
                        <span><i class="bi bi-pencil me-1"></i>แก้ไข <?= e(thai_date($p['updated_at'])) ?></span>
                        <span><i class="bi bi-clipboard-check me-1"></i>คัดลอก <span id="copyCount"><?= number_th((int) $p['copy_count']) ?></span> ครั้ง</span>
                        <span><i class="bi bi-eye me-1"></i>เข้าชม <?= number_th((int) $p['view_count']) ?> ครั้ง</span>
                    </div>

                    <?php if (!empty($p['images'])): ?>
                        <h2 class="h6 text-uppercase text-secondary mt-4"><i class="bi bi-images me-1"></i>ภาพตัวอย่างผลลัพธ์</h2>
                        <div class="row g-2 prompt-gallery">
                            <?php foreach ($p['images'] as $i => $img): ?>
                                <div class="<?= count($p['images']) === 1 ? 'col-12' : 'col-6' ?>">
                                    <a href="<?= e(prompt_image_url((int) $img['id'], $img['drive_file_id'] ?? null)) ?>" target="_blank" rel="noopener noreferrer" class="d-block">
                                        <img <?= prompt_image_attrs((int) $img['id'], $img['drive_file_id'] ?? null) ?> alt="ภาพตัวอย่างผลลัพธ์ที่ <?= $i + 1 ?> ของ <?= e($p['title']) ?>" loading="lazy" width="<?= (int) $img['width'] ?>" height="<?= (int) $img['height'] ?>">
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="small text-secondary mt-1 mb-0">กดที่ภาพเพื่อดูขนาดเต็ม</p>
                    <?php endif; ?>

                    <h2 class="h6 text-uppercase text-secondary mt-4">เนื้อหา Prompt (ต้นฉบับ)</h2>
                    <?php if (!$auth->check()): ?>
                        <?php
                        // แสดงไม่เกิน 40% ของเนื้อหา (สูงสุด 120 ตัวอักษร) เพื่อไม่ให้ Prompt สั้นแสดงครบทั้งหมด
                        $previewLen = min(120, max(20, (int) floor(mb_strlen($p['content']) * 0.4)));
                        $preview = mb_substr($p['content'], 0, $previewLen);
                        ?>
                        <div class="prompt-locked">
                            <pre class="prompt-source prompt-preview"><?= e($preview) ?>…</pre>
                            <div class="locked-cta text-center">
                                <i class="bi bi-lock fs-3 text-primary"></i>
                                <p class="fw-semibold mb-1">สมาชิกเท่านั้นที่ดู Prompt ฉบับเต็ม เติมตัวแปร และคัดลอกไปใช้งานได้</p>
                                <p class="small text-secondary mb-3"><?= google_enabled() ? 'สมัครฟรี หรือเข้าสู่ระบบด้วยบัญชี Google' : 'สมัครสมาชิกฟรี ใช้เวลาไม่ถึงนาที' ?></p>
                                <div class="d-flex flex-wrap justify-content-center gap-2">
                                    <a class="btn btn-primary" href="<?= e(url('login')) ?>"><i class="bi bi-box-arrow-in-right me-1"></i>เข้าสู่ระบบ</a>
                                    <a class="btn btn-outline-primary" href="<?= e(url('register')) ?>"><i class="bi bi-person-plus me-1"></i>สมัครสมาชิก</a>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <pre class="prompt-source" id="promptSource"><?= e($p['content']) ?></pre>

                        <?php if (trim((string) ($p['content_th'] ?? '')) !== ''): ?>
                            <h2 class="h6 text-uppercase text-secondary mt-3" id="translationHeading"><i class="bi bi-translate me-1"></i>คำแปลภาษาไทย</h2>
                            <div class="example-box preserve-lines" id="promptTranslation" lang="th" aria-labelledby="translationHeading"><?= e($p['content_th']) ?></div>
                        <?php endif; ?>

                        <div id="variableBox" class="variable-box d-none" aria-labelledby="varHeading">
                            <h2 class="h6 mb-1" id="varHeading"><i class="bi bi-input-cursor-text me-1"></i>กรอกค่าตัวแปร</h2>
                            <p class="small text-secondary mb-3">ค่าที่กรอกใช้สร้างข้อความพร้อมคัดลอกด้านล่างเท่านั้น ไม่แก้ไขต้นฉบับ</p>
                            <div class="row g-2" id="variableFields"></div>
                            <button type="button" class="btn btn-link btn-sm px-0 mt-2" id="resetVariables"><i class="bi bi-arrow-counterclockwise me-1"></i>ล้างค่าที่กรอก</button>
                        </div>

                        <label class="form-label fw-semibold mt-3" for="promptOutput">ข้อความพร้อมคัดลอก</label>
                        <textarea class="form-control prompt-output" id="promptOutput" rows="8" readonly><?= e($p['content']) ?></textarea>
                        <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                            <button type="button" class="btn btn-primary btn-lg" id="copyButton"
                                    data-id="<?= (int) $p['id'] ?>" data-url="<?= e(url('prompt.copy')) ?>" data-csrf="<?= e(csrf_token()) ?>">
                                <i class="bi bi-clipboard me-1"></i><span>คัดลอก Prompt</span>
                            </button>
                            <span id="copyStatus" class="small" role="status" aria-live="polite"></span>
                        </div>
                    <?php endif; ?>

                    <?php if (trim((string) $p['usage_example']) !== ''): ?>
                        <h2 class="h6 mt-4"><i class="bi bi-lightbulb me-1 text-warning"></i>ตัวอย่างวิธีใช้งาน</h2>
                        <div class="example-box preserve-lines"><?= e($p['usage_example']) ?></div>
                    <?php endif; ?>
                    <?php if (trim((string) $p['output_example']) !== ''): ?>
                        <h2 class="h6 mt-4"><i class="bi bi-chat-square-text me-1 text-primary"></i>ตัวอย่างผลลัพธ์</h2>
                        <div class="example-box preserve-lines"><?= e($p['output_example']) ?></div>
                    <?php endif; ?>
                </div>
            </article>
        </div>

        <aside class="col-lg-4">
            <div class="card mb-3">
                <div class="card-body">
                    <?php if ($isPublic): ?>
                        <?php if ($auth->check()): ?>
                            <form method="post" action="<?= e(url('favorite.toggle')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <input type="hidden" name="op" value="<?= $isFavorite ? 'remove' : 'add' ?>">
                                <button class="btn w-100 <?= $isFavorite ? 'btn-danger' : 'btn-outline-danger' ?>" type="submit">
                                    <i class="bi bi-heart<?= $isFavorite ? '-fill' : '' ?> me-1"></i><?= $isFavorite ? 'อยู่ในรายการโปรด (กดเพื่อยกเลิก)' : 'บันทึกเป็นรายการโปรด' ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <a class="btn btn-outline-danger w-100" href="<?= e(url('login')) ?>"><i class="bi bi-heart me-1"></i>เข้าสู่ระบบเพื่อบันทึกรายการโปรด</a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($p['tags']): ?>
                        <h2 class="h6 mt-3">แท็ก</h2>
                        <div class="tag-list">
                            <?php foreach ($p['tags'] as $t): ?>
                                <a class="tag" href="<?= e(url('prompts', ['tag' => $t['id']])) ?>">#<?= e($t['name']) ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($p['tools']): ?>
                        <h2 class="h6 mt-3">เหมาะกับเครื่องมือ AI</h2>
                        <div class="d-flex flex-wrap gap-1">
                            <?php foreach ($p['tools'] as $t): ?>
                                <a class="badge text-bg-light border text-decoration-none" href="<?= e(url('prompts', ['tool' => $t['id']])) ?>"><i class="bi bi-cpu me-1"></i><?= e($t['name']) ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($canEdit): ?>
                <div class="card mb-3">
                    <div class="card-body">
                        <h2 class="h6"><?= $isOwner ? 'จัดการ Prompt ของคุณ' : 'จัดการ (ผู้ดูแล)' ?></h2>
                        <div class="small mb-2">สถานะ: <?= status_badge($p['status']) ?> · <?= e(visibility_label($p['visibility'])) ?></div>
                        <?php if ($isOwner && $p['status'] === 'published'): ?>
                            <p class="small text-secondary">หากแก้ไข Prompt ที่เผยแพร่แล้ว ระบบจะพักการเผยแพร่และส่งให้ผู้ดูแลตรวจสอบใหม่</p>
                        <?php endif; ?>
                        <div class="d-flex gap-2">
                            <a class="btn btn-outline-primary flex-fill" href="<?= e(url('my.prompt.form', ['id' => $p['id']])) ?>"><i class="bi bi-pencil me-1"></i>แก้ไข</a>
                            <form method="post" action="<?= e(url('my.prompt.delete')) ?>" class="flex-fill" data-confirm="ต้องการลบ Prompt “<?= e($p['title']) ?>” ใช่หรือไม่? การลบไม่สามารถย้อนกลับได้ และจะนำออกจากรายการโปรดของทุกคน">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <?php if (!$isOwner): ?><input type="hidden" name="back" value="admin"><?php endif; ?>
                                <button class="btn btn-outline-danger w-100" type="submit"><i class="bi bi-trash me-1"></i>ลบ</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($isAdmin && $p['visibility'] === 'public' && in_array($p['status'], ['pending', 'published'], true)): ?>
                <div class="card border-warning mb-3">
                    <div class="card-body">
                        <h2 class="h6"><i class="bi bi-clipboard2-check me-1"></i>ตรวจสอบการเผยแพร่</h2>
                        <?php if ($p['status'] === 'pending'): ?>
                            <form method="post" action="<?= e(url('admin.prompt.moderate')) ?>" class="mb-3">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <input type="hidden" name="decision" value="approve">
                                <button class="btn btn-success w-100" type="submit"><i class="bi bi-check2-circle me-1"></i>อนุมัติและเผยแพร่</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url('admin.prompt.moderate')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <input type="hidden" name="decision" value="reject">
                            <label class="form-label small" for="reason"><?= $p['status'] === 'published' ? 'ถอนการเผยแพร่ — ' : '' ?>เหตุผลที่ไม่อนุมัติ (แสดงให้เจ้าของเห็น)</label>
                            <textarea class="form-control mb-2" id="reason" name="reason" rows="3" minlength="5" maxlength="1000" required></textarea>
                            <button class="btn btn-outline-danger w-100" type="submit"><i class="bi bi-x-circle me-1"></i>ไม่อนุมัติ</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</div>
