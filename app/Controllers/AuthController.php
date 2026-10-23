<?php
declare(strict_types=1);

namespace PromptHub\Controllers;

use PromptHub\Core\HttpException;
use PromptHub\Core\Mailer;
use PromptHub\Core\Validator;
use PromptHub\Repositories\UserRepository;
use PromptHub\Services\GoogleAuthService;
use PromptHub\Services\GoogleDriveService;
use PromptHub\Core\ValidationException;
use PromptHub\Services\AuthService;
use PromptHub\Services\PasswordResetService;

final class AuthController extends Controller
{
    public function loginForm(): void
    {
        $this->render('auth/login', ['title' => 'เข้าสู่ระบบ', 'old' => [], 'errors' => []]);
    }

    public function login(): void
    {
        $login = mb_substr($this->postString('login'), 0, 190);
        try {
            $user = (new AuthService($this->db))->attempt($login, $this->postString('password'), client_ip());
        } catch (ValidationException $e) {
            $this->render('auth/login', ['title' => 'เข้าสู่ระบบ', 'old' => ['login' => $login], 'errors' => $e->errors()], 422);
            return;
        }
        $this->auth->login($user);
        $this->flash('success', 'ยินดีต้อนรับ ' . $user['display_name']);
        $next = $this->postString('next');
        if ($next === 'admin' && $user['role'] === 'admin') {
            redirect('admin');
        }
        redirect('dashboard');
    }

    public function registerForm(): void
    {
        $this->render('auth/register', ['title' => 'สมัครสมาชิก', 'old' => [], 'errors' => []]);
    }

    public function register(): void
    {
        try {
            (new AuthService($this->db))->register($_POST);
        } catch (ValidationException $e) {
            $old = array_intersect_key($_POST, array_flip(['username', 'email', 'display_name', 'accept_privacy']));
            $this->render('auth/register', ['title' => 'สมัครสมาชิก', 'old' => $old, 'errors' => $e->errors()], 422);
            return;
        }
        $this->flash('success', 'สมัครสมาชิกสำเร็จ กรุณาเข้าสู่ระบบ');
        redirect('login');
    }

    // ---------- ลืมรหัสผ่าน ----------

    private function resetService(): PasswordResetService
    {
        return new PasswordResetService($this->db, $this->mailer());
    }

    private function mailer(): Mailer
    {
        return new Mailer((array) ($this->app->config['mail'] ?? []));
    }

    public function forgotForm(): void
    {
        $this->render('auth/forgot', [
            'title' => 'ลืมรหัสผ่าน',
            'old' => [],
            'errors' => [],
            'mailReady' => $this->mailer()->enabled(),
        ]);
    }

    public function forgot(): void
    {
        // ลิงก์สร้างจาก URL เว็บไซต์ที่ตั้งไว้ (หลังบ้าน หรือ app_url ในไฟล์ตั้งค่า) ไม่ใช้ Host header ของคำขอ
        $resetBase = absolute_url('reset');
        try {
            $this->resetService()->request($_POST, client_ip(), $resetBase, $this->app->setting('site_name', 'Prompt Hub'));
        } catch (ValidationException $e) {
            $this->render('auth/forgot', [
                'title' => 'ลืมรหัสผ่าน',
                'old' => ['email' => mb_substr($this->postString('email'), 0, 190)],
                'errors' => $e->errors(),
                'mailReady' => $this->mailer()->enabled(),
            ], 422);
            return;
        }
        $this->flash('info', 'หากอีเมลนี้ตรงกับบัญชีที่ใช้งานอยู่ ระบบได้ส่งลิงก์ตั้งรหัสผ่านใหม่แล้ว กรุณาตรวจกล่องจดหมาย (รวมถึงโฟลเดอร์สแปม) ลิงก์ใช้ได้ ' . PasswordResetService::TTL_MINUTES . ' นาที');
        redirect('forgot');
    }

    public function resetForm(): void
    {
        // ย้าย token จาก URL ไปเก็บใน session แล้วโหลดหน้าใหม่ เพื่อไม่ให้ token ค้างในแถบที่อยู่/ประวัติเบราว์เซอร์
        if (isset($_GET['token'])) {
            $token = is_string($_GET['token']) ? $_GET['token'] : '';
            $this->session->set('reset_token', preg_match('/^[a-f0-9]{64}$/D', $token) ? $token : '');
            redirect('reset');
        }
        $token = (string) $this->session->get('reset_token', '');
        $user = $token !== '' ? $this->resetService()->findValid($token) : null;
        $this->render('auth/reset', ['title' => 'ตั้งรหัสผ่านใหม่', 'user' => $user, 'errors' => []], $user ? 200 : 410);
    }

