<?php
declare(strict_types=1);

namespace PromptHub\Services;

use PromptHub\Core\Database;
use PromptHub\Core\HttpException;
use PromptHub\Core\Session;
use PromptHub\Repositories\UserRepository;

/**
 * สมัคร/เข้าสู่ระบบด้วยบัญชี Google (OAuth 2.0 Authorization Code + PKCE, OpenID Connect)
 *  - state กัน CSRF ของขั้นตอน callback, nonce ผูก id_token กับคำขอนี้, PKCE กันการขโมย code
 *  - แลก code กับ token ผ่านช่องทาง server-to-server (TLS) แล้วอ่านข้อมูลผู้ใช้จาก userinfo
 *  - ยอมรับเฉพาะอีเมลที่ Google ยืนยันแล้ว (email_verified)
 */
final class GoogleAuthService
{
    private const SESSION_KEY = 'google_oauth';
    public const PENDING_KEY = 'google_pending';

    public function __construct(private Database $db, private Session $session, private array $cfg, private string $redirectUri)
    {
    }

    public function enabled(): bool
    {
        return !empty($this->cfg['enabled']) && (string) ($this->cfg['client_id'] ?? '') !== '' && (string) ($this->cfg['client_secret'] ?? '') !== '';
    }

    private function endpoint(string $key, string $default): string
    {
        return (string) ($this->cfg[$key] ?? $default) ?: $default;
    }

