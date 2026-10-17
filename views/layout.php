<?php use function Hub\{e, csrf}; ?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e(
    $site["name"]
) ?> · <?= e(
     $site["slogan"]
 ) ?></title><link href="assets/vendor/bootstrap.min.css" rel="stylesheet"><link href="assets/vendor/bootstrap-icons.min.css" rel="stylesheet"><link href="assets/vendor/sarabun.css" rel="stylesheet"><link href="assets/style.css" rel="stylesheet"><script src="assets/app.js" defer></script></head><body>
<header class="bg-white border-bottom"><nav class="container py-3 d-flex flex-wrap align-items-center gap-3"><a class="brand me-auto" href="<?= e(
    url()
) ?>"><i class="bi bi-stars"></i> <?= e($site["name"]) ?><small><?= e(
    $site["slogan"]
) ?></small></a><a href="<?= e(
    url("browse")
) ?>">สำรวจ Prompt</a><a href="<?= e(
    url("categories")
) ?>">หมวดหมู่</a><a href="<?= e(url("about")) ?>">เกี่ยวกับ</a><?php if (
    $auth->user
): ?><a href="<?= e(url("dashboard")) ?>"><?= e(
    $auth->user["display_name"]
) ?></a><?php if ($auth->user["role"] === "admin"): ?><a href="<?= e(
    url("admin")
) ?>">หลังบ้าน</a><?php endif; ?><form method="post" action="<?= e(
    url()
) ?>"><?= csrf() ?><input type="hidden" name="action" value="logout"><button class="btn btn-outline-secondary btn-sm">ออกจากระบบ</button></form><?php else: ?><a href="<?= e(
    url("login")
) ?>">เข้าสู่ระบบ</a><a class="btn btn-primary" href="<?= e(
    url("register")
) ?>">สมัครสมาชิก</a><?php endif; ?></nav></header>
<main class="container py-4"><?php
if ($error): ?><div class="alert alert-danger" role="alert"><?= e(
    $error
) ?></div><?php endif;
if (
    isset($_SESSION["flash"])
): ?><div class="alert alert-success" role="status"><?= e(
    $_SESSION["flash"]
) ?></div><?php unset($_SESSION["flash"]);endif;
echo $body;
?></main><footer class="container border-top py-4 text-secondary"><?= e(
    $site["name"]
) ?> · <?= e(
     $site["slogan"]
 ) ?> <span class="float-end">เวลาแสดงผล Asia/Bangkok</span></footer></body></html>