    public function reset(): void
    {
        $token = (string) $this->session->get('reset_token', '');
        try {
            $this->resetService()->reset($token, $_POST);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $user = isset($errors['token']) ? null : $this->resetService()->findValid($token);
            $this->render('auth/reset', ['title' => 'ตั้งรหัสผ่านใหม่', 'user' => $user, 'errors' => $errors], $user ? 422 : 410);
            return;
        }
        $this->session->remove('reset_token');
        $this->flash('success', 'ตั้งรหัสผ่านใหม่เรียบร้อย กรุณาเข้าสู่ระบบด้วยรหัสผ่านใหม่');
        redirect('login');
    }

    // ---------- เข้าสู่ระบบด้วย Google ----------

    private function google(): GoogleAuthService
    {
        $redirect = google_redirect_uri();
        $svc = new GoogleAuthService($this->db, $this->session, (array) ($this->app->config['google'] ?? []), $redirect);
        if (!$svc->enabled()) {
            throw new HttpException(404, 'ระบบยังไม่ได้เปิดใช้การเข้าสู่ระบบด้วย Google');
        }
        return $svc;
    }

    public function googleStart(): void
    {
        $url = $this->google()->authorizationUrl();
        header('Location: ' . $url, true, 303);
        exit;
    }

    public function googleCallback(): void
    {
        // การเชื่อมต่อ Google Drive (หลังบ้าน) ใช้ redirect URI เดียวกัน — แยกด้วย state ที่บันทึกใน session ตอนกดปุ่มเชื่อมต่อ
        if (GoogleDriveService::ownsState($this->session, $_GET['state'] ?? null)) {
            (new AdminController($this->app))->driveCallback();
            return;
        }
        if ($this->auth->check()) {
            redirect('dashboard');
        }
        $svc = $this->google();
        $g = $svc->handleCallback($_GET);
        [$user, $linked] = $svc->resolveUser($g);
        if ($user === null) {
            $this->session->set(GoogleAuthService::PENDING_KEY, $g + ['at' => time()]);
            redirect('google.confirm');
        }
        if ($user['status'] !== 'active') {
            $this->flash('danger', 'บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ');
            redirect('login');
        }
        $this->auth->login($user);
        $this->flash('success', ($linked ? 'เชื่อมบัญชี Google กับบัญชีเดิมแล้ว · ' : '') . 'ยินดีต้อนรับ ' . $user['display_name']);
        redirect('dashboard');
    }

    private function pendingGoogle(): array
    {
        $g = $this->session->get(GoogleAuthService::PENDING_KEY);
        if (!is_array($g) || time() - (int) ($g['at'] ?? 0) > 900) {
            $this->session->remove(GoogleAuthService::PENDING_KEY);
            $this->flash('warning', 'ขั้นตอนสมัครด้วย Google หมดเวลา กรุณาลองใหม่');
            redirect('login');
        }
        return $g;
    }

    public function googleConfirm(): void
    {
        $g = $this->pendingGoogle();
        $this->render('auth/google_confirm', [
            'title' => 'สร้างบัญชีด้วย Google',
            'g' => $g,
            'old' => ['username' => $this->google()->suggestUsername($g['email']), 'display_name' => $g['name']],
            'errors' => [],
        ]);
    }

    public function googleFinish(): void
    {
        $g = $this->pendingGoogle();
        $svc = $this->google();
        $v = new Validator($_POST);
        $username = $v->username('username');
        $name = $v->string('display_name', 'ชื่อที่แสดง', 1, 100);
        if (($_POST['accept_privacy'] ?? '') !== '1') {
            $v->addError('accept_privacy', 'กรุณาอ่านและยอมรับนโยบายความเป็นส่วนตัวก่อนสร้างบัญชี');
        }
        if (!$v->hasError('username') && (new UserRepository($this->db))->usernameExists($username)) {
            $v->addError('username', 'ชื่อผู้ใช้นี้ถูกใช้แล้ว');
        }
        try {
            $v->validate();
            $user = $svc->createUser($g, $username, $name);
        } catch (ValidationException $e) {
            $this->render('auth/google_confirm', ['title' => 'สร้างบัญชีด้วย Google', 'g' => $g, 'old' => $_POST, 'errors' => $e->errors()], 422);
            return;
        } catch (\mysqli_sql_exception $e) {
            if ($e->getCode() !== 1062) {
                throw $e;
            }
            $this->render('auth/google_confirm', ['title' => 'สร้างบัญชีด้วย Google', 'g' => $g, 'old' => $_POST,
                'errors' => ['username' => 'ชื่อผู้ใช้หรืออีเมลนี้ถูกใช้แล้ว']], 409);
            return;
        }
        $this->session->remove(GoogleAuthService::PENDING_KEY);
        $this->auth->login($user);
        $this->flash('success', 'สร้างบัญชีด้วย Google เรียบร้อย ยินดีต้อนรับ ' . $user['display_name']);
        redirect('dashboard');
    }

    public function logout(): void
    {
        $this->auth->logout();
        $this->session->start();
        $this->flash('info', 'ออกจากระบบแล้ว');
        redirect('home');
    }
}
