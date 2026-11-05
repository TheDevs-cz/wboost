# Wboost "Nocturne" design system — implementation spec

Source: the "Wboost Prototyp" single-file HTML prototype (split into `app.css`, `body.html`, `app.js`). Every value below is quoted from `app.css` unless marked **(proposed)**. CSS line numbers (`L123`) refer to `app.css`; JS line numbers (`js:123`) refer to `app.js`.

Target stack: Symfony / Twig / Stimulus with plain CSS. The prototype has no build step. It is one stylesheet with custom properties, `color-mix(in oklab, …)`, `:has()`, container queries, `field-sizing: content` and native `<dialog>`. All of these work in current evergreen browsers. Keep them.

---

## 0. TL;DR for implementers

- **Dark-first.** Tokens live on `:root`. Light mode is `:root[data-theme="light"]`, plus `prefers-color-scheme: light` when `data-theme` is not `dark`.
- **One accent does the work: lavender.** It covers primary actions, selection, focus and "current". Rose = destructive/negative, mint = success/"on"/selected tick, peach (`pch`, apricot) = paid/premium/warning/"current month".
- **Everything is rounded.** Buttons, chips and pills are full pills (`999px`). Cards are 20–22 px. Dialogs are 22 px. Inputs are 14 px.
- **Surfaces stack** `--bg` → `--surface` (cards, sidebar) → `--surface-2` (inputs, menus, dialogs) → `--surface-3` (hover, tertiary buttons). Borders are 1 px hairlines (`--line*`). Shadows appear only on floating things (menus, dialogs, toasts, hovered cards).
- **Font: Outfit 400/500/600/700, base 14 px.** Titles are 600 with negative tracking. Uppercase eyebrows are 11 px / 600 / `.16em`.
- **Icons are CSS masks** (`.i .i-name`): 24-unit viewBox, stroke 1.8, round caps and joins, colour = `currentColor`, default 20 px.

---

## 1. Tokens

### 1.1 Theme switching

| Mechanism | Selector | Notes |
|---|---|---|
| Default (dark) | `:root` | `color-scheme: dark` |
| OS light, no override | `@media (prefers-color-scheme: light) { :root:not([data-theme="dark"]) {…} }` | Same overrides as below |
| Forced light | `:root[data-theme="light"]` | Set on `<html>` |
| Forced dark | `:root[data-theme="dark"]` | Blocks the OS media query |
| Toggle UI | `.sb-theme` radiogroup "Vzhled" (Tmavý / Světlý), stored in `localStorage['wb-theme']` | js:3198. The prototype applies it **only on `#projekty`** and greys the switch out elsewhere ("Světlý režim zatím zkoušíme jen na přehledu…"). This is a prototype limitation. The real app should apply it globally. |

### 1.2 Colour tokens (dark default and light override)

| Token | Dark | Light | Use |
|---|---|---|---|
| `--bg` | `#0B0A0D` | `#F6F4F8` | Page background, editor/preview stage, inner "paper" areas |
| `--surface` | `#141317` | `#FFFFFF` | Sidebar, cards, tiles, panels, collapsed toggle |
| `--surface-2` | `#1D1B21` | `#F1EEF5` | Inputs, menus/popovers, dialogs, hover on nav/rows, thumbnail wells |
| `--surface-3` | `#24222A` | `#E8E4EE` | Menu-item hover, tertiary button, mono avatar, toggle track |
| `--line` | `#24222A` | `#E6E2EC` | Default card/divider hairline |
| `--line-2` | `#2C2931` | `#DAD5E1` | Input border, menu border, dialog border, list-box border |
| `--line-3` | `#3A3640` | `#C6C0CF` | Hover border, dashed drop zones, checkbox border, scrollbar thumb |
| `--text` | `#F2EEF6` | `#17151B` | Primary text, headings |
| `--text-2` | `#C9C4D0` | `#3A3640` | Secondary text, nav items, ghost buttons |
| `--muted` | `#9A95A3` | `#5E5966` | Meta text, inactive tabs, icons in rows |
| `--subtle` | `#8A8593` | `#6B6673` | Captions (`.cap`), eyebrows (`.lbl`), placeholders, counts |
| `--logo-ink` | (unset → `#FFFFFF` fallback) | `#29253E` | "W" of the wordmark. The rest of the logo is `#C1B6D7`. |
| `--on-accent` | `#141218` | (unchanged) | Text/icon colour on any solid accent fill |
| `--scrim` | `rgba(5,4,7,.66)` | `rgba(23,21,27,.4)` | Dialog backdrop (with `backdrop-filter: blur(4px)`), drawer scrim |
| `--shadow-2` | `0 8px 24px rgba(0,0,0,.45)` | `0 8px 24px rgba(23,21,27,.08)` | Hovered card, collapsed toggle, small floating chrome |
| `--shadow-3` | `0 24px 56px rgba(0,0,0,.55)` | `0 24px 56px rgba(23,21,27,.12)` | Menus, popovers, toasts, tooltip, sticky footers |
| `--shadow-4` | `0 48px 112px rgba(0,0,0,.7)` | `0 48px 112px rgba(23,21,27,.18)` | Dialogs, mobile drawer, bulk bar |

### 1.3 Accent families

The base colours are the **same in both themes**: light mode keeps the pastel fills. Only `-deep`, `-ink`, `-glow` and `-line` change.

| Family | base | `-deep` dark / light | `-ink` dark / light | `-glow` dark / light | `-line` dark / light |
|---|---|---|---|---|---|
| **lav** (lavender) | `#C7B6F2` | `#2A2440` / `#EEE8FC` | `#C7B6F2` / `#5B47A8` | `rgba(199,182,242,.22)` / `rgba(91,71,168,.16)` | `rgba(199,182,242,.45)` / `rgba(91,71,168,.32)` |
| **rose** | `#F2A7B4` | `#3D2228` / `#FCE4E8` | `#F2A7B4` / `#A3334B` | `rgba(242,167,180,.2)` / `rgba(163,51,75,.14)` | **undefined** |
| **mnt** (mint) | `#A6E3CC` | `#1E3830` / `#E0F5EC` | `#A6E3CC` / `#1D6E52` | **undefined** | **undefined** |
| **pch** (peach/apricot) | `#F4C09A` | `#3A2A20` / `#FDEBDD` | `#F4C09A` / `#93501F` | **undefined** | **undefined** |

**Bug in the prototype:** `--mnt-glow`, `--mnt-line`, `--pch-glow`, `--pch-line` and `--rose-line` are referenced (`.c-success`, `.c-warning`, `.c-danger`, `.ed-save`, `.fi-ok`, `.ed-cont.sel`, `.pd-mod .sw`) but never defined. Define them **(proposed)** with the same alpha pattern as lavender:

| Proposed | Dark | Light |
|---|---|---|
| `--mnt-glow` / `--mnt-line` | `rgba(166,227,204,.2)` / `rgba(166,227,204,.45)` | `rgba(29,110,82,.14)` / `rgba(29,110,82,.32)` |
| `--pch-glow` / `--pch-line` | `rgba(244,192,154,.2)` / `rgba(244,192,154,.45)` | `rgba(147,80,31,.14)` / `rgba(147,80,31,.32)` |
| `--rose-line` | `rgba(242,167,180,.45)` | `rgba(163,51,75,.32)` |

**Anatomy of a family:**
- **base** is a solid fill: primary button, `.count` badge, solid pill, switch knob, progress bar.
- **-deep** is a tinted background: selected nav item, chip `.on`, `.pill`, icon circle.
- **-ink** is accent-coloured text or an icon on `-deep` or on a surface. It is darkened in light mode for contrast.
- **-glow** is a 3 px focus/selection halo (`box-shadow: 0 0 0 3px var(--x-glow)`) and the coloured drop shadow of solid buttons (`0 8px 24px var(--x-glow)`).
- **-line** is a tinted border for selected or "on" states.

**Accent scope (`--a*`).** Components read `--a`, `--a-deep`, `--a-ink`, `--a-line`, `--a-glow` and `--on-a`. These default to lavender (`:root`). Re-scope them with a helper class (L313-316):

| Class | Family |
|---|---|
| `.c-lav`, `.c-info` | lav |
| `.c-mnt`, `.c-success` | mnt |
| `.c-pch`, `.c-warning` | pch |
| `.c-rose`, `.c-danger` | rose |

Used by `.chip.on`, `.seg button.on`, `.cb`, `.rd`, `.sw`, `.tabs-u` underline and `.alert`.

### 1.4 Semantic mapping

| Meaning | Family | Examples |
|---|---|---|
| Primary action, selection, focus, current page, info | **lav** | `.btn` default, `aria-current` nav, focus ring, `.chip.on`, `.pcard.hl`, `.rcard[aria-checked]`, selected gallery card, drop-zone active, editor selection, `.alert.c-info` |
| Destructive, negative trend, error | **rose** | `.btn.danger`, `.mi.danger`, Koš (trash), `.inp.err`, `.hint.err`, `.notice`, KPI `.bad`, `▼` delta, `.yn` = "Ne", AI "Tvary" segment |
| Success, on/enabled, "selected tick", save | **mnt** | `.chk` tick circle, toast icon, `.u-st .dot` (Aktivní), `.pd-mod` switch, `.yn.on`, "Synchronizovaná" pill in the editor, `.ed-save` / `.fi-ok` buttons, registration request rows, drop-onto-folder, AI "Obrázky" segment, KPI `.good`, `▲` delta |
| Paid / premium / warning / "now" | **pch** | `.pstar` paid star, `.tp-prem` "Premium", owner pill "Vlastník" (`.pill.pch`), `.alert.c-warning`, `.gal-warn`, editor "Neuložené změny", "mimo plátno" badge, current month in charts (`.now`), "Dokument" badge, AI "Pozadí" segment, client read-only missing-field outline |
| Neutral | surface/line | `.tag`, `.pill.r-user`, `.pd-n` counter, `.tp-var` |

### 1.5 Chart tokens (dataviz-validated, deeper than the UI pastels)

| Token | Dark | Light |
|---|---|---|
| `--ch-1` (series 1, lavender) | `#8C75DB` | `#5B47A8` |
| `--ch-2` (series 2, apricot) | `#D07A3A` | `#93501F` |
| `--ch-ghost` (track / empty) | `#24222A` | `#ECE8F2` |

Rule (L508): UI pastels fail the chart lightness band, so charts use `--ch-*`. "Now" (current month) is `--pch`. Heatmap levels use `color-mix(in oklab, var(--ch-1) 22/42/66/100%, var(--surface))`.

### 1.6 Typography

`--font: Biotic, "Outfit", system-ui, -apple-system, "Segoe UI", sans-serif`. Only Outfit is loaded, from Google Fonts with weights 400/500/600/700. "Biotic" is a placeholder for a future brand face. `body { font-size: 14px; -webkit-font-smoothing: antialiased }`.

