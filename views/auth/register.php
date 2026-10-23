<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card auth-card">
                <div class="card-body p-4">
                    <h1 class="h4 mb-1"><i class="bi bi-person-plus text-primary me-1"></i>สมัครสมาชิก</h1>
                    <p class="text-secondary small mb-4">สมาชิกเก็บ Prompt ส่วนตัว ส่งเผยแพร่ และบันทึกรายการโปรดได้</p>
                    <?php if ($errors): ?>
                        <div class="alert alert-danger py-2" role="alert">กรุณาแก้ไขข้อมูลที่ไม่ถูกต้อง</div>
                    <?php endif; ?>
                    <?= $view->partial('partials/google_button', ['label' => 'สมัครด้วยบัญชี Google']) ?>
                    <form method="post" action="<?= e(url('register.submit')) ?>" novalidate>
                        <?= csrf_field() ?>
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label" for="username">ชื่อผู้ใช้</label>
                                <input class="form-control<?= invalid($errors, 'username') ?>" id="username" name="username" required minlength="3" maxlength="30" pattern="[A-Za-z0-9_]{3,30}" autocomplete="username" value="<?= e(old($old, 'username')) ?>">
                                <div class="form-text">a-z, 0-9 และ _ ยาว 3–30 ตัว</div>
                                <?= field_error($errors, 'username') ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="display_name">ชื่อที่แสดง</label>
                                <input class="form-control<?= invalid($errors, 'display_name') ?>" id="display_name" name="display_name" required maxlength="100" autocomplete="name" value="<?= e(old($old, 'display_name')) ?>">
                                <?= field_error($errors, 'display_name') ?>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="email">อีเมล</label>
                                <input class="form-control<?= invalid($errors, 'email') ?>" id="email" type="email" name="email" required maxlength="190" autocomplete="email" value="<?= e(old($old, 'email')) ?>">
                                <?= field_error($errors, 'email') ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="password">รหัสผ่าน</label>
                                <input class="form-control<?= invalid($errors, 'password') ?>" id="password" type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password">
                                <div class="form-text">อย่างน้อย 8 ตัวอักษร</div>
                                <?= field_error($errors, 'password') ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="password_confirm">ยืนยันรหัสผ่าน</label>
                                <input class="form-control<?= invalid($errors, 'password_confirm') ?>" id="password_confirm" type="password" name="password_confirm" required maxlength="72" autocomplete="new-password">
                                <?= field_error($errors, 'password_confirm') ?>
                            </div>
                        </div>
                        <div class="form-check mt-4">
                            <input class="form-check-input<?= invalid($errors, 'accept_privacy') ?>" type="checkbox" id="accept_privacy" name="accept_privacy" value="1" required<?= old($old, 'accept_privacy') === '1' ? ' checked' : '' ?>>
                            <label class="form-check-label small" for="accept_privacy">
                                ฉันได้อ่านและยอมรับ <a href="<?= e(url('privacy')) ?>" target="_blank" rel="noopener">นโยบายความเป็นส่วนตัว (PDPA)</a>
                                ซึ่งอธิบายการเก็บชื่อผู้ใช้ อีเมล ชื่อที่แสดง และข้อมูลการใช้งานเพื่อให้บริการ
                            </label>
                            <?= field_error($errors, 'accept_privacy') ?>
                        </div>
                        <button class="btn btn-primary w-100 mt-3" type="submit">สมัครสมาชิก</button>
                    </form>
                    <p class="text-center small mt-3 mb-0">มีบัญชีแล้ว? <a href="<?= e(url('login')) ?>">เข้าสู่ระบบ</a></p>
                </div>
            </div>
        </div>
    </div>
</div>
