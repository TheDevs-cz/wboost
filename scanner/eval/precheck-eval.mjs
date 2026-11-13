#!/usr/bin/env node
// Pre-check confusion matrix: the Haiku pre-check alone on positives (the eval sets, calendar pages)
// and on negatives made here from them — a "photo of a print" (the design on a desk, turned, with
// uneven light and noise), two designs side by side, a blurred small copy, a plain photo, a blank
// picture, a page of text. One call at a time, on the plan.
//   node eval/precheck-eval.mjs [--runs 1] [--only <part of a case id>]   (`--only other-kind`: what is not the
//   expected kind, for both kinds; `--only non-design` / `--only pair`: charts, screenshots, documents and plain photographs
//   against the same photographs with a headline — results in eval/runs/_precheck/results.<only>.json)
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { tmpdir } from 'node:os';
import sharp from 'sharp';
import { precheck } from '../src/intake/precheck.mjs';
import { normalise } from '../src/intake/normalise.mjs';
import { definitionFiles } from '../src/drivers/file-job.mjs';
import { readDefinition } from '../src/protocol.mjs';
import { engine } from '../src/engine/index.mjs';
import { parseArgs } from '../bin/scanner.mjs';

delete process.env.ANTHROPIC_API_KEY; delete process.env.ANTHROPIC_AUTH_TOKEN;
const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(HERE, '..', '..');
const args = parseArgs(process.argv.slice(2));
const sets = JSON.parse(readFileSync(join(HERE, 'sets.json'), 'utf8'));
const out = join(HERE, 'runs', '_precheck'); mkdirSync(out, { recursive: true });
const p = (f) => resolve(REPO, f);
const T = (slug) => p(`docs/ai-scan/examples/templates/${slug}/truth.png`);

