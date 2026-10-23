<?php
/** การ์ด Prompt: $p (แถวจาก PromptRepository), $showStatus (bool) */
$showStatus = $showStatus ?? false;
?>
<article class="card prompt-card h-100">
    <?php if (!empty($p['first_image_id'])): ?>
        <a href="<?= e(url('prompt', ['id' => $p['id']])) ?>" tabindex="-1" aria-hidden="true">
            <img class="prompt-thumb" <?= prompt_image_attrs((int) $p['first_image_id'], $p['first_image_drive'] ?? null, 800) ?> alt="" loading="lazy">
        </a>
    <?php endif; ?>
    <div class="card-body d-flex flex-column">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
            <a class="badge rounded-pill category-pill text-decoration-none" href="<?= e(url('category', ['id' => $p['category_id']])) ?>">
                <i class="bi bi-<?= e(icon_name((string) $p['category_icon'])) ?> me-1"></i><?= e($p['category_name']) ?>
            </a>
            <?php if ($showStatus): ?>
                <span class="d-flex gap-1 flex-wrap justify-content-end">
                    <?= status_badge($p['status']) ?>
                    <span class="badge text-bg-light border"><i class="bi bi-<?= $p['visibility'] === 'public' ? 'globe' : 'lock' ?> me-1"></i><?= e(visibility_label($p['visibility'])) ?></span>
                </span>
            <?php endif; ?>
        </div>
        <h3 class="h5 card-title mb-2"><a class="stretched-link-title" href="<?= e(url('prompt', ['id' => $p['id']])) ?>"><?= e($p['title']) ?></a></h3>
        <p class="card-text text-secondary small flex-grow-1"><?= e(str_limit($p['description'], 140)) ?></p>
        <?php if ($p['tags']): ?>
            <div class="tag-list mb-2">
                <?php foreach (array_slice($p['tags'], 0, 4) as $t): ?>
                    <a class="tag" href="<?= e(url('prompts', ['tag' => $t['id']])) ?>">#<?= e($t['name']) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($p['tools']): ?>
            <div class="small text-secondary mb-3"><i class="bi bi-cpu me-1"></i><?= e(implode(' · ', array_column($p['tools'], 'name'))) ?></div>
        <?php endif; ?>
        <div class="d-flex justify-content-between align-items-center mt-auto pt-2 border-top">
            <span class="small text-secondary" title="จำนวนครั้งที่คัดลอก / เข้าชม">
                <i class="bi bi-clipboard-check me-1"></i><?= number_th((int) $p['copy_count']) ?>
                <i class="bi bi-eye ms-2 me-1"></i><?= number_th((int) $p['view_count']) ?>
            </span>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('prompt', ['id' => $p['id']])) ?>">ดูรายละเอียด <i class="bi bi-arrow-right-short"></i></a>
        </div>
    </div>
</article>
