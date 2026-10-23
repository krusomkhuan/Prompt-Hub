<?php
/** สถานะว่าง: $icon, $heading, $text, $actionUrl, $actionLabel (ไม่บังคับ) */
?>
<div class="empty-state text-center">
    <div class="empty-icon"><i class="bi bi-<?= e($icon ?? 'inbox') ?>"></i></div>
    <h3 class="h5"><?= e($heading ?? 'ยังไม่มีข้อมูล') ?></h3>
    <?php if (!empty($text)): ?><p class="text-secondary mb-3"><?= e($text) ?></p><?php endif; ?>
    <?php if (!empty($actionUrl)): ?>
        <a class="btn btn-primary" href="<?= e($actionUrl) ?>"><?= e($actionLabel ?? 'เริ่มต้น') ?></a>
    <?php endif; ?>
</div>