async function photoOfPrint(file, angle, tint) {
    const print = await sharp(file).resize({ width: 900 }).rotate(angle, { background: tint }).modulate({ brightness: 0.92 }).blur(0.6).toBuffer();
    const meta = await sharp(print).metadata();
    const W = meta.width + 500, H = meta.height + 400;
    const light = Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}"><defs><radialGradient id="g" cx="25%" cy="20%" r="90%"><stop offset="0" stop-color="#fff" stop-opacity="0.28"/><stop offset="1" stop-color="#000" stop-opacity="0.35"/></radialGradient></defs><rect width="100%" height="100%" fill="url(#g)"/></svg>`);
    const noise = await sharp({ create: { width: W, height: H, channels: 3, background: tint, noise: { type: 'gaussian', mean: 128, sigma: 14 } } }).png().toBuffer();
    return sharp({ create: { width: W, height: H, channels: 3, background: tint } }).composite([{ input: noise, blend: 'soft-light' }, { input: print, left: 260, top: 180 }, { input: light }]).jpeg({ quality: 78 }).toBuffer();
}
const sideBySide = async (a, b) => { const A = await sharp(a).resize({ height: 1000 }).toBuffer(), B = await sharp(b).resize({ height: 1000 }).toBuffer(); const wa = (await sharp(A).metadata()).width, wb = (await sharp(B).metadata()).width; return sharp({ create: { width: wa + wb + 120, height: 1100, channels: 3, background: '#e9e9e9' } }).composite([{ input: A, left: 40, top: 50 }, { input: B, left: wa + 80, top: 50 }]).png().toBuffer(); };
const textPage = () => sharp(Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="1240" height="1754"><rect width="100%" height="100%" fill="#fff"/>${Array.from({ length: 46 }, (_, i) => `<rect x="120" y="${140 + i * 33}" width="${i % 7 === 6 ? 520 : 1000}" height="9" fill="#333"/>`).join('')}</svg>`)).png().toBuffer();

const C = (slug) => p(`docs/ai-scan/examples/calendars/synthetic/${slug}.png`);
const stacked = async (a, b) => { const A = await sharp(a).resize({ width: 1200 }).toBuffer(), B = await sharp(b).resize({ width: 1200 }).toBuffer(); const ha = (await sharp(A).metadata()).height, hb = (await sharp(B).metadata()).height; return sharp({ create: { width: 1280, height: ha + hb + 120, channels: 3, background: '#e9e9e9' } }).composite([{ input: A, left: 40, top: 40 }, { input: B, left: 40, top: ha + 80 }]).png().toBuffer(); };
/** A drawn application window: side bar, tool bar, a table of rows — nothing a design or a calendar page has. */
const appScreenshot = () => sharp(Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="1000"><rect width="100%" height="100%" fill="#f3f4f6"/><rect width="1600" height="56" fill="#1f2937"/><circle cx="28" cy="28" r="8" fill="#ef4444"/><circle cx="52" cy="28" r="8" fill="#f59e0b"/><circle cx="76" cy="28" r="8" fill="#10b981"/><rect x="0" y="56" width="260" height="944" fill="#e5e7eb"/>${Array.from({ length: 9 }, (_, i) => `<rect x="24" y="${96 + i * 44}" width="${150 + (i % 3) * 20}" height="14" rx="3" fill="#9ca3af"/>`).join('')}<rect x="300" y="88" width="420" height="36" rx="6" fill="#fff" stroke="#d1d5db"/><rect x="1400" y="88" width="160" height="36" rx="6" fill="#2563eb"/>${Array.from({ length: 16 }, (_, i) => `<rect x="300" y="${160 + i * 48}" width="1260" height="40" fill="${i % 2 ? '#fff' : '#f9fafb'}" stroke="#e5e7eb"/><rect x="320" y="${174 + i * 48}" width="${180 + (i * 37) % 160}" height="12" fill="#374151"/><rect x="700" y="${174 + i * 48}" width="120" height="12" fill="#9ca3af"/><rect x="1000" y="${174 + i * 48}" width="80" height="12" fill="#9ca3af"/><rect x="1440" y="${170 + i * 48}" width="90" height="20" rx="10" fill="${i % 4 ? '#d1fae5' : '#fee2e2'}"/>`).join('')}</svg>`)).png().toBuffer();
/** A bar chart with axes. */
const chart = () => sharp(Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="1400" height="1000"><rect width="100%" height="100%" fill="#fff"/><line x1="140" y1="80" x2="140" y2="860" stroke="#111" stroke-width="3"/><line x1="140" y1="860" x2="1320" y2="860" stroke="#111" stroke-width="3"/>${Array.from({ length: 7 }, (_, i) => `<line x1="140" y1="${860 - (i + 1) * 100}" x2="1320" y2="${860 - (i + 1) * 100}" stroke="#ddd"/>`).join('')}${[420, 610, 380, 700, 520, 260, 640, 480].map((h, i) => `<rect x="${190 + i * 140}" y="${860 - h}" width="90" height="${h}" fill="${i % 2 ? '#2563eb' : '#60a5fa'}"/>`).join('')}</svg>`)).png().toBuffer();

// ---- non-designs a TEMPLATE scan must not take, and the designs that look most like them (2026-11-13; `--only non-design`,
//      `--only pair`). The photographs are public pictures fetched once into eval/runs/_precheck/photos/ (git-ignored; a missing
//      file skips its cases): a family on a beach, a cat, food, a waterfall, a city, a tower, a dog, and two crops of client pages.
const PH = (name) => join(out, 'photos', name);
const xml = (t) => String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;');
const svgPng = (w, h, body) => sharp(Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" font-family="Helvetica, Arial, sans-serif">${body}</svg>`)).png().toBuffer();
/** A photograph with typography set over it — the design that looks most like a plain photo. */
async function poster(file, { w = 1080, h = 1350, overlay }) {
    const photo = await sharp(file).resize({ width: w, height: h, fit: 'cover' }).toBuffer();
    return sharp(photo).composite([{ input: Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" font-family="Helvetica, Arial, sans-serif">${overlay}</svg>`) }]).png().toBuffer();
}
const LOREM = 'Vážení občané, dovolujeme si vás informovat o plánované uzavírce místní komunikace v ulici Hlavní, která proběhne v termínu od 3. do 14. března. Objízdná trasa povede ulicemi Školní a Nádražní. Po dobu uzavírky bude zastávka autobusu přemístěna k obecnímu úřadu. Děkujeme za pochopení a trpělivost.'.split(' ');
const wrap = (words, n) => { const lines = []; let cur = ''; for (const w of words) { if ((cur + ' ' + w).length > n) { lines.push(cur); cur = w; } else cur = cur ? `${cur} ${w}` : w; } if (cur) lines.push(cur); return lines; };
/** A page of a document with real running text: a letter. */
const letter = () => { const para = wrap([...LOREM, ...LOREM, ...LOREM], 92); return svgPng(1240, 1754, `<rect width="100%" height="100%" fill="#fff"/><text x="120" y="150" font-size="22" fill="#222">Obecní úřad Horní Lhota</text><text x="120" y="182" font-size="18" fill="#555">Hlavní 12, 739 00 Horní Lhota</text><text x="820" y="300" font-size="18" fill="#222">V Horní Lhotě dne 24. 2. 2026</text><text x="120" y="400" font-size="24" font-weight="bold" fill="#111">Věc: Oznámení o uzavírce komunikace</text>${para.map((l, i) => `<text x="120" y="${470 + i * 34 + Math.floor(i / 6) * 22}" font-size="20" fill="#222">${xml(l)}</text>`).join('')}<text x="820" y="1560" font-size="20" fill="#222">Ing. Jan Novák, starosta</text>`); };
/** An invoice: a table of items, sums, an address block. */
const invoice = () => svgPng(1240, 1754, `<rect width="100%" height="100%" fill="#fff"/><text x="100" y="140" font-size="40" font-weight="bold" fill="#111">Faktura č. 2026-0147</text><text x="100" y="230" font-size="18" fill="#333">Dodavatel: Tiskárna Dvořák s. r. o., Nádražní 5, Ostrava · IČ 12345678</text><text x="100" y="262" font-size="18" fill="#333">Odběratel: Obec Horní Lhota, Hlavní 12 · IČ 87654321</text><text x="100" y="294" font-size="18" fill="#333">Datum vystavení: 2. 3. 2026 · Splatnost: 16. 3. 2026</text><rect x="100" y="360" width="1040" height="44" fill="#eee"/><text x="116" y="389" font-size="18" font-weight="bold">Položka</text><text x="700" y="389" font-size="18" font-weight="bold">Množství</text><text x="860" y="389" font-size="18" font-weight="bold">Cena/ks</text><text x="1020" y="389" font-size="18" font-weight="bold">Celkem</text>${[['Stolní kalendář 2027', 400, 62], ['Plakát A2', 50, 38], ['Leták A5', 2000, 1.9], ['Grafické práce (hod.)', 6, 850], ['Doprava', 1, 350]].map(([n, q, c], i) => `<line x1="100" y1="${448 + i * 44}" x2="1140" y2="${448 + i * 44}" stroke="#ccc"/><text x="116" y="${434 + i * 44}" font-size="18">${n}</text><text x="700" y="${434 + i * 44}" font-size="18">${q}</text><text x="860" y="${434 + i * 44}" font-size="18">${c} Kč</text><text x="1020" y="${434 + i * 44}" font-size="18">${Math.round(q * c)} Kč</text>`).join('')}<text x="820" y="720" font-size="24" font-weight="bold">K úhradě: 37 050 Kč</text><text x="100" y="1640" font-size="16" fill="#666">Společnost je zapsána v obchodním rejstříku vedeném Krajským soudem v Ostravě.</text>`);
/** A spreadsheet window: column letters, row numbers, cells of numbers. */
const spreadsheet = () => svgPng(1600, 1000, `<rect width="100%" height="100%" fill="#fff"/><rect width="1600" height="70" fill="#1d6f42"/><text x="24" y="44" font-size="22" fill="#fff">Rozpočet 2026.xlsx</text><rect y="70" width="1600" height="40" fill="#f3f3f3"/><text x="20" y="97" font-size="16" fill="#333">Soubor   Úpravy   Zobrazit   Vložit   Formát   Data   Nástroje</text>${Array.from({ length: 11 }, (_, c) => `<rect x="${60 + c * 140}" y="110" width="140" height="30" fill="#e8e8e8" stroke="#bbb"/><text x="${122 + c * 140}" y="131" font-size="15" fill="#444">${'ABCDEFGHIJK'[c]}</text>`).join('')}${Array.from({ length: 28 }, (_, r) => `<rect x="0" y="${140 + r * 30}" width="60" height="30" fill="#e8e8e8" stroke="#bbb"/><text x="22" y="${161 + r * 30}" font-size="14" fill="#444">${r + 1}</text>${Array.from({ length: 11 }, (_, c) => `<rect x="${60 + c * 140}" y="${140 + r * 30}" width="140" height="30" fill="#fff" stroke="#ddd"/>${c === 0 ? `<text x="${66}" y="${161 + r * 30}" font-size="14">${['Příjmy', 'Výdaje', 'Mzdy', 'Energie', 'Opravy', 'Kultura', 'Sport'][r % 7]}</text>` : (r * 7 + c * 3) % 5 ? `<text x="${190 + c * 140}" y="${161 + r * 30}" font-size="14" text-anchor="end">${((r * 7919 + c * 104729) % 90000 + 1000).toLocaleString('cs-CZ')}</text>` : ''}`).join('')}`).join('')}`);
/** A web page in a browser: tabs, an address bar, navigation, an article with a side column. */
const webPage = () => svgPng(1600, 1000, `<rect width="100%" height="100%" fill="#fff"/><rect width="1600" height="44" fill="#dee1e6"/><rect x="12" y="8" width="240" height="36" rx="8" fill="#fff"/><text x="28" y="32" font-size="15" fill="#333">Aktuality – Obec Horní Lhota</text><rect y="44" width="1600" height="46" fill="#fff" stroke="#ddd"/><rect x="130" y="52" width="1100" height="30" rx="15" fill="#f1f3f4"/><text x="150" y="73" font-size="15" fill="#333">https://www.hornilhota.example/aktuality/uzavirka-komunikace</text><text x="30" y="74" font-size="20" fill="#666">←  →  ⟳</text><rect y="90" width="1600" height="64" fill="#1e3a5f"/><text x="60" y="131" font-size="24" fill="#fff" font-weight="bold">Obec Horní Lhota</text><text x="700" y="130" font-size="17" fill="#cfe">Úřad    Aktuality    Úřední deska    Kontakty    Hledat</text><text x="60" y="230" font-size="34" font-weight="bold" fill="#111">Uzavírka komunikace v ulici Hlavní</text><text x="60" y="266" font-size="15" fill="#777">Zveřejněno 24. 2. 2026 · Aktuality</text>${wrap([...LOREM, ...LOREM, ...LOREM], 96).map((l, i) => `<text x="60" y="${320 + i * 30}" font-size="18" fill="#222">${xml(l)}</text>`).join('')}<rect x="1180" y="200" width="360" height="420" fill="#f5f7fa" stroke="#dde"/><text x="1200" y="240" font-size="20" font-weight="bold">Další aktuality</text>${['Svoz odpadu v březnu', 'Zápis do 1. ročníku', 'Obecní ples', 'Zasedání zastupitelstva', 'Očkování psů'].map((t, i) => `<text x="1200" y="${290 + i * 60}" font-size="17" fill="#1a56db">${t}</text><text x="1200" y="${312 + i * 60}" font-size="13" fill="#888">${10 + i}. 2. 2026</text>`).join('')}`);
/** A phone screen: status bar, a chat. */
const phoneChat = () => svgPng(1080, 1920, `<rect width="100%" height="100%" fill="#ece5dd"/><rect width="1080" height="70" fill="#075e54"/><text x="40" y="46" font-size="28" fill="#fff">9:41</text><text x="900" y="46" font-size="26" fill="#fff">5G  87 %</text><rect y="70" width="1080" height="130" fill="#128c7e"/><circle cx="110" cy="135" r="42" fill="#cfd8dc"/><text x="180" y="128" font-size="36" fill="#fff">Maminka</text><text x="180" y="170" font-size="24" fill="#d0ece7">online</text>${[['Ahoj, kdy přijedete?', 0], ['V sobotu kolem druhé', 1], ['Super, upeču koláč', 0], ['Vezmeme i děti a psa', 1], ['Tak to se těším!', 0], ['My taky, pa', 1]].map(([t, me], i) => `<rect x="${me ? 420 : 40}" y="${260 + i * 150}" width="620" height="100" rx="18" fill="${me ? '#dcf8c6' : '#fff'}"/><text x="${me ? 450 : 70}" y="${322 + i * 150}" font-size="32" fill="#111">${t}</text>`).join('')}<rect y="1780" width="1080" height="140" fill="#f0f0f0"/><rect x="30" y="1805" width="850" height="90" rx="45" fill="#fff"/><text x="70" y="1862" font-size="30" fill="#999">Zpráva</text><circle cx="980" cy="1850" r="48" fill="#128c7e"/>`);
/** A line chart with a title, axis labels and a legend. */
const lineChart = () => { const pts = (seed) => Array.from({ length: 12 }, (_, i) => `${200 + i * 90},${760 - ((i * seed * 37) % 300 + i * 22)}`).join(' '); return svgPng(1400, 1000, `<rect width="100%" height="100%" fill="#fff"/><text x="700" y="70" font-size="34" text-anchor="middle" fill="#111">Návštěvnost webu 2025</text><line x1="160" y1="120" x2="160" y2="800" stroke="#333" stroke-width="2"/><line x1="160" y1="800" x2="1280" y2="800" stroke="#333" stroke-width="2"/>${Array.from({ length: 6 }, (_, i) => `<line x1="160" y1="${800 - (i + 1) * 110}" x2="1280" y2="${800 - (i + 1) * 110}" stroke="#e5e5e5"/><text x="145" y="${806 - (i + 1) * 110}" font-size="16" text-anchor="end" fill="#555">${(i + 1) * 2000}</text>`).join('')}${['led', 'úno', 'bře', 'dub', 'kvě', 'čvn', 'čvc', 'srp', 'zář', 'říj', 'lis', 'pro'].map((m, i) => `<text x="${200 + i * 90}" y="830" font-size="16" text-anchor="middle" fill="#555">${m}</text>`).join('')}<polyline points="${pts(3)}" fill="none" stroke="#2563eb" stroke-width="4"/><polyline points="${pts(5)}" fill="none" stroke="#f59e0b" stroke-width="4"/><rect x="1000" y="140" width="22" height="6" fill="#2563eb"/><text x="1032" y="150" font-size="16">Návštěvy</text><rect x="1000" y="170" width="22" height="6" fill="#f59e0b"/><text x="1032" y="180" font-size="16">Noví uživatelé</text><text x="700" y="900" font-size="18" text-anchor="middle" fill="#555">měsíc</text>`); };
/** A pie chart with labels. */
const pieChart = () => { const parts = [[0.38, '#2563eb', 'Mzdy 38 %'], [0.22, '#f59e0b', 'Investice 22 %'], [0.17, '#10b981', 'Školství 17 %'], [0.13, '#ef4444', 'Doprava 13 %'], [0.10, '#8b5cf6', 'Ostatní 10 %']]; let a = -Math.PI / 2; const arcs = parts.map(([share, colour]) => { const b = a + share * 2 * Math.PI; const p = (t) => `${600 + 330 * Math.cos(t)},${520 + 330 * Math.sin(t)}`; const d = `M600,520 L${p(a)} A330,330 0 ${share > 0.5 ? 1 : 0} 1 ${p(b)} Z`; a = b; return `<path d="${d}" fill="${colour}" stroke="#fff" stroke-width="3"/>`; }).join(''); return svgPng(1400, 1000, `<rect width="100%" height="100%" fill="#fff"/><text x="700" y="80" font-size="34" text-anchor="middle" fill="#111">Rozpočet obce 2026 – výdaje</text>${arcs}${parts.map(([, colour, label], i) => `<rect x="1020" y="${330 + i * 56}" width="26" height="26" fill="${colour}"/><text x="1060" y="${351 + i * 56}" font-size="22" fill="#222">${label}</text>`).join('')}`); };
/** A chart as ONE PART of a composed post (headline, brand band, logo mark) — a design. */
const infographicPost = () => svgPng(1080, 1080, `<rect width="100%" height="100%" fill="#0f2d4a"/><rect x="0" y="0" width="1080" height="18" fill="#f2c14e"/><text x="80" y="170" font-size="84" font-weight="bold" fill="#fff">+38 %</text><text x="80" y="250" font-size="44" font-weight="bold" fill="#f2c14e">více návštěvníků knihovny</text><text x="80" y="310" font-size="28" fill="#cfe0ee">Děkujeme, že čtete s námi. Rok 2025 v číslech.</text>${[220, 300, 270, 380, 460].map((v, i) => `<rect x="${110 + i * 170}" y="${880 - v}" width="110" height="${v}" rx="10" fill="${i === 4 ? '#f2c14e' : '#3d6b94'}"/><text x="${165 + i * 170}" y="930" font-size="26" text-anchor="middle" fill="#cfe0ee">${2021 + i}</text>`).join('')}<circle cx="960" cy="1000" r="44" fill="#f2c14e"/><text x="960" y="1012" font-size="30" font-weight="bold" text-anchor="middle" fill="#0f2d4a">MK</text><text x="80" y="1020" font-size="26" fill="#fff">Městská knihovna Horní Lhota</text>`);
const band = (w, y, h, colour, o = 0.82) => `<rect x="0" y="${y}" width="${w}" height="${h}" fill="${colour}" fill-opacity="${o}"/>`;
const NON_DESIGN = [
    ...[['family', 'wm-Family_jump.jpg'], ['cat', 'wm-Felis_catus-cat_on_snow.jpg'], ['food', 'wm-Good_Food_Display_-_NCI_Visuals_Online.jpg'], ['waterfall', 'wm-Hopetoun_falls.jpg'], ['city', 'wm-Prague_skyline_view.jpg'], ['flower', 'wm-Sunflower_from_Silesia2.jpg'], ['tower', 'wm-Tour_Eiffel_Wikimedia_Commons.jpg'], ['dog', 'dog.jpg'], ['dog-2', 'dog2.jpg'], ['snapshot-tractors', 'snap-tractors.jpg']]
        .map(([id, f]) => ({ id: `non-design/photo-${id}`, kind: 'template', expect: 'not_expected_kind', file: PH(f) })),
    { id: 'non-design/photo-snapshot-kids', kind: 'template', expect: 'not_expected_kind', needs: p('docs/ai-scan/examples/calendars/chotebuz-2026-leden.png'), make: () => sharp(p('docs/ai-scan/examples/calendars/chotebuz-2026-leden.png')).extract({ left: 0, top: 110, width: 930, height: 900 }).jpeg({ quality: 88 }).toBuffer() },
    { id: 'non-design/chart-line', kind: 'template', expect: 'not_expected_kind', make: lineChart },
    { id: 'non-design/chart-pie', kind: 'template', expect: 'not_expected_kind', make: pieChart },
    { id: 'non-design/chart-bar', kind: 'template', expect: 'not_expected_kind', make: chart },
    { id: 'non-design/screenshot-app-list', kind: 'template', expect: 'not_expected_kind', file: p('docs/redesign/reference/screenshots/10-templates-list.jpg') },
    { id: 'non-design/screenshot-app-stats', kind: 'template', expect: 'not_expected_kind', file: p('docs/redesign/reference/screenshots/34-stats-system.jpg') },
    { id: 'non-design/screenshot-app-editor', kind: 'template', expect: 'not_expected_kind', file: p('docs/redesign/reference/screenshots/20-template-editor.jpg') },
    { id: 'non-design/screenshot-web-page', kind: 'template', expect: 'not_expected_kind', make: webPage },
    { id: 'non-design/screenshot-phone-chat', kind: 'template', expect: 'not_expected_kind', make: phoneChat },
    { id: 'non-design/screenshot-spreadsheet', kind: 'template', expect: 'not_expected_kind', make: spreadsheet },
    { id: 'non-design/document-letter', kind: 'template', expect: 'not_expected_kind', make: letter },
    { id: 'non-design/document-invoice', kind: 'template', expect: 'not_expected_kind', make: invoice },
    // ---- the pairs: the SAME photographs with typography set over them are designs and must pass
    { id: 'pair/poster-family-headline', kind: 'template', expect: 'pass', needs: PH('wm-Family_jump.jpg'), make: () => poster(PH('wm-Family_jump.jpg'), { overlay: `<text x="540" y="1180" font-size="120" font-weight="bold" text-anchor="middle" fill="#fff" stroke="#000" stroke-opacity="0.25" stroke-width="2">LÉTO 2026</text>` }) },
    { id: 'pair/poster-waterfall-headline-logo', kind: 'template', expect: 'pass', needs: PH('wm-Hopetoun_falls.jpg'), make: () => poster(PH('wm-Hopetoun_falls.jpg'), { overlay: `${band(1080, 980, 370, '#12372a')}<text x="80" y="1110" font-size="86" font-weight="bold" fill="#fff">Výlet k vodopádům</text><text x="80" y="1190" font-size="40" fill="#cfe8d8">sobota 16. května · sraz v 8:00 u školy</text><circle cx="960" cy="1260" r="50" fill="#f2c14e"/><text x="960" y="1274" font-size="34" font-weight="bold" text-anchor="middle" fill="#12372a">KČT</text>` }) },
    { id: 'pair/poster-food-price', kind: 'template', expect: 'pass', needs: PH('wm-Hamburger_sandwich.jpg'), make: () => poster(PH('wm-Hamburger_sandwich.jpg'), { w: 1080, h: 1080, overlay: `<circle cx="880" cy="200" r="150" fill="#d62828"/><text x="880" y="190" font-size="76" font-weight="bold" text-anchor="middle" fill="#fff">149 Kč</text><text x="880" y="250" font-size="34" text-anchor="middle" fill="#fff">denní menu</text><text x="60" y="1010" font-size="92" font-weight="bold" fill="#fff">Burger týdne</text>` }) },
    { id: 'pair/poster-city-one-line', kind: 'template', expect: 'pass', needs: PH('wm-Prague_skyline_view.jpg'), make: () => poster(PH('wm-Prague_skyline_view.jpg'), { w: 1200, h: 630, overlay: `<text x="60" y="560" font-size="64" font-weight="bold" fill="#fff">Dny evropského dědictví</text>` }) },
    { id: 'pair/poster-tower-title-date', kind: 'template', expect: 'pass', needs: PH('wm-Tour_Eiffel_Wikimedia_Commons.jpg'), make: () => poster(PH('wm-Tour_Eiffel_Wikimedia_Commons.jpg'), { overlay: `${band(1080, 0, 260, '#ffffff', 0.9)}<text x="540" y="130" font-size="96" font-weight="bold" text-anchor="middle" fill="#1d3557">PAŘÍŽ</text><text x="540" y="210" font-size="42" text-anchor="middle" fill="#e63946">poznávací zájezd · 12.–16. 9.</text>` }) },
    { id: 'pair/poster-cat-quote', kind: 'template', expect: 'pass', needs: PH('wm-Felis_catus-cat_on_snow.jpg'), make: () => poster(PH('wm-Felis_catus-cat_on_snow.jpg'), { w: 1080, h: 1080, overlay: `<text x="540" y="140" font-size="64" font-weight="bold" text-anchor="middle" fill="#fff">Hledáme nový domov</text><rect x="340" y="930" width="400" height="90" rx="45" fill="#e63946"/><text x="540" y="990" font-size="40" font-weight="bold" text-anchor="middle" fill="#fff">Adoptuj mě</text>` }) },
    { id: 'pair/post-with-chart', kind: 'template', expect: 'pass', make: infographicPost },
].filter((c) => { const need = c.needs ?? c.file; if (need && !existsSync(need)) { console.error(`${c.id}: skipped — ${need} is not on this machine`); return false; } return true; });

const cases = [
    ...['easy', 'photo', 'hard'].flatMap((g) => sets[g].map((it) => ({ id: it.id, kind: 'template', expect: 'pass', file: p(it.image) }))),
    ...['s01', 's02', 's04'].map((s) => ({ id: `calendar-${s}`, kind: 'calendar', expect: 'pass', file: p(`docs/ai-scan/examples/calendars/synthetic/${s}.png`) })),
    { id: 'calendar-chotebuz', kind: 'calendar', expect: 'pass', file: p('docs/ai-scan/examples/calendars/chotebuz-2026-leden.png') },
    { id: 'design-as-calendar', kind: 'calendar', expect: 'not_expected_kind', file: T('argumenty') },
    { id: 'photo-of-print-1', kind: 'template', expect: 'photo_of_print', make: () => photoOfPrint(T('argumenty'), 7, '#6b5a48') },
    { id: 'photo-of-print-2', kind: 'template', expect: 'photo_of_print', make: () => photoOfPrint(p('docs/ai-scan/examples/hard/inputs/B2-sleva.png'), -11, '#3c4046') },
    { id: 'photo-of-print-3', kind: 'template', expect: 'photo_of_print', make: () => photoOfPrint(p('docs/ai-scan/examples/hard/inputs/B5-otevreno.png'), 4, '#b9b2a6') },
    { id: 'several-1', kind: 'template', expect: 'several_designs', make: () => sideBySide(T('argumenty'), T('vedeli-jste')) },
    { id: 'several-2', kind: 'template', expect: 'several_designs', make: () => sideBySide(p('docs/ai-scan/examples/hard/inputs/B1-koncert.png'), p('docs/ai-scan/examples/hard/inputs/B2-sleva.png')) },
    { id: 'blurry-1', kind: 'template', expect: 'blurry_or_small', make: () => sharp(T('argumenty')).resize({ width: 640 }).blur(5).toBuffer() },
    { id: 'blurry-2', kind: 'template', expect: 'blurry_or_small', make: async () => sharp(await sharp(p('docs/ai-scan/examples/hard/inputs/B3-letak-a4.png')).resize({ width: 300 }).jpeg({ quality: 20 }).toBuffer()).resize({ width: 900 }).png().toBuffer() },
    { id: 'plain-photo', kind: 'template', expect: 'not_expected_kind', file: p('docs/ai-scan/prototypes/t1-agent/inputs/photo.jpg') },
    { id: 'blank', kind: 'template', expect: 'not_expected_kind', make: () => sharp({ create: { width: 1200, height: 1200, channels: 3, background: '#ffffff' } }).png().toBuffer() },
    { id: 'text-page', kind: 'template', expect: 'not_expected_kind', make: textPage },
    // ---- what is NOT the expected kind, for BOTH kinds (2026-11-13; `--only other-kind` runs these alone)
    // a calendar page sent as a TEMPLATE passes on purpose: it is also a design a template can be built from (precheck.mjs decide())
    { id: 'other-kind/calendar-as-template', kind: 'template', expect: 'pass', file: C('s03') },
    { id: 'other-kind/app-screenshot-as-template', kind: 'template', expect: 'not_expected_kind', make: appScreenshot },
    { id: 'other-kind/chart-as-template', kind: 'template', expect: 'not_expected_kind', make: chart },
    { id: 'other-kind/photo-as-calendar', kind: 'calendar', expect: 'not_expected_kind', file: p('docs/ai-scan/prototypes/t1-agent/inputs/photo.jpg') },
    { id: 'other-kind/blank-as-calendar', kind: 'calendar', expect: 'not_expected_kind', make: () => sharp({ create: { width: 1600, height: 750, channels: 3, background: '#ffffff' } }).png().toBuffer() },
    { id: 'other-kind/text-page-as-calendar', kind: 'calendar', expect: 'not_expected_kind', make: textPage },
    { id: 'other-kind/app-screenshot-as-calendar', kind: 'calendar', expect: 'not_expected_kind', make: appScreenshot },
    { id: 'other-kind/poster-as-calendar', kind: 'calendar', expect: 'not_expected_kind', file: p('docs/ai-scan/examples/hard/inputs/B1-koncert.png') },
    { id: 'other-kind/two-calendar-pages', kind: 'calendar', expect: 'several_designs', make: () => stacked(C('s03'), C('s08')) },
    { id: 'other-kind/photo-of-calendar', kind: 'calendar', expect: 'photo_of_print', make: () => photoOfPrint(C('s05'), 5, '#5d5348') },
    ...NON_DESIGN,
    { id: 'other-kind/blurry-calendar', kind: 'calendar', expect: 'blurry_or_small', make: () => sharp(C('s04')).resize({ width: 700 }).blur(4).toBuffer() },
].filter((c) => !args.only || c.id.includes(args.only));
const { definition, files } = readDefinition(definitionFiles());
const run = engine('sdk').run;
const rows = [];
for (let r = 1; r <= Number(args.runs ?? 1); r++) for (const c of cases) {
    const bytes = c.make ? await c.make() : readFileSync(c.file);
    if (c.make) writeFileSync(join(out, `${c.id.replace('/', '-')}.${bytes[0] === 0xff ? 'jpg' : 'png'}`), bytes);
    const n = await normalise(bytes);
    if (!n.ok) { rows.push({ id: c.id, run: r, expect: c.expect, got: `normalise:${n.stop}`, ok: n.stop === c.expect }); console.error(c.id, 'normalise', n.stop, n.detail); continue; }
    const t0 = Date.now();
    const pc = await precheck({ run, definition, files, preview: n.preview, kind: c.kind, scratchDir: tmpdir() });
    const got = pc.decision.pass ? 'pass' : pc.decision.stop ?? (pc.decision.refused ? 'refused' : 'error');
    rows.push({ id: c.id, run: r, kind: c.kind, expect: c.expect, verdict: pc.verdict, confidence: pc.confidence, got, ok: got === c.expect, ms: Date.now() - t0, apiMs: pc.engine.apiMs, costUsd: pc.engine.usage?.costUsd ?? null, error: pc.decision.error ?? null });
    console.error(`${c.id} r${r}: expect ${c.expect} → ${pc.verdict} (${pc.confidence}) ${got === c.expect ? 'ok' : 'WRONG'} ${Date.now() - t0} ms $${pc.engine.usage?.costUsd}`);
    if (pc.engine.facts?.rateLimit?.status === 'rejected') { console.error('plan limit — stopping'); break; }
}
writeFileSync(join(out, args.only ? `results.${String(args.only).replace(/[^a-z0-9-]/gi, '')}.json` : 'results.json'), JSON.stringify(rows, null, 1));
const ms = rows.filter((x) => x.ms).map((x) => x.ms).sort((a, b) => a - b);
console.log(JSON.stringify({ n: rows.length, correct: rows.filter((x) => x.ok).length, wrong: rows.filter((x) => !x.ok).map((x) => `${x.id}: ${x.expect} → ${x.got}`), msMedian: ms[ms.length >> 1], msMax: ms.at(-1), costMean: rows.reduce((a, x) => a + (x.costUsd ?? 0), 0) / rows.length }, null, 1));
