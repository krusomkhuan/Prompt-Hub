-- Prompt Hub — ข้อมูลสาธิต (ไม่บังคับ) นำเข้าหลัง schema.sql เพียงครั้งเดียว
-- ข้อความทั้งหมดแต่งขึ้นใหม่เพื่อสาธิตระบบ
-- บัญชีผู้เขียนสาธิตถูกระงับและไม่มีรหัสผ่านที่ใช้เข้าสู่ระบบได้ (hash ไม่ใช่รูปแบบที่ password_verify ยอมรับ)

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';
START TRANSACTION;

INSERT INTO users (username, email, display_name, password_hash, role, status)
VALUES ('demo_author', 'demo_author@example.invalid', 'ทีมสาธิต Prompt Hub', '!login-disabled', 'member', 'suspended');
SET @u = LAST_INSERT_ID();

INSERT INTO tags (name) VALUES
('ข้อมูลสาธิต'), ('แผนการสอน'), ('ข้อสอบ'), ('การตลาด'), ('โซเชียลมีเดีย'), ('อีเมล'),
('สรุปประชุม'), ('หนังสือราชการ'), ('ภาพประกอบ'), ('โปสเตอร์'), ('สคริปต์วิดีโอ'), ('สตอรีบอร์ด'),
('PHP'), ('ดีบัก'), ('Excel'), ('สรุปข้อมูล'), ('วางแผน'), ('ภาษาไทย');

-- 1
INSERT INTO prompts (user_id, category_id, title, description, content, usage_example, output_example, visibility, status, copy_count, view_count, published_at, created_at, updated_at)
SELECT @u, id, 'ออกแบบแผนการสอนแบบ Active Learning',
'[ข้อมูลสาธิต] สร้างแผนการสอน 1 คาบ ที่ผู้เรียนได้ลงมือทำจริง พร้อมเกณฑ์ประเมิน',
'คุณคือครูผู้เชี่ยวชาญการจัดการเรียนรู้เชิงรุก\nช่วยออกแบบแผนการสอนเรื่อง [หัวข้อ] สำหรับผู้เรียน [ระดับชั้น] เวลา [ระยะเวลา]\n\nให้มีหัวข้อดังนี้\n1. จุดประสงค์การเรียนรู้ (ความรู้ ทักษะ เจตคติ)\n2. ขั้นนำ ขั้นสอน ขั้นสรุป พร้อมเวลาแต่ละขั้น\n3. กิจกรรมที่ผู้เรียนลงมือทำเป็นกลุ่ม\n4. สื่อและอุปกรณ์\n5. เกณฑ์การประเมินแบบรูบริก 3 ระดับ\n\nเขียนเป็นภาษาไทยที่ครูนำไปใช้ได้ทันที',
'แทน [หัวข้อ] ด้วย “วงจรไฟฟ้าอย่างง่าย” [ระดับชั้น] ด้วย “ปวช.1” และ [ระยะเวลา] ด้วย “2 ชั่วโมง”',
'ตัวอย่างผลลัพธ์ (สาธิต): จุดประสงค์ 3 ข้อ → ขั้นนำ 10 นาที ตั้งคำถามจากอุปกรณ์จริง → ขั้นสอน 80 นาที ทดลองต่อวงจรเป็นกลุ่ม → ขั้นสรุป 30 นาที นำเสนอและสะท้อนคิด → รูบริก ดี/พอใช้/ปรับปรุง',
'public', 'published', 42, 310, UTC_TIMESTAMP() - INTERVAL 20 DAY, UTC_TIMESTAMP() - INTERVAL 20 DAY, UTC_TIMESTAMP() - INTERVAL 20 DAY
FROM categories WHERE name = 'การศึกษาและการสอน';
SET @p = LAST_INSERT_ID();
INSERT INTO prompt_tags SELECT @p, id FROM tags WHERE name IN ('ข้อมูลสาธิต','แผนการสอน');
INSERT INTO prompt_ai_tools SELECT @p, id FROM ai_tools WHERE name IN ('ChatGPT','Claude','Gemini');

