# WBoost landing page — design notes

Built in Pencil from `docs/marketing/landing-page-pencil-prompt.md`.
Source file: **`~/pencil/wboost.pen`**.

---

## 1. Deliverables and node ids

The document root holds exactly three frames, nothing else:

| Node id | Frame | Size |
|---|---|---|
| `hgMNG` | **Components** | 1440 × 1600 |
| `gCoLB` | **Desktop 1440** | 1440 × 11 970 |
| `VZfy3` | **Mobile 390** | 390 × 17 255 |
| `icEU7` | **Legal 1440** | 1440 × 2 585 |
| `IwrPI` | **Legal 390** | 390 × 2 753 |

Pencil names exported files by node id, so:

```
designs/exports/gCoLB.png   → Desktop 1440
designs/exports/VZfy3.png   → Mobile 390
designs/exports/hgMNG.png   → Components
designs/exports/sections/*  → one PNG per section, true 2× (ids below)
```

**Export-resolution caveat.** `Export([...], "png", ..., {scale: 2})` was run as the
brief specifies, but Pencil caps a PNG at 8192 px on the long edge. The desktop page
is 10 960 pt tall and the mobile page 15 999 pt, so both were downscaled to fit
(`gCoLB.png` 1077 × 8192 ≈ 0.75×, `VZfy3.png` 199 × 8192 ≈ 0.51×). The per-section
files in `designs/exports/sections/` are genuine 2× and are the ones to use for
anything printed or zoomed.

### Section id → name

**Desktop 1440** — `u4Zjn` Nav · `n6Aw5` Hero · `zqEXF` Proof Strip · `P1Eg7` Problém ·
`Bk0P9` Jak to funguje · `QhocG` Showcase · `bUQEv` Moduly (id `funkce`) ·
`kMamX` AI asistenti (id `ai`) · `w6cZ86` Pro koho · **`JXdwa` Reference [SKRYTELNÉ]** ·
`ykKvW` Příběh · `pDzy2` Cena · `UrO4h` FAQ · `PvjiT` Final CTA · `Z3W59c` Footer

**Mobile 390** — `mIYAE` Nav · `t8tOB3` Hero · `Zxu2U` Proof · `D6XJwl` Problém ·
`NxSDs` Jak to funguje · `L5YCyc` Showcase · `GxfFi` Moduly · `uNcik` AI ·
`ejuJR` Pro koho · **`k0oMjU` Reference [SKRYTELNÉ]** · `xkdEQ` Příběh · `rnP46` Cena ·
`rbX7q` FAQ · `NMVqe` Final CTA · `L7H4v` Footer

---

## 2. Typography — the choice and why

Three voices, as the brief asked, each carrying one of the three stories the page tells:

| Role | Face | Why |
|---|---|---|
| Display — H1, section titles, pull-quote, page numbers, founder monograms | **Instrument Serif** (400) | The designer's voice. A warm editorial serif is the one thing that cannot be mistaken for a SaaS template — every competitor in this space sets headlines in a geometric grotesk. At 62 px it carries the hero without needing weight, and its single upright cut forces the hierarchy to come from scale and space rather than from fake boldness. |
| Body, UI fragments, buttons, card titles | **Nunito** (400/600/700/800) | Mandated by the brief — it is the app's own face, so the page and the product read as one thing. It also does the work inside the product fragments at 11–14 px, which is where most of the page's craft lives. |
| Labels, chips, field tags, numbers, code, install commands | **IBM Plex Mono** (400) | The developer's voice, and the reason the API/MCP half of the page belongs to the same object as the brand-manual half. Also gives tabular figures for `1080 × 1080`, `2480 × 3508 px`, `300 DPI`. |

Scale — desktop: H1 62 / hero-scale section titles 52 (Showcase, AI, Cena) / section
titles 44–46 / tile titles 20–26 / body 15.5–18 / captions and mono labels 10–12.
Mobile: H1 40 / section titles 34 / body 16–17.

**Not used, deliberately:** Inter, Roboto, Arial, Poppins, Montserrat, Open Sans, Geist.

---

