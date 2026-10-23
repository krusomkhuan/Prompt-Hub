<?php $actions = \PromptHub\Repositories\ActivityLogRepository::ACTIONS; ?>
<div class="container py-4">
    <?= $view->partial('partials/admin_nav') ?>
    <h1 class="h3 mb-3">บันทึกกิจกรรมผู้ดูแล</h1>
    <form class="row g-2 mb-3" method="get" action="<?= e($app->baseUrl() . '/index.php') ?>">
        <input type="hidden" name="r" value="admin.logs">
        <div class="col-md-5">
            <select class="form-select" name="action" aria-label="ประเภทกิจกรรม">
                <option value="">ทุกกิจกรรม</option>
                <?php foreach ($actions as $k => $label): ?>
                    <option value="<?= e($k) ?>"<?= $action === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-grid"><button class="btn btn-primary" type="submit">กรอง</button></div>
    </form>
    <?php if (!$rows): ?>
        <?= $view->partial('partials/empty', ['icon' => 'journal-text', 'heading' => 'ยังไม่มีบันทึกกิจกรรม']) ?>
    <?php else: ?>
        <div class="table-responsive card">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>เวลา</th><th>ผู้ดูแล</th><th>กิจกรรม</th><th>รายละเอียด</th><th class="d-none d-md-table-cell">IP</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $l): ?>
                    <tr>
                        <td class="small text-nowrap"><?= e(thai_date($l['created_at'])) ?></td>
                        <td class="small"><?= e($l['admin_username']) ?><?= $l['admin_id'] === null ? ' <span class="text-secondary">(ลบแล้ว)</span>' : '' ?></td>
                        <td class="small"><?= e($actions[$l['action']] ?? $l['action']) ?></td>
                        <td class="small text-break"><?= e($l['target_type']) ?><?= $l['target_id'] !== null ? ' #' . (int) $l['target_id'] : '' ?> <?= e($l['details']) ?></td>
                        <td class="small text-secondary d-none d-md-table-cell"><?= e($l['ip_address']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $view->partial('partials/pagination', ['pager' => $pager, 'routeName' => 'admin.logs', 'params' => ['action' => $action]]) ?>
    <?php endif; ?>
</div>