-- 2
INSERT INTO prompts (user_id, category_id, title, description, content, usage_example, output_example, visibility, status, copy_count, view_count, published_at, created_at, updated_at)
SELECT @u, id, 'สร้างข้อสอบปรนัยพร้อมเฉลยและเหตุผล',
'[ข้อมูลสาธิต] ออกข้อสอบตามระดับการคิด พร้อมเฉลยและคำอธิบายตัวลวง',
'สร้างข้อสอบปรนัย 4 ตัวเลือก จำนวน [จำนวนข้อ] ข้อ เรื่อง [หัวข้อ] สำหรับ [ระดับชั้น]\n- แบ่งระดับการคิด: จำ 30% เข้าใจ 40% ประยุกต์ 30%\n- ทุกข้อมีเฉลยและเหตุผลว่าทำไมตัวเลือกอื่นผิด\n- หลีกเลี่ยงตัวเลือก “ถูกทุกข้อ” และ “ผิดทุกข้อ”\n- สรุปตารางวิเคราะห์ข้อสอบตอนท้าย',
'ระบุ [จำนวนข้อ] = 10, [หัวข้อ] = “การใช้ Excel เบื้องต้น”, [ระดับชั้น] = “ปวช.2”',
'ตัวอย่างผลลัพธ์ (สาธิต): ข้อ 1 ฟังก์ชันใดใช้หาผลรวม? ก. SUM ✓ … พร้อมเหตุผลของตัวลวง และตารางระดับการคิด',
'public', 'published', 35, 260, UTC_TIMESTAMP() - INTERVAL 18 DAY, UTC_TIMESTAMP() - INTERVAL 18 DAY, UTC_TIMESTAMP() - INTERVAL 18 DAY
FROM categories WHERE name = 'การศึกษาและการสอน';
SET @p = LAST_INSERT_ID();
INSERT INTO prompt_tags SELECT @p, id FROM tags WHERE name IN ('ข้อมูลสาธิต','ข้อสอบ');
INSERT INTO prompt_ai_tools SELECT @p, id FROM ai_tools WHERE name IN ('ChatGPT','Claude');

-- 3
INSERT INTO prompts (user_id, category_id, title, description, content, usage_example, output_example, visibility, status, copy_count, view_count, published_at, created_at, updated_at)
SELECT @u, id, 'แคปชันขายสินค้าบนโซเชียล 3 แบบ',
'[ข้อมูลสาธิต] เขียนแคปชันขายของ 3 สไตล์ ให้ตรงกลุ่มเป้าหมายและน้ำเสียงที่ต้องการ',
'เขียนแคปชันโพสต์ขาย [ชื่อสินค้า] สำหรับ [กลุ่มเป้าหมาย] ด้วยน้ำเสียง [น้ำเสียง]\nทำ 3 แบบ: (1) เล่าเรื่อง (2) เน้นปัญหา–ทางออก (3) สั้นกระชับไม่เกิน 2 บรรทัด\nแต่ละแบบต้องมี: ประโยคเปิดที่หยุดนิ้ว ประโยชน์หลัก 2 ข้อ คำกระตุ้นให้ตัดสินใจ และแฮชแท็ก 3 อัน\nห้ามกล่าวอ้างเกินจริงหรือรับประกันผลลัพธ์',
'[ชื่อสินค้า] = “น้ำพริกเห็ดสูตรคุณยาย”, [กลุ่มเป้าหมาย] = “คนทำงานออฟฟิศ”, [น้ำเสียง] = “อบอุ่นเป็นกันเอง”',
'ตัวอย่างผลลัพธ์ (สาธิต): แบบเล่าเรื่อง “กลิ่นน้ำพริกที่ทำให้นึกถึงบ้าน…” พร้อม CTA “ทักแชตสั่งได้เลย”',
'public', 'published', 58, 420, UTC_TIMESTAMP() - INTERVAL 16 DAY, UTC_TIMESTAMP() - INTERVAL 16 DAY, UTC_TIMESTAMP() - INTERVAL 16 DAY
FROM categories WHERE name = 'ธุรกิจและการตลาด';
SET @p = LAST_INSERT_ID();
INSERT INTO prompt_tags SELECT @p, id FROM tags WHERE name IN ('ข้อมูลสาธิต','การตลาด','โซเชียลมีเดีย');
INSERT INTO prompt_ai_tools SELECT @p, id FROM ai_tools WHERE name IN ('ChatGPT','Gemini','Microsoft Copilot');

