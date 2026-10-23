<?php
declare(strict_types=1);

namespace PromptHub\Controllers;

use PromptHub\Core\HttpException;
use PromptHub\Core\ValidationException;
use PromptHub\Core\Validator;
use PromptHub\Repositories\ActivityLogRepository;
use PromptHub\Repositories\AiToolRepository;
use PromptHub\Repositories\CategoryRepository;
use PromptHub\Repositories\PromptRepository;
use PromptHub\Repositories\SettingRepository;
use PromptHub\Repositories\TagRepository;
use PromptHub\Repositories\UserRepository;
use PromptHub\Services\AdminService;
use PromptHub\Services\GoogleAuthService;
use PromptHub\Services\GoogleDriveService;
use PromptHub\Services\PromptService;

/** หลังบ้าน (Router ตรวจสิทธิ์ผู้ดูแลก่อนเรียกทุกเมธอด และตรวจซ้ำที่ requireAdmin) */
final class AdminController extends Controller
{
    private function admin(): array
    {
        return $this->auth->requireAdmin();
    }

    private function logs(): ActivityLogRepository
    {
        return new ActivityLogRepository($this->db);
    }

    /** แปลงข้อผิดพลาดของฐานข้อมูลที่คาดได้เป็นข้อความสำหรับผู้ใช้ */
    private function guard(callable $fn, string $back, array $params = []): void
    {
        try {
            $msg = $fn();
            if (is_string($msg) && $msg !== '') {
                $this->flash('success', $msg);
            }
        } catch (ValidationException $e) {
            $this->flash('danger', implode(' ', $e->errors()));
        } catch (\mysqli_sql_exception $e) {
            if ($e->getCode() === 1062) {
                $this->flash('danger', 'ชื่อนี้มีอยู่แล้ว กรุณาใช้ชื่ออื่น');
            } elseif ($e->getCode() === 1451) {
                $this->flash('danger', 'ข้อมูลนี้ยังถูกใช้งานอยู่ จึงลบไม่ได้');
            } else {
                throw $e;
            }
        }
        redirect($back, $params);
    }

    public function dashboard(): void
    {
        $this->admin();
        $prompts = new PromptRepository($this->db);
        [$pending] = $prompts->search(['status' => 'pending', 'sort' => 'updated'], 'admin', 0, 1, 8);
        [$logs] = $this->logs()->search('', 1, 8);
        $this->render('admin/dashboard', [
            'title' => 'ภาพรวมหลังบ้าน',
            'users' => (new UserRepository($this->db))->counts(),
            'prompts' => $prompts->adminCounts(),
            'categoryCount' => (new CategoryRepository($this->db))->count(),
            'pending' => $pending,
            'logs' => $logs,
        ]);
    }

    // ---------- สมาชิก ----------

    public function users(): void
    {
        $this->admin();
        $q = $this->query('q', 100);
        $role = $this->queryIn('role', ['member', 'admin']);
        $status = $this->queryIn('status', ['active', 'suspended']);
        $page = $this->page();
        [$rows, $total] = (new UserRepository($this->db))->search($q, $role, $status, $page, 20);
        $this->render('admin/users', [
            'title' => 'จัดการสมาชิก',
            'rows' => $rows,
            'filters' => compact('q', 'role', 'status'),
            'pager' => self::pagination($total, $page, 20),
        ]);
    }

    public function userAction(): void
    {
        $admin = $this->admin();
        $id = $this->postId();
        $op = $this->postString('op');
        if (!in_array($op, ['suspend', 'activate', 'make_admin', 'make_member', 'remove_avatar', 'delete'], true)) {
            throw new HttpException(400, 'คำสั่งไม่ถูกต้อง');
        }
        $this->guard(fn() => (new AdminService($this->db))->userAction($admin, $id, $op), 'admin.users', $this->backParams());
    }

