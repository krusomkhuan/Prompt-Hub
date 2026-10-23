<?php
declare(strict_types=1);

namespace PromptHub\Repositories;

use PromptHub\Core\Database;

final class SettingRepository
{
    public const KEYS = ['site_name', 'site_url', 'site_slogan', 'site_description', 'hero_badge', 'developer_name', 'copyright_owner', 'copyright_start_year', 'privacy_controller', 'privacy_contact', 'drive_folder_id', 'google_login_enabled', 'google_client_id', 'google_client_secret', 'google_redirect_uri', 'drive_backup_folder_id', 'drive_backup_last', 'shop_enabled', 'shop_promptpay', 'shop_account_name', 'shop_payment_note'];

    public function __construct(private Database $db)
    {
    }

    public function all(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT setting_key, setting_value FROM site_settings') as $row) {
            $out[$row['setting_key']] = $row['setting_value'];
        }
        return $out;
    }

    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!in_array($key, self::KEYS, true)) {
                continue;
            }
            $this->db->execute(
                'INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                [$key, $value]
            );
        }
    }
}
