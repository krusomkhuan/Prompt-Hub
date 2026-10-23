<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-5">
            <div class="card auth-card">
                <div class="card-body p-4">
                    <h1 class="h4 mb-1"><i class="bi bi-box-arrow-in-right text-primary me-1"></i>เข้าสู่ระบบ</h1>
                    <p class="text-secondary small mb-4">ใช้ชื่อผู้ใช้หรืออีเมลที่สมัครไว้</p>
                    <?php if (!empty($errors['login'])): ?>
                        <div class="alert alert-danger py-2" role="alert"><?= e($errors['login']) ?></div>
                    <?php endif; ?>
                    <?= $view->partial('partials/google_button', ['label' => 'เข้าสู่ระบบด้วย Google']) ?>
                    <form method="post" action="<?= e(url('login.submit')) ?>" novalidate>
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label class="form-label" for="login">ชื่อผู้ใช้หรืออีเมล</label>
                            <input class="form-control" id="login" name="login" maxlength="190" required autocomplete="username" value="<?= e(old($old, 'login')) ?>" autofocus>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-baseline">
                                <label class="form-label" for="password">รหัสผ่าน</label>
                                <a class="small" href="<?= e(url('forgot')) ?>">ลืมรหัสผ่าน?</a>
                            </div>
                            <div class="input-group">
                                <input class="form-control" id="password" type="password" name="password" maxlength="72" required autocomplete="current-password">
                                <button class="btn btn-outline-secondary" type="button" data-toggle-password="password" aria-label="แสดงรหัสผ่าน"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <button class="btn btn-primary w-100" type="submit">เข้าสู่ระบบ</button>
                    </form>
                    <p class="text-center small mt-3 mb-0">ยังไม่มีบัญชี? <a href="<?= e(url('register')) ?>">สมัครสมาชิก</a></p>
                </div>
            </div>
        </div>
    </div>
</div>
