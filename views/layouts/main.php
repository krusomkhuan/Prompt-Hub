<?php
/** @var \PromptHub\Core\App $app @var \PromptHub\Core\Auth $auth */
$siteName = $app->setting('site_name', 'Prompt Hub');
$slogan = $app->setting('site_slogan');
$user = $auth->user();
$flashes = $app->session->takeFlashes();
$isAdminArea = str_starts_with((string) $route, 'admin');
$nav = static function (string $r, string $label, string $icon, array $match) use ($route): string {
    $active = in_array($route, $match, true);
    return '<li class="nav-item"><a class="nav-link' . ($active ? ' active' : '') . '"' . ($active ? ' aria-current="page"' : '')
        . ' href="' . e(url($r)) . '"><i class="bi bi-' . e($icon) . ' me-1"></i>' . e($label) . '</a></li>';
};
?><!doctype html>
<html lang="th" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($title ?? '') !== '' ? $title . ' · ' . $siteName : $siteName . ($slogan !== '' ? ' · ' . $slogan : '')) ?></title>
    <meta name="description" content="<?= e(str_limit($app->setting('site_description'), 160)) ?>">
    <?php
    // ลิงก์ canonical / Open Graph ใช้ URL เว็บไซต์ที่ตั้งไว้ (เฉพาะหน้าสาธารณะ ไม่มีข้อมูลส่วนตัวใน URL)
    $share = null;
    $shareImage = null;
    if (in_array($route, ['home', 'prompts', 'categories', 'leaders', 'about', 'privacy', 'shop'], true)) {
        $share = absolute_url($route);
    } elseif ($route === 'category' && !empty($category['id'])) {
        $share = absolute_url('category', ['id' => (int) $category['id']]);
    } elseif ($route === 'prompt' && !empty($prompt['id']) && \PromptHub\Services\PromptPolicy::isPublic($prompt)) {
        $share = absolute_url('prompt', ['id' => (int) $prompt['id']]);
        if (!empty($prompt['images'][0]['id'])) {
            $shareImage = absolute_url('media', ['id' => (int) $prompt['images'][0]['id']]);
        }
    }
    ?>
    <?php if ($share): ?>
    <link rel="canonical" href="<?= e($share) ?>">
    <meta property="og:type" content="<?= $route === 'prompt' ? 'article' : 'website' ?>">
    <meta property="og:site_name" content="<?= e($siteName) ?>">
    <meta property="og:title" content="<?= e(($title ?? '') !== '' ? $title : $siteName) ?>">
    <meta property="og:description" content="<?= e(str_limit($route === 'prompt' ? (string) $prompt['description'] : $app->setting('site_description'), 200)) ?>">
    <meta property="og:url" content="<?= e($share) ?>">
    <?php if ($shareImage): ?><meta property="og:image" content="<?= e($shareImage) ?>"><?php endif; ?>
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e(asset('css/app.css')) ?>" rel="stylesheet">
</head>
<body class="<?= $isAdminArea ? 'area-admin' : '' ?>">
<a class="visually-hidden-focusable skip-link" href="#main">ข้ามไปยังเนื้อหา</a>
<nav class="navbar navbar-expand-lg sticky-top site-nav">
    <div class="container">
        <a class="navbar-brand brand" href="<?= e(url('home')) ?>">
            <span class="brand-mark"><i class="bi bi-stars"></i></span>
            <span class="brand-text">
                <span class="brand-name"><?= e($siteName) ?></span>
                <?php if ($slogan !== ''): ?><span class="brand-slogan"><?= e($slogan) ?></span><?php endif; ?>
            </span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="เปิดเมนู">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?= $nav('prompts', 'สำรวจ Prompt', 'compass', ['prompts']) ?>
                <?= $nav('categories', 'หมวดหมู่', 'grid', ['categories', 'category']) ?>
                <?= $nav('shop', 'Digital Shop', 'bag', ['shop', 'shop.item', 'shop.checkout']) ?>
                <?= $nav('leaders', 'ทำเนียบ', 'award', ['leaders']) ?>
                <?= $nav('about', 'เกี่ยวกับ', 'info-circle', ['about']) ?>
            </ul>
            <ul class="navbar-nav align-items-lg-center gap-lg-1">
                <?php if ($user): ?>
                    <?php if ($auth->isAdmin()): ?>
                        <?= $nav('admin', 'หลังบ้าน', 'shield-lock', ['admin', 'admin.users', 'admin.categories', 'admin.tags', 'admin.tools', 'admin.prompts', 'admin.settings', 'admin.logs', 'admin.drive', 'admin.shop', 'admin.shop.form', 'admin.orders']) ?>
                    <?php endif; ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <?= avatar((int) $user['id'], $user['display_name'], $user['avatar_file'], 'sm') ?> <?= e(str_limit($user['display_name'], 24)) ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?= e(url('dashboard')) ?>"><i class="bi bi-speedometer2 me-2"></i>แดชบอร์ด</a></li>
                            <li><a class="dropdown-item" href="<?= e(url('my.prompts')) ?>"><i class="bi bi-collection me-2"></i>Prompt ของฉัน</a></li>
                            <li><a class="dropdown-item" href="<?= e(url('my.prompt.form')) ?>"><i class="bi bi-plus-circle me-2"></i>เพิ่ม Prompt</a></li>
                            <li><a class="dropdown-item" href="<?= e(url('favorites')) ?>"><i class="bi bi-heart me-2"></i>รายการโปรด</a></li>
                            <li><a class="dropdown-item" href="<?= e(url('library')) ?>"><i class="bi bi-box-seam me-2"></i>คลังของฉัน (Digital Shop)</a></li>
                            <li><a class="dropdown-item" href="<?= e(url('profile')) ?>"><i class="bi bi-gear me-2"></i>โปรไฟล์</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="post" action="<?= e(url('logout')) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>ออกจากระบบ</button>
                                </form>
                            </li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="<?= e(url('login')) ?>"><i class="bi bi-box-arrow-in-right me-1"></i>เข้าสู่ระบบ</a></li>
                    <li class="nav-item"><a class="btn btn-primary btn-sm px-3 ms-lg-2" href="<?= e(url('register')) ?>">สมัครสมาชิก</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<main id="main" class="site-main">
    <?php if ($flashes): ?>
        <div class="container pt-3">
            <?php foreach ($flashes as $f): ?>
                <div class="alert alert-<?= e(in_array($f['type'], ['success', 'danger', 'warning', 'info'], true) ? $f['type'] : 'info') ?> alert-dismissible fade show" role="<?= $f['type'] === 'danger' ? 'alert' : 'status' ?>">
                    <i class="bi bi-<?= $f['type'] === 'success' ? 'check-circle' : ($f['type'] === 'danger' ? 'exclamation-octagon' : 'info-circle') ?> me-2"></i><?= e($f['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="ปิด"></button>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?= $content ?>
</main>

<footer class="site-footer mt-5">
    <div class="container py-4">
        <div class="row g-3 align-items-center">
            <div class="col-md-7">
                <div class="fw-bold"><i class="bi bi-stars text-primary me-1"></i><?= e($siteName) ?> <span class="text-secondary fw-normal">· <?= e($slogan) ?></span></div>
                <div class="small text-secondary"><?= e($app->setting('site_description')) ?></div>
                <div class="small text-secondary mt-2 copyright-line"><?= e(copyright_notice($app->setting('copyright_owner'), $app->setting('copyright_start_year'))) ?></div>
            </div>
            <div class="col-md-5 text-md-end small text-secondary">
                <?php $developer = $app->setting('developer_name'); ?>
                <?php if ($developer !== ''): ?>
                    <div class="developer-credit"><i class="bi bi-code-slash me-1"></i>พัฒนาระบบโดย <strong class="text-body"><?= e($developer) ?></strong></div>
                <?php endif; ?>
                <div>
                    <a href="<?= e(url('privacy')) ?>"><i class="bi bi-shield-check me-1"></i>นโยบายความเป็นส่วนตัว (PDPA)</a>
                    <span class="mx-1">·</span>เวลาแสดงผล Asia/Bangkok
                </div>
                <div class="app-version"><i class="bi bi-tag me-1"></i>เวอร์ชัน <?= e(APP_VERSION) ?></div>
            </div>
        </div>
    </div>
</footer>

<div class="cookie-notice d-none" id="cookieNotice" role="region" aria-label="การใช้คุกกี้">
    <div class="container d-flex flex-column flex-sm-row align-items-sm-center gap-2 py-2">
        <div class="small flex-grow-1">
            <i class="bi bi-cookie me-1"></i>เว็บไซต์นี้ใช้คุกกี้ที่จำเป็นเพียงตัวเดียวเพื่อให้เข้าสู่ระบบและป้องกันการปลอมคำขอ ไม่มีคุกกี้โฆษณาหรือติดตามพฤติกรรม
            <a href="<?= e(url('privacy')) ?>#cookies">อ่านเพิ่มเติม</a>
        </div>
        <button type="button" class="btn btn-sm btn-primary flex-shrink-0" id="cookieAccept">รับทราบ</button>
    </div>
</div>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="confirmTitle"><i class="bi bi-exclamation-triangle text-danger me-2"></i>ยืนยันการดำเนินการ</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body" id="confirmMessage"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-danger" id="confirmOk">ยืนยัน</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