| Role | Size | Weight | Tracking | Line-height | Class / source |
|---|---|---|---|---|---|
| Page title (h1) | `clamp(28px, 4vw, 38px)` | 600 | `-.025em` | 1 | `.head h1`, `text-wrap: balance` |
| Count after h1 | `.6em` | 500 | 0 | — | `.head h1 small`, colour `--muted` |
| Project detail title | `clamp(30px, 4.4vw, 44px)` | 600 | `-.025em` | 1.05 | `.dhead h1` |
| Eyebrow ("DOBRÉ RÁNO, LUKÁŠI", "ADMINISTRACE", "PROJEKT · X") | 11px | 600 | `.16em`, uppercase | — | `.lbl`, colour `--subtle` |
| Table header eyebrow | 11px | 600 | `.14em`, uppercase | — | `.u-lh` |
| Panel section title (editor/fill) | 12px | 700 | `.04em`, uppercase | — | `.ed-sec h3`, `.fi-sec h3` |
| Template category heading | 20px | 600 | `-.01em` | 1.2 | `.tp-tg h2` |
| Font family name | 26px (22px mobile) | 600 | `-.01em` | 1.15 | `.fn-fam-t h2` |
| Sign-out card title | 24px | 600 | `-.01em` | — | `.so-card h2` |
| Profile dialog title | 22px | 600 | `-.015em` | 1.2 | `.up-id h2` |
| Dialog title | 19px | 600 | `-.01em` | — | `.dlg-h h2` |
| Empty-state title | 18px | 600 | — | — | inline |
| Box/section title | 17px | 600 | (−.01em on `.pname`) | 1.25 | `.pname`, `.pd-box-h h2`, `.pal-gh h2`, `.man-sech h2`, `.pd-t` |
| Card section title | 16px | 600 | `-.01em` | — | `.st-card-h h2`, `.up-sec-h h3`, `.tp-type-b b` |
| Small card title | 15px | 600 | — | — | `.man-vh h3`, `.tp-h h3` (`--tpfs`, 13–18px, scales with the size slider) |
| Body / control | 14px | 400–600 | — | 1.5–1.55 for paragraphs | buttons 600, nav 500 |
| Secondary / meta | 13px | 400–600 | — | 1.5 | `.meta`, chips 600, `.field > span` 600, `.notice` |
| Caption | 12px | 400 | — | 1.45–1.55 | `.cap` (`--subtle`), `.hint`, `.rsub` |
| Micro / badges | 11px | 600–700 | — | — | `.pill`, `.count`, `.chip .n`, `.tp-var`, chart ticks |
| Nano | 10px / 9.5px | 600–700 | — | — | `.pal-src` (uppercase `.06em`), editor tags, ruler 9px |
| KPI number | 30px (24px ≤560) | 600 | `-.02em` | 1.1 | `.st-kpi b`, tabular-nums; `small` = 15px/500 `--subtle` |
| Tile number | 26px | 600 | — | — | `.tile b` |
| Ring % | 18px | 600 | — | — | `.ring .rp` |

All counts and numbers use `font-variant-numeric: tabular-nums`. Monospace appears only in the editor HEX field (`ui-monospace, Menlo, monospace`, 12px). Elsewhere HEX is Outfit 600, uppercase, `.02em`.

### 1.7 Radii (by frequency)

| Radius | Use |
|---|---|
| `999px` | Buttons, chips, pills, badges, segmented control, switch, toggles, bulk bar, zoom bar, progress bars |
| `24px` | Project detail symbol (88px), sign-out card, "Nejpoužívanější" box, full-page drag overlay |
| `22px` | **Page cards** (`.pcard`, `.u-card`, `.st-card`, `.pd-box`, `.fn-fam`, `.man-*`, `.tp-card`), **dialogs**, `.empty`, skeleton |
| `20px` | Tiles (`.tile`, `.pd-tile`), KPI tiles, palette cards, insight card |
| `18px` | Popovers (`.qn-pop`, `.fi-hpop`), toolbars (`.gal-nav`, `.ai-steps`), gallery card, drop zone (dialog), new-template type card, AI sticky footer |
| `16px` | Menus (`.menu`), alerts, list boxes (`.share-list`, `.scopes`), folders, client boxes, fill pop-up, inner preview wells, `.sym` 56px |
| `14px` | **Inputs** (`.inp`), toast, quick-nav trigger, role cards, module rows, `.notice`, small boxes |
| `12px` | Nav items, share rows, scope rows, editor input box, `.fi-th` |
| `10–11px` | Menu items (`.mi`, `.qn`), small inputs (`.inp.sm`), editor tools, brand mark (11px) |
| `8px` | `.sym.sm` (26px), heatmap cells, editor layer rows, small controls |
| `6px` | Checkbox `.cb`, `.fn-tag`, editor layer input |
| `3–5px` | Chart legend squares, editor tags, AI box handles |

Nested-radius rule: inner = outer − padding (for example, the client box is 16 with a 15 row radius, and a 22 card holds a 16 well).

### 1.8 Spacing rhythm

The scale in use (by frequency) is **8 · 6 · 10 · 12 · 2 · 4 · 16 · 14 · 20 · 18 · 28** px. The base is a 2 px grid; 4 px steps cover most cases.

| Context | Value |
|---|---|
| Page vertical rhythm (`.inner` flex gap) | **28px** |
| Page padding | `32px clamp(0px, 3vw, 24px) 72px` inside `.wrap` (side padding `max(16px, safe-area)`); top 22px under 860 |
| Card grid gap | 20px (project grid, `.st-grid`), 16px (tiles, KPI, template grid, fonts, mockup pages), 12px (gallery, folders, palette) |
| Card padding | 20px (`.pcard`), 22px (`.tile`, `.fn-fam`, `.man-sec`), 22/24px (`.st-card`), 24px (`.pd-box`), 14px (`.tp-card`, `.man-pg`) |
| Card inner gap | 14px (`.pcard`, `.fn-fam`), 16–18px (boxes) |
| Dialog padding | 26px; scrolling dialogs use 22/26 header, 20/26/22 body, 16/26/22 footer; full dialogs use 26/32 header and 32px sides |
| Head ↔ tools | `gap: 16px 24px` |
| Toolbar items | 10px (`.head-tools`, `.gal-nav`); 8px (`.fbar`, `.dlg-f`) |
| Row padding | `12px 14px 12px 20px` user rows, min-height 64px |
| Profile/section layout | `.up-sec`: grid `260px | 1fr`, gap `16px 56px`, padding 32px 0 |

### 1.9 Control heights

| Height | Elements |
|---|---|
| 52–56px | Profile button (`.sb-me` 52), share rows (56), suggestion items |
| **44px** | Default `.btn`, `.inp`, `.nav-i` min-height, category tags, fill pop-up input |
| 48px | Quick-nav trigger, underline tabs `.tabs-u` |
| 40px | `.btn.sm`, `.btn.icon`, `.chip`, `.seg button`, `.mi` min, `.qn` min, folder trash button |
| 36px | `.inp.sm`, editor tools/variant tabs, size slider, palette fields |
| 32px | `.btn.xs`, `.btn.icon.xs`, `.chip.sm`, theme toggle segment, `.pstar` |
| 22–28px | Counters, pills, `.yn`, small icon buttons in rows |

### 1.10 Z-index scale (observed)

| z | Layer |
|---|---|
| 0–6 | In-card stacking (stretched-link `::after` under actions; `.card-acts` 3, card menu 4, `.pcard.menu-open` 5, sticky headers 5) |
| 20 | Mobile top bar `.mbar`, filter dropdowns `.fdrop .menu`, sort menus |
| 30 | Profile drop-up `.me-menu`, export-history popover, gallery bulk bar |
| 40 | Editor minimap, full-page file-drag overlay |
| 55 | Drawer scrim |
| 60 | Mobile drawer `.sb`, fill edit pop-up `.fi-pop` |
| 70 | Quick-nav popover `.qn-pop` |
| 80 | Toasts |
| 85 | Chart/data tooltip `.st-tip` |
| 90 | Signed-out overlay |
| 1000 | Gallery floating context menu (fixed) |
| top layer | Native `<dialog>` via `showModal()` |

### 1.11 Motion

| Token | Value |
|---|---|
| `--ease` | `cubic-bezier(.2,.7,.2,1)` (fast out, soft settle). Used for everything that moves. |
| `--dur-1` | 120ms: hover reveals, chart guides |
| `--dur-2` | 200ms: colour/background/border transitions (the most common) |
| `--dur-3` | 320ms: expand/collapse (`.cl-more` grid-rows) |
| Literal | `.15s` (gallery/fonts/palette hovers), `.2s` (buttons/inputs), `.25s` (sidebar width, chevron rotate), `.28s` (drawer slide) |

Keyframes:

| Keyframe | Effect | Used by |
|---|---|---|
| `spin` | .8s linear | `.btn.is-loading` |
| `sh` | 1.6s shimmer | skeleton `.sk` |
| `tin` | .3s, translateY(8px) → 0 + fade | toast |
| `rowflash` | 1.6s lavender flash | newly added share row |
| `fnFlash` | 1.4s | font cut / palette card flash |
| `pnavIn` | .6s, slide from −8px, mint tint | newly enabled module in the sidebar |
| `galUp` | .2s | bulk bar rise |
| `st-draw` | line draw-in | charts |
| `st-fade` | fade-in | charts |
| `st-grow` | bars scaleY | charts |
| `st-grow-x` | ranked/storage bars scaleX, staggered by `--d` | charts |
| `st-ring` | ring stroke-dashoffset | rings |
| `aiScan` | scan bar sweep | AI import |
| `aiSpin` | spinner | AI import |
| `fiDot` | typing dots | AI chat |

Reduced motion (L2007):

```css
@media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0s !important; transition-duration: 0s !important; } }
```

The typing dots also have their own opt-out.

### 1.12 Focus

- Global: `:focus-visible { outline: 2px solid var(--lav); outline-offset: 2px; }`
- In components that sit on tinted or white backgrounds: `outline: 2px solid var(--lav-ink)`, offset 2px (or −4px inside thumbnails). Over photos: `outline: 2px solid #fff`.
- Fields: `.inp:focus-within { border-color: var(--lav); box-shadow: 0 0 0 3px var(--lav-glow) }`. The same halo marks "open"/"selected" (`.qn-trigger[aria-expanded]`, `.rcard[aria-checked]`, `.pcard.hl`, `.cl-box.open`).
- Stretched-link rows show focus on the whole row: `.u-row:has(.u-name:focus-visible) { box-shadow: inset 0 0 0 2px var(--lav) }`.
- Hidden native inputs (`.sr`) keep focus visible on the custom control through `:has(input:focus-visible)`.

---

## 2. Layout

### 2.1 App shell