-- 4
INSERT INTO prompts (user_id, category_id, title, description, content, usage_example, output_example, visibility, status, copy_count, view_count, published_at, created_at, updated_at)
SELECT @u, id, 'วิเคราะห์ลูกค้าด้วย Persona',
'[ข้อมูลสาธิต] สร้างตัวตนลูกค้าสมมุติ เพื่อวางแผนสื่อสารการตลาดให้ตรงใจ',
'ช่วยสร้าง Customer Persona 2 แบบ สำหรับธุรกิจ [ประเภทธุรกิจ] ที่ขาย [สินค้าหรือบริการ]\nแต่ละ Persona ระบุ: ชื่อสมมุติ อายุ อาชีพ เป้าหมาย ปัญหาที่เจอ ช่องทางที่ใช้ เหตุผลที่ซื้อ เหตุผลที่ไม่ซื้อ\nจากนั้นแนะนำข้อความหลักและช่องทางสื่อสารที่เหมาะกับแต่ละ Persona ในรูปแบบตาราง',
'[ประเภทธุรกิจ] = “ร้านกาแฟในชุมชน”, [สินค้าหรือบริการ] = “กาแฟสดและเบเกอรี่โฮมเมด”',
'ตัวอย่างผลลัพธ์ (สาธิต): Persona “พี่ต้น 34 ปี พนักงานบริษัท” อยากได้กาแฟรสดีก่อนเข้างาน … ตารางช่องทาง LINE OA / Facebook',
'public', 'published', 21, 180, UTC_TIMESTAMP() - INTERVAL 15 DAY, UTC_TIMESTAMP() - INTERVAL 15 DAY, UTC_TIMESTAMP() - INTERVAL 15 DAY
FROM categories WHERE name = 'ธุรกิจและการตลาด';
SET @p = LAST_INSERT_ID();
INSERT INTO prompt_tags SELECT @p, id FROM tags WHERE name IN ('ข้อมูลสาธิต','การตลาด','วางแผน');
INSERT INTO prompt_ai_tools SELECT @p, id FROM ai_tools WHERE name IN ('ChatGPT','Claude');

-- 5
INSERT INTO prompts (user_id, category_id, title, description, content, usage_example, output_example, visibility, status, copy_count, view_count, published_at, created_at, updated_at)
SELECT @u, id, 'ร่างบันทึกข้อความภายในหน่วยงาน',
'[ข้อมูลสาธิต] ร่างบันทึกข้อความตามรูปแบบทางการ ภาษาสุภาพ กระชับ',
'ร่างบันทึกข้อความภายในหน่วยงาน\nส่วนราชการ: [หน่วยงาน]\nเรื่อง: [เรื่อง]\nเรียน: [ผู้รับ]\nสาระสำคัญ: [รายละเอียด]\n\nเขียนเป็น 3 ย่อหน้า คือ ความเป็นมา ข้อเท็จจริง/ข้อพิจารณา และข้อเสนอ\nใช้ภาษาราชการที่สุภาพ ชัดเจน และลงท้ายด้วย “จึงเรียนมาเพื่อโปรดพิจารณา”',
'กรอกข้อมูลในวงเล็บให้ครบ แล้วตรวจชื่อ–ตำแหน่งให้ถูกต้องก่อนใช้งานจริง',
'ตัวอย่างผลลัพธ์ (สาธิต): ย่อหน้า 1 ด้วยงานวิชาการมีความประสงค์… ย่อหน้า 2 ข้อเท็จจริง… ย่อหน้า 3 จึงเรียนมาเพื่อโปรดพิจารณา',
'public', 'published', 64, 500, UTC_TIMESTAMP() - INTERVAL 14 DAY, UTC_TIMESTAMP() - INTERVAL 14 DAY, UTC_TIMESTAMP() - INTERVAL 14 DAY
FROM categories WHERE name = 'งานเอกสาร';
SET @p = LAST_INSERT_ID();
INSERT INTO prompt_tags SELECT @p, id FROM tags WHERE name IN ('ข้อมูลสาธิต','หนังสือราชการ','ภาษาไทย');
INSERT INTO prompt_ai_tools SELECT @p, id FROM ai_tools WHERE name IN ('ChatGPT','Claude','Gemini','Microsoft Copilot');

