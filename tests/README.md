# การทดสอบสำหรับนักพัฒนา

ใช้ฐานข้อมูล Docker ที่ทิ้งได้เท่านั้น ห้ามใช้กับ production หรือข้อมูลสมาชิกจริง integration.py สร้างบัญชีและ Prompt ชั่วคราว แก้ไข site_settings ให้เป็นค่าเริ่มต้น และล้าง login_attempts เมื่อจบ (การทดสอบอื่นไม่ควรรันพร้อมกัน)

```sh
bash bin/dev-setup.sh
python3 tests/integration.py --allow-test-data
node tests/browser.cjs
```

HTTP tests ต้องมี Python 3 และ Docker container ชื่อ prompt-hub-web ใช้ tests/fixtures.php ผ่าน CLI เพื่อเชื่อมฐานข้อมูลด้วย config/local.php เดิม โดยไม่แสดงค่า credential ไม่ต้องใช้บัญชี root ในฐานข้อมูล

Browser tests ต้องติดตั้ง Playwright (`npm install --prefix /tmp/prompt-hub-test-tools playwright@1.56.1`) และ Chromium ตั้ง NODE_PATH ให้ชี้ node_modules ของเครื่องมือ และ CHROMIUM_PATH ให้เป็น executable ที่ติดตั้งไว้ หากเครื่องไม่มี /usr/bin/chromium เบราว์เซอร์ทดสอบบล็อก request ภายนอกทั้งหมด ทดสอบหน้าจอ 390, 768, 1440 พิกเซล และคัดลอกข้อความจริง อาจเพิ่มจำนวนคัดลอกของ Prompt สาธิตหนึ่งครั้ง ไม่แก้เนื้อหาต้นฉบับ

ไม่มี secret หรือรหัสผ่านผู้ดูแลที่ใช้ซ้ำใน test fixtures รหัสผ่านสร้างใหม่แบบสุ่มทุกรัน และลบบัญชีเมื่อจบ
