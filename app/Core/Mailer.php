<?php
declare(strict_types=1);

namespace PromptHub\Core;

/**
 * ส่งอีเมลข้อความธรรมดา (UTF-8) ผ่าน SMTP โดยตรง ไม่ต้องติดตั้งไลบรารีเพิ่ม
 * รองรับ STARTTLS (พอร์ต 587), SSL (พอร์ต 465) และ AUTH LOGIN — ใช้กับ Gmail App Password ได้
 */
final class Mailer
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(private array $cfg)
    {
    }

    public function enabled(): bool
    {
        return !empty($this->cfg['enabled'])
            && (string) ($this->cfg['host'] ?? '') !== ''
            && filter_var((string) ($this->cfg['from_email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false;
    }

    /** ส่งอีเมล 1 ฉบับ ล้มเหลวจะโยน RuntimeException (ข้อความไม่มีรหัสผ่าน SMTP) */
    public function send(string $toEmail, string $toName, string $subject, string $body): void
    {
        if (!$this->enabled()) {
            throw new \RuntimeException('Mail is not configured');
        }
        $from = (string) $this->cfg['from_email'];
        if (filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $toEmail . $from)) {
            throw new \RuntimeException('Invalid e-mail address');
        }
        $host = (string) $this->cfg['host'];
        $port = (int) ($this->cfg['port'] ?? 587);
        $enc = (string) ($this->cfg['encryption'] ?? 'tls');
        $timeout = (int) ($this->cfg['timeout'] ?? 15);

        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
        ]]);
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            throw new \RuntimeException("SMTP connect failed to {$host}:{$port} ({$errstr})");
        }
        $this->socket = $socket;
        stream_set_timeout($socket, $timeout);
        try {
            $this->expect([220]);
            $ehloHost = 'prompt-hub.local';
            $this->command('EHLO ' . $ehloHost, [250]);
            if ($enc === 'tls') {
                $this->command('STARTTLS', [220]);
                $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
                if (!@stream_socket_enable_crypto($socket, true, $method)) {
                    throw new \RuntimeException('STARTTLS negotiation failed');
                }
                $this->command('EHLO ' . $ehloHost, [250]);
            }
            $user = (string) ($this->cfg['username'] ?? '');
            if ($user !== '') {
                $this->command('AUTH LOGIN', [334]);
                $this->command(base64_encode($user), [334], 'username');
                $this->command(base64_encode((string) ($this->cfg['password'] ?? '')), [235], 'password');
            }
            $this->command('MAIL FROM:<' . $from . '>', [250]);
            $this->command('RCPT TO:<' . $toEmail . '>', [250, 251]);
            $this->command('DATA', [354]);
            $this->write($this->buildMessage($from, $toEmail, $toName, $subject, $body) . "\r\n.\r\n");
            $this->expect([250]);
            $this->command('QUIT', [221]);
        } finally {
            fclose($socket);
            $this->socket = null;
        }
    }

    private function buildMessage(string $from, string $to, string $toName, string $subject, string $body): string
    {
        $fromName = (string) ($this->cfg['from_name'] ?? '');
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . ($fromName !== '' ? self::encodeHeader($fromName) . ' ' : '') . '<' . $from . '>',
            'To: ' . ($toName !== '' ? self::encodeHeader($toName) . ' ' : '') . '<' . $to . '>',
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Auto-Submitted: auto-generated',
        ];
        // base64 ไม่มีบรรทัดที่ขึ้นต้นด้วย "." จึงไม่ต้อง dot-stuffing
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", $body)), 76, "\r\n"));
    }

    /** เข้ารหัสหัวเรื่อง/ชื่อภาษาไทยแบบ RFC 2047 และตัด CR/LF กัน header injection */
    private static function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function write(string $data): void
    {
        if (@fwrite($this->socket, $data) === false) {
            throw new \RuntimeException('SMTP write failed');
        }
    }

    /** ส่งคำสั่งและตรวจรหัสตอบกลับ $label ใช้แทนคำสั่งใน error เพื่อไม่ให้ข้อมูลลับหลุดลง log */
    private function command(string $line, array $codes, string $label = ''): string
    {
        $this->write($line . "\r\n");
        return $this->expect($codes, $label !== '' ? $label : $line);
    }

    private function expect(array $codes, string $after = 'connect'): string
    {
        $response = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            $meta = stream_get_meta_data($this->socket);
            $why = $meta['timed_out'] ? 'timeout' : trim($response);
            if (str_starts_with($after, 'AUTH') || in_array($after, ['username', 'password'], true)) {
                $after = 'AUTH ' . $after;
            }
            throw new \RuntimeException('SMTP error after ' . $after . ': ' . mb_substr($why, 0, 300));
        }
        return $response;
    }
}
