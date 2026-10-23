<?php
declare(strict_types=1);

namespace PromptHub\Core;

/** ข้อผิดพลาดที่แสดงต่อผู้ใช้ได้ พร้อมรหัสสถานะ HTTP มาตรฐาน */
class HttpException extends \RuntimeException
{
    public function __construct(private int $status, string $message)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
