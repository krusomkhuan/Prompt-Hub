"""ทดสอบระบบลืมรหัสผ่านกับเว็บจริง + เซิร์ฟเวอร์ SMTP จำลอง (ไม่ส่งอีเมลออกภายนอก)

    python tests/password_reset.py --allow-test-data

ระหว่างทดสอบจะแก้ส่วน 'mail' ใน config/config.php ให้ชี้ไปที่ 127.0.0.1:2525 ชั่วคราว
และคืนไฟล์เดิมทุกครั้งเมื่อจบ (รวมกรณีทดสอบล้มเหลว)
"""
import email
import email.policy
import hashlib
import http.cookiejar
import json
import os
import re
import shlex
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_smtp import FakeSmtp  # noqa: E402

if '--allow-test-data' not in sys.argv:
    raise SystemExit('ใช้ --allow-test-data เพื่อยืนยันว่ารันกับฐานข้อมูลที่สร้างข้อมูลทดสอบได้')

BASE = os.environ.get('PROMPT_HUB_BASE', 'http://localhost/prompt_hub/public/index.php')
PHP = shlex.split(os.environ.get('PROMPT_HUB_PHP', 'C:/xampp/php/php.exe'))
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CONFIG = os.path.join(ROOT, 'config', 'config.php')
SMTP_PORT = 2525
SMTP_USER = 'smtp-test-user'
SMTP_PASS = 'smtp-test-' + os.urandom(6).hex()
passed = []


def check(ok, name):
    if not ok:
        raise AssertionError('FAIL: ' + name)
    passed.append(name)
    print('PASS', name)


def php(*args, stdin=None):
    return subprocess.run(PHP + list(args), input=stdin, capture_output=True, text=True, encoding='utf-8', cwd=ROOT)


def scalar(query, *params):
    r = php('tests/fixtures.php', 'query', stdin=json.dumps({'sql': query, 'params': list(params)}))
    rows = json.loads(r.stdout)
    return list(rows[0].values())[0] if rows else None


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def req(self, route, data=None, headers=None, url=None, **query):
        url = url or BASE + '?' + urllib.parse.urlencode(dict(r=route, **query))
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        try:
            res = self.opener.open(urllib.request.Request(url, data=body, headers=headers or {}))
            return res.status, res.read().decode('utf-8'), res.url
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8'), e.url

    def post(self, route, data, csrf=True, headers=None):
        data = dict(data)
        if csrf:
            _, html, _ = self.req('login')
            data['_csrf'] = re.search(r'name="_csrf" value="([a-f0-9]{64})"', html).group(1)
        return self.req(route, data, headers=headers)


def set_mail_config(text, password):
    block = ("    'mail' => [\n        'enabled' => true,\n        'host' => '127.0.0.1',\n        'port' => %d,\n"
             "        'encryption' => 'none',\n        'username' => '%s',\n        'password' => '%s',\n"
             "        'from_email' => 'noreply@example.invalid',\n        'from_name' => 'Prompt Hub ทดสอบ',\n"
             "        'timeout' => 5,\n    ],\n") % (SMTP_PORT, SMTP_USER, password)
    new, n = re.subn(r"    'mail' => \[.*?\n    \],\n", lambda m: block, text, flags=re.S)
    assert n == 1, 'ไม่พบส่วน mail ใน config.php'
    with open(CONFIG, 'w', encoding='utf-8', newline='\n') as f:
        f.write(new)


def parse(msg):
    m = email.message_from_bytes(msg['data'], policy=email.policy.default)
    return m, m.get_content()


original_config = open(CONFIG, encoding='utf-8').read()
smtp = FakeSmtp(SMTP_PORT, SMTP_USER, SMTP_PASS).start()
fx = json.loads(php('tests/fixtures.php', 'create').stdout)
SUFFIX, PW = fx['suffix'], fx['password']
member_email = fx['member']['username'] + '@example.invalid'
log_dir = os.path.join(ROOT, 'storage', 'logs')

