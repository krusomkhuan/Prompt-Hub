<?php use PromptHub\Services\GoogleDriveService; ?>
<div class="container py-4">
    <?= $view->partial('partials/admin_nav') ?>
    <h1 class="h3 mb-1"><i class="bi bi-cloud-arrow-up me-2 text-primary"></i>Google Drive</h1>
    <p class="text-secondary">ที่เก็บภาพตัวอย่างผลลัพธ์ของ Prompt — อัปโหลดสำเนาขึ้น Drive และหน้าเว็บแสดงภาพจาก Drive ไฟล์ในเครื่องเก็บไว้เป็นข้อมูลสำรอง</p>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card mb-4" id="driveStatus">
                <div class="card-body">
                    <h2 class="h5 mb-3">สถานะการเชื่อมต่อ</h2>
                    <?php if ($active): ?>
                        <p class="mb-2"><span class="badge text-bg-success me-1"><i class="bi bi-check-circle me-1"></i>เชื่อมต่อแล้ว</span>
                            บัญชี <strong><?= e($drive['email']) ?></strong></p>
                        <dl class="row small mb-3">
                            <dt class="col-sm-4 text-secondary fw-normal">โฟลเดอร์หลัก</dt>
                            <dd class="col-sm-8"><a href="https://drive.google.com/drive/folders/<?= e(rawurlencode($drive['parent_folder_id'])) ?>" target="_blank" rel="noopener"><?= e($drive['parent_folder_name'] ?: $drive['parent_folder_id']) ?></a></dd>
                            <dt class="col-sm-4 text-secondary fw-normal">เก็บภาพในโฟลเดอร์ย่อย</dt>
                            <dd class="col-sm-8"><a href="https://drive.google.com/drive/folders/<?= e(rawurlencode($drive['folder_id'])) ?>" target="_blank" rel="noopener" id="driveSubfolder"><?= e(GoogleDriveService::SUBFOLDER_NAME) ?></a></dd>
                            <dt class="col-sm-4 text-secondary fw-normal">เชื่อมต่อเมื่อ</dt>
                            <dd class="col-sm-8"><?= e(thai_date($drive['connected_at'])) ?></dd>
                        </dl>
                        <?php if (($drive['parent_folder_id'] ?? '') !== $folderId): ?>
                            <div class="alert alert-warning small">โฟลเดอร์ที่บันทึกไว้ต่างจากโฟลเดอร์ที่เชื่อมต่ออยู่ กด “เชื่อมต่อใหม่” เพื่อเริ่มใช้โฟลเดอร์ใหม่</div>
                        <?php endif; ?>
                    <?php elseif (!empty($drive['needs_reconnect'])): ?>
                        <div class="alert alert-danger" id="driveReconnect"><i class="bi bi-exclamation-triangle me-1"></i>Google ยกเลิกสิทธิ์หรือการเชื่อมต่อหมดอายุ (บัญชี <?= e($drive['email'] ?? '') ?>) — ภาพใหม่เก็บในเครื่องไว้ก่อน กด “เชื่อมต่อใหม่” แล้วอัปโหลดภาพที่ค้าง</div>
                    <?php else: ?>
                        <p class="mb-3"><span class="badge text-bg-secondary">ยังไม่ได้เชื่อมต่อ</span> ภาพแสดงจากเครื่องเซิร์ฟเวอร์ตามเดิม</p>
                    <?php endif; ?>

                    <div class="d-flex flex-wrap gap-2">
                        <form method="post" action="<?= e(url('admin.drive.connect')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn-primary" type="submit" <?= $configured && $folderId !== '' ? '' : 'disabled' ?>>
                                <i class="bi bi-google me-1"></i><?= $drive ? 'เชื่อมต่อใหม่' : 'เชื่อมต่อ Google Drive' ?>
                            </button>
                        </form>
                        <?php if ($active): ?>
                            <form method="post" action="<?= e(url('admin.drive.sync')) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn-outline-primary" type="submit" <?= $onDrive < $total ? '' : 'disabled' ?>><i class="bi bi-cloud-upload me-1"></i>อัปโหลดภาพที่ยังไม่อยู่บน Drive</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($drive): ?>
                            <form method="post" action="<?= e(url('admin.drive.disconnect')) ?>" data-confirm="ยกเลิกการเชื่อมต่อ Google Drive? หน้าเว็บจะแสดงภาพจากสำเนาในเครื่อง ไฟล์บน Drive ไม่ถูกลบ">
                                <?= csrf_field() ?>
                                <button class="btn btn-outline-danger" type="submit"><i class="bi bi-x-circle me-1"></i>ยกเลิกการเชื่อมต่อ</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if (!$configured): ?>
                        <div class="alert alert-warning small mt-3 mb-0" id="driveNotConfigured"><i class="bi bi-gear me-1"></i>ยังไม่ได้ใส่ <code>client_id</code> / <code>client_secret</code> ในส่วน <code>drive</code> (หรือ <code>google</code>) ของ <code>config/config.php</code> — ดูขั้นตอนด้านขวา</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-4"><div class="card metric-card h-100"><div class="card-body"><div class="metric-value" id="driveTotal"><?= number_th($total) ?></div><div class="small text-secondary">ภาพทั้งหมด</div></div></div></div>
                <div class="col-4"><div class="card metric-card h-100"><div class="card-body"><div class="metric-value text-success" id="driveOnDrive"><?= number_th($onDrive) ?></div><div class="small text-secondary">อยู่บน Drive</div></div></div></div>
                <div class="col-4"><div class="card metric-card h-100"><div class="card-body"><div class="metric-value text-warning" id="drivePending"><?= number_th($total - $onDrive) ?></div><div class="small text-secondary">รออัปโหลด</div></div></div></div>
            </div>

            <form class="card card-body" method="post" action="<?= e(url('admin.drive.save')) ?>" novalidate>
                <?= csrf_field() ?>
                <label class="form-label fw-semibold" for="folder">โฟลเดอร์ Google Drive</label>
                <input class="form-control font-monospace" id="folder" name="folder" maxlength="300" required value="<?= e($folderId !== '' ? 'https://drive.google.com/drive/folders/' . $folderId : '') ?>"
                       placeholder="https://drive.google.com/drive/folders/..." autocomplete="off" spellcheck="false">
                <div class="form-text">วางลิงก์โฟลเดอร์ (หรือรหัสโฟลเดอร์) ระบบจะสร้างโฟลเดอร์ย่อย “<?= e(GoogleDriveService::SUBFOLDER_NAME) ?>” ไว้เก็บภาพ เพื่อแยกจากไฟล์อื่นในโฟลเดอร์</div>
                <div><button class="btn btn-outline-primary mt-2" type="submit"><i class="bi bi-save me-1"></i>บันทึกโฟลเดอร์</button></div>
            </form>
        </div>

        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-body small">
                    <h2 class="h6"><i class="bi bi-list-check me-1"></i>ตั้งค่าครั้งแรก (Google Cloud Console)</h2>
                    <ol class="ps-3 mb-2">
                        <li>APIs &amp; Services › Library › เปิดใช้ <strong>Google Drive API</strong></li>
                        <li>OAuth consent screen › เลือก <strong>Internal</strong> (บัญชีในโดเมนเดียวกัน token ไม่หมดอายุ) และเพิ่ม scope <code>…/auth/drive</code> — ถ้าเปิดให้สมาชิกทั่วไป (Gmail) สมัครด้วย Google ด้วย ให้สร้าง <strong>OAuth Client แยก</strong>: Client สำหรับเข้าสู่ระบบเป็น External (In production) และ Client สำหรับ Drive เป็น Internal</li>
                        <li>Credentials › OAuth client ID (Web application) › Authorized redirect URIs เพิ่ม:
                            <div class="font-monospace text-break bg-body-tertiary rounded p-2 my-1" id="driveRedirectUri"><?= e($redirectUri) ?></div></li>
                        <li>ใส่ Client ID / Secret ในส่วน <code>drive</code> ของ <code>config/config.php</code> (<code>'drive' => ['client_id' => '…', 'client_secret' => '…']</code>) — ถ้าไม่ใส่ ใช้ค่าจากส่วน <code>google</code></li>
                        <li>กด “เชื่อมต่อ Google Drive” แล้วเข้าสู่ระบบด้วย <strong>บัญชีเจ้าของโฟลเดอร์</strong></li>
                    </ol>
                    <p class="text-secondary mb-0">ถ้าเลือก External และแอปยังอยู่ในโหมด Testing Google จะให้เชื่อมต่อใหม่ทุก 7 วัน</p>
                </div>
            </div>
            <div class="card">
                <div class="card-body small">
                    <h2 class="h6"><i class="bi bi-shield-exclamation me-1 text-warning"></i>ข้อควรรู้</h2>
                    <ul class="ps-3 mb-0">
                        <li>ไฟล์ภาพบน Drive ตั้งเป็น <strong>ทุกคนที่มีลิงก์ดูได้</strong> เพื่อให้หน้าเว็บลิงก์ภาพได้โดยตรง — ภาพของ Prompt ส่วนตัว/รอตรวจสอบจึงเปิดได้ถ้ามีลิงก์ไฟล์ และผู้ที่มีลิงก์โฟลเดอร์จะเห็นภาพทั้งหมด</li>
                        <li>ลบภาพหรือ Prompt ในเว็บ → ไฟล์บน Drive ถูกย้ายไป <strong>ถังขยะ</strong> (กู้คืนได้ใน Drive)</li>
                        <li>ภาพทุกภาพยังมีสำเนาใน <code>storage/uploads/prompts</code> ถ้าโหลดภาพจาก Drive ไม่ได้ หน้าเว็บใช้สำเนาในเครื่องแทนอัตโนมัติ</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
