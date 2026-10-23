<?php
declare(strict_types=1);

/**
 * Prompt Hub — Google OAuth callback
 * Google ส่งผู้ใช้กลับมาที่ไฟล์นี้หลังเลือกบัญชี (Authorized redirect URI ที่ต้องลงทะเบียนใน Google Cloud Console)
 *   เช่น https://krusomkhuan.com/prompthub/public/google_callback.php
 * ส่งต่อให้เส้นทาง google.callback (AuthController::googleCallback) ผ่าน Front Controller
 * จึงได้ Security Headers, การตรวจ state/PKCE และการจัดการข้อผิดพลาดชุดเดียวกับทุกหน้า
 */

$_GET['r'] = 'google.callback';
require __DIR__ . '/index.php';