-- 6
INSERT INTO prompts (user_id, category_id, title, description, content, usage_example, output_example, visibility, status, copy_count, view_count, published_at, created_at, updated_at)
SELECT @u, id, 'สรุปรายงานการประชุมจากบันทึกดิบ',
'[ข้อมูลสาธิต] เปลี่ยนโน้ตประชุมที่กระจัดกระจายให้เป็นรายงานพร้อมรายการงานที่ต้องทำ',
'จากบันทึกการประชุมต่อไปนี้ ช่วยสรุปเป็นรายงานการประชุม\n\n[บันทึกการประชุม]\n\nโครงสร้าง:\n1. ประเด็นที่ประชุม\n2. มติที่ประชุม\n3. ตารางงานที่ต้องทำ (งาน | ผู้รับผิดชอบ | กำหนดเสร็จ)\n4. เรื่องที่ต้องติดตามครั้งหน้า\nห้ามเพิ่มข้อมูลที่ไม่มีในบันทึก ถ้าข้อมูลไม่ครบให้เขียนว่า “ไม่ระบุ”',
'วางข้อความโน้ตประชุมแทน [บันทึกการประชุม]',
'ตัวอย่างผลลัพธ์ (สาธิต): ตารางงาน — จัดทำปฏิทินกิจกรรม | ครูสมใจ | 30 ต.ค.',
'public', 'published', 49, 350, UTC_TIMESTAMP() - INTERVAL 13 DAY, UTC_TIMESTAMP() - INTERVAL 13 DAY, UTC_TIMESTAMP() - INTERVAL 13 DAY
FROM categories WHERE name = 'งานเอกสาร';
SET @p = LAST_INSERT_ID();
INSERT INTO prompt_tags SELECT @p, id FROM tags WHERE name IN ('ข้อมูลสาธิต','สรุปประชุม');
INSERT INTO prompt_ai_tools SELECT @p, id FROM ai_tools WHERE name IN ('ChatGPT','Claude','Microsoft Copilot');

-- 7
INSERT INTO prompts (user_id, category_id, title, description, content, usage_example, output_example, visibility, status, copy_count, view_count, published_at, created_at, updated_at)
SELECT @u, id, 'ภาพประกอบบทเรียนสไตล์มินิมอล',
'[ข้อมูลสาธิต] คำสั่งสร้างภาพประกอบสื่อการสอน โทนสีสบายตา ไม่มีตัวอักษรในภาพ',
'Minimal flat illustration of [ฉากหรือวัตถุ], educational style, soft [โทนสี] palette, clean white background, simple shapes, friendly mood, no text, no watermark, centered composition, aspect ratio [สัดส่วนภาพ]',
'[ฉากหรือวัตถุ] = “students building a small solar panel”, [โทนสี] = “purple and blue”, [สัดส่วนภาพ] = “16:9”',
'ตัวอย่างผลลัพธ์ (สาธิต): ภาพนักเรียนประกอบแผงโซลาร์เซลล์ขนาดเล็ก โทนม่วง–น้ำเงิน พื้นหลังขาว',
'public', 'published', 27, 240, UTC_TIMESTAMP() - INTERVAL 12 DAY, UTC_TIMESTAMP() - INTERVAL 12 DAY, UTC_TIMESTAMP() - INTERVAL 12 DAY
FROM categories WHERE name = 'การสร้างภาพ';
SET @p = LAST_INSERT_ID();
INSERT INTO prompt_tags SELECT @p, id FROM tags WHERE name IN ('ข้อมูลสาธิต','ภาพประกอบ');
INSERT INTO prompt_ai_tools SELECT @p, id FROM ai_tools WHERE name IN ('Midjourney','Leonardo AI','Gemini');