```
.app (grid: var(--sbw,272px) | minmax(0,1fr); height 100%; transition grid-template-columns .25s var(--ease))
├── aside.sb#sidebar  (surface, border-right line, padding 18 14 14, flex column gap 6)
└── main.main (overflow-y:auto)  >  .mbar.wrap (mobile only)  +  .wrap > .inner#view
```

`body` has `overflow: hidden`; only `.main` scrolls. `.app.collapsed { --sbw: 84px }`. The collapsed state persists in `localStorage['wb-collapsed']` and is ignored ≤860px.

**Sidebar anatomy, top to bottom:**

| # | Part | Markup / classes | Notes |
|---|---|---|---|
| 1 | Brand row | `.sb-brand` > `a.brand` (SVG wordmark `.brand-logo` 30px high; app icon `.brand-ico` 34px when collapsed) + `.collapse-btn` (`btn icon xs ghost`, `i-chev-left` rotates 180° when collapsed) + `.drawer-close` (mobile only) | Collapsed: the toggle becomes a 28px round button on the sidebar border (`right:-14.5px; top:23px`, surface fill, `--shadow-2`) |
| 2 | "Všechny projekty" | `nav[aria-label="Sekce"] > a.nav-i#navAll` + `.count` (solid lavender pill) | `aria-current="page"` on `#projekty` |
| 3 | Quick navigation | `.qn-head > .lbl` "Rychlá navigace" + `button.qn-trigger#qnBtn` (48px, surface-2, line-2, radius 14) | Shows the current project `.sym.sm` + name + chevron, or the placeholder "Vybrat projekt…" with a lavender `i-sort`. Opens `.qn-pop` (fixed, 300px, radius 18, shadow-3): search `.inp.sm` combobox + listbox. Sections "Naposledy otevřené" (last 3, `localStorage['wb-recent']`) / "Ostatní projekty". The current project gets a mint `.chk` tick. Arrow keys, Enter, Esc. |
| 4 | Scroll area | `.sb-scroll` (flex 1, thin scrollbar) | — |
| 4a | Project nav | `nav.sb-group.pnav#projNav` with `.sb-sec.lbl` "Projekt" | Items: Přehled, Manuály, Šablony, Galerie, Fonty, Paleta barev, then other enabled modules (E-maily, Jídelníčky, "Facebook a Instagram"). Only rendered inside a project. A newly enabled module animates with `.pnav-new`. |
| 4b | Administrace | `nav[aria-label="Administrace"]` + `.sb-sec.lbl` | Uživatelé (+ `.count#countReq` for new registration requests), Poptávky z webu (`i-inbox-in`), Statistiky (`i-trend`) |
| 4c | Propojení | `nav[aria-label="Propojení"]`, a divider always shown | Propojené aplikace (`i-plug`), AI (MCP server) (`i-bot`) |
| 5 | Theme | `.sb-theme` (grid 2 cols, pill track surface-2, active `.sb-th.on` = surface + `0 1px 4px rgb(0 0 0/.12)`, active icon lavender) | Collapsed: a single 44×36 icon toggle `.sb-thc` |
| 6 | Profile | `.sb-user` (border-top line) > `button.sb-me` (avatar `.av` 34, name 13/600, role `.cap`, `i-chev-up`) | Opens the drop-up `.menu.me-menu` (`bottom: calc(100% + 6px)`). Collapsed: opens to the right (`left: calc(100% + 14px)`, width 220). Items: Můj účet · role preview (prototype) · Odhlásit se. |

**Nav item `.nav-i`:** min-height 44, padding 0 12, radius 12, gap 12, colour `--text-2`, weight 500. Hover: surface-2 / text. Current: `aria-current="page"` → `--lav-deep` bg + `--lav-ink`. Collapsed: labels hidden through `.hide-c`, items centred, section labels replaced by `.sb-div` 1px dividers.

### 2.2 Main content

| Element | Rule |
|---|---|
| `.wrap` | `padding-inline: max(16px, env(safe-area-inset-*))` |
| `.inner` | `max-width: 1320px; margin: 0 auto; padding: 32px clamp(0px,3vw,24px) 72px; display:flex; flex-direction:column; gap:28px` |
| Full-width modes | Editor `body.ed-on`: `.inner` max-width none, padding 0, `.main` overflow hidden, sidebar forced collapsed. Dialog-pages (user profile, client stats) are full-viewport dialogs. |
| Inner reading widths | `.up-body` 1080px, `.sd-body` 1280px, `.gal-lead` 640px, `.pd-box-h > div` 860px |

### 2.3 Page header pattern (`.head`)

```html
<div class="head">  <!-- flex, wrap, align-items:flex-end, space-between, gap 16px 24px -->
  <div style="display:flex;flex-direction:column;gap:8px">
    <span class="lbl">Dobré ráno, Lukáši</span>          <!-- eyebrow: greeting / section group / "Projekt · Název" -->
    <h1>Všechny projekty <small>24</small></h1>         <!-- count as <small> or .pd-n pill -->
    <span class="cap gal-lead">One-line description…</span>  <!-- optional lead, 13px muted, max 640 -->
  </div>
  <div class="head-tools">                              <!-- flex wrap gap 10 -->
    <label class="inp"><span class="i i-search s16"></span><input type="search" placeholder="Hledat v projektech"></label>  <!-- 280px -->
    <button class="btn"><span class="i i-plus s16"></span>Nový projekt</button>   <!-- the single primary action, far right -->
  </div>
</div>
<div class="fbar" role="toolbar">…chips…</div>        <!-- optional filter row directly under -->
```

- An optional `a.back` sits above the head ("← Všechny projekty", 40px tall, muted → text on hover).
- In-project pages put a toolbar card under the head instead of head-tools: `.gal-nav` (surface, radius 18, padding 10). Primary button on the left, then tertiary buttons, a flexible spacer `.gal-nav-sp`, then search, size slider, or a destructive/secondary button on the right.
- ≤860px: `.head-tools` goes full width and the search flexes.

### 2.4 Grids

| Grid | Columns | Gap |
|---|---|---|
| Project cards `.grid` | `repeat(auto-fill, minmax(min(250px,100%), 1fr))` | 20 |
| Project tiles `.tiles/.pd-tiles` | `repeat(auto-fit, minmax(min(240px,100%), 1fr))`; ≤560: 2 columns | 16 (10) |
| KPI row `.st-kpis` | 4 columns → 2 at ≤1240 → 2 with 10 gap at ≤560; `.mini` auto-fit 150 | 16 |
| Stats two-up `.st-grid` | `1.25fr 1fr` → 1 col at ≤1100 | 20 |
| Gallery `.gal-grid` | `repeat(auto-fill, var(--galmin,168px))`, slider 100–360px; ≤560: 2 columns | 12 |
| Folders | `auto-fill, minmax(200px,1fr)`; ≤560: 2 columns | 12 |
| Templates `.tp-grid` | `auto-fill, min(var(--tpmin,250px),100%)`, slider 170–480px; preview height `--tph` = 1.12 × width; ≤560: 2 columns | 16 |
| Fill formats `.fi-grid` | `auto-fill, min(var(--fimin,360px),100%)`, slider 240–820 (default 440) | 20 |
| Fonts | `auto-fill, minmax(min(520px,100%),1fr)` | 16 |
| Palette / logo variants | `auto-fill, minmax(min(460px,100%),1fr)` | 12 / 16 |
| Mockup pages | `auto-fill, minmax(min(320px,100%),1fr)` | 16 |
| Template types | 4 → 2 (≤1000) → 1, horizontal card (≤760) | 14 |

The size slider is `.sz-ctl`: range 0–100 with small/large image icons, stored per viewer in `localStorage['wb-sz2-*']`, hidden ≤560.

### 2.5 Breakpoints: every query

| Query | Purpose |
|---|---|
| `max-width: 1240px` | KPI row 4 → 2 columns |
| `max-width: 1180px` | Editor columns 264/312 → 228/280; hide the save-state text |
| `max-width: 1100px` | `.st-grid` and `.sd-grid` → 1 column; AI side panel 380 → 320; manual PDF rail 168 → 140 |
| `max-width: 1000px` | Template-type picker 4 → 2 columns |
| `max-width: 900px` | Profile sections → 1 column; gallery preview side panel moves under the image; ranked list 2 → 1 column; editor → single canvas column (hide panels, minimap, history, multi-edit; show the `.ed-mobile` notice); fill "Všechna pole" and "Sdílet" dialogs become `100vw−24` and stack; font size slider narrower |
| **`max-width: 860px`** | **Sidebar becomes an off-canvas drawer** (`position:fixed; width:min(300px,86vw); transform:translateX(-102%)`; `.app.drawer .sb {transform:none}`, `.28s var(--ease)`, `--shadow-4`). Scrim (z 55). Sticky blurred `.mbar` (60px: menu button, logo, `+` new project). Collapse button hidden, drawer close shown. `.inner` padding-top 22. Head-tools full width. AI import → 1 column. Manual sticky top bar offset 72px. |
| `max-width: 760px` | Ring row 4 → 2; savings rows wrap; template type cards horizontal; manual PDF view loses the thumbnail rail |
| `max-width: 700px` | Fill edit pop-up docks to the bottom (`left/right 12px, bottom 12px`) |
| `max-width: 640px` | Full-screen user/stat dialogs (100vw × 100dvh, radius 0); storage grid → 1 column, bars wrap under labels |
| `max-width: 560px` (12×) | Phone density: 2-col tiles, gallery, folders, templates; smaller paddings (cards 16px); KPI 24px numbers; buttons in toolbars flex 1; size slider hidden; tags compressed to icon-only; palette card stacks vertically |
| `@container (max-width: 960/600/460px)` on `.hm-card` | Client stats list: 12 → 6 months → per-client mini-card → compact tags |
| `@container (max-width: 640px)` on `.u-card` | User list: role/status wrap under the name, header row hidden |
| `@container (max-width: 320px)` on `.fi-bd` | Small fill previews: labels only on hover, smaller action dots |
| `(hover: none)` | Gallery card controls always visible |
| `(prefers-reduced-motion: reduce)` | Kill all animation/transition durations |
| `(prefers-color-scheme: light)` | Light tokens unless `data-theme="dark"` |

Rule: use **container queries** for components that live inside cards (lists, tables) so they also react to the sidebar being open. Use viewport queries for shell and page grids.

---

## 3. Components

### 3.1 Buttons `.btn`

| Variant | Class | Fill / text | Shadow | Hover |
|---|---|---|---|---|
| Primary | `.btn` | `--lav` / `--on-accent` | `0 8px 24px var(--lav-glow)` | `color-mix(in oklab, var(--lav) 86%, white)` |
| Secondary ("tertiary") | `.btn.tertiary` | `--surface-3` / `--text`, border `--line-2` | none | bg `--line-3` |
| Ghost | `.btn.ghost` | transparent / `--text-2` | none | `--surface-3` / `--text` |
| Danger | `.btn.danger` | `--rose` / `--on-accent` | `0 8px 24px var(--rose-glow)` | rose mixed 86% with white |
| Ghost danger | `.btn.ghost.danger` | transparent / `--rose-ink` | none | `--rose-deep` |
| Save / confirm (editor, fill) | `.ed-save`, `.fi-ok` | `--mnt` | `0 8px 24px var(--mnt-glow)` | mint 86% white |