    /** คงค่าค้นหาและหน้าปัจจุบันหลังทำรายการ */
    private function backParams(): array
    {
        $p = [];
        foreach (['q', 'role', 'status', 'page', 'visibility'] as $k) {
            $v = $_POST['back_' . $k] ?? '';
            if (is_string($v) && $v !== '' && mb_strlen($v) <= 100) {
                $p[$k] = $v;
            }
        }
        return $p;
    }

    // ---------- หมวดหมู่ ----------

    public function categories(): void
    {
        $this->admin();
        $repo = new CategoryRepository($this->db);
        $editId = $this->queryId('edit');
        $this->render('admin/categories', [
            'title' => 'จัดการหมวดหมู่',
            'rows' => $repo->allWithCounts(),
            'edit' => $editId ? $repo->find($editId) : null,
        ]);
    }

    public function categorySave(): void
    {
        $admin = $this->admin();
        $id = !empty($_POST['id']) ? $this->postId() : 0;
        $this->guard(function () use ($admin, $id) {
            $v = new Validator($_POST);
            $name = $v->string('name', 'ชื่อหมวดหมู่', 2, 100);
            $desc = $v->string('description', 'คำอธิบาย', 0, 255);
            $icon = icon_name($v->string('icon', 'ไอคอน', 0, 50) ?: 'folder');
            $sort = $v->int('sort_order', 'ลำดับแสดงผล', -100000, 100000);
            $active = $v->bool('is_active');
            $allowImages = $v->bool('allow_images');
            $v->validate();
            $repo = new CategoryRepository($this->db);
            return $this->db->transaction(function () use ($repo, $admin, $id, $name, $desc, $icon, $sort, $active, $allowImages) {
                if ($id) {
                    if (!$repo->find($id)) {
                        throw new HttpException(404, 'ไม่พบหมวดหมู่');
                    }
                    $repo->update($id, $name, $desc, $icon, $active, $sort, $allowImages);
                    $this->logs()->log($admin, 'category.update', 'category', $id, $name . ($active ? '' : ' (ปิดใช้งาน)'));
                    return "บันทึกหมวดหมู่ “{$name}” แล้ว";
                }
                $newId = $repo->create($name, $desc, $icon, $active, $sort, $allowImages);
                $this->logs()->log($admin, 'category.create', 'category', $newId, $name);
                return "เพิ่มหมวดหมู่ “{$name}” แล้ว";
            });
        }, 'admin.categories');
    }

    public function categoryDelete(): void
    {
        $admin = $this->admin();
        $id = $this->postId();
        $moveTo = !empty($_POST['move_to']) ? $this->postId('move_to') : 0;
        $this->guard(fn() => (new AdminService($this->db))->deleteCategory($admin, $id, $moveTo), 'admin.categories');
    }

    // ---------- แท็ก ----------

    public function tags(): void
    {
        $this->admin();
        $repo = new TagRepository($this->db);
        $editId = $this->queryId('edit');
        $q = $this->query('q', 40);
        $this->render('admin/tags', [
            'title' => 'จัดการแท็ก',
            'rows' => $repo->allWithCounts($q),
            'q' => $q,
            'edit' => $editId ? $repo->find($editId) : null,
        ]);
    }

    public function tagSave(): void
    {
        $admin = $this->admin();
        $id = !empty($_POST['id']) ? $this->postId() : 0;
        $this->guard(function () use ($admin, $id) {
            $v = new Validator($_POST);
            $name = ltrim($v->string('name', 'ชื่อแท็ก', 1, 40), '#');
            if (str_contains($name, ',')) {
                $v->addError('name', 'ชื่อแท็กห้ามมีเครื่องหมายจุลภาค');
            }
            $v->validate();
            $repo = new TagRepository($this->db);
            return $this->db->transaction(function () use ($repo, $admin, $id, $name) {
                if ($id) {
                    if (!$repo->find($id)) {
                        throw new HttpException(404, 'ไม่พบแท็ก');
                    }
                    $repo->rename($id, $name);
                    $this->logs()->log($admin, 'tag.update', 'tag', $id, $name);
                    return "บันทึกแท็ก “{$name}” แล้ว";
                }
                $newId = $repo->create($name);
                $this->logs()->log($admin, 'tag.create', 'tag', $newId, $name);
                return "เพิ่มแท็ก “{$name}” แล้ว";
            });
        }, 'admin.tags');
    }

