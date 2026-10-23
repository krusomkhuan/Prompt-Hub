<?php
$items = [
    'admin' => ['ภาพรวม', 'speedometer2'],
    'admin.prompts' => ['Prompt', 'collection'],
    'admin.users' => ['สมาชิก', 'people'],
    'admin.categories' => ['หมวดหมู่', 'grid'],
    'admin.tags' => ['แท็ก', 'tags'],
    'admin.tools' => ['เครื่องมือ AI', 'cpu'],
    'admin.shop' => ['Digital Shop', 'bag'],
    'admin.orders' => ['คำสั่งซื้อ', 'receipt'],
    'admin.settings' => ['ตั้งค่าเว็บไซต์', 'sliders'],
    'admin.drive' => ['Google Drive', 'cloud-arrow-up'],
    'admin.logs' => ['บันทึกกิจกรรม', 'journal-text'],
];
?>
<div class="admin-nav mb-4">
    <div class="d-flex align-items-center gap-2 mb-2 text-secondary small"><i class="bi bi-shield-lock"></i> หลังบ้านผู้ดูแลระบบ</div>
    <ul class="nav nav-pills flex-nowrap overflow-auto">
        <?php foreach ($items as $r => [$label, $icon]): $on = $route === $r || ($r !== 'admin' && str_starts_with((string) $route, $r . '.')); ?>
            <li class="nav-item">
                <a class="nav-link text-nowrap<?= $on ? ' active' : '' ?>" href="<?= e(url($r)) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
                    <i class="bi bi-<?= e($icon) ?> me-1"></i><?= e($label) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