try:
    guest = Client()
    check(guest.post('forgot.submit', {'email': member_email}, csrf=False)[0] == 403, 'คำขอลืมรหัสผ่านต้องมี CSRF Token')

    # ปิดอีเมล (ค่าเริ่มต้น): หน้าแจ้งว่ายังไม่ตั้งค่า และไม่สร้าง token
    check('ยังไม่ได้ตั้งค่าการส่งอีเมล' in guest.req('forgot')[1], 'หน้าลืมรหัสผ่านแจ้งเมื่อยังไม่ตั้งค่าอีเมล')

    set_mail_config(original_config, SMTP_PASS)
    check('ยังไม่ได้ตั้งค่าการส่งอีเมล' not in guest.req('forgot')[1], 'เปิดใช้อีเมลแล้วฟอร์มพร้อมใช้งาน')

    # อีเมลที่ไม่มีบัญชี: ข้อความเหมือนกัน ไม่ส่งอีเมล
    code, html_unknown, _ = Client().post('forgot.submit', {'email': 'nobody_' + SUFFIX + '@example.invalid'})
    check(code == 200 and 'หากอีเมลนี้ตรงกับบัญชี' in html_unknown and len(smtp.messages) == 0, 'อีเมลที่ไม่มีบัญชีได้ข้อความเดียวกันและไม่ส่งอีเมล')
    check(Client().post('forgot.submit', {'email': 'not-an-email'})[0] == 422, 'ตรวจรูปแบบอีเมล')

    # บัญชีจริง: ส่งอีเมล (Host header ปลอมต้องไม่มีผลกับลิงก์)
    member_session = Client()
    member_session.post('login.submit', {'login': fx['member']['username'], 'password': PW})
    check(member_session.req('dashboard')[0] == 200, 'สมาชิกเข้าสู่ระบบไว้ก่อนรีเซ็ต')
    code, html, _ = guest.post('forgot.submit', {'email': member_email.upper()}, headers={'Host': 'evil.example'})
    check(code == 200 and 'หากอีเมลนี้ตรงกับบัญชี' in html and len(smtp.messages) == 1, 'ส่งอีเมลลิงก์ตั้งรหัสผ่านใหม่ (อีเมลไม่สนตัวพิมพ์)')
    check(smtp.auth_ok == [True], 'ยืนยันตัวตน SMTP AUTH LOGIN ด้วยค่าใน config')
    msg, body = parse(smtp.messages[0])
    check(smtp.messages[0]['to'] == [member_email] and msg['Subject'].startswith('ตั้งรหัสผ่านใหม่'), 'ผู้รับถูกต้องและหัวเรื่องภาษาไทยถอดรหัสได้')
    check('ทดสอบ member' in body and fx['member']['username'] in body, 'เนื้อหาอีเมลภาษาไทยถูกต้อง')
    link = re.search(r'(http\S+r=reset&token=([a-f0-9]{64}))', body)
    check(link is not None and link.group(1).startswith('http://localhost/prompt_hub/public/'), 'ลิงก์ใช้ app_url ไม่ใช้ Host header ที่ปลอมมา')
    token = link.group(2)
    stored = scalar('SELECT token_hash FROM password_resets r JOIN users u ON u.id = r.user_id WHERE u.username = ? AND r.used_at IS NULL', fx['member']['username'])
    check(stored == hashlib.sha256(token.encode()).hexdigest() and stored != token, 'ฐานข้อมูลเก็บเฉพาะ SHA-256 ของ token')

    # ขอใหม่แล้วลิงก์เก่าใช้ไม่ได้ (รอบนี้ตั้ง “URL เว็บไซต์” ในหลังบ้าน ลิงก์ต้องใช้ URL นั้นแทน app_url)
    site_admin = Client()
    site_admin.post('login.submit', {'login': fx['admin']['username'], 'password': PW})
    settings = {r['setting_key']: r['setting_value'] for r in json.loads(php('tests/fixtures.php', 'query', stdin=json.dumps({'sql': 'SELECT setting_key, setting_value FROM site_settings', 'params': []})).stdout)}
    site_admin.post('admin.settings.save', dict(settings, site_url='https://hub-' + SUFFIX + '.example.ac.th/app'))
    guest.post('forgot.submit', {'email': member_email})
    site_admin.post('admin.settings.save', settings)
    body2 = parse(smtp.messages[1])[1]
    check(('https://hub-%s.example.ac.th/app/index.php?r=reset&token=' % SUFFIX) in body2, 'ตั้ง URL เว็บไซต์ในหลังบ้านแล้ว ลิงก์ในอีเมลใช้ URL นั้น')
    token2 = re.search(r'token=([a-f0-9]{64})', body2).group(1)
    check(Client().req(None, url=link.group(1))[0] == 410, 'ขอลิงก์ใหม่แล้วลิงก์เก่าใช้ไม่ได้')

    # เปิดลิงก์: token ถูกย้ายออกจาก URL
    browser = Client()
    code, html, final_url = browser.req(None, url=BASE + '?r=reset&token=' + token2)
    check(code == 200 and 'token=' not in final_url and fx['member']['username'] in html, 'เปิดลิงก์แล้วแสดงฟอร์ม และ token ไม่ค้างใน URL')
    check(browser.post('reset.submit', {'password': 'short', 'password_confirm': 'short'})[0] == 422, 'ปฏิเสธรหัสผ่านใหม่ที่สั้นเกินไป')
    check(browser.post('reset.submit', {'password': 'NewPass-12345', 'password_confirm': 'Different-123'})[0] == 422, 'ปฏิเสธเมื่อยืนยันรหัสผ่านไม่ตรงกัน')
    new_pw = 'ใหม่-Reset-' + SUFFIX
    code, html, url = browser.post('reset.submit', {'password': new_pw, 'password_confirm': new_pw})
    check(code == 200 and 'r=login' in url and 'ตั้งรหัสผ่านใหม่เรียบร้อย' in html, 'ตั้งรหัสผ่านใหม่สำเร็จแล้วไปหน้าเข้าสู่ระบบ')
    check(Client().post('login.submit', {'login': fx['member']['username'], 'password': PW})[0] == 422, 'รหัสผ่านเดิมใช้ไม่ได้แล้ว')
    check(Client().post('login.submit', {'login': fx['member']['username'], 'password': new_pw})[0] == 200, 'เข้าสู่ระบบด้วยรหัสผ่านใหม่ (ภาษาไทยในรหัสผ่าน) ได้')
    check(member_session.req('dashboard')[0] == 401, 'session เดิมหมดสิทธิ์หลังรีเซ็ตรหัสผ่าน')
    check(Client().req(None, url=BASE + '?r=reset&token=' + token2)[0] == 410, 'ลิงก์ที่ใช้แล้วใช้ซ้ำไม่ได้')

    # ลิงก์หมดอายุ (ใช้บัญชี other)
    other_email = fx['other']['username'] + '@example.invalid'
    Client().post('forgot.submit', {'email': other_email})
    token3 = re.search(r'token=([a-f0-9]{64})', parse(smtp.messages[-1])[1]).group(1)
    php('tests/fixtures.php', 'expire-resets', fx['other']['username'])
    check(Client().req(None, url=BASE + '?r=reset&token=' + token3)[0] == 410, 'ลิงก์ที่หมดอายุใช้ไม่ได้')

    # บัญชีที่ถูกระงับไม่ได้รับอีเมล
    admin = Client()
    admin.post('login.submit', {'login': fx['admin']['username'], 'password': PW})
    admin.post('admin.user.action', {'id': fx['other']['id'], 'op': 'suspend'})
    before = len(smtp.messages)
    Client().post('forgot.submit', {'email': other_email})
    check(len(smtp.messages) == before, 'บัญชีที่ถูกระงับไม่ได้รับลิงก์')

    # จำกัดความถี่ต่ออีเมล (ครั้งที่ 4 ใน 1 ชั่วโมง)
    codes = [Client().post('forgot.submit', {'email': 'flood_' + SUFFIX + '@example.invalid'})[0] for _ in range(4)]
    check(codes == [200, 200, 200, 429], 'จำกัดคำขอลืมรหัสผ่าน 3 ครั้งต่ออีเมลต่อชั่วโมง')

    # SMTP ล้มเหลว: ผู้ใช้เห็นข้อความเดิม ไม่เหลือ token และ log ไม่มีรหัสผ่าน SMTP
    set_mail_config(original_config, SMTP_PASS + '-wrong')
    code, html, _ = Client().post('forgot.submit', {'email': fx['admin']['username'] + '@example.invalid'})
    pending = scalar('SELECT COUNT(*) FROM password_resets r JOIN users u ON u.id = r.user_id WHERE u.username = ? AND r.used_at IS NULL', fx['admin']['username'])
    logs = ''.join(open(os.path.join(log_dir, f), encoding='utf-8', errors='replace').read() for f in os.listdir(log_dir) if f.endswith('.log'))
    check(code == 200 and 'หากอีเมลนี้ตรงกับบัญชี' in html and pending == 0, 'SMTP ล้มเหลว: ข้อความเดิมและลบ token ทิ้ง')
    check('535 authentication failed' in logs and SMTP_PASS not in logs, 'บันทึกสาเหตุลง log โดยไม่มีรหัสผ่าน SMTP')

    print('\nTOTAL', len(passed), 'passed')
finally:
    with open(CONFIG, 'w', encoding='utf-8', newline='\n') as f:
        f.write(original_config)
    smtp.stop()
    print(php('tests/fixtures.php', 'cleanup', SUFFIX).stdout)