## 3. Pencil guidelines loaded

- `get_guidelines({category: "guide", name: "Landing Page"})` — followed for conversion
  intent, one CTA, one alignment axis per section, paper/ink rhythm, 50–75-character
  measure. Its instruction to show *people in the outcome state* was overridden by §0 of
  the brief in favour of product-native compositions.
- `get_guidelines({category: "style", name: "Split Inverse Showcase"})` with
  `colorPalette: "Minimal Ink"`, `roundness: "Basic Roundness"`, `elevation: "Gentle Lift"`,
  `decorativeImagery: ""`, `headings: "Newsreader"`, `body: "Geist"`, `captions: "Geist Mono"`.
  Taken as **reference only**: its "product interface as structural content, no decorative
  imagery" identity is the archetype this page uses, but none of its values shipped — not
  the `#0066FF` accent, not the Newsreader/Geist pairing, not the pill buttons. Document
  variables were defined from the brand tokens in §3 of the brief instead.
- One idea borrowed from *Editorial Landscape Stack*: the three typographic voices above.

Document variables (all defined in the `.pen`): `ink #313a46`, `ink-deep #20262f` (footer only),
`paper #fafbfe`, `paper-alt #f1f3f7`, `primary #727cf5`, `primary-hover #5b66e0`,
`primary-tint #eef0fe`, `slate #8491a0`, `logo-accent #7075b5`, `text-head #313a46`,
`text-body #6c757d`, `border #dee2e6`, `border-alt #dde2e9`, `border-ink #8491a03d`, `success #0acf97`,
`danger #fa5c7c`, `demo-green #1e4a3b`, `demo-brick #9c3a24`, plus the three font names.

---

## 4. The visual system

- **Grid**: 8 px base, 1200 px content inside 1440, 120 px side margins. Sections
  124–150 px of vertical padding; some sections full-bleed. Mobile: 20 px gutters
  (16 px for the AI section so the chat panel keeps its width, per §7).
- **Shape**: 8 px radius on buttons, inputs and fragment chrome; 16 px on cards and
  tiles; one hairline per surface (`#dee2e6` on paper, `#8491a03d` on ink). One soft
  ambient shadow, used only on floating popovers, the hero artwork and the toast.
- **Rhythm**: paper ×5 → **ink** (Showcase) → paper (Moduly) → **ink** (AI) → paper
  (Pro koho) → **ink** (Reference) → **recessed grey `#f1f3f7`** (Příběh) → **ink** (Cena) →
  paper (FAQ) → **ink** (Final CTA) → **deeper ink `#20262f`** (Footer).
  **No two dark sections are ever adjacent** — a hard rule, not a preference. Two bands of
  the same ink meeting have no visible boundary at all: the break disappears and they read as
  one enormous block with a hole in the middle (the two paddings meeting). It bit twice — the
  Reference section was first inserted one index too low and landed against *Cena*, and the
  Final CTA and Footer were both put on `#28303a`.

  **The order must be valid in BOTH states**, because *Reference* ships hidden. Verified with
  it disabled: the sequence becomes … Pro koho (paper) → **Příběh (warm)** → Cena (ink) …, so
  no dark pair appears — but it does put the page's two lightest surfaces next to each other
  (`#fafbfe` → `#f1f3f7`), which is the softest seam on the page. *Příběh* therefore carries a
  permanent `#dde2e9` top hairline: irrelevant when Reference is shown (ink above it already
  does the work), and the thing that keeps the boundary legible when it is not.

  Two seams are marked rather than relying on contrast alone:

  | Seam | Treatment |
  |---|---|
  | Final CTA → Footer | tonal step `#313a46` → `#20262f` **and** `#ffffff1a` top hairline |
  | Pro koho → Příběh (whenever Reference is hidden) | `#dde2e9` top hairline on *Příběh* |

  Every other boundary is a paper↔ink contrast change and needs nothing. The light opening
  run (Problém → Jak to funguje) is deliberately left unmarked: each section leads with an
  eyebrow and a 44 px serif title, and the content blocks are dense enough to chunk on their
  own — adding hairlines there would over-segment an editorial page.
