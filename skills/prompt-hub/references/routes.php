<?php
declare(strict_types=1);

use PromptHub\Controllers\AdminController as A;
use PromptHub\Controllers\AuthController as Au;
use PromptHub\Controllers\HomeController as H;
use PromptHub\Controllers\MemberController as M;
use PromptHub\Controllers\PromptController as P;

// ชื่อเส้นทาง => [HTTP method, Controller, เมธอด, สิทธิ์ any|guest|member|admin]
// คำขอที่เปลี่ยนข้อมูลทั้งหมดเป็น POST และผ่านการตรวจ CSRF ใน Router
return [
    // หน้าบ้าน
    'home' => ['GET', H::class, 'index', 'any'],
    'about' => ['GET', H::class, 'about', 'any'],
    'privacy' => ['GET', H::class, 'privacy', 'any'],
    'prompts' => ['GET', P::class, 'index', 'any'],
    'prompt' => ['GET', P::class, 'show', 'any'],
    'prompt.copy' => ['POST', P::class, 'copy', 'member'],
    'media' => ['GET', P::class, 'media', 'any'],
    'categories' => ['GET', P::class, 'categories', 'any'],
    'leaders' => ['GET', H::class, 'leaders', 'any'],
    'avatar' => ['GET', H::class, 'avatar', 'any'],
    'category' => ['GET', P::class, 'category', 'any'],

    // สมาชิก
    'login' => ['GET', Au::class, 'loginForm', 'guest'],
    'login.submit' => ['POST', Au::class, 'login', 'guest'],
    'register' => ['GET', Au::class, 'registerForm', 'guest'],
    'register.submit' => ['POST', Au::class, 'register', 'guest'],
    'forgot' => ['GET', Au::class, 'forgotForm', 'guest'],
    'forgot.submit' => ['POST', Au::class, 'forgot', 'guest'],
    'reset' => ['GET', Au::class, 'resetForm', 'guest'],
    'reset.submit' => ['POST', Au::class, 'reset', 'guest'],
    'google.start' => ['POST', Au::class, 'googleStart', 'guest'],
    'google.callback' => ['GET', Au::class, 'googleCallback', 'any'],
    'google.confirm' => ['GET', Au::class, 'googleConfirm', 'guest'],
    'google.finish' => ['POST', Au::class, 'googleFinish', 'guest'],
    'logout' => ['POST', Au::class, 'logout', 'member'],
    'dashboard' => ['GET', M::class, 'dashboard', 'member'],
    'my.prompts' => ['GET', M::class, 'myPrompts', 'member'],
    'my.prompt.form' => ['GET', M::class, 'form', 'member'],
    'my.prompt.save' => ['POST', M::class, 'save', 'member'],
    'my.prompt.delete' => ['POST', M::class, 'delete', 'member'],
    'favorites' => ['GET', M::class, 'favorites', 'member'],
    'favorite.toggle' => ['POST', M::class, 'favorite', 'member'],
    'profile' => ['GET', M::class, 'profile', 'member'],
    'profile.update' => ['POST', M::class, 'profileUpdate', 'member'],
    'password.update' => ['POST', M::class, 'passwordUpdate', 'member'],
    'profile.avatar' => ['POST', M::class, 'avatarUpdate', 'member'],
    'profile.avatar.delete' => ['POST', M::class, 'avatarDelete', 'member'],

    // หลังบ้าน
    'admin' => ['GET', A::class, 'dashboard', 'admin'],
    'admin.users' => ['GET', A::class, 'users', 'admin'],
    'admin.user.action' => ['POST', A::class, 'userAction', 'admin'],
    'admin.categories' => ['GET', A::class, 'categories', 'admin'],
    'admin.category.save' => ['POST', A::class, 'categorySave', 'admin'],
    'admin.category.delete' => ['POST', A::class, 'categoryDelete', 'admin'],
    'admin.tags' => ['GET', A::class, 'tags', 'admin'],
    'admin.tag.save' => ['POST', A::class, 'tagSave', 'admin'],
    'admin.tag.delete' => ['POST', A::class, 'tagDelete', 'admin'],
    'admin.tools' => ['GET', A::class, 'tools', 'admin'],
    'admin.tool.save' => ['POST', A::class, 'toolSave', 'admin'],
    'admin.tool.delete' => ['POST', A::class, 'toolDelete', 'admin'],
    'admin.prompts' => ['GET', A::class, 'prompts', 'admin'],
    'admin.prompt.moderate' => ['POST', A::class, 'moderate', 'admin'],
    'admin.settings' => ['GET', A::class, 'settings', 'admin'],
    'admin.settings.save' => ['POST', A::class, 'settingsSave', 'admin'],
    'admin.google.save' => ['POST', A::class, 'googleSave', 'admin'],
    'admin.google.test' => ['POST', A::class, 'googleTest', 'admin'],
    'admin.logs' => ['GET', A::class, 'logsPage', 'admin'],
    'admin.drive' => ['GET', A::class, 'drivePage', 'admin'],
    'admin.drive.save' => ['POST', A::class, 'driveSave', 'admin'],
    'admin.drive.connect' => ['POST', A::class, 'driveConnect', 'admin'],
    'admin.drive.callback' => ['GET', A::class, 'driveCallback', 'admin'],
    'admin.drive.disconnect' => ['POST', A::class, 'driveDisconnect', 'admin'],
    'admin.drive.sync' => ['POST', A::class, 'driveSync', 'admin'],
];
