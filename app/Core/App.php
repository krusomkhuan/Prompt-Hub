<?php
declare(strict_types=1);

namespace PromptHub\Core;

use PromptHub\Repositories\SettingRepository;

/** จุดรวมบริการหลักของแอป (config, ฐานข้อมูล, session, auth, view) */
final class App
{
    private static ?App $instance = null;
    public Database $db;
    public Session $session;
    public Auth $auth;
    public View $view;
    public array $settings = [];

    private function __construct(public array $config)
    {
    }

    public static function boot(array $config): self
    {
        $app = new self($config);
        $app->db = new Database($config['db']);
        $app->session = new Session($config['app']);
        $app->session->start();
        $app->auth = new Auth($app->db, $app->session);
        $app->settings = (new SettingRepository($app->db))->all();
        $app->applyGoogleSettings();
        $app->view = new View();
        self::$instance = $app;
        return $app;
    }

    /**
     * การเข้าสู่ระบบด้วย Google ตั้งได้จากหลังบ้าน (เปิด/ปิด, Client ID, Client Secret) — ค่าที่ตั้งในหลังบ้านแทนค่าในส่วน google ของ config
     * ค่าว่าง = ใช้ค่าใน config ตามเดิม
     */
    private function applyGoogleSettings(): void
    {
        $g = (array) ($this->config['google'] ?? []);
        $id = trim((string) ($this->settings['google_client_id'] ?? ''));
        $secret = (string) ($this->settings['google_client_secret'] ?? '');
        $enabled = (string) ($this->settings['google_login_enabled'] ?? '');
        if ($id !== '' && $secret !== '') {
            $g['client_id'] = $id;
            $g['client_secret'] = $secret;
        }
        if ($enabled === '1' || $enabled === '0') {
            $g['enabled'] = $enabled === '1';
        }
        $this->config['google'] = $g;
    }

    /** ที่มาของค่า Google Login: 'admin' (หลังบ้าน) หรือ 'config' */
    public function googleSource(): string
    {
        return trim((string) ($this->settings['google_client_id'] ?? '')) !== '' && (string) ($this->settings['google_client_secret'] ?? '') !== '' ? 'admin' : 'config';
    }

    public static function get(): self
    {
        if (!self::$instance) {
            throw new \LogicException('App not booted');
        }
        return self::$instance;
    }

    public static function booted(): bool
    {
        return self::$instance !== null;
    }

    public function baseUrl(): string
    {
        return rtrim((string) ($this->config['app']['base_url'] ?? ''), '/');
    }

    /**
     * URL เต็มของหน้าเว็บ (โฟลเดอร์ public) ไม่มี / ท้าย ใช้สร้างลิงก์ในอีเมล, Google redirect URI และลิงก์แชร์
     * ใช้ค่า “URL เว็บไซต์” จากหลังบ้าน ถ้าว่างใช้ app_url + base_url ในไฟล์ตั้งค่า — ไม่อ่านจาก Host header เพื่อกันการปลอมลิงก์
     */
    public function publicUrl(): string
    {
        $site = rtrim($this->setting('site_url'), '/');
        if ($site !== '') {
            return $site;
        }
        return rtrim((string) ($this->config['app']['app_url'] ?? 'http://localhost'), '/') . $this->baseUrl();
    }

    public function setting(string $key, string $default = ''): string
    {
        return (string) ($this->settings[$key] ?? $default);
    }
}
