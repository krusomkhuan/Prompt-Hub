"""ทดสอบฟีเจอร์ภาพตัวอย่าง การคัดลอกเฉพาะสมาชิก และสมัคร/เข้าสู่ระบบด้วย Google (เซิร์ฟเวอร์ Google จำลอง)

    python tests/features.py --allow-test-data

ระหว่างทดสอบจะแก้ส่วน 'google' ใน config/config.php ให้ชี้ไปที่ 127.0.0.1:2526 ชั่วคราว และคืนไฟล์เดิมเมื่อจบ
"""
import http.cookiejar
import json
import os
import re
import shlex
import struct
import subprocess
import sys
import tempfile
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
UPLOADS = os.path.join(ROOT, 'storage', 'uploads', 'prompts')
PORT = 2526
CLIENT_ID, CLIENT_SECRET = 'test-client.apps.example', 'test-secret-' + os.urandom(4).hex()
passed = []


def check(ok, name):
    if not ok:
        raise AssertionError('FAIL: ' + name)
    passed.append(name)
    print('PASS', name)


def php(*args, stdin=None):
    return subprocess.run(PHP + list(args), input=stdin, capture_output=True, text=True, encoding='utf-8', cwd=ROOT)


def sql(query, *params):
    r = php('tests/fixtures.php', 'query', stdin=json.dumps({'sql': query, 'params': list(params)}))
    if r.returncode:
        raise RuntimeError(r.stderr)
    return json.loads(r.stdout)


def scalar(query, *params):
    rows = sql(query, *params)
    return list(rows[0].values())[0] if rows else None


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def open(self, req):
        try:
            res = self.opener.open(req)
            return res.status, res.read(), res.url, dict(res.headers)
        except urllib.error.HTTPError as e:
            return e.code, e.read(), e.url, dict(e.headers)

    def get(self, route, **q):
        code, body, url, h = self.open(BASE + '?' + urllib.parse.urlencode(dict(r=route, **q)))
        return code, body.decode('utf-8', 'replace'), url, h

    def raw(self, route, **q):
        return self.open(BASE + '?' + urllib.parse.urlencode(dict(r=route, **q)))

    def token(self):
        html = self.get('login')[1]
        return re.search(r'name="_csrf" value="([a-f0-9]{64})"', html).group(1)

    def post(self, route, data, csrf=True):
        data = list(data.items()) if isinstance(data, dict) else list(data)
        if csrf:
            data.append(('_csrf', self.token()))
        req = urllib.request.Request(BASE + '?r=' + route, data=urllib.parse.urlencode(data).encode())
        code, body, url, h = self.open(req)
        return code, body.decode('utf-8', 'replace'), url, h

    def multipart(self, route, fields, files):
        boundary = uuid.uuid4().hex
        fields = list(fields) + [('_csrf', self.token())]
        parts = []
        for k, v in fields:
            parts.append(('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (boundary, k, v)).encode())
        for k, name, content, ctype in files:
            parts.append(('--%s\r\nContent-Disposition: form-data; name="%s"; filename="%s"\r\nContent-Type: %s\r\n\r\n'
                          % (boundary, k, name, ctype)).encode() + content + b'\r\n')
        body = b''.join(parts) + ('--%s--\r\n' % boundary).encode()
        req = urllib.request.Request(BASE + '?r=' + route, data=body,
                                     headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
        code, body, url, h = self.open(req)
        return code, body.decode('utf-8', 'replace'), url, h

    def login(self, username, password):
        return self.post('login.submit', {'login': username, 'password': password})


def png(color=(120, 60, 220), text=b''):
    w, h = 8, 6
    raw = b''.join(b'\x00' + bytes(color) * w for _ in range(h))
    def chunk(t, d):
        return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    out = b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0))
    if text:
        out += chunk(b'tEXt', b'Comment\x00' + text)
    return out + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b'')


def jpeg_with_exif(secret):
    path = os.path.join(tempfile.mkdtemp(), 't.jpg')
    ps = ("Add-Type -AssemblyName System.Drawing; $b = New-Object Drawing.Bitmap 64,48; "
          "$g = [Drawing.Graphics]::FromImage($b); $g.Clear([Drawing.Color]::MediumPurple); "
          "$b.Save('%s', [Drawing.Imaging.ImageFormat]::Jpeg)" % path)
    subprocess.run(['powershell', '-NoProfile', '-Command', ps], check=True, capture_output=True)
    data = open(path, 'rb').read()
    payload = b'Exif\x00\x00' + secret
    app1 = b'\xff\xe1' + struct.pack('>H', len(payload) + 2) + payload
    return data[:2] + app1 + data[2:]


def payload(title, cat, **over):
    d = [('title', title), ('description', 'ทดสอบภาพตัวอย่างผลลัพธ์'), ('content', 'Minimal illustration of [ฉาก] in [โทนสี] palette, no text'),
         ('usage_example', ''), ('output_example', ''), ('category_id', str(cat)), ('tags', ''), ('visibility', 'private'), ('intent', 'draft')]
    d = [(k, over.pop(k, v)) for k, v in d]
    return d + list(over.items())


def set_google_config(text, enabled=True):
    block = ("    'google' => [\n        'enabled' => %s,\n        'client_id' => '%s',\n        'client_secret' => '%s',\n"
             "        'auth_url' => 'http://127.0.0.1:%d/auth',\n        'token_url' => 'http://127.0.0.1:%d/token',\n"
             "        'userinfo_url' => 'http://127.0.0.1:%d/userinfo',\n    ],\n") % ('true' if enabled else 'false', CLIENT_ID, CLIENT_SECRET, PORT, PORT, PORT)
    new, n = re.subn(r"    'google' => \[.*?\n    \],\n", lambda m: block, text, flags=re.S)
    assert n == 1
    open(CONFIG, 'w', encoding='utf-8', newline='\n').write(new)


