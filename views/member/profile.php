<?php $user = $auth->user(); ?>
<div class="container py-4">
    <h1 class="h3 mb-3">โปรไฟล์</h1>
    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h5"><i class="bi bi-person-badge me-1"></i>ข้อมูลบัญชี</h2>
                    <div class="d-flex align-items-center gap-3 mb-3" id="avatarSection">
                        <span id="avatarPreviewWrap"><?= avatar((int) $user['id'], $user['display_name'], $user['avatar_file'], 'lg') ?></span>
                        <div class="flex-grow-1">
                            <form method="post" action="<?= e(url('profile.avatar')) ?>" enctype="multipart/form-data" novalidate>
                                <?= csrf_field() ?>
                                <input type="hidden" name="MAX_FILE_SIZE" value="<?= \PromptHub\Services\AvatarService::MAX_BYTES ?>">
                                <label class="form-label small mb-1" for="avatar">ภาพโปรไฟล์</label>
                                <input class="form-control form-control-sm<?= invalid($errors, 'avatar') ?>" type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/webp" required data-resize-max="<?= \PromptHub\Services\ImageResizer::AVATAR_SIZE ?>" data-resize-square>
                                <div class="form-text text-info" data-resize-status aria-live="polite"></div>
                                <?= field_error($errors, 'avatar') ?>
                                <div class="form-text">JPEG, PNG หรือ WebP ไม่เกิน 2 MB ระบบตัดกลางเป็นสี่เหลี่ยมจัตุรัส 256×256 px ให้อัตโนมัติ</div>
                                <button class="btn btn-sm btn-primary mt-2" type="submit"><i class="bi bi-upload me-1"></i>อัปโหลดภาพ</button>
                            </form>
                            <?php if ($user['avatar_file'] !== null): ?>
                                <form method="post" action="<?= e(url('profile.avatar.delete')) ?>" class="mt-1" data-confirm="ลบภาพโปรไฟล์? ระบบจะแสดงอักษรตัวแรกของชื่อแทน">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-link text-danger p-0" type="submit"><i class="bi bi-trash me-1"></i>ลบภาพโปรไฟล์</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <dl class="row small mb-3">
                        <?php if ($user['google_sub'] !== null): ?><dt class="col-4">Google</dt><dd class="col-8"><span class="badge text-bg-light border"><i class="bi bi-google me-1"></i>เชื่อมกับ Google</span></dd><?php endif; ?>
                        <dt class="col-4">บทบาท</dt><dd class="col-8"><?= $user['role'] === 'admin' ? 'ผู้ดูแลระบบ' : 'สมาชิก' ?></dd>
                        <dt class="col-4">สมัครเมื่อ</dt><dd class="col-8"><?= e(thai_date($user['created_at'], false)) ?></dd>
                    </dl>
                    <form method="post" action="<?= e(url('profile.update')) ?>" novalidate id="profileForm">
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label class="form-label" for="display_name">ชื่อที่แสดง</label>
                            <input class="form-control<?= invalid($errors, 'display_name') ?>" id="display_name" name="display_name" required maxlength="100" value="<?= e(old($old, 'display_name', $user['display_name'])) ?>">
                            <?= field_error($errors, 'display_name') ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="username">ชื่อผู้ใช้ (ใช้เข้าสู่ระบบ)</label>
                            <input class="form-control font-monospace<?= invalid($errors, 'username') ?>" id="username" name="username" required minlength="3" maxlength="30" pattern="[A-Za-z0-9_]{3,30}" autocomplete="username" spellcheck="false"
                                   value="<?= e(old($old, 'username', $user['username'])) ?>" data-original="<?= e($user['username']) ?>">
                            <div class="form-text">a-z, A-Z, 0-9 และ _ ยาว 3–30 ตัว</div>
                            <?= field_error($errors, 'username') ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="email">อีเมล</label>
                            <input class="form-control<?= invalid($errors, 'email') ?>" id="email" name="email" type="email" required maxlength="190" autocomplete="email"
                                   value="<?= e(old($old, 'email', $user['email'])) ?>" data-original="<?= e($user['email']) ?>">
                            <div class="form-text">ใช้เข้าสู่ระบบและรับลิงก์ตั้งรหัสผ่านใหม่<?= $user['google_sub'] !== null ? ' · การเข้าสู่ระบบด้วย Google ยังใช้ได้แม้เปลี่ยนอีเมล' : '' ?></div>
                            <?= field_error($errors, 'email') ?>
                        </div>
                        <?php if (str_starts_with((string) $user['password_hash'], '$')): ?>
                            <div class="mb-3<?= isset($errors['profile_password']) ? '' : ' d-none' ?>" id="profilePasswordWrap">
                                <label class="form-label" for="profile_password">รหัสผ่านปัจจุบัน</label>
                                <input class="form-control<?= invalid($errors, 'profile_password') ?>" id="profile_password" name="profile_password" type="password" maxlength="72" autocomplete="current-password">
                                <div class="form-text">ต้องยืนยันเมื่อเปลี่ยนชื่อผู้ใช้หรืออีเมล</div>
                                <?= field_error($errors, 'profile_password') ?>
                            </div>
                        <?php endif; ?>
                        <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>บันทึกข้อมูลบัญชี</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h5"><i class="bi bi-key me-1"></i>เปลี่ยนรหัสผ่าน</h2>
                    <?php if (!str_starts_with((string) $user['password_hash'], '$')): ?>
                        <p class="small text-secondary mb-2">บัญชีนี้สร้างด้วย Google จึงยังไม่มีรหัสผ่านของเว็บนี้ เข้าสู่ระบบด้วยปุ่ม Google ได้ตามปกติ</p>
                        <p class="small text-secondary">ต้องการเข้าสู่ระบบด้วยชื่อผู้ใช้และรหัสผ่านด้วย? ออกจากระบบแล้วใช้ <a href="<?= e(url('forgot')) ?>">ลืมรหัสผ่าน</a> กับอีเมล <?= e($user['email']) ?> เพื่อตั้งรหัสผ่าน</p>
                    <?php else: ?>
                    <p class="small text-secondary">ต้องยืนยันรหัสผ่านปัจจุบัน หลังเปลี่ยนแล้วอุปกรณ์อื่นจะต้องเข้าสู่ระบบใหม่</p>
                    <form method="post" action="<?= e(url('password.update')) ?>" novalidate>
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label class="form-label" for="current_password">รหัสผ่านปัจจุบัน</label>
                            <input class="form-control<?= invalid($errors, 'current_password') ?>" id="current_password" type="password" name="current_password" required maxlength="72" autocomplete="current-password">
                            <?= field_error($errors, 'current_password') ?>
                        </div>
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label" for="new_password">รหัสผ่านใหม่</label>
                                <input class="form-control<?= invalid($errors, 'new_password') ?>" id="new_password" type="password" name="new_password" required minlength="8" maxlength="72" autocomplete="new-password">
                                <?= field_error($errors, 'new_password') ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="new_password_confirm">ยืนยันรหัสผ่านใหม่</label>
                                <input class="form-control<?= invalid($errors, 'new_password_confirm') ?>" id="new_password_confirm" type="password" name="new_password_confirm" required maxlength="72" autocomplete="new-password">
                                <?= field_error($errors, 'new_password_confirm') ?>
                            </div>
                        </div>
                        <button class="btn btn-primary mt-3" type="submit"><i class="bi bi-shield-lock me-1"></i>เปลี่ยนรหัสผ่าน</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