Base: inline-flex, gap 8, height 44, padding 0 18, radius 999, 1px transparent border, 14/600, nowrap, `transition: background .2s, box-shadow .2s, transform .12s`.

| Size / state | Rule |
|---|---|
| `.sm` | 40 / pad 16 / 13px |
| `.xs` | 32 / pad 12 / 12px |
| `.icon` | 40×40 |
| `.icon.xs` | 32×32. Icon-only buttons are usually `btn icon xs ghost` with a 16px icon. |
| `:active` | `translateY(1px) scale(.98)` |
| `:disabled` | `opacity:.4; box-shadow:none; pointer-events:none` |
| `.is-loading` | text transparent + 16px spinner (`2px solid var(--on-accent)`, right side transparent) |
| Toggle buttons | `aria-pressed="true"` → `--lav-deep` bg, `--lav-line` border, `--lav-ink` (`.fi-autob`) |
| Dialog footers | buttons are `.sm` |

### 3.2 Inputs

| Component | Spec |
|---|---|
| `.inp` (wrapper label) | flex, gap 10, h44, pad 0 14, radius 14, `--surface-2`, border `--line-2`, leading icon colour `--muted`; `input` transparent, flex 1, colour `--text`; placeholder `--subtle`. Hover border `--line-3`. Focus-within: lavender border + 3px glow. |
| `.inp.sm` | h36, radius 10, pad 0 10, 13px |
| `.inp.err` | rose border + rose glow; pair with `.hint.err` (12px, `--rose-ink`) |
| `.inp.ro` | read-only: transparent, **dashed** border, muted text |
| `.inp.ro-miss` (client) | dashed `color-mix(--pch-ink 60%)` outline = "not specified" |
| Search | `.inp` + `i-search s16` + `input[type=search]`, 280px in the head; `.inp.sm` inside menus/popovers |
| Select | `.inp.sel` > native `select` (appearance none) + absolutely placed `i-chev-down` at right 10px; options use surface bg |
| Textarea | `textarea.inp` with `height:auto; padding:8px 10px; radius 10; resize:vertical` (`.ed-ta`, `.fi-shtx`) |
| Field | `label.field` (flex column, gap 8) > `span` 13/600 label (or `.field-l` with a right-aligned extra; `small` = 500 `--subtle` "nepovinné") + control + `.hint` (12px `--subtle`, optional `i-info s14`) |
| Compact field | `.pal-f`: label 11/600 `--subtle` `.02em`, input h36 |
| Inline-rename input | transparent, 1px transparent border; hover `--line-2`; focus lavender + glow (`.ed-name`, `.ed-ph-n`, `.tp-cat input` with `field-sizing: content`) |
| Range | native `input[type=range]` with `accent-color: var(--lav)` |

### 3.3 Selection controls

| Control | Spec |
|---|---|
| Checkbox `.cb` | 20×20, radius 6, 1.5px `--line-3`, bg `--bg`; `.on` / `.ind` → fill `--a`, check icon 14px `--on-a`. Unchecked icon `visibility:hidden`. Wraps a hidden native input (`.sr`). |
| Radio `.rd` | 20 round, 1.5px `--line-3`; `.on` → border `--a`, bg `--a-deep`, 8px dot `--a` |
| Switch `.sw` | 48×28 track `--surface-3` + line-2 border, 20px knob `--subtle` at 3px; `.on` → track `--a-deep` + `--a-line`, knob `--a` at left 23 + `0 0 12px var(--a-glow)`. `.sm` 36×22 (knob 14), `.lg` 60×34 (knob 26). Use `input[type=checkbox][role=switch].sr` + visual `.sw`. Module switches are scoped to mint. |
| Segmented `.seg` | inline-flex pill track (`--surface`, line border, pad 4, gap 2); buttons h40, pad 0 18, 13/600 `--muted`; `.on` → `--a` / `--on-a`. `.seg.soft .on` → `--surface-3` / text (neutral; used for the period 3/6/12 months). `.seg.full` stretches. |
| Selected tick `.chk` | **20px solid mint circle**, 12px `i-check` in `--on-accent`; `.lg` 28/16. Marks the current choice at the end of menu/list rows (`.mi .end`). Never use a checkbox for single-select lists. |
| Role picker `.rpick > .rcard` | stacked cards: radio + title (14/600) + description (12px muted, 1.45). Pad 12/14, radius 14, line-2 border, surface. `[aria-checked=true]` → lavender border + lav-deep bg + 3px glow. `role="radiogroup"`, roving tabindex, arrow keys. |
| Scope list `.scopes > .scope` | list box (pad 6, line-2, radius 16) of rows: `.cb` + `.who` (title `b` 14/600, description 12px) + `code` scope id (11px, `--surface-3`, radius 6). `aria-disabled` → opacity .45. |

### 3.4 Chips and filter bar

- `.chip`: pill h40, pad 0 16, gap 8, 1px `--line-2`, transparent, `--text-2` 13/600. Hover: border `--line-3`, text. `.on` → `--a-deep` / `--a-line` / `--a-ink`. `.sm` h32. Count `.n` 11/600 `--subtle` tabular (in `.on` it inherits at .75 opacity). Avatar in a chip: `.av` 24px with `margin-left:-10px`.
- `.fbar[role=toolbar]`: flex-wrap, gap 8. Groups are separated by `.sep` (1×24px `--line-2`, margin 0 4).
- Filters on the dashboard: `Vše (n) | ★ Placené · Moje projekty · [Grafik ▾] | S manuálem · Bez manuálu`. They combine with AND. "Vše" clears them.
- Dropdown chip `.fdrop`: a chip with a trailing `i-chev-down s14` that opens `.menu` (absolute, top 46, width 260/300, z 20) with a search `.inp.sm` + listbox of `.mi`/`.qn` rows + an "all" row, then `.menu-sep`, then options. The selected row ends with the `.chk` tick, others with their count.
- Chip with project: `.pf-chip` shows `.sym.sm` 24px + name (max 180px, ellipsis).
- Highlighted chip with pending items: `.req-chip.has:not(.on)` → mint border and text.

### 3.5 Cards

**Project card `.pcard`** (`article`). Flex column, gap 14, pad 20, radius 22, surface, line border. Hover: `--line-3` border + `--shadow-2`. Highlighted (just created) `.hl`: lavender border + glow.
- `.card-top`: symbol `.sym` (56px, radius 16) on the left; `.card-acts` on the right with the paid star `.pstar` (32×32 box, 16px `i-star-f` in `--pch`) and a "…" menu button (`btn icon xs ghost`, `i-more`).
- `.pname`: 17/600 title, a link with a stretched `::after` (inset 0, radius 22) so the whole card is clickable. Hover colour `--lav-ink`. Actions sit above it (z 2–3).
- `.meta-btn`: "N uživatelů" with `i-users s16`, muted 13px. Opens the share dialog.
- Missing-data marker `.pwarn`: a grey 16px filled triangle (`i-warn-f`, `--muted`) hanging on the left card edge (`left:-8.5px; top:27px`), level with the star. It has a title and `aria-label` "Chybí: barevná paleta, písma".
- Card menu: Změnit název · Změnit logo · Sdílet · ☆ Označit jako placený (`menuitemcheckbox`) · sep · **Odstranit projekt** (`.mi.danger`).

**Symbol / monogram `.sym`.** 56px, radius 16, 20/700, `-.02em`. Logo: an `img` on white with 8px padding, `object-fit: contain`. No logo: `.sym.mono` (surface-3, text-2, inset line-2) with initials (first letters of the first two capitalised words). `.sym.sm` is 26px radius 8 (36px radius 11 in the collapsed sidebar). Detail header `.dhead .sym` is 88px radius 24, 32px text.

**Avatar `.av`.** 34px circle, `--lav-deep` / `--lav-ink`, 12/700 initials. Variants: `.av.inv` (invited: transparent, 1.5px dashed-looking inset `--line-3`, `i-mail` icon) and `.av.req` (registration request: mint deep/ink). Sizes 24/26/32/44/52 by context.

**Tile `.tile` / `.pd-tile`.** Pad 22, radius 20, gap 6–10.
- `.pd-ico` 44px radius 12 lav-deep icon square.
- `.pd-t` 17/600 title + `.pd-n` count + caption.
- Clickable `.link`: hover lifts `translateY(-2px)` + `--line-3` border.
- Draggable tiles get a left grip rail `.pd-grip` (24px column, `i-grip`). Drop target `.pd-over` gets a lavender border + glow. The order is personal per viewer.

**Box `.pd-box`** (titled section card): pad 24, radius 22, gap 16. Header `.pd-box-h` = h2 17/600 (+ `.pd-n` count, + optional `.pill`) with a caption under it, and one `.btn.sm` action on the right.

**Stat card `.st-card`**: pad 22/24, radius 22, gap 18. Header `.st-card-h` = h2 16/600 + `.cap` explanation, with legend / scale / controls on the right.

### 3.6 Badges, pills, counters

| Class | Look | Use |
|---|---|---|
| `.count` | solid lavender pill, 22px min, 11/700, `--on-accent` | Sidebar counts, "new" counts |
| `.pill` | 22px, pad 0 9, 11/600, lav-deep / lav-ink; `.mint`, `.pch`; `.pill .i` 12px | Status tags: "Pozvánka", "Vlastník" (pch), "Synchronizovaná" (mint in the editor), "Jen administrátor" |
| Role pills | `.pill.r-user` (surface-3/text-2), `.r-designer` (lav-deep), `.r-admin` (**solid** lavender + `i-shield`) | Role in headers/lists |
| `.pd-n` | neutral counter: surface-2 / muted, 22px, 12/600 | Counts next to titles and tabs |
| `.tag` | 11/600, pad 4 10, surface-3 / muted | Generic neutral tag |
| `.yn` / `.yn.on` | rose-deep "Ne" / mint-deep "Ano" pills | Yes/no columns |
| `.tp-sync`, `.tp-doc`, `.tp-var`, `.tp-prem` | lav-deep "Synchronizováno" · pch-deep "Dokument" · surface-2 ratio chips "4:5" · **solid pch "✦ Premium"** (11/700, `#3A2A20` text) | Template meta |
| `.ed-out` | pch-deep "N mimo plátno" with `i-alert` | Editor warning |
| `.ms-run` | solid pch 18px "probíhá" | Current, unfinished month |
| Status dot | `.u-st .dot` 7px mint = Aktivní; `--line-3` = Pozastavený | User status |

