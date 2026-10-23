<?php
$me = (int) $auth->id();
$keep = ['q' => $filters['q'], 'role' => $filters['role'], 'status' => $filters['status']];
$back = static function () use ($keep, $pager): string {
    $h = '';
    foreach ($keep + ['page' => $pager['page'] > 1 ? (string) $pager['page'] : ''] as $k => $v) {
        if ($v !== '') {
            $h .= '<input type="hidden" name="back_' . e($k) . '" value="' . e($v) . '">';
        }
    }
    return $h;
};
$actionForm = static function (int $id, string $op, string $label, string $cls, string $icon, string $confirm = '') use ($back): string {
    return '<form method="post" action="' . e(url('admin.user.action')) . '" class="d-inline"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
        . csrf_field() . $back()
        . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="op" value="' . e($op) . '">'
        . '<button class="btn btn-sm ' . $cls . '" type="submit" title="' . e($label) . '"><i class="bi bi-' . e($icon) . ' me-1"></i>' . e($label) . '</button></form>';
};
?>
<div class="container py-4">
    <?= $view->partial('partials/admin_nav') ?>
    <h1 class="h3 mb-3">จัดการสมาชิก</h1>

    <form class="row g-2 mb-3" method="get" action="<?= e($app->baseUrl() . '/index.php') ?>">
        <input type="hidden" name="r" value="admin.users">
        <div class="col-md-5"><input class="form-control" type="search" name="q" maxlength="100" value="<?= e($filters['q']) ?>" placeholder="ค้นหาชื่อผู้ใช้ อีเมล หรือชื่อที่แสดง" aria-label="ค้นหาสมาชิก"></div>
        <div class="col-6 col-md-3">
            <select class="form-select" name="role" aria-label="บทบาท">
                <option value="">ทุกบทบาท</option>
                <option value="member"<?= $filters['role'] === 'member' ? ' selected' : '' ?>>สมาชิก</option>
                <option value="admin"<?= $filters['role'] === 'admin' ? ' selected' : '' ?>>ผู้ดูแล</option>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <select class="form-select" name="status" aria-label="สถานะ">
                <option value="">ทุกสถานะ</option>
                <option value="active"<?= $filters['status'] === 'active' ? ' selected' : '' ?>>ใช้งาน</option>
                <option value="suspended"<?= $filters['status'] === 'suspended' ? ' selected' : '' ?>>ระงับ</option>
            </select>
        </div>
        <div class="col-md-1 d-grid"><button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button></div>
    </form>

    <p class="small text-secondary">พบ <?= number_th($pager['total']) ?> บัญชี</p>
    <?php if (!$rows): ?>
        <?= $view->partial('partials/empty', ['icon' => 'people', 'heading' => 'ไม่พบสมาชิกตามเงื่อนไข', 'actionUrl' => url('admin.users'), 'actionLabel' => 'ล้างการค้นหา']) ?>
    <?php else: ?>
        <div class="table-responsive card">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>สมาชิก</th><th>บทบาท / สถานะ</th><th class="d-none d-md-table-cell">Prompt</th><th class="d-none d-md-table-cell">แต้ม / เหรียญ</th><th class="d-none d-lg-table-cell">เข้าสู่ระบบล่าสุด</th><th class="text-end">จัดการ</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $u): $id = (int) $u['id']; $self = $id === $me; ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                            <?= avatar($id, $u['display_name'], $u['avatar_file'], 'md') ?>
                            <div>
                            <div class="fw-semibold"><?= e($u['display_name']) ?><?= $self ? ' <span class="badge text-bg-info">คุณ</span>' : '' ?><?= $u['google_sub'] !== null ? ' <span class="badge text-bg-light border" title="เข้าสู่ระบบด้วย Google ได้"><i class="bi bi-google"></i></span>' : '' ?></div>
                            <div class="small text-secondary"><?= e($u['username']) ?> · <span class="text-break"><?= e($u['email']) ?></span></div>
                            </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?= $u['role'] === 'admin' ? 'text-bg-primary' : 'text-bg-light border' ?>"><?= $u['role'] === 'admin' ? 'ผู้ดูแล' : 'สมาชิก' ?></span>
                            <span class="badge <?= $u['status'] === 'active' ? 'text-bg-success' : 'text-bg-danger' ?>"><?= $u['status'] === 'active' ? 'ใช้งาน' : 'ระงับ' ?></span>
                        </td>
                        <td class="d-none d-md-table-cell"><?= number_th((int) $u['prompt_count']) ?></td>
                        <td class="d-none d-md-table-cell text-nowrap"><?php if ($u['role'] === 'member'): ?><?= number_th((int) $u['published_count']) ?> <?= medal_badge((int) $u['published_count']) ?><?php else: ?><span class="text-secondary">-</span><?php endif; ?></td>
                        <td class="d-none d-lg-table-cell small text-secondary"><?= e(thai_date($u['last_login_at'])) ?></td>
                        <td class="text-end">
                            <?php if ($self): ?>
                                <span class="small text-secondary">บัญชีที่กำลังใช้งาน</span>
                            <?php else: ?>
                                <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                    <?= $u['status'] === 'active'
                                        ? $actionForm($id, 'suspend', 'ระงับ', 'btn-outline-warning', 'pause-circle', 'ระงับบัญชี ' . $u['username'] . '? ผู้ใช้จะถูกออกจากระบบทันที')
                                        : $actionForm($id, 'activate', 'เปิดใช้', 'btn-outline-success', 'play-circle') ?>
                                    <?= $u['role'] === 'admin'
                                        ? $actionForm($id, 'make_member', 'ลดเป็นสมาชิก', 'btn-outline-secondary', 'person-dash', 'ลดสิทธิ์ ' . $u['username'] . ' เป็นสมาชิกทั่วไป?')
                                        : $actionForm($id, 'make_admin', 'ตั้งเป็นผู้ดูแล', 'btn-outline-primary', 'person-gear', 'ให้สิทธิ์ผู้ดูแลระบบแก่ ' . $u['username'] . '?') ?>
                                    <?php if ($u['avatar_file'] !== null): ?>
                                        <?= $actionForm($id, 'remove_avatar', 'ลบภาพ', 'btn-outline-secondary', 'person-x', 'ลบภาพโปรไฟล์ของ ' . $u['username'] . '? (เช่น ภาพไม่เหมาะสม)') ?>
                                    <?php endif; ?>
                                    <?= $actionForm($id, 'delete', 'ลบ', 'btn-outline-danger', 'trash', 'ลบบัญชี ' . $u['username'] . ' ถาวร? Prompt ' . (int) $u['prompt_count'] . ' รายการและรายการโปรดของบัญชีนี้จะถูกลบด้วย') ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $view->partial('partials/pagination', ['pager' => $pager, 'routeName' => 'admin.users', 'params' => $keep]) ?>
    <?php endif; ?>
</div>
