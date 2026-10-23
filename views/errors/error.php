<?php
$icons = [401 => 'lock', 403 => 'shield-x', 404 => 'search', 405 => 'slash-circle', 422 => 'exclamation-circle', 429 => 'hourglass-split'];
$icon = $icons[$status] ?? 'exclamation-triangle';
?>
<div class="container py-5">
    <div class="empty-state text-center">
        <div class="empty-icon"><i class="bi bi-<?= e($icon) ?>"></i></div>
        <p class="text-secondary mb-1">ข้อผิดพลาด <?= (int) $status ?></p>
        <h1 class="h4 mb-4"><?= e($message) ?></h1>
        <div class="d-flex justify-content-center flex-wrap gap-2">
            <a class="btn btn-primary" href="<?= e(url('home')) ?>"><i class="bi bi-house me-1"></i>กลับหน้าแรก</a>
            <?php if ($status === 401): ?>
                <a class="btn btn-outline-primary" href="<?= e(url('login')) ?>"><i class="bi bi-box-arrow-in-right me-1"></i>เข้าสู่ระบบ</a>
            <?php else: ?>
                <a class="btn btn-outline-secondary" href="<?= e(url('prompts')) ?>"><i class="bi bi-compass me-1"></i>สำรวจ Prompt</a>
            <?php endif; ?>
        </div>
    </div>
</div>