- **Asymmetry**: the hero composition overlaps the right edge (the "Publikováno na
  Facebook" toast is cut by it on purpose); the showcase format row bleeds off-canvas
  at the 9:16; the Final CTA is a single big line in an almost empty band.

### The signature element

The **dashed indigo editable outline** with its field tag and pencil + eye cluster —
lifted straight from the fill page — appears exactly three times, as §6 asks:

1. **Hero** — three of them over the "ODSTÁVKA VODY" artwork, one in its *active* state
   (solid outline, indigo tint, chrome collapsed into the open popover) so the page shows
   two states of the same control rather than three identical decorations.
2. **Moduly → "Vyplnění a export"** tile, over the mini preview.
3. **Footer** — wrapped around the one-line description, tagged `Popisek`. A quiet joke
   that also proves the motif scales down.

Secondary recurring motifs: mono format chips (`1:1 · 4:5 · 9:16 · A4`), the layers list,
the synchronised format row, tool-call chips, and the manual's `07` page number.

### Product fragments

Everything that looks like the app was drawn as real interface at true UI density
(13–14 px labels, 30–40 px controls, 1 px hairlines) in the app's actual palette, with
states shown: one selected layer, one focused field, one disabled toggle, one unchecked
font in the allowlist, one active editable box. No screenshots, no device mockups.

**Client artwork uses two extra colours, not one.** The municipality's brand is deep
green `#1e4a3b`, the football club's is brick `#9c3a24`. That is a deliberate reading of
the "one extra brand-of-the-client colour" rule: two different fictional clients have two
different brands, which is the entire premise of the product. Both stay inside the fake
artwork; the page's own accent is indigo and nothing else.

Photographs inside the artwork are drawn as duotone gradient fields in the client's own
brand colour, never stock photography (§6).

---

## 5. Section-by-section decisions

- **Nav** — 72 px, light, hairline on scroll. Anchors centred by giving the left and
  right groups equal 300 px widths rather than relying on `space_between`.
- **Hero** — text column 600 px on the left, composition 720 px starting at x 760 and
  running past the right edge. The composition is the fill page: the 1:1 artwork, three
  editable regions, one open "Datum" popover with a focused field and a character
  counter, the synchronised 1:1 / 4:5 / 9:16 rail with pixel captions, and the publish
  toast bleeding off-canvas.
- **Proof strip** — statement left, four mono chips right in two rows (they do not fit
  on one line at a readable size), reference row below with six neutral placeholder
  slots. No logos invented.
- **Problém** — three columns, no icons. 84 px Instrument Serif numerals in `#dde1e9`
  above a hairline. Titles are height-locked to two lines so all three bodies start on
  the same baseline.
- **Jak to funguje** — three fragment cards of equal height above the numbered copy:
  the add-panel + layers list; the input-properties popover with three toggles, a max-length
  field and a two-face font allowlist; the export button group with the `Dokončujeme` chip.
- **Showcase (ink)** — A4 → 1:1 → 4:5 → 9:16 as an ascending staircase, all top-aligned,
  with a 1.5 px indigo guide running through the headline of all four (the editor's own
  smart-guide motif). The 9:16 bleeds off the canvas.
- **Moduly** — a real bento, not a 4×2 grid: one full-width tile (Brand manuál, with a
  manual page fragment showing HEX/CMYK/Pantone/RAL and the page number `07`), then
  740 + 436 (Editor šablon with a live canvas fragment; Vyplnění a export with the
  signature motif and an export-history list), then 3 × 384, then 2 × 588. The brief's
  module order is preserved exactly.
- **AI asistenti (ink)** — chat panel 640 px on the left with tool-call chips between the
  turns and two result thumbnails; four hairline-separated bullets, the two-line install
  command block and the guide link on the right.
- **Pro koho** — three cards, the developer card inverted to ink. Titles and outcome
  sentences are height-locked so the three "CO DOSTANETE" lists align.
- **Příběh (recessed grey)** — copy left, founder cards right, then the pull-quote
  set at 38 px in the display face with the opening `„` optically hung into the left
  margin, then three facts under 2 px rules.
- **Cena (ink)** — no table, no tiers. Big statement left, three checked facts and the CTA
  right. Rendered as a full-bleed band rather than a card, which the brief allows and which
  the §6 rhythm ("price ink") asks for.
- **FAQ** — two columns, first item open, `−`/`+` affordances.
- **Final CTA (ink)** — one big line, two buttons, microcopy. Deliberately the emptiest
  section on the page.
- **Footer** — deeper ink, three link columns plus the real company block, the signature
  motif around the description, legal line at the bottom.

### Mobile 390

Single column throughout. The hero composition is reduced to the one artwork with **two**
dashed boxes (Nadpis, Fotografie) as §7 specifies. The bento collapses to a stacked list
of eight tiles that keep the manual-page, editor-canvas and fill-preview fragments. The
showcase becomes a horizontal scroll strip with a `posuňte prstem →` hint. The chat panel
keeps its width inside 16 px gutters. FAQ is a single column. All buttons are full width
at 52 px tall (≥ 44 px touch target).

---

## 6. What the two critique passes changed

**Pass 1**
- Contrast: every page-level eyebrow, caption and microcopy on paper moved off slate
  `#8491a0` (2.7:1) onto `#6c757d` (4.6:1). Slate now appears only on ink, where the brief
  permits it for captions, and inside product fragments where it is depicted UI chrome.
- Hierarchy was flat at 44–46 px across every section title; Showcase, AI and Cena went to
  52 px so the three "argument" sections outrank the explanatory ones.
- The six reference placeholders read as broken images; they now carry a hairline so they
  read as reserved space.
- The Pro koho and Problém columns were ragged because titles wrapped to different line
  counts; titles and outcome sentences are now height-locked per row.
- The Jak to funguje step titles had a locked two-line height that left a hole above the
  body — released back to auto.

**Pass 2**
- The Problém section was the most template-like block on the page: numerals went from
  58 px to 84 px and the gap under them tightened, so the section now has a real
  typographic device instead of three text columns.
- The story section's opening quotation mark was rendering beside the second line because
  a `„` sits on the baseline; the glyph was resized and repositioned so it hangs beside
  line one.
- Founder photo placeholders were flat beige blocks; they are now square (as the brief
  asks) and carry `LR` / `JM` monograms in the display face, so a missing photo reads as
  intentional rather than broken.
- The footer's dashed motif was floating with nothing inside it; it now wraps the
  description line and is tagged `Popisek`.
- Footer contact lines were wrapping mid-address; the contact column was given a fixed
  300 px so the address, IČ and DIČ each sit on one line.
- The mobile fill-preview field tag was covering the artwork's own top line; it was
  removed there and the dashed box repositioned onto the headline.
- Two tiles and the mobile fill fragment had content overflowing their clip boxes; all
  remaining "clipped" reports on both artboards are the four intentional edge bleeds.

---

## 6b. Revision round — 2026-09-03, after the first review

Seven changes, all requested by Jan after seeing the first build:

1. **Nav CTA swapped.** *Vyzkoušet zdarma* is gone from the header; **only *Přihlásit se*
   remains, in the primary (indigo) style**. The signup CTA still sits above the fold in the
   hero, so §7's "primary CTA visible without scrolling" still holds. It does soften §7's
   "the primary button style is used only for *Vyzkoušet zdarma*" — two different actions now
   share it. That is the conventional SaaS split (header serves people who already have an
   account, hero serves people who do not) and it was a deliberate call, not an oversight.

2. **New section: `Reference [SKRYTELNÉ]`** — desktop `JXdwa`, mobile `k0oMjU`, on ink,
   between *Pro koho* and *Příběh*. Three testimonial cards plus a six-slot logo row. **Every
   word in it is an explicit placeholder** ("Sem přijde citace klienta – dvě až tři věty…",
   "Jméno Příjmení", "role · organizace") rather than a plausible-sounding fake quote, because
   §6 forbids invented testimonials and logos. It is built as one self-contained section so it
   can be commented out of the HTML until there is real content — hence the layer name.
   Placing it on ink also fixed a rhythm problem: *Pro koho* (paper) → *Příběh* (warm paper)
   were two light sections back to back.

3. **Legal page templates** — new artboards `icEU7` (1440) and `IwrPI` (390). Nav, eyebrow,
   52 px serif H1, a "Poslední aktualizace" line, hairline, perex, then **nine numbered
   sections** with placeholder paragraphs and two bulleted lists, then the footer. The section
   headings are a real Czech privacy-policy skeleton (Správce · Jaké údaje · Proč · Právní
   základ · Jak dlouho · Komu předáváme · Cookies · Vaše práva · Kontakt), so the founders can
   fill prose into a structure instead of starting from a blank page. *Obchodní podmínky*
   reuses the identical shell with its own headings — one template, two pages.

4. **Timeline removed from *Příběh*.** The 2023 / 2023–2026 / 2026 rows were restating the
   copy beside them.

5. **The three facts under the pull-quote were rebuilt.** They were three small columns under
   2 px rules; they are now a **full-width stacked list** — mono index, 26 px serif statement,
   and a supporting sentence — which reads as a manifesto rather than a caption row and takes
   a fourth or fifth item without redesign. Each fact gained a second line of copy.

6. **Final CTA differentiated from *Cena*.** They were both ink bands with a left-aligned
   serif headline and buttons, and read as the same section twice. The close is now
   **centred, 78 px (the largest type on the page), on the deeper ink `#28303a`, with a lot of
   air** and a short indigo rule above it. Four axes of difference — alignment, scale, surface
   and density — and it delivers §6's "let one section be almost empty around a single big
   line". The deeper ink also lets the CTA and the footer read as one closing block.

7. **Footer simplified.** The dashed `Popisek` motif around the description is gone — inside
   the product it means "editable field", but to a first-time visitor it reads as a rendering
   bug. The signature motif therefore now appears **twice** (hero, *Vyplnění a export* tile)
   rather than three times; two placements still read as a system. The whole **Pro vývojáře**
   column (Dokumentace API · AI a MCP server · GitHub) was removed, and with one link column
   left the **Produkt** heading became noise and went too. Consequence for §11: `/api/docs` is
   no longer linked from anywhere on the page — `/ai` still is, from the AI section's
   *Průvodce připojením →*.

## 6c. Second revision — 2026-09-03, later the same evening

8. **The warm cream on *Příběh* is gone.** §6 of the design brief explicitly permits "one
   optional warm tint … the story section on `#f4f1ea`", and it did stop the page feeling
   clinical — but on a page whose entire colour story is *one* accent plus an ink/paper
   system, a cream surface reads as a fourth colour with no reason, and the first question it
   provokes is "why is this yellow?". A colour that needs explaining is the wrong colour.
   Replaced with a **neutral recessed grey `#f1f3f7`** — a tone, not a hue — and everything
   inside the section was remapped off the warm greys (`#e4dfd4` photo placeholders,
   `#6f6a5e` captions, `#d8d1c1` rules) onto neutral equivalents. Tokens: `paper-warm` →
   **`paper-alt #f1f3f7`**, plus a new **`border-alt #dde2e9`** for its hairlines.

   Inner shadows on the section were tried as an alternative and rejected: Pencil's inner
   shadow has no usable spread, so it vignettes the left and right edges of a full-bleed
   band, and a section shadow breaks the page's own rule of **one shadow, floating popovers
   only**. A flat tone plus a hairline is both cleaner and in-system.

9. **The wordmark was rendering 50 % too large everywhere.** Symptom: in the nav the logo
   nearly touched the bottom border. Cause: every lockup is a `Copy` of the component with a
   `descendants` map resizing the seven paths, and **that override silently did not apply** —
   the paths kept the component's native 176 × 48 while their frame was 118 × 32, so the
   glyphs overflowed the frame downward. Fixed by setting each path's `width`/`height`
   explicitly after the copy, on all eight lockups (two navs, two footers, and the same pair
   on both legal artboards). Worth knowing when coding: **size the inlined SVG by its own
   `width` + `height:auto`**, never by a wrapper the paths do not inherit from.

## 7. Motion hints (annotated, not animated)

- On hero load the three dashed outlines fade in one by one (≈ 80 ms apart), the popover
  last. Nothing translates.
- The sticky nav gains its hairline on first scroll.
- The showcase headline nudges ~6 px when the band scrolls into view, once, on all four
  formats simultaneously — that is the whole point of the section.
- FAQ rows expand with a height transition; the `+` rotates to `−`.
- Nothing else moves. No parallax, no counters, no marquee.

---

## 8. Interaction and accessibility states

Defined in the **Components** frame: primary button default / hover `#5b66e0` / focus
(2 px `#727cf566` ring at 3 px offset) / disabled (38 % opacity); ghost on paper default
and hover; ghost on ink default and hover; mono chip on paper and on ink; field tag; icon
cluster; status chip; the dashed motif; FAQ row open and closed; a bento tile; both
wordmark lockups; and a written summary of the token values.

Contrast: body `#6c757d` on paper = 4.6:1; body `#c2cbd6` on ink ≈ 6.8:1; white on
`#727cf5` at 16 px/700 passes; slate on ink is used for captions only. Body text is never
below 14 px and the reading measure stays inside 50–75 characters.

---

## 9. Copy

All copy is verbatim from §5 of the brief, in Czech, with typographic corrections applied:
non-breaking spaces after every one-letter preposition and conjunction (v, k, s, z, a, i,
o, u), real en dashes, `×` for multiplication, Czech quotation marks `„ “`, and mono
(tabular) figures wherever numbers appear.

**H1 alternatives tested and not shipped** (kept here per the brief):
- *"Grafika, kterou děláte každý týden znovu, se odteď dělá sama."* — stronger
  transformation claim but a longer, softer line; it wants a two-column hero and loses
  the "vy → klient" hand-off that the whole product is about.
- *"Šablony, které klient vyplní sám a značku přitom nerozbije."* — the most accurate
  sentence on the page, but it leads with the feature word (*šablony*) and reads as a
  product description rather than a promise.
- Shipped: **"Navrhněte jednou. Klient si vyexportuje sám."** Two short clauses, two
  actors, one hand-off; breaks cleanly onto two lines at 62 px in a 600 px column.

**One deliberate deviation from §7.** "The primary button style is used only for
*Vyzkoušet zdarma*" holds for every piece of page chrome. Indigo buttons also appear
*inside* the product fragments (`Uložit` in the popover, `Export do PNG` in step 3) —
those are the app's own interface being depicted, not page CTAs, and they sit inside
drawn UI containers. If you disagree, they can be desaturated without touching anything
else.

---

## 10. [POTVRDIT] — for the founders, before this goes live

From the brief:

1. **Customer names, logos and testimonials.** May any existing customer (the
   Moravian-Silesian municipalities, the football club, the local companies) be named, shown
   or quoted? Two places wait on this: the proof strip's six placeholder slots (§5.2) and the
   whole new **Reference** section (§6b.2) — three quote cards and a logo row, every field an
   explicit placeholder. Nothing is invented. **The Reference section ships hidden**; reveal
   it only when there is at least one real, approved quote. Collecting them is the ask: a name,
   a role, an organisation and two or three sentences about what WBoost saved them.
2. **Registration response time.** The hero, Moduly, Cena and Final CTA microcopy says
   *"Zdarma a bez platební karty. Přístup vám pošleme e-mailem."* — no speed promise.
   Confirm before adding anything like *"do 24 hodin"*.
3. **The light-surface logo lockup does not exist yet.** The Components frame proposes it:
   the same wordmark with `boost` in ink `#313a46` and the `w` in slate `#8491a0`, built
   from the real `logo-lg.svg` paths (not redrawn). It is used in the nav on both
   artboards. Needs sign-off, and ideally a proper SVG export from the source file.
4. **Founder photos and exact titles.** Placeholders are square with `LR` / `JM`
   monograms; titles read *"grafik, spoluzakladatel"* and *"vývojář, spoluzakladatel"*.
5. **The timeline years**: 2023 · první verze pro jednoho grafika → 2023–2026 · tři roky
   ladění na vlastních klientech → 2026 · otevřeno pro další grafiky a týmy.
6. **Facebook / Instagram wording at launch.** The page carries a `Dokončujeme` chip on
   the module tile and in the step-3 fragment, and the FAQ answer says the integration is
   finished and in Meta review. Update both the moment review clears — or if it does not.
7. **Privacy and terms pages: the design exists, the text does not.** Artboards `icEU7` /
   `IwrPI` give a nine-section skeleton with `[DOPLNIT]` placeholders throughout; the footer
   links point at `/ochrana-osobnich-udaju` and `/obchodni-podminky`. Someone has to write the
   actual wording, including the "Poslední aktualizace" date, and confirm whether a separate
   cookie policy is needed (there is no analytics or consent banner today, so probably not
   yet). The commercial-register line belongs on
   the terms page, not the footer, and is not on the page.
8. **No hosting, GDPR or data-location claim** is made anywhere. Do not add one without
   checking it.

Added by this build, also worth a look:

9. **Fictional client names** used in the artwork, invented per §6 because no real ones
   could be shown: *Obec Horní Lhota*, *FK Horní Lhota*, *TJ Sokol Březiny*, and the
   Instagram handle *@obechornilhota*. Confirm none collides with a real customer.
10. **Example dates, streets and times** are invented: *čtvrtek 12. 9. 2026, 8:00–14:00,
    ulice Nádražní, Lesní a Krátká*; *sobota 20. 9. · 16:30*; *středa 24. 9. · 15:00 ·
    sál OÚ*.
11. **Numbers inside product fragments** are example UI data, not claims: the export
    history rows (`12. 9. 2026 · Lukáš R. · ×3` …), the `31 / 48` character counter, and
    the fonts tile's *"Rubik · 5 řezů · 100–900 · používá 12 šablon"*. If any of those
    could be misread as a public statistic, say so and they will be softened. The
    genuine claims on the page are only the ones §5.2 and the module copy authorise.
12. **The API code block is illustrative, not the contract.** It shows
    `POST /api/template-variants/{id}/export` with an `inputs` object keyed by readable
    names (`"nadpis"`, `"datum"`) because UUID keys are unreadable at 10 px. The real API
    keys `inputs` by **inputId UUID**. Anyone copying the snippet should be sent to
    `/api/docs`. Consider a one-line caption on the tile if that worries you.
13. **The e-mail signature fragment** shows a real person and the real company:
    *Lukáš Rejda · grafik · Wantoo Design · info@wantoo.cz*. Fine if intended, but it is
    a real name on a public page.

---

## 11. Handover notes

- Sticky nav, accordions and the horizontal scroll strip are drawn in their resting
  states; the artboards are static.
- The **Reference** section is designed to be shipped hidden — build it, then comment it out
  until real quotes exist. Do not fill it with invented content to "see how it looks".
- The two legal artboards are **one template used twice**; build a single page shell and
  swap the heading, the section list and the body copy.
- The header now carries **only** *Přihlásit se*, in the primary style. All *Vyzkoušet
  zdarma* buttons (hero, modules, pricing, final CTA) carry
  `https://wboost.cz/registration`; every *Přihlásit se* carries `https://wboost.cz/login`; *Dokumentace API* → `https://wboost.cz/api/docs`;
  *AI a MCP server* → `https://wboost.cz/ai`; the footer e-mail is a `mailto:`; nav links
  are anchors to `#funkce`, `#jak-to-funguje`, `#pro-koho`, `#ai`, `#pribeh`, `#cena`.
- The `Jídelníčky` and `Kalendáře` modules are deliberately absent, per §1.
- The dashed outlines are drawn as single filled paths with a baked dash pattern, because
  Pencil has no stroke-dash property. In CSS they are a plain `1.5px dashed #727cf5`
  border with a 2–3 px radius; do not reproduce them as paths.
- Fonts to load in production: Instrument Serif 400, Nunito 400/600/700/800,
  IBM Plex Mono 400.
