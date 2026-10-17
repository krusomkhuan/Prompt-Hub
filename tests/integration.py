"""HTTP acceptance against an isolated disposable development database.
Run: python3 tests/integration.py --allow-test-data
Requires running Docker containers prompt-hub-web and prompt-hub-db.
"""
import urllib.request, urllib.parse, urllib.error, http.cookiejar, re, subprocess, json, sys
if '--allow-test-data' not in sys.argv: raise SystemExit('Use --allow-test-data only against a disposable test database')
BASE='http://127.0.0.1:8080/index.php'
checks=[]
def check(ok,name):
 if not ok: raise AssertionError(name)
 checks.append(name);print('PASS',name)
class Client:
 def __init__(self):
  self.jar=http.cookiejar.CookieJar();self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
 def req(self,route='home',data=None,**query):
  url=BASE+'?'+urllib.parse.urlencode(dict(r=route,**query));body=urllib.parse.urlencode(data,doseq=True).encode() if data is not None else None
  try:
   res=self.opener.open(url,body);return res.status,res.read().decode(),res.url
  except urllib.error.HTTPError as e:return e.code,e.read().decode(),e.url
 def token(self,route='login',**q):
  code,html,_=self.req(route,**q);m=re.search(r'(?:name="csrf" value="|data-csrf=")([a-f0-9]+)"',html)
  if not m:raise AssertionError(('token missing',route,code,html[:200]))
  return m[1]
 def post(self,route,action,**data):
  query={}
  if route=='detail':query['id']=data['id']
  token=self.token(route,**query)
  return self.req(route,dict(csrf=token,action=action,**data),**query)
 def login(self,user,pw):return self.post('login','login',login=user,password=pw)
def sql(s):
 rows=json.loads(subprocess.check_output(['docker','exec','-i','prompt-hub-web','php','tests/fixtures.php','query'],input=json.dumps({'sql':s}),text=True))
 return '\n'.join('\t'.join(str(v) for v in row) for row in rows).strip()