### 3.7 Tabs

- `.tabs-u` (underline): flex, gap 28, bottom border `--line`. Buttons h48, 14/600 `--muted`, optional icon + `.pd-n` count. `.on` → text colour + 2px `--a` bar at `bottom:-1px`. ARIA: `role=tablist/tab/tabpanel`, `aria-selected`, roving tabindex. In the editor, `.ed-tabs` is gap 20 / h40 / 13px. Use for 2–4 page-level views (Statistiky: Klienti / Celý systém / Úspory; Manuál: Logotypy n/5 / Stránky s mockupy).
- `.seg` for compact mode switches inside a toolbar (period, view Mřížka / Náhled stránek).
- Step bar `.ai-steps`: a card containing equal step buttons (h46, radius 13) with a 28px numbered circle `.ai-st-n` (2px border in step colour `--c`). Done = filled circle with a check. Current = `color-mix(--c 16%, surface)` bg. ≤860 shows only the current label.

### 3.8 Menus, popovers, tooltips

| Component | Spec |
|---|---|
| `.menu` | min 220 (typically 240), pad 6, radius 16, `--surface-2`, line-2 border, `--shadow-3`, flex column gap 2. Positioned absolute under its trigger (`top: 38–46px`, right- or left-aligned). |
| `.mi` (menu item) | min-height 40, pad 0 12, radius 10, gap 12, `--text-2`, leading 16px icon. Hover/focus: surface-3 / text. `.danger` → `--rose-ink`. `.end` slot at the right (12px subtle) for a count, tick or shortcut. |
| `.menu-sep` | 1px `--line-2`, margin 4 6 |
| `.lbl` section label | in a menu: `.me-sec` 10px |
| `.qn` row | project picker row: 40px, `.sym.sm` + name + `.end` |
| `.qn-pop` | fixed popover with search + list (300px, radius 18) |
| Floating context menu `.gal-menu` | fixed, z 1000, `width:max-content` |
| Export history `.fi-hpop` | 360px, radius 18, surface, shadow-3. Head (title + caption), rows (name 14/600 + caption, rename and pin icon buttons; pinned rows lav-deep and pinned first), footer "Zobrazit vše (N)". |
| Tooltip `.st-tip` | fixed, z 85, pad 8 12, radius 12, surface-2, line-2, shadow-3, 12px/1.5 `--text-2`, `b` = text. JS tooltip for any `[data-tip]`, on hover and on keyboard focus, clamped to the viewport (js:1425). Otherwise native `title`. |
| Behaviour | Every menu closes on outside `pointerdown`, on Esc (returning focus to its trigger), and on item click. Arrow-key navigation inside `role=menu`. Only one card menu open at a time; `.menu-open` raises the card's z-index. |

### 3.9 Dialogs (native `<dialog>` + `showModal()`)

| Kind | Spec |
|---|---|
| Base | `border:1px solid var(--line-2); border-radius:22px; background:var(--surface-2); padding:26px; width:min(460px, calc(100vw - 32px)); box-shadow:var(--shadow-4)`. `::backdrop` = `--scrim` + `blur(4px)`. Content `.dlg` flex column gap 16. |
| Header `.dlg-h` | h2 19/600 + close `btn icon xs ghost [data-close]` with `i-x s16`, space-between |
| Footer `.dlg-f` | flex-end, gap 8, wrap: `[optional ghost left action] <span style=flex:1> Zrušit (.btn.sm.tertiary) · Primary (.btn.sm)`. The primary is **disabled until the form is valid or dirty**. |
| Confirm (destructive) | `role="alertdialog"`: a 44px rose `.ico-c.danger` with `i-trash` next to an h2 question ("Odstranit projekt?"), a muted explanatory paragraph, footer Zrušit + `.btn.danger` ("Odstranit"/"Odebrat") |
| Scrolling dialog | `dialog.dlg-user`: `width:min(600px,100vw−32)`, `max-height:min(780px,100dvh−32)`, padding 0, flex column. Header fixed (`22px 26px 0`), `.dlg-scroll` body (flex 1, overflow auto, gap 18, pad `20px 26px 22px`), footer fixed with a top border. Suggestion lists render inline (static) inside. |
| Full-screen "page" dialog | `.dlg-user.dlg-full`: `calc(100vw−80px) × calc(100dvh−80px)` (40px margin). Header with avatar/title/meta + actions (ghost buttons, `.up-vr` 1px divider, close). Body: `.up-body` max 1080 of `.up-sec` sections (title + description left 260px, controls right, hairline between). ≤640: true full-screen, radius 0. Has its own URL (`#uzivatel-<id>`, `#statistiky-<id>`). |
| Wide dialogs | Categories 760px, new-template type picker 1120px, gallery picker 980px, "Všechna pole" / "Sdílet na sítě" `100vw−100` × `100vh−100` |
| Close | backdrop click (`e.target === dialog`) and any `[data-close]` |
| Sizes summary | S 460 (default form/confirm) · M 520 (share) · L 600 (invite) · XL 760–1120 · Full (40px inset) |

### 3.10 Toasts, notices, alerts

| Component | Spec |
|---|---|
| `.toasts` | fixed bottom-right (16px + safe area), z 80, gap 8, stacked to the right |
| `.toast` | pad 12 16, radius 14, surface-2, line-2, shadow-3, 13px, max 380, `tin` animation. Leading icon `i-check-circle` in mint-ink; error `.err` uses `i-alert` in rose-ink. Auto-dismiss after 3.6s. The copy is a short past-tense confirmation ("Logo uloženo", "Projekt vytvořen", "Šablona zduplikována"). |
| `.notice` | inline rose-deep strip (pad 14 16, radius 14, 13/1.5) with an `i-alert` icon. Used for system problems ("Ukládání není v tomto zobrazení dostupné…"). |
| `.alert` (kit) | min-height 72, pad `16px 18px 16px 76px`, radius 16, bg `color-mix(in oklab, var(--a-deep) 60%, var(--surface))`, line border. A 4px left bar in `--a` plus a **40px solid accent circle** at left 22px holding the 20px icon in `--on-a`. `.alert-t` 15/600 title, `.alert-d` 13/1.55 `--text-2`. A side button is vertically centred. `.alert.quiet` uses a surface bg. Colour via `.c-info`/`.c-warning`/`.c-success`/`.c-danger`. |
| `.gal-warn` | small pch-deep warning box (12px) inside side panels |

### 3.11 Lists and table rows

**User list** (`.u-card` > `.u-lh` header + `ul.u-list` > `li.u-row` + `.u-foot`):
- Grid `minmax(0,1fr) auto 176px`. Rows have min-height 64 and bottom hairlines. Hover surface-2. Highlighted `.hl` lav-deep.
- `.u-who` = avatar + name link (stretched `::after`; focus outlines the row) + optional `.pill`.
- `.u-tags` = grid `170px 200px`: role (`.u-role` with icon) + status (`.u-st` dot).
- `.u-acts` = "…" menu.
- Ordering: requests first, then "me", then invited, then the rest A–Z (Czech collation).
- Registration requests are pinned rows tinted mint (`color-mix(--mnt-deep 55%)`) with inline Zamítnout / Povolit buttons. Rejected rows are muted and live under the "Zamítnuté" filter.
- Footer: "Zobrazeno X z Y".
- Container ≤640: the header row hides and the tags wrap under the name (indent 46px).

**Share list** (`.share-list`): bordered list box (pad 6, radius 16, max-height 320). `.share-row` 56px: avatar + `.who` (name `b` 14/600, e-mail `span` 12px subtle) + role text + remove. Inside user dialogs each row is its own surface box (radius 14, line-2). A new row flashes (`.flash`).

**Project member rows** `.pu-row`: avatar + name (link to the profile) + right-aligned pills (Vlastník pch, Pozvánka, role pill). Hairline between rows.

**Client rows with expand** (Statistiky › Klienti, `.clg` grid):
- `--cols: minmax(200px,1.4fr) repeat(12, minmax(30px,1fr)) 50px 50px 64px` (name · 12 months · SS · AI · total).
- Each client is a box `.cl-box` (radius 16, surface-2, line-2). Open state: lavender line + glow, with a chevron `.hm-chev` rotating 90° in lav-ink.
- Expanded `.cl-more` animates `grid-template-rows: 0fr → 1fr` (`--dur-3`) and shows per-user horizontal bars `.cl-bar` under the month columns.
- The row toggles expansion; the name link opens the detail dialog.
- Responsive through container queries (12 → 6 months → mini cards → compact).

**Savings rows** `.ms-row`: grid `name | bar | count | Kč`, hairline top.

### 3.12 Empty states and skeletons

- `.empty`: centred column, gap 12, pad 64 24, radius 22, **1.5px dashed `--line-3`**. Contents: `.ico-c` (56px lav-deep circle with a 26px icon; `.danger` rose variant), an 18/600 title, a muted 340–380px paragraph, and one `.btn.sm` CTA or tertiary "Zrušit hledání / Zrušit filtry" buttons.
- Smaller dashed empties: `.tp-empty`, `.ai-empty`, `.share-empty`, `.u-empty` (plain centred subtle text).
- Copy pattern: "Zatím tu nejsou žádné projekty" + what the object is + "Vytvořit první projekt". No-results: `Žádný projekt neodpovídá „dotaz“`. Unbuilt section: "Tuto sekci teprve postavíme".
- Skeleton `.sk`: radius 22, height 196 (a card), shimmer gradient surface → surface-2 → surface, 1.6s. Render 8 of them in the grid with `aria-busy="true"`.
- Spinners: `.btn.is-loading`; `.ai-spin` (32px, 3px surface-3 ring with a lavender top) for long jobs; progress `.gal-prog` (6px track, lavender fill).

### 3.13 Data visualisation

