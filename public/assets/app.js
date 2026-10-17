"use strict";
document.querySelectorAll("form").forEach((form) => {
  form.addEventListener("submit", (event) => {
    const confirmText =
      form.dataset.confirm ||
      (event.submitter?.value === "delete" ? form.dataset.confirmDelete : null);
    if (confirmText && !confirm(confirmText)) {
      event.preventDefault();
      return;
    }
    const button = event.submitter;
    if (button) {
      button.setAttribute("aria-busy", "true");
      setTimeout(() => {
        button.disabled = true;
        button.textContent = "กำลังทำงาน…";
      }, 0);
    }
  });
});
const original = document.getElementById("original");
if (original) {
  const source = original.textContent;
  const output = document.getElementById("rendered");
  const container = document.getElementById("variables");
  const names = [
    ...new Set(
      [...source.matchAll(/\[([^\[\]\r\n]{1,80})\]/gu)].map((m) => m[1]),
    ),
  ].slice(0, 50);
  const values = new Map();
  names.forEach((name, i) => {
    const col = document.createElement("div");
    col.className = "col-md-6";
    const label = document.createElement("label");
    label.className = "form-label";
    label.htmlFor = "variable-" + i;
    label.textContent = name;
    const input = document.createElement("input");
    input.className = "form-control";
    input.id = label.htmlFor;
    input.maxLength = 2000;
    input.placeholder = "กรอก " + name;
    input.addEventListener("input", () => {
      values.set(name, input.value);
      output.value = source.replace(
        /\[([^\[\]\r\n]{1,80})\]/gu,
        (match, key) => values.get(key) || match,
      );
    });
    col.append(label, input);
    container.append(col);
  });
  document.getElementById("copy").addEventListener("click", async (event) => {
    const button = event.currentTarget;
    const status = document.getElementById("copy-status");
    button.disabled = true;
    try {
      if (navigator.clipboard && window.isSecureContext)
        await navigator.clipboard.writeText(output.value);
      else {
        output.focus();
        output.select();
        if (!document.execCommand("copy")) throw Error("clipboard");
      }
      status.textContent = "คัดลอกข้อความแล้ว";
      const data = new FormData();
      data.set("action", "copy");
      data.set("id", button.dataset.id);
      data.set("csrf", button.dataset.csrf);
      const response = await fetch(location.href, {
        method: "POST",
        body: data,
        credentials: "same-origin",
      });
      if (!response.ok)
        status.textContent =
          "คัดลอกแล้ว แต่บันทึกสถิติไม่สำเร็จ กรุณาโหลดหน้าใหม่";
    } catch (error) {
      status.textContent =
        "คัดลอกอัตโนมัติไม่สำเร็จ กรุณาเลือกข้อความแล้วคัดลอกด้วยตนเอง";
    } finally {
      button.disabled = false;
    }
  });
}
