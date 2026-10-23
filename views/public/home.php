<?php
$siteName = $app->setting('site_name', 'Prompt Hub');
$badge = $app->setting('hero_badge');
?>
<section class="hero">
    <div class="container">
        <div class="hero-inner">
            <?php if ($badge !== ''): ?>
                <span class="hero-badge"><i class="bi bi-magic me-1"></i><?= e($badge) ?></span>
            <?php endif; ?>
            <h1 class="hero-title"><?= e($siteName) ?></h1>
            <p class="hero-slogan"><?= e($app->setting('site_slogan')) ?></p>
            <p class="hero-lead"><?= e($app->setting('site_description')) ?></p>

            <div class="hero-stats">
                <div class="stat-card"><div class="stat-value"><?= number_th($stats['prompts'] ?? 0) ?></div><div class="stat-label">Prompts</div></div>
                <div class="stat-card"><div class="stat-value"><?= number_th($stats['views'] ?? 0) ?></div><div class="stat-label">เข้าชม</div></div>
                <div class="stat-card"><div class="stat-value"><?= number_th($stats['copies'] ?? 0) ?></div><div class="stat-label">คัดลอก</div></div>
                <div class="stat-card"><div class="stat-value"><?= number_th($stats['categories'] ?? 0) ?></div><div class="stat-label">หมวดหมู่</div></div>
            </div>

            <form class="hero-search" method="get" action="<?= e($app->baseUrl() . '/index.php') ?>" role="search">
                <input type="hidden" name="r" value="prompts">
                <label class="visually-hidden" for="heroSearch">ค้นหา Prompt</label>
                <div class="input-group input-group-lg">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input id="heroSearch" class="form-control" type="search" name="q" maxlength="100" placeholder="ค้นหา เช่น แผนการสอน แคปชัน สรุปประชุม">
                    <button class="btn btn-warning fw-semibold" type="submit">ค้นหา</button>
                </div>
            </form>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <a class="btn btn-light fw-semibold" href="<?= e(url('prompts')) ?>"><i class="bi bi-compass me-1"></i>สำรวจ Prompt ทั้งหมด</a>
                <?php if (!$auth->check()): ?>
                    <a class="btn btn-outline-light" href="<?= e(url('register')) ?>"><i class="bi bi-person-plus me-1"></i>สมัครเพื่อแบ่งปัน Prompt</a>
                <?php else: ?>
                    <a class="btn btn-outline-light" href="<?= e(url('my.prompt.form')) ?>"><i class="bi bi-plus-circle me-1"></i>เพิ่ม Prompt ของคุณ</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<div class="container">
    <section class="mt-5" aria-labelledby="catHeading">
        <div class="section-head">
            <h2 id="catHeading" class="h4 mb-0">หมวดหมู่</h2>
            <a href="<?= e(url('categories')) ?>">ดูทั้งหมด <i class="bi bi-arrow-right"></i></a>
        </div>
        <?php if (!$categories): ?>
            <?= $view->partial('partials/empty', ['icon' => 'grid', 'heading' => 'ยังไม่มีหมวดหมู่']) ?>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($categories as $c): ?>
                    <div class="col-6 col-md-4 col-lg-3">
                        <a class="category-tile" href="<?= e(url('category', ['id' => $c['id']])) ?>">
                            <span class="category-icon"><i class="bi bi-<?= e(icon_name($c['icon'])) ?>"></i></span>
                            <span class="fw-semibold"><?= e($c['name']) ?></span>
                            <span class="small text-secondary"><?= number_th((int) $c['public_count']) ?> Prompt</span>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="mt-5" aria-labelledby="latestHeading">
        <div class="section-head">
            <h2 id="latestHeading" class="h4 mb-0">Prompt ล่าสุด</h2>
            <a href="<?= e(url('prompts')) ?>">ดูทั้งหมด <i class="bi bi-arrow-right"></i></a>
        </div>
        <?php if (!$latest): ?>
            <?= $view->partial('partials/empty', [
                'icon' => 'lightbulb',
                'heading' => 'ยังไม่มี Prompt ที่เผยแพร่',
                'text' => 'เป็นคนแรกที่แบ่งปัน Prompt ดี ๆ ให้ทุกคน',
                'actionUrl' => url($auth->check() ? 'my.prompt.form' : 'register'),
                'actionLabel' => 'เพิ่ม Prompt',
            ]) ?>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($latest as $p): ?>
                    <div class="col-md-6 col-lg-4"><?= $view->partial('partials/prompt_card', ['p' => $p]) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($popular): ?>
        <section class="mt-5" aria-labelledby="popularHeading">
            <div class="section-head">
                <h2 id="popularHeading" class="h4 mb-0">คัดลอกมากที่สุด</h2>
                <a href="<?= e(url('prompts', ['sort' => 'copies'])) ?>">ดูทั้งหมด <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="list-group popular-list">
                <?php foreach ($popular as $i => $p): ?>
                    <a class="list-group-item list-group-item-action d-flex align-items-center gap-3" href="<?= e(url('prompt', ['id' => $p['id']])) ?>">
                        <span class="rank"><?= $i + 1 ?></span>
                        <span class="flex-grow-1">
                            <span class="d-block fw-semibold"><?= e($p['title']) ?></span>
                            <span class="small text-secondary"><?= e($p['category_name']) ?></span>
                        </span>
                        <span class="small text-secondary text-nowrap"><i class="bi bi-clipboard-check me-1"></i><?= number_th((int) $p['copy_count']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($tags): ?>
        <section class="mt-5" aria-labelledby="tagHeading">
            <h2 id="tagHeading" class="h4">แท็กยอดนิยม</h2>
            <div class="tag-list">
                <?php foreach ($tags as $t): ?>
                    <a class="tag tag-lg" href="<?= e(url('prompts', ['tag' => $t['id']])) ?>">#<?= e($t['name']) ?> <span class="text-secondary">(<?= (int) $t['n'] ?>)</span></a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