| Component | Spec |
|---|---|
| KPI tile `.st-kpi` | pad 20 22, radius 20. Hover: lift −1px + `--line-3`. `.k` label 13px muted with a 16px subtle icon. `b` 30/600 number with optional `small` ("z 24"). `.s` 12px subtle sub-line with a delta `.up` (mint-ink "▲ 12 %") or `.dn` (rose-ink "▼"). Optional sparkline `.spark` absolutely placed along the bottom 44px (line `--ch-1` 2px, area 16% mix). Variants: `.bad` (rose border, rose-deep → surface gradient, rose-ink number), `.good` (mint), `.warn` (number `--ch-2`), `.price` (clamp 20–30px). |
| Area/bar charts `.svgc` | inline SVG drawn at the container's real width. Grid lines `--line` dashed 2 4. Ticks 11px subtle. Line 2.5px `--ch-1` with draw-in. End dot with a surface stroke + value label. Hover column `.hv` shows a guide line + dot + 7% hit tint + tooltip. Bars `--ch-1` grow staggered; current month bar `--pch`; zero months tinted rose with a rose label. |
| Rings `.ring` | 96px SVG, track `--ch-ghost` 9px, value `--ch-1` (or `.c2` → `--ch-2`), round cap. % 18/600 centred, label 13/600 with icon, `.cap` under. Grid 2 / 4 columns. |
| Ranked rows `.st-rank` | `20px rank | label | value`, track 6px `--ch-ghost` with a gradient fill (60% → 100% `--ch-1`) growing with stagger `--d` |
| Heatmap `.hm` / cells `.hm-c` | 40px cells, radius 8, 3px border-spacing. `lv0` outlined empty with a dash; `lv1`–`lv4` sequential lavender; `.now` = pch with dark text. Sticky first column. Hover: inset lavender ring on the hovered cell only (never scale — it covers the gaps), the row's name turns lav-ink. Group gaps are a `tr.hm-gap` spacer row. Client heatmap: name + `.count` project badge as a disclosure button (projects collapsed), search, `.seg.soft` metric switch Stažení / Šablony (both values + levels server-side as `data-ld` / `data-lt`). |
| Storage meter `.st-stack` | `name 220 | stacked bar 14px (used --ch-1, unused --ch-2) | value 72`, plus a ring for the "Nepoužívané" share and mini KPIs (Celkem, Bez projektu). "Načteno 30. 10. 2026 02:30" + "Načíst znovu" ghost xs. |
| Insight card `.sd-ins-card` | lav-deep → surface 135° gradient with lav-line (or rose for bad). List of bullet rows with 24px icon circles: bad = rose, good = mint, offer = pch, info = neutral. Plain-language Czech sentences ("Už 3 měsíce nestáhli nic (od července).", "…prostor pro nabídku."). |
| Legend `.st-legend` | 10px squares radius 3, 12px muted |

### 3.14 Drag and drop and upload

| Component | Spec |
|---|---|
| `.drop` (dialog) | min-height 200, radius 18, **1.5px dashed `--line-3`**, surface bg, centred `.ico-c` + bold line ("Přetáhněte symbol sem") + `.cap` ("nebo klikněte pro výběr · SVG, PNG, JPG, WebP"). `.on` → lavender border + lav-deep. A preview image sits on white with radius 12. |
| `.gal-drop` (page strip) | min-height 168 (132 mobile), radius 16, 30px lav-ink icon, text with `<u>vyberte</u>` link styling |
| Page-wide drag | `body.gal-dragging #view::after` = fixed inset 16px overlay, radius 24, 2px dashed lavender, lav-deep 80% fill, 18/600 lav-ink message ("Pusťte obrázky — nahrají se sem" / "Pusťte soubory písem — zařadí se do rodin") |
| Drop targets while moving | folders get a dashed lav-line border; hover target `.drop-on` → mint-deep + mint border + scale 1.02. Trash `.gal-trash-b.drop-on` → solid rose + scale 1.05. |
| Reorder | dragged item `opacity:.45`; target `border-color: var(--lav); box-shadow: 0 0 0 3px var(--lav-glow)` (`.man-over`, `.pd-over`); category header target `box-shadow: 0 -3px 0 var(--lav)`. Grips use `i-grip` in `--subtle`, `cursor: grab`. |
| Limits copy | "Obrázek je větší než 10 MB", "Nahrajte JPG nebo PNG" (toast err) |

### 3.15 Domain components

| Component | Key classes | Notes |
|---|---|---|
| Gallery folder | `.gal-fold` (64px, radius 16; `.gal-fi` 38px icon square; name + `.cap` count; "…" revealed on hover); `.on` = lav-deep + lav icon fill | Folders are drop targets |
| Gallery toolbar | `.gal-nav` (Nahrát obrázky primary · Nová složka tertiary · spacer · **Koš** rose pill with count `.n`, turning into "Zpět do galerie"), `.gal-bar` (breadcrumb `.gal-path` with lav-ink crumbs, type chips, search, size slider) | — |
| Gallery tile | `.gal-card` (radius 18; `.gal-th` square well surface-2 with an `object-fit:contain` image inset 12; `.gal-used` "used N×" and `.gal-fmt` "PNG" blurred badges; info row `.gal-meta` = checkbox `.gal-pick` + name 13/600 + `.cap` "1080 × 1080 · 240 kB" + "…" button, always visible). Hover lifts −2px + shadow-2. `.sel` = lavender border + 2px lav-line ring. | Unnamed images show italic subtle "Bez názvu" |
| Bulk bar | `.gal-bulk`: sticky bottom 16, pill, surface-3, line-3, shadow-4, "N vybráno" + actions separated by `.sep` | — |
| Image preview dialog | `.gal-pv`: header (title + count + actions + close), body = stage (bg `--bg`, checkerboard for transparency, prev/next round buttons) + 340px side panel (`dl.gal-dl` key/value rows with hairlines, `.gal-warn`) | — |
| Font family card | `.fn-fam` (name in its own typeface 26px, meta). Cuts list `.fn-cut` = live sample `.fn-prev` (shared sample text and size) + `.fn-meta` chips (`.fn-tag` weight/style/format 20px radius 6; `.fn-use` usage pill "3 šablony" or "Nepoužito") + actions (copy CSS, delete `.fn-del` rose on hover) | Toolbar: Nahrát písma · sample text input (presets Pangram / Abeceda / Číslice) · size range 14–72 |
| Colour card | `.pal-card` (radius 20, horizontal). Swatch `.pal-sw` 112px wide (click = copy HEX, `cursor: copy`; source label `.pal-src` "Z LOGA"/"VLASTNÍ" uppercase 10px; contrast-aware text). Body: HEX field (uppercase 600), role select (Primární/Sekundární/Doplňková/Text/Pozadí/Nepřiřazeno), RGB caption, CMYK 4-up grid (dashed when missing + "Dopočítat z HEX" link button), Pantone + RAL. Autosave. | Palette preview strip `.pal-strip` (28px pill of colour segments). Project detail swatches `.pd-sw` are 24px dot + HEX pills. |
| Logo variant card | `.man-var` with a white preview well `.man-prev` (200px), status pill `.man-st` (mint "Nahráno" / grey "Chybí"), detected colour dots `.man-col` 26px, size fields, width slider, "show on its own page" switch | 5 variants: horizontal, vertical, ± slogan, symbol |
| Mockup page | `.man-pg` card: grip, number `.man-pgn`, title, attachment icon; layout preview `.man-lay` (3×2 grid areas, 11 layouts); layout picker `.man-lc` tiles (`.on` lavender); image slots `.man-slot` (dashed empty with lav-ink icon + recommended size; filled with a blurred dark pill toolbar) | "Náhled stránek" = PDF-reader view with a sticky thumbnail rail |
| Template card | `.tp-card` (radius 22, pad 14): header (grip, name, "Synchronizováno" badge, "…"), preview button `.tp-prev` (image with `0 6px 18px rgba(0,0,0,.25)`), `.tp-exp` "Naposledy exportováno před 3 dny", variant ratio chips | Groups `.tp-group`: collapsible category sections divided by hairlines; header button `.tp-tg` (20px h2 + 32px round chevron `.tp-chev` like the sidebar toggle) |
| "Nejpoužívanější" | `.tp-top`: soft lavender gradient panel (radius 24) with a 34px solid lavender star circle, the top 4 templates by exports in the last 30 days, cards borderless and semi-transparent; caption "N× exportováno za 30 dní" | Automatic, shown only when ≥2 qualify |
| Category manager | `.tp-cats`: e-shop-style pill tags (44px): grip · inline-rename input · count · round trash (disabled when not empty); add tag `.tp-cat-add` dashed | — |
| New-template picker | `.tp-type` cards: SVG wireframe well (150px, `.wf-*` fills: surface-3, line-3, lavender accents, pch spark) + name + description + quiet tertiary button that turns lavender on card hover. Premium card: pch border, pch button, "✦ Premium" badge. | — |
| AI import ("Šablona z obrázku") | 5-step bar (Texty lav, Obrázky mnt, Pozadí pch, Tvary rose, Shrnutí). Stage card with the image and colour-coded boxes `.ai-box` (active: 2px solid `--c` + 18% fill; inactive: dashed 45% at .5 opacity; selected: white + coloured double ring; 12px white handles with a coloured border; hover label `.ai-tag`). Scanning overlay with a sweeping glow bar. 380px sticky side panel with accordion items `.ai-it` (dot, type, transcription, chevron). Sticky blurred footer `.ai-foot` (Back / Next). | Type colour drives everything through `--c` |
| Editor chrome | `.ed` grid rows: top bar 64px (back tertiary icon, eyebrow + inline name, sync pill, undo/redo/clean-view, spacer, save state "Vše uloženo" (mint icon) / "Neuložené změny" (pch), Zobrazení ghost, Vyplnit a exportovat tertiary, **Uložit mint**) · variant tabs bar `.ed-var` (ratio `b` + "1080 × 1350" caption, `.on` surface-3, "+ Přidat rozměr" lav-ink, multi-edit switch) · tools row (36px icon tools) · body grid `264px | canvas | 312px` (no selection → 2 columns) | Left panel: Přidat (Text/Obrázek/Tvar/Seznam/Pozadí) + tabs Vrstvy/Kontejnery. Layer rows 26px, `.on` lav-deep, hidden at .45, a 6px lavender "client input" dot `.ed-in`, lock/eye buttons on hover. Canvas stage: surface-2 with a 20px dot grid, rulers 18px, board with shadow-3. Selection 2px lavender + white 10px handles; client inputs dashed lavender; containers dashed mint. Floating group toolbar `.ed-gtb` (radius 14, shadow-3, 34px buttons, divider). Round zoom bar. Draggable minimap. Right inspector: header (36px lav-deep icon + inline name), "client input" box (lav-deep + switch), `.ed-sec` sections with uppercase 12px titles, 2-col number fields with unit suffix, segmented align, swatch dots 26px (`.on` = 2px surface + 4px lavender ring), HEX field. |
| Fill page chrome | Head: back eyebrow link "Projekt · Šablony", h1 template name, tools (Historie exportů ▾ tertiary · Upravit design ghost (not for clients) · Sdílet na sítě tertiary · Uložit rozpracované tertiary · **Stáhnout vše (ZIP)** primary with a `.fi-n` count). Bar: format chips, toggles (labels / areas / clean preview), size slider. Grid of `.fi-card` per format (header with ratio, ZIP checkbox, PNG button). | Inside the design: areas outlined dashed (text lav, image mnt, colour pch) with tiny corner tags `.fi-tag` and round 22px action dots `.fi-ab` (pencil / palette / image, eye for hiding container rows). Edit pop-up `.fi-pop` (380px, draggable by `.fi-grip`, mint "Uložit"). Container pills `.fi-cp` restore hidden rows. "Všechna pole" dialog: form columns + 220px thumbnail rail. Gallery picker dialog: crumbs, tiles `.fi-gt`, dashed upload `.fi-gdrop`. |
| Share to social | `.fi-sharedlg` (near full-screen): grid `AI chat panel | post preview | 56px network rail`. Chat `.fi-shai` (surface-2 panel; AI avatar = lavender→mint gradient circle with `i-sparkle`; bubbles `.fi-msg` (AI: surface, radius `14 14 14 4`; me: lavender, radius `14 14 4 14`); typing dots; suggestion chips; input + round send `i-arrow-up`; editable post textarea). Preview `.fi-post` mimics **Facebook** / **Instagram** (white card, platform greys `#1c1e21`, `#65676b`, IG ring `#d62976`). Network rail `.fi-net` (40px, `.on` lav-deep + inset lav-line). Header actions: Kopírovat text ghost · Stáhnout obrázek tertiary · Publikovat primary. | — |
| Signed-out screen | `.signed-out` full-screen `--bg`, `.so-card` (400px, pad 40/32, radius 24) | — |

