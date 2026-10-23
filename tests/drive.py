"""ทดสอบการเก็บภาพตัวอย่างใน Google Drive กับเซิร์ฟเวอร์ Google/Drive จำลอง (ไม่ติดต่อ Google จริง)

    python tests/drive.py --allow-test-data

ระหว่างทดสอบจะแก้ส่วน google/drive ใน config/config.php ชั่วคราว และสำรอง storage/google-drive.json (ถ้ามี) แล้วคืนค่าเดิมเมื่อจบ
"""
import http.cookiejar
import json
import os
import re
import shlex
import struct
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
import zlib

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fake_google import FakeGoogle  # noqa: E402

if '--allow-test-data' not in sys.argv:
    raise SystemExit('ใช้ --allow-test-data เพื่อยืนยันว่ารันกับฐานข้อมูลที่สร้างข้อมูลทดสอบได้')

BASE = os.environ.get('PROMPT_HUB_BASE', 'http://localhost/prompt_hub/public/index.php')
PHP = shlex.split(os.environ.get('PROMPT_HUB_PHP', 'C:/xampp/php/php.exe'))
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CONFIG = os.path.join(ROOT, 'config', 'config.php')
TOKEN = os.path.join(ROOT, 'storage', 'google-drive.json')
UPLOADS = os.path.join(ROOT, 'storage', 'uploads', 'prompts')
PORT = 2527
FAKE = 'http://127.0.0.1:%d' % PORT
CLIENT_ID, CLIENT_SECRET = 'drive-test.apps.example', 'drive-secret-' + os.urandom(4).hex()
FOLDER = 'folderPromptHub' + os.urandom(4).hex()
LEGACY = 'Prompt Hub - ภาพตัวอย่างผลลัพธ์'  # โฟลเดอร์ภาพรุ่นเก่า (ย้ายเข้า storage/uploads/prompts)


def child(parent, name):
    """รหัสโฟลเดอร์ชื่อนี้ในโฟลเดอร์แม่บน Drive จำลอง (ไม่มี = None)"""
    ids = [k for k, v in google.drive_files.items() if v['name'] == name and parent in v['parents'] and not v['trashed']
           and v['mimeType'] == 'application/vnd.google-apps.folder']
    assert len(ids) <= 1, ('duplicate folder', name, ids)
    return ids[0] if ids else None


def path_id(*names):
    cur = FOLDER
    for n in names:
        cur = child(cur, n)
        if cur is None:
            return None
    return cur
passed = []


def check(ok, name):
    if not ok:
        raise AssertionError('FAIL: ' + name)
    passed.append(name)
    print('PASS', name)


def php(*args, stdin=None):
    return subprocess.run(PHP + list(args), input=stdin, capture_output=True, text=True, encoding='utf-8', cwd=ROOT)


def sql(q, *p):
    return json.loads(php('tests/fixtures.php', 'query', stdin=json.dumps({'sql': q, 'params': list(p)})).stdout)


def scalar(q, *p):
    rows = sql(q, *p)
    return list(rows[0].values())[0] if rows else None


