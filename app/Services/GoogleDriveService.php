<?php
declare(strict_types=1);

namespace PromptHub\Services;

use PromptHub\Core\Database;
use PromptHub\Core\HttpException;
use PromptHub\Core\Logger;
use PromptHub\Core\Session;

/**
 * เก็บภาพตัวอย่างผลลัพธ์ไว้ใน Google Drive ของผู้ดูแล (OAuth 2.0 + PKCE, refresh token)
 *  - ผู้ดูแลกด “เชื่อมต่อ Google Drive” ในหลังบ้านครั้งเดียว ในโฟลเดอร์หลักที่กำหนด ระบบจัดโฟลเดอร์ให้ตรงกับเว็บ:
 *      โฟลเดอร์หลัก/storage/uploads/prompts  ← ภาพตัวอย่าง (ชื่อไฟล์เดียวกับบนเซิร์ฟเวอร์)
 *      โฟลเดอร์หลัก/storage/backups          ← ไฟล์สำรองข้อมูล (เมื่อไม่ได้ตั้งโฟลเดอร์สำรองแยก)
 *    โฟลเดอร์ที่มีชื่อตรงกันอยู่แล้วใช้ของเดิม · ภาพที่เคยอยู่ในโฟลเดอร์รุ่นเก่า (LEGACY_SUBFOLDER) ย้ายมาด้วย relocateLegacy()
 *  - ภาพยังเก็บสำเนาในเครื่อง (storage/uploads/prompts) เป็นข้อมูลสำรอง และใช้แสดงแทนเมื่อ Drive ใช้ไม่ได้
 *  - ไฟล์บน Drive ตั้งสิทธิ์ “ทุกคนที่มีลิงก์ดูได้” แล้วหน้าเว็บลิงก์ภาพตรงไปที่ Drive (lh3.googleusercontent.com)
 *  - ลบภาพในระบบ → ย้ายไฟล์บน Drive ไปถังขยะ (กู้คืนได้ใน Drive 30 วัน)
 *  - token เก็บใน storage/google-drive.json (นอก public, เว็บเข้าไม่ได้) ไม่เก็บในฐานข้อมูล
 */
final class GoogleDriveService
{
    public const SCOPE = 'https://www.googleapis.com/auth/drive';
    /** โฟลเดอร์ภาพบน Drive ตรงกับ storage/uploads/prompts ของเว็บ */
    public const IMAGE_PATH = ['storage', 'uploads', 'prompts'];
    public const BACKUP_PATH = ['storage', 'backups'];
    /** โฟลเดอร์ภาพรุ่นก่อน 1.12 (ภาพในนี้ถูกย้ายไป IMAGE_PATH) */
    public const LEGACY_SUBFOLDER = 'Prompt Hub - ภาพตัวอย่างผลลัพธ์';
    private const SESSION_KEY = 'drive_oauth';
    private static ?array $stateCache = null;

    public function __construct(private Database $db, private array $config, private string $redirectUri = '')
    {
    }

    public static function tokenFile(): string
    {
        return APP_ROOT . '/storage/google-drive.json';
    }

    /**
     * ค่าของ OAuth: client_id / client_secret ใช้จากส่วน drive ก่อน (OAuth Client แยกสำหรับ Drive เช่น แบบ Internal ของโดเมน)
     * ถ้าไม่ได้ตั้งใช้ค่าจากส่วน google (Client เดียวกับการเข้าสู่ระบบด้วย Google)
     */
    private function g(string $key, string $default = ''): string
    {
        if (in_array($key, ['client_id', 'client_secret'], true)) {
            $driveId = (string) ($this->config['drive']['client_id'] ?? '');
            $driveSecret = (string) ($this->config['drive']['client_secret'] ?? '');
            if ($driveId !== '' && $driveSecret !== '') {
                return $key === 'client_id' ? $driveId : $driveSecret;
            }
        }
        return (string) ($this->config['google'][$key] ?? $default) ?: $default;
    }