original_config = open(CONFIG, encoding='utf-8').read()
original_google_settings = {r['setting_key']: r['setting_value'] for r in json.loads(subprocess.run(PHP + ['tests/fixtures.php', 'query'], input=json.dumps({
    'sql': "SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('google_login_enabled', 'google_client_id', 'google_client_secret')", 'params': []}),
    capture_output=True, text=True, encoding='utf-8', cwd=ROOT).stdout)}
google = FakeGoogle(PORT, CLIENT_ID, CLIENT_SECRET).start()
fx = json.loads(php('tests/fixtures.php', 'create').stdout)
SUFFIX, PW = fx['suffix'], fx['password']
IMG_CAT = scalar('SELECT id FROM categories WHERE allow_images = 1 AND is_active = 1 ORDER BY id LIMIT 1')
TEXT_CAT = scalar('SELECT id FROM categories WHERE allow_images = 0 AND is_active = 1 ORDER BY id LIMIT 1')

try:
    guest, member, other, admin = Client(), Client(), Client(), Client()
    for c, key in [(member, 'member'), (other, 'other'), (admin, 'admin')]:
        c.login(fx[key]['username'], PW)

    # ================= ภาพโปรไฟล์ =================
    AVATARS = os.path.join(ROOT, 'storage', 'uploads', 'avatars')
    def avatar_of(uid):
        return scalar('SELECT avatar_file FROM users WHERE id = ?', uid)
    mid = fx['member']['id']
    prof = member.get('profile')[1]
    check('avatar-initial' in prof and 'name="avatar"' in prof and 'enctype="multipart/form-data"' in prof, 'โปรไฟล์ยังไม่มีภาพ: แสดงอักษรย่อและฟอร์มอัปโหลด')
    code, html, url, _ = member.multipart('profile.avatar', [], [('avatar', 'me.png', png((200, 80, 40), text=b'AVATAR-SECRET'), 'image/png')])
    a1 = avatar_of(mid)
    check(code == 200 and 'r=profile' in url and a1 and re.fullmatch(r'[a-f0-9]{32}\.png', a1) and os.path.isfile(os.path.join(AVATARS, a1)),
          'อัปโหลดภาพโปรไฟล์ PNG เก็บนอก public ด้วยชื่อสุ่ม')
    check(b'AVATAR-SECRET' not in open(os.path.join(AVATARS, a1), 'rb').read(), 'ตัดข้อมูลแฝงออกจากภาพโปรไฟล์')
    check(('r=avatar&amp;id=%d&amp;v=%s' % (mid, a1[:8])) in member.get('home')[1], 'แถบเมนูแสดงภาพโปรไฟล์ของผู้ใช้')
    code, body, _, h = guest.raw('avatar', id=mid, v=a1[:8])
    check(code == 200 and h.get('Content-Type') == 'image/png' and h.get('X-Content-Type-Options') == 'nosniff' and 'sandbox' in h.get('Content-Security-Policy', ''),
          'เปิดภาพโปรไฟล์ได้ พร้อม header ป้องกัน')
    code, html, url, _ = member.multipart('profile.avatar', [], [('avatar', 'me.jpg', jpeg_with_exif(b'GPS-AVATAR'), 'image/jpeg')])
    a2 = avatar_of(mid)
    check(a2 != a1 and a2.endswith('.jpg') and not os.path.exists(os.path.join(AVATARS, a1)) and b'GPS-AVATAR' not in open(os.path.join(AVATARS, a2), 'rb').read(),
          'เปลี่ยนภาพใหม่ (JPEG ตัด EXIF) และลบไฟล์ภาพเดิม')
    code, html, _, _ = member.multipart('profile.avatar', [], [('avatar', 'x.png', b'GIF89a not really', 'image/png')])
    check(code == 422 and 'ไม่ใช่ภาพ JPEG, PNG หรือ WebP' in html and avatar_of(mid) == a2, 'ปฏิเสธไฟล์ที่ไม่ใช่ภาพ ภาพเดิมยังอยู่')
    code, html, _, _ = member.multipart('profile.avatar', [], [('avatar', 'big.png', b'\x89PNG\r\n\x1a\n' + os.urandom(2 * 1024 * 1024 + 10), 'image/png')])
    check(code == 422 and 'เกิน 2 MB' in html and avatar_of(mid) == a2, 'ปฏิเสธภาพโปรไฟล์เกิน 2 MB')
    check(member.multipart('profile.avatar', [], [])[0] == 422, 'ไม่ได้เลือกไฟล์ได้ 422')
    check(member.post('profile.avatar.delete', {}, csrf=False)[0] == 403 and avatar_of(mid) == a2, 'ลบภาพโปรไฟล์ต้องมี CSRF token')
    member.post('profile.avatar.delete', {})
    check(avatar_of(mid) is None and not os.path.exists(os.path.join(AVATARS, a2)) and guest.raw('avatar', id=mid)[0] == 404,
          'ลบภาพโปรไฟล์ของตนเองได้ ไฟล์ถูกลบและกลับไปใช้อักษรย่อ')
    admin.multipart('profile.avatar', [], [('avatar', 'adm.png', png((10, 120, 200)), 'image/png')])
    aa = avatar_of(fx['admin']['id'])
    check(aa and os.path.isfile(os.path.join(AVATARS, aa)) and 'avatar-lg' in admin.get('profile')[1], 'ผู้ดูแลเปลี่ยนภาพโปรไฟล์ของตนเองได้')
    member.multipart('profile.avatar', [], [('avatar', 'me.png', png((90, 90, 200)), 'image/png')])
    a3 = avatar_of(mid)
    check('ลบภาพ' in admin.get('admin.users', q=fx['member']['username'])[1], 'หลังบ้าน › สมาชิก มีปุ่มลบภาพโปรไฟล์')
    admin.post('admin.user.action', {'id': mid, 'op': 'remove_avatar'})
    check(avatar_of(mid) is None and not os.path.exists(os.path.join(AVATARS, a3))
          and scalar("SELECT COUNT(*) FROM admin_activity_logs WHERE action = 'user.avatar_remove' AND target_id = ?", mid) == 1,
          'ผู้ดูแลลบภาพโปรไฟล์ที่ไม่เหมาะสมของสมาชิกได้ และบันทึกกิจกรรม')
    member.multipart('profile.avatar', [], [('avatar', 'me.png', png((90, 90, 200)), 'image/png')])
    other.multipart('profile.avatar', [], [('avatar', 'o.png', png((9, 9, 9)), 'image/png')])
    other_avatar = os.path.join(AVATARS, avatar_of(fx['other']['id']))

    # ================= ภาพตัวอย่าง =================
    form = member.get('my.prompt.form')[1]
    check('id="imageSection"' in form and ('value="%d" data-images="1"' % IMG_CAT) in form and 'enctype="multipart/form-data"' in form,
          'ฟอร์มมีส่วนอัปโหลดภาพ และระบุหมวดที่รับภาพ')
    jpg = jpeg_with_exif(b'GPS-SECRET-' + SUFFIX.encode())
    title = 'ภาพทดสอบ ' + SUFFIX
    code, html, url, _ = member.multipart('my.prompt.save', payload(title, IMG_CAT),
                                          [('images[]', 'a.png', png(text=b'SECRET-PNG-TEXT'), 'image/png'), ('images[]', 'b.jpg', jpg, 'image/jpeg')])
    pid = int(re.search(r'[?&]id=(\d+)', url).group(1))
    rows = sql('SELECT id, file_name, mime FROM prompt_images WHERE prompt_id = ? ORDER BY sort_order', pid)
    check(code == 200 and len(rows) == 2 and [r['mime'] for r in rows] == ['image/png', 'image/jpeg'], 'บันทึก Prompt พร้อมภาพ PNG และ JPEG')
    files = [os.path.join(UPLOADS, r['file_name']) for r in rows]
    check(all(os.path.isfile(f) for f in files) and all(re.fullmatch(r'[a-f0-9]{32}\.(png|jpg)', r['file_name']) for r in rows),
          'เก็บไฟล์นอก public ด้วยชื่อสุ่ม')
    stored_png, stored_jpg = open(files[0], 'rb').read(), open(files[1], 'rb').read()
    check(b'SECRET-PNG-TEXT' not in stored_png and stored_png.startswith(b'\x89PNG'), 'ตัดข้อความแฝง (tEXt) ออกจาก PNG')
    check(b'GPS-SECRET' not in stored_jpg and b'Exif' not in stored_jpg and stored_jpg.startswith(b'\xff\xd8'), 'ตัดข้อมูล EXIF/GPS ออกจาก JPEG')
    code, body, _, h = member.raw('media', id=rows[0]['id'])
    check(code == 200 and h.get('Content-Type') == 'image/png' and h.get('X-Content-Type-Options') == 'nosniff'
          and 'sandbox' in h.get('Content-Security-Policy', '') and body.startswith(b'\x89PNG'), 'เจ้าของเปิดภาพได้ พร้อม header ป้องกัน')
    check(guest.raw('media', id=rows[0]['id'])[0] == 404 and other.raw('media', id=rows[0]['id'])[0] == 404,
          'ภาพของ Prompt ส่วนตัว ผู้เยี่ยมชมและสมาชิกอื่นเปิด URL ตรงไม่ได้')
    check(guest.raw('media', id='abc')[0] == 404 and guest.raw('media', id=999999999)[0] == 404, 'รหัสภาพไม่ถูกต้องได้ 404')
    detail = member.get('prompt', id=pid)[1]
    check(detail.count('r=media&amp;id=') >= 2 and 'prompt-gallery' in detail, 'หน้ารายละเอียดแสดงแกลเลอรีภาพ')

    code, html, _, _ = member.multipart('my.prompt.save', payload(title, IMG_CAT, id=str(pid)),
                                        [('images[]', 'evil.png', b'<?php echo 1; ?> not an image', 'image/png')])
    check(code == 422 and 'ไม่ใช่ภาพ JPEG, PNG หรือ WebP' in html and scalar('SELECT COUNT(*) FROM prompt_images WHERE prompt_id = ?', pid) == 2,
          'ปฏิเสธไฟล์ที่ไม่ใช่ภาพจริง (ตรวจจากเนื้อไฟล์)')
    code, html, _, _ = member.multipart('my.prompt.save', payload(title, IMG_CAT, id=str(pid)),
                                        [('images[]', 'c%d.png' % i, png((i * 40, 10, 10)), 'image/png') for i in range(3)])
    check(code == 422 and 'ไม่เกิน 4 ภาพ' in html and scalar('SELECT COUNT(*) FROM prompt_images WHERE prompt_id = ?', pid) == 2,
          'จำกัดไม่เกิน 4 ภาพต่อ Prompt')
    code, html, _, _ = member.multipart('my.prompt.save', payload('หมวดไม่รับภาพ ' + SUFFIX, TEXT_CAT),
                                        [('images[]', 'x.png', png(), 'image/png')])
    check(code == 422 and 'หมวดหมู่นี้ไม่รับภาพตัวอย่าง' in html, 'หมวดหมู่ที่ไม่รับภาพอัปโหลดไม่ได้')
    big = b'\x89PNG\r\n\x1a\n' + os.urandom(5 * 1024 * 1024 + 10)
    code, html, _, _ = member.multipart('my.prompt.save', payload(title, IMG_CAT, id=str(pid)), [('images[]', 'big.png', big, 'image/png')])
    check(code == 422 and '5 MB' in html, 'ปฏิเสธไฟล์ใหญ่เกิน 5 MB')

    member.multipart('my.prompt.save', payload(title, IMG_CAT, id=str(pid), visibility='public', intent='submit'), [])
    admin.post('admin.prompt.moderate', {'id': pid, 'decision': 'approve'})
    check(guest.raw('media', id=rows[0]['id'])[0] == 200, 'อนุมัติแล้วผู้เยี่ยมชมเห็นภาพได้')
    browse = guest.get('prompts', q=SUFFIX)[1]
    check('prompt-thumb' in browse and ('id=%d' % rows[0]['id']) in browse, 'การ์ด Prompt แสดงภาพหน้าปก')
    member.multipart('my.prompt.save', payload(title, IMG_CAT, id=str(pid), visibility='public', intent='submit') + [('remove_images[]', str(rows[0]['id']))], [])
    check(scalar('SELECT COUNT(*) FROM prompt_images WHERE prompt_id = ?', pid) == 1 and not os.path.exists(files[0]) and os.path.exists(files[1]),
          'ลบภาพที่เลือกออกจากฐานข้อมูลและดิสก์')
    check(scalar('SELECT status FROM prompts WHERE id = ?', pid) == 'pending' and guest.raw('media', id=rows[1]['id'])[0] == 404,
          'แก้ไขภาพของ Prompt ที่เผยแพร่แล้ว กลับไปรอตรวจสอบและภาพหยุดแสดงต่อสาธารณะ')
    member.post('my.prompt.delete', {'id': pid})
    check(not os.path.exists(files[1]) and scalar('SELECT COUNT(*) FROM prompt_images WHERE prompt_id = ?', pid) == 0, 'ลบ Prompt แล้วไฟล์ภาพถูกลบตาม')

    code, html, url, _ = other.multipart('my.prompt.save', payload('ของสมาชิกที่จะถูกลบ ' + SUFFIX, IMG_CAT), [('images[]', 'o.png', png(), 'image/png')])
    opid = int(re.search(r'[?&]id=(\d+)', url).group(1))
    ofile = os.path.join(UPLOADS, scalar('SELECT file_name FROM prompt_images WHERE prompt_id = ?', opid))
    admin.post('admin.user.action', {'id': fx['other']['id'], 'op': 'delete'})
    check(not os.path.exists(ofile) and not os.path.exists(other_avatar), 'ผู้ดูแลลบสมาชิกแล้วไฟล์ภาพตัวอย่างและภาพโปรไฟล์ถูกลบตาม')

    catname = 'หมวดภาพทดสอบ ' + SUFFIX
    admin.post('admin.category.save', {'name': catname, 'description': '', 'icon': 'image', 'sort_order': '99', 'is_active': '1', 'allow_images': '1'})
    cid = scalar('SELECT id FROM categories WHERE name = ?', catname)
    check(scalar('SELECT allow_images FROM categories WHERE id = ?', cid) == 1 and ('value="%d" data-images="1"' % cid) in member.get('my.prompt.form')[1],
          'ผู้ดูแลเปิดรับภาพให้หมวดหมู่ได้')

    # ================= การคัดลอกเฉพาะสมาชิก =================
    pub = scalar("SELECT id FROM prompts WHERE visibility = 'public' AND status = 'published' ORDER BY id LIMIT 1")
    full = scalar('SELECT content FROM prompts WHERE id = ?', pub)
    ghtml = guest.get('prompt', id=pub)[1]
    check('prompt-locked' in ghtml and 'copyButton' not in ghtml and full.split('\n')[-1][-20:] not in ghtml, 'ผู้เยี่ยมชมเห็นเพียงบางส่วนของ Prompt')
    check(guest.post('prompt.copy', {'id': pub})[0] == 401, 'ผู้เยี่ยมชมเรียก endpoint คัดลอกไม่ได้ (401)')

    # ================= แต้มสะสมและเหรียญ =================
    r = php('-r', 'require "app/bootstrap.php"; use PromptHub\Services\BadgeService as B; '
                  'foreach ([0,4,5,10,11,20,21,99] as $n) { $t = B::tierFor($n); echo $n, ":", $t ? $t["key"] : "-", ":", B::progress($n)["remaining"], " "; }')
    check(r.stdout.split() == ['0:-:5', '4:-:1', '5:bronze:6', '10:bronze:1', '11:silver:10', '20:silver:1', '21:gold:0', '99:gold:0'],
          'เกณฑ์เหรียญ: 5–10 ทองแดง, 11–20 เงิน, 21 ขึ้นไปทอง (และแต้มที่ขาดถึงเหรียญถัดไป)')
    mid = fx['member']['id']
    def points():
        return scalar("SELECT COUNT(*) FROM prompts WHERE user_id = ? AND status = 'published' AND visibility = 'public'", mid)
    def publish(n):
        ids = []
        for _ in range(n):
            _, _, u, _ = member.multipart('my.prompt.save', payload('แต้ม %s %d' % (SUFFIX, len(ids)), TEXT_CAT, visibility='public', intent='submit'), [])
            pid_ = int(re.search(r'[?&]id=(\d+)', u).group(1))
            admin.post('admin.prompt.moderate', {'id': pid_, 'decision': 'approve'})
            ids.append(pid_)
        return ids
    base = points()
    check(base == 0 and 'อีก <strong class="text-body">5</strong> แต้ม จะได้รับ' in member.get('dashboard')[1] and 'ยังไม่มีเหรียญ' in member.get('dashboard')[1],
          'แดชบอร์ดสมาชิกใหม่: 0 แต้ม ยังไม่มีเหรียญ และบอกแต้มที่ขาด')
    member.multipart('my.prompt.save', payload('ร่างไม่นับแต้ม ' + SUFFIX, TEXT_CAT), [])
    member.multipart('my.prompt.save', payload('รอตรวจไม่นับแต้ม ' + SUFFIX, TEXT_CAT, visibility='public', intent='submit'), [])
    ids = publish(4)
    dash = member.get('dashboard')[1]
    check(points() == 4 and 'id="pointsValue">4<' in dash and 'medal-bronze' not in dash.split('id="pointsHeading"')[1].split('</h2>')[0],
          'ฉบับร่างและรายการรอตรวจไม่นับแต้ม · 4 แต้มยังไม่ได้เหรียญ')
    ids += publish(1)
    dash = member.get('dashboard')[1]
    check('id="pointsValue">5<' in dash and 'medal medal-bronze' in dash and 'จะได้รับ<strong class="medal-text-silver">เหรียญเงิน' in dash,
          'ครบ 5 แต้ม ได้เหรียญทองแดง และบอกเป้าหมายเหรียญเงิน')
    detail = guest.get('prompt', id=ids[0])[1]
    check('medal medal-bronze' in detail and ('r=avatar&amp;id=%d' % mid) in detail, 'หน้ารายละเอียด Prompt แสดงภาพโปรไฟล์และเหรียญข้างชื่อผู้สร้าง')
    lead = guest.get('leaders')[1]
    check('id="leaderTable"' in lead and 'ทดสอบ member' in lead and 'medal medal-bronze' in lead, 'ทำเนียบแสดงสมาชิกพร้อมแต้มและเหรียญ (ผู้เยี่ยมชมเปิดได้)')
    check('ผู้ดูแลระบบ' not in lead.split('id="leaderTable"')[1] and 'ทดสอบ admin' not in lead, 'ผู้ดูแลไม่ร่วมสะสมแต้มในทำเนียบ')
    users_html = admin.get('admin.users', q=fx['member']['username'])[1]
    check('medal medal-bronze' in users_html, 'หลังบ้าน › สมาชิก แสดงแต้มและเหรียญ')
    ids += publish(5)
    check('medal medal-bronze' in member.get('dashboard')[1] and 'อีก <strong class="text-body">1</strong> แต้ม' in member.get('dashboard')[1], '10 แต้มยังเป็นเหรียญทองแดง')
    ids += publish(1)
    check('medal medal-silver' in member.get('dashboard')[1], 'ครบ 11 แต้ม ได้เหรียญเงิน')
    ids += publish(10)
    dash = member.get('dashboard')[1]
    check(points() == 21 and 'medal medal-gold' in dash and 'ได้รับเหรียญสูงสุดแล้ว' in dash, 'ครบ 21 แต้ม ได้เหรียญทอง')
    member.multipart('my.prompt.save', payload('แต้ม %s แก้ไข' % SUFFIX, TEXT_CAT, id=str(ids[0]), visibility='public', intent='submit'), [])
    check(points() == 20 and 'medal medal-silver' in member.get('dashboard')[1], 'แก้ Prompt ที่เผยแพร่ (กลับไปรอตรวจ) แต้มลดและเหรียญปรับตามทันที')
    admin.post('admin.prompt.moderate', {'id': ids[0], 'decision': 'approve'})
    check(points() == 21 and 'medal medal-gold' in member.get('dashboard')[1], 'อนุมัติอีกครั้งได้แต้มคืน')
    for i in ids:
        member.post('my.prompt.delete', {'id': i})

    # ================= คำแปลภาษาไทย =================
    th_word = 'อินโฟกราฟิกนิตยสาร' + SUFFIX
    th_text = 'สร้าง' + th_word + ' ใช้ตัวอักษรหนา\nจัดวางแบบไม่สมมาตร <b>ไม่ใช่ HTML</b>'
    code, html, url, _ = member.multipart('my.prompt.save', payload('Editorial ' + SUFFIX, TEXT_CAT, content='Create an editorial-style infographic with bold typography',
                                          content_th=th_text, visibility='public', intent='submit'), [])
    tid = int(re.search(r'[?&]id=(\d+)', url).group(1))
    check(scalar('SELECT content_th FROM prompts WHERE id = ?', tid) == th_text, 'บันทึกคำแปลภาษาไทยพร้อม Prompt (หลายบรรทัด)')
    check('id="content_th"' in member.get('my.prompt.form', id=tid)[1] and th_word in member.get('my.prompt.form', id=tid)[1], 'ฟอร์มแก้ไขแสดงคำแปลเดิม')
    admin.post('admin.prompt.moderate', {'id': tid, 'decision': 'approve'})
    mhtml = member.get('prompt', id=tid)[1]
    box = re.search(r'id="promptTranslation"[^>]*>(.*?)</div>', mhtml, re.S)
    check(box is not None and th_word in box.group(1) and '&lt;b&gt;' in box.group(1) and mhtml.index('id="promptSource"') < mhtml.index('id="promptTranslation"'),
          'สมาชิกเห็นคำแปลใต้เนื้อหาต้นฉบับ (escape HTML)')
    check(th_word not in guest.get('prompt', id=tid)[1], 'ผู้เยี่ยมชมไม่เห็นคำแปล (เหมือนเนื้อหาเต็ม)')
    check(('id=%d' % tid) in guest.get('prompts', q=th_word)[1], 'ค้นหาด้วยคำในคำแปลภาษาไทยเจอ Prompt')
    check(member.multipart('my.prompt.save', payload('Editorial ' + SUFFIX, TEXT_CAT, id=str(tid), content_th='ก' * 20001), [])[0] == 422, 'คำแปลยาวเกิน 20,000 ตัวอักษรได้ 422')
    member.multipart('my.prompt.save', payload('Editorial ' + SUFFIX, TEXT_CAT, id=str(tid), content_th='   '), [])
    check(scalar('SELECT content_th FROM prompts WHERE id = ?', tid) is None and 'id="promptTranslation"' not in member.get('prompt', id=tid)[1],
          'ลบคำแปล (ช่องว่าง) แล้วไม่แสดงกล่องคำแปล')
    member.post('my.prompt.delete', {'id': tid})

    # ================= Google =================
    check('google.start' not in guest.get('login')[1], 'ปิดใช้ Google: ไม่มีปุ่มในหน้าเข้าสู่ระบบ')
    check(guest.post('google.start', {})[0] == 404, 'ปิดใช้ Google: เริ่มขั้นตอนไม่ได้')
    set_google_config(original_config, True)
    check('google.start' in guest.get('login')[1] and 'google.start' in guest.get('register')[1], 'เปิดใช้ Google: มีปุ่มในหน้าเข้าสู่ระบบและสมัครสมาชิก')

    # URL เว็บไซต์ที่ตั้งในหลังบ้าน → redirect_uri ที่ส่งให้ Google ต้องใช้ URL นั้น
    settings = {r['setting_key']: r['setting_value'] for r in sql('SELECT setting_key, setting_value FROM site_settings')}
    admin.post('admin.settings.save', dict(settings, site_url='https://hub-' + SUFFIX + '.example.ac.th'))
    probe = Client()
    tok = probe.token()
    raw = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(probe.jar), NoRedirect)
    try:
        raw.open(urllib.request.Request(BASE + '?r=google.start', data=urllib.parse.urlencode({'_csrf': tok}).encode()))
        location = ''
    except urllib.error.HTTPError as e:
        location = e.headers.get('Location', '')
    admin.post('admin.settings.save', settings)
    q = dict(urllib.parse.parse_qsl(urllib.parse.urlparse(location).query))
    check(q.get('redirect_uri') == 'https://hub-' + SUFFIX + '.example.ac.th/google_callback.php' and q.get('code_challenge_method') == 'S256',
          'ตั้ง URL เว็บไซต์แล้ว Google redirect URI ใช้ URL นั้น')

    gsub, gemail = 'g' + SUFFIX + '1', 'gnew_' + SUFFIX + '@example.invalid'
    google.profile = {'sub': gsub, 'email': gemail, 'email_verified': True, 'name': 'ครูกูเกิล ทดสอบ'}
    g = Client()
    code, html, url, _ = g.post('google.start', {})
    check(code == 200 and 'r=google.confirm' in url and gemail in html and 'ครูกูเกิล ทดสอบ' in html, 'บัญชี Google ใหม่ไปหน้ายืนยันสร้างบัญชี')
    check(google.token_requests[-1].get('code_verifier'), 'แลก code ด้วย PKCE (code_verifier)')
    code, html, _, _ = g.post('google.finish', {'username': 'g_' + SUFFIX, 'display_name': 'ครูกูเกิล ทดสอบ'})
    check(code == 422 and scalar('SELECT COUNT(*) FROM users WHERE email = ?', gemail) == 0, 'ต้องยอมรับนโยบายความเป็นส่วนตัวก่อนสร้างบัญชี')
    code, html, url, _ = g.post('google.finish', {'username': 'g_' + SUFFIX, 'display_name': 'ครูกูเกิล ทดสอบ', 'accept_privacy': '1'})
    row = sql('SELECT google_sub, password_hash, privacy_accepted_at IS NOT NULL AS consent, role FROM users WHERE email = ?', gemail)
    check(code == 200 and 'r=dashboard' in url and row and row[0]['google_sub'] == gsub and row[0]['password_hash'] == '!google'
          and row[0]['consent'] == 1 and row[0]['role'] == 'member', 'สร้างบัญชีสมาชิกจาก Google พร้อมบันทึกการยอมรับนโยบาย')
    check('สร้างด้วย Google' in g.get('profile')[1], 'หน้าโปรไฟล์แจ้งว่าเป็นบัญชี Google')
    check('id="profile_password"' not in g.get('profile')[1], 'บัญชี Google ที่ไม่มีรหัสผ่าน ไม่มีช่องยืนยันรหัสผ่านในโปรไฟล์')
    gemail2 = 'gchanged_' + SUFFIX + '@example.invalid'
    g.post('profile.update', {'display_name': 'ครูกูเกิล ทดสอบ', 'username': 'g_' + SUFFIX, 'email': gemail2})
    check(scalar('SELECT email FROM users WHERE google_sub = ?', gsub) == gemail2, 'บัญชี Google เปลี่ยนอีเมลได้โดยไม่ต้องใช้รหัสผ่าน')
    g.post('profile.update', {'display_name': 'ครูกูเกิล ทดสอบ', 'username': 'g_' + SUFFIX, 'email': gemail})
    check(g.post('prompt.copy', {'id': pub})[0] == 200, 'สมาชิกจาก Google คัดลอก Prompt ได้')
    g.post('logout', {})
    check(Client().login('g_' + SUFFIX, '!google')[0] == 422, 'บัญชี Google เข้าสู่ระบบด้วยรหัสผ่านไม่ได้')
    users_before = scalar('SELECT COUNT(*) FROM users')
    code, _, url, _ = g.post('google.start', {})
    check(code == 200 and 'r=dashboard' in url and scalar('SELECT COUNT(*) FROM users') == users_before, 'เข้าสู่ระบบด้วย Google ซ้ำ เข้าบัญชีเดิมทันที')

    google.profile = {'sub': 'g' + SUFFIX + '2', 'email': fx['member']['username'] + '@example.invalid', 'email_verified': True, 'name': 'x'}
    code, html, url, _ = Client().post('google.start', {})
    check('r=dashboard' in url and 'เชื่อมบัญชี Google' in html and scalar('SELECT google_sub FROM users WHERE id = ?', fx['member']['id']) == 'g' + SUFFIX + '2',
          'อีเมลตรงกับบัญชีเดิม: เชื่อมบัญชี Google กับบัญชีเดิม')

    google.profile = {'sub': 'g' + SUFFIX + '3', 'email': 'unverified_' + SUFFIX + '@example.invalid', 'email_verified': False, 'name': 'x'}
    code, html, _, _ = Client().post('google.start', {})
    check(code == 400 and 'ยังไม่ได้ยืนยัน' in html and scalar('SELECT COUNT(*) FROM users WHERE email = ?', 'unverified_' + SUFFIX + '@example.invalid') == 0,
          'ปฏิเสธอีเมล Google ที่ยังไม่ยืนยัน')
    t = Client()
    t.get('login')
    check(t.get('google.callback', code='code-x', state='forged')[0] == 400, 'ปฏิเสธ callback ที่ state ไม่ตรง (กัน CSRF)')

    gid = scalar('SELECT id FROM users WHERE email = ?', gemail)
    admin.post('admin.user.action', {'id': gid, 'op': 'suspend'})
    google.profile = {'sub': gsub, 'email': gemail, 'email_verified': True, 'name': 'x'}
    code, html, url, _ = Client().post('google.start', {})
    check('r=login' in url and 'ถูกระงับ' in html, 'บัญชี Google ที่ถูกระงับเข้าสู่ระบบไม่ได้')

    set_google_config(original_config, False)
    check(Client().post('google.start', {})[0] == 404, 'ปิดใช้ Google อีกครั้งแล้วเริ่มขั้นตอนไม่ได้')

    # ================= เปิด/ปิด Google Login และตั้ง Client ในหลังบ้าน =================
    # config ตอนนี้: enabled = false แต่มี client_id/secret ของเซิร์ฟเวอร์จำลอง
    check(guest.post('admin.google.save', {'google_login_enabled': '1'})[0] == 401 and member.post('admin.google.save', {'google_login_enabled': '1'})[0] == 403
          and member.post('admin.google.test', {})[0] == 403, 'ตั้งค่า Google Login ได้เฉพาะผู้ดูแล')
    page = admin.get('admin.settings')[1]
    check('id="googleLoginCard"' in page and 'id="googleStatus">ปิดอยู่' in page and BASE.replace('/index.php', '/google_callback.php') in page, 'หน้าตั้งค่ามีส่วน Google Login แสดงสถานะและ redirect URI')
    admin.post('admin.google.save', {'google_login_enabled': '1'})
    check('google.start' in Client().get('login')[1] and 'id="googleStatus">เปิดใช้งาน' in admin.get('admin.settings')[1],
          'เปิดสวิตช์ในหลังบ้าน (ใช้ Client ใน config) → ปุ่ม Google แสดงในหน้าเข้าสู่ระบบ')
    google.strict_clients = {CLIENT_ID}
    google.allowed_redirects = {BASE.replace('/index.php', '/google_callback.php')}
    check('Google รับ Client ID และ redirect URI แล้ว' in admin.post('admin.google.test', {})[1], 'ปุ่ม “ทดสอบกับ Google”: ตั้งค่าถูกต้อง → แจ้งผ่าน')
    google.allowed_redirects = {'https://elsewhere.example/callback'}
    check('redirect_uri_mismatch' in admin.post('admin.google.test', {})[1], 'ปุ่มทดสอบ: redirect URI ไม่ได้ลงทะเบียน → แจ้ง redirect_uri_mismatch พร้อม URI ที่ต้องเพิ่ม')
    google.allowed_redirects = None
    admin.post('admin.google.save', {'google_login_enabled': '1', 'google_client_id': 'not-a-client-id', 'google_client_secret': 'fake-secret-abcdefgh1234'})
    check(scalar("SELECT setting_value FROM site_settings WHERE setting_key = 'google_client_id'") in (None, ''), 'ปฏิเสธ Client ID ผิดรูปแบบ')
    code, html, _, _ = admin.post('admin.google.save', {'google_login_enabled': '1', 'google_client_id': '123-abc' + SUFFIX + '.apps.googleusercontent.com'})
    check('ให้ครบทั้งคู่' in html, 'ใส่ Client ID โดยไม่มี secret → แจ้งให้กรอกครบ')
    secret = 'GOCSPX-test' + SUFFIX + 'secret'
    admin.post('admin.google.save', {'google_login_enabled': '1', 'google_client_id': '123-abc' + SUFFIX + '.apps.googleusercontent.com', 'google_client_secret': secret})
    page = admin.get('admin.settings')[1]
    check(secret not in page and 'ตั้งค่าแล้ว' in page and 'Client ID จากหน้านี้' in page, 'บันทึก Client ID/secret ในหลังบ้าน และไม่แสดง secret ในหน้าเว็บ')
    p = Client()
    tok = p.token()
    raw_op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(p.jar), NoRedirect)
    try:
        raw_op.open(urllib.request.Request(BASE + '?r=google.start', data=urllib.parse.urlencode({'_csrf': tok}).encode()))
        loc = ''
    except urllib.error.HTTPError as e:
        loc = e.headers.get('Location', '')
    check(('client_id=123-abc' + SUFFIX + '.apps.googleusercontent.com') in loc, 'ปุ่ม Google ใช้ Client ID จากหลังบ้านแทนค่าใน config')
    check('invalid_client' in admin.post('admin.google.test', {})[1], 'ปุ่มทดสอบ: Client ID ที่ Google ไม่รู้จัก → แจ้ง invalid_client')
    admin.post('admin.google.save', {'google_login_enabled': '1', 'google_client_id': '123-abc' + SUFFIX + '.apps.googleusercontent.com', 'google_client_secret': ''})
    check(scalar("SELECT setting_value FROM site_settings WHERE setting_key = 'google_client_secret'") == secret, 'เว้นช่อง secret ว่าง = ใช้ secret เดิม')
    admin.post('admin.google.save', {'google_login_enabled': '0', 'google_client_id': '123-abc' + SUFFIX + '.apps.googleusercontent.com', 'google_client_secret': ''})
    check('google.start' not in Client().get('login')[1] and Client().post('google.start', {})[0] == 404, 'ปิดสวิตช์ → ซ่อนปุ่มและเริ่มขั้นตอนไม่ได้')
    admin.post('admin.google.save', {'google_login_enabled': '0', 'google_clear': '1'})
    check(scalar("SELECT setting_value FROM site_settings WHERE setting_key = 'google_client_id'") == '' and 'Client ID จากไฟล์ config' in admin.get('admin.settings')[1],
          'ลบค่าในหลังบ้าน → กลับไปใช้ Client ในไฟล์ config')
    check(scalar("SELECT COUNT(*) FROM admin_activity_logs WHERE admin_id = ? AND action = 'google.settings' AND details NOT LIKE ?", fx['admin']['id'], '%' + secret + '%') >= 3,
          'บันทึกกิจกรรมการตั้งค่า Google (ไม่มี secret ใน log)')
    google.strict_clients = None

    # Redirect URI ตั้งเองได้ในหลังบ้าน (เฉพาะ URL ที่ชี้มาที่ตัวรับ callback ของระบบ)
    admin.post('admin.google.save', {'google_login_enabled': '0', 'google_redirect_uri': 'https://evil.example/steal'})
    check(scalar("SELECT setting_value FROM site_settings WHERE setting_key = 'google_redirect_uri'") in (None, ''), 'ปฏิเสธ redirect URI ที่ไม่ใช่ตัวรับ callback ของระบบ')
    custom = 'https://hub-' + SUFFIX + '.example.ac.th/sub/index.php?r=google.callback'
    admin.post('admin.google.save', {'google_login_enabled': '1', 'google_redirect_uri': custom})
    page = admin.get('admin.settings')[1]
    check(scalar("SELECT setting_value FROM site_settings WHERE setting_key = 'google_redirect_uri'") == custom and 'id="google_redirect_uri"' in page and custom.replace('&', '&amp;') in page,
          'บันทึก redirect URI ที่ตั้งเองในหลังบ้าน และแสดงในหน้าตั้งค่า')
    p = Client()
    tok = p.token()
    try:
        urllib.request.build_opener(urllib.request.HTTPCookieProcessor(p.jar), NoRedirect).open(
            urllib.request.Request(BASE + '?r=google.start', data=urllib.parse.urlencode({'_csrf': tok}).encode()))
        loc = ''
    except urllib.error.HTTPError as e:
        loc = e.headers.get('Location', '')
    check(dict(urllib.parse.parse_qsl(urllib.parse.urlparse(loc).query)).get('redirect_uri') == custom, 'ปุ่ม Google ส่ง redirect URI ที่ตั้งเองให้ Google')
    admin.post('admin.google.save', {'google_login_enabled': '0', 'google_redirect_uri': ''})
    check(scalar("SELECT setting_value FROM site_settings WHERE setting_key = 'google_redirect_uri'") == '' and '/google_callback.php' in admin.get('admin.settings')[1],
          'เว้นว่าง redirect URI → กลับไปใช้ค่าเริ่มต้น /google_callback.php')


    print('\nTOTAL', len(passed), 'passed')
finally:
    open(CONFIG, 'w', encoding='utf-8', newline='\n').write(original_config)
    php('tests/fixtures.php', 'settings-restore', stdin=json.dumps({k: original_google_settings.get(k) for k in ('google_login_enabled', 'google_client_id', 'google_client_secret')}))
    google.stop()
    print(php('tests/fixtures.php', 'cleanup', SUFFIX).stdout)
