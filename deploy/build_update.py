"""สร้างชุดไฟล์อัปเดตสำหรับอัปโหลดขึ้นโฮสต์ (เฉพาะไฟล์ที่เปลี่ยนตั้งแต่เวอร์ชันบนโฮสต์)

    python deploy/build_update.py

ผลลัพธ์: deploy/prompthub-update-1.9.0-to-1.10.0.zip (ไม่มี config/config.php, storage/ หรือไฟล์ข้อมูล)
"""
import os
import re
import zipfile

DEPLOY = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(DEPLOY)
FROM, TO = '1.9.0', re.search(r"APP_VERSION', '([0-9.]+)'", open(os.path.join(ROOT, 'app', 'version.php'), encoding='utf-8').read()).group(1)

# ไฟล์ที่เปลี่ยนตั้งแต่ 1.9.0 (ตรวจจากเวลาแก้ไขไฟล์ และเทียบ app.js / app.css บนเว็บจริงแล้วว่าตรงกับเครื่องนี้)
FILES = [
    'public/index.php',                         # 1.9.1 แก้ปุ่ม Google ค้าง (CSP form-action)
    'public/assets/js/app.js',                  # 1.11.0 ช่องรหัสผ่านในโปรไฟล์
    'app/version.php',                          # เลขเวอร์ชัน
    'app/routes.php',                           # 1.11.0 route ตั้งค่า/ทดสอบ Google
    'app/Core/App.php',                         # 1.11.0 ค่า Google จากหลังบ้าน
    'app/Controllers/AdminController.php',      # 1.10.0–1.11.0
    'app/Controllers/MemberController.php',     # 1.11.0 แก้ไขชื่อผู้ใช้/อีเมล
    'app/Services/AuthService.php',             # 1.11.0 แก้ไขชื่อผู้ใช้/อีเมล
    'app/Services/GoogleAuthService.php',       # 1.11.0 ปุ่มทดสอบกับ Google
    'app/Services/GoogleDriveService.php',      # 1.10.0 OAuth Client แยกสำหรับ Drive
    'app/Repositories/UserRepository.php',      # 1.11.0
    'app/Repositories/SettingRepository.php',   # 1.11.0
    'app/Repositories/ActivityLogRepository.php',  # 1.11.0
    'views/member/profile.php',                 # 1.11.0
    'views/admin/settings.php',                 # 1.11.0 การ์ด Google Login
    'views/admin/drive.php',                    # 1.10.0 คำแนะนำการตั้งค่า
]
FORBIDDEN = re.compile(r'(^config/config\.php$|^storage/|\.sql$|google-drive\.json$)')

GUIDE = """อัปเดต Prompt Hub {frm} → {to} บน krusomkhuan.com
=====================================================

1) อัปโหลดไฟล์ (ทับไฟล์เดิม) — ในโฮสต์ โฟลเดอร์ระบบคือ prompthub/
{files}

   * ไม่มี config/config.php และ storage/ ในชุดนี้ — ห้ามลบหรือทับของเดิมบนโฮสต์

2) เข้าสู่ระบบเว็บด้วยบัญชีผู้ดูแล > หลังบ้าน > ตั้งค่าเว็บไซต์ > การ์ด "เข้าสู่ระบบ / สมัครสมาชิกด้วย Google"
   - เปิดสวิตช์ ใส่ Client ID และ Client secret > บันทึกการตั้งค่า Google
   - กด "ทดสอบกับ Google" — ถ้าขึ้น redirect_uri_mismatch ให้ทำข้อ 3 แล้วทดสอบใหม่
   (ไม่ต้องแก้ config/config.php แล้ว — ถ้าเคยใส่ไว้ใน config ก็ใช้ได้ ค่าในหลังบ้านมีผลก่อน)

3) Google Cloud Console (OAuth Client เดียวกับ client_id ข้างบน)
   - Credentials > OAuth client > Authorized redirect URIs เพิ่มให้ตรงทุกตัวอักษร:
       https://krusomkhuan.com/prompthub/public/index.php?r=google.callback
       https://krusomkhuan.com/prompthub/public/index.php?r=admin.drive.callback   (ถ้าใช้ Client นี้กับ Drive)
   - OAuth consent screen > Audience: External > Publish app (In production)
     (Internal = ใช้ได้เฉพาะบัญชี @pticec.ac.th, Testing = ใช้ได้เฉพาะ Test users)
   - บันทึกแล้วรอสักครู่ (Google อาจใช้เวลา 5 นาที – ไม่กี่ชั่วโมงก่อนค่าใหม่มีผล)

4) ตรวจ
   - ส่วนท้ายเว็บต้องขึ้น "เวอร์ชัน {to}"
   - หน้าเข้าสู่ระบบ/สมัครสมาชิกต้องมีปุ่ม Google
   - แจ้งผู้ช่วยให้รันตัวตรวจเว็บจริง (deploy/check_live.py) อีกครั้ง
"""


def main():
    for f in FILES:
        assert not FORBIDDEN.search(f), f
        assert os.path.isfile(os.path.join(ROOT, f)), f
    out = os.path.join(DEPLOY, 'prompthub-update-%s-to-%s.zip' % (FROM, TO))
    guide = GUIDE.format(frm=FROM, to=TO, files='\n'.join('   - prompthub/' + f for f in FILES))
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
        for f in FILES:
            z.write(os.path.join(ROOT, f), 'prompthub/' + f)
        z.writestr('อ่านก่อนอัปโหลด.txt', '﻿' + guide.replace('\n', '\r\n'))
    open(os.path.join(DEPLOY, 'อ่านก่อนอัปโหลด.txt'), 'w', encoding='utf-8-sig', newline='\r\n').write(guide)
    print(out, os.path.getsize(out), 'bytes')
    for n in zipfile.ZipFile(out).namelist():
        print('  ' + n)


if __name__ == '__main__':
    main()