---

## 4. Icons

- **Technique** (L52): every icon is a CSS mask, not an `<svg>` element and not a sprite.
  ```css
  .i { display:inline-block; width:20px; height:20px; flex:none; background:currentColor;
       -webkit-mask: var(--i) center / contain no-repeat; mask: var(--i) center / contain no-repeat; }
  .i-search { --i: url("data:image/svg+xml;utf8,<svg … viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'>…</svg>"); }
  ```
  Usage: `<span class="i i-search s16" aria-hidden="true"></span>`. Colour comes from `color`.
- **Grid:** 24×24 viewBox, **stroke 1.8**, round caps and joins, outline style. Exceptions: filled glyphs (`i-more`, `i-star-f`, `i-warn-f`, social logos, about 6 fills) and one 2.4 stroke.
- **Sizes:** default 20, plus `.s12 .s14 .s16 .s18 .s24 .s32`. Convention: **16 in buttons, menus and meta lines**; 14 in chips and inside small inputs; 12 in pills and badges; 20 in nav and alerts; 26–30 in empty states and drop zones.
- **Source:** "copied 1:1 from the Nocturne kit (nocturne.css)", the in-house Nocturne UI kit. Wboost-specific additions are marked "Admin & integration icons (kit + Wboost sidebar)".
- **Available names (87):**
  - Navigation and UI: alert, arrow-left/right/up/down, chev-down/up/left/right, menu, more, plus, minus, x, check, check-circle, x-circle, info, help, search, filter, sort, refresh, undo, redo, eye, eye-off, lock, unlock, pin, link, copy, download, upload, share, calendar, history, grip, paperclip.
  - Objects: book, edit, grid, image, template, trash, users, user, user-plus, mail, inbox-in, trend, chart, plug, bot, shield, star, star-f, warn-f, file, folder, sparkle, layers, palette, type, font, database, utensils, list, shape, dropper, fit, logout.
  - Brands: facebook, instagram.
  - Editor alignment: al-left/center/right, alh-l/c/r, alv-t/m/b, dist-h/v, front, back.
  - Theme: moon, sun.
- Twig implementation: keep the same CSS (one `icons.css`) and a `{% macro icon(name, size) %}` that outputs the span.

---

## 5. Interaction and accessibility patterns

| Pattern | Implementation |
|---|---|
| Hash routing | Each view and each full-screen dialog has a URL (`#uzivatel-<id>`, `#statistiky-<id>`, `#projekt-<id>/sablony/<tid>/vyplnit`). Closing the dialog returns to the list URL. In Symfony these become real routes. |
| Current page | `aria-current="page"` on sidebar links. Steps use `aria-current="step"`. |
| Comboboxes | Search input `role=combobox` + `aria-controls` + `aria-activedescendant` over a `role=listbox` of `role=option` buttons with `aria-selected`. ArrowUp/Down, Enter, Esc. |
| Menus | `aria-haspopup="menu"` + `aria-expanded` on the trigger; `role=menu/menuitem/menuitemcheckbox/menuitemradio`. Focus moves to the first item on open. Esc returns focus. Outside click closes. |
| Toggles | Chips and filter buttons use `aria-pressed`. Switches are `input[type=checkbox][role=switch]` visually hidden with `.sr` and a visual `.sw`. Radiogroups use roving `tabindex` and arrow keys. |
| Disclosure | `aria-expanded` + `aria-controls` on group headers (`.tp-tg`, `.cl-row`). Chevron rotation 90° or 180°. |
| Dialogs | `<dialog aria-labelledby>`; destructive ones are `role="alertdialog"`. Close on backdrop / `[data-close]` / Esc (native). |
| Status | Icon-only markers carry `role="img"` + `aria-label` (paid star, missing-data triangle). Decorative icons are `aria-hidden="true"`. Live warnings use `role="status"`. |
| Stretched links | The card/row title link carries `::after { inset:0 }`; secondary actions get `z-index:2–3` above it. Focus is shown on the container through `:has()`. |
| Hover-only affordances | Gallery checkbox and "…" are revealed on hover, **always visible under `@media (hover:none)`** and on `:focus-visible`. Newer gallery cards moved the controls into the info row so they are always visible. Follow that for new work. |
| Autosave | Palette, manual and existing pages autosave with a toast "Uloženo". The editor and new forms use an explicit Save with a dirty state (pch "Neuložené změny" / mint "Vše uloženo"). Dialog primary buttons stay disabled until something changed. |
| Persistence per viewer | Sidebar collapsed, recents, theme, size sliders, collapsed template categories, tile order, view mode are all stored in `localStorage` with a `wb-*` prefix. In the real app these are user preferences. |
| Keyboard (editor) | Delete; arrows (Shift = 10px); Ctrl/Cmd+Z, Shift+Z; Esc |
| Reduced motion | Global kill switch (L2007) |
| Safe areas | `env(safe-area-inset-*)` on the sidebar, `.wrap`, toasts, the drawer and the signed-out screen |

---

## 6. Voice and content

- **Language: Czech** throughout, with full diacritics. Tone is friendly and direct, uses the polite plural ("vy"), and is free of jargon ("Přetáhněte symbol sem", "Požádejte grafika o doplnění, aby barvy seděly i v tisku").
- **Greeting eyebrow:** `h < 10 ? 'Dobré ráno' : h < 18 ? 'Dobrý den' : 'Dobrý večer'`, followed by `, Lukáši` (first name in the **vocative**), rendered uppercase by `.lbl`. Other eyebrows name the context: "Administrace", "Projekt", "Projekt · {název}", "{projekt} · Šablony".
- **Titles** are nouns ("Všechny projekty", "Uživatelé", "Statistiky", "Galerie", "Fonty", "Paleta barev", "Manuál", "Šablony"). Counts sit next to the title, not in it.
- **Buttons** are verb + object in the infinitive or imperative: "Nový projekt", "Vytvořit projekt", "Uložit změny", "Poslat pozvánku", "Přidat kolegu", "Nahrát obrázky", "Stáhnout vše (ZIP)". Cancel is always "Zrušit". A destructive button repeats the verb ("Odstranit", "Odebrat", "Smazat trvale").
- **Confirmations** are questions ("Odstranit projekt?"), with consequences in a muted paragraph.
- **Toasts** are short past-tense confirmations ("Projekt vytvořen", "Modul Galerie zapnut", "Pořadí dlaždic uloženo"). Errors say what to do ("Změnu se nepodařilo uložit. Zkuste to znovu.", "Na tuto změnu nemáte oprávnění.").
- **Plurals** use the Czech 1 / 2–4 / 5+ rule, always helper-formatted: `n === 1 ? 'uživatel' : n >= 2 && n <= 4 ? 'uživatelé' : 'uživatelů'`. The same applies to projekt/projekty/projektů, šablona/šablony/šablon, obrázek/obrázky/obrázků, písmo/písma/písem, barva/barvy/barev, podpis/podpisy/podpisů, měsíce/měsíců. Implement one Twig filter (ICU `{count, plural, one{…} few{…} other{…}}`).
- **Numbers:** `toLocaleString('cs-CZ')` (narrow-NBSP thousands: "1 911"), "251 MB", "22,2 MB" (decimal comma), "1 250 Kč", percent with a space ("12 %"), dimensions "1080 × 1350 px", deltas "▲ 12 %".
- **Dates:** `D. M. YYYY` without leading zeros ("30. 10. 2026"); time "02:30". Relative: "dnes", "včera", "před N dny" (< 30 days), then the date. Months: short `led úno bře dub kvě čvn čvc srp zář říj lis pro`; long "říjen"; locative "v říjnu"; "od října".
- **Helper text** explains consequences ("Vypnutím se žádná data nesmažou.", "Odkaz platí 7 dní.", "Bez loga se zobrazí iniciály projektu."). Optional fields are labelled "nepovinné" in a `small` tag.
- **Placeholders** are examples: "Např. Obec Čeladná", "jmeno@firma.cz", "Jméno nebo e-mail".
- **Role names:** Administrátor / Designer (UI also says "Grafik") / Uživatel (shown to staff as "Klient"). Use one term per surface.

---

## 7. Design language rules (for new pages)

