"""สร้างชุดไฟล์อัปเดตสำหรับอัปโหลดขึ้นโฮสต์ (เฉพาะไฟล์ที่เปลี่ยนตั้งแต่เวอร์ชันบนโฮสต์)

    python deploy/build_update.py

ผลลัพธ์: deploy/prompthub-update-1.11.0-to-1.12.0.zip (ไม่มี config/config.php, storage/ หรือไฟล์ข้อมูล)
"""
import os
import re
import zipfile

DEPLOY = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(DEPLOY)
FROM, TO = '1.11.0', re.search(r"APP_VERSION', '([0-9.]+)'", open(os.path.join(ROOT, 'app', 'version.php'), encoding='utf-8').read()).group(1)

# ไฟล์ที่เปลี่ยนตั้งแต่ 1.11.0 (เทียบกับสำเนาก่อนแก้ และตรวจแล้วว่า app.css / app.js บนเว็บจริงตรงกับ 1.11.0)
FILES = [
    'app/version.php',                              # เลขเวอร์ชัน
    'app/routes.php',                               # เส้นทาง shop.* / library / admin.shop* / admin.orders
    'app/helpers.php',                              # shop_order_badge()
    'app/Controllers/ShopController.php',           # ใหม่: หน้าร้าน คลังของฉัน ดาวน์โหลด
    'app/Controllers/ShopAdminController.php',      # ใหม่: หลังบ้านสินค้า/คำสั่งซื้อ
    'app/Services/ShopService.php',                 # ใหม่: กฎร้านค้า ไฟล์ และ QR พร้อมเพย์
    'app/Repositories/ShopRepository.php',          # ใหม่: SQL ของร้านค้า
    'app/Repositories/SettingRepository.php',       # ค่าตั้งค่า shop_*
    'app/Repositories/ActivityLogRepository.php',   # ป้ายบันทึกกิจกรรมของร้านค้า
    'views/layouts/main.php',                       # เมนู Digital Shop / คลังของฉัน
    'views/partials/admin_nav.php',                 # เมนูหลังบ้าน Digital Shop / คำสั่งซื้อ
    'views/partials/shop_card.php',                 # ใหม่
    'views/shop/index.php',                         # ใหม่
    'views/shop/show.php',                          # ใหม่
    'views/shop/checkout.php',                      # ใหม่
    'views/shop/library.php',                       # ใหม่
    'views/admin/shop.php',                         # ใหม่
    'views/admin/shop_form.php',                    # ใหม่
    'views/admin/shop_orders.php',                  # ใหม่
    'public/assets/css/app.css',                    # สไตล์ร้านค้า
    'public/assets/js/app.js',                      # QR พร้อมเพย์ หัวใจ แชร์ คัดลอกโค้ด
    'database/migrations/009_shop.sql',             # ตารางร้านค้า (นำเข้าใน phpMyAdmin)
]
# ไฟล์ .sql อื่นอาจเป็น dump ที่มีข้อมูลสมาชิก — อนุญาตเฉพาะไฟล์ migration
FORBIDDEN = re.compile(r'(^config/config\.php$|^storage/|google-drive\.json$|^(?!database/migrations/\d{3}_[a-z_]+\.sql$).*\.sql$)')

GUIDE = """อัปเดต Prompt Hub {frm} → {to} (Digital Shop) บน krusomkhuan.com
=====================================================

0) สำรองก่อนอัปเดต
   - phpMyAdmin > ฐานข้อมูลของ Prompt Hub > Export (เก็บไว้ในเครื่อง ห้ามอัปขึ้น GitHub — มีข้อมูลสมาชิก)

1) นำเข้าฐานข้อมูล (ทำก่อนอัปโหลดไฟล์)
   - phpMyAdmin > เลือกฐานข้อมูลของ Prompt Hub > Import > เลือก prompthub/database/migrations/009_shop.sql > Go
   - ได้ตารางใหม่ 4 ตาราง: shop_products, shop_orders, shop_favorites, shop_reviews (รันซ้ำได้ ไม่ทับข้อมูลเดิม)

2) อัปโหลดไฟล์ (ทับไฟล์เดิม / ไฟล์ใหม่วางตามโฟลเดอร์) — ในโฮสต์ โฟลเดอร์ระบบคือ prompthub/
{files}

   * ไม่มี config/config.php และ storage/ ในชุดนี้ — ห้ามลบหรือทับของเดิมบนโฮสต์
   * โฟลเดอร์ storage/uploads/shop ระบบสร้างให้เองเมื่ออัปโหลดไฟล์สินค้าครั้งแรก (storage/ ต้องเขียนได้เหมือนเดิม)

3) ตั้งค่าร้าน — หลังบ้าน > คำสั่งซื้อ > การ์ด "ตั้งค่าร้าน"
   - ใส่หมายเลขพร้อมเพย์ (เบอร์โทร 10 หลัก หรือเลข 13 หลัก) ชื่อบัญชี และคำแนะนำการชำระเงิน > บันทึก
   - ต้องการเตรียมสินค้าก่อนเปิดร้าน: ปิดสวิตช์ "เปิด Digital Shop ให้ทุกคนเห็น" ไว้ก่อน (ผู้ดูแลยังเห็นหน้าร้าน)

4) เพิ่มสินค้า — หลังบ้าน > Digital Shop > เพิ่มสินค้า
   - ไฟล์ดาวน์โหลดใหญ่สุดตามค่า upload_max_filesize / post_max_size ของโฮสต์ (ดูได้ที่ใต้ช่องเลือกไฟล์)

5) ตรวจ
   - ส่วนท้ายเว็บต้องขึ้น "เวอร์ชัน {to}" และแถบเมนูมี "Digital Shop"
   - https://krusomkhuan.com/prompthub/public/index.php?r=shop เปิดได้
   - https://krusomkhuan.com/prompthub/storage/uploads/shop/ ต้องได้ 403 Forbidden
   - หน้าชำระเงินของสินค้ามีราคาต้องแสดง QR พร้อมเพย์
"""

def main():
    for f in FILES:
        assert not FORBIDDEN.search(f), f
        assert os.path.isfile(os.path.join(ROOT, f)), f
    out = os.path.join(DEPLOY, 'prompthub-update-%s-to-%s.zip' % (FROM, TO))
    guide = GUIDE.format(frm=FROM, to=TO, files='\n'.join('   - prompthub/' + f for f in FILES))
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
        for f in FILES:
            z.write(os.path.join(ROOT, f), 'prompthub/' + f)
        z.writestr('อ่านก่อนอัปโหลด.txt', '﻿' + guide.replace('\n', '\r\n'))
    open(os.path.join(DEPLOY, 'อ่านก่อนอัปโหลด.txt'), 'w', encoding='utf-8-sig', newline='\r\n').write(guide)
    print(out, os.path.getsize(out), 'bytes')
    for n in zipfile.ZipFile(out).namelist():
        print('  ' + n)


if __name__ == '__main__':
    main()
