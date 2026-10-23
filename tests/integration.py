"""Prompt Hub — ชุดทดสอบ HTTP ตามเกณฑ์ตรวจรับ (รันกับเว็บและฐานข้อมูลจริง)

สร้างบัญชี/ข้อมูลทดสอบชั่วคราว แล้วลบออกเมื่อจบ ใช้กับเครื่องทดลองหรือฐานข้อมูลทดสอบเท่านั้น

    python tests/integration.py --allow-test-data

ตัวแปรสภาพแวดล้อม:
    PROMPT_HUB_BASE  URL ของ index.php (ค่าเริ่มต้น http://localhost/prompt_hub/public/index.php)
    PROMPT_HUB_PHP   คำสั่ง PHP CLI (ค่าเริ่มต้น C:/xampp/php/php.exe)
"""
import datetime
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

if '--allow-test-data' not in sys.argv:
    raise SystemExit('ใช้ --allow-test-data เพื่อยืนยันว่ารันกับฐานข้อมูลที่สร้างข้อมูลทดสอบได้')

BASE = os.environ.get('PROMPT_HUB_BASE', 'http://localhost/prompt_hub/public/index.php')
PHP = shlex.split(os.environ.get('PROMPT_HUB_PHP', 'C:/xampp/php/php.exe'))
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
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
    if r.returncode != 0:
        raise RuntimeError(r.stderr)
    return json.loads(r.stdout)


def scalar(query, *params):
    rows = sql(query, *params)
    return list(rows[0].values())[0] if rows else None


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def req(self, route='home', data=None, headers=None, **query):
        url = BASE + '?' + urllib.parse.urlencode(dict(r=route, **query))
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        request = urllib.request.Request(url, data=body, headers=headers or {})
        try:
            res = self.opener.open(request)
            return res.status, res.read().decode('utf-8'), res.url, dict(res.headers)
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8'), e.url, dict(e.headers)

    def get(self, route='home', **query):
        return self.req(route, **query)

    def token(self):
        # หน้า login มีฟอร์มพร้อม token (ผู้ที่เข้าสู่ระบบแล้วถูกพาไปแดชบอร์ดซึ่งมีฟอร์มออกจากระบบ)
        _, html, _, _ = self.get('login')
        m = re.search(r'name="_csrf" value="([a-f0-9]{64})"', html)
        return m.group(1) if m else ''

    def post(self, route, data=None, csrf=True, **query):
        data = dict(data or {})
        if csrf:
            data['_csrf'] = self.token()
        return self.req(route, data, **query)

    def login(self, username, password):
        return self.post('login.submit', {'login': username, 'password': password})

    def session_id(self):
        for c in self.jar:
            if c.name == 'PROMPTHUB_SID':
                return c.value
        return None


def prompt_payload(title, **over):
    data = {
        'title': title,
        'description': 'คำอธิบายภาษาไทยสำหรับทดสอบระบบ',
        'content': '<script>alert("xss")</script>\nเขียนแคปชันขาย [ชื่อสินค้า]\nสำหรับ [กลุ่มเป้าหมาย] น้ำเสียง [น้ำเสียง]',
        'usage_example': '<b>ตัวอย่าง</b> บรรทัดแรก\nบรรทัดที่สอง',
        'output_example': 'ผลลัพธ์ทดสอบ',
        'category_id': str(CAT),
        'tags': 'ทดสอบ' + SUFFIX + ', ภาษาไทย',
        'ai_tools[]': [str(TOOL)],
        'visibility': 'private',
        'intent': 'draft',
    }
    data.update(over)
    return data


fx = json.loads(php('tests/fixtures.php', 'create').stdout)
SUFFIX, PW = fx['suffix'], fx['password']
CAT = scalar("SELECT id FROM categories WHERE is_active = 1 ORDER BY sort_order LIMIT 1")
CAT2 = scalar("SELECT id FROM categories WHERE is_active = 1 ORDER BY sort_order DESC LIMIT 1")
TOOL = scalar("SELECT id FROM ai_tools WHERE is_active = 1 ORDER BY sort_order LIMIT 1")
original_settings = {r['setting_key']: r['setting_value'] for r in sql('SELECT setting_key, setting_value FROM site_settings')}

