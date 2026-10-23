<div class="container py-4">
    <h1 class="h3 mb-1"><i class="bi bi-heart text-danger me-1"></i>รายการโปรด</h1>
    <p class="text-secondary">Prompt สาธารณะที่คุณบันทึกไว้</p>
    <?php if ($hidden > 0): ?>
        <div class="alert alert-light border small"><i class="bi bi-eye-slash me-1"></i>มี <?= (int) $hidden ?> รายการที่ถูกซ่อน เพราะเจ้าของกำลังแก้ไขหรือยังไม่ได้เผยแพร่อีกครั้ง จะกลับมาแสดงเมื่อได้รับอนุมัติ</div>
    <?php endif; ?>
    <?php if (!$rows): ?>
        <?= $view->partial('partials/empty', [
            'icon' => 'heart',
            'heading' => 'ยังไม่มีรายการโปรด',
            'text' => 'กดปุ่ม “บันทึกเป็นรายการโปรด” ในหน้ารายละเอียด Prompt',
            'actionUrl' => url('prompts'),
            'actionLabel' => 'สำรวจ Prompt',
        ]) ?>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($rows as $p): ?>
                <div class="col-md-6 col-lg-4">
                    <?= $view->partial('partials/prompt_card', ['p' => $p]) ?>
                    <form method="post" action="<?= e(url('favorite.toggle')) ?>" class="mt-1 text-end" data-confirm="นำ “<?= e($p['title']) ?>” ออกจากรายการโปรด?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                        <input type="hidden" name="op" value="remove">
                        <input type="hidden" name="back" value="favorites">
                        <button class="btn btn-link btn-sm text-danger" type="submit"><i class="bi bi-heartbreak me-1"></i>ยกเลิกรายการโปรด</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <?= $view->partial('partials/pagination', ['pager' => $pager, 'routeName' => 'favorites', 'params' => []]) ?>
    <?php endif; ?>
</div>
