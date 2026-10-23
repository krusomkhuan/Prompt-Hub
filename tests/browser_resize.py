"""ทดสอบการย่อภาพในเบราว์เซอร์จริง (Chrome headless + DevTools) แล้วส่งฟอร์มผ่าน Apache

    python tests/browser_resize.py --allow-test-data
"""
import json, os, re, subprocess, sys, tempfile, time, urllib.request
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import capture_screens as cs  # noqa

PHP = os.environ.get('PROMPT_HUB_PHP', 'C:/xampp/php/php.exe')
passed = []
if '--allow-test-data' not in sys.argv:
    raise SystemExit('ใช้ --allow-test-data เพื่อยืนยันว่ารันกับฐานข้อมูลที่สร้างข้อมูลทดสอบได้')


def check(ok, name):
    print(('PASS ' if ok else 'FAIL ') + name)
    if not ok:
        raise SystemExit(1)
    passed.append(name)


def sql(q, *p):
    r = subprocess.run([PHP, 'tests/fixtures.php', 'query'], cwd=ROOT, input=json.dumps({'sql': q, 'params': list(p)}), capture_output=True, text=True, encoding='utf-8')
    return json.loads(r.stdout)


MAKE = """
(async () => {
  const c = document.createElement('canvas'); c.width = %(w)d; c.height = %(h)d;
  const x = c.getContext('2d');
  for (let i = 0; i < 1500; i++) { x.fillStyle = `hsl(${Math.random()*360},70%%,50%%)`; x.fillRect(Math.random()*c.width, Math.random()*c.height, 120, 120); }
  const blob = await new Promise(r => c.toBlob(r, '%(type)s', 0.98));
  const input = document.getElementById('%(input)s');
  const dt = new DataTransfer(); dt.items.add(new File([blob], 'big.%(ext)s', {type: '%(type)s'}));
  input.files = dt.files;
  const before = blob.size;
  input.dispatchEvent(new Event('change', {bubbles: true}));
  const disabledDuring = [...input.form.querySelectorAll('[type=submit]')].some(b => b.disabled);
  const busy = () => [...input.form.querySelectorAll('[type=submit]')].some(b => b.disabled);
  for (let i = 0; i < 100 && busy(); i++) await new Promise(r => setTimeout(r, 100));
  await new Promise(r => setTimeout(r, 300));
  const f = input.files[0];
  const bmp = await createImageBitmap(f);
  const status = input.parentNode.querySelector('[data-resize-status]').textContent;
  return {before, after: f.size, type: f.type, w: bmp.width, h: bmp.height, status, disabledDuring,
          enabledAfter: [...input.form.querySelectorAll('[type=submit]')].every(b => !b.disabled),
          previews: document.querySelectorAll('#imagePreview img, #avatarPreviewWrap img').length};
})()
"""

