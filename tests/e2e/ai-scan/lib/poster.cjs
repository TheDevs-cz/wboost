/*
 * The picture the e2e scripts upload: a small poster drawn by the browser itself (no binary in
 * the repository, nobody's material). It matches the design the fake scanner "recognises"
 * (lib/fake-scanner.cjs): 900 × 1200, a headline, a line, a photo place, a logo place, a strip.
 */
const POSTER_HTML = `<!doctype html><meta charset="utf-8"><style>
html,body{margin:0}body{width:900px;height:1200px;background:#f4f1ea;position:relative;font-family:Georgia,serif;overflow:hidden}
h1{position:absolute;left:80px;top:120px;margin:0;font-size:60px;line-height:90px;color:#c9112f;text-shadow:3px 4px 0 rgba(0,0,0,.18)}
p{position:absolute;left:80px;top:240px;margin:0;font-size:34px;line-height:50px;color:#111}
.foto{position:absolute;left:100px;top:400px;width:400px;height:300px;background:linear-gradient(135deg,#2f6f8f,#9fd3c7 60%,#f2d388)}
.logo{position:absolute;left:700px;top:60px;width:140px;height:60px;border-radius:30px;background:#12355b}
.dot{position:absolute;left:620px;top:820px;width:180px;height:120px;border-radius:50%;background:radial-gradient(circle,#f2a65a,#c8553d)}
.pruh{position:absolute;left:0;top:1000px;width:900px;height:200px;background:#c8102e}
</style><h1>Den otevřených dveří</h1><p>Sobota 14. června</p><div class="foto"></div><div class="logo"></div><div class="dot"></div><div class="pruh"></div>`;

/** Renders the poster with the given puppeteer browser → a PNG Buffer (`scale` < 1 makes a small one). */
async function posterPng(browser, { scale = 1, tint = null } = {}) {
    const page = await browser.newPage();
    await page.setViewport({ width: 900, height: 1200, deviceScaleFactor: scale });
    // `tint` changes the bytes (a fresh sha256 per run: an identical picture trips "failed twice").
    await page.setContent(POSTER_HTML + (tint ? `<div style="position:absolute;right:0;bottom:0;width:6px;height:6px;background:${tint}"></div>` : ''));
    const png = await page.screenshot({ type: 'png' });
    await page.close();
    return Buffer.from(png);
}

module.exports = { posterPng, POSTER_HTML };