    public function tagDelete(): void
    {
        $admin = $this->admin();
        $id = $this->postId();
        $this->guard(function () use ($admin, $id) {
            $repo = new TagRepository($this->db);
            $tag = $repo->find($id);
            if (!$tag) {
                throw new HttpException(404, 'ไม่พบแท็ก');
            }
            $this->db->transaction(function () use ($repo, $admin, $tag) {
                $repo->delete((int) $tag['id']);
                $this->logs()->log($admin, 'tag.delete', 'tag', (int) $tag['id'], $tag['name']);
            });
            return "ลบแท็ก “{$tag['name']}” แล้ว (นำออกจาก Prompt ที่ใช้แท็กนี้)";
        }, 'admin.tags');
    }

    // ---------- เครื่องมือ AI ----------

    public function tools(): void
    {
        $this->admin();
        $repo = new AiToolRepository($this->db);
        $editId = $this->queryId('edit');
        $this->render('admin/tools', [
            'title' => 'จัดการเครื่องมือ AI',
            'rows' => $repo->allWithCounts(),
            'edit' => $editId ? $repo->find($editId) : null,
        ]);
    }

    public function toolSave(): void
    {
        $admin = $this->admin();
        $id = !empty($_POST['id']) ? $this->postId() : 0;
        $this->guard(function () use ($admin, $id) {
            $v = new Validator($_POST);
            $name = $v->string('name', 'ชื่อเครื่องมือ', 1, 80);
            $sort = $v->int('sort_order', 'ลำดับแสดงผล', -100000, 100000);
            $active = $v->bool('is_active');
            $v->validate();
            $repo = new AiToolRepository($this->db);
            return $this->db->transaction(function () use ($repo, $admin, $id, $name, $sort, $active) {
                if ($id) {
                    if (!$repo->find($id)) {
                        throw new HttpException(404, 'ไม่พบเครื่องมือ');
                    }
                    $repo->update($id, $name, $active, $sort);
                    $this->logs()->log($admin, 'tool.update', 'ai_tool', $id, $name);
                    return "บันทึกเครื่องมือ “{$name}” แล้ว";
                }
                $newId = $repo->create($name, $active, $sort);
                $this->logs()->log($admin, 'tool.create', 'ai_tool', $newId, $name);
                return "เพิ่มเครื่องมือ “{$name}” แล้ว";
            });
        }, 'admin.tools');
    }

    public function toolDelete(): void
    {
        $admin = $this->admin();
        $id = $this->postId();
        $this->guard(function () use ($admin, $id) {
            $repo = new AiToolRepository($this->db);
            $tool = $repo->find($id);
            if (!$tool) {
                throw new HttpException(404, 'ไม่พบเครื่องมือ');
            }
            $this->db->transaction(function () use ($repo, $admin, $tool) {
                $repo->delete((int) $tool['id']);
                $this->logs()->log($admin, 'tool.delete', 'ai_tool', (int) $tool['id'], $tool['name']);
            });
            return "ลบเครื่องมือ “{$tool['name']}” แล้ว";
        }, 'admin.tools');
    }

    // ---------- Prompt ----------

