<?php
declare(strict_types=1);

namespace PromptHub\Core;

/** ข้อมูลจากฟอร์มไม่ผ่านการตรวจสอบ เก็บข้อความแยกตามชื่อฟิลด์ */
final class ValidationException extends \RuntimeException
{
    public function __construct(private array $errors)
    {
        parent::__construct(implode(' ', $errors) ?: 'ข้อมูลไม่ถูกต้อง');
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
