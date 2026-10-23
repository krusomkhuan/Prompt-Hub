# รูปแบบการเขียนโค้ด (ตามโค้ดจริงในระบบ)

ทุกไฟล์ PHP ขึ้นต้นด้วย `declare(strict_types=1);` namespace `PromptHub\...` ตรงกับโฟลเดอร์ใน `app/`
คลาสส่วนใหญ่เป็น `final` ใช้ constructor property promotion คอมเมนต์อธิบายเป็นภาษาไทย สั้น ตรงจุด

## Route

```php
// app/routes.php — ชื่อเส้นทาง => [HTTP method, Controller, เมธอด, สิทธิ์ any|guest|member|admin]
'profile.avatar' => ['POST', M::class, 'avatarUpdate', 'member'],
'avatar'         => ['GET',  H::class, 'avatar', 'any'],
```
ชื่อเส้นทางใช้ได้เฉพาะ `[a-z.]` ยาวไม่เกิน 40 ตัวอักษร

## Controller

```php
public function avatarUpdate(): void
{
    $user = $this->auth->requireMember();
    try {
        (new AvatarService($this->db))->update((int) $user['id'], $_FILES['avatar'] ?? null);
    } catch (ValidationException $e) {
        // ฟอร์มผิด: แสดงหน้าเดิมพร้อมข้อผิดพลาด สถานะ 422
        $this->render('member/profile', ['title' => 'โปรไฟล์', 'errors' => $e->errors(), 'old' => []], 422);
        return;
    }
    $this->flash('success', 'เปลี่ยนภาพโปรไฟล์แล้ว');
    redirect('profile');            // Post/Redirect/Get
}
```

ตัวช่วยใน `Controller`: `query($key, $max)`, `queryId($key)`, `queryIn($key, $allowed, $default)`, `page()`, `postId()`, `postString()`, `pagination($total, $page, $perPage)`, `render()`, `json()`, `flash()`
หลังบ้านใช้ `$this->guard(fn() => ..., 'admin.users', $params)` เพื่อแปลง ValidationException / duplicate key (1062) / FK (1451) เป็นข้อความ flash

## Validator

```php
$v = new Validator($_POST);
$name = $v->string('display_name', 'ชื่อที่แสดง', 1, 100);   // ตัดช่องว่าง ตรวจความยาว (นับแบบ mb)
$year = $v->int('copyright_start_year', 'ปีที่เริ่มเผยแพร่ (ค.ศ.)', 2000, 2100);
$vis  = $v->in('visibility', 'การมองเห็น', ['private', 'public']);
$v->validate();   // โยน ValidationException(['field' => 'ข้อความภาษาไทย'])
```
อื่น ๆ: `text()` (หลายบรรทัด), `username()`, `email()`, `password()`, `ids()` (รายการ id), `bool()`, `addError()`

## Database (MySQLi)

```php
$rows = $this->db->all('SELECT id, name FROM tags WHERE name LIKE ? LIMIT ?', ['%' . Database::like($q) . '%', 20]);
$row  = $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
$n    = (int) $this->db->value('SELECT COUNT(*) FROM prompts WHERE user_id = ?', [$id]);
$this->db->execute('UPDATE users SET avatar_file = NULL WHERE id = ?', [$id]);   // คืนจำนวนแถว
$id   = $this->db->lastId();
$in   = Database::placeholders(count($ids));  // "?,?,?"

$old = $this->db->transaction(function () use ($userId, $name) {
    $old = $this->db->value('SELECT avatar_file FROM users WHERE id = ? FOR UPDATE', [$userId]);
    $this->db->execute('UPDATE users SET avatar_file = ? WHERE id = ?', [$name, $userId]);
    return $old;
});
AvatarService::deleteFile($old);   // ลบไฟล์หลัง commit เท่านั้น
```
- การเชื่อมต่อตั้ง `time_zone = '+00:00'` และ utf8mb4 · ใช้ `UTC_TIMESTAMP()` ใน SQL
- transaction ซ้อนได้ (ชั้นในรันตรง ๆ) · ใช้ `FOR UPDATE` เมื่อต้องกันการแข่งกัน (เช่น ต้องเหลือผู้ดูแล ≥ 1)
- ORDER BY ต้องมาจาก whitelist เช่น `PromptRepository::SORTS`

