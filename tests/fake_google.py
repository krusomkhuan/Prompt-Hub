"""เซิร์ฟเวอร์ Google จำลองสำหรับทดสอบ — OAuth (authorization, token, userinfo, revoke) และ Google Drive API บางส่วน
ไม่ติดต่อ Google จริง

Drive ที่จำลอง: GET/POST /drive/v3/files, GET/PATCH /drive/v3/files/{id}, POST /drive/v3/files/{id}/permissions,
POST /upload/drive/v3/files?uploadType=multipart และ GET /d/{id}=w… (แทน lh3.googleusercontent.com)
"""
import base64
import hashlib
import http.server
import itertools
import json
import re
import threading
import time
import urllib.parse


def b64url(data):
    return base64.urlsafe_b64encode(data).rstrip(b'=').decode()


class FakeGoogle:
    def __init__(self, port, client_id, client_secret):
        self.client_id, self.client_secret = client_id, client_secret
        self.profile = {}          # ข้อมูลผู้ใช้ที่ "Google" จะคืนในรอบถัดไป
        self.codes = {}            # code -> {challenge, nonce, redirect_uri}
        self.token_requests = []
        self.auth_requests = []    # query ของ /auth ทุกครั้ง
        # ---- Drive ----
        self.drive_files = {}      # id -> {name, mimeType, parents, trashed, data, mime, permissions, capabilities}
        self.granted_scope = 'openid email https://www.googleapis.com/auth/drive'
        self.expires_in = 3600
        self.refresh_tokens = set()
        self.access_tokens = set()
        self.revoked = []
        self.fail_uploads = False
        self.drive_calls = []      # (method, path)
        # โหมดเข้มงวด (ใช้ทดสอบการวินิจฉัย): ปฏิเสธ client_id / redirect_uri ที่ไม่รู้จัก แบบเดียวกับ Google จริง
        self.strict_clients = None     # set ของ client_id ที่ยอมรับ หรือ None = รับทั้งหมด
        self.allowed_redirects = None  # set ของ redirect_uri ที่ลงทะเบียน หรือ None = รับทั้งหมด
        self._ids = itertools.count(1)
        owner = self

        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *a):
                pass

            def send_json(self, code, obj):
                body = json.dumps(obj).encode()
                self.send_response(code)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Content-Length', str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def authorized(self):
                h = self.headers.get('Authorization', '')
                return h == 'Bearer access-' + owner.profile.get('sub', '') or h[7:] in owner.access_tokens

            def body(self):
                n = int(self.headers.get('Content-Length', 0))
                return self.rfile.read(n)

            def do_GET(self):
                u = urllib.parse.urlparse(self.path)
                q = dict(urllib.parse.parse_qsl(u.query))
                if u.path == '/auth':
                    owner.auth_requests.append(q)
                    error = None
                    if owner.strict_clients is not None and q.get('client_id') not in owner.strict_clients:
                        error = 'invalid_client'
                    elif owner.allowed_redirects is not None and q.get('redirect_uri') not in owner.allowed_redirects:
                        error = 'redirect_uri_mismatch'
                    if error:
                        self.send_response(302)
                        self.send_header('Location', '/signin/oauth/error?authError=' + error)
                        self.end_headers()
                        return
                    # จำลองผู้ใช้กดยอมรับ แล้ว redirect กลับพร้อม code และ state เดิม
                    code = 'code-' + str(len(owner.codes) + 1) + '-' + str(next(owner._ids))
                    owner.codes[code] = {'challenge': q.get('code_challenge'), 'nonce': q.get('nonce'),
                                         'redirect_uri': q.get('redirect_uri'), 'client_id': q.get('client_id'), 'scope': q.get('scope', '')}
                    loc = q['redirect_uri'] + '&' + urllib.parse.urlencode({'code': code, 'state': q.get('state', '')})
                    self.send_response(302)
                    self.send_header('Location', loc)
                    self.end_headers()
                elif u.path == '/signin/oauth/error':
                    body = ('<html><body>Error 400: %s</body></html>' % q.get('authError', '')).encode()
                    self.send_response(400)
                    self.send_header('Content-Type', 'text/html')
                    self.send_header('Content-Length', str(len(body)))
                    self.end_headers()
                    self.wfile.write(body)
                elif u.path == '/userinfo':
                    if not self.authorized():
                        return self.send_json(401, {'error': 'invalid_token'})
                    self.send_json(200, owner.profile)
                elif u.path.startswith('/d/'):
                    # แทน lh3.googleusercontent.com/d/{id}=w…: ไฟล์ต้องเป็น anyone-reader และไม่อยู่ในถังขยะ
                    fid = u.path[3:].split('=')[0]
                    f = owner.drive_files.get(fid)
                    public = f and any(p.get('type') == 'anyone' for p in f['permissions'])
                    if not f or f['trashed'] or not public:
                        self.send_response(403)
                        self.end_headers()
                        return
                    self.send_response(200)
                    self.send_header('Content-Type', f['mime'])
                    self.send_header('Content-Length', str(len(f['data'])))
                    self.end_headers()
                    self.wfile.write(f['data'])
                elif u.path == '/drive/v3/files':
                    owner.drive_calls.append(('GET', u.path))
                    if not self.authorized():
                        return self.send_json(401, {'error': {'message': 'unauthorized'}})
                    name = re.search(r"name = '((?:[^'\\]|\\.)*)'", q.get('q', ''))
                    parent = re.search(r"'([A-Za-z0-9_-]+)' in parents", q.get('q', ''))
                    files = [{'id': k, 'name': v['name']} for k, v in owner.drive_files.items()
                             if not v['trashed'] and v['mimeType'] == 'application/vnd.google-apps.folder'
                             and (not name or v['name'] == name.group(1).replace("\\'", "'"))
                             and (not parent or parent.group(1) in v['parents'])]
                    self.send_json(200, {'files': files})
                elif u.path.startswith('/drive/v3/files/'):
                    owner.drive_calls.append(('GET', u.path))
                    if not self.authorized():
                        return self.send_json(401, {'error': {'message': 'unauthorized'}})
                    f = owner.drive_files.get(u.path.split('/')[4])
                    if not f:
                        return self.send_json(404, {'error': {'message': 'File not found'}})
                    self.send_json(200, {'id': u.path.split('/')[4], 'name': f['name'], 'mimeType': f['mimeType'],
                                         'capabilities': f.get('capabilities', {'canAddChildren': True})})
                else:
                    self.send_json(404, {'error': 'not_found'})

            def do_PATCH(self):
                u = urllib.parse.urlparse(self.path)
                owner.drive_calls.append(('PATCH', u.path))
                if not self.authorized():
                    return self.send_json(401, {'error': {'message': 'unauthorized'}})
                f = owner.drive_files.get(u.path.split('/')[-1])
                if not f:
                    return self.send_json(404, {'error': {'message': 'File not found'}})
                f.update({k: v for k, v in json.loads(self.body() or b'{}').items() if k in ('trashed', 'name')})
                self.send_json(200, {'id': u.path.split('/')[-1]})

            def do_POST(self):
                u = urllib.parse.urlparse(self.path)
                raw = self.body()
                if u.path == '/token':
                    form = dict(urllib.parse.parse_qsl(raw.decode()))
                    owner.token_requests.append(form)
                    if form.get('client_id') != owner.client_id or form.get('client_secret') != owner.client_secret:
                        return self.send_json(401, {'error': 'invalid_client'})
                    if form.get('grant_type') == 'refresh_token':
                        if form.get('refresh_token') not in owner.refresh_tokens:
                            return self.send_json(400, {'error': 'invalid_grant'})
                        token = 'access-r-' + str(next(owner._ids))
                        owner.access_tokens.add(token)
                        return self.send_json(200, {'access_token': token, 'expires_in': owner.expires_in, 'token_type': 'Bearer'})
                    saved = owner.codes.pop(form.get('code', ''), None)
                    verifier = form.get('code_verifier', '')
                    ok = (saved and form.get('redirect_uri') == saved['redirect_uri']
                          and b64url(hashlib.sha256(verifier.encode()).digest()) == saved['challenge'])
                    if not ok:
                        return self.send_json(400, {'error': 'invalid_grant'})
                    claims = {'iss': 'https://accounts.google.com', 'aud': owner.client_id, 'exp': int(time.time()) + 600,
                              'nonce': saved['nonce'], 'sub': owner.profile.get('sub', '')}
                    id_token = b64url(b'{"alg":"RS256"}') + '.' + b64url(json.dumps(claims).encode()) + '.sig'
                    resp = {'access_token': 'access-' + owner.profile.get('sub', ''), 'id_token': id_token,
                            'token_type': 'Bearer', 'expires_in': owner.expires_in}
                    if 'drive' in saved.get('scope', ''):
                        refresh = 'refresh-' + form['code']
                        owner.refresh_tokens.add(refresh)
                        resp.update({'refresh_token': refresh, 'scope': owner.granted_scope})
                    return self.send_json(200, resp)
                if u.path == '/revoke':
                    token = dict(urllib.parse.parse_qsl(raw.decode())).get('token', '')
                    owner.revoked.append(token)
                    owner.refresh_tokens.discard(token)
                    return self.send_json(200, {})
                owner.drive_calls.append(('POST', u.path))
                if not self.authorized():
                    return self.send_json(401, {'error': {'message': 'unauthorized'}})
                if u.path == '/upload/drive/v3/files':
                    if owner.fail_uploads:
                        return self.send_json(500, {'error': {'message': 'backend error'}})
                    boundary = re.search(r'boundary=([^;]+)', self.headers.get('Content-Type', '')).group(1).encode()
                    parts = [p for p in raw.split(b'--' + boundary) if p.strip() not in (b'', b'--')]
                    meta_part, media_part = parts[0], parts[1]
                    meta = json.loads(meta_part.split(b'\r\n\r\n', 1)[1].strip())
                    head, data = media_part.split(b'\r\n\r\n', 1)
                    mime = re.search(rb'Content-Type: ([^\r\n]+)', head).group(1).decode()
                    fid = 'drivefile' + str(next(owner._ids)).zfill(6)
                    owner.drive_files[fid] = {'name': meta['name'], 'mimeType': mime, 'parents': meta.get('parents', []), 'trashed': False,
                                              'data': data[:-2] if data.endswith(b'\r\n') else data, 'mime': mime, 'permissions': [],
                                              'description': meta.get('description', '')}
                    return self.send_json(200, {'id': fid})
                if u.path == '/drive/v3/files':
                    meta = json.loads(raw)
                    fid = 'drivefolder' + str(next(owner._ids)).zfill(6)
                    owner.drive_files[fid] = {'name': meta['name'], 'mimeType': meta['mimeType'], 'parents': meta.get('parents', []),
                                              'trashed': False, 'data': b'', 'mime': '', 'permissions': []}
                    return self.send_json(200, {'id': fid})
                m = re.fullmatch(r'/drive/v3/files/([A-Za-z0-9_-]+)/permissions', u.path)
                if m and m.group(1) in owner.drive_files:
                    owner.drive_files[m.group(1)]['permissions'].append(json.loads(raw))
                    return self.send_json(200, {'id': 'perm' + str(next(owner._ids))})
                self.send_json(404, {'error': {'message': 'not_found'}})

        self.server = http.server.ThreadingHTTPServer(('127.0.0.1', port), Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)

    def add_folder(self, folder_id, name, can_add=True):
        self.drive_files[folder_id] = {'name': name, 'mimeType': 'application/vnd.google-apps.folder', 'parents': [], 'trashed': False,
                                       'data': b'', 'mime': '', 'permissions': [{'type': 'anyone', 'role': 'reader'}],
                                       'capabilities': {'canAddChildren': can_add}}

    def start(self):
        self.thread.start()
        return self

    def stop(self):
        self.server.shutdown()
        self.server.server_close()
