/* Prompt Hub — สคริปต์หน้าเว็บ (ไม่มี inline script เพื่อให้ใช้ Content-Security-Policy ได้) */
(function () {
    'use strict';

    // ---------- ภาพจาก Google Drive โหลดไม่ได้ → ใช้สำเนาในเครื่อง (img[data-fallback]) ----------
    document.addEventListener('error', function (event) {
        var img = event.target;
        if (img instanceof HTMLImageElement && img.dataset.fallback && img.src.indexOf(img.dataset.fallback) === -1) {
            img.removeAttribute('referrerpolicy');
            img.src = img.dataset.fallback;
            img.dataset.fallback = '';
        }
    }, true);

    // ---------- ยืนยันก่อนลบหรือทำรายการสำคัญ (form[data-confirm]) ----------
    var modalEl = document.getElementById('confirmModal');
    var pendingForm = null;
    var modal = modalEl && window.bootstrap ? new bootstrap.Modal(modalEl) : null;

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.dataset.confirm && form.dataset.confirmed !== '1') {
            event.preventDefault();
            if (modal) {
                pendingForm = form;
                document.getElementById('confirmMessage').textContent = form.dataset.confirm;
                modal.show();
            } else if (window.confirm(form.dataset.confirm)) {
                form.dataset.confirmed = '1';
                form.requestSubmit ? form.requestSubmit() : form.submit();
            }
            return;
        }
        // สถานะกำลังทำงาน: ปิดปุ่มกันกดซ้ำ
        var button = event.submitter || form.querySelector('[type=submit]');
        if (button && !button.disabled) {
            if (button.name) {
                // ปุ่มที่มี name/value ต้องส่งค่าไปด้วย แม้จะถูกปิดหลังกด
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = button.name;
                hidden.value = button.value;
                form.appendChild(hidden);
            }
            setTimeout(function () {
                button.disabled = true;
                button.classList.add('is-loading');
                button.insertAdjacentHTML('afterbegin', '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>');
            }, 0);
        }
    });

    if (modalEl) {
        document.getElementById('confirmOk').addEventListener('click', function () {
            if (!pendingForm) return;
            var form = pendingForm;
            pendingForm = null;
            form.dataset.confirmed = '1';
            modal.hide();
            form.requestSubmit ? form.requestSubmit() : form.submit();
        });
    }

    // ---------- แถบแจ้งการใช้คุกกี้ (จำการกดรับทราบไว้ในเบราว์เซอร์นี้) ----------
    var notice = document.getElementById('cookieNotice');
    if (notice) {
        var seen = false;
        try { seen = window.localStorage.getItem('ph-cookie-notice') === '1'; } catch (e) { /* โหมดส่วนตัว */ }
        // เว้นที่ท้ายหน้าเท่าความสูงของแถบ เพื่อไม่ให้บังส่วนท้ายเว็บ (เวอร์ชัน/ลิขสิทธิ์)
        var reserve = function () { document.body.style.paddingBottom = notice.classList.contains('d-none') ? '' : notice.offsetHeight + 'px'; };
        if (!seen) {
            notice.classList.remove('d-none');
            reserve();
            window.addEventListener('resize', reserve);
        }
        document.getElementById('cookieAccept').addEventListener('click', function () {
            try { window.localStorage.setItem('ph-cookie-notice', '1'); } catch (e) { /* ไม่เป็นไร */ }
            notice.classList.add('d-none');
            reserve();
        });
    }

    // ---------- แถบความคืบหน้าแต้มสะสม (CSP ไม่อนุญาต style inline จึงตั้งความกว้างจาก aria-valuenow) ----------
    document.querySelectorAll('.progress[aria-valuenow] .points-bar').forEach(function (bar) {
        var v = parseInt(bar.parentNode.getAttribute('aria-valuenow'), 10) || 0;
        bar.style.width = Math.max(0, Math.min(100, v)) + '%';
    });

    // ---------- ย่อภาพในเบราว์เซอร์ก่อนอัปโหลด (input[data-resize-max], [data-resize-square] = ตัดเป็นจัตุรัส) ----------
    // ลดเวลาอัปโหลดและแก้ภาพมือถือที่หมุนผิด (เบราว์เซอร์หมุนตาม EXIF ให้ตอนวาด) เซิร์ฟเวอร์ยังตรวจและย่อซ้ำอีกชั้น
    var canResize = !!(window.createImageBitmap && window.DataTransfer && window.File && HTMLCanvasElement.prototype.toBlob);
    var shrinkImage = function (file, max, square) {
        if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return Promise.resolve(file);
        return createImageBitmap(file).then(function (bmp) {
            var w = bmp.width, h = bmp.height, sx = 0, sy = 0, sw = w, sh = h, tw, th;
            if (square) {
                var side = Math.min(w, h);
                sx = Math.floor((w - side) / 2); sy = Math.floor((h - side) / 2); sw = sh = side;
                tw = th = Math.min(side, max);
            } else {
                var scale = Math.min(1, max / Math.max(w, h));
                tw = Math.max(1, Math.round(w * scale)); th = Math.max(1, Math.round(h * scale));
            }
            // JPEG เข้ารหัสใหม่เสมอ (แก้การหมุนและตัด EXIF) ส่วน PNG/WebP ทำเฉพาะเมื่อต้องย่อหรือตัด
            if (file.type !== 'image/jpeg' && tw === w && th === h) { bmp.close(); return file; }
            var canvas = document.createElement('canvas');
            canvas.width = tw; canvas.height = th;
            var ctx = canvas.getContext('2d');
            ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(bmp, sx, sy, sw, sh, 0, 0, tw, th);
            bmp.close();
            var encode = function (type) {
                return new Promise(function (resolve) { canvas.toBlob(resolve, type, 0.86); });
            };
            return encode(file.type).then(function (blob) {
                // เบราว์เซอร์บางตัว (เช่น Safari) เข้ารหัส WebP ไม่ได้ → ใช้ PNG แทนเพื่อคงความโปร่งใส
                if (blob && blob.type === file.type) return { blob: blob, type: file.type, name: file.name };
                return encode('image/png').then(function (png) {
                    return png ? { blob: png, type: 'image/png', name: file.name.replace(/\.[^.]+$/, '') + '.png' } : null;
                });
            }).then(function (r) {
                if (!r) return file;
                // ไม่ได้ย่อแต่ไฟล์ใหม่ใหญ่กว่าเดิมมาก ใช้ไฟล์เดิม (เซิร์ฟเวอร์จะจัดการต่อ)
                if (tw === w && th === h && r.blob.size > file.size * 1.5) return file;
                return new File([r.blob], r.name, { type: r.type, lastModified: Date.now() });
            });
        }).catch(function () { return file; });
    };
    document.querySelectorAll('input[type=file][data-resize-max]').forEach(function (input) {
        var status = input.parentNode.querySelector('[data-resize-status]');
        input.addEventListener('change', function (event) {
            if (input.dataset.resized === '1') { input.dataset.resized = ''; return; }
            var files = Array.prototype.slice.call(input.files || []);
            if (!canResize || !files.length) return;
            // หยุดตัวจัดการอื่น (เช่น ดูตัวอย่าง) จนกว่าจะย่อเสร็จ แล้วค่อยส่ง change ใหม่
            event.stopImmediatePropagation();
            var max = parseInt(input.dataset.resizeMax, 10) || 1600;
            var square = input.hasAttribute('data-resize-square');
            var buttons = input.form ? Array.prototype.slice.call(input.form.querySelectorAll('[type=submit]')) : [];
            buttons.forEach(function (b) { b.disabled = true; });
            if (status) status.textContent = 'กำลังปรับขนาดภาพ…';
            var before = files.reduce(function (s, f) { return s + f.size; }, 0);
            Promise.all(files.map(function (f) { return shrinkImage(f, max, square); })).then(function (out) {
                var dt = new DataTransfer();
                out.forEach(function (f) { dt.items.add(f); });
                input.files = dt.files;
                var after = out.reduce(function (s, f) { return s + f.size; }, 0);
                if (status) {
                    status.textContent = after < before
                        ? 'ปรับขนาดภาพแล้ว ' + (before / 1048576).toFixed(1) + ' MB → ' + (after / 1048576).toFixed(1) + ' MB'
                        : '';
                }
            }).finally(function () {
                buttons.forEach(function (b) { b.disabled = false; });
                input.dataset.resized = '1';
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    });

    // ---------- ตั้งค่าเว็บไซต์: แสดงลิงก์ตัวอย่างตาม URL ที่กำลังพิมพ์ (เซิร์ฟเวอร์ตรวจรูปแบบอีกครั้งตอนบันทึก) ----------
    var siteUrl = document.getElementById('site_url');
    if (siteUrl) {
        var previews = document.querySelectorAll('#siteUrlBox [data-url-suffix]');
        var updateUrl = function () {
            var v = siteUrl.value.trim().replace(/\/+$/, '');
            var ok = /^https?:\/\/[^\s\/?#@]+(\/[^\s?#]*)?$/i.test(v);
            var base = ok ? v.replace(/^(https?:\/\/[^\/]+)/i, function (h) { return h.toLowerCase(); }) : siteUrl.placeholder;
            previews.forEach(function (dd) { dd.textContent = base + dd.dataset.urlSuffix; });
            siteUrl.classList.toggle('is-invalid', v !== '' && !ok);
        };
        siteUrl.addEventListener('input', updateUrl);
    }

    // ---------- โปรไฟล์: แสดงช่องรหัสผ่านเมื่อเปลี่ยนชื่อผู้ใช้หรืออีเมล ----------
    var pwWrap = document.getElementById('profilePasswordWrap');
    if (pwWrap) {
        var watch = ['username', 'email'].map(function (id) { return document.getElementById(id); }).filter(Boolean);
        var togglePw = function () {
            var changed = watch.some(function (el) { return el.value.trim().toLowerCase() !== (el.dataset.original || '').toLowerCase(); });
            if (changed) pwWrap.classList.remove('d-none');
        };
        watch.forEach(function (el) { el.addEventListener('input', togglePw); });
        togglePw();
    }

    // ---------- ดูตัวอย่างภาพโปรไฟล์ก่อนอัปโหลด ----------
    var avatarInput = document.getElementById('avatar');
    var avatarWrap = document.getElementById('avatarPreviewWrap');
    if (avatarInput && avatarWrap) {
        avatarInput.addEventListener('change', function () {
            var f = avatarInput.files && avatarInput.files[0];
            if (!f || !/^image\/(jpeg|png|webp)$/.test(f.type)) return;
            var img = document.createElement('img');
            img.className = 'avatar avatar-lg';
            img.alt = 'ตัวอย่างภาพโปรไฟล์ใหม่';
            img.width = img.height = 112;
            img.src = URL.createObjectURL(f);
            avatarWrap.replaceChildren(img);
        });
    }

    // ---------- แสดง/ซ่อนรหัสผ่าน ----------
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.dataset.togglePassword);
            if (!input) return;
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-label', show ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน');
            btn.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
        });
    });

    // ---------- ตัวนับตัวอักษร ----------
    document.querySelectorAll('[data-counter]').forEach(function (field) {
        var out = document.getElementById(field.dataset.counter);
        if (!out) return;
        var update = function () {
            out.textContent = field.value.length.toLocaleString('th-TH') + ' / ' + Number(field.maxLength).toLocaleString('th-TH');
        };
        field.addEventListener('input', update);
        update();
    });

    // ---------- ฟอร์ม Prompt: คำอธิบายตามการมองเห็น ----------
    var hint = document.getElementById('visibilityHint');
    var submitReview = document.getElementById('submitReview');
    if (hint && submitReview) {
        var updateVis = function () {
            var pub = document.getElementById('visPublic').checked;
            submitReview.disabled = !pub;
            hint.textContent = pub
                ? '“ส่งตรวจเพื่อเผยแพร่” จะส่งให้ผู้ดูแลตรวจสอบ ส่วน “บันทึกฉบับร่าง” จะยังไม่แสดงต่อสาธารณะ'
                : 'Prompt ส่วนตัวจะเห็นเฉพาะคุณและผู้ดูแลระบบ เลือก “สาธารณะ” หากต้องการส่งเผยแพร่';
        };
        document.querySelectorAll('input[name=visibility]').forEach(function (r) { r.addEventListener('change', updateVis); });
        updateVis();
    }

    // ---------- ฟอร์ม Prompt: ภาพตัวอย่าง (แสดงเฉพาะหมวดที่รับภาพ + ดูตัวอย่างก่อนอัปโหลด) ----------
    var catSelect = document.getElementById('category_id');
    var imageWrap = document.getElementById('imageInputWrap');
    if (catSelect && imageWrap) {
        var notAllowed = document.getElementById('imageNotAllowed');
        var fileInput = document.getElementById('images');
        var preview = document.getElementById('imagePreview');
        var syncImages = function () {
            var opt = catSelect.options[catSelect.selectedIndex];
            var allow = opt && opt.dataset.images === '1';
            imageWrap.classList.toggle('d-none', !allow);
            notAllowed.classList.toggle('d-none', allow || !opt || !opt.value);
            if (!allow) { fileInput.value = ''; preview.textContent = ''; }
        };
        catSelect.addEventListener('change', syncImages);
        syncImages();
        fileInput.addEventListener('change', function () {
            preview.textContent = '';
            Array.prototype.slice.call(fileInput.files, 0, 8).forEach(function (f) {
                var col = document.createElement('div');
                col.className = 'col-6 col-md-3';
                var img = document.createElement('img');
                img.alt = '';
                img.src = URL.createObjectURL(f);
                var note = document.createElement('div');
                note.className = 'small ' + (f.size > 5 * 1024 * 1024 ? 'text-danger' : 'text-secondary');
                note.textContent = f.name + ' · ' + (f.size / 1048576).toFixed(1) + ' MB' + (f.size > 5 * 1024 * 1024 ? ' (เกิน 5 MB)' : '');
                col.appendChild(img);
                col.appendChild(note);
                preview.appendChild(col);
            });
        });
    }

    // ---------- ตัวแปรใน Prompt และการคัดลอก ----------
    var source = document.getElementById('promptSource');
    var output = document.getElementById('promptOutput');
    if (source && output) {
        // อ่านต้นฉบับจาก textContent (ข้อความล้วน ไม่ตีความ HTML)
        var original = source.textContent;
        var pattern = /\[([^\[\]\r\n]{1,60})\]/g;
        var names = [];
        var m;
        while ((m = pattern.exec(original)) !== null) {
            var name = m[1].trim();
            if (name && names.indexOf(name) === -1 && names.length < 30) names.push(name);
        }
        var values = {};
        var render = function () {
            // สร้างข้อความใหม่จากต้นฉบับทุกครั้ง ต้นฉบับในหน้าและฐานข้อมูลไม่ถูกแก้ไข
            output.value = original.replace(pattern, function (whole, key) {
                var v = values[key.trim()];
                return v ? v : whole;
            });
        };
        if (names.length) {
            var box = document.getElementById('variableBox');
            var fields = document.getElementById('variableFields');
            names.forEach(function (n, i) {
                var col = document.createElement('div');
                col.className = 'col-md-6';
                var label = document.createElement('label');
                label.className = 'form-label small mb-1';
                label.htmlFor = 'var' + i;
                label.textContent = n; // textContent ป้องกัน XSS จากชื่อตัวแปร
                var input = document.createElement('input');
                input.className = 'form-control form-control-sm';
                input.id = 'var' + i;
                input.maxLength = 500;
                input.placeholder = 'กรอก ' + n;
                input.addEventListener('input', function () {
                    values[n] = input.value;
                    render();
                });
                col.appendChild(label);
                col.appendChild(input);
                fields.appendChild(col);
            });
            box.classList.remove('d-none');
            document.getElementById('resetVariables').addEventListener('click', function () {
                values = {};
                fields.querySelectorAll('input').forEach(function (i) { i.value = ''; });
                render();
            });
        }
        render();

        var copyBtn = document.getElementById('copyButton');
        var status = document.getElementById('copyStatus');
        var setStatus = function (text, cls) {
            status.textContent = text;
            status.className = 'small ' + (cls || '');
        };
        var fallbackCopy = function () {
            output.focus();
            output.select();
            output.setSelectionRange(0, output.value.length);
            try { return document.execCommand('copy'); } catch (e) { return false; }
        };
        var label = copyBtn.querySelector('span');
        copyBtn.addEventListener('click', function () {
            copyBtn.disabled = true;
            label.textContent = 'กำลังคัดลอก…';
            var text = output.value;
            var done = function (ok) {
                copyBtn.disabled = false;
                label.textContent = 'คัดลอก Prompt';
                if (!ok) {
                    setStatus('คัดลอกอัตโนมัติไม่สำเร็จ ข้อความถูกเลือกไว้แล้ว กด Ctrl+C (หรือค้างเพื่อคัดลอกบนมือถือ)', 'text-danger');
                    output.focus();
                    output.select();
                    return;
                }
                setStatus('คัดลอกแล้ว ✓', 'text-success fw-semibold');
                var body = new FormData();
                body.append('id', copyBtn.dataset.id);
                body.append('_csrf', copyBtn.dataset.csrf);
                fetch(copyBtn.dataset.url, {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                }).then(function (r) { return r.ok ? r.json() : null; })
                  .then(function (data) {
                      var counter = document.getElementById('copyCount');
                      if (data && data.ok && counter) counter.textContent = Number(data.copy_count).toLocaleString('en-US');
                  })
                  .catch(function () { /* นับสถิติไม่สำเร็จไม่กระทบการคัดลอก */ });
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(fallbackCopy()); });
            } else {
                done(fallbackCopy());
            }
        });
    }
})();

