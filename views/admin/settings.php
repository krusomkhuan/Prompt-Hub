<div class="container py-4">
    <?= $view->partial('partials/admin_nav') ?>
    <h1 class="h3 mb-3">ตั้งค่าเว็บไซต์</h1>
    <form class="card card-body col-lg-8" method="post" action="<?= e(url('admin.settings.save')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label" for="site_name">ชื่อเว็บไซต์</label>
            <input class="form-control<?= invalid($errors, 'site_name') ?>" id="site_name" name="site_name" required minlength="2" maxlength="100" value="<?= e(old($old, 'site_name')) ?>">
            <?= field_error($errors, 'site_name') ?>
        </div>
        <?php
        $cfgUrl = rtrim((string) ($app->config['app']['app_url'] ?? 'http://localhost'), '/') . $app->baseUrl();
        $typed = (string) old($old, 'site_url');
        $effective = $typed !== '' ? (normalize_site_url($typed) ?? $app->publicUrl()) : $cfgUrl;
        $host = (string) parse_url($effective, PHP_URL_HOST);
        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');
        $isHttps = str_starts_with($effective, 'https://');
        $sitePath = rtrim((string) parse_url($effective, PHP_URL_PATH), '/');
        ?>
        <div class="mb-3 p-3 rounded border site-url-box" id="siteUrlBox">
            <label class="form-label fw-semibold" for="site_url"><i class="bi bi-globe2 me-1"></i>URL เว็บไซต์ (ที่อยู่จริงที่ใช้งาน)</label>
            <input class="form-control font-monospace<?= invalid($errors, 'site_url') ?>" id="site_url" name="site_url" type="url" inputmode="url" maxlength="200"
                   value="<?= e($typed) ?>" placeholder="<?= e($cfgUrl) ?>" autocomplete="off" spellcheck="false">
            <?= field_error($errors, 'site_url') ?>
            <div class="form-text">URL ของหน้าแรกเว็บไซต์ เช่น <code>https://prompthub.example.ac.th</code> หรือ <code>https://www.example.ac.th/prompt_hub/public</code> · เว้นว่างเพื่อใช้ค่าในไฟล์ตั้งค่า (<code><?= e($cfgUrl) ?></code>)</div>
            <dl class="row small mb-0 mt-2">
                <dt class="col-sm-4 text-secondary fw-normal">ลิงก์ในอีเมลลืมรหัสผ่าน</dt>
                <dd class="col-sm-8 font-monospace text-break" id="resetUrlPreview" data-url-suffix="/index.php?r=reset&token=…"><?= e($effective . '/index.php?r=reset&token=…') ?></dd>
                <dt class="col-sm-4 text-secondary fw-normal">Google redirect URI</dt>
                <dd class="col-sm-8 font-monospace text-break" id="googleRedirectUri" data-url-suffix="/index.php?r=google.callback"><?= e($effective . '/index.php?r=google.callback') ?></dd>
                <dt class="col-sm-4 text-secondary fw-normal">ลิงก์แชร์ (canonical)</dt>
                <dd class="col-sm-8 font-monospace text-break" data-url-suffix="/"><?= e($effective . '/') ?></dd>
            </dl>
            <?php if (!$isHttps && !$isLocal): ?>
                <div class="alert alert-warning small mt-2 mb-0" id="siteUrlHttpWarning"><i class="bi bi-shield-exclamation me-1"></i>เว็บไซต์ที่เปิดใช้จริงควรใช้ <strong>https://</strong> เพื่อเข้ารหัสรหัสผ่านและ session (เมื่อใช้ HTTPS แล้วให้ตั้ง <code>secure_cookie = true</code> ใน config/config.php)</div>
            <?php elseif ($isHttps && empty($app->config['app']['secure_cookie'])): ?>
                <div class="alert alert-info small mt-2 mb-0" id="siteUrlCookieHint"><i class="bi bi-info-circle me-1"></i>ใช้ HTTPS แล้ว แนะนำให้ตั้ง <code>'secure_cookie' => true</code> ใน config/config.php เพื่อส่ง cookie ผ่าน HTTPS เท่านั้น</div>
            <?php endif; ?>
            <?php if ($sitePath !== $app->baseUrl()): ?>
                <div class="alert alert-secondary small mt-2 mb-0" id="siteUrlPathNote"><i class="bi bi-signpost-split me-1"></i>path ของ URL นี้ (<code><?= e($sitePath === '' ? '/' : $sitePath) ?></code>) ต่างจาก <code>base_url</code> ใน config (<code><?= e($app->baseUrl() === '' ? '/' : $app->baseUrl()) ?></code>) — ใช้ได้เมื่อตั้ง VirtualHost/reverse proxy ให้ URL นี้ชี้มาที่โฟลเดอร์ public ลิงก์ภายในเว็บยังใช้ base_url ตามเดิม</div>
            <?php endif; ?>
            <div class="form-text mt-2">เปลี่ยน URL แล้ว ถ้าเปิดใช้ Google ต้องเพิ่ม redirect URI ข้างต้นใน Google Cloud Console ด้วย</div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="site_slogan">สโลแกน</label>
            <input class="form-control<?= invalid($errors, 'site_slogan') ?>" id="site_slogan" name="site_slogan" maxlength="150" value="<?= e(old($old, 'site_slogan')) ?>">
            <?= field_error($errors, 'site_slogan') ?>
        </div>
        <div class="mb-3">
            <label class="form-label" for="hero_badge">ข้อความป้ายบนหน้าแรก</label>
            <input class="form-control<?= invalid($errors, 'hero_badge') ?>" id="hero_badge" name="hero_badge" maxlength="100" value="<?= e(old($old, 'hero_badge')) ?>">
            <?= field_error($errors, 'hero_badge') ?>
        </div>
        <div class="mb-3">
            <label class="form-label" for="site_description">คำอธิบายเว็บไซต์</label>
            <textarea class="form-control<?= invalid($errors, 'site_description') ?>" id="site_description" name="site_description" rows="3" maxlength="1000"><?= e(old($old, 'site_description')) ?></textarea>
            <?= field_error($errors, 'site_description') ?>
        </div>
        <div class="mb-3">
            <label class="form-label" for="developer_name">ผู้พัฒนาระบบ (แสดงที่ส่วนท้ายเว็บ)</label>
            <input class="form-control<?= invalid($errors, 'developer_name') ?>" id="developer_name" name="developer_name" maxlength="150" value="<?= e(old($old, 'developer_name')) ?>">
            <?= field_error($errors, 'developer_name') ?>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-sm-8">
                <label class="form-label" for="copyright_owner">เจ้าของลิขสิทธิ์ (ชื่อบุคคลหรือหน่วยงาน)</label>
                <input class="form-control<?= invalid($errors, 'copyright_owner') ?>" id="copyright_owner" name="copyright_owner" maxlength="150" value="<?= e(old($old, 'copyright_owner')) ?>">
                <?= field_error($errors, 'copyright_owner') ?>
            </div>
            <div class="col-sm-4">
                <label class="form-label" for="copyright_start_year">ปีที่เริ่มเผยแพร่ (ค.ศ.)</label>
                <input class="form-control<?= invalid($errors, 'copyright_start_year') ?>" id="copyright_start_year" name="copyright_start_year" type="number" min="2000" max="2100" required value="<?= e(old($old, 'copyright_start_year')) ?>">
                <?= field_error($errors, 'copyright_start_year') ?>
            </div>
            <div class="col-12 form-text mt-1">ส่วนท้ายเว็บจะแสดง “<?= e(copyright_notice((string) old($old, 'copyright_owner'), (string) old($old, 'copyright_start_year'))) ?>” ปีถัดไปจะแสดงเป็นช่วงปีอัตโนมัติ · เวอร์ชันระบบ <?= e(APP_VERSION) ?> (กำหนดใน <code>app/version.php</code>)</div>
        </div>
        <h2 class="h6 mt-2"><i class="bi bi-shield-check me-1"></i>ข้อมูลสำหรับนโยบายความเป็นส่วนตัว (PDPA)</h2>
        <div class="mb-3">
            <label class="form-label" for="privacy_controller">ผู้ควบคุมข้อมูลส่วนบุคคล</label>
            <input class="form-control<?= invalid($errors, 'privacy_controller') ?>" id="privacy_controller" name="privacy_controller" maxlength="200" value="<?= e(old($old, 'privacy_controller')) ?>" placeholder="เช่น ชื่อสถานศึกษา / หน่วยงานที่ให้บริการเว็บไซต์">
            <?= field_error($errors, 'privacy_controller') ?>
        </div>
        <div class="mb-3">
            <label class="form-label" for="privacy_contact">ช่องทางติดต่อเรื่องข้อมูลส่วนบุคคล</label>
            <input class="form-control<?= invalid($errors, 'privacy_contact') ?>" id="privacy_contact" name="privacy_contact" maxlength="300" value="<?= e(old($old, 'privacy_contact')) ?>" placeholder="เช่น อีเมลเจ้าหน้าที่ / เบอร์โทรงานทะเบียน">
            <div class="form-text">แสดงในหน้า <a href="<?= e(url('privacy')) ?>">นโยบายความเป็นส่วนตัว</a> ให้สมาชิกใช้ติดต่อขอแก้ไข ขอสำเนา หรือขอลบข้อมูล</div>
            <?= field_error($errors, 'privacy_contact') ?>
        </div>
        <div><button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>บันทึกการตั้งค่า</button></div>
    </form>

    <?php
    $g = (array) ($app->config['google'] ?? []);
    $gOn = google_enabled();
    $gSource = $app->googleSource();
    $gHasSecret = (string) $app->setting('google_client_secret') !== '';
    $gRedirect = absolute_url('google.callback');
    ?>
    <div class="card col-lg-8 mt-4" id="googleLoginCard">
        <div class="card-body">
            <h2 class="h5 mb-1"><i class="bi bi-google me-1"></i>เข้าสู่ระบบ / สมัครสมาชิกด้วย Google</h2>
            <p class="small mb-3">
                สถานะ: <?= $gOn ? '<span class="badge text-bg-success" id="googleStatus">เปิดใช้งาน</span>' : '<span class="badge text-bg-secondary" id="googleStatus">ปิดอยู่</span>' ?>
                <span class="text-secondary ms-1">Client ID จาก<?= $gSource === 'admin' ? 'หน้านี้' : 'ไฟล์ config/config.php' ?><?= (string) ($g['client_id'] ?? '') === '' ? ' (ยังไม่ได้ตั้ง)' : '' ?></span>
            </p>
            <form method="post" action="<?= e(url('admin.google.save')) ?>" novalidate autocomplete="off">
                <?= csrf_field() ?>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="google_login_enabled" name="google_login_enabled" value="1"<?= $gOn ? ' checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="google_login_enabled">เปิดการเข้าสู่ระบบด้วย Google (แสดงปุ่มในหน้าเข้าสู่ระบบและสมัครสมาชิก)</label>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="google_client_id">Client ID</label>
                    <input class="form-control font-monospace" id="google_client_id" name="google_client_id" maxlength="230" spellcheck="false"
                           value="<?= e($app->setting('google_client_id')) ?>" placeholder="<?= e($gSource === 'config' && (string) ($g['client_id'] ?? '') !== '' ? 'ใช้ค่าใน config: ' . $g['client_id'] : '123456789-xxxx.apps.googleusercontent.com') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="google_client_secret">Client secret</label>
                    <input class="form-control font-monospace" id="google_client_secret" name="google_client_secret" type="password" maxlength="200" autocomplete="new-password" spellcheck="false"
                           placeholder="<?= $gHasSecret ? '•••••••• ตั้งค่าแล้ว — เว้นว่างเพื่อใช้ค่าเดิม' : 'GOCSPX-...' ?>">
                    <div class="form-text">ระบบไม่แสดง Client secret ที่บันทึกแล้ว · เก็บในฐานข้อมูล (ตาราง site_settings) — เว้นว่าง Client ID และ secret เพื่อใช้ค่าในไฟล์ config แทน</div>
                </div>
                <?php if ($gSource === 'admin'): ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="google_clear" name="google_clear" value="1">
                        <label class="form-check-label small" for="google_clear">ลบ Client ID / secret ที่ตั้งในหน้านี้ (กลับไปใช้ค่าในไฟล์ config)</label>
                    </div>
                <?php endif; ?>
                <div class="small mb-3">
                    <div class="text-secondary">Authorized redirect URI ที่ต้องเพิ่มใน Google Cloud Console › Credentials › OAuth client:</div>
                    <div class="font-monospace text-break bg-body-tertiary rounded p-2 mt-1" id="googleRedirectUri2"><?= e($gRedirect) ?></div>
                    <div class="text-secondary mt-1">OAuth consent screen: เลือก <strong>External</strong> แล้วกด <strong>Publish app</strong> เพื่อให้บัญชี Gmail ทั่วไปสมัครได้</div>
                </div>
                <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>บันทึกการตั้งค่า Google</button>
            </form>
            <?php if ((string) ($g['client_id'] ?? '') !== ''): ?>
                <form method="post" action="<?= e(url('admin.google.test')) ?>" class="mt-2">
                    <?= csrf_field() ?>
                    <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-plug me-1"></i>ทดสอบกับ Google</button>
                    <span class="small text-secondary ms-1">ตรวจว่า Google รับ Client ID และ redirect URI (ไม่เข้าสู่ระบบบัญชีใด)</span>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
