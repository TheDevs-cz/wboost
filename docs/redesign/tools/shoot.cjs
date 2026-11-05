#!/usr/bin/env node
/*
 * Redesign visual check: logs into the local app and screenshots pages at
 * phone / tablet / desktop widths in dark and light theme.
 *
 *   node docs/redesign/tools/shoot.cjs <outDir> <path> [<path> …]
 *
 * Env:
 *   BASE       app URL (default http://localhost:18080)
 *   USER_EMAIL / USER_PASS   login (default redesign@test.cz / Redesign123)
 *   WIDTHS     comma list (default 390,820,1440)
 *   THEMES     comma list (default dark,light)
 *   FULL       "1" = full-page screenshots (default 1)
 *   PUPPETEER_DIR  directory containing node_modules/puppeteer-core
 *   CHROME     Chrome binary (default macOS Google Chrome)
 *   IMG_FROM / IMG_TO  rewrite request URLs starting with IMG_FROM to IMG_TO,
 *              e.g. IMG_FROM=http://localhost:19000 IMG_TO=https://img.wboost.cz
 *              shows real (public) uploads when the local DB is a prod copy
 *              but local Minio has no files
 *
 * Output: <outDir>/<slug>--<width>--<theme>.png and a console summary with
 * horizontal-overflow detection (document wider than the viewport = layout bug).
 */
const path = require('path');
const fs = require('fs');
const { createRequire } = require('module');

const PUP_DIR = process.env.PUPPETEER_DIR
  || '/private/tmp/claude-501/-Users-janmikes-www-brand-manuals/9299226c-106a-49cd-874c-c4325dd94b05/scratchpad/pp';
const puppeteer = createRequire(path.join(PUP_DIR, 'package.json'))('puppeteer-core');

const BASE = process.env.BASE || 'http://localhost:18080';
const EMAIL = process.env.USER_EMAIL || 'redesign@test.cz';
const PASS = process.env.USER_PASS || 'Redesign123';
const WIDTHS = (process.env.WIDTHS || '390,820,1440').split(',').map(Number);
const THEMES = (process.env.THEMES || 'dark,light').split(',');
const FULL = (process.env.FULL || '1') === '1';
const CHROME = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

const [outDir, ...paths] = process.argv.slice(2);
if (!outDir || !paths.length) { console.error('usage: shoot.cjs <outDir> <path>…'); process.exit(1); }
fs.mkdirSync(outDir, { recursive: true });

const slug = (p) => p.replace(/^\//, '').replace(/[^a-z0-9]+/gi, '_').slice(0, 80) || 'root';

(async () => {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox', '--hide-scrollbars'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900 });
  if (process.env.IMG_FROM && process.env.IMG_TO) {
    await page.setRequestInterception(true);
    page.on('request', (req) => {
      const u = req.url();
      if (!u.startsWith(process.env.IMG_FROM)) return req.continue();
      // continue({url}) cannot change the protocol (http -> https): fetch + respond.
      fetch(process.env.IMG_TO + u.slice(process.env.IMG_FROM.length))
        .then(async (r) => req.respond({ status: r.status, contentType: r.headers.get('content-type') || undefined, body: Buffer.from(await r.arrayBuffer()) }))
        .catch(() => req.abort());
    });
  }
  await page.goto(BASE + '/login', { waitUntil: 'networkidle2' });
  if (await page.$('input[name="_username"]')) {
    await page.type('input[name="_username"]', EMAIL);
    await page.type('input[name="_password"]', PASS);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('button[type="submit"], [type="submit"]')]);
  }
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e.message || e)));
  for (const p of paths) {
    for (const theme of THEMES) {
      for (const w of WIDTHS) {
        // isMobile:false on purpose — see memory "fill page on phones".
        await page.setViewport({ width: w, height: w < 600 ? 844 : 900, isMobile: false });
        await page.evaluateOnNewDocument((t) => { try { localStorage.setItem('wb-theme', t); } catch (e) {} }, theme);
        errors.length = 0;
        const res = await page.goto(BASE + p, { waitUntil: 'networkidle2', timeout: 60000 }).catch((e) => ({ status: () => 'ERR ' + e.message }));
        await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme);
        await page.addStyleTag({ content: '.sf-toolbar,.sf-minitoolbar{display:none!important}' }).catch(() => {});
        await new Promise((r) => setTimeout(r, 400));
        const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, title: document.title }));
        const file = path.join(outDir, `${slug(p)}--${w}--${theme}.png`);
        await page.screenshot({ path: file, fullPage: FULL });
        const overflow = m.sw > m.cw + 1 ? ` OVERFLOW(${m.sw}>${m.cw})` : '';
        console.log(`${res && res.status ? res.status() : '?'} ${p} ${w}px ${theme}${overflow}${errors.length ? ' JSERR: ' + errors.join(' | ') : ''} -> ${file}`);
      }
    }
  }
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