/* ---------- Digital Shop ---------- */
(function () {
    'use strict';

    // QR พร้อมเพย์: ข้อความ EMVCo สร้างที่เซิร์ฟเวอร์ (data-payload) วาดเป็นภาพด้วย qrcode-generator (โหลดเฉพาะหน้าชำระเงิน)
    var qrBox = document.getElementById('promptpayQr');
    if (qrBox) {
        if (typeof window.qrcode === 'function') {
            var qr = window.qrcode(0, 'M');
            qr.addData(qrBox.dataset.payload);
            qr.make();
            var img = document.createElement('img');
            img.alt = '';
            img.src = qr.createDataURL(8, 0);
            qrBox.replaceChildren(img);
        } else {
            qrBox.textContent = 'โหลด QR ไม่สำเร็จ กรุณาโอนตามหมายเลขพร้อมเพย์ด้านล่าง';
            qrBox.classList.add('small', 'text-dark', 'text-center');
        }
    }

    // หัวใจบนการ์ดสินค้า: สลับรายการโปรดโดยไม่โหลดหน้าใหม่ (ไม่มี JavaScript ก็ส่งฟอร์มตามปกติ)
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.classList.contains('shop-fav-form') || !window.fetch) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        var btn = form.querySelector('.shop-fav');
        btn.disabled = true;
        fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
            .then(function (data) {
                btn.classList.toggle('is-on', data.favorite);
                btn.setAttribute('aria-pressed', data.favorite ? 'true' : 'false');
                btn.querySelector('i').className = 'bi bi-heart' + (data.favorite ? '-fill' : '');
            })
            .catch(function () { form.submit(); })
            .finally(function () { btn.disabled = false; });
    }, true);

    // ปุ่มแชร์: ใช้เมนูแชร์ของอุปกรณ์ ถ้าไม่มีให้คัดลอกลิงก์
    var copyText = function (text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return false; });
        }
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        ta.remove();
        return Promise.resolve(ok);
    };
    document.querySelectorAll('[data-share-url]').forEach(function (btn) {
        var label = btn.querySelector('span');
        btn.addEventListener('click', function () {
            var url = btn.dataset.shareUrl;
            if (navigator.share) {
                navigator.share({ title: btn.dataset.shareTitle, url: url }).catch(function () { /* ผู้ใช้ยกเลิก */ });
                return;
            }
            copyText(url).then(function (ok) {
                label.textContent = ok ? 'คัดลอกลิงก์แล้ว' : 'คัดลอกไม่สำเร็จ';
                setTimeout(function () { label.textContent = 'แชร์'; }, 2000);
            });
        });
    });

    // คัดลอกเนื้อหาเต็ม/โค้ดในคลังของฉัน: <button data-copy-target="#id">
    document.querySelectorAll('[data-copy-target]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var src = document.querySelector(btn.dataset.copyTarget);
            var status = btn.parentNode.querySelector('[data-copy-status]');
            if (!src) return;
            copyText(src.textContent).then(function (ok) {
                if (status) {
                    status.textContent = ok ? 'คัดลอกแล้ว ✓' : 'คัดลอกอัตโนมัติไม่สำเร็จ กรุณาเลือกข้อความแล้วกด Ctrl+C';
                    status.className = 'small me-auto ' + (ok ? 'text-success fw-semibold' : 'text-danger');
                }
            });
        });
    });
})();
