# WBoost — marketing landing page · design brief for Claude Code + Pencil (pen.dev)

> **How to use this file.** Paste everything below the horizontal rule into a Claude Code session that has the Pencil MCP server connected (the Pencil desktop app must be running). CLI alternative, from the repo root:
>
> ```bash
> pen --out designs/wboost-landing.pen \
>     --prompt "Design the WBoost marketing landing page exactly per the attached brief. Desktop 1440 first, then mobile 390." \
>     --prompt-file docs/marketing/landing-page-pencil-prompt.md \
>     --export designs/wboost-landing.png --export-scale 2
> ```
>
> Items marked **[POTVRDIT]** are claims or assets the founders must confirm before the page goes live. Nothing else on the page is invented — every feature below exists in the product today unless it carries a "Dokončujeme" badge.

---

## 0. Your task

Design the public marketing landing page for **WBoost** (https://wboost.cz) in Pencil.

**The bar.** The audience is graphic designers and marketers — people who judge design for a living and close a templated page in the first scroll. The page has to be beautiful in the way a design studio's own site is beautiful: confident typography, deliberate whitespace, a visual signature that belongs only to this product, craft in the details. Treat "looks like a SaaS template" as a failed build, not a style choice. If a section could be dropped into any other startup's site unchanged, redo it.

Deliverables:

- `designs/wboost-landing.pen` with three top-level frames: **Desktop 1440** (the full page, every section in §5), **Mobile 390** (the same content, single column, same components) and **Components** (buttons, chips, cards, the product-UI fragments you build).
- PNG exports of both artboards at 2×: `designs/wboost-landing-desktop.png`, `designs/wboost-landing-mobile.png`.
- `designs/wboost-landing-notes.md`: the decisions you made, plus every **[POTVRDIT]** item copied out so the founders can tick them off.

Workflow in Pencil:

1. `get_app_state({ include_schema: true, include_canvas_design: true, include_scripts_and_shaders: false })`.
2. `get_guidelines()` — load the guide for marketing websites / landing pages. Browse the styles and load the ONE closest to "editorial, typographic, product-led SaaS". Then override its colours and fonts with the tokens in §3. Do not ship the style's stock palette or stock font pairing.
3. Build the desktop page section by section in the order of §5, as auto-layout frames on an 8 px grid. After every section validate and screenshot it; fix overflow, overlap and contrast before moving on. Only then build mobile from the same components.
4. **Self-critique pass, mandatory.** Screenshot the finished desktop page and review it against §6 as a hostile art director would: Which sections could be from a template? Where is the visual signature missing? Where does the type hierarchy go flat? Which product fragment looks like a wireframe? Fix every finding, then re-screenshot. Do this twice.
5. Write the notes file last, including what the critique pass changed.

What is fixed: the Czech copy, the brand tokens, the section list and order, the link targets, the "not stock" rules in §6. What is yours: composition, grid, type scale, spacing rhythm, how you draw the product-UI fragments, motion hints. Treat §6 and §7 as direction, not as a pixel spec.

---

## 1. What WBoost is (context for you, not copy)

WBoost is a Czech SaaS for graphic designers and the marketing teams they serve. One **project** = one client brand. Inside a project:

- **Brand manuál** — upload the logo as SVG once; WBoost generates a complete, paginated brand manual as a live web document with a public, login-free URL (`/nahled-manualu/{project}/{manual}`): every colour variant of every logo type (horizontal, vertical, with claim, symbol; light/dark/one-colour/black/white background — colour remapping is automatic), protection zone, minimum sizes, colour palette with HEX + CMYK + Pantone + RAL, primary/secondary fonts with live specimens, up to 11 mockup-page layouts, downloadable print files. The client downloads any logo as SVG/PNG/JPG in the exact approved colour treatment straight from the manual page. Two manual types: brand manual and the lighter logo manual.
- **Šablony (templates)** — a Fabric.js canvas editor for the designer: layers panel, snapping, rulers, undo, 7 vector shapes, rich text, lists and checklists, image placeholders, "smart text areas" (containers that reflow vertically when a filled headline is longer; nested; hidden items collapse). Per text input the designer sets: locked / hidable / max length / forced uppercase / sample value / allowed fonts / allowed colours. Per image slot: may move / resize / rotate / hide, and which gallery folders feed it. Dimensions: Instagram presets 1:1 (1080×1080), 4:5 (1080×1350), 9:16 (1080×1920), or physical mm/cm rasterised at 300 DPI (A4 = 2480×3508 px), one-click A5/A4/A3.
- **Synchronizované šablony (template groups)** — one design across several dimensions. Move or restyle an element in one format and it propagates to all of them; adding or removing an element always reaches every format, so the set can never diverge.
- **Vyplnění a export (fill page)** — the non-designer's surface. Every editable element is a dashed box drawn over a live preview; click it, a popover opens with the field (textarea / WYSIWYG / checklist / font select); pick photos from the gallery; hide what is hideable; the preview is the SAME server render (Gotenberg + headless Chromium) as the final file, so what you see is byte-for-byte what you download. Export PNG, or "Stáhnout vše (ZIP)" for all formats of a group at once. **Historie exportů**: the last 30 fills are stored and can be re-loaded with one click.
- **Facebook / Instagram** — connect a Page and an IG business account, fill a template, publish directly from the fill page with a caption. Built and tested; awaiting Meta app review, so the page must say "Dokončujeme" (finishing).
- **Galerie** — one shared image library per project: nested folders, trash bin with 7-day retention, iPhone HEIC photos transcoded automatically, file name + pixel size on every tile, 10 MB cap.
- **Fonty** — upload the brand's licensed typefaces (TTF/OTF/WOFF, families with faces on a weight axis); they render identically in editor, preview, export and manual. Usage scan shows which templates use which face; rename rewrites every reference.
- **E-mailové podpisy** — one HTML signature template per brand (drag-and-drop editor), one variant per employee, vCard QR code, test e-mail to up to 5 recipients, copy/download HTML.
- **REST API** — OAuth2 client-credentials, OpenAPI docs at `/api/docs`. List templates with their full input contract (ids, limits, pixel frames), export PNG with data from any system, upload placeholder pictures. A football club's back-office already generates match graphics from its own match database through it.
- **MCP server** — `https://wboost.cz/_mcp`. Connect Claude, ChatGPT, Codex or any MCP client. OAuth 2.1 with PKCE and dynamic client registration (nothing to paste — the client registers itself and the user approves on a Czech consent screen), or a personal access token for headless agents. Four scopes: `templates:read`, `templates:export`, `templates:design`, `gallery:write`. Nine tools: `get_context` (who am I, real font face strings, brand colours, dimensions in use), `find_templates`, `describe_variant`, `render_variant` (free, lossy preview, uncounted), `export_variant` (the counted lossless PNG), `list_gallery`, `upload_image`, `preview_design`, `set_design` (an AI can author a whole template from a design DSL and is shown what it would overwrite before saving). One-command install for Claude Code: `claude plugin marketplace add TheDevs-cz/wboost` then `claude plugin install wboost@wboost`. An in-app guide lives at `/ai`.
- **Roles and sharing** — Uživatel → Designer → Administrátor. A designer owns projects and shares them read-only with the client's people, who can fill, export and publish but never redesign. Registration is invite-gated: the public `/registration` page collects an e-mail, the founders approve and send an invitation link.
- Also in the app: a "Jídelníčky" weekly-menu module (restaurants/canteens, with an approval workflow and PDF) and a "Kalendáře" module that is announced as coming soon. Neither belongs on this landing page.

Founders' stance, to be felt across the page: the two co-founders built WBoost for their own daily work and still use it every day on their own clients. They see dogfooding as the only way to keep the product genuinely good — the story section carries this explicitly (§5.9); the rest of the page should feel like it was made by people who use the tool, not by a marketing agency.

Everything is in Czech. There is no dark mode in the app. The app shell is a dark left sidebar (`#313a46`) with indigo accents.

## 2. Who we sell to

Evidence from real usage (13 projects, 31 templates, 40 variants at the time of writing):

- **Persona A — the anchor: a solo brand designer or small studio with a book of local clients.** Builds the brand manual, then a library of templates for each recurring piece the client needs, sets the guardrails, invites the client and stops being the bottleneck for "same poster, new date". Pain: trivial edits eat the week; letting the client into Canva destroys the brand.
- **Persona B — the marketing team or communications officer at a municipality, sports club, clinic or SMB.** Not a designer, often part-time in comms. Publishes a steady drip of structured content: water outage, council session, doctor's holiday, home match, summer campaign. Needs a fill-in-the-blanks form that cannot be got wrong, on any device, that produces an on-brand image every time and lands on Facebook.
- **Persona C — the developer or agency integrating brand graphics into another system** (club back-office, CMS, intranet) via the API, or automating everything with an AI agent through MCP.

Real template names in production (safe to use as example content because they are generic civic/club pieces): "Odstávka vody", "Odstávka energie", "Zasedání zastupitelstva", "Změna pracovní doby pošty", "Setkání seniorů", "Fotbal – Domácí utkání", "Volby", "Začátek školy", "Letní kampaň". Real fields: "Datum", "Nadpis", "Čas utkání", "Domácí", "Hostující tým", "Popisek fotky".

Existing customers include several Moravian-Silesian municipalities, a football club and local companies. **[POTVRDIT]** whether any of them may be named or shown as logos; until then, use an anonymous reference strip (see §5.2).

## 3. Brand system (tokens)

The product has no marketing identity yet — only the app's admin-theme palette and one logo file. Use these tokens; do not add a second accent.

| Token | Value | Use |
|---|---|---|
| Ink (dark surface) | `#313a46` | Dark sections, nav on dark, footer, the logo's home |
| Paper (light surface) | `#fafbfe` | Page background (not pure white) |
| Primary / CTA | `#727cf5` | Buttons, links, the "editable" dashed-outline motif. Nothing else. |
| Primary hover | `#5b66e0` | Derived, darker |
| Slate | `#8491a0` | Secondary text on dark, the `w` of the wordmark, borders on dark |
| Logo accent | `#7075b5` | The tiny tab on the wordmark only |
| Body text on light | `#313a46` (headlines), `#6c757d` (body) | |
| Border on light | `#dee2e6` | Hairlines, card outlines |
| Success | `#0acf97` | "Hotovo / Publikováno" states inside product fragments, never decoration |
| Danger | `#fa5c7c` | Only inside product fragments (an overflow warning) |

Typography:

- **Body and UI fragments: Nunito** (the app's face; keeps the landing page and the app feeling like one thing). Weights 400/600/700.
- **Headlines: a display face with character that is NOT Inter, Roboto, Arial, Poppins, Montserrat, Open Sans or Nunito.** Two directions you may pick from: (a) a sharp contemporary grotesk with tight tracking and a large x-height (e.g. Bricolage Grotesque, Instrument Sans, Schibsted Grotesk), or (b) a warm editorial serif for the H1 and section titles only (e.g. Fraunces, Instrument Serif) against Nunito body. Pick one and commit; state the choice in the notes.
- Headline scale on desktop: H1 ≈ 64–72 px, section titles ≈ 40–44 px, body 18 px, captions 14 px. Mobile: H1 ≈ 40 px, body 17 px.

Logo:

- The only asset is `assets/images/logo-lg.svg`: lowercase wordmark **wboost**, the `w` in slate `#8491a0` with a small rounded tab in `#7075b5`, the letters `boost` in **white**. It therefore only works on a dark surface. `assets/images/logo-icon.svg` is a `w`+`b` monogram; `public/android-icon-192x192.png` is the same monogram in white on an indigo rounded square (the app icon).
- Import the SVG if Pencil lets you; otherwise recreate it: lowercase "wboost" in a heavy geometric sans, `w` in slate, rest white, the tab in `#7075b5`.
- The page needs a **light-surface lockup that does not exist yet**: same wordmark with `boost` in ink `#313a46` and the `w` in slate. Propose it in the Components frame and flag it **[POTVRDIT]**.

Spacing and shape: 8 px grid, 12-column desktop grid with a 1200 px content width (sections may go full-bleed), 8 px radius on buttons and inputs, 16 px on cards, one hairline border style. No drop shadows beyond a single soft ambient shadow on floating popovers.

## 4. Language, voice, links

- **All copy is Czech**, with proper diacritics, sentence case, no exclamation marks, no emoji. Product names stay as the app spells them: WBoost, Brand manuál, Šablony, Galerie, Fonty, E-maily. Developer names stay English: MCP, API, OAuth.
- Voice: a designer talking to a designer. Concrete, dry, confident. Short sentences. Numbers where they earn their place. Never "revoluční", "inovativní", "all-in-one", "unlock".
- Every primary CTA reads **Vyzkoušet zdarma** and links to `https://wboost.cz/registration`. Under the hero CTA the microcopy is *"Zdarma a bez platební karty. Přístup vám pošleme e-mailem."* — the registration page today asks only for an e-mail and the founders approve manually, and the page must not promise anything faster. **[POTVRDIT]** any response-time promise ("do 24 hodin").
- Secondary links: **Přihlásit se** → `https://wboost.cz/login`; **Dokumentace API** → `https://wboost.cz/api/docs`; **AI a MCP** → `https://wboost.cz/ai`.

## 5. Page structure and copy

Section order is fixed. Layout hints are suggestions. All copy below is final unless marked as an option.

### 5.0 Navigation (sticky)

Light bar on paper, 64 px. Left: the light-surface wordmark. Centre: anchor links **Funkce · Jak to funguje · Pro koho · AI asistenti · Příběh · Cena**. Right: text link **Přihlásit se**, primary button **Vyzkoušet zdarma**. On scroll the bar gains a hairline. Mobile: wordmark + CTA + a menu toggle.

### 5.1 Hero

Purpose: say what it is, who it is for, and get the click.

- Eyebrow: **Pro grafiky, studia a marketingové týmy**
- H1 (primary): **Navrhněte jednou. Klient si vyexportuje sám.**
  - Alternatives you may test in the notes, not on the page: *"Grafika, kterou děláte každý týden znovu, se odteď dělá sama."* / *"Šablony, které klient vyplní sám a značku přitom nerozbije."*
- Subhead: **WBoost je nástroj pro grafiky a marketingové týmy: brand manuál, šablony s jasnými pravidly, export do všech formátů jedním kliknutím a publikace rovnou na Facebook a Instagram. Grafik přestane být úzkým hrdlem, marketing přestane čekat.**
- Primary CTA: **Vyzkoušet zdarma** · Secondary (ghost): **Jak to funguje** (anchor). Microcopy under the buttons as in §4.
- Visual (right or below, roughly 55 % of the width): a **product composition drawn in Pencil, not a screenshot and not a laptop mockup**. Show the fill page: a 1:1 Instagram graphic titled "ODSTÁVKA VODY" with the town-noticeboard look, three dashed indigo outlines over the editable parts ("Nadpis", "Datum", "Fotografie") each with a tiny pencil + eye icon cluster and a field tag, one open popover with a text field reading "čtvrtek 12. 9. 2026, 8:00–14:00" and a **Uložit** button. Beside it, three smaller thumbnails of the same design in **1:1 · 4:5 · 9:16** with a "Synchronizováno" chip, and one toast "Publikováno na Facebook" with a success dot. Everything in the brand palette; the fake client graphic may use one extra brand-of-the-client colour (a deep green or brick red) so the composition does not read as all-indigo.

### 5.2 Proof strip

A single quiet row under the hero, hairline above and below.

Left: **Vzniklo pro jednoho grafika. Šetří mu desítky hodin měsíčně.**
Right: four chips: **1 návrh → 1:1 · 4:5 · 9:16 · A4 · A5 · A3** · **Tisk ve 300 DPI** · **Historie posledních 30 exportů** · **Ovládání z Claude, ChatGPT i Codexu**

Below it a reference row: six equal grey placeholder slots captioned **Používají obce, sportovní kluby i firmy** — **[POTVRDIT]** real names/logos before launch; do not invent any.

### 5.3 Problém — "Zní vám to povědomě?"

Three columns, no icons in coloured circles. Use a large numeral or a tiny product fragment per column instead.

1. **Každé úterý stejný plakát.** Změna otevírací doby, odstávka vody, domácí zápas. Jiné datum, stejná grafika – a stejně to musí přes grafika.
2. **Klient si to udělal sám. V Canvě.** Jiné písmo, roztažené logo, barva „přibližně" taková. Značka, na které jste pracovali měsíce, je pryč jedním exportem.
3. **Jeden příspěvek, tři formáty. Pak ještě A4.** Čtverec, portrét, story a plakát na nástěnku. Každou drobnou úpravu čtyřikrát.

### 5.4 Jak to funguje — "Tři kroky. Potom už jen vyplňování."

Numbered, horizontal on desktop, each step with a small product fragment.

1. **Grafik navrhne šablonu.** V editoru s vrstvami, chytrými textovými oblastmi, tvary a písmy značky. Jednou. — fragment: the left panel "Přidat text · Přidat obrázek · Přidat tvar · Přidat zaškrtávací seznam" and a "Vrstvy" list (Pozadí, Obrázek 1, Nadpis, Datum).
2. **Nastaví pravidla.** Co jde měnit a co je zamčené, maximální délka textu, povolené řezy písma a barvy, ze kterých složek galerie se smí vybírat fotka. — fragment: a text-input popover with toggles "Uzamčeno · Lze skrýt · Verzálky · Max. délka 48" and a font allowlist with two ticked faces.
3. **Marketing vyplní a exportuje.** Klikne do náhledu, přepíše text, vybere fotku. PNG, ZIP se všemi formáty, nebo rovnou příspěvek na Facebook či Instagram. Vždy přesně podle návrhu. — fragment: the export button group "Export do PNG · Stáhnout vše (ZIP) · Facebook · Instagram".

### 5.5 Showcase band — "Jeden návrh. Každý formát."

Full-bleed **dark (ink) section**. Left: title and two sentences. Right or below: a wide triptych + one print sheet.

- Title: **Jeden návrh. Každý formát.**
- Copy: **Posunete nadpis ve čtverci – posune se i v portrétu, ve story a na A4. Přidáte prvek – přibude všude. Sada formátů se nikdy nerozjede, protože ji WBoost hlídá za vás. Tiskové rozměry vykreslí ve 300 DPI ze stejného návrhu jako příspěvek na Instagram.**
- Visual: the same "FOTBAL – DOMÁCÍ UTKÁNÍ" design in 1:1, 4:5, 9:16 side by side with format chips, plus an A4 sheet at a smaller scale labelled "A4 · 2480 × 3508 px · 300 DPI". A thin indigo connector line runs through the moved headline in all four to show the propagation.

### 5.6 Moduly — "Všechno, co značka potřebuje. Na jednom místě." (id: funkce)

A bento grid on paper: two large tiles, six regular. Each tile: title, 2–3 sentences, and a product fragment or a single strong typographic detail. No icon grid.

- **Brand manuál** (large) — Nahrajte logo v SVG a WBoost vygeneruje kompletní manuál: barevné varianty loga, ochrannou zónu, minimální rozměry, paletu s HEX, CMYK, Pantone i RAL, písma a mockupy. Klientovi pošlete veřejný odkaz, který je vždy aktuální – a stáhne si z něj logo v SVG, PNG i JPG ve správné barevné variantě. — fragment: a manual page "Primární barevná paleta" with three swatches showing HEX/CMYK/Pantone/RAL lines, and a page counter "07".
- **Editor šablon** (large) — Vrstvy, přichytávání, pravítka, tvary, formátovaný text, seznamy i zaškrtávací seznamy. Chytré textové oblasti se samy přeskládají, když je nadpis delší. Vše, co znáte z Figmy nebo Canvy – jen s pravidly pro toho, kdo přijde po vás. — fragment: a canvas with a dashed container zone, a floating mini-toolbar and a layers panel.
- **Vyplnění a export** — Kliknete do náhledu, přepíšete, hotovo. Náhled je tentýž render jako výsledný soubor, takže žádná překvapení. PNG, ZIP se všemi formáty a kdykoliv návrat k posledním 30 exportům.
- **Facebook a Instagram** · chip **Dokončujeme** — Propojte stránku a účet, vyplňte šablonu a publikujte rovnou z WBoostu. Bez stahování, bez přeposílání souborů.
- **Galerie** — Sdílená knihovna se složkami a košem. Fotky z iPhonu se převedou automaticky, u každého obrázku vidíte název a rozměr v pixelech.
- **Fonty značky** — Nahrajte licencovaná písma a vykreslí se stejně v editoru, náhledu, exportu i manuálu. Vidíte, které šablony je používají.
- **E-mailové podpisy** — Jedna šablona podpisu, varianta pro každého člověka, QR kód s vizitkou. Zkušební e-mail na jedno kliknutí.
- **API pro vaše systémy** — OAuth2 REST API: vypište šablony, vyplňte je daty z vlastního systému a stáhněte PNG. Fotbalový klub tak generuje grafiku k zápasům přímo ze své databáze.

Under the grid, one centred primary CTA **Vyzkoušet zdarma**.

### 5.7 AI asistenti — "Řekněte to svému asistentovi." (id: ai)

Dark section again, or a paper section with a dark chat panel. This is the differentiator: give it room.

- Title: **Řekněte to svému asistentovi.**
- Subhead: **WBoost má vlastní MCP server. Připojte Claude, ChatGPT, Codex nebo jakýkoliv nástroj s podporou MCP a ovládejte celý systém konverzací – s opravdovými písmy, barvami a galerií vaší značky, ne s vymyšlenými.**
- Visual: a chat transcript drawn as a product fragment, with tool-call chips between the turns:
  - User: **Vyplň šablonu Odstávka vody na čtvrtek 12. 9., 8:00–14:00, ulice Nádražní. Chci čtverec i story.**
  - chips: `find_templates` · `describe_variant` · `render_variant ×2` · `export_variant ×2`
  - Assistant: **Hotovo, oba formáty jsou v příloze. Text se vešel do vymezené oblasti, použil jsem Rubik Bold z manuálu.** followed by two small thumbnails.
- Four short bullets, set as a list with hairlines, not cards:
  - **Připojení jedním příkazem, bez kopírování tokenů.** OAuth 2.1, klient se zaregistruje sám, vy jen odsouhlasíte oprávnění.
  - **Oprávnění po vrstvách.** Čtení, export, návrh, zápis do galerie – token dostane jen to, co má.
  - **AI umí i navrhnout celou šablonu.** A před uložením ukáže, co by v původním návrhu přepsala.
  - **Náhledy neomezeně, počítá se jen finální export.**
- Code block, monospace, on ink:
  ```
  claude plugin marketplace add TheDevs-cz/wboost
  claude plugin install wboost@wboost
  ```
- Small link: **Průvodce připojením →** (`https://wboost.cz/ai`)

### 5.8 Pro koho — "Pro koho je WBoost" (id: pro-koho)

Three cards with a strong title, an outcome sentence and a 3-item "co dostanete" list. Different tint per card is fine; still one accent colour.

- **Grafik na volné noze nebo studio** — Předejte klientovi šablony místo nekonečných drobných úprav. Značka zůstane vaše, čas taky. · Brand manuál s veřejným odkazem · Šablony s pravidly, které klient nepřekročí · Jeden projekt na klienta, sdílení jen pro čtení
- **Marketingový tým – obce, kluby, firmy** — Oznámení, akce, zápasy, kampaně. Vyplníte, exportujete, publikujete. Bez grafického vzdělání a bez čekání na grafika. · Vyplňování přímo v náhledu · Všechny formáty jedním kliknutím · Facebook a Instagram rovnou z aplikace
- **Vývojáři a integrace** — REST API a MCP server. Generujte grafiku ze svých dat nebo ji nechte na AI agentovi. · OpenAPI dokumentace · Export PNG z libovolného systému · 9 MCP nástrojů, 4 úrovně oprávnění

### 5.9 Příběh — "Vzniklo z jedné konkrétní potřeby" (id: pribeh)

A calmer, editorial section on paper (or a warm paper tint), two columns: copy left, two founder cards right with photo placeholders (square, 1:1, grey) captioned with name and role.

- Title: **Vzniklo z jedné konkrétní potřeby.**
- Copy: **Jan Mikeš postavil WBoost pro Lukáše Rejdu – grafika, který spravuje značky svých klientů a každý týden řešil ty samé drobné úpravy: nové datum, jiná fotka, stejný plakát. Dnes mu WBoost šetří desítky hodin měsíčně a jeho klienti si grafiku vyplňují sami. Fungovalo to tak dobře, že jsme z něj udělali produkt a sdílíme ho s každým, kdo řeší totéž. Pořád ho ale stavíme hlavně pro sebe – a to je jediný způsob, jak ho udržet opravdu dobrý.**
- Founder cards: **Lukáš Rejda** — grafik, spoluzakladatel · **Jan Mikeš** — vývojář, spoluzakladatel. **[POTVRDIT]** photos and exact titles.
- Pull-quote block under the copy, set large in the headline face, with a hairline and the two names as attribution. This is the "why we love it" message and must be kept: **„Postavili jsme WBoost pro sebe a používáme ho každý den při vlastní práci. Jen tak jsme si jistí, že děláme produkt, který opravdu funguje – protože každou chybu potkáme dřív než vy."**
- Under the quote, three short facts in a row: **Používáme ho denně na vlastní klienty** · **Každou funkci nejdřív zkoušíme na sobě** · **Co nás štve, opravíme – nečekáme na tiket**

### 5.10 Cena — "Žádný ceník. Nejdřív vyzkoušejte." (id: cena)

One wide card on ink, or a paper card with an ink border. No pricing table, no tiers, no "from X Kč".

- Title: **Žádný ceník. Nejdřív vyzkoušejte.**
- Copy: **Nemáme tabulku s balíčky. Vyzkoušejte WBoost zdarma a bez omezení – s vlastními klienty, vlastními šablonami, vlastními písmy. Když vám bude dávat smysl, domluvíme cenu podle toho, jak ho používáte. A nebojte, není to drahé.**
- Three facts as a horizontal list: **Kompletní funkce od prvního dne** · **Bez platební karty** · **Cena po dohodě, podle reálného využití**
- CTA: **Vyzkoušet zdarma** + microcopy from §4.

### 5.11 FAQ — "Časté otázky"

Accordion, first item open. Two columns on desktop.

- **Musím umět pracovat s grafickým editorem?** Ne. Šablonu navrhne grafik, vy jen vyplňujete texty a vybíráte fotky. Náhled vidíte průběžně a soubor vypadá přesně jako náhled.
- **Jak se dostanu k účtu?** Klikněte na Vyzkoušet zdarma a zadejte e-mail. Přístup vám pošleme e-mailem – registrace schvalujeme ručně, aby měl každý osobní start.
- **Kdo vidí brand manuál?** Kdokoliv, komu pošlete jeho veřejný odkaz. Bez přihlašování a vždy v aktuální verzi.
- **Můžu použít vlastní písma?** Ano. Nahrajte licencovaná písma značky a vykreslí se ve všech výstupech stejně.
- **Jaké formáty umí export?** PNG v přesných rozměrech šablony: sociální formáty 1:1, 4:5 a 9:16 i tiskové rozměry v milimetrech ve 300 DPI. Všechny formáty najednou v ZIPu.
- **Funguje publikace na Facebook a Instagram?** Integrace je hotová a právě prochází schválením ze strany Meta. **[POTVRDIT]** wording at launch.
- **Co znamená ovládání přes AI?** WBoost je MCP server. Připojíte Claude, ChatGPT nebo jiného asistenta a zadáváte úkoly slovy – vyplnit, vyexportovat, nahrát fotku, navrhnout šablonu.
- **Je WBoost v češtině?** Ano, celý.

### 5.12 Final CTA

Full-bleed ink band. **Přestaňte dělat stejný plakát podesáté.** Subline: **Vyzkoušejte WBoost zdarma, s vlastními klienty a šablonami.** Primary CTA **Vyzkoušet zdarma**, ghost **Přihlásit se**.

### 5.13 Footer

Ink. Wordmark (white version), one-line description **Brand manuály, šablony a export pro grafiky a marketingové týmy.** Columns: **Produkt** (Funkce, Jak to funguje, Cena, Přihlásit se, Registrace) · **Pro vývojáře** (Dokumentace API, AI a MCP server, GitHub: TheDevs-cz/wboost) · **Kontakt** (**[POTVRDIT]** contact e-mail, company legal name and address — none exists in the codebase; use `kontakt@wboost.cz` as a placeholder and mark it). Bottom line: **© 2026 TheDevs · wboost.cz** and placeholder links **Ochrana osobních údajů · Obchodní podmínky** **[POTVRDIT]**.

## 6. Visual direction — how to not look stock

Aim: a page that could only be WBoost's, read as designed by a designer for designers. The product's own UI gives you the motifs; use them instead of generic SaaS decoration.

Reference points for tone only, never for copying: the product-led restraint of Linear and Vercel, the typographic confidence of Pitch and Raycast, the editorial rhythm of a good studio portfolio. WBoost should sit next to those, in its own palette and with its own motifs.

Craft details the audience will notice:

- Optical alignment over mathematical — hang punctuation and the Czech quotation marks „ " on large quotes, align icons to the x-height, balance headline line breaks by hand.
- Tabular figures in chips and numbers (1080 × 1080, 300 DPI, 2480 × 3508 px), the multiplication sign ×, real en dashes –, non-breaking spaces after one-letter Czech prepositions (v, k, s, z, a, i, o, u).
- Icons drawn in one consistent stroke weight (1.5 px at 20 px), from one set, or none at all; never mixed sets, never filled-blob icons.
- Product fragments at true UI density: 13–14 px labels, 32 px controls, 1 px hairlines, states shown (one selected layer, one focused field, one disabled toggle).
- A single signature element repeated with intent — recommended: the dashed editable outline with its icon cluster, appearing in the hero, once in the modules grid and once as a tiny detail in the footer.
- Whitespace as a design element: section paddings of 120–160 px on desktop, not 64; let one section be almost empty around a single big line.
- Consistent corner radii, one shadow, one hairline colour per surface — no visual noise from mixed tokens.

Do:

- **Product-native motifs as the visual language.** The dashed indigo "editable" outline with its pencil + eye icon cluster and field tag; format chips (1:1 · 4:5 · 9:16 · A4); the layers list; the synchronized triptych; the tool-call chips in the chat; the manual's page numbers "07". Repeat two or three of these across sections so the page has a signature.
- **Typography carries the page.** Big, tightly set headlines; generous measure for body; a visible type hierarchy per section. Let some headlines break across lines on purpose.
- **Rhythm through contrast.** Alternate paper and ink sections (hero paper → showcase ink → modules paper → AI ink → story paper → price ink). Vary content widths: some sections at 1200 px, some full-bleed, the story narrower.
- **Asymmetry.** Hero text left and the composition overlapping the right edge; the showcase triptych bleeding off-canvas; do not centre every block.
- **Draw the UI fragments as real interface** in Nunito at 13–14 px with proper hierarchy, hairlines and one accent, in the app's actual palette (dark sidebar `#313a46`, indigo primary, paper background). They must look like a mature product, not a wireframe.
- **Example content in Czech and from the real domain** (municipal notices, a home match, a summer campaign). Invent town-sounding but fictional names if you need one (e.g. "Obec Horní Lhota").
- One optional warm tint (a paper with a hint of cream, or the story section on `#f4f1ea`) is allowed to keep the page from feeling clinical.

Don't:

- No purple-blue gradient blobs, no glassmorphism, no floating 3D illustrations, no abstract geometric hero art.
- No laptop, phone or browser-window mockups with screenshots inside; no stock photography; no people pointing at screens.
- No icon-in-a-coloured-circle feature grids in threes; no emoji; no oversized rounded pills as section eyebrows.
- No lorem ipsum, no English placeholder copy, no fake customer logos, no fake testimonials, no fake numbers (only the ones in §5.2 and the module copy).
- No pricing table, no "od 990 Kč", no star ratings, no "trusted by 10 000 teams".
- No second accent colour. Indigo is for actions and the editable motif; success green only inside product fragments.

## 7. UX rules

- One primary CTA per viewport; the primary button style is used only for **Vyzkoušet zdarma**.
- Primary CTA visible above the fold on desktop and mobile without scrolling.
- Sticky nav with anchors; every section in §5 with an id gets one.
- Contrast AA at minimum: body text on paper ≥ 4.5:1, white on indigo buttons at 16 px+ bold, slate on ink only for captions.
- Body text ≥ 16 px; line length 60–75 characters; touch targets ≥ 44 px on mobile.
- Define hover and focus states for buttons, links and accordion rows in the Components frame.
- Mobile 390: single column, hero composition simplified to the one fill-page graphic with two dashed boxes, the bento collapses to a stacked list, the triptych becomes a horizontal scroll strip, the chat panel keeps its width with 16 px gutters, the FAQ single column.
- Motion hints (annotate in the notes, do not animate): the dashed outlines fade in one by one on hero load; the triptych headline nudge on scroll into view; nothing else moves.

## 8. Facts you may state, and facts you must not invent

Safe to state (true in the product today): public manual URL without login; logo download in SVG/PNG/JPG per colour variant; HEX/CMYK/Pantone/RAL; 11 mockup layouts; 1:1, 4:5, 9:16 presets; mm/cm at 300 DPI, A4 = 2480×3508 px; one-click A5/A4/A3; synchronized templates propagate edits and structure to every format; preview = export render; PNG and ZIP export; history of the last 30 exports; gallery folders, trash with 7-day retention, HEIC conversion, 10 MB per upload; custom fonts with usage scan; e-mail signatures with vCard QR and test mail to 5 recipients; OAuth2 REST API with OpenAPI docs; MCP server with 9 tools and 4 scopes, OAuth 2.1 + PKCE, Claude Code plugin install in two commands; Czech-only UI; roles Uživatel/Designer/Administrátor; read-only sharing with clients; invite-gated registration.

Must be marked **[POTVRDIT]**, never asserted: customer names or logos; any response-time promise on registration; the exact founder titles and photos; "není to drahé" is fine, any number is not; Facebook/Instagram as "live" (it is "Dokončujeme"); a contact e-mail; a legal entity, address, terms and privacy pages; any hosting/GDPR/data-location claim.

## 9. Definition of done

- Both artboards complete, every section from §5 present in order, all copy verbatim, all CTAs linking to `https://wboost.cz/registration`.
- No text overflow, no overlapping frames, consistent 8 px spacing, AA contrast verified on the ink sections.
- Components frame contains: primary/secondary/ghost buttons with states, chip, bento tile, FAQ row, the dashed editable-outline motif, both wordmark lockups (white on ink, ink on paper).
- PNG exports at 2× and the notes file with your typeface choice, the one style you loaded from Pencil, and the complete **[POTVRDIT]** list.