    /** สร้าง URL หน้าเลือกบัญชีของ Google และเก็บ state/nonce/verifier ไว้ใน session (อายุ 10 นาที) */
    public function authorizationUrl(): string
    {
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $this->session->set(self::SESSION_KEY, ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'at' => time()]);
        return $this->endpoint('auth_url', 'https://accounts.google.com/o/oauth2/v2/auth') . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->cfg['client_id'],
            'redirect_uri' => $this->redirectUri,
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ]);
    }

    /** ตรวจ callback แล้วคืนข้อมูลผู้ใช้ Google: sub, email, name */
    public function handleCallback(array $query): array
    {
        $saved = $this->session->get(self::SESSION_KEY);
        $this->session->remove(self::SESSION_KEY);
        if (isset($query['error'])) {
            throw new HttpException(400, 'ยกเลิกการเข้าสู่ระบบด้วย Google แล้ว');
        }
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';
        if (!is_array($saved) || $state === '' || !hash_equals((string) $saved['state'], $state) || time() - (int) $saved['at'] > 600) {
            throw new HttpException(400, 'คำขอเข้าสู่ระบบด้วย Google หมดอายุหรือไม่ถูกต้อง กรุณาลองใหม่');
        }
        if ($code === '' || strlen($code) > 2048) {
            throw new HttpException(400, 'ไม่ได้รับรหัสยืนยันจาก Google');
        }
        $token = $this->post($this->endpoint('token_url', 'https://oauth2.googleapis.com/token'), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->cfg['client_id'],
            'client_secret' => $this->cfg['client_secret'],
            'code_verifier' => $saved['verifier'],
        ]);
        if (empty($token['access_token'])) {
            throw new \RuntimeException('Google token response without access_token');
        }
        // id_token ได้รับตรงจาก Google ผ่าน TLS (back channel) จึงตรวจ claims ได้โดยไม่ต้องตรวจลายเซ็น (OIDC Core 3.1.3.7)
        if (!empty($token['id_token'])) {
            $claims = self::decodeJwtPayload((string) $token['id_token']);
            $iss = (string) ($claims['iss'] ?? '');
            $aud = $claims['aud'] ?? '';
            $audOk = is_array($aud) ? in_array($this->cfg['client_id'], $aud, true) : $aud === $this->cfg['client_id'];
            if (!in_array($iss, $this->cfg['issuers'] ?? ['https://accounts.google.com', 'accounts.google.com'], true)
                || !$audOk || (int) ($claims['exp'] ?? 0) < time() || !hash_equals((string) $saved['nonce'], (string) ($claims['nonce'] ?? ''))) {
                throw new HttpException(400, 'ข้อมูลยืนยันตัวตนจาก Google ไม่ถูกต้อง');
            }
        }
        $info = $this->get($this->endpoint('userinfo_url', 'https://openidconnect.googleapis.com/v1/userinfo'), (string) $token['access_token']);
        $sub = (string) ($info['sub'] ?? '');
        $email = strtolower((string) ($info['email'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $sub) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpException(400, 'ไม่ได้รับข้อมูลบัญชีจาก Google');
        }
        if (empty($info['email_verified'])) {
            throw new HttpException(400, 'อีเมลของบัญชี Google นี้ยังไม่ได้ยืนยัน');
        }
        if (isset($claims['sub']) && (string) $claims['sub'] !== $sub) {
            throw new HttpException(400, 'ข้อมูลยืนยันตัวตนจาก Google ไม่ตรงกัน');
        }
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) ($info['name'] ?? '')) ?? '');
        return ['sub' => $sub, 'email' => $email, 'name' => mb_substr($name !== '' ? $name : strstr($email, '@', true), 0, 100)];
    }

    /**
     * ทดสอบการตั้งค่ากับ Google โดยไม่เข้าสู่ระบบบัญชีใด: เปิด URL หน้าเลือกบัญชีแล้วดูว่า Google แจ้งข้อผิดพลาดหรือไม่
     * คืน ['ok' => bool, 'code' => รหัสข้อผิดพลาดของ Google|null]
     */
    public function diagnose(): array
    {
        $url = $this->endpoint('auth_url', 'https://accounts.google.com/o/oauth2/v2/auth') . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->cfg['client_id'] ?? '',
            'redirect_uri' => $this->redirectUri,
            'scope' => 'openid email profile',
            'state' => bin2hex(random_bytes(8)),
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', 'diagnose', true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ]);
        $authHost = (string) parse_url($url, PHP_URL_HOST);
        $codes = ['redirect_uri_mismatch', 'invalid_client', 'deleted_client', 'disabled_client', 'unauthorized_client', 'org_internal', 'invalid_request', 'access_denied'];
        for ($hop = 0; $hop < 6; $hop++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => ['Accept-Language: en'],
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36',
            ]);
            $res = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);
            if ($res === false) {
                return ['ok' => false, 'code' => 'network'];
            }
            foreach ($codes as $c) {
                if (str_contains((string) $res, $c) || str_contains($location, $c)) {
                    return ['ok' => false, 'code' => $c];
                }
            }
            if ($status >= 300 && $status < 400 && $location !== '') {
                if (str_starts_with($location, $this->redirectUri)) {
                    return ['ok' => true, 'code' => null]; // Google ส่งกลับมาที่เว็บ (เช่น ผ่านการอนุญาตแล้ว)
                }
                if ((string) parse_url($location, PHP_URL_HOST) !== $authHost) {
                    return ['ok' => true, 'code' => null];
                }
                $url = $location;
                continue;
            }
            return $status === 200 ? ['ok' => true, 'code' => null] : ['ok' => false, 'code' => 'http_' . $status];
        }
        return ['ok' => true, 'code' => null];
    }

    /**
     * หาบัญชีที่ผูกกับ Google แล้ว หรือผูกกับบัญชีเดิมที่ใช้อีเมลเดียวกัน
     * คืน [ผู้ใช้|null, ผูกใหม่หรือไม่]  ผู้ใช้ null = ยังไม่มีบัญชี ต้องยืนยันสร้างบัญชี
     */
    public function resolveUser(array $g): array
    {
        $user = $this->db->one('SELECT * FROM users WHERE google_sub = ?', [$g['sub']]);
        if ($user) {
            return [$user, false];
        }
        $user = $this->db->one('SELECT * FROM users WHERE email = ?', [$g['email']]);
        if ($user) {
            if ($user['google_sub'] !== null && $user['google_sub'] !== $g['sub']) {
                throw new HttpException(409, 'อีเมลนี้ผูกกับบัญชี Google อื่นอยู่แล้ว');
            }
            $this->db->execute('UPDATE users SET google_sub = ? WHERE id = ?', [$g['sub'], (int) $user['id']]);
            $user['google_sub'] = $g['sub'];
            return [$user, true];
        }
        return [null, false];
    }

    /** สร้างบัญชีใหม่จากข้อมูล Google (บัญชีนี้ไม่มีรหัสผ่าน ตั้งภายหลังได้ด้วย “ลืมรหัสผ่าน”) */
    public function createUser(array $g, string $username, string $displayName): array
    {
        $users = new UserRepository($this->db);
        $id = $this->db->transaction(function () use ($users, $g, $username, $displayName): int {
            $id = $users->create($username, $g['email'], $displayName, '!google', 'member', true);
            $this->db->execute('UPDATE users SET google_sub = ? WHERE id = ?', [$g['sub'], $id]);
            return $id;
        });
        return $users->find($id);
    }

    /** ชื่อผู้ใช้เริ่มต้นจากส่วนหน้าของอีเมล ปรับให้ตรงรูปแบบและไม่ซ้ำ */
    public function suggestUsername(string $email): string
    {
        $base = preg_replace('/[^A-Za-z0-9_]/', '_', strstr($email, '@', true) ?: 'member') ?? 'member';
        $base = substr(trim($base, '_'), 0, 24);
        if (strlen($base) < 3) {
            $base = 'member';
        }
        $users = new UserRepository($this->db);
        $candidate = $base;
        for ($i = 0; $i < 20 && $users->usernameExists($candidate); $i++) {
            $candidate = $base . '_' . random_int(100, 9999);
        }
        return $candidate;
    }

    private static function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            throw new HttpException(400, 'ข้อมูลยืนยันตัวตนจาก Google ไม่ถูกต้อง');
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4), true);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data)) {
            throw new HttpException(400, 'ข้อมูลยืนยันตัวตนจาก Google ไม่ถูกต้อง');
        }
        return $data;
    }

    private function post(string $url, array $fields): array
    {
        return $this->request($url, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields)]);
    }

    private function get(string $url, string $accessToken): array
    {
        return $this->request($url, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken]]);
    }

    private function request(string $url, array $opts): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => array_merge($opts[CURLOPT_HTTPHEADER] ?? [], ['Accept: application/json']),
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException('Google request failed: ' . $err);
        }
        $data = json_decode((string) $body, true);
        if ($status >= 400 || !is_array($data)) {
            // ไม่บันทึก body เต็มเพราะอาจมีข้อมูล token
            throw new \RuntimeException('Google responded HTTP ' . $status . ' (' . (is_array($data) ? (string) ($data['error'] ?? '') : 'non-JSON') . ')');
        }
        return $data;
    }
}