    private function d(string $key, string $default): string
    {
        return rtrim((string) ($this->config['drive'][$key] ?? $default) ?: $default, '/');
    }

    /** มี Client ID/Secret ใน config แล้ว (ใช้ OAuth Client เดียวกับการเข้าสู่ระบบด้วย Google) */
    public function configured(): bool
    {
        return $this->g('client_id') !== '' && $this->g('client_secret') !== '';
    }

    public static function state(): ?array
    {
        if (self::$stateCache === null) {
            $raw = is_file(self::tokenFile()) ? (string) file_get_contents(self::tokenFile()) : '';
            $data = $raw !== '' ? json_decode($raw, true) : null;
            self::$stateCache = is_array($data) ? $data : [];
        }
        return self::$stateCache ?: null;
    }

    /** เชื่อมต่อแล้วและใช้งานได้ (ใช้ตัดสินว่าจะแสดงภาพจาก Drive หรือจากเครื่อง) */
    public static function active(): bool
    {
        $s = self::state();
        return $s !== null && !empty($s['refresh_token']) && !empty($s['folder_id']) && empty($s['needs_reconnect']);
    }

    private static function saveState(?array $state): void
    {
        $file = self::tokenFile();
        if ($state === null) {
            if (is_file($file)) {
                unlink($file);
            }
        } elseif (file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write Drive token file');
        }
        self::$stateCache = $state ?? [];
    }

    /** URL รูปภาพบน Drive สำหรับแสดงในหน้าเว็บ (ไฟล์ต้องเป็น “ทุกคนที่มีลิงก์”) */
    public static function imageUrl(string $fileId, int $width = 1600): string
    {
        $base = \PromptHub\Core\App::booted()
            ? rtrim((string) (\PromptHub\Core\App::get()->config['drive']['image_base'] ?? 'https://lh3.googleusercontent.com/d'), '/')
            : 'https://lh3.googleusercontent.com/d';
        return $base . '/' . rawurlencode($fileId) . '=w' . max(64, min(1600, $width));
    }

    /** ดึงรหัสโฟลเดอร์จากลิงก์ Drive หรือรหัสโดยตรง */
    public static function parseFolderId(string $input): ?string
    {
        $input = trim($input);
        if (preg_match('#/folders/([A-Za-z0-9_-]{10,100})#', $input, $m) || preg_match('#[?&]id=([A-Za-z0-9_-]{10,100})#', $input, $m)) {
            return $m[1];
        }
        return preg_match('/^[A-Za-z0-9_-]{10,100}$/D', $input) ? $input : null;
    }

    // ---------- เชื่อมต่อ ----------