## View

```php
<h1 class="h3 mb-3"><?= e($title) ?></h1>
<form method="post" action="<?= e(url('profile.avatar')) ?>" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <input class="form-control<?= invalid($errors, 'avatar') ?>" type="file" id="avatar" name="avatar" ...>
    <?= field_error($errors, 'avatar') ?>
</form>
<form method="post" action="<?= e(url('profile.avatar.delete')) ?>" data-confirm="ลบภาพโปรไฟล์?">...</form>
```
- helper ที่ใช้บ่อย: `e()`, `url()` (ลิงก์ภายใน), `absolute_url()` (ลิงก์เต็มสำหรับอีเมล/OAuth/แชร์), `normalize_site_url()`, `asset()`, `old($old, 'key', $default)`, `invalid()`, `field_error()`, `thai_date()`, `number_th()`, `status_badge()`, `visibility_label()`, `str_limit()`, `avatar($id, $name, $file, 'sm|md|lg')`, `medal_badge($points)`, `copyright_notice()`
- helper ที่คืน HTML (เช่น `status_badge`, `avatar`, `medal_badge`) escape ข้างในแล้ว ห้ามครอบด้วย `e()` ซ้ำ
- `data-confirm="..."` บน `<form>` = กล่องยืนยันก่อนส่ง (จัดการใน app.js)
- ห้าม `style="..."` และ `<script>` ในหน้า ใช้ class ใน `app.css` และ data-attribute + โค้ดใน `app.js`
- ไอคอน Bootstrap Icons: `<i class="bi bi-award-fill"></i>`
- ข้อความทุกอย่างภาษาไทย ปุ่มอันตรายใช้ `btn-outline-danger`

## JavaScript (public/assets/js/app.js)

- ไฟล์เดียว ห่อด้วย IIFE `'use strict'` ES5 + Promise (ไม่ใช้ build tool)
- หาองค์ประกอบด้วย id/data-attribute แล้ว `if (!el) return` เพื่อให้ไฟล์เดียวใช้ได้ทุกหน้า
- ใช้ `textContent` ไม่ใช้ `innerHTML` กับข้อมูลผู้ใช้
- ตัวอย่าง: `input[type=file][data-resize-max]` ย่อภาพก่อนอัปโหลด, `.progress[aria-valuenow] .points-bar` ตั้งความกว้างแถบ (เพราะ CSP ห้าม style inline)

## CSS / ธีม

โหมดมืด `data-bs-theme="dark"` พื้น `#020617` ไล่สี `#9333ea → #2563eb` สีเน้น `#c084fc` ฟอนต์ Sarabun เขียนต่อท้าย `app.css` เป็นกลุ่มพร้อมคอมเมนต์ภาษาไทย ทดสอบที่ความกว้าง 375px ไม่ให้ล้นแนวนอน

## CLI (bin/)

```php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
```
ข้อความผ่าน `Console::line()` / `Console::error()` · คำสั่งที่แก้ข้อมูลจำนวนมากควรมี `--dry-run`

## Migration

```sql
-- ภาพโปรไฟล์ของผู้ใช้ (สำหรับฐานข้อมูลที่ติดตั้งก่อนมีฟีเจอร์นี้)
-- ติดตั้งใหม่ไม่ต้องรันไฟล์นี้ เพราะรวมอยู่ใน schema.sql แล้ว
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE users ADD COLUMN avatar_file VARCHAR(64) NULL AFTER google_sub;
```
ใช้ `INSERT IGNORE` กับข้อมูลตั้งต้น เพื่อรันซ้ำได้ปลอดภัย