fx = json.loads(subprocess.run([PHP, 'tests/fixtures.php', 'create'], cwd=ROOT, capture_output=True, text=True).stdout)
chrome = None
try:
    op, jar = cs.login(fx['member']['username'], fx['password'])
    sid = next(c for c in jar if c.name == 'PROMPTHUB_SID')
    chrome = subprocess.Popen([cs.CHROME, '--headless=new', '--disable-gpu', '--no-first-run', f'--remote-debugging-port={cs.PORT}',
                               f'--user-data-dir={tempfile.mkdtemp()}', 'about:blank'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(50):
        try:
            page = next(t for t in json.loads(urllib.request.urlopen(f'http://127.0.0.1:{cs.PORT}/json').read()) if t['type'] == 'page')
            break
        except Exception:
            time.sleep(0.2)
    ws = cs.WS(page['webSocketDebuggerUrl'])
    ws.send('Network.enable')
    ws.send('Network.setCookie', {'name': sid.name, 'value': sid.value, 'domain': 'localhost', 'path': sid.path, 'httpOnly': True})

    def go(route):
        ws.send('Page.navigate', {'url': cs.BASE + '?r=' + route})
        time.sleep(2.0)

    def js(expr):
        r = ws.send('Runtime.evaluate', {'expression': expr, 'awaitPromise': True, 'returnByValue': True})
        if 'exceptionDetails' in r:
            raise RuntimeError(r['exceptionDetails'])
        return r['result'].get('value')

    # ---------- ภาพตัวอย่างใน Prompt ----------
    go('my.prompt.form')
    cat = sql('SELECT id FROM categories WHERE allow_images = 1 AND is_active = 1 ORDER BY id LIMIT 1')[0]['id']
    js(f"document.getElementById('category_id').value = '{cat}'; document.getElementById('category_id').dispatchEvent(new Event('change'));")
    r = js(MAKE % {'w': 4000, 'h': 3000, 'type': 'image/jpeg', 'ext': 'jpg', 'input': 'images'})
    print(r)
    check(r['w'] == 1600 and r['h'] == 1200 and r['type'] == 'image/jpeg' and r['after'] < r['before'],
          'เบราว์เซอร์ย่อ JPEG 4000×3000 เป็น 1600×1200 ก่อนอัปโหลด (%.1f MB → %.2f MB)' % (r['before'] / 1048576, r['after'] / 1048576))
    check(r['disabledDuring'] and r['enabledAfter'], 'ปิดปุ่มบันทึกระหว่างย่อภาพ แล้วเปิดคืน')
    check('ปรับขนาดภาพแล้ว' in r['status'] and r['previews'] == 1, 'แสดงสถานะการย่อและภาพตัวอย่างหลังย่อ')
    js("""document.getElementById('title').value = 'ย่อภาพเบราว์เซอร์ %s';
          document.getElementById('description').value = 'ทดสอบการย่อภาพก่อนอัปโหลด';
          document.getElementById('content').value = 'ภาพ [ฉาก] โทน [สี]';
          document.querySelector('button[name=intent][value=draft]').click();""" % fx['suffix'])
    time.sleep(2.5)
    rows = sql("SELECT i.width, i.height, i.mime, i.bytes FROM prompt_images i JOIN prompts p ON p.id = i.prompt_id WHERE p.title = ?", 'ย่อภาพเบราว์เซอร์ ' + fx['suffix'])
    print(rows)
    check(len(rows) == 1 and rows[0]['width'] == 1600 and rows[0]['height'] == 1200 and rows[0]['mime'] == 'image/jpeg',
          'ส่งฟอร์มแล้วเซิร์ฟเวอร์ (ไม่มี GD) เก็บภาพขนาด 1600×1200 ที่ย่อแล้ว')

    go('my.prompt.form')
    js(f"document.getElementById('category_id').value = '{cat}'; document.getElementById('category_id').dispatchEvent(new Event('change'));")
    r = js(MAKE % {'w': 1200, 'h': 900, 'type': 'image/png', 'ext': 'png', 'input': 'images'})
    check(r['w'] == 1200 and r['type'] == 'image/png', 'PNG ที่เล็กกว่าเกณฑ์ไม่ถูกย่อหรือเปลี่ยนชนิด')
    r = js(MAKE % {'w': 3000, 'h': 1500, 'type': 'image/webp', 'ext': 'webp', 'input': 'images'})
    print(r)
    check(r['w'] == 1600 and r['h'] == 800 and r['type'] == 'image/webp', 'WebP 3000×1500 ย่อเป็น 1600×800 และยังเป็น WebP')

    # ---------- ภาพโปรไฟล์ ----------
    go('profile')
    r = js(MAKE % {'w': 1200, 'h': 800, 'type': 'image/png', 'ext': 'png', 'input': 'avatar'})
    print(r)
    check(r['w'] == 256 and r['h'] == 256 and r['previews'] == 1, 'เบราว์เซอร์ตัดภาพโปรไฟล์ 1200×800 เป็น 256×256 และแสดงตัวอย่าง')
    js("document.querySelector('form[action*=\"profile.avatar\"]').querySelector('[type=submit]').click();")
    time.sleep(2.5)
    av = sql('SELECT avatar_file FROM users WHERE id = ?', fx['member']['id'])[0]['avatar_file']
    size = subprocess.run([PHP, '-r', 'echo json_encode(getimagesize($argv[1]));', ROOT + '/storage/uploads/avatars/' + av], capture_output=True, text=True).stdout
    dims = json.loads(size)
    check(dims['0'] == 256 and dims['1'] == 256, 'เซิร์ฟเวอร์เก็บภาพโปรไฟล์ 256×256 ที่ย่อจากเบราว์เซอร์')
    print('\nTOTAL', len(passed), 'passed')
finally:
    if chrome:
        chrome.terminate()
    print(subprocess.run([PHP, 'tests/fixtures.php', 'cleanup', fx['suffix']], cwd=ROOT, capture_output=True, text=True).stdout)
