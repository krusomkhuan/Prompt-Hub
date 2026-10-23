<?php
// ตัวอย่างการตั้งค่า — คัดลอกเป็น config/config.php แล้วแก้ค่าให้ตรงกับเครื่อง
// config/config.php มีข้อมูลลับ ห้าม commit และห้ามวางไว้ในโฟลเดอร์ public
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'prompt_hub',
        'user' => 'root',
        'pass' => '',
    ],
    'app' => [
        // URL path ของโฟลเดอร์ public (XAMPP: /prompt_hub/public) หรือ '' ถ้า DocumentRoot ชี้ไปที่ public
        'base_url' => '/prompt_hub/public',
        'display_timezone' => 'Asia/Bangkok',
        // true เมื่อเว็บใช้ HTTPS เพื่อส่ง session cookie เฉพาะ HTTPS
        'secure_cookie' => false,
        // อายุ session เมื่อไม่มีการใช้งาน (วินาที)
        'session_idle_seconds' => 7200,
        // ที่อยู่เว็บแบบเต็ม (ไม่มี / ท้าย) ใช้สร้างลิงก์ในอีเมล — ไม่อ่านจาก Host header เพื่อกันการปลอมลิงก์
        'app_url' => 'http://localhost',
    ],
    // ส่งอีเมลลิงก์ตั้งรหัสผ่านใหม่ผ่าน SMTP
    // Gmail: host smtp.gmail.com, port 587, encryption tls, username = อีเมล, password = App Password 16 หลัก
    //        (ต้องเปิด 2-Step Verification ก่อน แล้วสร้างที่ https://myaccount.google.com/apppasswords)
    'mail' => [
        'enabled' => false,
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'encryption' => 'tls',      // tls (STARTTLS, พอร์ต 587) | ssl (พอร์ต 465) | none
        'username' => '',
        'password' => '',
        'from_email' => '',
        'from_name' => 'Prompt Hub',
        'timeout' => 15,
    ],
    // สมัคร/เข้าสู่ระบบด้วยบัญชี Google (OAuth 2.0) สร้าง Client ID ที่ https://console.cloud.google.com/apis/credentials
    // ชนิด Web application และใส่ Authorized redirect URI = app_url + base_url + /index.php?r=google.callback
    // เช่น http://localhost/prompt_hub/public/index.php?r=google.callback
    'google' => [
        'enabled' => false,
        'client_id' => '',
        'client_secret' => '',
    ],
    // เก็บภาพตัวอย่างใน Google Drive (เปิด Google Drive API และเพิ่ม redirect URI ที่หน้า หลังบ้าน › Google Drive แสดง)
    // แนะนำ OAuth Client แยกสำหรับ Drive แบบ Internal ของโดเมน — ถ้าไม่ใส่ จะใช้ client_id/secret ของส่วน google ด้านบน
    // (ถ้าส่วน google เปิดให้สมาชิกทั่วไปสมัคร ต้องเป็น External จึงควรแยก Client)
    'drive' => [
        'client_id' => '',
        'client_secret' => '',
    ],
];