    public function authorizationUrl(Session $session): string
    {
        if (!$this->configured()) {
            throw new HttpException(400, 'ยังไม่ได้ตั้งค่า Google Client ID/Secret ใน config/config.php');
        }
        $state = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $session->set(self::SESSION_KEY, ['state' => $state, 'verifier' => $verifier, 'at' => time()]);
        return $this->g('auth_url', 'https://accounts.google.com/o/oauth2/v2/auth') . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->g('client_id'),
            'redirect_uri' => $this->redirectUri,
            'scope' => 'openid email ' . self::SCOPE,
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            // ขอ refresh token ทุกครั้งที่เชื่อมต่อ
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);
    }

    /** รับ callback: แลก token ตรวจสิทธิ์ในโฟลเดอร์ แล้วสร้าง/ใช้โฟลเดอร์ย่อยสำหรับภาพ */
    /**
     * callback นี้เป็นของการเชื่อมต่อ Drive หรือไม่ (Drive ใช้ redirect URI เดียวกับการเข้าสู่ระบบด้วย Google)
     * ตรวจจาก state ที่บันทึกใน session ตอนกดปุ่มเชื่อมต่อ — ไม่ตรง = เป็นการเข้าสู่ระบบตามปกติ
     */
    public static function ownsState(Session $session, $state): bool
    {
        $saved = $session->get(self::SESSION_KEY);
        return is_string($state) && $state !== '' && is_array($saved) && is_string($saved['state'] ?? null)
            && hash_equals($saved['state'], $state);
    }

    public function handleCallback(Session $session, array $query, string $parentFolderId): array
    {
        $saved = $session->get(self::SESSION_KEY);
        $session->remove(self::SESSION_KEY);
        if (isset($query['error'])) {
            throw new HttpException(400, 'ยกเลิกการเชื่อมต่อ Google Drive แล้ว');
        }
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';
        if (!is_array($saved) || $state === '' || !hash_equals((string) $saved['state'], $state) || time() - (int) $saved['at'] > 600) {
            throw new HttpException(400, 'คำขอเชื่อมต่อ Google Drive หมดอายุหรือไม่ถูกต้อง กรุณาลองใหม่');
        }
        if ($code === '' || strlen($code) > 2048) {
            throw new HttpException(400, 'ไม่ได้รับรหัสยืนยันจาก Google');
        }
        $token = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $saved['verifier'],
        ]);
        if (empty($token['refresh_token']) || empty($token['access_token'])) {
            throw new HttpException(400, 'Google ไม่ได้ส่ง refresh token มา กรุณาเชื่อมต่อใหม่และกด “อนุญาต” ทุกข้อ');
        }
        $scopes = explode(' ', (string) ($token['scope'] ?? self::SCOPE));
        if (!in_array(self::SCOPE, $scopes, true)) {
            throw new HttpException(400, 'ยังไม่ได้อนุญาตให้เข้าถึง Google Drive กรุณาเชื่อมต่อใหม่และเลือกอนุญาตสิทธิ์ Drive');
        }
        $access = (string) $token['access_token'];
        $info = $this->api('GET', $this->g('userinfo_url', 'https://openidconnect.googleapis.com/v1/userinfo'), $access);
        $parent = $this->api('GET', $this->d('api_base', 'https://www.googleapis.com/drive/v3') . '/files/' . rawurlencode($parentFolderId)
            . '?' . http_build_query(['fields' => 'id,name,mimeType,capabilities(canAddChildren)', 'supportsAllDrives' => 'true']), $access, null, [404]);
        if (($parent['__status'] ?? 200) === 404 || ($parent['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') {
            throw new HttpException(400, 'บัญชี Google นี้เข้าถึงโฟลเดอร์ที่ตั้งไว้ไม่ได้ หรือรหัสไม่ใช่โฟลเดอร์ — เข้าสู่ระบบด้วยบัญชีเจ้าของโฟลเดอร์');
        }
        if (empty($parent['capabilities']['canAddChildren'])) {
            throw new HttpException(400, 'บัญชีนี้ไม่มีสิทธิ์เพิ่มไฟล์ในโฟลเดอร์ “' . ($parent['name'] ?? '') . '” (ต้องเป็นผู้แก้ไขหรือเจ้าของ)');
        }
        $folderId = $this->ensurePath($access, $parentFolderId, self::IMAGE_PATH);
        $previous = self::state();
        // เคยใช้โฟลเดอร์รุ่นเก่า (หรือโฟลเดอร์อื่น) → จำไว้เพื่อย้ายภาพเดิมมาโฟลเดอร์ใหม่
        $legacy = $this->findFolder($access, $parentFolderId, self::LEGACY_SUBFOLDER);
        if (!empty($previous['folder_id']) && $previous['folder_id'] !== $folderId) {
            $legacy = (string) $previous['folder_id'];
        }
        $state = [
            'refresh_token' => (string) $token['refresh_token'],
            'access_token' => $access,
            'expires_at' => time() + (int) ($token['expires_in'] ?? 3600) - 60,
            'email' => strtolower((string) ($info['email'] ?? '')),
            'parent_folder_id' => $parentFolderId,
            'parent_folder_name' => (string) ($parent['name'] ?? ''),
            'folder_id' => $folderId,
            'layout' => 'mirror',
            'connected_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($legacy !== null && $legacy !== $folderId) {
            $state['legacy_folder_id'] = $legacy;
            $state['legacy_cursor'] = 0;
        }
        self::saveState($state);
        return $state;
    }

    /** หาโฟลเดอร์ชื่อนี้ในโฟลเดอร์แม่ (ไม่พบ = null) */
    private function findFolder(string $access, string $parentId, string $name): ?string
    {
        $q = sprintf("name = '%s' and '%s' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false",
            str_replace(['\\', "'"], ['\\\\', "\\'"], $name), $parentId);
        $list = $this->api('GET', $this->d('api_base', 'https://www.googleapis.com/drive/v3') . '/files?' . http_build_query([
            'q' => $q, 'fields' => 'files(id,name)', 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
        ]), $access);
        return !empty($list['files'][0]['id']) ? (string) $list['files'][0]['id'] : null;
    }

    /** หาหรือสร้างโฟลเดอร์ซ้อนตามลำดับชื่อ เช่น ['storage','uploads','prompts'] คืนรหัสโฟลเดอร์ชั้นสุดท้าย (มีอยู่แล้วใช้ของเดิม) */
    private function ensurePath(string $access, string $parentId, array $names): string
    {
        foreach ($names as $name) {
            $id = $this->findFolder($access, $parentId, $name);
            if ($id === null) {
                $folder = $this->api('POST', $this->d('api_base', 'https://www.googleapis.com/drive/v3') . '/files?supportsAllDrives=true&fields=id', $access,
                    json_encode(['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$parentId]], JSON_UNESCAPED_UNICODE));
                $id = (string) $folder['id'];
            }
            $parentId = $id;
        }
        return $parentId;
    }

    /**
     * การเชื่อมต่อที่สร้างก่อนจัดโฟลเดอร์ให้ตรงกับเว็บ: สร้าง storage/uploads/prompts แล้วจำโฟลเดอร์เดิมไว้ย้ายภาพ
     * (เรียกก่อนอัปโหลดทุกครั้ง — การเชื่อมต่อแบบใหม่แล้วไม่ทำอะไร)
     */
    public function ensureLayout(): void
    {
        $s = self::state();
        if (!$s || ($s['layout'] ?? '') === 'mirror' || !self::active()) {
            return;
        }
        $folderId = $this->ensurePath($this->accessToken(), (string) $s['parent_folder_id'], self::IMAGE_PATH);
        $s = self::state();
        if ($s['folder_id'] !== $folderId) {
            $s['legacy_folder_id'] = (string) $s['folder_id'];
            $s['legacy_cursor'] = 0;
        }
        $s['folder_id'] = $folderId;
        $s['layout'] = 'mirror';
        self::saveState($s);
    }

    /** จำนวนภาพบน Drive ที่ยังต้องย้ายจากโฟลเดอร์เดิม */
    public function legacyRemaining(): int
    {
        $s = self::state();
        // เชื่อมต่อด้วยรุ่นก่อนจัดโฟลเดอร์ (ยังไม่มี layout) = ภาพทุกภาพบน Drive ยังอยู่ในโฟลเดอร์เดิม
        $oldLayout = $s && !empty($s['folder_id']) && ($s['layout'] ?? '') !== 'mirror';
        if (!$oldLayout && empty($s['legacy_folder_id'])) {
            return 0;
        }
        return (int) $this->db->value('SELECT COUNT(*) FROM prompt_images WHERE drive_file_id IS NOT NULL AND id > ?', [$oldLayout ? 0 : (int) ($s['legacy_cursor'] ?? 0)]);
    }

    /**
     * ย้ายภาพที่อัปโหลดไว้แล้วจากโฟลเดอร์เดิมมา storage/uploads/prompts และเปลี่ยนชื่อเป็นชื่อเดียวกับบนเซิร์ฟเวอร์
     * (ย้ายไฟล์เดิม ไม่อัปโหลดซ้ำ — รหัสไฟล์และลิงก์ภาพบนเว็บไม่เปลี่ยน) คืน ['moved' => n, 'failed' => n, 'remaining' => n]
     */
    public function relocateLegacy(int $limit = 50): array
    {
        $result = ['moved' => 0, 'failed' => 0, 'remaining' => 0];
        if (!self::active()) {
            return $result;
        }
        $this->ensureLayout();
        $s = self::state();
        if (empty($s['legacy_folder_id'])) {
            return $result;
        }
        $access = $this->accessToken();
        $cursor = (int) ($s['legacy_cursor'] ?? 0);
        $rows = $this->db->all('SELECT id, file_name, drive_file_id FROM prompt_images WHERE drive_file_id IS NOT NULL AND id > ? ORDER BY id LIMIT ?', [$cursor, $limit]);
        foreach ($rows as $row) {
            try {
                $r = $this->api('PATCH', $this->d('api_base', 'https://www.googleapis.com/drive/v3') . '/files/' . rawurlencode((string) $row['drive_file_id']) . '?'
                    . http_build_query(['addParents' => $s['folder_id'], 'removeParents' => $s['legacy_folder_id'], 'supportsAllDrives' => 'true', 'fields' => 'id']),
                    $access, json_encode(['name' => $row['file_name']]), [404]);
                if (($r['__status'] ?? 200) !== 404) {
                    $result['moved']++;
                }
                $cursor = (int) $row['id'];
            } catch (\Throwable $e) {
                Logger::error($e, 'drive-relocate');
                $result['failed']++;
                break; // หยุดไว้ที่ไฟล์นี้ รอบหน้าทำต่อจากตรงนี้
            }
        }
        $s = self::state();
        $s['legacy_cursor'] = $cursor;
        self::saveState($s);
        $result['remaining'] = $this->legacyRemaining();
        if ($result['remaining'] === 0 && $result['failed'] === 0) {
            unset($s['legacy_folder_id'], $s['legacy_cursor']); // ย้ายครบแล้ว (โฟลเดอร์เดิมว่าง ลบเองได้ใน Drive)
            self::saveState($s);
        }
        return $result;
    }

    /** โฟลเดอร์ storage/backups ในโฟลเดอร์หลัก (สร้างเมื่อยังไม่มี) */
    public function backupFolderId(): string
    {
        $s = self::state();
        if (!self::active() || empty($s['parent_folder_id'])) {
            throw new \RuntimeException('ยังไม่ได้เชื่อมต่อ Google Drive หรือต้องเชื่อมต่อใหม่');
        }
        return $this->ensurePath($this->accessToken(), (string) $s['parent_folder_id'], self::BACKUP_PATH);
    }

    public function disconnect(): void
    {
        $s = self::state();
        if ($s && !empty($s['refresh_token'])) {
            try {
                // ยกเลิกสิทธิ์ที่ Google ด้วย (ถ้าไม่สำเร็จก็ลบ token ในเครื่องต่อ)
                $this->rawRequest('POST', $this->g('revoke_url', 'https://oauth2.googleapis.com/revoke'), ['Content-Type: application/x-www-form-urlencoded'],
                    http_build_query(['token' => $s['refresh_token']]));
            } catch (\Throwable $e) {
                Logger::error($e, 'drive-revoke');
            }
        }
        self::saveState(null);
    }

    // ---------- อัปโหลด / ลบ ----------

    /** อัปโหลดภาพที่ยังไม่อยู่บน Drive (ทั้ง Prompt เดียว หรือทั้งระบบ) คืน ['uploaded' => n, 'failed' => n, 'remaining' => n] */
    public function syncPending(?int $promptId = null, int $limit = 20): array
    {
        $result = ['uploaded' => 0, 'failed' => 0, 'remaining' => 0];
        if (!self::active()) {
            return $result;
        }
        $this->ensureLayout();
        $where = 'drive_file_id IS NULL' . ($promptId !== null ? ' AND prompt_id = ?' : '');
        $args = $promptId !== null ? [$promptId] : [];
        $rows = $this->db->all("SELECT id, prompt_id, file_name, mime FROM prompt_images WHERE $where ORDER BY id LIMIT ?", array_merge($args, [$limit]));
        foreach ($rows as $row) {
            try {
                $this->uploadOne($row);
                $result['uploaded']++;
            } catch (\Throwable $e) {
                Logger::error($e, 'drive-upload');
                $result['failed']++;
                if (!self::active()) {
                    break; // token ใช้ไม่ได้แล้ว หยุดรอบนี้
                }
            }
        }
        $result['remaining'] = (int) $this->db->value("SELECT COUNT(*) FROM prompt_images WHERE $where", $args);
        return $result;
    }

    private function uploadOne(array $row): void
    {
        $path = ImageService::dir() . '/' . $row['file_name'];
        if (!is_file($path)) {
            throw new \RuntimeException('Local image missing: ' . $row['id']);
        }
        $access = $this->accessToken();
        $boundary = 'ph' . bin2hex(random_bytes(12));
        $meta = json_encode([
            'name' => $row['file_name'], // ชื่อเดียวกับ storage/uploads/prompts บนเซิร์ฟเวอร์
            'parents' => [self::state()['folder_id']],
            'description' => 'Prompt Hub · ภาพตัวอย่างผลลัพธ์ของ Prompt #' . (int) $row['prompt_id'],
        ], JSON_UNESCAPED_UNICODE);
        $body = "--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$meta\r\n"
            . "--$boundary\r\nContent-Type: {$row['mime']}\r\n\r\n" . file_get_contents($path) . "\r\n--$boundary--";
        $file = $this->api('POST', $this->d('upload_base', 'https://www.googleapis.com/upload/drive/v3')
            . '/files?uploadType=multipart&supportsAllDrives=true&fields=id', $access, $body, [], 'multipart/related; boundary=' . $boundary);
        $fileId = (string) ($file['id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{10,128}$/D', $fileId)) {
            throw new \RuntimeException('Drive upload returned no file id');
        }
        // ลิงก์ภาพตรงต้องให้ทุกคนที่มีลิงก์ดูได้
        $this->api('POST', $this->d('api_base', 'https://www.googleapis.com/drive/v3') . '/files/' . rawurlencode($fileId)
            . '/permissions?supportsAllDrives=true', $access, json_encode(['type' => 'anyone', 'role' => 'reader']));
        $updated = $this->db->execute('UPDATE prompt_images SET drive_file_id = ? WHERE id = ? AND drive_file_id IS NULL', [$fileId, (int) $row['id']]);
        if ($updated === 0) {
            // ภาพถูกลบหรืออัปโหลดซ้ำระหว่างทาง: ทิ้งไฟล์ที่เพิ่งสร้าง
            $this->trash([$fileId]);
        }
    }

    // ---------- สำรองข้อมูล ----------

    public const BACKUP_CHUNK = 8 * 1024 * 1024; // ต้องเป็นผลคูณของ 256 KB ตามข้อกำหนดของ Drive

    /**
     * อัปโหลดไฟล์สำรอง (zip) ขึ้นโฟลเดอร์ที่กำหนดแบบ resumable ทีละ 8 MB — ไม่ตั้งสิทธิ์แชร์ (ไฟล์มีข้อมูลสมาชิก)
     * คืน ['id' => fileId, 'name' => …, 'size' => bytes, 'folder_name' => …]
     */
    public function uploadBackup(string $path, string $folderId): array
    {
        if (!self::active()) {
            throw new \RuntimeException('ยังไม่ได้เชื่อมต่อ Google Drive หรือต้องเชื่อมต่อใหม่');
        }
        $access = $this->accessToken();
        $api = $this->d('api_base', 'https://www.googleapis.com/drive/v3');
        $folder = $this->api('GET', $api . '/files/' . rawurlencode($folderId) . '?'
            . http_build_query(['fields' => 'id,name,mimeType,capabilities(canAddChildren)', 'supportsAllDrives' => 'true']), $access, null, [404]);
        if (($folder['__status'] ?? 200) === 404 || ($folder['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') {
            throw new HttpException(400, 'บัญชี Google ที่เชื่อมต่อ (' . (self::state()['email'] ?? '') . ') เข้าถึงโฟลเดอร์สำรองข้อมูลไม่ได้ — แชร์โฟลเดอร์ให้บัญชีนี้เป็นผู้แก้ไข');
        }
        if (empty($folder['capabilities']['canAddChildren'])) {
            throw new HttpException(400, 'บัญชีที่เชื่อมต่อไม่มีสิทธิ์เพิ่มไฟล์ในโฟลเดอร์ “' . ($folder['name'] ?? '') . '” (ต้องเป็นผู้แก้ไขหรือเจ้าของ)');
        }
        $size = (int) filesize($path);
        $name = basename($path);
        // 1) เปิด session อัปโหลด
        [$status, , $headers] = $this->rawRequestWithHeaders('POST', $this->d('upload_base', 'https://www.googleapis.com/upload/drive/v3')
            . '/files?uploadType=resumable&supportsAllDrives=true&fields=id,name,size', [
                'Authorization: Bearer ' . $access,
                'Content-Type: application/json; charset=UTF-8',
                'X-Upload-Content-Type: application/zip',
                'X-Upload-Content-Length: ' . $size,
            ], json_encode(['name' => $name, 'parents' => [$folderId], 'mimeType' => 'application/zip',
                'description' => 'Prompt Hub · ไฟล์สำรองข้อมูล (ฐานข้อมูล + ไฟล์อัปโหลด) — มีข้อมูลสมาชิก ห้ามแชร์'], JSON_UNESCAPED_UNICODE));
        $session = $headers['location'] ?? '';
        if ($status !== 200 || !preg_match('#^https?://#', $session)) {
            throw new \RuntimeException('Drive resumable session failed (HTTP ' . $status . ')');
        }
        // 2) ส่งไฟล์ทีละช่วง (308 = รับแล้ว ส่งต่อ / 200,201 = เสร็จ)
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('Cannot read backup file');
        }
        try {
            $offset = 0;
            $result = null;
            do {
                $chunk = $size > 0 ? (string) fread($fh, self::BACKUP_CHUNK) : '';
                $end = $offset + strlen($chunk) - 1;
                $range = $size > 0 ? "bytes $offset-$end/$size" : 'bytes */0';
                [$status, $raw, $h] = $this->rawRequestWithHeaders('PUT', $session, ['Content-Range: ' . $range, 'Content-Type: application/zip'], $chunk);
                if ($status === 308) {
                    // Drive บอกว่าได้รับถึงไบต์ไหน (Range: bytes=0-N) — ส่งต่อจากตรงนั้น
                    $offset = preg_match('/bytes=0-(\d+)/', $h['range'] ?? '', $m) ? (int) $m[1] + 1 : $end + 1;
                    fseek($fh, $offset);
                    continue;
                }
                if ($status === 200 || $status === 201) {
                    $result = json_decode($raw, true);
                    break;
                }
                throw new \RuntimeException('Drive backup upload HTTP ' . $status);
            } while ($offset < $size);
        } finally {
            fclose($fh);
        }
        if (!is_array($result) || !preg_match('/^[A-Za-z0-9_-]{10,128}$/D', (string) ($result['id'] ?? ''))) {
            throw new \RuntimeException('Drive backup upload returned no file id');
        }
        return ['id' => (string) $result['id'], 'name' => $name, 'size' => $size, 'folder_name' => (string) ($folder['name'] ?? '')];
    }

    /** เหมือน rawRequest แต่คืน header ด้วย (ชื่อ header ตัวพิมพ์เล็ก) */
    private function rawRequestWithHeaders(string $method, string $url, array $headers, ?string $body): array
    {
        $out = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body ?? '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$out): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $out[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        $res = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) {
            throw new \RuntimeException('Google request failed: ' . $err);
        }
        return [$status, (string) $res, $out];
    }

    /** ย้ายไฟล์บน Drive ไปถังขยะ (ผิดพลาดบันทึก log ไม่ขัดการทำงานของเว็บ) */
    public function trash(array $fileIds): void
    {
        if (!$fileIds || !self::active()) {
            return;
        }
        foreach (array_unique($fileIds) as $id) {
            try {
                $this->api('PATCH', $this->d('api_base', 'https://www.googleapis.com/drive/v3') . '/files/' . rawurlencode((string) $id)
                    . '?supportsAllDrives=true', $this->accessToken(), json_encode(['trashed' => true]), [404]);
            } catch (\Throwable $e) {
                Logger::error($e, 'drive-trash');
            }
        }
    }

    // ---------- token และ HTTP ----------

    private function accessToken(): string
    {
        $s = self::state();
        if (!$s || empty($s['refresh_token'])) {
            throw new \RuntimeException('Drive not connected');
        }
        if (!empty($s['access_token']) && (int) ($s['expires_at'] ?? 0) > time()) {
            return (string) $s['access_token'];
        }
        try {
            $t = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $s['refresh_token']]);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'invalid_grant')) {
                // ถูกยกเลิกสิทธิ์ หรือ refresh token หมดอายุ (เช่น แอปโหมด Testing 7 วัน) → ต้องเชื่อมต่อใหม่
                $s['needs_reconnect'] = true;
                self::saveState($s);
            }
            throw $e;
        }
        $s['access_token'] = (string) ($t['access_token'] ?? '');
        $s['expires_at'] = time() + (int) ($t['expires_in'] ?? 3600) - 60;
        self::saveState($s);
        return $s['access_token'];
    }

    private function tokenRequest(array $fields): array
    {
        [$status, $body] = $this->rawRequest('POST', $this->g('token_url', 'https://oauth2.googleapis.com/token'),
            ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            http_build_query($fields + ['client_id' => $this->g('client_id'), 'client_secret' => $this->g('client_secret')]));
        $data = json_decode($body, true);
        if ($status >= 400 || !is_array($data)) {
            throw new \RuntimeException('Google token HTTP ' . $status . ' (' . (is_array($data) ? (string) ($data['error'] ?? '') : 'non-JSON') . ')');
        }
        return $data;
    }

    /** เรียก Google API แบบ JSON — $allow = รหัสสถานะที่ไม่ถือว่าผิดพลาด (คืน ['__status' => code]) */
    private function api(string $method, string $url, string $access, ?string $body = null, array $allow = [], string $type = 'application/json; charset=UTF-8'): array
    {
        $headers = ['Authorization: Bearer ' . $access, 'Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: ' . $type;
        }
        [$status, $raw] = $this->rawRequest($method, $url, $headers, $body);
        if (in_array($status, $allow, true)) {
            return ['__status' => $status];
        }
        $data = $raw === '' ? [] : json_decode($raw, true);
        if ($status >= 400 || !is_array($data)) {
            $reason = is_array($data) ? (string) ($data['error']['message'] ?? $data['error'] ?? '') : 'non-JSON';
            throw new \RuntimeException('Google Drive HTTP ' . $status . ' ' . $method . ' (' . mb_substr($reason, 0, 200) . ')');
        }
        return $data;
    }

    private function rawRequest(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $res = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) {
            throw new \RuntimeException('Google request failed: ' . $err);
        }
        return [$status, (string) $res];
    }
}