    public function prompts(): void
    {
        $this->admin();
        $filters = [
            'q' => $this->query('q', 100),
            'status' => $this->queryIn('status', ['draft', 'pending', 'published', 'rejected']),
            'visibility' => $this->queryIn('visibility', ['private', 'public']),
            'category' => $this->queryId('category'),
            'sort' => 'updated',
        ];
        $page = $this->page();
        [$rows, $total] = (new PromptRepository($this->db))->search($filters, 'admin', 0, $page, 20);
        $this->render('admin/prompts', [
            'title' => 'จัดการ Prompt',
            'rows' => $rows,
            'filters' => $filters,
            'pager' => self::pagination($total, $page, 20),
            'categories' => (new CategoryRepository($this->db))->allWithCounts(),
        ]);
    }

    public function moderate(): void
    {
        $admin = $this->admin();
        $id = $this->postId();
        $decision = $this->postString('decision') === 'approve' ? 'approve' : 'reject';
        try {
            $p = (new PromptService($this->db))->moderate($admin, $id, $decision, $this->postString('reason'));
            $this->flash('success', $decision === 'approve' ? "อนุมัติ “{$p['title']}” และเผยแพร่แล้ว" : "ไม่อนุมัติ “{$p['title']}” แล้ว เจ้าของจะเห็นเหตุผล");
        } catch (ValidationException $e) {
            $this->flash('danger', implode(' ', $e->errors()));
        }
        $this->postString('back') === 'list' ? redirect('admin.prompts', ['status' => 'pending']) : redirect('prompt', ['id' => $id]);
    }

    // ---------- ตั้งค่าและบันทึก ----------

    public function settings(): void
    {
        $this->admin();
        $this->render('admin/settings', ['title' => 'ตั้งค่าเว็บไซต์', 'errors' => [], 'old' => $this->app->settings]);
    }

    // ---------- เข้าสู่ระบบด้วย Google (เปิด/ปิด และ Client ID/Secret) ----------

    public function googleSave(): void
    {
        $admin = $this->admin();
        $v = new Validator($_POST);
        $enable = $v->bool('google_login_enabled');
        $id = trim($this->postString('google_client_id'));
        $secret = trim((string) ($_POST['google_client_secret'] ?? ''));
        $clear = $v->bool('google_clear');
        $settings = $this->app->settings;
        if ($clear) {
            $id = '';
            $secret = '';
        } else {
            if ($id !== '' && !preg_match('/^[A-Za-z0-9._-]{8,200}\.apps\.googleusercontent\.com$/D', $id)) {
                $v->addError('google_client_id', 'Client ID ต้องลงท้ายด้วย .apps.googleusercontent.com');
            }
            if ($secret === '') {
                $secret = (string) ($settings['google_client_secret'] ?? ''); // เว้นว่าง = ใช้ค่าเดิม
            } elseif (preg_match('/\s/', $secret) || strlen($secret) < 10 || strlen($secret) > 200) {
                $v->addError('google_client_secret', 'Client secret ไม่ถูกต้อง (ไม่มีช่องว่าง ยาว 10–200 ตัว)');
            }
            if (($id === '') !== ($secret === '')) {
                $v->addError('google_client_id', 'กรอก Client ID และ Client secret ให้ครบทั้งคู่ (หรือเว้นว่างทั้งคู่เพื่อใช้ค่าในไฟล์ config)');
            }
        }
        // Client ID ที่จะใช้จริงหลังบันทึก: ค่าจากหน้านี้ ถ้าเว้นว่างใช้ค่าในไฟล์ config (อ่านไฟล์ใหม่ เพราะ config ในหน่วยความจำรวมค่าหลังบ้านไว้แล้ว)
        $fileCfg = (array) (load_config()['google'] ?? []);
        $effectiveId = $id !== '' ? $id : ((string) ($fileCfg['client_id'] ?? '') !== '' && (string) ($fileCfg['client_secret'] ?? '') !== '' ? (string) $fileCfg['client_id'] : '');
        if ($enable && $effectiveId === '' && !$v->hasError('google_client_id')) {
            $v->addError('google_client_id', 'ต้องมี Client ID และ Client secret ก่อนเปิดใช้การเข้าสู่ระบบด้วย Google');
        }
        try {
            $v->validate();
        } catch (ValidationException $e) {
            $this->flash('danger', implode(' ', $e->errors()));
            redirect('admin.settings');
        }
        (new SettingRepository($this->db))->save([
            'google_login_enabled' => $enable ? '1' : '0',
            'google_client_id' => $id,
            'google_client_secret' => $secret,
        ]);
        $this->logs()->log($admin, 'google.settings', 'settings', null, ($enable ? 'เปิด' : 'ปิด') . ' · Client ID: ' . ($id !== '' ? $id : '(ใช้ค่าใน config)'));
        $this->flash('success', $enable ? 'เปิดการเข้าสู่ระบบด้วย Google แล้ว — กด “ทดสอบกับ Google” เพื่อตรวจ redirect URI' : 'ปิดการเข้าสู่ระบบด้วย Google แล้ว ปุ่ม Google ถูกซ่อน');
        redirect('admin.settings');
    }

