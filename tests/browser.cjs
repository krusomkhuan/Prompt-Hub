const { chromium } = require("playwright");
const assert = require("node:assert/strict");
(async () => {
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.CHROMIUM_PATH || "/usr/bin/chromium",
    args: ["--no-sandbox"],
  });
  const context = await browser.newContext({
    permissions: ["clipboard-read", "clipboard-write"],
  });
  await context.route("**/*", (route) =>
    route.request().url().startsWith("http://127.0.0.1:8080/")
      ? route.continue()
      : route.abort(),
  );
  const page = await context.newPage();
  const errors = [];
  page.on("pageerror", (e) => errors.push(e.message));
  let checks = 0;
  function pass(name) {
    console.log("PASS", name);
    checks++;
  }
  for (const width of [390, 768, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto("http://127.0.0.1:8080/index.php", {
      waitUntil: "networkidle",
    });
    assert.ok(await page.locator("h1").isVisible());
    assert.equal(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
      true,
    );
    pass(`หน้าแรก responsive ไม่มี overflow ${width}px`);
    assert.equal(
      await page
        .locator("body")
        .evaluate((el) => getComputedStyle(el).fontFamily.includes("Sarabun")),
      true,
    );
    assert.equal(
      await page
        .locator("body")
        .evaluate((el) => getComputedStyle(el).marginTop),
      "0px",
    );
    pass(`โหลด stylesheet ${width}px`);
  }
  await page.setViewportSize({ width: 390, height: 844 });
  await page
    .getByRole("link", { name: /ดูรายละเอียด/ })
    .first()
    .click();
  const source = await page.locator("#original").textContent();
  const input = page.locator("#variables input").first();
  assert.ok(await input.count());
  await input.fill("<script>ไม่รัน</script> สินค้าทดสอบ");
  const value = await page.locator("#rendered").inputValue();
  assert.ok(value.includes("<script>ไม่รัน</script> สินค้าทดสอบ"));
  assert.equal(await page.locator("#original").textContent(), source);
  pass("แทนค่าตัวแปรโดยไม่เปลี่ยนต้นฉบับและไม่รัน HTML");
  await page.locator("#copy").click();
  await page.waitForFunction(() =>
    document
      .querySelector("#copy-status")
      .textContent.includes("คัดลอกข้อความแล้ว"),
  );
  assert.equal(
    await page.evaluate(() => navigator.clipboard.readText()),
    value,
  );
  pass("ปุ่มคัดลอกทำงานกับ clipboard จริง");
  assert.equal(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
    true,
  );
  pass("หน้ารายละเอียดบนมือถือไม่มี overflow");
  await page.screenshot({ path: "/tmp/prompt-hub-mobile.png", fullPage: true });
  assert.deepEqual(errors, []);
  pass("ไม่มี JavaScript runtime error");
  console.log(`TOTAL ${checks} passed`);
  await browser.close();
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