1. **Start every page with `.head`:** eyebrow (context) → h1 (noun) → optional count and one-line lead → right-aligned search + **exactly one primary button**. Everything else is tertiary or ghost.
2. **One lavender thing per view.** Primary button, current nav and selection are lavender. Don't add other solid accent fills unless they carry a meaning from §1.4.
3. **Colour means something.** Rose only for destructive/negative, mint only for success/on/selected-tick/save, peach only for paid/premium/warning/"now". Never decorate with accents.
4. **Cards for objects, rows for people and records.** Things with a visual (projects, templates, images, fonts, colours) are cards in auto-fill grids. People, requests, exports and downloads are rows in a single bordered `.u-card`-style list with hairlines.
5. **Boxes for settings.** Group related settings in a `.pd-box` / `.up-sec`: title + one-sentence description, controls beside or below. Prefer the two-column "title left, controls right" section layout for long forms.
6. **Surfaces step one level at a time.** Page `--bg` → card `--surface` → control/well `--surface-2` → hover `--surface-3`. Never place surface-3 directly on bg.
7. **Borders, not shadows, at rest.** Hairline `--line` for cards. A shadow appears only on hover (`--shadow-2`) or for floating layers (menus `--shadow-3`, dialogs `--shadow-4`).
8. **Pills for anything you click that isn't a field.** Buttons, chips, segments and badges are fully rounded. Fields are 14px-rounded rectangles.
9. **Selection = lavender border + 3px glow + deep tint.** Use the same recipe for focused fields, open triggers, chosen cards and drop targets. A single choice in a list ends with the mint `.chk`.
10. **Toolbars are cards.** In-page actions live in a `.gal-nav` strip: primary on the left, spacer, search/view controls/secondary or destructive on the right.
11. **Filter with chips, not selects.** Use `.fbar` chips with counts, a `.sep` between groups, dropdown chips (with search) for long lists, and "Vše" to reset.
12. **Dense but calm.** 14px base, 44px controls (40 in toolbars, 32 inline), 28px between page blocks, 16–20px grid gaps, 20–24px card padding. On phones, drop to 2-column grids and 16px padding rather than shrinking type.
13. **Numbers are tabular and big.** KPI 30/600; always add a comparison line ("minulý měsíc …", a ▲/▼ delta) and an optional sparkline. Charts use `--ch-*`, never the UI pastels. Highlight the current period in peach.
14. **Explain in captions.** Every card or box header gets a `.cap` line saying what it is or what happens. Empty states get a dashed `.empty` with an icon circle, a title, one sentence and one CTA.
15. **Dialogs for focused tasks, full-screen dialogs for entities.** Short forms and confirms use 460px. Entity detail (user, client) uses a 40px-inset full dialog with its own URL. Long forms use a fixed header/footer with a scrolling body.
16. **Destructive actions are last, separated and confirmed:** a menu separator, `.mi.danger`, then an `alertdialog` with a rose icon circle. Soft-delete to a "Koš" where possible.
17. **Reveal secondary controls responsibly.** Hover reveal is fine on desktop but must be visible on touch and on focus. Prefer always-visible compact controls in an info row.
18. **Status speaks in tinted pills, not coloured text blocks.** "Pozvánka", "Vlastník", "Synchronizováno", "Premium".
19. **Role-aware, not role-forked.** Build one page and hide or disable capabilities per role through `body[data-as]`-style switches. Read-only fields become dashed inputs, and missing values get a peach dashed outline plus a `.alert.c-warning` explaining whom to ask.
20. **Motion is short and functional.** 120–320ms with `--ease`. Animate entering (toasts, new rows, new nav items, chart draw-in), never idle. Respect reduced motion.
21. **Container queries for components inside cards.** Lists and tables must survive the sidebar being open.
22. **Write Czech, human, consequence-first.** Verb buttons, "Zrušit" for cancel, plural-correct counts, `D. M. YYYY` dates, `cs-CZ` numbers.

---

## 8. Role-based views

The prototype previews roles from the profile menu ("Náhled role · jen prototyp", js:3215). It sets `body[data-as="admin|designer|user"]`, and CSS plus JS branch on that value. In the real app the role comes from `users.role`.

| Area | Administrátor (`admin`) | Grafik / Designer (`designer`) | Klient / Uživatel (`user`) |
|---|---|---|---|
| Sidebar: Všechny projekty + count | yes | yes | **hidden** |
| Rychlá navigace | yes | yes | **hidden** |
| Project menu (Projekt) | yes | yes | yes (their project only) |
| Administrace (Uživatelé, Poptávky, Statistiky) | yes | **hidden**; routes redirect to `#projekty` | hidden |
| Propojení (Propojené aplikace, AI MCP) | yes | yes | **hidden** |
| Mobile `+` new project, `.back` links | yes | yes | hidden |
| Routing guard | — | admin routes → `#projekty` | anything outside `#projekt-<own>` / `#ucet` → own project |
| Project dashboard: Moduly projektu box | **yes** (pill "Jen administrátor") | no | no |
| Palette link on dashboard | edit pencil "Upravit paletu" | edit | eye "Zobrazit paletu" |
| Fonty | upload, drop, delete, edit | same | **read-only**: no upload, drop zone or delete; copy "(specifikuje je grafik)" |
| Paleta barev | add, edit, CMYK calc, notes | same | **read-only**: disabled fields; empty ones get a peach dashed outline; `.alert.c-warning` "Některé hodnoty u barev nejsou specifikované… Požádejte grafika o doplnění"; toolbar replaced by a full-width palette strip showing HEX |
| Manuál | full editor | full editor | cover card + "Zobrazit manuál" only (`.man-cl`); a missing-variant alert lets them ask the designer or report a problem |
| Šablony list | create, categories, grips, "…" menus; click → editor | same | no create/grip/menu; empty categories hidden; click → **fill page**; lead copy "Vyberte šablonu, doplňte texty a obrázky a exportujte." |
| Editor / AI import | yes | yes | redirected to the list |
| Fill page | + "Upravit design" | + "Upravit design" | without "Upravit design" |
| Profile caption | "Administrátor" | "Grafik · náhled" | "Klient · náhled" |
| Default AI scopes (`AI_DEFAULT`) | all 4 | all 4 | `templates:read`, `templates:export` (the `templates:design` scope is restricted to designer/admin) |

User roles in the admin UI (`ROLES`, js:675):
- **Uživatel**: "Vidí jen projekty sdílené s ním. Vyplňuje šablony a stahuje exporty."
- **Designer**: "Zakládá vlastní projekty, navrhuje šablony a sdílí je klientům."
- **Administrátor**: "Spravuje uživatele, žádosti a celý systém."

---

## 9. New business logic seen in the prototype

Compared with the current wboost app. JS locations are `app.js` line numbers.

| # | Feature | What it is | Where |
|---|---|---|---|
| 1 | Paid projects ★ | `projects.paid` flag, set from the card menu ("Označit jako placený"); peach star on the card; "Placené" filter chip | js:131-260 (`cardHTML`, `act === 'paid'`) |
| 2 | Completeness warning | Card triangle when the palette or fonts are missing (`missingOf`) | js:133 |
| 3 | Dashboard filters | Combinable chips: Placené, Moje projekty, Grafik (owner, searchable), S manuálem / Bez manuálu | js:142-187 |
| 4 | Quick project switcher | Sidebar combobox with the last 3 opened projects (recents) + search | js:66-130 |
| 5 | Project modules | `projects.modules` (manualy, sablony (always on), emaily, jidelnicky, socialni, ai); admin switches per project; sidebar menu reflects them; Galerie/Fonty/Paleta always on | js:1498-1608 (`MODULES`, `projModules`, `renderProjNav`) |
| 6 | Self-service module discovery | "Objevte další moduly": users enable modules themselves and admins get an e-mail (described in copy) | js:1572 |
| 7 | Personal tile order | Draggable project-dashboard tiles stored per viewer | js:1527-1538 |
| 8 | Project members box | Owner + collaborators with role pills; "Přidat kolegu" → share dialog; "Spravovat role projektu" (planned) | js:1540-1590, share dialog js:455-568 |
| 9 | Invite users + roles | Invite dialog (e-mail, optional name, role picker, projects to share, 7-day link), resend / copy invite URL, statuses invited/active/disabled | js:674-1096 |
| 10 | Registration requests | `status: request/rejected`, pinned mint rows with Povolit / Zamítnout, sidebar counter, "Žádosti" filter | js:796-825 |
| 11 | AI scopes per user | OAuth scope ceiling per user (templates:read/export/design, gallery:write) with role restrictions | js:680-686, profile dialog |
| 12 | Full-page user profile | `#uzivatel-<id>` with Profil / Projekty / AI aplikace / Další údaje sections | js:959-1052 |
| 13 | Client statistics (sales tool) | Per-client 12-month download heatmap, alerts (no downloads, streaks, drop/rise ±40 %, dormant templates, unused output types → "prostor pro nabídku"), SS/AI columns, expandable per-user bars, client detail dialog `#statistiky-<id>` with insights | js:1097-1362 |
| 14 | System statistics | Downloads trend (3/6/12 months), output-type rings, top templates, active clients | js:1363-1431 |
| 15 | Storage inventory UI | Total / unused / orphaned MB, per-client stacked bars, last scan time + rescan | inside `renderStSystem` (`STORAGE`) |
| 16 | Savings ("Úspory") | What the account saved vs a designer: new design 1 000–1 500 Kč (midpoint 1 250), synced extra format 500 Kč; month select, KPI tiles, monthly bars, download list | js:1432-1497 |
| 17 | Gallery folders and trash | Folders as drop targets, Koš with restore / delete forever, bulk select, "used N×" badge, rename, move dialog, size slider | js:1609-1850 |
| 18 | Fonts page | Families grouped from file names, weight-sorted cuts, live specimen with a shared sample text and size, usage counts per cut, copy CSS | js:1851-1962 |
| 19 | Rich palette | Colours from the logo vs own, role, auto RGB, CMYK (+ compute from HEX), Pantone, RAL; autosave | js:1963-2056 |
| 20 | Read-only fonts/palette for clients | Clients see specs only; missing values flagged; "ask the designer" alerts | CSS L882-895, js:1885-2007 |
| 21 | Manual: detected logo colours | Backend detects colours per logo variant and builds colour versions automatically; width slider; own-page switch | js:2057-2174 |
| 22 | Manual: PDF-reader preview | "Náhled stránek" with a scroll-synced thumbnail rail and drag-reorder | js:2152-2320 |
| 23 | Client "missing logo variant" flow | Alert with "ask the designer" / "report a problem" actions (toasts) | `manMissAlert`, js:2124 |
| 24 | Template categories as tags | Reorder categories and templates by drag; collapsible groups remembered per viewer | js:2321-2480 |
| 25 | "Nejpoužívanější" | Automatic top-4 templates by exports in the last 30 days | `tpTop`, js:2375 |
| 26 | Template types | Prázdná / Synchronizovaná / Vícestránkový dokument (PDF) / Šablona z obrázku (Premium) | `TP_TYPES`, `tpNew` js:2411 |
| 27 | AI import of templates (Premium) | Upload JPG/PNG; AI segments it into Texty / Obrázky / Pozadí / Tvary; step-by-step review (fix box, transcription, retype, draw missed); summary → editor | js:2481-2656 |
| 28 | Editor upgrades | Variant tabs with an "N mimo plátno" warning, "Upravovat všechny varianty najednou", containers tab, client-input marker per layer, clean-view toggle, minimap, rulers, group toolbar | js:2657-2979 |
| 29 | Fill page in-design editing | Per-area badges (text / colour / image), draggable pop-up, palette + eyedropper, hide/show container rows, "Všechna pole" dialog, per-format ZIP toggles, "Uložit rozpracované" (drafts) | js:2980-3197 |
| 30 | Export history curation | Popover with pinned-first exports, rename, pin, click to load content, "Zobrazit vše (N)" | `fiHist`, js:3117 |
| 31 | Share to social networks + AI copywriter | Facebook / Instagram post preview, AI chat drafts the post text from template content, editable text, copy, download 4:5, publish (needs a connected app) | `fiShare`, js:3083 |
| 32 | Poptávky z webu, Propojené aplikace, AI (MCP server) | New sidebar sections (placeholder pages) | `SECTIONS`, js:272-283 |
| 33 | Light theme | Tmavý / Světlý switch with an OS default | js:3198-3214 |
| 34 | Role preview | Admin / Grafik / Klient view-as (prototype only); defines role-gated UI | js:3215-3247 |