    public function googleTest(): void
    {
        $this->admin();
        $cfg = (array) ($this->app->config['google'] ?? []);
        if ((string) ($cfg['client_id'] ?? '') === '' || (string) ($cfg['client_secret'] ?? '') === '') {
            $this->flash('danger', 'ยังไม่มี Client ID / Client secret');
            redirect('admin.settings');
        }
        $redirect = absolute_url('google.callback');
        $r = (new GoogleAuthService($this->db, $this->session, $cfg, $redirect))->diagnose();
        $messages = [
            'redirect_uri_mismatch' => 'Google แจ้ง redirect_uri_mismatch — เพิ่ม redirect URI นี้ใน OAuth Client (Authorized redirect URIs) ให้ตรงทุกตัวอักษร: ' . $redirect,
            'invalid_client' => 'Google แจ้ง invalid_client — Client ID ไม่ถูกต้อง หรือ OAuth Client ถูกลบ',
            'deleted_client' => 'Google แจ้งว่า OAuth Client ถูกลบแล้ว',
            'disabled_client' => 'Google แจ้งว่า OAuth Client ถูกปิดใช้งาน',
            'unauthorized_client' => 'Google แจ้ง unauthorized_client — ตรวจชนิด OAuth Client (ต้องเป็น Web application)',
            'org_internal' => 'consent screen เป็น Internal — บัญชีนอกองค์กรเข้าไม่ได้ ให้เปลี่ยนเป็น External แล้ว Publish app',
            'network' => 'เซิร์ฟเวอร์ติดต่อ Google ไม่ได้ (ตรวจการเชื่อมต่ออินเทอร์เน็ตของโฮสต์ / curl)',
        ];
        if ($r['ok']) {
            $this->flash('success', 'Google รับ Client ID และ redirect URI แล้ว (แสดงหน้าเลือกบัญชีได้) — ลองกดปุ่มเข้าสู่ระบบด้วย Google ในหน้าเข้าสู่ระบบได้เลย');
        } else {
            $this->flash('danger', $messages[$r['code']] ?? ('Google ตอบกลับไม่ปกติ (' . $r['code'] . ')'));
        }
        redirect('admin.settings');
    }