-- 8
INSERT INTO prompts (user_id, category_id, title, description, content, usage_example, output_example, visibility, status, copy_count, view_count, published_at, created_at, updated_at)
SELECT @u, id, 'โปสเตอร์ประชาสัมพันธ์กิจกรรม',
'[ข้อมูลสาธิต] สร้างภาพพื้นหลังโปสเตอร์ที่เว้นพื้นที่ใส่ข้อความภายหลัง',
'Poster background for [ชื่องาน], theme [ธีม], vibrant gradient, modern geometric shapes, large empty area at the top for title text, high resolution, vertical 4:5, no text, no logo',
'[ชื่องาน] = “science fair”, [ธีม] = “innovation and future technology” แล้วนำภาพไปใส่ข้อความภาษาไทยเองในโปรแกรมออกแบบ',
'ตัวอย่างผลลัพธ์ (สาธิต): พื้นหลังไล่สีม่วง–ฟ้า มีรูปทรงเรขาคณิต ด้านบนเว้นว่างสำหรับชื่องาน',
'public', 'published', 18, 150, UTC_TIMESTAMP() - INTERVAL 11 DAY, UTC_TIMESTAMP() - INTERVAL 11 DAY, UTC_TIMESTAMP() - INTERVAL 11 DAY
FROM categories WHERE name = 'การสร้างภาพ';
SET @p = LAST_INSERT_ID();
INSERT INTO prompt_tags SELECT @p, id FROM tags WHERE name IN ('ข้อมูลสาธิต','โปสเตอร์');
INSERT INTO prompt_ai_tools SELECT @p, id FROM ai_tools WHERE name IN ('Midjourney','Leonardo AI');

-- 9
INSERT INTO prompts (user_id, category_id, title, description, content, usage_example, output_example, visibility, status, copy_count, view_count, published_at, created_at, updated_at)
SELECT @u, id, 'สคริปต์วิดีโอสั้น 60 วินาที',
'[ข้อมูลสาธิต] เขียนสคริปต์คลิปแนวตั้ง แบ่งช่วงเวลา ภาพ และเสียงบรรยาย',
'เขียนสคริปต์วิดีโอแนวตั้งความยาว 60 วินาที เรื่อง [หัวข้อ] สำหรับ [กลุ่มผู้ชม]\nทำเป็นตาราง: ช่วงเวลา | ภาพที่เห็น | เสียงบรรยาย | ข้อความบนจอ\n- 3 วินาทีแรกต้องดึงความสนใจ\n- จบด้วยคำชวนให้ทำต่อ [สิ่งที่อยากให้ผู้ชมทำ]\n- ภาษาพูดเป็นธรรมชาติ ไม่เกิน 150 คำ',
'[หัวข้อ] = “3 วิธีประหยัดไฟในห้องเรียน”, [กลุ่มผู้ชม] = “นักเรียนมัธยม”, [สิ่งที่อยากให้ผู้ชมทำ] = “แชร์ให้เพื่อน”',
'ตัวอย่างผลลัพธ์ (สาธิต): 0–3 วิ ภาพสวิตช์ไฟเปิดทิ้ง | “รู้ไหม ห้องเรียนเราเปลืองไฟแค่ไหน?”',
'public', 'published', 33, 290, UTC_TIMESTAMP() - INTERVAL 10 DAY, UTC_TIMESTAMP() - INTERVAL 10 DAY, UTC_TIMESTAMP() - INTERVAL 10 DAY
FROM categories WHERE name = 'การสร้างวิดีโอ';
SET @p = LAST_INSERT_ID();
INSERT INTO prompt_tags SELECT @p, id FROM tags WHERE name IN ('ข้อมูลสาธิต','สคริปต์วิดีโอ');
INSERT INTO prompt_ai_tools SELECT @p, id FROM ai_tools WHERE name IN ('ChatGPT','Claude','Gemini');

