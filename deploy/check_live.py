"""ตรวจความพร้อมของ “เข้าสู่ระบบ/สมัครด้วย Google” บนเว็บจริง (อ่านอย่างเดียว ไม่เข้าสู่ระบบบัญชีใด ไม่สร้างข้อมูล)

    python deploy/check_live.py [https://krusomkhuan.com/prompthub/public]

ขั้นตอนที่ตรวจ: เวอร์ชัน · HTTPS · CSP form-action · ปุ่ม Google · การเริ่ม OAuth (redirect_uri) · คำตอบของ Google ต่อ Client ID/redirect URI
"""
import http.cookiejar
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = (sys.argv[1] if len(sys.argv) > 1 else 'https://krusomkhuan.com/prompthub/public').rstrip('/')
INDEX = BASE + '/index.php'
UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36'
results = []


def report(ok, name, detail=''):
    results.append(ok)
    print(('ผ่าน   ' if ok else 'ไม่ผ่าน ') + name + (' — ' + detail if detail else ''))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


jar = http.cookiejar.CookieJar()
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
raw = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)


def get(url):
    r = op.open(urllib.request.Request(url, headers={'User-Agent': UA}), timeout=25)
    return r.read().decode('utf-8', 'replace'), dict(r.headers), r.url


home, headers, _ = get(INDEX + '?r=login')
version = re.search(r'เวอร์ชัน ([0-9.]+)', home)
report(version is not None and tuple(map(int, version.group(1).split('.'))) >= (1, 9, 1), 'เวอร์ชันบนเว็บ 1.9.1 ขึ้นไป',
       'พบ ' + (version.group(1) if version else 'ไม่พบเลขเวอร์ชัน'))
report(BASE.startswith('https://'), 'ใช้ HTTPS')
csp = headers.get('Content-Security-Policy', '')
fa = re.search(r"form-action ([^;]*)", csp)
report(bool(fa) and 'https://accounts.google.com' in fa.group(1), 'CSP อนุญาตฟอร์มไปหน้า Google (ปุ่มไม่ค้าง)', 'form-action ' + (fa.group(1) if fa else '-'))
register, _, _ = get(INDEX + '?r=register')
has_login_btn, has_reg_btn = 'google.start' in home, 'google.start' in register
report(has_login_btn and has_reg_btn, 'มีปุ่ม Google ในหน้าเข้าสู่ระบบและสมัครสมาชิก',
       '' if has_login_btn and has_reg_btn else "ไม่พบปุ่ม → ตั้ง 'enabled' => true และใส่ client_id/client_secret ใน config/config.php บนโฮสต์")

if has_login_btn:
    token = re.search(r'name="_csrf" value="([a-f0-9]{64})"', home).group(1)
    try:
        raw.open(urllib.request.Request(INDEX + '?r=google.start', data=urllib.parse.urlencode({'_csrf': token}).encode(),
                                        headers={'User-Agent': UA}), timeout=25)
        location = ''
    except urllib.error.HTTPError as e:
        location = e.headers.get('Location', '')
    q = dict(urllib.parse.parse_qsl(urllib.parse.urlparse(location).query))
    expected = BASE + '/google_callback.php'
    report(location.startswith('https://accounts.google.com/') and q.get('redirect_uri') == expected, 'เว็บส่งผู้ใช้ไปหน้า Google พร้อม redirect URI ที่ถูกต้อง',
           'redirect_uri = ' + q.get('redirect_uri', '-'))
    if location:
        try:
            g = op.open(urllib.request.Request(location, headers={'User-Agent': UA, 'Accept-Language': 'th,en'}), timeout=25)
            body, final = g.read().decode('utf-8', 'replace'), g.url
        except urllib.error.HTTPError as e:
            body, final = e.read().decode('utf-8', 'replace'), e.url
        errors = {
            'redirect_uri_mismatch': 'ยังไม่ได้เพิ่ม redirect URI ข้างบนใน OAuth Client (Authorized redirect URIs)',
            'invalid_client': 'Client ID ไม่ถูกต้อง หรือ OAuth Client ถูกลบ',
            'deleted_client': 'OAuth Client ถูกลบแล้ว',
            'disabled_client': 'OAuth Client ถูกปิดใช้งาน',
            'org_internal': 'consent screen เป็น Internal — บัญชีนอกโดเมนเข้าไม่ได้ ให้เปลี่ยนเป็น External',
            'access_denied': 'Google ปฏิเสธคำขอ',
        }
        found = [k for k in errors if k in body or k in final]
        report(not found and 'accounts.google.com' in final, 'Google รับ Client ID และ redirect URI (แสดงหน้าเลือกบัญชี)',
               '; '.join(errors[k] for k in found) if found else '')

print('\nสรุป: ผ่าน %d/%d' % (sum(results), len(results)))
print('หมายเหตุ: ตัวตรวจนี้ทดสอบได้ถึงหน้าเลือกบัญชีของ Google — การกดเลือกบัญชีและอนุญาตต้องลองเองในเบราว์เซอร์')
sys.exit(0 if all(results) else 1)