def png(color):
    w, h = 40, 30
    raw = b''.join(b'\x00' + bytes(color) * w for _ in range(h))
    chunk = lambda t, d: struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b'')


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def open(self, req):
        try:
            r = self.op.open(req)
            return r.status, r.read().decode('utf-8', 'replace'), r.url, dict(r.headers)
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace'), e.url, dict(e.headers)

    def get(self, route, **q):
        return self.open(BASE + '?' + urllib.parse.urlencode(dict(r=route, **q)))

    def token(self):
        return re.search(r'name="_csrf" value="([a-f0-9]{64})"', self.get('login')[1]).group(1)

    def post(self, route, data=None):
        data = dict(data or {}, _csrf=self.token())
        return self.open(urllib.request.Request(BASE + '?r=' + route, data=urllib.parse.urlencode(data).encode()))

    def multipart(self, route, fields, files):
        b = uuid.uuid4().hex
        parts = [('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (b, k, v)).encode() for k, v in list(fields) + [('_csrf', self.token())]]
        parts += [('--%s\r\nContent-Disposition: form-data; name="%s"; filename="%s"\r\nContent-Type: %s\r\n\r\n' % (b, k, n, t)).encode() + c + b'\r\n' for k, n, c, t in files]
        return self.open(urllib.request.Request(BASE + '?r=' + route, data=b''.join(parts) + ('--%s--\r\n' % b).encode(),
                                                headers={'Content-Type': 'multipart/form-data; boundary=' + b}))


def set_config(text):
    # ส่วน google เป็น Client ของการเข้าสู่ระบบ (คนละตัว) ส่วน drive มี Client แยก — เซิร์ฟเวอร์จำลองรับเฉพาะ Client ของ Drive
    # ผ่านทุกข้อ = การเชื่อมต่อ Drive ใช้ client_id/secret ของส่วน drive จริง
    block = ("    'google' => [\n        'enabled' => false,\n        'client_id' => 'login-only.apps.example',\n        'client_secret' => 'login-only-secret',\n"
             "        'auth_url' => '%s/auth',\n        'token_url' => '%s/token',\n        'userinfo_url' => '%s/userinfo',\n"
             "        'revoke_url' => '%s/revoke',\n    ],\n"
             "    'drive' => [\n        'client_id' => '%s',\n        'client_secret' => '%s',\n"
             "        'api_base' => '%s/drive/v3',\n        'upload_base' => '%s/upload/drive/v3',\n        'image_base' => '%s/d',\n    ],\n"
             ) % (FAKE, FAKE, FAKE, FAKE, CLIENT_ID, CLIENT_SECRET, FAKE, FAKE, FAKE)
    new, n = re.subn(r"    'google' => \[.*?\n    \],\n", lambda m: block, text, flags=re.S)
    assert n == 1
    open(CONFIG, 'w', encoding='utf-8', newline='\n').write(new)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


def token_state():
    return json.load(open(TOKEN, encoding='utf-8')) if os.path.exists(TOKEN) else None


original_config = open(CONFIG, encoding='utf-8').read()
original_token = open(TOKEN, 'rb').read() if os.path.exists(TOKEN) else None
if original_token is not None:
    os.remove(TOKEN)
google = FakeGoogle(PORT, CLIENT_ID, CLIENT_SECRET).start()
google.add_folder(FOLDER, 'Prompt Hub')
# ผู้ดูแลสร้างโฟลเดอร์ storage ไว้เองแล้ว (เหมือนบน Drive จริง) → ระบบต้องใช้ของเดิม ไม่สร้างซ้ำ
PRE_STORAGE = 'preStorage' + os.urandom(4).hex()
google.add_folder(PRE_STORAGE, 'storage', parent=FOLDER)
fx = json.loads(php('tests/fixtures.php', 'create').stdout)
SUFFIX, PW = fx['suffix'], fx['password']
original_folder = scalar("SELECT setting_value FROM site_settings WHERE setting_key = 'drive_folder_id'")
# สำรองข้อมูล: จำค่าเดิมและไฟล์สำรองที่มีอยู่ เพื่อคืนค่าและลบไฟล์ที่ชุดทดสอบสร้างเมื่อจบ
BACKUPS = os.path.join(ROOT, 'storage', 'backups')
original_backup = {r['setting_key']: r['setting_value'] for r in sql("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('drive_backup_folder_id', 'drive_backup_last')")}
existing_backups = set(os.listdir(BACKUPS)) if os.path.isdir(BACKUPS) else set()
BACKUP_FOLDER = 'backupFolder' + os.urandom(4).hex()
# ภาพจริงที่มีอยู่ก่อนทดสอบ: ปุ่ม “อัปโหลดภาพที่ค้าง” จะส่งขึ้นเซิร์ฟเวอร์จำลองด้วย จึงต้องล้างรหัสจำลองคืนเมื่อจบ
existing_images = [r['id'] for r in sql('SELECT id FROM prompt_images WHERE drive_file_id IS NULL')]
IMG_CAT = scalar('SELECT id FROM categories WHERE allow_images = 1 AND is_active = 1 ORDER BY id LIMIT 1')
admin, member, guest = Client(), Client(), Client()

try:
    set_config(original_config)
    admin.post('login.submit', {'login': fx['admin']['username'], 'password': PW})
    member.post('login.submit', {'login': fx['member']['username'], 'password': PW})
    google.profile = {'sub': 'g' + SUFFIX, 'email': 'owner_' + SUFFIX + '@example.invalid', 'email_verified': True, 'name': 'เจ้าของโฟลเดอร์'}

    def save_prompt(title, files, **extra):
        fields = [('title', title), ('description', 'ทดสอบเก็บภาพใน Google Drive'), ('content', 'ภาพ [ฉาก] โทน [สี]'), ('category_id', str(IMG_CAT)),
                  ('visibility', extra.pop('visibility', 'private')), ('intent', extra.pop('intent', 'draft')), ('tags', '')] + list(extra.items())
        code, html, url, _ = member.multipart('my.prompt.save', fields, files)
        assert code == 200, (code, re.findall(r'invalid-feedback[^>]*>([^<]+)', html))
        return int(re.search(r'[?&]id=(\d+)', url).group(1))

    def images(pid):
        return sql('SELECT id, file_name, drive_file_id FROM prompt_images WHERE prompt_id = ? ORDER BY sort_order, id', pid)

    # ---------- สิทธิ์ ----------
    check(guest.get('admin.drive')[0] == 401 and member.get('admin.drive')[0] == 403, 'หน้า Google Drive เปิดได้เฉพาะผู้ดูแล')
    check(guest.post('admin.drive.connect')[0] == 401 and member.post('admin.drive.sync')[0] == 403, 'คำสั่ง Drive ใช้ได้เฉพาะผู้ดูแล')
    page = admin.get('admin.drive')[1]
    callback = BASE.replace('/index.php', '/google_callback.php')  # Drive ใช้ redirect URI เดียวกับการเข้าสู่ระบบ
    check('ยังไม่ได้เชื่อมต่อ' in page and callback.replace('&', '&amp;') in page, 'ยังไม่เชื่อมต่อ: แสดงสถานะและ redirect URI ที่ต้องลงทะเบียน')

    # ---------- ตั้งโฟลเดอร์ ----------
    admin.post('admin.drive.save', {'folder': 'not a folder link'})
    check(scalar("SELECT setting_value FROM site_settings WHERE setting_key = 'drive_folder_id'") == original_folder, 'ปฏิเสธลิงก์โฟลเดอร์ที่ไม่ถูกต้อง')
    admin.post('admin.drive.save', {'folder': 'https://drive.google.com/drive/folders/%s?usp=sharing' % FOLDER})
    check(scalar("SELECT setting_value FROM site_settings WHERE setting_key = 'drive_folder_id'") == FOLDER, 'บันทึกโฟลเดอร์จากลิงก์แชร์ (ดึงรหัสโฟลเดอร์)')

    # ---------- เชื่อมต่อ ----------
    code, html, url, _ = admin.post('admin.drive.connect')
    q = google.auth_requests[-1]
    check('https://www.googleapis.com/auth/drive' in q['scope'] and q['access_type'] == 'offline' and q['prompt'] == 'consent'
          and q['code_challenge_method'] == 'S256' and q['redirect_uri'] == callback and q['client_id'] == CLIENT_ID,
          'ขอสิทธิ์ Drive แบบ offline พร้อม PKCE, redirect URI และ Client ID ของส่วน drive (แยกจาก Client ของการเข้าสู่ระบบ)')
    st = token_state()
    check(code == 200 and 'r=admin.drive' in url and 'เชื่อมต่อแล้ว' in html and google.profile['email'] in html, 'เชื่อมต่อ Google Drive สำเร็จ แสดงบัญชีที่เชื่อม')
    check(st and st['refresh_token'].startswith('refresh-') and path_id('storage') == PRE_STORAGE and st['folder_id'] == path_id('storage', 'uploads', 'prompts')
          and st.get('layout') == 'mirror',
          'เก็บ refresh token และจัดโฟลเดอร์บน Drive ตรงกับเว็บ: โฟลเดอร์หลัก/storage/uploads/prompts (ใช้โฟลเดอร์ storage ที่มีอยู่แล้ว)')
    check(Client().open(BASE.replace('/public/index.php', '/storage/google-drive.json'))[0] == 403, 'ไฟล์ token เปิดผ่านเว็บไม่ได้ (403)')
    admin.post('admin.drive.connect')
    check(len([1 for v in google.drive_files.values() if v['name'] == 'prompts']) == 1 and len([1 for v in google.drive_files.values() if v['name'] == 'uploads']) == 1,
          'เชื่อมต่อซ้ำใช้โฟลเดอร์เดิม ไม่สร้างซ้ำ')

    # ---------- อัปโหลดภาพ ----------
    pid = save_prompt('Drive ' + SUFFIX, [('images[]', 'a.png', png((200, 30, 90)), 'image/png'), ('images[]', 'b.png', png((20, 160, 90)), 'image/png')])
    rows = images(pid)
    files = [google.drive_files.get(r['drive_file_id']) for r in rows]
    check(len(rows) == 2 and all(files) and all(f['parents'] == [st['folder_id']] for f in files), 'บันทึก Prompt แล้วภาพถูกอัปโหลดขึ้น storage/uploads/prompts บน Drive')
    check(all({'type': 'anyone', 'role': 'reader'} in f['permissions'] for f in files), 'ตั้งสิทธิ์ไฟล์บน Drive เป็นทุกคนที่มีลิงก์ดูได้')
    local = open(os.path.join(UPLOADS, rows[0]['file_name']), 'rb').read()
    check(files[0]['data'] == local and files[0]['name'] == rows[0]['file_name'], 'ไฟล์บน Drive ตรงกับสำเนาในเครื่อง ชื่อเดียวกับบนเซิร์ฟเวอร์')
    code, html, _, headers = member.get('prompt', id=pid)
    src = '%s/d/%s=w1600' % (FAKE, rows[0]['drive_file_id'])
    check(('src="%s"' % src) in html and ('data-fallback="/prompt_hub/public/index.php?r=media&amp;id=%d"' % rows[0]['id']) in html,
          'หน้ารายละเอียดลิงก์ภาพตรงไปที่ Drive และมีสำเนาในเครื่องเป็นทางสำรอง')
    check(FAKE in headers.get('Content-Security-Policy', ''), 'Content-Security-Policy อนุญาตภาพจากโดเมนของ Drive')
    check(re.search(r"form-action 'self' " + re.escape(FAKE) + r";", headers.get('Content-Security-Policy', '')) is not None,
          'CSP form-action อนุญาต redirect ไปหน้าเลือกบัญชี Google (ปุ่มเชื่อมต่อไม่ค้างในเบราว์เซอร์)')
    check(urllib.request.urlopen(src).read() == local, 'ลิงก์ภาพบน Drive เปิดได้โดยไม่ต้องเข้าสู่ระบบ')
    check(os.path.exists(os.path.join(UPLOADS, rows[0]['file_name'])), 'เก็บสำเนาภาพในเครื่องไว้เป็นข้อมูลสำรอง')
    member.multipart('my.prompt.save', [('id', str(pid)), ('title', 'Drive ' + SUFFIX), ('description', 'ทดสอบเก็บภาพใน Google Drive'),
                                        ('content', 'ภาพ [ฉาก] โทน [สี]'), ('category_id', str(IMG_CAT)), ('visibility', 'public'), ('intent', 'submit'), ('tags', '')], [])
    admin.post('admin.prompt.moderate', {'id': pid, 'decision': 'approve'})
    check(('%s/d/%s=w800' % (FAKE, rows[0]['drive_file_id'])) in guest.get('prompts', q=SUFFIX)[1], 'การ์ด Prompt ใช้ภาพหน้าปกจาก Drive')
    # ภาพบนเว็บยึดตาม Google Drive: ลิงก์ดูขนาดเต็ม / og:image / ภาพในฟอร์มแก้ไข / ?r=media เมื่อไม่มีสำเนาในเครื่อง
    html = guest.get('prompt', id=pid)[1]
    check(('href="%s"' % src) in html, 'ลิงก์ “ดูขนาดเต็ม” เปิดภาพบน Drive')
    check(('<meta property="og:image" content="%s">' % src) in html, 'ภาพตัวอย่างเวลาแชร์ (og:image) ใช้ภาพบน Drive')
    check(('src="%s/d/%s=w400"' % (FAKE, rows[0]['drive_file_id'])) in member.get('my.prompt.form', id=pid)[1], 'ภาพในฟอร์มแก้ไข Prompt แสดงจาก Drive')
    kept = os.path.join(UPLOADS, rows[0]['file_name'])
    os.rename(kept, kept + '.moved')
    try:
        raw_op = urllib.request.build_opener(NoRedirect)
        try:
            raw_op.open(BASE + '?r=media&id=%d' % rows[0]['id'])
            code, loc = 200, ''
        except urllib.error.HTTPError as e:
            code, loc = e.code, e.headers.get('Location', '')
        check(code == 302 and loc == src, 'ไม่มีสำเนาบนเซิร์ฟเวอร์: ?r=media ส่งต่อไปภาพบน Drive แทน 404')
    finally:
        os.rename(kept + '.moved', kept)

    # ---------- Drive ล้มเหลว → เก็บในเครื่องก่อน แล้วอัปโหลดภายหลัง ----------
    google.fail_uploads = True
    pid2 = save_prompt('Drive ล่ม ' + SUFFIX, [('images[]', 'c.png', png((10, 10, 220)), 'image/png')])
    r2 = images(pid2)[0]
    html2 = member.get('prompt', id=pid2)[1]
    check(r2['drive_file_id'] is None and ('src="/prompt_hub/public/index.php?r=media&amp;id=%d"' % r2['id']) in html2,
          'Drive อัปโหลดไม่ได้: บันทึกสำเร็จและแสดงภาพจากเครื่อง')
    check('id="drivePending">1<' in admin.get('admin.drive')[1] or int(re.search(r'id="drivePending">(\d+)<', admin.get('admin.drive')[1]).group(1)) >= 1,
          'หน้า Google Drive แสดงจำนวนภาพที่รออัปโหลด')
    google.fail_uploads = False
    code, html, _, _ = admin.post('admin.drive.sync')
    check(images(pid2)[0]['drive_file_id'] is not None and 'อัปโหลดขึ้น Drive' in html, 'ปุ่ม “อัปโหลดภาพที่ยังไม่อยู่บน Drive” อัปโหลดภาพที่ค้าง')

    # ---------- ลบภาพ / ลบ Prompt → ย้ายไฟล์บน Drive ไปถังขยะ ----------
    member.multipart('my.prompt.save', [('id', str(pid)), ('title', 'Drive ' + SUFFIX), ('description', 'ทดสอบเก็บภาพใน Google Drive'), ('content', 'ภาพ [ฉาก] โทน [สี]'),
                                        ('category_id', str(IMG_CAT)), ('visibility', 'public'), ('intent', 'submit'), ('tags', ''), ('remove_images[]', str(rows[0]['id']))], [])
    check(google.drive_files[rows[0]['drive_file_id']]['trashed'] and not google.drive_files[rows[1]['drive_file_id']]['trashed'],
          'ลบภาพที่เลือก → ไฟล์นั้นบน Drive ถูกย้ายไปถังขยะ (ภาพอื่นไม่โดน)')
    member.post('my.prompt.delete', {'id': pid})
    check(google.drive_files[rows[1]['drive_file_id']]['trashed'], 'ลบ Prompt → ไฟล์ภาพบน Drive ถูกย้ายไปถังขยะ')

    # ---------- ต่ออายุ access token ----------
    st = token_state()
    st['expires_at'] = 0
    json.dump(st, open(TOKEN, 'w', encoding='utf-8'))
    before = len([t for t in google.token_requests if t.get('grant_type') == 'refresh_token'])
    pid3 = save_prompt('Drive refresh ' + SUFFIX, [('images[]', 'd.png', png((90, 90, 90)), 'image/png')])
    check(images(pid3)[0]['drive_file_id'] is not None and len([t for t in google.token_requests if t.get('grant_type') == 'refresh_token']) == before + 1,
          'access token หมดอายุ → ใช้ refresh token ขอใหม่อัตโนมัติ')

    # ---------- สิทธิ์ถูกยกเลิก (invalid_grant) ----------
    google.refresh_tokens.clear()
    st = token_state()
    st['expires_at'] = 0
    json.dump(st, open(TOKEN, 'w', encoding='utf-8'))
    pid4 = save_prompt('Drive revoked ' + SUFFIX, [('images[]', 'e.png', png((240, 200, 10)), 'image/png')])
    r4 = images(pid4)[0]
    page = admin.get('admin.drive')[1]
    check(r4['drive_file_id'] is None and token_state().get('needs_reconnect') and 'id="driveReconnect"' in page,
          'Google ยกเลิกสิทธิ์: บันทึกภาพในเครื่องต่อได้ และหลังบ้านแจ้งให้เชื่อมต่อใหม่')
    check(('src="/prompt_hub/public/index.php?r=media&amp;id=%d"' % images(pid3)[0]['id']) in member.get('prompt', id=pid3)[1],
          'ระหว่างรอเชื่อมต่อใหม่ หน้าเว็บแสดงภาพจากเครื่อง')
    admin.post('admin.drive.connect')
    admin.post('admin.drive.sync')
    check(images(pid4)[0]['drive_file_id'] is not None and not token_state().get('needs_reconnect'), 'เชื่อมต่อใหม่แล้วอัปโหลดภาพที่ค้างได้')

    # ---------- กรณีผิดพลาด ----------
    t = Client()
    t.post('login.submit', {'login': fx['admin']['username'], 'password': PW})
    code, html, url, _ = t.get('admin.drive.callback', code='x', state='forged')
    check('r=admin.drive' in url and 'หมดอายุหรือไม่ถูกต้อง' in html, 'callback ที่ state ไม่ตรงถูกปฏิเสธ')
    saved_state = token_state()
    admin.post('admin.drive.save', {'folder': 'missingFolder' + SUFFIX + 'xyz'})
    code, html, _, _ = admin.post('admin.drive.connect')
    check('เข้าถึงโฟลเดอร์ที่ตั้งไว้ไม่ได้' in html and token_state()['folder_id'] == saved_state['folder_id'], 'โฟลเดอร์ที่บัญชีเข้าถึงไม่ได้ → แจ้งข้อผิดพลาด การเชื่อมต่อเดิมไม่เปลี่ยน')
    google.add_folder('readonlyFolder' + SUFFIX, 'อ่านอย่างเดียว', can_add=False)
    admin.post('admin.drive.save', {'folder': 'readonlyFolder' + SUFFIX})
    check('ไม่มีสิทธิ์เพิ่มไฟล์' in admin.post('admin.drive.connect')[1], 'โฟลเดอร์ที่ไม่มีสิทธิ์แก้ไข → แจ้งข้อผิดพลาด')
    admin.post('admin.drive.save', {'folder': FOLDER})

    # ---------- สำรองข้อมูลไปยัง Google Drive ----------
    google.add_folder(BACKUP_FOLDER, 'สำรองข้อมูล Prompt Hub')
    admin.post('admin.backup.folder', {'backup_folder': 'not a folder'})
    check(scalar("SELECT setting_value FROM site_settings WHERE setting_key = 'drive_backup_folder_id'") == original_backup.get('drive_backup_folder_id'),
          'สำรองข้อมูล: ปฏิเสธลิงก์โฟลเดอร์ที่ไม่ถูกต้อง')
    admin.post('admin.backup.folder', {'backup_folder': 'https://drive.google.com/drive/folders/%s?usp=sharing' % BACKUP_FOLDER})
    check(scalar("SELECT setting_value FROM site_settings WHERE setting_key = 'drive_backup_folder_id'") == BACKUP_FOLDER, 'สำรองข้อมูล: บันทึกโฟลเดอร์จากลิงก์แชร์')
    check(member.post('admin.backup.drive')[0] == 403 and member.get('admin.backup.download', name='x')[0] == 403, 'สำรองข้อมูล/ดาวน์โหลดไฟล์สำรอง ใช้ได้เฉพาะผู้ดูแล')
    google.chunk_accept = 300000  # รับไม่ครบทุกครั้ง → ระบบต้องส่งต่อจากตำแหน่งที่ Drive แจ้ง (308 + Range)
    code, html, _, _ = admin.post('admin.backup.drive')
    google.chunk_accept = None
    made = sorted(set(os.listdir(BACKUPS)) - existing_backups)
    zips = [n for n in made if n.endswith('.zip')]
    up = [v for v in google.drive_files.values() if BACKUP_FOLDER in v['parents']]
    local = open(os.path.join(BACKUPS, zips[-1]), 'rb').read() if zips else b''
    check(code == 200 and 'สำรองข้อมูลขึ้น Google Drive แล้ว' in html and len(up) == 1 and up[0]['name'] == zips[-1],
          'กดสำรองข้อมูล: สร้าง zip และอัปโหลดขึ้นโฟลเดอร์สำรองบน Drive')
    check(len(local) > 300000 and up[0]['data'] == local, 'อัปโหลดแบบ resumable ส่งต่อจากตำแหน่งที่ Drive แจ้งจนไฟล์บน Drive ตรงกับไฟล์ในเครื่องทุกไบต์')
    check(up[0]['permissions'] == [] and up[0]['mime'] == 'application/zip', 'ไฟล์สำรองบน Drive ไม่ตั้งสิทธิ์แชร์ (มีข้อมูลสมาชิก)')
    import zipfile, io
    zf = zipfile.ZipFile(io.BytesIO(local))
    names = zf.namelist()
    dump = zf.read('database.sql').decode('utf-8')
    check('database.sql' in names and 'CREATE TABLE `users`' in dump and 'INSERT INTO `prompts`' in dump
          and any(n.startswith('uploads/prompts/') for n in names), 'ไฟล์สำรองมี database.sql (โครงสร้าง+ข้อมูล) และไฟล์อัปโหลด')
    check(not any(re.search(r'config\.php|google-drive\.json|initial-accounts|\.log$', n) for n in names), 'ไฟล์สำรองไม่มี config/token/log')
    check(zips[-1] in html and 'id="backupLast"' in html, 'หน้า Google Drive แสดงการสำรองล่าสุดและไฟล์ในเครื่อง')
    code, body, _, headers = admin.get('admin.backup.download', name=zips[-1])
    check(code == 200 and headers.get('Content-Type', '').startswith('application/zip'), 'ผู้ดูแลดาวน์โหลดไฟล์สำรองในเครื่องได้')
    check(admin.get('admin.backup.download', name='../config/config.php')[0] == 404, 'ดาวน์โหลดไฟล์สำรอง: ปฏิเสธชื่อไฟล์นอกโฟลเดอร์สำรอง')
    check(Client().open(BASE.replace('/public/index.php', '/storage/backups/' + zips[-1]))[0] == 403, 'ไฟล์สำรองเปิดผ่านเว็บโดยตรงไม่ได้ (403)')
    google.add_folder('roBackup' + SUFFIX, 'อ่านอย่างเดียว', can_add=False)
    admin.post('admin.backup.folder', {'backup_folder': 'roBackup' + SUFFIX})
    code, html, _, _ = admin.post('admin.backup.drive')
    check('ไม่มีสิทธิ์เพิ่มไฟล์' in html and 'ไฟล์สำรองยังอยู่ในเครื่อง' in html, 'โฟลเดอร์สำรองที่ไม่มีสิทธิ์เพิ่มไฟล์ → แจ้งข้อผิดพลาด เก็บไฟล์ในเครื่องไว้')
    actions = {r['action'] for r in sql('SELECT DISTINCT action FROM admin_activity_logs WHERE admin_id = ?', fx['admin']['id'])}
    check({'backup.folder', 'backup.drive'} <= actions, 'บันทึกกิจกรรมการสำรองข้อมูล')
    # ---------- ย้ายภาพจากโฟลเดอร์รุ่นเก่า (การเชื่อมต่อก่อนจัดโฟลเดอร์ตรงกับเว็บ) ----------
    st = token_state()
    google.add_folder('legacy' + SUFFIX, LEGACY, parent=FOLDER)
    old_rows = [r for r in sql('SELECT id, file_name, drive_file_id FROM prompt_images WHERE drive_file_id LIKE ?', 'drivefile%')]
    for r in old_rows:  # จำลองภาพที่อัปโหลดด้วยรุ่นเก่า: อยู่ในโฟลเดอร์เก่า ชื่อแบบเก่า
        google.drive_files[r['drive_file_id']]['parents'] = ['legacy' + SUFFIX]
        google.drive_files[r['drive_file_id']]['name'] = 'prompt-old-%d.png' % r['id']
    old = dict(st, folder_id='legacy' + SUFFIX)
    old.pop('layout', None)
    open(TOKEN, 'w', encoding='utf-8').write(json.dumps(old))
    check('id="driveLegacy"' in admin.get('admin.drive')[1], 'การเชื่อมต่อรุ่นเก่า: หน้า Google Drive แจ้งว่ามีภาพเดิมรอย้าย')
    for _ in range(10):  # ย้ายทีละ 50 ภาพต่อการกด — กดซ้ำจนครบ
        code, html, _, _ = admin.post('admin.drive.sync')
        if 'เหลืออีก' not in html:
            break
    st2 = token_state()
    moved = [google.drive_files[r['drive_file_id']] for r in old_rows]
    check(len(old_rows) >= 1 and all(f['parents'] == [st2['folder_id']] and f['name'] == r['file_name'] for f, r in zip(moved, old_rows)),
          'ปุ่มอัปโหลด: ย้ายภาพเดิมเข้า storage/uploads/prompts และเปลี่ยนชื่อตรงกับเซิร์ฟเวอร์ (ไม่อัปโหลดซ้ำ)')
    check(st2.get('layout') == 'mirror' and 'legacy_folder_id' not in st2 and st2['folder_id'] == path_id('storage', 'uploads', 'prompts'),
          'ย้ายครบแล้ว: ล้างสถานะโฟลเดอร์เดิม ใช้ storage/uploads/prompts ต่อ')
    check(all(sql('SELECT drive_file_id FROM prompt_images WHERE id = ?', r['id'])[0]['drive_file_id'] == r['drive_file_id'] for r in old_rows),
          'ย้ายแล้วรหัสไฟล์บน Drive ไม่เปลี่ยน (ลิงก์ภาพบนเว็บใช้ได้เหมือนเดิม)')
    check('id="driveLegacy"' not in admin.get('admin.drive')[1], 'ย้ายครบแล้วไม่แจ้งเตือนภาพเดิม')

    # ---------- สำรองข้อมูล: ไม่ตั้งโฟลเดอร์แยก → โฟลเดอร์หลัก/storage/backups ----------
    admin.post('admin.backup.folder', {'backup_folder': ''})
    check(scalar("SELECT setting_value FROM site_settings WHERE setting_key = 'drive_backup_folder_id'") == '', 'สำรองข้อมูล: เว้นว่างโฟลเดอร์ = ใช้ storage/backups')
    code, html, _, _ = admin.post('admin.backup.drive')
    bk = path_id('storage', 'backups')
    check(bk is not None and child(FOLDER, 'storage') == PRE_STORAGE and any(bk in v['parents'] and v['name'].endswith('.zip') for v in google.drive_files.values()),
          'สำรองข้อมูลลงโฟลเดอร์หลัก/storage/backups (ตรงกับเว็บ)')
    # ---------- ยกเลิกการเชื่อมต่อ ----------
    refresh = token_state()['refresh_token']
    code, html, _, _ = admin.post('admin.drive.disconnect')
    check(not os.path.exists(TOKEN) and refresh in google.revoked and 'ยังไม่ได้เชื่อมต่อ' in html, 'ยกเลิกการเชื่อมต่อ: ลบ token และยกเลิกสิทธิ์ที่ Google')
    html3 = member.get('prompt', id=pid3)[1]
    check(('src="/prompt_hub/public/index.php?r=media&amp;id=%d"' % images(pid3)[0]['id']) in html3 and FAKE + '/d/' not in html3,
          'หลังยกเลิก หน้าเว็บกลับไปแสดงภาพจากสำเนาในเครื่อง')
    actions = {r['action'] for r in sql('SELECT DISTINCT action FROM admin_activity_logs WHERE admin_id = ?', fx['admin']['id'])}
    check({'drive.folder', 'drive.connect', 'drive.sync', 'drive.disconnect'} <= actions, 'บันทึกกิจกรรมการตั้งค่า Drive ครบ')
    for p in (pid2, pid3, pid4):
        member.post('my.prompt.delete', {'id': p})

    print('\nTOTAL', len(passed), 'passed')
finally:
    open(CONFIG, 'w', encoding='utf-8', newline='\n').write(original_config)
    if os.path.exists(TOKEN):
        os.remove(TOKEN)
    if original_token is not None:
        open(TOKEN, 'wb').write(original_token)
    google.stop()
    try:
        a = Client()
        a.post('login.submit', {'login': fx['admin']['username'], 'password': PW})
        a.post('admin.drive.save', {'folder': original_folder or 'resetPlaceholder000'})
    except Exception as e:
        print('restore folder failed', e)
    # สำรองข้อมูล: ลบไฟล์ที่ชุดทดสอบสร้าง และคืนค่าตั้งค่าเดิม
    for n in set(os.listdir(BACKUPS)) - existing_backups if os.path.isdir(BACKUPS) else []:
        if re.fullmatch(r'prompthub-backup-\d{8}-\d{6}\.zip', n):
            os.remove(os.path.join(BACKUPS, n))
    php('tests/fixtures.php', 'backup-settings', stdin=json.dumps(original_backup))
    if existing_images:
        php('tests/fixtures.php', 'drive-reset', ','.join(map(str, existing_images)))
    leftover = scalar("SELECT COUNT(*) FROM prompt_images WHERE drive_file_id REGEXP '^drivefile[0-9]{6}$'")
    print('fake drive ids left on real images:', leftover)
    print(php('tests/fixtures.php', 'cleanup', SUFFIX).stdout)
