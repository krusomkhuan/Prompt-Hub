"""ทดสอบการย่อภาพฝั่งเซิร์ฟเวอร์ผ่านเว็บจริง (ต้องเปิด extension=gd ใน php.ini ของ Apache)

    python tests/server_resize.py --allow-test-data

ส่งไฟล์ภาพขนาดใหญ่ตรงถึงเซิร์ฟเวอร์ (ไม่ผ่านการย่อในเบราว์เซอร์) แล้วตรวจไฟล์ที่ระบบเก็บจริง
"""
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
import uuid

if '--allow-test-data' not in sys.argv:
    raise SystemExit('ใช้ --allow-test-data เพื่อยืนยันว่ารันกับฐานข้อมูลที่สร้างข้อมูลทดสอบได้')

BASE = os.environ.get('PROMPT_HUB_BASE', 'http://localhost/prompt_hub/public/index.php')
PHP = shlex.split(os.environ.get('PROMPT_HUB_PHP', 'C:/xampp/php/php.exe'))
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
passed = []

# สร้างภาพทดสอบด้วย GD ของ PHP CLI: [ชื่อ, ชนิด, กว้าง, สูง, ตัวเลือก]
MAKE_IMAGE = r'''
[$type, $w, $h, $opt] = [$argv[1], (int) $argv[2], (int) $argv[3], $argv[4] ?? ''];
$im = imagecreatetruecolor($w, $h);
if ($opt === 'noise') {
    for ($y = 0; $y < $h; $y += 2) for ($x = 0; $x < $w; $x += 2)
        imagefilledrectangle($im, $x, $y, $x + 1, $y + 1, imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
} elseif ($opt === 'halves') {
    imagefilledrectangle($im, 0, 0, intdiv($w, 2) - 1, $h, imagecolorallocate($im, 255, 0, 0));
    imagefilledrectangle($im, intdiv($w, 2), 0, $w, $h, imagecolorallocate($im, 0, 0, 255));
} else {
    for ($i = 0; $i < 300; $i++) imagefilledellipse($im, mt_rand(0, $w), mt_rand(0, $h), mt_rand(40, 400), mt_rand(40, 400), imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
}
ob_start();
$type === 'png' ? imagepng($im) : imagejpeg($im, null, 95);
$data = ob_get_clean();
if ($opt === 'halves') { // ใส่ EXIF Orientation = 6 (ภาพจากมือถือที่ต้องหมุน 90° ตามเข็ม)
    $tiff = 'II' . pack('v', 42) . pack('V', 8) . pack('v', 1) . pack('vvV', 0x0112, 3, 1) . pack('v', 6) . "\0\0" . pack('V', 0);
    $p = "Exif\0\0" . $tiff;
    $data = substr($data, 0, 2) . "\xFF\xE1" . pack('n', strlen($p) + 2) . $p . substr($data, 2);
}
echo base64_encode($data);
'''

INFO = r'''$f = $argv[1]; $i = getimagesize($f); $im = imagecreatefromstring(file_get_contents($f));
$top = imagecolorsforindex($im, imagecolorat($im, intdiv(imagesx($im), 2), 5));
echo json_encode(['w' => $i[0], 'h' => $i[1], 'mime' => $i['mime'], 'bytes' => filesize($f), 'top_red' => $top['red'], 'top_blue' => $top['blue']]);'''


def check(ok, name):
    if not ok:
        raise AssertionError('FAIL: ' + name)
    passed.append(name)
    print('PASS', name)


def php(*args, stdin=None):
    r = subprocess.run(PHP + list(args), input=stdin, capture_output=True, text=True, encoding='utf-8', cwd=ROOT)
    if r.returncode:
        raise RuntimeError(r.stderr or r.stdout)
    return r.stdout


def image(kind, w, h, opt=''):
    import base64
    return base64.b64decode(php('-r', MAKE_IMAGE, kind, str(w), str(h), opt))


def info(path):
    return json.loads(php('-r', INFO, path))


def sql(query, *params):
    return json.loads(php('tests/fixtures.php', 'query', stdin=json.dumps({'sql': query, 'params': list(params)})))


class Client:
    def __init__(self):
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def open(self, req):
        try:
            r = self.op.open(req)
            return r.status, r.read().decode('utf-8', 'replace'), r.url
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace'), e.url

    def token(self):
        return re.search(r'name="_csrf" value="([a-f0-9]{64})"', self.open(BASE + '?r=login')[1]).group(1)

    def post(self, route, data):
        data = dict(data, _csrf=self.token())
        return self.open(urllib.request.Request(BASE + '?r=' + route, data=urllib.parse.urlencode(data).encode()))

    def multipart(self, route, fields, files):
        b = uuid.uuid4().hex
        parts = [('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (b, k, v)).encode()
                 for k, v in list(fields) + [('_csrf', self.token())]]
        parts += [('--%s\r\nContent-Disposition: form-data; name="%s"; filename="%s"\r\nContent-Type: %s\r\n\r\n' % (b, k, n, t)).encode() + c + b'\r\n'
                  for k, n, c, t in files]
        body = b''.join(parts) + ('--%s--\r\n' % b).encode()
        return self.open(urllib.request.Request(BASE + '?r=' + route, data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + b}))