f=json.loads(subprocess.check_output(['docker','exec','prompt-hub-web','php','tests/fixtures.php','create'],text=True))
try:
 guest=Client();member=Client();other=Client();admin=Client()

 cli='test_cli_'+f['suffix']
 result=subprocess.run(['docker','exec','-i','prompt-hub-web','php','bin/create-admin.php'],input='\n'.join([cli,cli+'@example.invalid','ผู้ดูแลทดสอบ',f['password'],f['password']])+'\n',capture_output=True,text=True)
 check(result.returncode==0,'สร้างบัญชีผู้ดูแลผ่าน CLI ด้วยรหัสผ่านกำหนดเอง')
 cli_client=Client();check(cli_client.login(cli,f['password'])[0]==200 and cli_client.req('admin')[0]==200,'บัญชีจาก CLI เข้าหลังบ้านได้')
 sql("DELETE FROM users WHERE username='"+cli+"'")
 check(guest.req()[0]==200,'หน้าแรกพร้อมข้อมูลภาษาไทย')
 for client,role in [(member,'member'),(other,'other'),(admin,'admin')]:check(client.login(f[role]['name'],f['password'])[0]==200,'เข้าสู่ระบบ '+role)
 check(member.req('admin')[0]==403,'สมาชิกเข้าหลังบ้านไม่ได้')
 check(guest.req('dashboard')[0]==401,'ผู้เยี่ยมชมเข้าพื้นที่สมาชิกไม่ได้')
 reg='test_reg_'+f['suffix'];code,_,_=guest.post('register','register',username=reg,email=reg+'@example.invalid',display_name='ผู้ทดสอบภาษาไทย',password=f['password']);check(code==200,'สมัครสมาชิก')
 check(guest.post('register','register',username=reg,email='different_'+reg+'@example.invalid',display_name='ซ้ำ',password=f['password'])[0]==409,'ป้องกัน username ซ้ำ')
 check(guest.post('register','register',username='x'+reg,email=reg+'@example.invalid',display_name='ซ้ำ',password=f['password'])[0]==409,'ป้องกัน email ซ้ำ')
 sql("DELETE FROM users WHERE username='"+reg+"'")
 category=int(sql('SELECT id FROM categories WHERE active=1 ORDER BY id LIMIT 1'));tag=int(sql('SELECT id FROM tags ORDER BY id LIMIT 1'));tool=int(sql('SELECT id FROM ai_tools ORDER BY id LIMIT 1'))
 title='ทดสอบ_'+f['suffix'];content='<script>window.hacked=true</script>\nเขียนเกี่ยวกับ [สินค้า] ให้ [กลุ่มเป้าหมาย]'
 payload=dict(title=title,description='ทดสอบข้อมูลภาษาไทย',content=content,category_id=category,usage_example='ตัวอย่าง',output_example='ผลลัพธ์',visibility='private',submit_mode='draft',**{'tags[]':[tag],'ai_tools[]':[tool]})
 code,html,u=member.post('edit','save_prompt',**payload);check(code==200,'สมาชิกสร้าง Prompt ส่วนตัวพร้อมความสัมพันธ์')
 pid=int(re.search(r'[?&]id=(\d+)',u).group(1));check(sql(f'SELECT content FROM prompts WHERE id={pid}')==content,'เก็บภาษาไทยและบรรทัดเดิม')
 check('&lt;script&gt;' in html and '<script>window.hacked' not in html,'escape HTML และ JavaScript')
 check(guest.req('detail',id=pid)[0]==404,'ผู้เยี่ยมชมเปิด URL ส่วนตัวไม่ได้')
 check(other.req('detail',id=pid)[0]==404,'สมาชิกอื่นเปิด URL ส่วนตัวไม่ได้')
 check(other.req('edit',id=pid)[0]==404,'สมาชิกอื่นแก้ไข Prompt ส่วนตัวไม่ได้')
 tok=other.token();check(other.req('mine',dict(csrf=tok,action='delete_prompt',id=pid))[0]==404,'สมาชิกอื่นลบ Prompt ส่วนตัวไม่ได้')
 check(guest.req('home',dict(action='copy',id=pid))[0]==419,'ปฏิเสธคำขอเปลี่ยนข้อมูลที่ไม่มี CSRF')
 check(guest.req('home',dict(csrf=guest.token(),action='copy',id=pid))[0]==404,'copy endpoint ตรวจสิทธิ์ส่วนตัว')
 code,_,_=member.post('edit','save_prompt',id=pid,**dict(payload,visibility='public',submit_mode='review'));check(code==200 and sql(f'SELECT status FROM prompts WHERE id={pid}')=='pending','ส่งสาธารณะเป็นรอตรวจสอบ')
 check(guest.req('detail',id=pid)[0]==404,'ผู้เยี่ยมชมเปิด URL รอตรวจสอบไม่ได้')
 check('พบ 0 รายการ' in guest.req('browse',q=title)[1],'ค้นหาไม่แสดงรายการรอตรวจสอบ')
 check(admin.post('detail','moderate',id=pid,status='rejected',reason='โปรดเพิ่มรายละเอียด')[0]==200,'ผู้ดูแลไม่อนุมัติพร้อมเหตุผล')
 check('โปรดเพิ่มรายละเอียด' in member.req('detail',id=pid)[1],'เจ้าของเห็นเหตุผลไม่อนุมัติ')
 member.post('edit','save_prompt',id=pid,**dict(payload,visibility='public',submit_mode='review'))
 check(admin.post('detail','moderate',id=pid,status='published',reason='')[0]==200,'ผู้ดูแลอนุมัติ Prompt')
 check(guest.req('detail',id=pid)[0]==200,'ผู้เยี่ยมชมดู Prompt เผยแพร่ได้')
 check(other.req('edit',id=pid)[0]==403,'เจ้าของอื่นแก้ไข Prompt สาธารณะไม่ได้')
 check(member.post('detail','favorite',id=pid)[0]==200 and member.post('detail','favorite',id=pid)[0]==200,'เพิ่มรายการโปรดซ้ำอย่างปลอดภัย')
 check(sql(f'SELECT COUNT(*) FROM favorites WHERE user_id={f["member"]["id"]} AND prompt_id={pid}')=='1','Unique favorite ต่อคู่')
 check(title in member.req('favorites')[1],'รายการโปรดแสดงรายการเผยแพร่')
 filtered=guest.req('browse',q=title,category=category,tag=tag,tool=tool,sort='copies')[1];check(title in filtered,'ค้นหา กรองหมวด แท็ก AI และเรียงร่วมกัน')
 code,html,_=guest.req('browse',q='ข้อมูลสาธิต',sort='copies',page=2);check(code==200 and 'page=1' in html and 'q=' in html and 'sort=copies' in html,'แบ่งหน้ารักษาค่าค้นหาและเรียงลำดับ')
 check(guest.req('browse',page='abc')[0]==400 and guest.req('browse',sort='id;DROP TABLE users')[0]==400,'ตรวจพารามิเตอร์แบ่งหน้าและ allowlist sort')
 before=int(sql(f'SELECT copy_count FROM prompts WHERE id={pid}'));check(guest.post('detail','copy',id=pid)[0]==200 and int(sql(f'SELECT copy_count FROM prompts WHERE id={pid}'))==before+1,'copy endpoint เพิ่มสถิติ')
 check(member.post('edit','save_prompt',id=pid,**dict(payload,visibility='public',submit_mode='draft'))[0]==200,'แก้ไข Prompt เผยแพร่')
 check(sql(f'SELECT status FROM prompts WHERE id={pid}')=='pending','แก้ไขแล้วกลับรอตรวจสอบแม้กดบันทึกร่าง')
 check(guest.req('detail',id=pid)[0]==404 and title not in member.req('favorites')[1],'ซ่อนรายละเอียดและรายการโปรดหลังแก้ไข')
 check(member.post('detail','favorite',id=pid)[0]==404,'favorite endpoint ไม่รับรายการรอตรวจสอบ')
 check(admin.post('admin_users','user',id=f['admin']['id'],role='admin',active='0')[0]==422,'ห้ามระงับผู้ดูแลที่กำลังใช้งาน')
 check(admin.post('admin_users','user',id=f['admin']['id'],role='admin',active='1',operation='delete')[0]==422,'ห้ามลบผู้ดูแลที่กำลังใช้งาน')
 check(admin.post('admin_users','user',id=f['other']['id'],role='member',active='0')[0]==200,'ระงับสมาชิก')
 check(other.req('dashboard')[0]==401,'session เดิมของสมาชิกที่ถูกระงับหมดสิทธิ์')
 check(member.post('profile','profile',display_name='ชื่อใหม่',new_password='NewPasswordForTesting',old_password='wrong-password')[0]==422,'เปลี่ยนรหัสผ่านต้องยืนยันรหัสเดิม')
 check(member.post('profile','profile',display_name='ชื่อใหม่',new_password=f['password']+'a',old_password=f['password'])[0]==200,'เปลี่ยนโปรไฟล์และรหัสผ่าน')
 check(member.post('home','logout')[0]==200 and member.req('dashboard')[0]==401,'ออกจากระบบ')
 check(member.login(f['member']['name'],f['password']+'a')[0]==200,'เข้าสู่ระบบด้วยรหัสผ่านใหม่')
 attacker=Client()
 for i in range(5):check(attacker.login('not_existing_'+f['suffix'],'incorrect-password')[0]==422,'นับ login ล้มเหลว '+str(i+1))
 check(attacker.login('not_existing_'+f['suffix'],'incorrect-password')[0]==429,'จำกัดความถี่ login')

 catname='test_category_'+f['suffix'];tagname='test_tag_'+f['suffix'];toolname='test_tool_'+f['suffix']
 check(admin.post('admin_taxonomy','taxonomy',kind='categories',name=catname,active='on',sort_order=99,operation='save')[0]==200,'ผู้ดูแลเพิ่มหมวดหมู่')
 catid=int(sql("SELECT id FROM categories WHERE name='"+catname+"'"))
 check(admin.post('admin_taxonomy','taxonomy',id=catid,kind='categories',name=catname,sort_order=99,operation='save')[0]==200,'ปิดหมวดหมู่')
 check(f'value="{catid}"' not in member.req('edit')[1],'หมวดหมู่ปิดไม่อยู่ในตัวเลือก Prompt ใหม่')
 check(member.post('edit','save_prompt',**dict(payload,category_id=catid))[0]==422,'ฝั่ง server ปฏิเสธหมวดหมู่ปิดสำหรับ Prompt ใหม่')
 admin.post('admin_taxonomy','taxonomy',id=catid,kind='categories',name=catname,active='on',sort_order=99,operation='save')
 member.post('edit','save_prompt',id=pid,**dict(payload,category_id=catid))
 check(admin.post('admin_taxonomy','taxonomy',id=catid,kind='categories',operation='delete')[0]==422,'ป้องกันลบหมวดหมู่ที่มี Prompt โดยไม่ย้าย')
 check(admin.post('admin_taxonomy','taxonomy',id=catid,kind='categories',operation='delete',move_to=category)[0]==200 and sql(f'SELECT category_id FROM prompts WHERE id={pid}')==str(category),'ย้าย Prompt และลบหมวดหมู่ใน Transaction')
 for kind,name in [('tags',tagname),('ai_tools',toolname)]:
  check(admin.post('admin_taxonomy','taxonomy',kind=kind,name=name,operation='save')[0]==200,'เพิ่ม '+kind)
  tid=int(sql("SELECT id FROM "+kind+" WHERE name='"+name+"'"))
  check(admin.post('admin_taxonomy','taxonomy',id=tid,kind=kind,name=name+'x',operation='save')[0]==200,'แก้ไข '+kind)
  check(admin.post('admin_taxonomy','taxonomy',id=tid,kind=kind,operation='delete')[0]==200,'ลบ '+kind)
 check(admin.post('admin_settings','settings',name='Prompt Hub',slogan='ช่วยกันคิด',description='ศูนย์รวมคำสั่ง AI ที่ช่วยเปลี่ยนความคิดให้เป็นผลงาน')[0]==200,'บันทึกการตั้งค่าเว็บไซต์')
 check(admin.post('admin_users','user',id=f['other']['id'],role='member',active='1')[0]==200,'เปิดใช้งานสมาชิกอีกครั้ง')
 check(other.login(f['other']['name'],f['password'])[0]==200,'สมาชิกกลับมาเข้าสู่ระบบได้หลังเปิดใช้')
 check(admin.post('admin_users','user',id=f['other']['id'],role='member',active='1',operation='delete')[0]==200,'ผู้ดูแลลบสมาชิกอื่น')
 check(other.req('dashboard')[0]==401,'session บัญชีที่ลบใช้สิทธิ์ไม่ได้')
 check('taxonomy' in admin.req('admin_logs')[1] and int(sql(f"SELECT COUNT(*) FROM admin_activity_logs WHERE admin_id={f['admin']['id']} AND action='moderate'"))>0,'บันทึกกิจกรรมผู้ดูแล')
 for route in ['home','browse','categories','about','dashboard','mine','edit','favorites','profile','admin','admin_users','admin_taxonomy','admin_prompts','admin_settings','admin_logs']:
  check(admin.req(route)[0]==200,'หน้า '+route+' ตอบสำเร็จ')
 check(member.post('mine','delete_prompt',id=pid)[0]==200,'เจ้าของลบ Prompt')
 check(sql(f'SELECT COUNT(*) FROM favorites WHERE prompt_id={pid}')=='0','cascade รายการโปรดหลังลบ Prompt')
 print('TOTAL',len(checks),'passed')
finally:
 subprocess.run(['docker','exec','prompt-hub-web','php','tests/fixtures.php','cleanup',f['suffix']],check=True)