-- 10
INSERT INTO prompts (user_id, category_id, title, description, content, usage_example, output_example, visibility, status, copy_count, view_count, published_at, created_at, updated_at)
SELECT @u, id, 'คำสั่งสร้างฉากวิดีโอด้วย AI',
'[ข้อมูลสาธิต] อธิบายฉาก มุมกล้อง และการเคลื่อนไหว สำหรับเครื่องมือสร้างวิดีโอ',
'Cinematic shot of [ฉาก], camera [การเคลื่อนกล้อง], lighting [แสง], duration 5 seconds, smooth motion, realistic details, no text overlay',
'[ฉาก] = “a vocational student repairing an electric motor in a bright workshop”, [การเคลื่อนกล้อง] = “slow dolly in”, [แสง] = “soft morning light”',
'ตัวอย่างผลลัพธ์ (สาธิต): คลิป 5 วินาที กล้องค่อย ๆ เคลื่อนเข้าหานักศึกษาที่กำลังซ่อมมอเตอร์',
'public', 'published', 12, 130, UTC_TIMESTAMP() - INTERVAL 9 DAY, UTC_TIMESTAMP() - INTERVAL 9 DAY, UTC_TIMESTAMP() - INTERVAL 9 DAY
FROM categories WHERE name = 'การสร้างวิดีโอ';
SET @p = LAST_INSERT_ID();
INSERT INTO prompt_tags SELECT @p, id FROM tags WHERE name IN ('ข้อมูลสาธิต','สตอรีบอร์ด');
INSERT INTO prompt_ai_tools SELECT @p, id FROM ai_tools WHERE name IN ('Runway');

-- 11
INSERT INTO prompts (user_id, category_id, title, description, content, usage_example, output_example, visibility, status, copy_count, view_count, published_at, created_at, updated_at)
SELECT @u, id, 'อธิบายโค้ดทีละบรรทัดสำหรับมือใหม่',
'[ข้อมูลสาธิต] ให้ AI อธิบายโค้ดเป็นภาษาไทยง่าย ๆ พร้อมจุดที่ควรระวัง',
'อธิบายโค้ด [ภาษาโปรแกรม] ต่อไปนี้ให้ผู้เรียนระดับเริ่มต้นเข้าใจ\n\n[โค้ด]\n\n- อธิบายทีละส่วนเป็นภาษาไทย\n- บอกผลลัพธ์ที่คาดว่าจะได้\n- ชี้จุดเสี่ยงด้านความปลอดภัยหรือบั๊กที่อาจเกิด\n- เสนอแบบฝึกหัดต่อยอด 2 ข้อ',
'วางโค้ดแทน [โค้ด] และระบุ [ภาษาโปรแกรม] เช่น PHP หรือ Python',
'ตัวอย่างผลลัพธ์ (สาธิต): บรรทัด 1–3 ประกาศตัวแปร… จุดเสี่ยง: ควรใช้ Prepared Statement แทนการต่อสตริง SQL',
'public', 'published', 47, 380, UTC_TIMESTAMP() - INTERVAL 8 DAY, UTC_TIMESTAMP() - INTERVAL 8 DAY, UTC_TIMESTAMP() - INTERVAL 8 DAY
FROM categories WHERE name = 'การเขียนโปรแกรม';
SET @p = LAST_INSERT_ID();
INSERT INTO prompt_tags SELECT @p, id FROM tags WHERE name IN ('ข้อมูลสาธิต','PHP');
INSERT INTO prompt_ai_tools SELECT @p, id FROM ai_tools WHERE name IN ('ChatGPT','Claude','GitHub Copilot');

-- 12
INSERT INTO prompts (user_id, category_id, title, description, content, usage_example, output_example, visibility, status, copy_count, view_count, published_at, created_at, updated_at)
SELECT @u, id, 'ช่วยหาสาเหตุ Error และวิธีแก้',
'[ข้อมูลสาธิต] วิเคราะห์ข้อความผิดพลาด หาสาเหตุที่เป็นไปได้ และขั้นตอนตรวจสอบ',
'ฉันเจอข้อผิดพลาดนี้ใน [ภาษาหรือเฟรมเวิร์ก]\n\nข้อความ Error:\n[ข้อความผิดพลาด]\n\nโค้ดที่เกี่ยวข้อง:\n[โค้ด]\n\nช่วย:\n1. อธิบายความหมายของ Error\n2. เรียงสาเหตุที่เป็นไปได้จากมากไปน้อย\n3. ให้ขั้นตอนตรวจสอบทีละขั้น\n4. เสนอโค้ดที่แก้แล้ว พร้อมเหตุผล',
'ระวังอย่าวางรหัสผ่านหรือข้อมูลลับลงใน Prompt',
'ตัวอย่างผลลัพธ์ (สาธิต): “Undefined index” หมายถึงอ่านคีย์ที่ไม่มีใน array → ตรวจด้วย isset() หรือ ??',
'public', 'published', 39, 330, UTC_TIMESTAMP() - INTERVAL 7 DAY, UTC_TIMESTAMP() - INTERVAL 7 DAY, UTC_TIMESTAMP() - INTERVAL 7 DAY
FROM categories WHERE name = 'การเขียนโปรแกรม';
SET @p = LAST_INSERT_ID();
INSERT INTO prompt_tags SELECT @p, id FROM tags WHERE name IN ('ข้อมูลสาธิต','ดีบัก','PHP');
INSERT INTO prompt_ai_tools SELECT @p, id FROM ai_tools WHERE name IN ('ChatGPT','Claude','GitHub Copilot');

