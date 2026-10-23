<?php $user = $auth->user(); ?>
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-0">สวัสดี <?= e($user['display_name']) ?></h1>
            <p class="text-secondary mb-0">สรุป Prompt ของคุณ</p>
        </div>
        <a class="btn btn-primary" href="<?= e(url('my.prompt.form')) ?>"><i class="bi bi-plus-circle me-1"></i>เพิ่ม Prompt</a>
    </div>

    <div class="row g-3 mb-4">
        <?php foreach ([
            ['all', 'ทั้งหมด', $counts['total'], 'collection', 'primary', null],
            ['draft', 'ฉบับร่าง', $counts['draft'], 'pencil-square', 'secondary', 'draft'],
            ['pending', 'รอตรวจสอบ', $counts['pending'], 'hourglass-split', 'warning', 'pending'],
            ['published', 'เผยแพร่', $counts['published'], 'globe', 'success', 'published'],
            ['rejected', 'ไม่อนุมัติ', $counts['rejected'], 'x-octagon', 'danger', 'rejected'],
        ] as [$key, $label, $n, $icon, $color, $status]): ?>
            <div class="col-6 col-md">
                <a class="card metric-card text-decoration-none h-100" href="<?= e(url('my.prompts', ['status' => $status])) ?>">
                    <div class="card-body">
                        <i class="bi bi-<?= e($icon) ?> text-<?= e($color) ?> fs-4"></i>
                        <div class="metric-value"><?= number_th((int) $n) ?></div>
                        <div class="small text-secondary"><?= e($label) ?></div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($badge): ?>
        <section class="card points-card mb-4" aria-labelledby="pointsHeading">
            <div class="card-body d-flex flex-column flex-md-row gap-3 align-items-md-center">
                <div class="points-total text-center flex-shrink-0">
                    <div class="metric-value" id="pointsValue"><?= number_th($badge['points']) ?></div>
                    <div class="small text-secondary">แต้มสะสม</div>
                </div>
                <div class="flex-grow-1">
                    <h2 class="h5 mb-1" id="pointsHeading">
                        <?php if ($badge['tier']): ?>
                            <?= medal_badge($badge['points']) ?>
                        <?php else: ?>
                            <span class="text-secondary">ยังไม่มีเหรียญ</span>
                        <?php endif; ?>
                    </h2>
                    <?php if ($badge['next']): ?>
                        <p class="small text-secondary mb-2" id="pointsNext">อีก <strong class="text-body"><?= number_th($badge['remaining']) ?></strong> แต้ม จะได้รับ<strong class="medal-text-<?= e($badge['next']['key']) ?>"><?= e($badge['next']['label']) ?></strong></p>
                        <div class="progress" role="progressbar" aria-label="ความคืบหน้าสู่<?= e($badge['next']['label']) ?>" aria-valuenow="<?= (int) $badge['percent'] ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar points-bar"></div>
                        </div>
                    <?php else: ?>
                        <p class="small text-secondary mb-0" id="pointsNext">ยอดเยี่ยม! คุณได้รับเหรียญสูงสุดแล้ว</p>
                    <?php endif; ?>
                    <div class="small text-secondary mt-2">
                        ได้ 1 แต้มต่อ Prompt สาธารณะที่ผ่านการอนุมัติ ·
                        <?php foreach (array_reverse(\PromptHub\Services\BadgeService::TIERS) as $t): ?>
                            <span class="me-2 text-nowrap"><i class="bi bi-award-fill medal-text-<?= e($t['key']) ?>"></i> <?= e($t['label']) ?> <?= (int) $t['min'] ?><?= $t['max'] ? '–' . (int) $t['max'] : '+' ?></span>
                        <?php endforeach; ?>
                        <a href="<?= e(url('leaders')) ?>">ดูทำเนียบ</a>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h5 mb-0">แก้ไขล่าสุด</h2>
        <a href="<?= e(url('my.prompts')) ?>">ดูทั้งหมด <i class="bi bi-arrow-right"></i></a>
    </div>
    <?php if (!$recent): ?>
        <?= $view->partial('partials/empty', [
            'icon' => 'journal-plus',
            'heading' => 'คุณยังไม่มี Prompt',
            'text' => 'เริ่มเก็บ Prompt ที่ใช้บ่อยไว้เป็นส่วนตัว หรือแบ่งปันให้ผู้อื่น',
            'actionUrl' => url('my.prompt.form'),
            'actionLabel' => 'เพิ่ม Prompt แรก',
        ]) ?>
    <?php else: ?>
        <div class="list-group">
            <?php foreach ($recent as $p): ?>
                <a class="list-group-item list-group-item-action d-flex flex-wrap justify-content-between gap-2" href="<?= e(url('prompt', ['id' => $p['id']])) ?>">
                    <span>
                        <span class="fw-semibold"><?= e($p['title']) ?></span>
                        <span class="d-block small text-secondary"><?= e($p['category_name']) ?> · แก้ไข <?= e(thai_date($p['updated_at'])) ?></span>
                        <?php if ($p['status'] === 'rejected' && $p['rejection_reason']): ?>
                            <span class="d-block small text-danger"><i class="bi bi-chat-left-text me-1"></i><?= e(str_limit($p['rejection_reason'], 120)) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="d-flex gap-1 align-items-start"><?= status_badge($p['status']) ?><span class="badge text-bg-light border"><?= e(visibility_label($p['visibility'])) ?></span></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
