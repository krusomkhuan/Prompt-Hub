"""ถ่ายภาพหน้าจอหน้าที่ต้องเข้าสู่ระบบ สำหรับคู่มือ (ใช้ Chrome แบบ headless ผ่าน DevTools Protocol)

    python tests/capture_screens.py <username> <password-file>

อ่านรหัสผ่านจากไฟล์ (ไม่รับผ่าน command line) เข้าสู่ระบบด้วย HTTP แล้วส่ง session cookie ให้ Chrome
บันทึกภาพลง docs/screenshots/ ใช้กับเครื่องทดลองเท่านั้น
"""
import base64
import http.cookiejar
import json
import os
import re
import socket
import struct
import subprocess
import sys
import tempfile
import time
import urllib.parse
import urllib.request

BASE = os.environ.get('PROMPT_HUB_BASE', 'http://localhost/prompt_hub/public/index.php')
CHROME = os.environ.get('CHROME', r'C:\Program Files\Google\Chrome\Application\chrome.exe')
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'docs', 'screenshots')
PORT = 9333


def login(username, password):
    jar = http.cookiejar.CookieJar()
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    html = op.open(BASE + '?r=login').read().decode()
    token = re.search(r'name="_csrf" value="([a-f0-9]{64})"', html).group(1)
    res = op.open(BASE + '?r=login.submit', urllib.parse.urlencode({'_csrf': token, 'login': username, 'password': password}).encode())
    if 'r=dashboard' not in res.url:
        raise SystemExit('login failed')
    return op, jar


def post(op, route, data):
    html = op.open(BASE + '?r=dashboard').read().decode()
    data = dict(data, _csrf=re.search(r'name="_csrf" value="([a-f0-9]{64})"', html).group(1))
    op.open(BASE + '?r=' + route, urllib.parse.urlencode(data).encode())


class WS:
    """websocket client แบบย่อสำหรับคุยกับ Chrome DevTools (ข้อความ text ไม่แยก fragment)"""

    def __init__(self, url):
        u = urllib.parse.urlparse(url)
        self.sock = socket.create_connection((u.hostname, u.port))
        key = base64.b64encode(os.urandom(16)).decode()
        self.sock.sendall((f'GET {u.path} HTTP/1.1\r\nHost: {u.hostname}:{u.port}\r\nUpgrade: websocket\r\n'
                           f'Connection: Upgrade\r\nSec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n').encode())
        buf = b''
        while b'\r\n\r\n' not in buf:
            buf += self.sock.recv(4096)
        self.rest = buf.split(b'\r\n\r\n', 1)[1]
        self.next_id = 0

    def _recv(self, n):
        while len(self.rest) < n:
            chunk = self.sock.recv(1 << 20)
            if not chunk:
                raise ConnectionError('closed')
            self.rest += chunk
        out, self.rest = self.rest[:n], self.rest[n:]
        return out

    def send(self, method, params=None):
        self.next_id += 1
        payload = json.dumps({'id': self.next_id, 'method': method, 'params': params or {}}).encode()
        header = bytearray([0x81])
        n = len(payload)
        if n < 126:
            header.append(0x80 | n)
        elif n < 65536:
            header += bytes([0x80 | 126]) + struct.pack('>H', n)
        else:
            header += bytes([0x80 | 127]) + struct.pack('>Q', n)
        mask = os.urandom(4)
        self.sock.sendall(bytes(header) + mask + bytes(b ^ mask[i % 4] for i, b in enumerate(payload)))
        while True:
            b1, b2 = self._recv(2)
            n = b2 & 0x7F
            if n == 126:
                n = struct.unpack('>H', self._recv(2))[0]
            elif n == 127:
                n = struct.unpack('>Q', self._recv(8))[0]
            msg = json.loads(self._recv(n))
            if msg.get('id') == self.next_id:
                if 'error' in msg:
                    raise RuntimeError(msg['error'])
                return msg.get('result', {})


def main():
    username, pw_file = sys.argv[1], sys.argv[2]
    text = open(pw_file, encoding='utf-8-sig').read()
    password = re.search(r'ชื่อผู้ใช้: ' + re.escape(username) + r'\r?\n.*?\r?\nรหัสผ่าน: (\S+)', text).group(1)
    op, jar = login(username, password)
    sid = next(c for c in jar if c.name == 'PROMPTHUB_SID')
    post(op, 'favorite.toggle', {'id': 1, 'op': 'add'})
    post(op, 'favorite.toggle', {'id': 5, 'op': 'add'})

    profile = tempfile.mkdtemp(prefix='ph-chrome-')
    chrome = subprocess.Popen([CHROME, '--headless=new', '--disable-gpu', '--hide-scrollbars', '--no-first-run',
                               f'--remote-debugging-port={PORT}', f'--user-data-dir={profile}', 'about:blank'],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(50):
            try:
                targets = json.loads(urllib.request.urlopen(f'http://127.0.0.1:{PORT}/json').read())
                page = next(t for t in targets if t['type'] == 'page')
                break
            except Exception:
                time.sleep(0.2)
        ws = WS(page['webSocketDebuggerUrl'])
        ws.send('Network.enable')
        ws.send('Network.setCookie', {'name': sid.name, 'value': sid.value, 'domain': 'localhost',
                                      'path': sid.path, 'httpOnly': True})

        def shot(name, route, height, script=None):
            ws.send('Emulation.setDeviceMetricsOverride', {'width': 1200, 'height': height, 'deviceScaleFactor': 1, 'mobile': False})
            ws.send('Page.navigate', {'url': BASE + '?r=' + route})
            time.sleep(2.5)
            ws.send('Runtime.evaluate', {'expression': "try{localStorage.setItem('ph-cookie-notice','1')}catch(e){};"
                                         "document.getElementById('cookieNotice')?.classList.add('d-none');"
                                         "document.querySelectorAll('.alert-dismissible').forEach(a=>a.remove());"
                                         + (script or '')})
            time.sleep(0.6)
            data = ws.send('Page.captureScreenshot', {'format': 'png'})['data']
            path = os.path.join(OUT, name + '.png')
            with open(path, 'wb') as f:
                f.write(base64.b64decode(data))
            print(name, os.path.getsize(path))

        shot('07-dashboard', 'dashboard', 760)
        shot('08-my-prompts', 'my.prompts', 820)
        shot('09-prompt-form', 'my.prompt.form', 900)
        shot('10-prompt-form-publish', 'my.prompt.form', 760,
             "document.getElementById('visPublic').click();document.querySelector('.visibility-options').scrollIntoView({block:'center'});")
        shot('11-favorites', 'favorites', 820)
        shot('12-profile', 'profile', 620)
    finally:
        post(op, 'favorite.toggle', {'id': 1, 'op': 'remove'})
        post(op, 'favorite.toggle', {'id': 5, 'op': 'remove'})
        chrome.terminate()


if __name__ == '__main__':
    main()
