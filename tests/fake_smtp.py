"""เซิร์ฟเวอร์ SMTP จำลองสำหรับทดสอบ (ไม่ส่งอีเมลออกจริง) รองรับ EHLO, AUTH LOGIN, MAIL, RCPT, DATA, QUIT"""
import base64
import socketserver
import threading


class FakeSmtp:
    def __init__(self, port, username, password):
        self.messages = []          # [{'from':..., 'to': [...], 'data': bytes}]
        self.auth_ok = []           # ผลการ AUTH แต่ละครั้ง
        self.username = username
        self.password = password
        owner = self

        class Handler(socketserver.StreamRequestHandler):
            def send(self, line):
                self.wfile.write((line + '\r\n').encode())

            def handle(self):
                self.send('220 fake-smtp ready')
                mail_from, rcpts = None, []
                while True:
                    raw = self.rfile.readline()
                    if not raw:
                        return
                    line = raw.decode('utf-8', 'replace').rstrip('\r\n')
                    cmd = line.upper()
                    if cmd.startswith('EHLO') or cmd.startswith('HELO'):
                        self.wfile.write(b'250-fake-smtp\r\n250 AUTH LOGIN\r\n')
                    elif cmd == 'AUTH LOGIN':
                        self.send('334 VXNlcm5hbWU6')
                        user = base64.b64decode(self.rfile.readline().strip()).decode()
                        self.send('334 UGFzc3dvcmQ6')
                        pw = base64.b64decode(self.rfile.readline().strip()).decode()
                        ok = user == owner.username and pw == owner.password
                        owner.auth_ok.append(ok)
                        self.send('235 ok' if ok else '535 authentication failed')
                    elif cmd.startswith('MAIL FROM:'):
                        mail_from, rcpts = line[10:].strip('<> '), []
                        self.send('250 ok')
                    elif cmd.startswith('RCPT TO:'):
                        rcpts.append(line[8:].strip('<> '))
                        self.send('250 ok')
                    elif cmd == 'DATA':
                        self.send('354 end with .')
                        chunks = []
                        while True:
                            l = self.rfile.readline()
                            if l in (b'.\r\n', b'.\n', b''):
                                break
                            chunks.append(l)
                        owner.messages.append({'from': mail_from, 'to': rcpts, 'data': b''.join(chunks)})
                        self.send('250 queued')
                    elif cmd == 'QUIT':
                        self.send('221 bye')
                        return
                    else:
                        self.send('502 not implemented')

        socketserver.ThreadingTCPServer.allow_reuse_address = True
        self.server = socketserver.ThreadingTCPServer(('127.0.0.1', port), Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)

    def start(self):
        self.thread.start()
        return self

    def stop(self):
        self.server.shutdown()
        self.server.server_close()