-- 13
INSERT INTO prompts (user_id, category_id, title, description, content, usage_example, output_example, visibility, status, copy_count, view_count, published_at, created_at, updated_at)
SELECT @u, id, 'สร้างสูตร Excel จากคำอธิบาย',
'[ข้อมูลสาธิต] บอกสิ่งที่ต้องการเป็นภาษาไทย แล้วได้สูตร Excel พร้อมคำอธิบาย',
'ฉันมีตารางใน Excel คอลัมน์ดังนี้: [รายการคอลัมน์]\nต้องการ: [สิ่งที่ต้องการคำนวณ]\n\nช่วยเขียนสูตร Excel ที่ใช้ได้ พร้อม\n- อธิบายแต่ละส่วนของสูตร\n- ตัวอย่างข้อมูล 3 แถวและผลลัพธ์\n- ทางเลือกสำหรับ Google Sheets ถ้าแตกต่าง',
'[รายการคอลัมน์] = “A ชื่อ, B คะแนนกลางภาค, C คะแนนปลายภาค”, [สิ่งที่ต้องการคำนวณ] = “ตัดเกรด 4 ระดับจากคะแนนรวม”',
'ตัวอย่างผลลัพธ์ (สาธิต): =IFS(B2+C2>=80,4,B2+C2>=70,3,B2+C2>=60,2,TRUE,1)',
'public', 'published', 52, 410, UTC_TIMESTAMP() - INTERVAL 6 DAY, UTC_TIMESTAMP() - INTERVAL 6 DAY, UTC_TIMESTAMP() - INTERVAL 6 DAY
FROM categories WHERE name = 'การวิเคราะห์ข้อมูล';
SET @p = LAST_INSERT_ID();
INSERT INTO prompt_tags SELECT @p, id FROM tags WHERE name IN ('ข้อมูลสาธิต','Excel');
INSERT INTO prompt_ai_tools SELECT @p, id FROM ai_tools WHERE name IN ('ChatGPT','Microsoft Copilot','Gemini');

-- 14
INSERT INTO prompts (user_id, category_id, title, description, content, usage_example, output_example, visibility, status, copy_count, view_count, published_at, created_at, updated_at)
SELECT @u, id, 'สรุปผลแบบสอบถามความพึงพอใจ',
'[ข้อมูลสาธิต] ตีความค่าเฉลี่ย ส่วนเบี่ยงเบนมาตรฐาน และข้อเสนอแนะจากแบบสอบถาม',
'ข้อมูลผลแบบสอบถามความพึงพอใจเรื่อง [เรื่อง] จากผู้ตอบ [จำนวนผู้ตอบ] คน\n\n[ตารางค่าเฉลี่ยและส่วนเบี่ยงเบนมาตรฐาน]\n\nช่วยเขียนสรุปผลเชิงวิชาการ:\n- แปลความหมายค่าเฉลี่ยตามเกณฑ์ 5 ระดับ\n- ระบุด้านที่สูงสุดและต่ำสุด\n- ข้อเสนอแนะเพื่อพัฒนา 3 ข้อ\nห้ามสร้างตัวเลขที่ไม่มีในข้อมูล',
'วางตารางผลจาก Excel แทนวงเล็บ แล้วตรวจตัวเลขอีกครั้งก่อนนำไปใช้',
'ตัวอย่างผลลัพธ์ (สาธิต): ภาพรวมอยู่ในระดับมาก (x̄ = 4.21, S.D. = 0.56) ด้านที่สูงสุดคือ…',
'public', 'published', 29, 220, UTC_TIMESTAMP() - INTERVAL 5 DAY, UTC_TIMESTAMP() - INTERVAL 5 DAY, UTC_TIMESTAMP() - INTERVAL 5 DAY
FROM categories WHERE name = 'การวิเคราะห์ข้อมูล';
SET @p = LAST_INSERT_ID();
INSERT INTO prompt_tags SELECT @p, id FROM tags WHERE name IN ('ข้อมูลสาธิต','สรุปข้อมูล');
INSERT INTO prompt_ai_tools SELECT @p, id FROM ai_tools WHERE name IN ('ChatGPT','Claude');