try:
    guest, member, other, admin = Client(), Client(), Client(), Client()

    # ---------- หน้าแรก ภาษาไทย และ header ความปลอดภัย ----------
    code, html, _, headers = guest.get('home')
    check(code == 200 and original_settings['site_slogan'] in html and 'Prompts' in html, 'หน้าแรกแสดงชื่อ สโลแกน และสถิติภาษาไทย')
    check("default-src 'self'" in headers.get('Content-Security-Policy', ''), 'มี Content-Security-Policy')
    dev = original_settings.get('developer_name', '')
    check(dev != '' and 'พัฒนาระบบโดย' in html and dev in html and 'r=privacy' in html and 'id="cookieNotice"' in html, 'footer แสดงผู้พัฒนาระบบ ลิงก์ PDPA และแถบแจ้งคุกกี้')
    version = re.search(r"define\('APP_VERSION', '([0-9.]+)'\)", open(os.path.join(ROOT, 'app', 'version.php'), encoding='utf-8').read()).group(1)
    check(('เวอร์ชัน ' + version) in html and ('© ' + original_settings['copyright_start_year']) in html and 'All rights reserved.' in html,
          'footer แสดงเวอร์ชันระบบและข้อความลิขสิทธิ์')
    code, html, _, _ = guest.get('privacy')
    check(code == 200 and 'พระราชบัญญัติคุ้มครองข้อมูลส่วนบุคคล' in html and 'PROMPTHUB_SID' in html and dev in html, 'หน้านโยบายความเป็นส่วนตัวเปิดได้และมีรายละเอียดคุกกี้')
    cookie_header = headers.get('Set-Cookie', '')
    check('HttpOnly' in cookie_header and 'SameSite=Lax' in cookie_header, 'Session cookie เป็น HttpOnly และ SameSite=Lax')

    # ---------- สมัครสมาชิก ----------
    reg = 'reg_' + SUFFIX
    code, html, url, _ = guest.post('register.submit', {'username': reg, 'email': reg + '@example.invalid', 'display_name': 'ผู้ใช้ทดสอบภาษาไทย', 'password': PW, 'password_confirm': PW, 'accept_privacy': '1'})
    check(code == 200 and 'r=login' in url and scalar('SELECT COUNT(*) FROM users WHERE username = ?', reg) == 1, 'สมัครสมาชิกสำเร็จ')
    check(scalar('SELECT display_name FROM users WHERE username = ?', reg) == 'ผู้ใช้ทดสอบภาษาไทย', 'ชื่อภาษาไทยในฐานข้อมูลไม่ผิดเพี้ยน')
    check(scalar('SELECT privacy_accepted_at IS NOT NULL FROM users WHERE username = ?', reg) == 1, 'บันทึกเวลาที่ยอมรับนโยบายความเป็นส่วนตัว')
    noconsent = 'nc_' + SUFFIX
    code, html, _, _ = Client().post('register.submit', {'username': noconsent, 'email': noconsent + '@example.invalid', 'display_name': 'ไม่ยอมรับ', 'password': PW, 'password_confirm': PW})
    check(code == 422 and 'นโยบายความเป็นส่วนตัว' in html and scalar('SELECT COUNT(*) FROM users WHERE username = ?', noconsent) == 0, 'สมัครโดยไม่ยอมรับนโยบายความเป็นส่วนตัวไม่ได้')
    check(scalar('SELECT password_hash FROM users WHERE username = ?', reg).startswith('$2y$'), 'เก็บรหัสผ่านด้วย password_hash')
    code, html, _, _ = Client().post('register.submit', {'username': reg, 'email': 'x' + reg + '@example.invalid', 'display_name': 'ซ้ำ', 'password': PW, 'password_confirm': PW, 'accept_privacy': '1'})
    check(code == 422 and 'ชื่อผู้ใช้นี้ถูกใช้แล้ว' in html, 'ป้องกัน username ซ้ำ')
    code, html, _, _ = Client().post('register.submit', {'username': 'x' + reg, 'email': reg.upper() + '@example.invalid', 'display_name': 'ซ้ำ', 'password': PW, 'password_confirm': PW, 'accept_privacy': '1'})
    check(code == 422 and 'อีเมลนี้ถูกใช้แล้ว' in html, 'ป้องกัน email ซ้ำ (ไม่สนตัวพิมพ์)')
    code, html, _, _ = Client().post('register.submit', {'username': 'bad name!', 'email': 'nope', 'display_name': '', 'password': '123', 'password_confirm': '1'})
    check(code == 422 and html.count('invalid-feedback') >= 4, 'ตรวจรูปแบบข้อมูลสมัครสมาชิก')

    # ---------- เข้าสู่ระบบ / ออกจากระบบ ----------
    regc = Client()
    regc.get('login')
    sid_before = regc.session_id()
    code, html, url, _ = regc.login(reg, PW)
    check(code == 200 and 'r=dashboard' in url, 'สมาชิกที่สมัครใหม่เข้าสู่ระบบได้')
    check(regc.session_id() not in (None, sid_before), 'เปลี่ยน Session ID หลังเข้าสู่ระบบ')
    check(Client().login(reg, 'wrong-password')[0] == 422, 'รหัสผ่านผิดเข้าสู่ระบบไม่ได้')
    code, _, url, _ = regc.post('logout')
    check(code == 200 and regc.get('dashboard')[0] == 401, 'ออกจากระบบแล้วเข้าพื้นที่สมาชิกไม่ได้')

    for c, key in [(member, 'member'), (other, 'other'), (admin, 'admin')]:
        check(c.login(fx[key]['username'], PW)[0] == 200, 'เข้าสู่ระบบบัญชีทดสอบ ' + key)

    # ---------- สิทธิ์หลังบ้าน ----------
    for r in ['admin', 'admin.users', 'admin.categories', 'admin.tags', 'admin.tools', 'admin.prompts', 'admin.settings', 'admin.logs']:
        check(member.get(r)[0] == 403 and guest.get(r)[0] == 401, 'สมาชิก/ผู้เยี่ยมชมเข้า ' + r + ' ไม่ได้')
    check(member.post('admin.user.action', {'id': fx['other']['id'], 'op': 'suspend'})[0] == 403, 'สมาชิกส่งคำสั่งหลังบ้านไม่ได้')

    # ---------- สร้าง Prompt ส่วนตัว + XSS + ภาษาไทยหลายบรรทัด ----------
    title = 'ทดสอบ Prompt ' + SUFFIX
    code, html, url, _ = member.post('my.prompt.save', prompt_payload(title))
    pid = int(re.search(r'[?&]id=(\d+)', url).group(1))
    row = sql('SELECT * FROM prompts WHERE id = ?', pid)[0]
    check(code == 200 and row['status'] == 'draft' and row['visibility'] == 'private', 'สร้าง Prompt ส่วนตัวเป็นฉบับร่าง')
    check(row['content'] == prompt_payload(title)['content'], 'เนื้อหาภาษาไทยและบรรทัดเดิมถูกเก็บครบ')
    check(scalar('SELECT COUNT(*) FROM prompt_tags WHERE prompt_id = ?', pid) == 2 and scalar('SELECT COUNT(*) FROM prompt_ai_tools WHERE prompt_id = ?', pid) == 1, 'บันทึกแท็กและเครื่องมือ AI พร้อม Prompt')
    check('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;' in html and '<script>alert(' not in html and '<b>ตัวอย่าง</b>' not in html, 'HTML/JavaScript ใน Prompt แสดงเป็นข้อความ ไม่ทำงาน')

    # ---------- การเข้าถึง Prompt ส่วนตัว ----------
    check(guest.get('prompt', id=pid)[0] == 404, 'ผู้เยี่ยมชมเปิด URL Prompt ส่วนตัวไม่ได้')
    check(other.get('prompt', id=pid)[0] == 404, 'สมาชิกอื่นเปิด Prompt ส่วนตัวไม่ได้')
    check(other.get('my.prompt.form', id=pid)[0] == 404, 'สมาชิกอื่นเปิดฟอร์มแก้ไข Prompt ส่วนตัวไม่ได้')
    check(other.post('my.prompt.save', prompt_payload('แฮ็ก', id=str(pid)))[0] == 404 and scalar('SELECT title FROM prompts WHERE id = ?', pid) == title, 'สมาชิกอื่นแก้ไข Prompt ส่วนตัวไม่ได้')
    check(other.post('my.prompt.delete', {'id': pid})[0] == 404 and scalar('SELECT COUNT(*) FROM prompts WHERE id = ?', pid) == 1, 'สมาชิกอื่นลบ Prompt ส่วนตัวไม่ได้')
    code, body, _, _ = other.post('prompt.copy', {'id': pid}, headers={'Accept': 'application/json'})
    check(code == 404 and scalar('SELECT copy_count FROM prompts WHERE id = ?', pid) == 0, 'endpoint คัดลอกไม่เปิดเผย Prompt ส่วนตัวของผู้อื่น')
    check(admin.get('prompt', id=pid)[0] == 200, 'ผู้ดูแลเปิด Prompt ส่วนตัวได้')

    # ---------- CSRF และ method ----------
    code, _, _, _ = member.post('my.prompt.delete', {'id': pid}, csrf=False)
    check(code == 403 and scalar('SELECT COUNT(*) FROM prompts WHERE id = ?', pid) == 1, 'ปฏิเสธคำขอลบที่ไม่มี CSRF Token')
    code, _, _, _ = member.req('my.prompt.delete', {'id': pid, '_csrf': 'f' * 64})
    check(code == 403, 'ปฏิเสธ CSRF Token ที่ไม่ถูกต้อง')
    check(member.post('profile.update', {'display_name': 'ไม่ควรเปลี่ยน'}, csrf=False)[0] == 403, 'ปฏิเสธการแก้โปรไฟล์ที่ไม่มี CSRF')
    check(member.get('my.prompt.delete', id=pid)[0] == 405 and scalar('SELECT COUNT(*) FROM prompts WHERE id = ?', pid) == 1, 'GET ใช้ลบข้อมูลไม่ได้ (405)')
    check(guest.req('prompt.copy', {'id': pid}, headers={'Accept': 'application/json'})[0] == 403, 'endpoint คัดลอกต้องมี CSRF')

    # ---------- ส่งตรวจ → ไม่อนุมัติ → แก้ไข → อนุมัติ ----------
    member.post('my.prompt.save', prompt_payload(title, id=str(pid), visibility='public', intent='submit'))
    check(scalar('SELECT status FROM prompts WHERE id = ?', pid) == 'pending', 'ส่งสาธารณะแล้วสถานะรอตรวจสอบ')
    check(guest.get('prompt', id=pid)[0] == 404, 'ผู้เยี่ยมชมเปิด Prompt รอตรวจสอบไม่ได้')
    check(title not in guest.get('prompts', q=SUFFIX)[1], 'การค้นหาไม่แสดงรายการรอตรวจสอบ')
    check(member.post('favorite.toggle', {'id': pid, 'op': 'add'})[0] == 404, 'บันทึกรายการโปรดของ Prompt ที่ยังไม่เผยแพร่ไม่ได้')
    admin.post('admin.prompt.moderate', {'id': pid, 'decision': 'reject', 'reason': ''})
    check(scalar('SELECT status FROM prompts WHERE id = ?', pid) == 'pending', 'ไม่อนุมัติโดยไม่ระบุเหตุผลไม่ได้')
    reason = 'กรุณาเพิ่มตัวอย่างผลลัพธ์ให้ชัดเจน'
    admin.post('admin.prompt.moderate', {'id': pid, 'decision': 'reject', 'reason': reason})
    check(sql('SELECT status, rejection_reason FROM prompts WHERE id = ?', pid)[0] == {'status': 'rejected', 'rejection_reason': reason}, 'ผู้ดูแลไม่อนุมัติพร้อมเหตุผล')
    check(reason in member.get('prompt', id=pid)[1] and reason in member.get('my.prompts')[1], 'เจ้าของเห็นเหตุผลที่ไม่อนุมัติ')
    check(admin.post('admin.prompt.moderate', {'id': pid, 'decision': 'approve'})[0] == 200 and scalar('SELECT status FROM prompts WHERE id = ?', pid) == 'rejected', 'อนุมัติรายการที่ไม่ได้รอตรวจไม่ได้')
    member.post('my.prompt.save', prompt_payload(title, id=str(pid), visibility='public', intent='submit'))
    check(sql('SELECT status, rejection_reason FROM prompts WHERE id = ?', pid)[0] == {'status': 'pending', 'rejection_reason': None}, 'แก้ไขแล้วส่งตรวจใหม่ ล้างเหตุผลเดิม')
    admin.post('admin.prompt.moderate', {'id': pid, 'decision': 'approve'})
    check(scalar('SELECT status FROM prompts WHERE id = ?', pid) == 'published', 'ผู้ดูแลอนุมัติแล้วเผยแพร่')
    check(guest.get('prompt', id=pid)[0] == 200, 'ผู้เยี่ยมชมเปิด Prompt ที่เผยแพร่ได้')
    check(other.get('my.prompt.form', id=pid)[0] == 403, 'สมาชิกอื่นแก้ไข Prompt สาธารณะของผู้อื่นไม่ได้ (403)')

    # ---------- ค้นหา กรอง เรียง แบ่งหน้า ----------
    tag_id = scalar('SELECT id FROM tags WHERE name = ?', 'ทดสอบ' + SUFFIX)
    code, html, _, _ = guest.get('prompts', q=SUFFIX, category=CAT, tool=TOOL, tag=tag_id, sort='copies')
    check(code == 200 and title in html and 'พบ 1 รายการ' in html, 'ค้นหา + กรองหมวด/เครื่องมือ/แท็ก + เรียงร่วมกันได้')
    check(title in guest.get('prompts', q='ทดสอบ' + SUFFIX)[1], 'ค้นหาจากชื่อแท็กได้')
    check(title in guest.get('prompts', q='[กลุ่มเป้าหมาย]')[1], 'ค้นหาจากเนื้อหาได้')
    check(title not in guest.get('prompts', q=SUFFIX, category=CAT2)[1], 'กรองหมวดหมู่อื่นแล้วไม่พบ')
    check('ไม่พบ Prompt ที่ตรงกับการค้นหา' in guest.get('prompts', q='ไม่มีคำนี้แน่นอน' + SUFFIX)[1], 'แสดงสถานะผลค้นหาว่าง')
    literal_ok = True
    for ch in ['%', '_']:
        expected = scalar(
            "SELECT COUNT(*) FROM prompts p WHERE p.visibility = 'public' AND p.status = 'published' AND "
            "(INSTR(p.title, ?) OR INSTR(p.description, ?) OR INSTR(p.content, ?) OR EXISTS "
            "(SELECT 1 FROM prompt_tags pt JOIN tags t ON t.id = pt.tag_id WHERE pt.prompt_id = p.id AND INSTR(t.name, ?)))",
            ch, ch, ch, ch)
        literal_ok = literal_ok and ('พบ %d รายการ' % expected) in guest.get('prompts', q=ch)[1]
    total_public = scalar("SELECT COUNT(*) FROM prompts WHERE visibility = 'public' AND status = 'published'")
    check(literal_ok and ('พบ %d รายการ' % total_public) not in guest.get('prompts', q='%')[1], 'ค้นหา % และ _ เป็นตัวอักษรจริง ไม่ใช่ wildcard')
    code, html, _, _ = guest.get('prompts', q='ข้อมูลสาธิต', sort='copies', page=2)
    cards = html.count('class="card prompt-card')
    check(code == 200 and cards == 4 and 'sort=copies' in html and 'q=%E0%B8%82' in html, 'แบ่งหน้า 12 รายการ หน้า 2 และลิงก์รักษาค่าค้นหา/เรียง')
    _, html, _, _ = guest.get('prompts', q='ข้อมูลสาธิต', sort='copies')
    copies = [int(x) for x in re.findall(r'bi-clipboard-check me-1"></i>(\d+)', html)]
    check(copies == sorted(copies, reverse=True) and len(copies) == 12, 'เรียงตามจำนวนการคัดลอกถูกต้อง')
    for bad in [{'page': 'abc'}, {'page': '-1'}, {'sort': 'id;DROP TABLE users'}, {'per_page': '100000'}, {'category': '1 OR 1=1'}, {'page': '999999'}]:
        check(guest.get('prompts', **bad)[0] == 200, 'พารามิเตอร์ผิดรูปแบบใช้ค่าเริ่มต้นอย่างปลอดภัย ' + json.dumps(bad))
    check(scalar('SELECT COUNT(*) FROM users') >= 4, 'ตาราง users ยังอยู่หลังลองใส่ SQL ในพารามิเตอร์')
    check(title in guest.get('category', id=CAT)[1], 'หน้ารายการตามหมวดหมู่แสดง Prompt')

    # ---------- คัดลอกและเข้าชม ----------
    code, body, _, _ = guest.post('prompt.copy', {'id': pid}, headers={'Accept': 'application/json'})
    check(code == 401 and scalar('SELECT copy_count FROM prompts WHERE id = ?', pid) == 0, 'ผู้เยี่ยมชมคัดลอก Prompt ไม่ได้ (เฉพาะสมาชิก)')
    guest_html = guest.get('prompt', id=pid)[1]
    check('prompt-locked' in guest_html and 'copyButton' not in guest_html and '[กลุ่มเป้าหมาย]' not in guest_html, 'ผู้เยี่ยมชมเห็นเฉพาะตัวอย่างบางส่วน ไม่มีปุ่มคัดลอก')
    member_html = other.get('prompt', id=pid)[1]
    check('copyButton' in member_html and '[กลุ่มเป้าหมาย]' in member_html, 'สมาชิกเห็น Prompt ฉบับเต็มและปุ่มคัดลอก')
    code, body, _, _ = other.post('prompt.copy', {'id': pid}, headers={'Accept': 'application/json'})
    check(code == 200 and json.loads(body)['copy_count'] == 1, 'สมาชิกคัดลอกได้ endpoint เพิ่มสถิติและตอบ JSON')
    check(scalar('SELECT view_count FROM prompts WHERE id = ?', pid) >= 1, 'นับจำนวนเข้าชม')

    # ---------- รายการโปรด ----------
    member.post('favorite.toggle', {'id': pid, 'op': 'add'})
    member.post('favorite.toggle', {'id': pid, 'op': 'add'})
    check(scalar('SELECT COUNT(*) FROM favorites WHERE user_id = ? AND prompt_id = ?', fx['member']['id'], pid) == 1, 'เพิ่มรายการโปรดซ้ำไม่ได้')
    check(title in member.get('favorites')[1], 'หน้ารายการโปรดแสดง Prompt')
    check(guest.post('favorite.toggle', {'id': pid, 'op': 'add'})[0] == 401, 'ผู้เยี่ยมชมบันทึกรายการโปรดไม่ได้')

    # ---------- แก้ไข Prompt ที่เผยแพร่แล้ว ----------
    member.post('my.prompt.save', prompt_payload(title, id=str(pid), visibility='public', intent='draft'))
    check(scalar('SELECT status FROM prompts WHERE id = ?', pid) == 'pending', 'แก้ไข Prompt ที่เผยแพร่แล้วกลับเป็นรอตรวจสอบ')
    check(guest.get('prompt', id=pid)[0] == 404 and title not in guest.get('prompts', q=SUFFIX)[1], 'หยุดแสดงต่อสาธารณะจนกว่าจะอนุมัติใหม่')
    fav_html = member.get('favorites')[1]
    check(title not in fav_html and 'ถูกซ่อน' in fav_html, 'รายการโปรดซ่อน Prompt ที่ถูกพักการเผยแพร่')
    admin.post('admin.prompt.moderate', {'id': pid, 'decision': 'approve'})
    admin.post('my.prompt.save', prompt_payload(title + ' (admin)', id=str(pid), visibility='public', intent='draft'))
    check(scalar('SELECT status FROM prompts WHERE id = ?', pid) == 'published', 'ผู้ดูแลแก้ไขรายการที่เผยแพร่แล้วคงสถานะเผยแพร่')

    # ---------- โปรไฟล์และรหัสผ่าน ----------
    mu, me = fx['member']['username'], fx['member']['username'] + '@example.invalid'
    check(member.post('profile.update', {'display_name': 'ชื่อใหม่ภาษาไทย', 'username': mu, 'email': me})[0] == 200
          and scalar('SELECT display_name FROM users WHERE id = ?', fx['member']['id']) == 'ชื่อใหม่ภาษาไทย', 'แก้ไขชื่อที่แสดง (ไม่ต้องยืนยันรหัสผ่าน)')
    prof = member.get('profile')[1]
    check('id="username"' in prof and 'id="email"' in prof and ('value="%s"' % mu) in prof, 'หน้าโปรไฟล์มีช่องแก้ไขชื่อผู้ใช้และอีเมล')
    new_u, new_e = 'renamed_' + SUFFIX, 'Renamed_' + SUFFIX + '@Example.invalid'
    code, html, *_ = member.post('profile.update', {'display_name': 'ชื่อใหม่ภาษาไทย', 'username': new_u, 'email': new_e})
    check(code == 422 and 'กรอกรหัสผ่านปัจจุบัน' in html and scalar('SELECT username FROM users WHERE id = ?', fx['member']['id']) == mu,
          'เปลี่ยนชื่อผู้ใช้/อีเมลต้องยืนยันรหัสผ่าน (ไม่กรอก → 422 ข้อมูลไม่เปลี่ยน)')
    check(member.post('profile.update', {'display_name': 'x', 'username': new_u, 'email': new_e, 'profile_password': 'wrong-password'})[0] == 422
          and scalar('SELECT username FROM users WHERE id = ?', fx['member']['id']) == mu, 'รหัสผ่านผิด → ไม่เปลี่ยนชื่อผู้ใช้/อีเมล')
    taken_u = fx['other']['username']
    code, html, *_ = member.post('profile.update', {'display_name': 'x', 'username': taken_u, 'email': me, 'profile_password': PW})
    check(code == 422 and 'ชื่อผู้ใช้นี้มีผู้ใช้แล้ว' in html, 'ชื่อผู้ใช้ซ้ำกับบัญชีอื่นไม่ได้')
    code, html, *_ = member.post('profile.update', {'display_name': 'x', 'username': mu, 'email': taken_u.upper() + '@example.invalid', 'profile_password': PW})
    check(code == 422 and 'อีเมลนี้ใช้กับบัญชีอื่นแล้ว' in html, 'อีเมลซ้ำกับบัญชีอื่นไม่ได้ (ไม่สนตัวพิมพ์)')
    check(member.post('profile.update', {'display_name': 'x', 'username': 'ab', 'email': 'not-mail', 'profile_password': PW})[0] == 422, 'ตรวจรูปแบบชื่อผู้ใช้และอีเมล')
    code, html, *_ = member.post('profile.update', {'display_name': 'ชื่อใหม่ภาษาไทย', 'username': new_u, 'email': new_e, 'profile_password': PW})
    row = sql('SELECT username, email FROM users WHERE id = ?', fx['member']['id'])[0]
    check(code == 200 and row['username'] == new_u and row['email'] == new_e.lower() and 'บันทึกชื่อผู้ใช้ อีเมลแล้ว' in html,
          'เปลี่ยนชื่อผู้ใช้และอีเมลสำเร็จ (อีเมลเก็บตัวพิมพ์เล็ก)')
    relog = Client()
    check('r=dashboard' in relog.post('login.submit', {'login': new_u, 'password': PW})[2] and Client().post('login.submit', {'login': mu, 'password': PW})[0] == 422,
          'เข้าสู่ระบบด้วยชื่อผู้ใช้ใหม่ได้ ชื่อเดิมใช้ไม่ได้')
    check('r=dashboard' in Client().post('login.submit', {'login': new_e.lower(), 'password': PW})[2], 'เข้าสู่ระบบด้วยอีเมลใหม่ได้')
    check(member.get('dashboard')[0] == 200, 'session เดิมยังใช้งานได้หลังเปลี่ยนชื่อผู้ใช้')
    member.post('profile.update', {'display_name': 'ชื่อใหม่ภาษาไทย', 'username': mu, 'email': me, 'profile_password': PW})
    check(scalar('SELECT username FROM users WHERE id = ?', fx['member']['id']) == mu, 'เปลี่ยนชื่อผู้ใช้กลับเป็นเดิมได้')
    second = Client()
    second.login(fx['member']['username'], PW)
    new_pw = PW + 'X'
    check(member.post('password.update', {'current_password': 'wrong', 'new_password': new_pw, 'new_password_confirm': new_pw})[0] == 422, 'เปลี่ยนรหัสผ่านต้องยืนยันรหัสผ่านเดิม')
    check(member.post('password.update', {'current_password': PW, 'new_password': new_pw, 'new_password_confirm': new_pw})[0] == 200, 'เปลี่ยนรหัสผ่านสำเร็จ')
    check(member.get('dashboard')[0] == 200 and second.get('dashboard')[0] == 401, 'session อื่นหมดสิทธิ์หลังเปลี่ยนรหัสผ่าน')
    check(Client().login(fx['member']['username'], new_pw)[0] == 200, 'เข้าสู่ระบบด้วยรหัสผ่านใหม่ได้')

    # ---------- จัดการสมาชิก ----------
    oid = fx['other']['id']
    admin.post('admin.user.action', {'id': oid, 'op': 'suspend'})
    check(scalar('SELECT status FROM users WHERE id = ?', oid) == 'suspended', 'ผู้ดูแลระงับสมาชิก')
    check(other.get('dashboard')[0] == 401, 'session เดิมของสมาชิกที่ถูกระงับใช้ไม่ได้ทันที')
    code, html, _, _ = Client().login(fx['other']['username'], PW)
    check(code == 422 and 'ถูกระงับ' in html, 'บัญชีที่ถูกระงับเข้าสู่ระบบไม่ได้')
    admin.post('admin.user.action', {'id': oid, 'op': 'activate'})
    check(other.login(fx['other']['username'], PW)[0] == 200, 'เปิดใช้งานแล้วเข้าสู่ระบบได้')
    aid = fx['admin']['id']
    for op in ['suspend', 'make_member', 'delete']:
        admin.post('admin.user.action', {'id': aid, 'op': op})
        check(sql('SELECT role, status FROM users WHERE id = ?', aid) == [{'role': 'admin', 'status': 'active'}], 'ห้ามผู้ดูแล ' + op + ' บัญชีที่กำลังใช้งาน')
    admin.post('admin.user.action', {'id': oid, 'op': 'make_admin'})
    check(other.get('admin')[0] == 200, 'เลื่อนสมาชิกเป็นผู้ดูแลได้')
    admin.post('admin.user.action', {'id': oid, 'op': 'make_member'})
    check(other.get('admin')[0] == 403, 'ลดสิทธิ์แล้วเข้าหลังบ้านไม่ได้ทันที')
    _, html, _, _ = admin.get('admin.users', q=SUFFIX)
    check(fx['member']['username'] in html and 'พบ 4 บัญชี' in html, 'ค้นหาสมาชิกในหลังบ้าน')

    # ---------- หมวดหมู่ ----------
    catname = 'หมวดทดสอบ ' + SUFFIX
    admin.post('admin.category.save', {'name': catname, 'description': 'ทดสอบ', 'icon': 'star', 'sort_order': '999', 'is_active': '1'})
    cid = scalar('SELECT id FROM categories WHERE name = ?', catname)
    check(cid is not None, 'ผู้ดูแลเพิ่มหมวดหมู่')
    pid2_url = member.post('my.prompt.save', prompt_payload('ในหมวดทดสอบ ' + SUFFIX, category_id=str(cid)))[2]
    pid2 = int(re.search(r'[?&]id=(\d+)', pid2_url).group(1))
    admin.post('admin.category.save', {'id': cid, 'name': catname, 'description': 'ทดสอบ', 'icon': 'star', 'sort_order': '999'})
    check(scalar('SELECT is_active FROM categories WHERE id = ?', cid) == 0, 'ปิดใช้งานหมวดหมู่')
    check('<option value="%d"' % cid not in member.get('my.prompt.form')[1], 'หมวดหมู่ที่ปิดไม่อยู่ในตัวเลือกเพิ่ม Prompt ใหม่')
    check('<option value="%d"' % cid in member.get('my.prompt.form', id=pid2)[1], 'Prompt เดิมในหมวดที่ปิดยังแก้ไขได้และคงหมวดเดิม')
    check(member.post('my.prompt.save', prompt_payload('หมวดปิด', category_id=str(cid)))[0] == 422, 'เซิร์ฟเวอร์ปฏิเสธหมวดหมู่ที่ปิดสำหรับ Prompt ใหม่')
    check(member.get('prompt', id=pid2)[0] == 200, 'Prompt เดิมในหมวดที่ปิดยังแสดงได้')
    admin.post('admin.category.delete', {'id': cid})
    check(scalar('SELECT COUNT(*) FROM categories WHERE id = ?', cid) == 1, 'ลบหมวดหมู่ที่มี Prompt โดยไม่ย้ายไม่ได้')
    admin.post('admin.category.delete', {'id': cid, 'move_to': CAT})
    check(scalar('SELECT COUNT(*) FROM categories WHERE id = ?', cid) == 0 and scalar('SELECT category_id FROM prompts WHERE id = ?', pid2) == CAT, 'ย้าย Prompt แล้วลบหมวดหมู่')

    # ---------- แท็กและเครื่องมือ AI ----------
    tname = 'แท็กทดสอบ' + SUFFIX
    admin.post('admin.tag.save', {'name': tname})
    tid = scalar('SELECT id FROM tags WHERE name = ?', tname)
    admin.post('admin.tag.save', {'id': tid, 'name': tname + 'x'})
    check(scalar('SELECT name FROM tags WHERE id = ?', tid) == tname + 'x', 'เพิ่มและแก้ไขแท็ก')
    admin.post('admin.tag.save', {'name': tname + 'x'})
    check(scalar('SELECT COUNT(*) FROM tags WHERE name = ?', tname + 'x') == 1, 'ป้องกันชื่อแท็กซ้ำ')
    admin.post('admin.tag.delete', {'id': tid})
    check(scalar('SELECT COUNT(*) FROM tags WHERE id = ?', tid) == 0, 'ลบแท็ก')
    toolname = 'AI ทดสอบ ' + SUFFIX
    admin.post('admin.tool.save', {'name': toolname, 'sort_order': '5', 'is_active': '1'})
    toolid = scalar('SELECT id FROM ai_tools WHERE name = ?', toolname)
    check('id="tool%d"' % toolid in member.get('my.prompt.form')[1], 'เครื่องมือ AI ที่เปิดอยู่ในแบบฟอร์ม')
    admin.post('admin.tool.save', {'id': toolid, 'name': toolname, 'sort_order': '5'})
    check('id="tool%d"' % toolid not in member.get('my.prompt.form')[1], 'เครื่องมือ AI ที่ปิดไม่อยู่ในแบบฟอร์ม')
    check(member.post('my.prompt.save', prompt_payload('เครื่องมือปิด', **{'ai_tools[]': [str(toolid)]}))[0] == 422, 'เซิร์ฟเวอร์ปฏิเสธเครื่องมือ AI ที่ปิด')
    admin.post('admin.tool.delete', {'id': toolid})
    check(scalar('SELECT COUNT(*) FROM ai_tools WHERE id = ?', toolid) == 0, 'ลบเครื่องมือ AI')

    # ---------- ตั้งค่าเว็บไซต์และบันทึกกิจกรรม ----------
    changed = dict(original_settings, site_name='ทดสอบชื่อ ' + SUFFIX, developer_name='ผู้พัฒนาทดสอบ ' + SUFFIX,
                   privacy_controller='หน่วยงานทดสอบ ' + SUFFIX, privacy_contact='ติดต่อทดสอบ ' + SUFFIX,
                   copyright_owner='เจ้าของทดสอบ ' + SUFFIX, copyright_start_year='2020')
    check(admin.post('admin.settings.save', dict(changed, copyright_start_year='20x5'))[0] == 422, 'ปีลิขสิทธิ์ต้องเป็นตัวเลข ค.ศ.')
    # URL เว็บไซต์: ปฏิเสธรูปแบบอันตราย/ไม่ถูกต้อง ค่าเดิมไม่เปลี่ยน
    bad_urls = ['javascript:alert(1)', 'ftp://files.example.ac.th', 'https://x.example.ac.th/?a=1', 'https://u:p@x.example.ac.th',
                'https://x.example.ac.th/#top', 'prompthub.example.ac.th', 'https://x.example.ac.th/index.php']
    codes = [admin.post('admin.settings.save', dict(changed, site_url=u)) for u in bad_urls]
    check(all(c[0] == 422 and 'URL เว็บไซต์ต้องขึ้นต้นด้วย' in c[1] for c in codes) and scalar("SELECT setting_value FROM site_settings WHERE setting_key = 'site_url'") == original_settings['site_url'],
          'ปฏิเสธ URL เว็บไซต์ที่ไม่ใช่ http(s) หรือมี ? # ชื่อผู้ใช้ (%d รูปแบบ)' % len(bad_urls))
    changed['site_url'] = 'https://Hub-%s.Example.ac.th/prompt/' % SUFFIX
    site = 'https://hub-%s.example.ac.th/prompt' % SUFFIX
    admin.post('admin.settings.save', changed)
    this_year = datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=7))).year
    check(('© 2020–%d เจ้าของทดสอบ %s. All rights reserved.' % (this_year, SUFFIX)) in guest.get('home')[1], 'แก้เจ้าของลิขสิทธิ์และปีเริ่ม แสดงเป็นช่วงปี')
    home_html, privacy_html = guest.get('home')[1], guest.get('privacy')[1]
    check(scalar("SELECT setting_value FROM site_settings WHERE setting_key = 'site_url'") == site, 'บันทึก URL เว็บไซต์ (ตัวพิมพ์เล็กและตัด / ท้าย)')
    check(('<link rel="canonical" href="%s/">' % site) in home_html and ('<meta property="og:url" content="%s/">' % site) in home_html,
          'หน้าแรกมีลิงก์ canonical / og:url ตาม URL เว็บไซต์')
    pub_id = scalar("SELECT id FROM prompts WHERE visibility = 'public' AND status = 'published' ORDER BY id LIMIT 1")
    pub_html = guest.get('prompt', id=pub_id)[1]
    check(('href="%s/index.php?r=prompt&amp;id=%d"' % (site, pub_id)) in pub_html and 'og:type" content="article"' in pub_html,
          'หน้า Prompt สาธารณะมีลิงก์แชร์แบบเต็มตาม URL เว็บไซต์')
    check('rel="canonical"' not in admin.get('admin')[1] and 'rel="canonical"' not in member.get('dashboard')[1], 'หน้าหลังบ้าน/สมาชิกไม่มีลิงก์แชร์')
    settings_html = admin.get('admin.settings')[1]
    check(('%s/google_callback.php' % site) in settings_html, 'หน้าตั้งค่าแสดง Google redirect URI ตาม URL ใหม่')
    admin.post('admin.settings.save', dict(changed, site_url='http://hub-%s.example.ac.th' % SUFFIX))
    check('siteUrlHttpWarning' in admin.get('admin.settings')[1], 'เตือนเมื่อ URL จริงไม่ใช้ https')
    admin.post('admin.settings.save', dict(changed, site_url=''))
    check(('<link rel="canonical" href="%s/">' % BASE.rsplit('/index.php', 1)[0]) in guest.get('home')[1], 'เว้นว่าง: กลับไปใช้ app_url + base_url ในไฟล์ตั้งค่า')
    admin.post('admin.settings.save', changed)
    check('ทดสอบชื่อ ' + SUFFIX in home_html and 'ผู้พัฒนาทดสอบ ' + SUFFIX in home_html, 'ตั้งค่าชื่อเว็บไซต์และผู้พัฒนาแล้วแสดงหน้าบ้าน')
    check('หน่วยงานทดสอบ ' + SUFFIX in privacy_html and 'ติดต่อทดสอบ ' + SUFFIX in privacy_html, 'ตั้งค่าผู้ควบคุมข้อมูลและช่องทางติดต่อแสดงในหน้า PDPA')
    admin.post('admin.settings.save', original_settings)
    restored = {r['setting_key']: r['setting_value'] for r in sql('SELECT setting_key, setting_value FROM site_settings')}
    check(restored == original_settings, 'คืนค่าการตั้งค่าเดิมครบทุกรายการ')
    logs_html = admin.get('admin.logs')[1]
    actions = {r['action'] for r in sql('SELECT DISTINCT action FROM admin_activity_logs WHERE admin_id = ?', aid)}
    check({'prompt.approve', 'prompt.reject', 'user.suspend', 'category.delete', 'settings.update'} <= actions and 'อนุมัติ Prompt' in logs_html, 'บันทึกกิจกรรมผู้ดูแลครบและแสดงในหลังบ้าน')

    # ---------- หน้าหลังบ้านทั้งหมด ----------
    for r in ['admin', 'admin.users', 'admin.categories', 'admin.tags', 'admin.tools', 'admin.prompts', 'admin.settings', 'admin.logs']:
        check(admin.get(r)[0] == 200, 'หน้า ' + r + ' เปิดได้')
    for r in ['dashboard', 'my.prompts', 'my.prompt.form', 'favorites', 'profile']:
        check(member.get(r)[0] == 200, 'หน้าสมาชิก ' + r + ' เปิดได้')

    # ---------- ลบ Prompt และ cascade ----------
    check(member.post('my.prompt.delete', {'id': pid})[0] == 200 and scalar('SELECT COUNT(*) FROM prompts WHERE id = ?', pid) == 0, 'เจ้าของลบ Prompt ของตนเอง')
    check(scalar('SELECT COUNT(*) FROM favorites WHERE prompt_id = ?', pid) == 0 and scalar('SELECT COUNT(*) FROM prompt_tags WHERE prompt_id = ?', pid) == 0, 'ลบ Prompt แล้วลบรายการโปรดและแท็กเชื่อมตาม')
    admin.post('admin.user.action', {'id': fx['member']['id'], 'op': 'delete'})
    check(scalar('SELECT COUNT(*) FROM prompts WHERE id = ?', pid2) == 0, 'ลบสมาชิกแล้วลบ Prompt ของสมาชิกนั้นตาม')

    # ---------- สร้างผู้ดูแลผ่าน CLI ----------
    cli = 'cli_' + SUFFIX
    r = php('bin/create-admin.php', stdin='\n'.join([cli, cli + '@example.invalid', 'ผู้ดูแล CLI', PW, PW]) + '\n')
    check(r.returncode == 0 and scalar('SELECT role FROM users WHERE username = ?', cli) == 'admin', 'สร้างผู้ดูแลผ่าน CLI ด้วยรหัสผ่านที่กำหนดเอง')
    r = php('bin/create-admin.php', stdin='\n'.join(['cli2_' + SUFFIX, 'cli2_' + SUFFIX + '@example.invalid', 'สั้น', 'short', 'short']) + '\n')
    check(r.returncode == 1 and scalar('SELECT COUNT(*) FROM users WHERE username = ?', 'cli2_' + SUFFIX) == 0, 'CLI ปฏิเสธรหัสผ่านผู้ดูแลที่สั้นเกินไป')
    cc = Client()
    check(cc.login(cli, PW)[0] == 200 and cc.get('admin')[0] == 200, 'ผู้ดูแลจาก CLI เข้าหลังบ้านได้')

    # ---------- จำกัดความถี่การเข้าสู่ระบบ ----------
    attacker = Client()
    codes = [attacker.login('nobody_' + SUFFIX, 'wrong-password')[0] for _ in range(6)]
    check(codes[:5] == [422] * 5 and codes[5] == 429, 'จำกัดความถี่การเข้าสู่ระบบที่ล้มเหลว (ครั้งที่ 6 ได้ 429)')

    print('\nTOTAL', len(passed), 'passed')
finally:
    print(php('tests/fixtures.php', 'cleanup', SUFFIX).stdout)
