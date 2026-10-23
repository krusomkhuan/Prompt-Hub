<?php $siteName = $app->setting('site_name', 'Prompt Hub'); ?>
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <h1 class="h3">เกี่ยวกับ <?= e($siteName) ?></h1>
            <p class="lead text-secondary"><?= e($app->setting('site_slogan')) ?> — <?= e($app->setting('site_description')) ?></p>

            <div class="row g-3 my-3">
                <div class="col-md-4"><div class="card h-100"><div class="card-body">
                    <i class="bi bi-search fs-3 text-primary"></i>
                    <h2 class="h6 mt-2">ค้นหาและกรอง</h2>
                    <p class="small text-secondary mb-0">ค้นจากชื่อ คำอธิบาย เนื้อหา และแท็ก กรองตามหมวดหมู่และเครื่องมือ AI</p>
                </div></div></div>
                <div class="col-md-4"><div class="card h-100"><div class="card-body">
                    <i class="bi bi-input-cursor-text fs-3 text-primary"></i>
                    <h2 class="h6 mt-2">เติมตัวแปรแล้วคัดลอก</h2>
                    <p class="small text-secondary mb-0">Prompt ที่มี [ตัวแปร] จะมีช่องให้กรอก ระบบสร้างข้อความพร้อมใช้โดยไม่แก้ต้นฉบับ</p>
                </div></div></div>
                <div class="col-md-4"><div class="card h-100"><div class="card-body">
                    <i class="bi bi-shield-check fs-3 text-primary"></i>
                    <h2 class="h6 mt-2">ตรวจก่อนเผยแพร่</h2>
                    <p class="small text-secondary mb-0">Prompt สาธารณะทุกรายการผ่านการตรวจโดยผู้ดูแลก่อนแสดงต่อทุกคน</p>
                </div></div></div>
            </div>

            <h2 class="h5 mt-4">วงจรของ Prompt</h2>
            <ol class="lifecycle">
                <li><?= status_badge('draft') ?> สมาชิกสร้าง Prompt เก็บเป็นส่วนตัวหรือฉบับร่าง</li>
                <li><?= status_badge('pending') ?> เลือก “สาธารณะ” แล้วส่งตรวจ</li>
                <li><?= status_badge('published') ?> ผู้ดูแลอนุมัติ Prompt จึงปรากฏหน้าบ้าน</li>
                <li><?= status_badge('rejected') ?> หากไม่อนุมัติ เจ้าของจะเห็นเหตุผลและแก้ไขส่งใหม่ได้</li>
            </ol>
            <p class="small text-secondary">เมื่อแก้ไข Prompt ที่เผยแพร่แล้ว ระบบจะพักการเผยแพร่และกลับไปรอตรวจสอบอีกครั้ง</p>

            <h2 class="h5 mt-4">ข้อควรระวัง</h2>
            <ul class="small">
                <li>ห้ามใส่รหัสผ่าน ข้อมูลส่วนบุคคล หรือข้อมูลลับขององค์กรลงใน Prompt</li>
                <li>ตรวจสอบความถูกต้องของผลลัพธ์จาก AI ทุกครั้งก่อนนำไปใช้จริง</li>
                <li>ข้อมูลที่มีป้าย “ข้อมูลสาธิต” สร้างขึ้นเพื่อทดลองระบบเท่านั้น</li>
            </ul>
        </div>
    </div>
</div>
