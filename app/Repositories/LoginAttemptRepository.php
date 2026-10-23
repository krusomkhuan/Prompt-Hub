<?php
declare(strict_types=1);

namespace PromptHub\Repositories;

use PromptHub\Core\Database;

final class LoginAttemptRepository
{
    public function __construct(private Database $db)
    {
    }

    public function countForIdentifier(string $hash, int $minutes): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE identifier_hash = ? AND attempted_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE',
            [$hash, $minutes]
        );
    }

    public function countForIp(string $ip, int $minutes): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE',
            [$ip, $minutes]
        );
    }

    public function record(string $hash, string $ip): void
    {
        $this->db->execute('INSERT INTO login_attempts (identifier_hash, ip_address) VALUES (?, ?)', [$hash, $ip]);
    }

    public function clear(string $hash): void
    {
        $this->db->execute('DELETE FROM login_attempts WHERE identifier_hash = ?', [$hash]);
    }

    public function purgeOlderThanDay(): void
    {
        $this->db->execute('DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
    }
}
