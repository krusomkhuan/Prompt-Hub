<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-5">
            <div class="card auth-card">
                <div class="card-body p-4">
                    <h1 class="h4 mb-1"><i class="bi bi-key text-primary me-1"></i>ลืมรหัสผ่าน</h1>
                    <p class="text-secondary small mb-4">กรอกอีเมลที่ใช้สมัครสมาชิก ระบบจะส่งลิงก์สำหรับตั้งรหัสผ่านใหม่ให้ทางอีเมล</p>

                    <?php if (!$mailReady): ?>
                        <div class="alert alert-warning small" role="status">
                            <i class="bi bi-envelope-slash me-1"></i>ระบบยังไม่ได้ตั้งค่าการส่งอีเมล จึงยังส่งลิงก์ตั้งรหัสผ่านใหม่ไม่ได้ กรุณาติดต่อผู้ดูแลระบบ
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?= e(url('forgot.submit')) ?>" novalidate>
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label class="form-label" for="email">อีเมล</label>
                            <input class="form-control<?= invalid($errors, 'email') ?>" id="email" type="email" name="email" required maxlength="190" autocomplete="email" value="<?= e(old($old, 'email')) ?>" autofocus<?= $mailReady ? '' : ' disabled' ?>>
                            <?= field_error($errors, 'email') ?>
                        </div>
                        <button class="btn btn-primary w-100" type="submit"<?= $mailReady ? '' : ' disabled' ?>><i class="bi bi-send me-1"></i>ส่งลิงก์ตั้งรหัสผ่านใหม่</button>
                    </form>
                    <p class="small text-secondary mt-3 mb-0">ลิงก์ใช้ได้ครั้งเดียว หมดอายุใน <?= (int) \PromptHub\Services\PasswordResetService::TTL_MINUTES ?> นาที หากไม่พบอีเมลให้ดูในโฟลเดอร์สแปม</p>
                    <p class="text-center small mt-3 mb-0"><a href="<?= e(url('login')) ?>"><i class="bi bi-arrow-left me-1"></i>กลับไปหน้าเข้าสู่ระบบ</a></p>
                </div>
            </div>
        </div>
    </div>
</div>
