<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-5">
            <div class="card auth-card">
                <div class="card-body p-4">
                    <?php if (!$user): ?>
                        <div class="text-center">
                            <div class="empty-icon"><i class="bi bi-link-45deg"></i></div>
                            <h1 class="h4">ลิงก์ใช้ไม่ได้</h1>
                            <p class="text-secondary"><?= e($errors['token'] ?? 'ลิงก์ตั้งรหัสผ่านไม่ถูกต้อง หมดอายุ หรือถูกใช้ไปแล้ว กรุณาขอลิงก์ใหม่') ?></p>
                            <a class="btn btn-primary" href="<?= e(url('forgot')) ?>"><i class="bi bi-arrow-repeat me-1"></i>ขอลิงก์ใหม่</a>
                        </div>
                    <?php else: ?>
                        <h1 class="h4 mb-1"><i class="bi bi-shield-lock text-primary me-1"></i>ตั้งรหัสผ่านใหม่</h1>
                        <p class="text-secondary small mb-4">บัญชี <strong><?= e($user['username']) ?></strong> — หลังตั้งรหัสผ่านใหม่ อุปกรณ์ที่เข้าสู่ระบบไว้จะต้องเข้าสู่ระบบใหม่</p>
                        <form method="post" action="<?= e(url('reset.submit')) ?>" novalidate>
                            <?= csrf_field() ?>
                            <div class="mb-3">
                                <label class="form-label" for="password">รหัสผ่านใหม่</label>
                                <input class="form-control<?= invalid($errors, 'password') ?>" id="password" type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" autofocus>
                                <div class="form-text">อย่างน้อย 8 ตัวอักษร</div>
                                <?= field_error($errors, 'password') ?>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="password_confirm">ยืนยันรหัสผ่านใหม่</label>
                                <input class="form-control<?= invalid($errors, 'password_confirm') ?>" id="password_confirm" type="password" name="password_confirm" required maxlength="72" autocomplete="new-password">
                                <?= field_error($errors, 'password_confirm') ?>
                            </div>
                            <button class="btn btn-primary w-100" type="submit"><i class="bi bi-check2-circle me-1"></i>บันทึกรหัสผ่านใหม่</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
