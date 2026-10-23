<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card auth-card">
                <div class="card-body p-4">
                    <h1 class="h4 mb-1"><i class="bi bi-google text-primary me-1"></i>สร้างบัญชีด้วย Google</h1>
                    <p class="text-secondary small mb-4">ยืนยันข้อมูลอีกครั้งก่อนสร้างบัญชีสมาชิก อีเมลมาจากบัญชี Google ของคุณและแก้ไขไม่ได้</p>
                    <?php if ($errors): ?>
                        <div class="alert alert-danger py-2" role="alert">กรุณาแก้ไขข้อมูลที่ไม่ถูกต้อง</div>
                    <?php endif; ?>
                    <form method="post" action="<?= e(url('google.finish')) ?>" novalidate>
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label class="form-label" for="g_email">อีเมล (จาก Google)</label>
                            <input class="form-control" id="g_email" value="<?= e($g['email']) ?>" readonly>
                        </div>
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label" for="username">ชื่อผู้ใช้</label>
                                <input class="form-control<?= invalid($errors, 'username') ?>" id="username" name="username" required minlength="3" maxlength="30" pattern="[A-Za-z0-9_]{3,30}" value="<?= e(old($old, 'username')) ?>">
                                <div class="form-text">a-z, 0-9 และ _ ยาว 3–30 ตัว</div>
                                <?= field_error($errors, 'username') ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="display_name">ชื่อที่แสดง</label>
                                <input class="form-control<?= invalid($errors, 'display_name') ?>" id="display_name" name="display_name" required maxlength="100" value="<?= e(old($old, 'display_name')) ?>">
                                <?= field_error($errors, 'display_name') ?>
                            </div>
                        </div>
                        <div class="form-check mt-4">
                            <input class="form-check-input<?= invalid($errors, 'accept_privacy') ?>" type="checkbox" id="accept_privacy" name="accept_privacy" value="1" required<?= old($old, 'accept_privacy') === '1' ? ' checked' : '' ?>>
                            <label class="form-check-label small" for="accept_privacy">
                                ฉันได้อ่านและยอมรับ <a href="<?= e(url('privacy')) ?>" target="_blank" rel="noopener">นโยบายความเป็นส่วนตัว (PDPA)</a>
                                ระบบจะเก็บชื่อ อีเมล และรหัสบัญชี Google เพื่อใช้เข้าสู่ระบบ
                            </label>
                            <?= field_error($errors, 'accept_privacy') ?>
                        </div>
                        <button class="btn btn-primary w-100 mt-3" type="submit"><i class="bi bi-person-check me-1"></i>สร้างบัญชี</button>
                    </form>
                    <p class="small text-secondary mt-3 mb-0">บัญชีที่สร้างด้วย Google ไม่มีรหัสผ่านของเว็บนี้ เข้าสู่ระบบด้วยปุ่ม Google ทุกครั้ง หรือตั้งรหัสผ่านภายหลังด้วย “ลืมรหัสผ่าน”</p>
                </div>
            </div>
        </div>
    </div>
</div>
