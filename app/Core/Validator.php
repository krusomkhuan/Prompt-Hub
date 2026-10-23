<?php
declare(strict_types=1);

namespace PromptHub\Core;

/**
 * ตรวจชนิด ความยาว และค่าที่อนุญาตของข้อมูลจากฟอร์ม
 * เรียก validate() ตอนท้ายเพื่อโยน ValidationException ถ้ามีข้อผิดพลาด
 */
final class Validator
{
    private array $errors = [];

    public function __construct(private array $input)
    {
    }

    private function raw(string $field): ?string
    {
        $v = $this->input[$field] ?? null;
        if ($v === null) {
            return null;
        }
        if (!is_string($v) && !is_int($v)) {
            return "\0invalid";
        }
        return (string) $v;
    }

    /** ข้อความบรรทัดเดียว ตัดช่องว่างหัวท้าย */
    public function string(string $field, string $label, int $min, int $max): string
    {
        return $this->clean($field, $label, $min, $max, false);
    }

    /** ข้อความหลายบรรทัด เก็บบรรทัดเดิม (แปลง CRLF เป็น LF) */
    public function text(string $field, string $label, int $min, int $max): string
    {
        return $this->clean($field, $label, $min, $max, true);
    }

    private function clean(string $field, string $label, int $min, int $max, bool $multiline): string
    {
        $v = $this->raw($field) ?? '';
        if ($v === "\0invalid" || !mb_check_encoding($v, 'UTF-8')) {
            $this->errors[$field] = "{$label}มีรูปแบบไม่ถูกต้อง";
            return '';
        }
        if ($multiline) {
            $v = str_replace(["\r\n", "\r"], "\n", $v);
            $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
            $check = trim($v);
            $v = rtrim($v);
            $v = ltrim($v, "\n");
        } else {
            $v = preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '';
            $v = trim($v);
            $check = $v;
        }
        $len = mb_strlen($check);
        if ($min > 0 && $len === 0) {
            $this->errors[$field] = "กรุณากรอก{$label}";
        } elseif ($len < $min) {
            $this->errors[$field] = "{$label}ต้องมีอย่างน้อย {$min} ตัวอักษร";
        } elseif (mb_strlen($v) > $max) {
            $this->errors[$field] = "{$label}ต้องไม่เกิน {$max} ตัวอักษร";
        }
        return $v;
    }

    public function username(string $field): string
    {
        $v = $this->string($field, 'ชื่อผู้ใช้', 3, 30);
        if (!isset($this->errors[$field]) && !preg_match('/^[A-Za-z0-9_]{3,30}$/D', $v)) {
            $this->errors[$field] = 'ชื่อผู้ใช้ใช้ได้เฉพาะ a-z, A-Z, 0-9 และ _ ยาว 3–30 ตัว';
        }
        return $v;
    }

    public function email(string $field): string
    {
        $v = $this->string($field, 'อีเมล', 3, 190);
        if (!isset($this->errors[$field]) && filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$field] = 'รูปแบบอีเมลไม่ถูกต้อง';
        }
        return strtolower($v);
    }

    /** รหัสผ่าน: ไม่ตัดช่องว่าง จำกัด 72 ไบต์ตามข้อจำกัดของ bcrypt */
    public function password(string $field, string $label, int $min = 8): string
    {
        $v = $this->raw($field) ?? '';
        if ($v === "\0invalid" || !mb_check_encoding($v, 'UTF-8')) {
            $this->errors[$field] = "{$label}ไม่ถูกต้อง";
            return '';
        }
        if ($v === '') {
            $this->errors[$field] = "กรุณากรอก{$label}";
        } elseif (mb_strlen($v) < $min) {
            $this->errors[$field] = "{$label}ต้องมีอย่างน้อย {$min} ตัวอักษร";
        } elseif (strlen($v) > 72) {
            $this->errors[$field] = "{$label}ยาวเกินไป (ไม่เกิน 72 ไบต์)";
        }
        return $v;
    }

    public function int(string $field, string $label, int $min = 1, int $max = PHP_INT_MAX): int
    {
        $v = $this->raw($field);
        if ($v === null || $v === '' || !preg_match('/^-?\d{1,10}$/D', $v) || (int) $v < $min || (int) $v > $max) {
            $this->errors[$field] = "กรุณาเลือกหรือระบุ{$label}ให้ถูกต้อง";
            return 0;
        }
        return (int) $v;
    }

    public function in(string $field, string $label, array $allowed): string
    {
        $v = $this->raw($field);
        if ($v === null || !in_array($v, $allowed, true)) {
            $this->errors[$field] = "{$label}ไม่ถูกต้อง";
            return (string) $allowed[0];
        }
        return $v;
    }

    /** รายการรหัส (checkbox หลายค่า) คืน array ของ int ที่ไม่ซ้ำ */
    public function ids(string $field, string $label, int $maxCount): array
    {
        $v = $this->input[$field] ?? [];
        if ($v === '' || $v === null) {
            return [];
        }
        if (!is_array($v) || count($v) > $maxCount) {
            $this->errors[$field] = "{$label}ไม่ถูกต้อง (เลือกได้ไม่เกิน {$maxCount} รายการ)";
            return [];
        }
        $out = [];
        foreach ($v as $item) {
            if (!is_string($item) && !is_int($item) || !preg_match('/^\d{1,10}$/D', (string) $item)) {
                $this->errors[$field] = "{$label}ไม่ถูกต้อง";
                return [];
            }
            $out[(int) $item] = (int) $item;
        }
        return array_values($out);
    }

    public function bool(string $field): bool
    {
        $v = $this->input[$field] ?? '';
        return in_array($v, ['1', 'on', 'true', 1, true], true);
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }

    public function hasError(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    public function validate(): void
    {
        if ($this->errors) {
            throw new ValidationException($this->errors);
        }
    }
}