    public function settingsSave(): void
    {
        $admin = $this->admin();
        $v = new Validator($_POST);
        $values = [
            'site_name' => $v->string('site_name', 'ชื่อเว็บไซต์', 2, 100),
            'site_slogan' => $v->string('site_slogan', 'สโลแกน', 0, 150),
            'site_description' => $v->text('site_description', 'คำอธิบายเว็บไซต์', 0, 1000),
            'hero_badge' => $v->string('hero_badge', 'ข้อความป้ายหน้าแรก', 0, 100),
            'developer_name' => $v->string('developer_name', 'ชื่อผู้พัฒนาระบบ', 0, 150),
            'copyright_owner' => $v->string('copyright_owner', 'เจ้าของลิขสิทธิ์', 0, 150),
            'copyright_start_year' => (string) $v->int('copyright_start_year', 'ปีที่เริ่มเผยแพร่ (ค.ศ.)', 2000, 2100),
            'privacy_controller' => $v->string('privacy_controller', 'ผู้ควบคุมข้อมูลส่วนบุคคล', 0, 200),
            'privacy_contact' => $v->string('privacy_contact', 'ช่องทางติดต่อเรื่องข้อมูลส่วนบุคคล', 0, 300),
            'site_url' => $v->string('site_url', 'URL เว็บไซต์', 0, 200),
        ];
        if ($values['site_url'] !== '') {
            $normalized = normalize_site_url($values['site_url']);
            if ($normalized === null) {
                $v->addError('site_url', 'URL เว็บไซต์ต้องขึ้นต้นด้วย https:// หรือ http:// ตามด้วยโดเมน และไม่มี ? หรือ # (เช่น https://prompthub.example.ac.th)');
            } else {
                $values['site_url'] = $normalized;
            }
        }
        try {
            $v->validate();
        } catch (ValidationException $e) {
            $this->render('admin/settings', ['title' => 'ตั้งค่าเว็บไซต์', 'errors' => $e->errors(), 'old' => $_POST], 422);
            return;
        }
        $this->db->transaction(function () use ($values, $admin) {
            (new SettingRepository($this->db))->save($values);
            $this->logs()->log($admin, 'settings.update', 'settings', null, $values['site_name'] . ' · ' . $values['site_slogan']
                . ($values['site_url'] !== $this->app->setting('site_url') ? ' · URL: ' . ($values['site_url'] ?: '(ใช้ค่าในไฟล์ตั้งค่า)') : ''));
        });
        $this->flash('success', 'บันทึกการตั้งค่าเว็บไซต์แล้ว');
        redirect('admin.settings');
    }

    // ---------- Google Drive (ที่เก็บภาพตัวอย่างผลลัพธ์) ----------

    private function drive(): GoogleDriveService
    {
        return new GoogleDriveService($this->db, $this->app->config, absolute_url('admin.drive.callback'));
    }

    public function drivePage(): void
    {
        $this->admin();
        $counts = $this->db->one('SELECT COUNT(*) AS total, SUM(drive_file_id IS NOT NULL) AS on_drive FROM prompt_images') ?? [];
        $this->render('admin/drive', [
            'title' => 'Google Drive',
            'drive' => GoogleDriveService::state(),
            'active' => GoogleDriveService::active(),
            'configured' => $this->drive()->configured(),
            'folderId' => $this->app->setting('drive_folder_id'),
            'total' => (int) ($counts['total'] ?? 0),
            'onDrive' => (int) ($counts['on_drive'] ?? 0),
            'redirectUri' => absolute_url('admin.drive.callback'),
        ]);
    }

    public function driveSave(): void
    {
        $admin = $this->admin();
        $id = GoogleDriveService::parseFolderId($this->postString('folder'));
        if ($id === null) {
            $this->flash('danger', 'ลิงก์หรือรหัสโฟลเดอร์ Google Drive ไม่ถูกต้อง (เช่น https://drive.google.com/drive/folders/…)');
            redirect('admin.drive');
        }
        (new SettingRepository($this->db))->save(['drive_folder_id' => $id]);
        $this->logs()->log($admin, 'drive.folder', 'settings', null, $id);
        $state = GoogleDriveService::state();
        $this->flash('success', 'บันทึกโฟลเดอร์แล้ว' . ($state && ($state['parent_folder_id'] ?? '') !== $id ? ' — กด “เชื่อมต่อใหม่” เพื่อเริ่มใช้โฟลเดอร์นี้' : ''));
        redirect('admin.drive');
    }