-- 15
INSERT INTO prompts (user_id, category_id, title, description, content, usage_example, output_example, visibility, status, copy_count, view_count, published_at, created_at, updated_at)
SELECT @u, id, 'วางแผนงานประจำสัปดาห์',
'[ข้อมูลสาธิต] จัดลำดับงานตามความสำคัญและเวลาที่มี ออกมาเป็นตารางรายวัน',
'ช่วยวางแผนสัปดาห์นี้จากรายการงานต่อไปนี้\n[รายการงาน]\n\nเวลาว่างต่อวันประมาณ [ชั่วโมงต่อวัน] ชั่วโมง\nจัดลำดับด้วยหลักเร่งด่วน–สำคัญ แล้วทำตารางจันทร์ถึงศุกร์\nเว้นเวลาพักและเวลาสำรองสำหรับงานแทรกวันละ 30 นาที',
'พิมพ์รายการงานบรรทัดละ 1 งาน พร้อมกำหนดส่งถ้ามี',
'ตัวอย่างผลลัพธ์ (สาธิต): จันทร์ 08:00–10:00 ตรวจงานนักเรียน (สำคัญ–เร่งด่วน) …',
'public', 'published', 24, 200, UTC_TIMESTAMP() - INTERVAL 3 DAY, UTC_TIMESTAMP() - INTERVAL 3 DAY, UTC_TIMESTAMP() - INTERVAL 3 DAY
FROM categories WHERE name = 'งานทั่วไป';
SET @p = LAST_INSERT_ID();
INSERT INTO prompt_tags SELECT @p, id FROM tags WHERE name IN ('ข้อมูลสาธิต','วางแผน');
INSERT INTO prompt_ai_tools SELECT @p, id FROM ai_tools WHERE name IN ('ChatGPT','Claude','Gemini','Microsoft Copilot');

-- 16
INSERT INTO prompts (user_id, category_id, title, description, content, usage_example, output_example, visibility, status, copy_count, view_count, published_at, created_at, updated_at)
SELECT @u, id, 'เขียนอีเมลขอความร่วมมืออย่างสุภาพ',
'[ข้อมูลสาธิต] ร่างอีเมลขอความช่วยเหลือที่สุภาพ ชัดเจน และบอกสิ่งที่ต้องการครบ',
'ร่างอีเมลถึง [ผู้รับ] เพื่อขอความร่วมมือเรื่อง [เรื่อง]\nรายละเอียด: [รายละเอียด]\nกำหนดตอบกลับภายใน [วันที่]\nน้ำเสียง [น้ำเสียง]\n\nมีหัวเรื่องอีเมล คำขึ้นต้น เนื้อหาไม่เกิน 150 คำ และคำลงท้ายที่เหมาะสม',
'[ผู้รับ] = “หัวหน้าแผนกวิชา”, [น้ำเสียง] = “สุภาพ เป็นทางการเล็กน้อย”',
'ตัวอย่างผลลัพธ์ (สาธิต): หัวเรื่อง: ขอความอนุเคราะห์ข้อมูลรายวิชา … ด้วยความเคารพ',
'public', 'published', 31, 240, UTC_TIMESTAMP() - INTERVAL 1 DAY, UTC_TIMESTAMP() - INTERVAL 1 DAY, UTC_TIMESTAMP() - INTERVAL 1 DAY
FROM categories WHERE name = 'งานทั่วไป';
SET @p = LAST_INSERT_ID();
INSERT INTO prompt_tags SELECT @p, id FROM tags WHERE name IN ('ข้อมูลสาธิต','อีเมล','ภาษาไทย');
INSERT INTO prompt_ai_tools SELECT @p, id FROM ai_tools WHERE name IN ('ChatGPT','Claude','Gemini');

COMMIT;
