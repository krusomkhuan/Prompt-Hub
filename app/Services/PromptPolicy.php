<?php
declare(strict_types=1);

namespace PromptHub\Services;

/** สิทธิ์การเข้าถึง Prompt — ใช้ร่วมกันทุกหน้าและทุก endpoint */
final class PromptPolicy
{
    public static function isPublic(array $prompt): bool
    {
        return $prompt['visibility'] === 'public' && $prompt['status'] === 'published';
    }

    public static function isOwner(?array $user, array $prompt): bool
    {
        return $user !== null && (int) $user['id'] === (int) $prompt['user_id'];
    }

    public static function isAdmin(?array $user): bool
    {
        return $user !== null && $user['role'] === 'admin' && $user['status'] === 'active';
    }

    /** ดูได้เมื่อเผยแพร่สาธารณะแล้ว หรือเป็นเจ้าของ หรือเป็นผู้ดูแล */
    public static function canView(?array $user, array $prompt): bool
    {
        return self::isPublic($prompt) || self::isOwner($user, $prompt) || self::isAdmin($user);
    }

    public static function canEdit(?array $user, array $prompt): bool
    {
        return self::isOwner($user, $prompt) || self::isAdmin($user);
    }
}