    public function driveConnect(): void
    {
        $this->admin();
        if ($this->app->setting('drive_folder_id') === '') {
            $this->flash('danger', 'กรุณาบันทึกโฟลเดอร์ Google Drive ก่อนเชื่อมต่อ');
            redirect('admin.drive');
        }
        if (!$this->drive()->configured()) {
            $this->flash('danger', 'ยังไม่ได้ใส่ Google Client ID/Secret ใน config/config.php (ส่วน drive หรือ google)');
            redirect('admin.drive');
        }
        header('Location: ' . $this->drive()->authorizationUrl($this->session), true, 303);
        exit;
    }

    public function driveCallback(): void
    {
        $admin = $this->admin();
        try {
            $state = $this->drive()->handleCallback($this->session, $_GET, $this->app->setting('drive_folder_id'));
        } catch (HttpException $e) {
            $this->flash('danger', $e->getMessage());
            redirect('admin.drive');
        } catch (\RuntimeException $e) {
            \PromptHub\Core\Logger::error($e, 'drive-connect');
            $this->flash('danger', 'เชื่อมต่อ Google Drive ไม่สำเร็จ ตรวจว่าเปิดใช้ Google Drive API ในโปรเจกต์ Google Cloud แล้ว (รายละเอียดใน storage/logs)');
            redirect('admin.drive');
        }
        $this->logs()->log($admin, 'drive.connect', 'settings', null, $state['email'] . ' → ' . $state['parent_folder_name']);
        $this->flash('success', 'เชื่อมต่อ Google Drive ด้วยบัญชี ' . $state['email'] . ' แล้ว ภาพใหม่จะเก็บในโฟลเดอร์ “' . GoogleDriveService::SUBFOLDER_NAME . '”');
        redirect('admin.drive');
    }

    public function driveDisconnect(): void
    {
        $admin = $this->admin();
        $state = GoogleDriveService::state();
        $this->drive()->disconnect();
        $this->logs()->log($admin, 'drive.disconnect', 'settings', null, (string) ($state['email'] ?? ''));
        $this->flash('success', 'ยกเลิกการเชื่อมต่อ Google Drive แล้ว หน้าเว็บกลับไปแสดงภาพจากสำเนาในเครื่อง (ไฟล์บน Drive ยังอยู่)');
        redirect('admin.drive');
    }

    public function driveSync(): void
    {
        $admin = $this->admin();
        if (!GoogleDriveService::active()) {
            $this->flash('danger', 'ยังไม่ได้เชื่อมต่อ Google Drive หรือต้องเชื่อมต่อใหม่');
            redirect('admin.drive');
        }
        $r = $this->drive()->syncPending(null, 20);
        $this->logs()->log($admin, 'drive.sync', 'settings', null, "อัปโหลด {$r['uploaded']} ล้มเหลว {$r['failed']} เหลือ {$r['remaining']}");
        $msg = "อัปโหลดขึ้น Drive {$r['uploaded']} ภาพ" . ($r['failed'] ? " · ไม่สำเร็จ {$r['failed']} ภาพ (ดู storage/logs)" : '')
            . ($r['remaining'] ? " · เหลืออีก {$r['remaining']} ภาพ กดอีกครั้งเพื่ออัปโหลดต่อ" : ' · ภาพทั้งหมดอยู่บน Drive แล้ว');
        $this->flash($r['failed'] ? 'warning' : 'success', $msg);
        redirect('admin.drive');
    }

    public function logsPage(): void
    {
        $this->admin();
        $action = $this->queryIn('action', array_keys(ActivityLogRepository::ACTIONS));
        $page = $this->page();
        [$rows, $total] = $this->logs()->search($action, $page, 30);
        $this->render('admin/logs', [
            'title' => 'บันทึกกิจกรรมผู้ดูแล',
            'rows' => $rows,
            'action' => $action,
            'pager' => self::pagination($total, $page, 30),
        ]);
    }
}
