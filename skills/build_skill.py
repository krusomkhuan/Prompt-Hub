"""สร้างไฟล์สกิล Prompt Hub สำหรับ ChatGPT

    python skills/build_skill.py

ผลลัพธ์:
  skills/prompt-hub/assets/prompt-hub-source.zip  ซอร์สโค้ด (ไม่มีไฟล์ตั้งค่าจริง รหัสผ่าน log ภาพที่อัปโหลด หรือข้อมูลสำรอง)
  skills/prompt-hub-skill.zip                     โฟลเดอร์สกิล prompt-hub/ สำหรับอัปโหลดเข้า ChatGPT
"""
import os
import re
import shutil
import zipfile

SKILLS = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(SKILLS)
SKILL = os.path.join(SKILLS, 'prompt-hub')

INCLUDE_DIRS = ['app', 'views', 'public', 'database', 'bin', 'tests']
INCLUDE_FILES = ['.htaccess', '.gitignore', 'index.php', 'README.md', 'CHANGELOG.md', 'TESTING.md',
                 'config/.htaccess', 'config/config.example.php', 'docs/USER_GUIDE.md', 'docs/.htaccess',
                 'storage/.htaccess']
# โฟลเดอร์ว่างที่ระบบต้องมี (ใส่ .gitkeep)
KEEP_DIRS = ['storage/logs', 'storage/uploads/prompts', 'storage/uploads/avatars', 'storage/uploads/shop']
# ห้ามหลุดเข้า zip เด็ดขาด
FORBIDDEN = [r'^config/config\.php$', r'google-drive\.json$',r'^storage/initial-accounts\.txt$', r'^storage/backups/', r'\.log$',
             r'^storage/uploads/.+\.(jpg|png|webp)$', r'__pycache__', r'\.pyc$']
SECRET_PATTERNS = [rb'GOCSPX-[A-Za-z0-9_-]{10,}', rb'-----BEGIN [A-Z ]*PRIVATE KEY-----', '(?m)^รหัสผ่าน: \\S{8,}\\s*$'.encode('utf-8')]


def source_files():
    out = []
    for d in INCLUDE_DIRS:
        for base, dirs, files in os.walk(os.path.join(ROOT, d)):
            dirs[:] = [x for x in dirs if x != '__pycache__']
            for f in files:
                out.append(os.path.relpath(os.path.join(base, f), ROOT).replace('\\', '/'))
    out += [f for f in INCLUDE_FILES if os.path.isfile(os.path.join(ROOT, f))]
    for rel in out:
        if any(re.search(p, rel) for p in FORBIDDEN):
            raise SystemExit('ไฟล์ต้องห้ามหลุดเข้า zip: ' + rel)
        data = open(os.path.join(ROOT, rel), 'rb').read()
        for p in SECRET_PATTERNS:
            if re.search(p, data):
                raise SystemExit('พบข้อมูลที่อาจเป็นความลับใน ' + rel)
    return sorted(set(out))


def build():
    assets = os.path.join(SKILL, 'assets')
    os.makedirs(assets, exist_ok=True)
    # ให้ references ตรงกับโค้ดล่าสุดเสมอ
    shutil.copyfile(os.path.join(ROOT, 'database', 'schema.sql'), os.path.join(SKILL, 'references', 'schema.sql'))
    shutil.copyfile(os.path.join(ROOT, 'app', 'routes.php'), os.path.join(SKILL, 'references', 'routes.php'))

    src_zip = os.path.join(assets, 'prompt-hub-source.zip')
    files = source_files()
    with zipfile.ZipFile(src_zip, 'w', zipfile.ZIP_DEFLATED) as z:
        for rel in files:
            z.write(os.path.join(ROOT, rel), 'prompt_hub/' + rel)
        for d in KEEP_DIRS:
            z.writestr('prompt_hub/' + d + '/.gitkeep', '')
    print('source zip: %d files, %.0f KB' % (len(files), os.path.getsize(src_zip) / 1024))

    skill_zip = os.path.join(SKILLS, 'prompt-hub-skill.zip')
    with zipfile.ZipFile(skill_zip, 'w', zipfile.ZIP_DEFLATED) as z:
        for base, _, names in os.walk(SKILL):
            for f in names:
                full = os.path.join(base, f)
                z.write(full, os.path.relpath(full, SKILLS).replace('\\', '/'))
    with zipfile.ZipFile(skill_zip) as z:
        skill_md = [n for n in z.namelist() if n.lower().endswith('skill.md')]
        if skill_md != ['prompt-hub/SKILL.md']:
            raise SystemExit('ต้องมี SKILL.md เพียงไฟล์เดียวที่ prompt-hub/SKILL.md')
        print('skill zip:', len(z.namelist()), 'files, %.0f KB' % (os.path.getsize(skill_zip) / 1024))
        for n in z.namelist():
            print('  ' + n)


if __name__ == '__main__':
    build()