fx = json.loads(php('tests/fixtures.php', 'create'))
try:
    member = Client()
    member.post('login.submit', {'login': fx['member']['username'], 'password': fx['password']})
    cat = sql('SELECT id FROM categories WHERE allow_images = 1 AND is_active = 1 ORDER BY id LIMIT 1')[0]['id']

    def save_prompt(title, files):
        fields = [('title', title), ('description', 'ทดสอบย่อภาพฝั่งเซิร์ฟเวอร์'), ('content', 'ภาพ [ฉาก] โทน [สี]'),
                  ('category_id', str(cat)), ('visibility', 'private'), ('intent', 'draft'), ('tags', '')]
        code, html, url = member.multipart('my.prompt.save', fields, files)
        assert code == 200, (code, re.findall(r'invalid-feedback[^>]*>([^<]+)', html) or re.findall(r'alert-danger[^>]*>(.*?)</div>', html, re.S))
        pid = int(re.search(r'[?&]id=(\d+)', url).group(1))
        return sql('SELECT file_name, mime, width, height, bytes FROM prompt_images WHERE prompt_id = ? ORDER BY sort_order', pid)

    big = image('jpg', 4000, 3000)
    rows = save_prompt('ย่อฝั่งเซิร์ฟเวอร์ ' + fx['suffix'], [('images[]', 'big.jpg', big, 'image/jpeg')])
    meta = info(os.path.join(ROOT, 'storage', 'uploads', 'prompts', rows[0]['file_name']))
    check(rows[0]['width'] == 1600 and rows[0]['height'] == 1200 and meta['w'] == 1600 and meta['h'] == 1200,
          'เซิร์ฟเวอร์ (Apache + GD) ย่อ JPEG 4000×3000 เป็น 1600×1200 (%.1f MB → %.0f KB)' % (len(big) / 1048576, meta['bytes'] / 1024))
    check(rows[0]['bytes'] == meta['bytes'], 'บันทึกขนาดไฟล์และขนาดภาพหลังย่อในฐานข้อมูลถูกต้อง')

    heavy = image('png', 1400, 1400, 'noise')
    rows = save_prompt('PNG หนัก ' + fx['suffix'], [('images[]', 'ai.png', heavy, 'image/png')])
    meta = info(os.path.join(ROOT, 'storage', 'uploads', 'prompts', rows[0]['file_name']))
    check(rows[0]['mime'] == 'image/jpeg' and rows[0]['file_name'].endswith('.jpg') and meta['mime'] == 'image/jpeg' and meta['bytes'] < len(heavy),
          'PNG ทึบ %.1f MB แปลงเป็น JPEG %.1f MB' % (len(heavy) / 1048576, meta['bytes'] / 1048576))

    rotated = image('jpg', 800, 400, 'halves')
    rows = save_prompt('หมุนภาพ ' + fx['suffix'], [('images[]', 'phone.jpg', rotated, 'image/jpeg')])
    meta = info(os.path.join(ROOT, 'storage', 'uploads', 'prompts', rows[0]['file_name']))
    check(rows[0]['width'] == 400 and rows[0]['height'] == 800 and meta['top_red'] > 200 and meta['top_blue'] < 60,
          'ภาพมือถือ (EXIF Orientation 6) ถูกหมุนให้ตั้งตรงเป็น 400×800')

    small = image('jpg', 640, 480)
    rows = save_prompt('ภาพเล็ก ' + fx['suffix'], [('images[]', 'small.jpg', small, 'image/jpeg')])
    stored = open(os.path.join(ROOT, 'storage', 'uploads', 'prompts', rows[0]['file_name']), 'rb').read()
    check(rows[0]['width'] == 640 and len(stored) <= len(small), 'ภาพเล็กกว่าเกณฑ์ไม่ถูกย่อหรือบีบอัดซ้ำ')

    avatar = image('png', 1500, 900)
    code, html, url = member.multipart('profile.avatar', [], [('avatar', 'me.png', avatar, 'image/png')])
    name = sql('SELECT avatar_file FROM users WHERE id = ?', fx['member']['id'])[0]['avatar_file']
    meta = info(os.path.join(ROOT, 'storage', 'uploads', 'avatars', name))
    check(code == 200 and meta['w'] == 256 and meta['h'] == 256, 'ภาพโปรไฟล์ 1500×900 ถูกตัดกลางเป็น 256×256 (%.0f KB → %.0f KB)'
          % (len(avatar) / 1024, meta['bytes'] / 1024))
    print('\nTOTAL', len(passed), 'passed')
finally:
    print(php('tests/fixtures.php', 'cleanup', fx['suffix']))
