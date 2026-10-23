<?php
/** แบ่งหน้า: $pager [page, pages, total], $routeName, $params (ค่าค้นหา/ตัวกรองที่ต้องคงไว้) */
if (($pager['pages'] ?? 1) <= 1) {
    return;
}
$page = (int) $pager['page'];
$pages = (int) $pager['pages'];
$link = static fn(int $n): string => url($routeName, ['page' => $n > 1 ? $n : null] + $params);
$start = max(1, $page - 2);
$end = min($pages, $page + 2);
?>
<nav class="mt-4" aria-label="แบ่งหน้า">
    <ul class="pagination justify-content-center flex-wrap">
        <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
            <a class="page-link" href="<?= e($link(max(1, $page - 1))) ?>" aria-label="หน้าก่อนหน้า"><i class="bi bi-chevron-left"></i></a>
        </li>
        <?php if ($start > 1): ?>
            <li class="page-item"><a class="page-link" href="<?= e($link(1)) ?>">1</a></li>
            <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
        <?php endif; ?>
        <?php for ($i = $start; $i <= $end; $i++): ?>
            <li class="page-item<?= $i === $page ? ' active' : '' ?>">
                <a class="page-link" href="<?= e($link($i)) ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a>
            </li>
        <?php endfor; ?>
        <?php if ($end < $pages): ?>
            <?php if ($end < $pages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
            <li class="page-item"><a class="page-link" href="<?= e($link($pages)) ?>"><?= $pages ?></a></li>
        <?php endif; ?>
        <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>">
            <a class="page-link" href="<?= e($link(min($pages, $page + 1))) ?>" aria-label="หน้าถัดไป"><i class="bi bi-chevron-right"></i></a>
        </li>
    </ul>
    <p class="text-center small text-secondary">หน้า <?= $page ?> จาก <?= $pages ?> · ทั้งหมด <?= number_th((int) $pager['total']) ?> รายการ</p>
</nav>
