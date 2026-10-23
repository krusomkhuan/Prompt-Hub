<?php
declare(strict_types=1);

namespace PromptHub\Services;

use PromptHub\Core\Database;

/**
 * ระบบสะสมแต้มและเหรียญรางวัลของสมาชิก
 *
 * แต้ม = จำนวน Prompt สาธารณะที่ผ่านการอนุมัติ (เผยแพร่อยู่) ของสมาชิก 1 รายการ = 1 แต้ม
 * นับสดจากฐานข้อมูลทุกครั้ง จึงตรงกับสถานะจริงเสมอ (ถูกถอนการเผยแพร่/ลบ แต้มลดตาม)
 * ฉบับร่าง Prompt ส่วนตัว และรายการรอตรวจสอบไม่นับ เพื่อกันการปั๊มแต้มด้วยรายการที่ไม่ผ่านการตรวจ
 * ให้แต้มเฉพาะบัญชีบทบาทสมาชิก (ผู้ดูแลไม่ร่วมสะสม)
 *
 *   5–10 แต้ม  เหรียญทองแดง
 *   11–20 แต้ม เหรียญเงิน
 *   21 แต้มขึ้นไป เหรียญทอง
 */
final class BadgeService
{
    /** เรียงจากเกณฑ์สูงไปต่ำ */
    public const TIERS = [
        ['key' => 'gold', 'label' => 'เหรียญทอง', 'min' => 21, 'max' => null],
        ['key' => 'silver', 'label' => 'เหรียญเงิน', 'min' => 11, 'max' => 20],
        ['key' => 'bronze', 'label' => 'เหรียญทองแดง', 'min' => 5, 'max' => 10],
    ];

    private const COUNTED = "p.status = 'published' AND p.visibility = 'public'";

    public function __construct(private Database $db)
    {
    }

    public static function tierFor(int $points): ?array
    {
        foreach (self::TIERS as $tier) {
            if ($points >= $tier['min']) {
                return $tier;
            }
        }
        return null;
    }

    /** เหรียญปัจจุบัน เหรียญถัดไป แต้มที่ยังขาด และเปอร์เซ็นต์ความคืบหน้าสู่เหรียญถัดไป */
    public static function progress(int $points): array
    {
        $tier = self::tierFor($points);
        $next = null;
        foreach (array_reverse(self::TIERS) as $t) {
            if ($points < $t['min']) {
                $next = $t;
                break;
            }
        }
        $from = $tier['min'] ?? 0;
        $percent = $next ? (int) floor(($points - $from) * 100 / max(1, $next['min'] - $from)) : 100;
        return [
            'points' => $points,
            'tier' => $tier,
            'next' => $next,
            'remaining' => $next ? $next['min'] - $points : 0,
            'percent' => max(0, min(100, $percent)),
        ];
    }

    public function points(int $userId): int
    {
        return $this->pointsFor([$userId])[$userId] ?? 0;
    }

    /** @param int[] $userIds @return array<int,int> user_id => แต้ม (เฉพาะสมาชิกที่มีแต้ม) */
    public function pointsFor(array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        if (!$ids) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT p.user_id, COUNT(*) AS points FROM prompts p JOIN users u ON u.id = p.user_id
             WHERE u.role = \'member\' AND ' . self::COUNTED . ' AND p.user_id IN (' . Database::placeholders(count($ids)) . ')
             GROUP BY p.user_id',
            $ids
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']] = (int) $r['points'];
        }
        return $out;
    }

    /** ทำเนียบสมาชิกที่มีแต้ม (บัญชีที่ใช้งานอยู่) เรียงตามแต้ม แล้วตามยอดคัดลอกรวม */
    public function leaderboard(int $limit = 50): array
    {
        return $this->db->all(
            'SELECT u.id, u.username, u.display_name, u.avatar_file, COUNT(*) AS points, SUM(p.copy_count) AS copies
             FROM users u JOIN prompts p ON p.user_id = u.id
             WHERE u.role = \'member\' AND u.status = \'active\' AND ' . self::COUNTED . '
             GROUP BY u.id, u.username, u.display_name, u.avatar_file
             ORDER BY points DESC, copies DESC, u.id ASC LIMIT ?',
            [$limit]
        );
    }
}
