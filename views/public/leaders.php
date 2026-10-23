<?php use PromptHub\Services\BadgeService; ?>
<div class="container py-4">
    <div class="mb-4">
        <h1 class="h3 mb-1"><i class="bi bi-award text-primary me-2"></i>ทำเนียบผู้สร้าง Prompt</h1>
        <p class="text-secondary mb-0">สมาชิกได้ 1 แต้มต่อ Prompt สาธารณะที่ผ่านการอนุมัติ สะสมครบเกณฑ์รับเหรียญรางวัล</p>
    </div>

    <div class="row g-3 mb-4">
        <?php foreach (array_reverse(BadgeService::TIERS) as $t): ?>
            <div class="col-md-4">
                <div class="card tier-card tier-<?= e($t['key']) ?> h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <i class="bi bi-award-fill fs-1 medal-text-<?= e($t['key']) ?>" aria-hidden="true"></i>
                        <div>
                            <div class="fw-bold"><?= e($t['label']) ?></div>
                            <div class="small text-secondary"><?= (int) $t['min'] ?><?= $t['max'] ? '–' . (int) $t['max'] . ' แต้ม' : ' แต้มขึ้นไป' ?></div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (!$rows): ?>
        <?= $view->partial('partials/empty', [
            'icon' => 'award',
            'heading' => 'ยังไม่มีสมาชิกที่สะสมแต้ม',
            'text' => 'เพิ่ม Prompt แล้วส่งตรวจเพื่อเผยแพร่ เมื่อผู้ดูแลอนุมัติจะได้รับแต้มทันที',
            'actionUrl' => url('my.prompt.form'),
            'actionLabel' => 'เพิ่ม Prompt',
        ]) ?>
    <?php else: ?>
        <div class="card">
            <div class="table-responsive">
                <table class="table align-middle mb-0" id="leaderTable">
                    <thead><tr><th class="text-center">อันดับ</th><th>สมาชิก</th><th class="text-end">แต้ม</th><th>เหรียญ</th><th class="text-end d-none d-sm-table-cell">ยอดคัดลอกรวม</th></tr></thead>
                    <tbody>
                        <?php foreach ($rows as $i => $r): ?>
                            <tr>
                                <td class="text-center fw-bold"><?= $i + 1 ?></td>
                                <td><span class="d-inline-flex align-items-center gap-2"><?= avatar((int) $r['id'], $r['display_name'], $r['avatar_file'], 'md') ?><?= e($r['display_name']) ?></span></td>
                                <td class="text-end fw-semibold"><?= number_th((int) $r['points']) ?></td>
                                <td><?= medal_badge((int) $r['points']) ?: '<span class="small text-secondary">อีก ' . (BadgeService::TIERS[2]['min'] - (int) $r['points']) . ' แต้ม</span>' ?></td>
                                <td class="text-end d-none d-sm-table-cell"><?= number_th((int) $r['copies']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
