# Calendar module — implementation handoff for Claude Code

Nov 11, 2026 · @Jan

## 1. Purpose of this document

You are implementing the **Kalendáře (Calendars) module** of Wboost as a fully working, persistent, production feature. Today it exists only as a clickable prototype ("Wboost Prototyp", a single HTML file) that fakes its data and keeps state in the browser.

**Prototype:** [Wboost Prototyp](https://claude.ai/code/artifact/ab3b9009-cd72-4e15-aa3e-e6506f4b9d25). Open it in Chrome and go to a project, then **Kalendáře**. The link is private to its owner, so the browser must be signed in to that claude.ai account. If it does not open, use the prototype's HTML file supplied with the task instead and open it locally. The prototype keeps its data in the browser, so anything you create there stays on that machine only.

This document explains **what the module does and why**, from the user's point of view, and how to verify that your implementation behaves correctly. It deliberately does **not** prescribe technical implementation: data model, storage, rendering technology, component structure and print pipeline are your decisions, to be made in line with the conventions of the target codebase.

How to read it together with the other inputs:

- **The prototype is the reference for look, layout, wording and interaction feel.** All UI copy is Czech and must be kept exactly as in the prototype. Open it and click through each flow described here before you implement that flow.
- **This document is the reference for intent and rules.** Where the prototype is a shortcut (fake data, hard-coded results, toasts that say "prototype"), this document says what the real behaviour must be.
- **Where this document and the prototype disagree, this document wins.** Three such deliberate differences are listed in section 2 (AI features postponed, the 14-day layout, page dimensions).
- Where neither says anything, follow the prototype's behaviour and note your assumption in your final report.

Czech UI labels are quoted in this document exactly as they appear on screen (for example **Propojit prvky**), with an English explanation next to them on first use.

## 2. Scope of this iteration

Build everything the prototype shows under **Projekt › Kalendáře**, with real persistence, except the items listed as out of scope below.

### In scope

- The calendar list of a project: create, open, rename, delete, last-change info.
- The five-step workspace: **1 Typ**, **2 Rozvržení**, **3 Úpravy prvků**, **4 Obsah**, **5 Náhled**.
- The page editor (step 3, also reachable from step 4 as **Styl stránek**): selecting, moving, resizing, layering, hiding and styling elements, the two-level kalendárium editing, snapping, **Propojit prvky**, undo and redo.
- Automatic generation of all pages of the year with correct Czech dates, week numbers, name days and public holidays.
- Content: photos and captions, waste collection, events and clubs, extra names, moon phases, time and season changes, shift tables, special pages, QR codes.
- Per-page overrides, warnings (collisions), comments, change history, the client view and the client link.
- Print settings (bleed, crop marks) and a preview that shows them.

### Out of scope now (second iteration)

Do not implement these. Keep the design ready for them: leave room in the UI structure and data so they can be added without rework, but do not ship dead or fake controls.

| Prototype control | Where | What to do now |
| --- | --- | --- |
| **Naskenovat design** (Premium) card, upload zone, fake progress, **Použít ukázkový kalendář** | Step 2 | Leave out. |
| **Nahrát layout › Z PDF nebo obrázku** | Step 3, Vrstvy header menu | Leave out. The two other menu items stay. |
| The import button **Nahrát z Excelu** | Step 4, Odpady and Akce a spolky | Leave out. The import is rule-based, not AI, but it is postponed to the second iteration. |

Also leave out **Simulovat kolize** (step 5). It exists only to demo warnings in the prototype.

### Three deliberate differences from the prototype

1. **AI features are postponed**, as listed above.
2. **The 14-day calendar has one fixed layout with days on both sides of the page.** The prototype wrongly reuses the 7-day layouts and stacks 14 rows in one column. Details in section 3.
3. **The page is 310 × 145 mm.** The prototype's comments and unit conversions assume 210 × 98 mm. Details in section 3.

## 3. Product concept and vocabulary

A designer (**Grafik**) builds a spiral-bound **desk calendar** for a client, typically a municipality. The designer sets the look once on a sample page. The system then generates every page of the year with the right dates. The client (**Klient**) fills in photos and local content instead of sending proofreading rounds.

The core idea to preserve: **one style, many pages.** Layout and styling are defined once and apply to all week pages. Individual pages only carry content overrides (photo, caption, a different QR code and similar), never their own layout.

### Who uses it

| Role | Sees | Can do |
| --- | --- | --- |
| **Grafik** (designer) | All five steps | Everything in this document. |
| **Klient** (client) | Only step 5 **Náhled**, without the step bar | Photos and captions, waste, events, extra names, comments. No layout or style changes. |

### Pages of a calendar

A calendar is an ordered list of pages, generated from the year, the type and the content settings:

1. **Titulní strana** (cover).
2. Special pages placed **Za titulní stranou** (after the cover), if any.
3. **Week pages.** The first page starts on the Monday of the week that contains 1 January. Pages continue until the week that contains 31 December is covered. Weeks start on Monday and use ISO week numbers.
4. Special pages placed **Před zadní stranou** (before the back page), if any.
5. **Zadní strana** (back page).

For 2027 this gives 53 week pages in the 7-day type (55 pages with cover and back) and 27 week pages in the 14-day type (29 pages). The first and last week pages may contain days of the neighbouring year, and the last 14-day page may run past the first week of January. These days are shown normally with their real dates.

### Calendar types

| Type | Label | Days per page | Layout |
| --- | --- | --- | --- |
| 7 days | **7 dní**, Týdenní | One week | The designer chooses **Fotka vlevo** (photo left, days right) or **Fotka vpravo** (days left, photo right). |
| 14 days | **14 dní**, Dvojtýdenní | Two weeks | **One fixed layout, no choice.** The photo is in the middle, the first week on the left of it, the second week on the right. |

The 14-day rule replaces what the prototype shows. Consequences you must handle:

- In step 2 a 14-day calendar offers no left or right choice. There is exactly one layout and it is applied automatically.
- Both day columns belong to the same **Kalendárium** style. Every part style (day number, day name, name days and so on) applies to all 14 days.
- The second column mirrors the first, so that day numbers sit towards the outer page edges, the same way **Fotka vlevo** and **Fotka vpravo** mirror each other in the 7-day type.
- Switching a calendar between 7 and 14 days changes the available layouts, so the layout must be re-derived. See section 8.

This layout is confirmed but not implemented in the prototype. Derive its default geometry from the 7-day layouts: same header, same proportions of the day rows, photo between the two columns. See point 1 in section 10 for the remaining details.

### Page size and units

- The trimmed page is **310 × 145 mm**, landscape, spiral bound along the top edge.
- Treat width and height as properties of the calendar, not constants. There is no control for them in this iteration, but a later iteration will make them customisable. Nothing in layout, snapping, linking, rendering or print output may assume a fixed size or ratio.
- Every measurement the user sees is in real units of the printed page: positions and sizes in **mm**, type sizes in **pt**. Entering X = 20 mm must put the element 20 mm from the left trim edge on the printed 310 mm page.
- The prototype draws on a 1000 × 468 canvas and its mm and pt conversions assume a 210 mm wide page. Those numbers are wrong for 310 mm. Do not copy them. The aspect ratio is practically the same, so proportions seen in the prototype remain valid.
- A band along the top edge is reserved for the binding holes. It is fixed, not an editable element, and is drawn on every page. In the prototype it is about 8.5 % of the page height.

### Bleed and crop marks

- **Spadávka** (bleed) is an extra margin outside the trim edge on all four sides. Default on, 5 mm, adjustable in 0.5 mm steps.
- **Ořezové značky** (crop marks) are drawn outside the bleed at the four corners. Default on.
- Elements that should print to the edge must reach into the bleed. Nothing is stretched automatically. The default layouts already place edge elements into the bleed.
- In the editor the bleed area is shown hatched around the page, with a dashed line on the trim edge.

### Element vocabulary

A week page consists of nine elements. Their Czech names are used throughout the UI.

| Element | Meaning |
| --- | --- |
| **Fotografie** | The photo frame of the week. |
| **Popisek fotografie** | Caption text for the photo, for example title and author. |
| **Logo** | The client's logo. |
| **Kalendárium** | The block with one row per day. It has inner **parts** (section 6). |
| **Měsíc** | Month and year text, for example LEDEN 2027 or PROSINEC 2026 / LEDEN 2027 when the page spans two months. |
| **Číslo týdne** | Week number text, for example 1. týden. |
| **Hlavička** | Header band behind the month, with colour, gradient or picture. |
| **QR kód** | QR code image. Exists only when QR is enabled in step 1. |
| **Pozadí** | Background of the whole page including bleed. Always the bottom layer. |

## 4. User flow from list to finished calendar

### 4.1 Calendar list

The list lives at **Projekt › Kalendáře**.

- Each calendar is a card: thumbnail of its first week page, name, and a summary line (type · year · layout · number of pages).
- A calendar whose setup was never finished shows the pill **Rozpracovaný vzhled**.
- If the calendar has history, the card shows who changed it last and when. Clicking this opens **Historie**.
- The trash button deletes the calendar. The prototype deletes immediately. See open question 6.
- With no calendars, an empty state explains the feature and offers **Vytvořit kalendář**.
- **Vytvořit kalendář** creates a calendar at once, named **Stolní kalendář {next year}**, type 7 dní, year = next year, and opens step 1. There is no dialog.
- Opening an unfinished calendar returns to the step where the user left. Opening a finished calendar opens step 5 **Náhled**.

The prototype code also contains an older screen with a grid of all pages and a page preview dialog. It cannot be reached in the prototype and is not part of the module. Do not build it.

### 4.2 Workspace top bar (all steps)

- Back arrow to the list.
- Calendar name, editable in place, up to 60 characters.
- The five steps. A step is clickable at any time, except that steps 3 to 5 stay disabled until a layout exists (chosen in step 2).
- Undo and redo buttons, shown only in step 3.
- Save status: **Neuložené změny** after any change, **Vše uloženo** after saving.
- **Uložit** (also Ctrl+S) saves without leaving.
- **Zpět** goes one step back. **Pokračovat** goes one step forward.
- In step 5 the forward button reads **Dokončit** the first time and **Uložit vzhled** afterwards. It saves, marks the calendar finished, returns to the list and confirms with a toast.
- A client sees no steps, no **Zpět** and no forward button.

### 4.3 Step 1: Typ

- **Typ kalendáře**: 7 dní or 14 dní. Each card states how many pages the year will have.
- **Rok**: the current year, next year or the year after. Changing the year renames the calendar if it still has its default name, moves all dated content (events, extra names, exact waste dates) to the same day and month of the new year, and recomputes moon phases.
- **Tisk**: **Spadávka** with its size in mm, and **Ořezové značky**.
- **Co bude kalendář zobrazovat?** A checklist with **Označit vše** (checked, unchecked or mixed). It switches whole content features on and off:

| Item | Default | Effect when on |
| --- | --- | --- |
| Popisky fotografií | on | Caption element is shown under each photo. |
| Zobrazení týdnů | on | Week number element is shown. |
| Fáze měsíce | on | Moon phase icons on the right days. |
| Změna času | off | Start of summer and winter time (last Sunday of March and October). |
| Změna ročního období | off | Start of spring, summer, autumn, winter. |
| Odpady | off | Waste collection icons per day. |
| Upravitelné akce | off | Events of clubs and the municipality in day rows. |
| Šichtovnice | off | Shift columns at the outer edge of the kalendárium. |
| Speciální stránky | off | Extra generated pages. |
| QR kód | off | QR element on week pages. |

A feature that is off disappears from the pages, from the kalendárium part list and from icon choices. Its data is kept, so switching it back on restores it.

Details of this step:

- Bleed size is limited to 0–10 mm. Switching bleed off and on again resets it to 5 mm.
- **Označit vše** switches everything on if at least one item is off, otherwise everything off.
- **Popisky fotografií** and the eye button of **Popisek fotografie** in the editor are the same switch seen from two places. Changing one changes the other.
- Pressing Enter in the calendar name or in a number field confirms it and leaves the field.

### 4.4 Step 2: Rozvržení

- **7 dní**: two cards with a live preview of the first week, **Fotka vlevo** and **Fotka vpravo**. Clicking a card applies it and goes straight to step 3.
- Clicking the card that is already active just continues to step 3 and keeps all customisation.
- Clicking the other card when a layout was already customised **mirrors the current layout horizontally** and keeps the customisation. Every element flips to the other side, and text alignment and the day-number side flip too. This is one undo step.
- **14 dní**: no choice. The single two-sided layout is applied (section 3).

### 4.5 Step 3: Úpravy prvků

The page editor. Sections 5 to 7 describe it in full.

### 4.6 Step 4: Obsah

The screen has a toolbar on top, a page list on the left, the selected page in the middle and a panel on the right.

**Left list**, from the top:

- **Styl stránek** opens the same editor as step 3 inside this step, so the designer can adjust style while looking at content.
- **Speciální stránky** opens special page settings in the right panel.
- A plus button adds a special page: one of the generated kinds not yet on, **Nahrát grafiku** (a finished page as PDF or image) or **Text z Wordu** (text typeset in the calendar's fonts and colours).
- Thumbnails of all pages in order. A thumbnail shows a warning badge when the page has warnings and a dot when the page has its own overrides.

**Middle**: the selected page with previous and next buttons and zoom. When warnings exist, the toolbar shows N upozornění; clicking it jumps to the next page that has a warning.

**Right panel for a page**: warnings of that page, then only the fields that apply:

- **Fotografie**: upload, only when the photo element is set to **Doplním sám**. Otherwise the note that the client supplies photos.
- **Popisek fotografie**: text, up to 80 characters, when captions are on.
- **Obrázek v hlavičce**, **Fotka pozadí**: upload, when the style enables a picture there. The page then uses its own picture instead of the default.
- **QR kód**: upload, when QR is on.
- **Vrátit na styl** removes all overrides of that page.

**Toolbar tools.** Each opens a large dialog closed with **Hotovo** or Esc. A tool whose feature is off in step 1 is dimmed and its dialog offers **Zapnout**.

| Tool | What the user manages |
| --- | --- |
| **Fotky a popisky** | One row per week: photo, caption, remove. **Nahrát všechny fotky najednou** assigns many files to weeks in file-name order and fills captions from the image's embedded description when present. A counter shows how many weeks have a photo. |
| **Odpady** | Waste kinds as tabs (Komunální odpad, Bio odpad, Plast, Papír, Sklo, or a custom kind). Per kind: show or hide, name (custom kinds), optional collection place, and the schedule. **Pravidelně**: one or more weekdays, a week rule (every week, even weeks, odd weeks, every 4th week, every 4th week shifted by 2, first in month) and a from–to date range. **Konkrétní data**: a month grid where clicking a day toggles a collection, a month strip with counts, **Vymazat vše**. |
| **Akce a spolky** | Clubs (short code up to 6 characters, name) and events (date, name up to 80 characters, organiser). Filter by organiser. View by month or whole year. An event of a known club prints as code · name. Optional own icon per event. |
| **Jména navíc** | Extra names by date. They are appended to the name day, for example Karina / Vasil. |
| **Fáze měsíce** | The computed list of phases for the year. The user can change a date or phase, add, remove, and reset with **Přepočítat podle roku**. |
| **Šichtovnice** | One or more operations. Each has a name, shifts (letter, from, to; one to four) and four crews. Presets: 8 h with 3 shifts, 12 h with 2 shifts. |
| **QR kódy** | One QR image per week. Available only when the QR element is set to a different code on every page. |

**Nahrát z Excelu** (shown in the prototype in Odpady and Akce a spolky) is **not part of this iteration**. Do not show the control. Waste dates and events are entered by hand for now.

Dates in content forms are limited to 1 December of the previous year through 31 January of the following year, because the first and last pages reach into those months.

**Speciální stránky.** Generated kinds: **Úvodní slovo**, **Důležité kontakty**, **Roční kalendárium**, **Plánovací kalendář svozu odpadů**. Each has a checkbox, a choice of page layouts shown as thumbnails, a position (after the cover or before the back page) and a preview. **Roční kalendárium** can show the calendar year or the following year. Pages are reordered by dragging. Uploaded pages can be renamed, have their file replaced and be deleted. When **Roční kalendárium** is on, the back page shows client contacts instead of the year overview.

### 4.7 Step 5: Náhled

One large page, thumbnails of all pages on the right with warning badges, previous and next buttons.

- The designer sees the page with bleed and crop marks, as it goes to print. The client sees the trimmed page.
- Toolbar: the same content tools as step 4 (the client only gets Fotky a popisky, Odpady, Akce a spolky, Jména navíc), **Odkaz pro klienta** (designer only), **N upozornění** when warnings exist, **Historie**.
- Photo controls on a week page: **Nahrát fotku** or **Vyměnit fotku**, a zoom slider from 100 to 300 %, dragging the photo inside its frame to choose the crop, and remove.
- A pencil button at the caption opens a small caption editor with a live counter (80 characters).
- A plus button beside every day, on the outer side of the kalendárium, offers **Odpad** (pick a kind, adds a collection on that day), **Akce** (name and organiser) and **Komentář** (a note for the designer). An empty event name or empty comment is not saved and the field keeps focus.
- Days with comments show a bubble with the count. Clicking it shows the thread.
- The small editors float near the click, can be dragged by their grip, confirm with Enter and close with Esc or the close button without saving.
- **Upozornění** lists all warnings grouped by page. Clicking one jumps to that page.
- **Historie** lists changes with author (Grafik or Klient), description and time. Every content change in step 5 is recorded.
- **Odkaz pro klienta** shows a link to copy. Opening it gives the client view of this calendar.

### 4.8 Warnings

Warnings never block anything. They appear as a red exclamation mark on the page, on thumbnails and in the list.

| Warning | Condition |
| --- | --- |
| Portrait photo | A portrait photo is placed in a landscape frame and will be cropped. |
| Low photo quality | The effective print resolution of the photo in its frame, at the current zoom, is below 200 dpi. The message recommends 300 dpi. |
| Too many icons | A day has more icons than the icon layout has cells. |
| Event does not fit | The event text of a day, after wrapping, is taller than the event box. |

## 5. The editor (step 3, Úpravy prvků)

The editor works on one **sample page** (**Vzorová strana**): the first week of the calendar. Whatever the designer does here applies to every week page. The sample page shows real dates, name days and holidays of that week, and every enabled content feature, so the designer styles real output.

One exception: while the part **Změna času** is being styled, the sample switches to the first week that contains a time or season change, so there is something to look at.

### 5.1 Screen anatomy

| Area | Content |
| --- | --- |
| Left: **Vrstvy** (layers) | One row per element, top row = top layer. **Pozadí** is fixed at the bottom with a lock icon. The header holds the **Nahrát layout** menu. |
| Centre: stage | A small toolbar (**Propojit prvky**, the note *Vzorová strana · styl platí pro všechny strany*, zoom), the page with its bleed, and a hint line under the page. |
| Right: properties | Settings of the selected element or part. With nothing selected: *Vyberte prvek na stránce nebo v seznamu vlevo*. A handle on the edge collapses and expands this panel. |

The hint line changes with the state:

- Nothing or an ordinary element selected: click an element, drag it, resize by handles; the change applies to all pages.
- Whole kalendárium selected: click a part again to style it.
- A kalendárium part selected: drag to move it in all rows; Esc returns to the whole kalendárium. If the part was moved, a **Vrátit na původní místo** button appears here.

### 5.2 Selecting

- Click an element on the page, or its row in **Vrstvy**. Both stay in sync.
- The selected element gets a frame with eight handles (four corners, four sides) and a small tag with its name.
- Where elements overlap, a click selects the topmost one under the pointer, following the layer order.
- Clicking the selected row in **Vrstvy** again deselects. Clicking the empty stage around the page deselects.
- Esc steps back one level: part → whole kalendárium → nothing selected.
- **Pozadí** can be selected by clicking bare page background or its row. It has no frame, no handles and no position, because it always covers the whole page including bleed.

### 5.3 Moving

- Drag the element. A drag starts only after the pointer moved about 3 screen pixels, so a click never nudges anything.
- Arrow keys move the selected element by one small step, about 0.3 mm. Shift + arrow moves it ten times as far, about 3 mm. Arrow keys do nothing while a kalendárium part is selected.
- An element can be moved anywhere on the page and into the bleed, but never beyond the outer bleed edge. If bleed is off, the trim edge is the limit.
- Moving keeps the size.
- Moving never affects other elements, even with **Propojit prvky** on.

### 5.4 Resizing

- Drag one of the eight handles. A side handle moves one edge. A corner handle moves two edges.
- The opposite edge or corner stays fixed.
- There is a minimum size (about 6 mm wide and 4 mm high at the current page size). When it is reached, the dragged edge stops and the element does not flip or jump.
- Each edge stops individually at the outer bleed edge.
- Aspect ratio is not locked for any element.
- With **Propojit prvky** on, neighbours follow. See section 7.

### 5.5 Snapping and guides

While moving or resizing, edges snap when they come within about 6 screen pixels of a target. Because the threshold is in screen pixels, zooming in allows finer placement.

Snap targets:

- the four trim edges of the page;
- the four outer bleed edges;
- the vertical centre line of the page;
- the lower edge of the binding band, and the horizontal centre line of the area below it;
- the left, right, top and bottom edges of every other visible element.

Rules:

- When moving, the element's left edge, centre and right edge are all candidates horizontally, and top, centre and bottom vertically. The nearest target wins, independently for each axis.
- When resizing, only the edge being dragged snaps.
- A thin guide line is drawn across the page at every active snap position and disappears on release.
- Holding **Alt** while dragging turns snapping off.
- Hidden elements are not snap targets.

### 5.6 Exact position and size

Every element except **Pozadí** has **Pozice a velikost** in the right panel: **X**, **Y**, **Š** (width), **V** (height) in mm, measured from the top-left trim corner. Each field has minus and plus buttons (0.5 mm) and accepts typed values with a decimal comma. Values are kept within the same limits as dragging. Typed changes update the page immediately. X and Y never affect other elements. Š and V move the element's right or bottom edge, and with Propojit prvky on that edge takes its neighbours along (section 7).

### 5.7 Layers (Vrstvy)

- Drag a row to change the stacking order. A drop indicator shows whether it lands above or below the target row. The page redraws in the new order.
- **Pozadí** cannot be reordered.
- An eye button hides or shows **Popisek fotografie**, **Logo**, **Měsíc**, **Číslo týdne** and **QR kód**. A hidden element is not drawn on any page, is dimmed in the list, loses its selection, and is ignored by snapping and linking. Its settings are kept. **Fotografie**, **Kalendárium**, **Hlavička** and **Pozadí** cannot be hidden.
- The **Kalendárium** row has an arrow that expands the list of its parts (section 6.2). Selecting the kalendárium expands it.
- The **QR kód** row exists only while QR is enabled in step 1.

### 5.8 Nahrát layout

The menu in the **Vrstvy** header replaces the whole layout and style of this calendar with another one:

- from another calendar of the same project (listed by name);
- from a layout file saved from Wboost.

Content (photos, dates, events) is untouched. The action is one undo step, the toast says so, and it is written to the history. An invalid file is rejected with a message and changes nothing. See open question 5 about how a layout file gets saved.

### 5.9 Zoom

Minus, plus and a percentage button. The percentage button resets to **fit to window**. Steps: 50, 75, 100, 125, 150, 200, 300 and 400 %. 100 % means the page fits the available stage. The stage scrolls when the page is larger. Zoom is a view setting only and is never saved into the layout.

### 5.10 Undo and redo

- Buttons in the top bar, Ctrl+Z, and Ctrl+Shift+Z or Ctrl+Y. Shortcuts do nothing while the cursor is in a text field.
- Every layout or style change in the editor is undoable: move, resize (including everything a linked resize changed), arrow-key nudge, layer reorder, hide and show, style changes, loading a layout, mirroring left and right.
- One drag is one step, however far the pointer travelled.
- A new change after undo clears the redo list.
- At least 60 steps are kept. If undo or redo leads to a state where the selected element is hidden, the selection is cleared.
- Undo covers layout and style only. Content edits of step 4 and 5 are not part of this stack.

### 5.11 Text never overflows its frame

**Měsíc** and **Číslo týdne** are single-line texts. If the text becomes wider than its frame (longer month name, larger type), the frame grows sideways to fit the text. This happens after a change, not during a drag. The frame never shrinks by itself.

## 6. Elements and what can be changed

Every setting below belongs to the style and therefore changes all week pages at once. The panel header of each element says so: *Platí pro všechny strany kalendáře*. Each change is visible on the sample page immediately, without a confirm button.

### 6.1 Element properties

| Element | Settings in the right panel |
| --- | --- |
| **Fotografie** | Position and size. **Fotky**: **Doplní klient** (the frame stays an empty slot the client fills in step 5) or **Doplním sám** (the designer uploads photos per page in step 4). **Zaoblení rohů** (corner radius). **Ohraničení** (border): on or off, thickness, colour. **Stín** (shadow): on or off, blur, opacity, offset X, offset Y, colour. |
| **Popisek fotografie** | Position and size. Font family, cut and size in pt. Horizontal and vertical alignment inside the frame. Text colour. Background (see below). Padding from the background edge, when a background is on. |
| **Logo** | Position and size. Upload SVG or PNG, remove. Fit: **Přizpůsobit** (whole logo visible) or **Vyplnit** (fills the frame, cropped). Without a file the frame shows a LOGO placeholder. |
| **Kalendárium** | Position and size. **Názvy dnů**: full names or abbreviations. **Den v týdnu**: beside the number or above it, with its own alignment when above. **Číslo dne**: on the left, centre or right of the block; the text column and the icon zone arrange themselves around it. Background colour and opacity. **Velikosti**: one slider per text kind that is enabled, for quick overall sizing. The Čísla slider sets all four day-number styles together. |
| **Měsíc** | Position and size. Font, cut, size. Alignment. Text colour. **Verzálky** (upper case). Background and padding. |
| **Číslo týdne** | Position and size. Font, cut, size, alignment, colour. **Tvar**: 1. týden, Týden 1 or T1. For 14 days the same forms cover two weeks, for example 1. a 2. týden. If week display is off in step 1, the panel says so instead. |
| **Hlavička** | Position and size. Background: colour, gradient, picture. The picture is either a default image or an empty slot that can be filled per page. Fit and opacity of the picture. |
| **QR kód** | Position and size (always drawn square). **Na každé straně jiný QR kód**: off means one uploaded code repeats on all week pages; on means codes are uploaded per week in step 4, and a page without a code shows none. |
| **Pozadí** | Colour with opacity, gradient, picture, in any combination. The picture is a default that individual pages can replace. Covers the page to the outer bleed edge. |

Shared building blocks used by these panels:

- **Colour picker.** Swatches of the project's brand colours plus a few neutral defaults, and a HEX field. A typed colour that is not in the palette appears as an extra selected swatch. Where it makes sense there is a **Bez barvy** (no colour) swatch. An invalid HEX value is rejected with a message and the old colour stays.
- **Fonts.** Only the font families and cuts uploaded to the project are offered.
- **Background panel (Pozadí).** Three independent checkboxes: **Barva** (with opacity), **Přechod** (gradient), **Obrázek** (picture, with fit **Vyplnit** or **Přizpůsobit** and opacity). They stack in this order, colour at the bottom.
- **Gradient editor.** A preview, a swap button, a list of colour stops (colour, opacity, position), add and remove (never fewer than two), type linear or radial, and an angle for linear.
- **Number fields.** Minus and plus buttons with a fixed step, typed values accepted, unit shown.

Limits the fields enforce. A value outside the range is corrected to the nearest limit.

| Setting | Range | Step |
| --- | --- | --- |
| Any type size | 3–60 pt | 0.5 pt |
| **Velikosti** sliders | Čísla 8–40 pt, Den v týdnu 3–16 pt, other texts 3–14 pt | 0.5 pt |
| Line thickness | 0.1–5 pt |  |
| Icon size (default) | 1.5–12 mm | 0.5 mm |
| Icon size in one cell | 1–30 mm | 0.5 mm |
| Moon phase size | 1.5–9 mm | 0.2 mm (slider 0.1) |
| Photo border thickness | 0.1–10 mm |  |
| Photo shadow offset X and Y | −10 to 10 mm |  |
| Padding of caption and month | 0–20 mm | 0.5 mm |
| Opacity values, gradient stop opacity and position | 0–100 % | 5 % |
| Other numeric style values (corner radius, shadow blur) | 0–30 | 0.5 |

Further behaviour of the shared controls:

- HEX input accepts three or six digits, with or without #. Clearing the field means no colour where **Bez barvy** is offered.
- Switching **Barva** on when no colour is set starts with white.
- Changing the font family keeps the weight if that family has it, otherwise picks the nearest available cut.
- **Přidat barvu** in the gradient editor adds a stop in the middle of the widest gap. The swap button reverses the stops.
- Uploading a picture for a background switches that picture layer on.
- Sliders redraw the page while being dragged and count as one undo step.

### 6.2 Kalendárium: two-level editing

The kalendárium is a single element with one row per day. It is edited on two levels.

1. **First click** selects the whole block. It moves and resizes like any element. The rows always divide the block's height evenly, so making the block taller makes every row taller. Day rows are laid out only inside the trim area: if the block reaches into the bleed, its background fills the bleed but texts and icons stay on the page.
2. **A second click on something inside it** selects that **part**, for example the day number. The same part is then highlighted in every row at once, the frame of the block dims, and the right panel shows that part's style. The tag reads *Kalendárium › part name*.

A part can also be selected from the expanded list under **Kalendárium** in **Vrstvy**. A back arrow in the panel, or Esc, returns to the whole block.

Parts and their settings:

| Part | Shown when | Settings |
| --- | --- | --- |
| **Číslo dne**: Pracovní dny, Sobota, Neděle, Svátky | always | Four separate styles: font, cut, size, colour each. One shared horizontal alignment for all numbers. |
| **Den v týdnu** | always | Font, cut, size, colour. Separate colours for Saturday and for Sunday plus public holidays. |
| **Jména** | always | Font, cut, size, colour. |
| **Svátek** | always | Font, cut, size, colour. Two side handles on the page set the width at which a long holiday name wraps. |
| **Linky** | always | Lines between days on or off, thickness, style (**Plná**, **Čárkovaná**, **Tečkovaná**), colour. |
| **Fáze měsíce** | moon phases on | Position among the icons (shown when more than one icon category is active), size, vertical alignment in the row, colour, optional own icon per phase. |
| **Změna času** | time or season change on | Font, size, colour. **Kde se zobrazí**: with the day's text (under name and holiday) or on its own, with horizontal and vertical alignment. |
| **Akce** | events on | Font, size, colour, horizontal and vertical alignment. **Pole pro akce**: see below. |
| **Šichtovnice** | shifts on | Font, size, colour. Column background: alternating, all or none, and its colour. Add and remove columns. |
| **Odpady** | waste on | Show waste, add a kind, own icon per kind, and optionally print the collection place as text beside the icons with its own text colour. |
| **Ikony** | always | See below. |

**Moving a part.** Dragging a selected part shifts it by the same offset in every row. It snaps back to its original position when dragged close to it, unless Alt is held. **Vrátit na původní místo** in the hint line clears the offset. Waste and moon icons follow the offset of **Ikony** unless they were given their own.

**Pole pro akce (event box).** When **Akce** is selected, every row shows a red dashed box. It marks where event text may go. It can be dragged as a whole to another place in the row and has four side handles. Dragging a handle resizes the box in all rows together. Event text wraps inside the box and never pushes other parts. Text that does not fit raises a warning. The reset button returns both the size and the position of the box. **Výchozí velikost pole** resets the box.

**Ikony (icons in the row).** Icons sit in the zone on the side away from the day numbers.

- **Ikony v řádku** is the ordered list of icon categories: Odpady, Fáze měsíce, Akce, Změna času, Změna ročního období. Position 1 is closest to the outer edge. Reorder by dragging, or with Alt + up or down arrow on a focused row.
- **Přidat ikonu** offers only categories that are enabled in step 1 and not yet in the list. **Odebrat z ikon** removes one. Odpady expands into its active kinds in their own order.
- **Rozvržení ikon v řádku**: 4 in a line, 2 + 2, 1 + 2 or 2 + 1. With these grids the icons of a day fill the cells in the set order. Icons beyond the number of cells are not drawn and raise a warning.
- **Buňka**: clicking a cell in the small diagram selects it; its icon size and alignment can then be set per cell.
- **Velikost ikon (výchozí)** in mm.
- When **Ikony** is selected, dashed violet cells show on the page, and two side handles change the cell width.
- Each category accepts an uploaded icon (SVG or PNG) in place of the built-in one, with a button to return to the default. Seasons can use four icons or one.
- **Prázdné místo pro ikonu u každého dne** reserves an empty dashed slot per day when no icon category is active.

### 6.3 What a day row prints

For every day the generator fills in, without user input:

- the day number, styled by kind: public holiday beats Sunday, which beats Saturday, which beats workday;
- the weekday name;
- the Czech name day, followed by any extra names for that date, joined with " / ";
- the Czech public holiday name, if any. Fixed-date holidays plus Good Friday and Easter Monday, which move with Easter each year;
- events, time or season change, shift letters and icons, when those features are on and something falls on that day.

When rows get short, as with 14 days, the row switches to a compact single-line form and sizes scale down so nothing overlaps. The style settings stay the same.

## 7. Propojit prvky (linking elements)

**Propojit prvky** makes shared boundaries behave like dividers. When the designer drags an edge of one element, every other element that had an edge on the same line moves that edge along. The page stays gapless and aligned without fixing each neighbour by hand.

The typical case: photo, kalendárium and header tile the page. Dragging the line between photo and kalendárium should make one wider and the other narrower in one gesture.

### 7.1 The control

- A toggle button with a link icon in the stage toolbar, labelled **Propojit prvky**.
- **On by default** for every new calendar. The state is remembered per calendar.
- Switching shows a toast: *Prvky propojené — sousedé se přizpůsobí* or *Prvky odpojené — každý zvlášť*.
- The tooltip explains both states.
- Toggling it changes nothing on the page by itself. It only changes how the next resize behaves.
- There is no persistent link between two specific elements and nothing to connect by hand. Linking is decided fresh at the start of every resize, purely from where the edges are at that moment.

### 7.2 The rule

At the moment a resize drag starts, remember the position of every element. Call the element being resized A.

For each edge of A that the drag moves (one edge for a side handle, two for a corner handle):

1. Take the line the edge was on when the drag started.
2. Find every other **visible** element that had an edge on that same line, within a tolerance of about 0.2 mm. It does not matter whether the other element is a neighbour across the line (A's right edge and B's left edge) or aligned on the same side (A's right edge and B's right edge).
3. Move that edge of the other element to the new position of A's edge. The other element's opposite edge stays where it was, so the element gets wider or narrower, taller or shorter. It is resized, never shifted as a whole.

Further rules:

- **Only resizing links.** **Dragging a handle links. So does changing Š or V in Pozice a velikost, by typing or with the plus and minus buttons: the element's right or bottom edge moves and its neighbours on that line follow by the same rule. Moving an element, nudging with arrow keys and changing X or Y** never affect other elements.
- **Page edges never link.** If the edge of A started on the trim edge of the page or in the bleed, nothing follows it. Otherwise every full-bleed element would move together.
- **Hidden elements are ignored**, both as followers and as triggers.
- **Pozadí never takes part.** It has no edges.
- **Collinear is enough.** The two elements do not have to touch. A vertical edge at the same X follows even if the elements are far apart vertically. This is intended: it keeps aligned columns aligned.
- **No chain reaction.** Only edges that sat on the dragged line follow. An element that followed does not drag its own other neighbours.
- **Minimum size of followers.** A follower never becomes smaller than the minimum element size. When it reaches the minimum it stops, while A keeps following the pointer. A and the follower then overlap.
- **Computed from the start position.** During the drag, positions are always derived from the snapshot taken at the start, not accumulated step by step. Dragging back to the starting point restores every follower exactly, including one that had hit its minimum.
- **Snapping comes first.** A's dragged edge snaps as described in 5.5, using the start positions of other elements as targets. Followers then go to the snapped position. Alt disables snapping but not linking.
- **Live feedback.** Followers redraw continuously during the drag, not on release.
- **One undo step** restores A and all followers.
- After release, elements whose edges no longer coincide (for example after an overlap caused by the minimum size) are simply no longer linked on the next drag.

### 7.3 With linking off

Only A changes. Gaps and overlaps with neighbours are the designer's responsibility. Everything else (snapping, limits, undo) works the same.

### 7.4 Worked examples on the default layout Fotka vlevo

In the default 7-day layout the edges coincide as follows:

- The header's bottom edge, the photo's top edge, the kalendárium's top edge and the month text's bottom edge are on one horizontal line.
- The photo's right edge and the kalendárium's left edge are on one vertical line.
- The kalendárium's bottom edge and the week number's top edge are on one horizontal line.
- The month text and the week number share the same left edge and the same right edge.
- Logo and caption share no edge with anything.
- The photo's left and bottom edges, the header's top, left and right edges and the kalendárium's right edge lie on the page edge or in the bleed.

| Action with linking on | Expected result |
| --- | --- |
| Drag the photo's right handle to the left | The photo gets narrower. The kalendárium's left edge follows, so it gets wider by the same amount. Its right edge does not move. Nothing else changes. |
| Drag the kalendárium's left handle to the right | Mirror case: the kalendárium gets narrower and the photo wider. |
| Drag the header's bottom handle down | The header gets taller. The photo and the kalendárium start lower and get shorter, their bottoms stay. The month text's bottom edge follows too, so its frame gets taller. |
| Drag the kalendárium's top handle down | The photo's top edge and the header's bottom edge follow, as does the month text's bottom edge. |
| Drag the kalendárium's bottom handle up | The week number's top edge follows, so its frame gets taller. Its bottom stays. |
| Drag the month text's left handle | The week number's left edge follows, because the two are aligned. |
| Drag the photo's left or bottom handle inwards | Only the photo changes. These edges started on the page edge. |
| Drag the logo's or the caption's handles | Only that element changes. No shared edges. |
| Drag the kalendárium's top-left corner handle | Both rules apply at once: the photo's right edge follows horizontally, and header, photo and month text follow vertically. |
| Move the kalendárium by dragging its body | Only the kalendárium moves. |
| Hide the week number, then drag the kalendárium's bottom handle | Only the kalendárium changes. After showing the week number again it is where it was. |
| Switch linking off, drag the photo's right handle to the left | Only the photo changes. A gap opens between photo and kalendárium. |
| With that gap open, switch linking on and drag the photo's right handle again | The kalendárium does not follow, because the edges no longer coincide. Drag the photo's edge until it snaps to the kalendárium's left edge and release. From the next drag on they move together again. |

The same behaviour applies in the mirrored layout **Fotka vpravo**, in the 14-day layout, and in the editor opened from step 4 as **Styl stránek**.

## 8. Situations that can happen

Rows marked *(recommended)* describe behaviour the prototype does not define. Implement the recommendation unless the codebase has an established convention, and list it in your report.

### Editor

| Situation | Expected behaviour |
| --- | --- |
| An element is completely covered by another | It cannot be clicked on the page. It is selected through **Vrstvy** and can then be moved and resized by its frame, which is always drawn on top. |
| The selected element gets hidden | Selection is cleared and the right panel returns to the empty state. |
| A kalendárium part is selected and its feature is switched off in step 1 | The part disappears from the list and the page. Selection falls back to the whole kalendárium. Its style is kept for when the feature returns. |
| QR is switched off in step 1 while the QR element is selected | The QR row and element disappear and the selection is cleared. Position and settings are kept. |
| The user drags an element far outside the page | It stops at the outer bleed edge and keeps its size. |
| The user resizes past the opposite edge | The element stops at its minimum size. It never inverts. |
| A click without movement on a selected kalendárium | Selects the part under the pointer. It does not move anything. |
| A drag starting on a kalendárium part that is not the selected part | Moves the whole kalendárium and clears the part selection. |
| Typing a value outside the limits into X, Y, Š or V | The value is corrected to the nearest allowed value and the field shows the corrected number. |
| Keyboard shortcuts while a text field has focus or a dialog is open | Arrow keys, Esc, undo and redo act on the field or dialog, not on the page. |
| The browser window is resized | The page rescales to fit. Selection frame, handles and guides stay aligned with the page. |
| Narrow screens | The side panels stack under the page instead of disappearing. |
| A layout is loaded from another calendar | Everything in layout and style is replaced, selection is cleared, one undo step brings the old layout back. *(recommended)* Offer only calendars of the same type, 7 or 14 days. |
| Undo after switching **Fotka vlevo** to **Fotka vpravo** | The layout returns to its unmirrored state. |

### Type, year and print settings

| Situation | Expected behaviour |
| --- | --- |
| Type is switched between 7 and 14 days after the layout was edited | *(recommended)* Ask for confirmation. Keep all styles (fonts, colours, part settings, icon order). Reset element positions to the default layout of the new type. See open question 2. |
| Year is changed after content was entered | Dated content moves to the new year, moon phases are recomputed, the page list is regenerated. A date that does not exist in the new year (29 February) must not break anything. *(recommended)* Drop it and say so. |
| Year or type is changed while pages have their own photos or captions | Overrides belong to a specific week. *(recommended)* Warn before the change that page-specific content may no longer match, and keep overrides for weeks that still exist by week number. |
| Bleed is switched off, on, or its size is changed after elements were placed | The prototype does not adjust elements. See open question 7. At minimum no element may end up outside the allowed area. |
| A page spans two months or two years | **Měsíc** shows both, for example PROSINEC 2026 / LEDEN 2027. |
| A date has no name day | Nothing is printed in its place. |

### Content

| Situation | Expected behaviour |
| --- | --- |
| A week has no photo | The frame shows a neutral placeholder, labelled *FOTKU DOPLNÍ KLIENT* when the client supplies photos. |
| A photo is replaced | Crop position and zoom return to centred and 100 %. Warnings are recalculated. Every upload path (the row in step 4, bulk upload, step 5) must record the photo's pixel size so that cropping and warnings work. The prototype does this only in step 5. |
| A photo is zoomed in | The low-quality warning accounts for the zoom, because zooming lowers effective resolution. |
| Bulk photo upload with a different number of files than weeks | Files are assigned in name order starting with the first week. Extra files are ignored, missing ones leave weeks empty. |
| Several events on one day | Up to four are printed, each on its own lines inside the event box. A warning appears when they do not fit. |
| An event or extra name with an empty text | It is not printed. |
| A club is deleted while events refer to it | The events stay and print without the club code. |
| Waste schedule: end date before start date | *The prototype shows the message Konec svozu je před začátkem but keeps the date. (recommended) Reject the change and keep the previous date*. |
| Waste schedule: the last selected weekday is unselected | Rejected with *Vyberte aspoň jeden den*. |
| Import from Excel | Not available in this iteration. No import control is shown. |
| A waste collection is added to a single day in step 5 | It is an extra collection on that date only. The regular rule is untouched. |
| A special page of an uploaded file is deleted | It is removed from the page list and a toast confirms. Generated kinds are switched off, not deleted. |
| Large images | Uploads must work for print-size photos. The prototype's save failure for large images is an artefact of browser storage and must not exist. |

### Roles, saving and history

| Situation | Expected behaviour |
| --- | --- |
| A client opens the client link | Only step 5 is available, with the limited tools. Layout and style cannot be changed by any route. Enforce this on the server, not only by hiding controls. |
| A client changes something | The change is saved, visible to the designer and recorded in **Historie** as Klient. |
| The user leaves with unsaved changes | See open question 4. |
| Designer and client edit at the same time | See open question 8. |
| **Dokončit** on a calendar with warnings | Allowed. Warnings inform, they do not block. |

## 9. How to verify the implementation

The work is done when every check below passes in the running application, driven through a real browser, and the same checks exist as automated tests in the repository. Do not mark a check as passed from reading code.

How to work:

- For each flow, first perform it in the prototype and note what happens. Then perform it in your build and compare.
- Cover the pure rules (page generation, dates, holidays, snapping, linking, limits) with fast unit-level tests.
- Cover the interactions with end-to-end tests that really drag with the pointer and press keys. Assert on resulting positions and on saved state, not only on what is drawn.
- Compare screenshots of the sample page against the prototype for the default layouts.
- After every scenario that changes data, reload the page and confirm the result is still there.

### 9.1 Generation (year 2027)

| # | Check | Expected |
| --- | --- | --- |
| G1 | Page count, 7 dní, no special pages | 55 pages: cover, 53 week pages, back. |
| G2 | Page count, 14 dní, no special pages | 29 pages: cover, 27 week pages, back. |
| G3 | First week page, 7 dní | Monday 28 December 2026 to Sunday 3 January 2027. Month text PROSINEC 2026 / LEDEN 2027. Week number 53. |
| G4 | Second week page, 7 dní | 4 to 10 January 2027. LEDEN 2027. Week number 1. |
| G5 | Last week page, 7 dní | 27 December 2027 to 2 January 2028. PROSINEC 2027 / LEDEN 2028. Week number 52. |
| G6 | First week page, 14 dní | 28 December 2026 to 10 January 2027, first week on the left and second on the right. Week text names weeks 53 and 1. |
| G7 | Public holidays | 1 Jan, 26 Mar (Velký pátek), 29 Mar (Velikonoční pondělí), 1 May, 8 May, 5 Jul, 6 Jul, 28 Sep, 28 Oct, 17 Nov, 24, 25 and 26 Dec are styled as holidays and print the holiday name. No other day does. |
| G8 | Easter in other years | Good Friday and Easter Monday are correct for 2026 (3 and 6 April) and 2028 (14 and 17 April). |
| G9 | Name days | 2 January prints Karina. With the extra name Vasil on that date it prints Karina / Vasil. |
| G10 | Day number style priority | A holiday on a weekday uses the holiday style. Saturday and Sunday use their own styles. |
| G11 | Time change, when on | Marked on 28 March 2027 and 31 October 2027. |
| G12 | Week number forms | Switching the form changes every week page: 1. týden, Týden 1, T1. |
| G13 | Special pages | Switching one on adds a page at the chosen position. Changing position or order changes the page list accordingly. Page numbers in all lists update. |
| G14 | Year change | Switching 2027 to 2028 regenerates all pages, renames a calendar that still has the default name and moves dated content. |

### 9.2 Editor

| # | Steps | Expected |
| --- | --- | --- |
| E1 | Click the photo on the page | Frame with eight handles and the tag Fotografie. The row in Vrstvy is highlighted. The right panel shows photo settings. |
| E2 | Press Esc | Nothing is selected. The panel shows the empty state. |
| E3 | Drag the logo by 30 mm to the left | It follows the pointer. X in the panel drops by 30 mm. Size unchanged. After reload it is still there on the sample page and on any other week page. |
| E4 | Drag the logo slowly near the vertical page centre | It snaps. A vertical guide appears and disappears on release. Repeat with Alt held: no snap, no guide. |
| E5 | Drag the logo so its edge approaches the photo's edge | It snaps to that edge and a guide shows. |
| E6 | Select the logo, press right arrow, then Shift + right arrow | It moves one step, then ten steps. |
| E7 | Drag the logo far past the right page edge | It stops at the outer bleed edge. With bleed off, at the trim edge. |
| E8 | Drag the caption's right handle far to the left | It stops at the minimum width. The left edge has not moved. |
| E9 | Type X = 20 and Š = 80 for the logo | It sits 20 mm from the left trim edge and is 80 mm wide. Confirm by measuring the rendered page against the 310 mm width. |
| E10 | Drag the Logo row above and below other rows in Vrstvy | Stacking on the page changes to match. Pozadí stays last. |
| E11 | Click the eye on Číslo týdne | It disappears from the sample page and from every week page in steps 4 and 5. Click again: it returns with the same position and style. |
| E12 | Click the kalendárium, then click a day number | First the whole block is selected. Then all workday numbers are highlighted in every row and the panel shows the number style. |
| E13 | With the number part selected, change its colour and size | Every workday number changes on the sample page and on other week pages. Saturday, Sunday and holiday numbers keep their own styles. |
| E14 | Select the part Jména and drag it 3 mm to the right | The names shift in every row. The hint offers Vrátit na původní místo. Clicking it restores them. |
| E15 | Switch events on, select the part Akce, drag the right handle of the red box | The box changes in every row. Long event text wraps to the new width. |
| E16 | Select Ikony, change the layout to 2 + 2, reorder two categories | Icons on days that have them rearrange accordingly. |
| E17 | Make ten different changes, press Ctrl+Z ten times, then Ctrl+Shift+Z ten times | The page returns to the original state step by step and then to the final state. Buttons are disabled at both ends. |
| E18 | Change the month's font size until the text is wider than its frame | The frame grows to fit. No text is clipped. |
| E19 | In step 2 click Fotka vpravo on a customised Fotka vlevo calendar | Every element mirrors. Custom sizes are preserved. Ctrl+Z in step 3 mirrors back. |
| E20 | Zoom to 200 % and repeat E3 and E4 | Same results in mm. Snapping needs to be closer in mm, since the threshold is in screen pixels. |
| E21 | Open step 4, choose Styl stránek, repeat E1, E3 and E12 | Identical behaviour to step 3. |

### 9.3 Propojit prvky

Run every row of the table in section 7.4 as its own test. Each test must assert the position and size of **all** elements before and after, so that elements that should not move are checked too. In addition:

| # | Steps | Expected |
| --- | --- | --- |
| L1 | New calendar, open step 3 | The toggle is on. |
| L2 | Switch it off, reload | It is still off. A toast appeared on switching. |
| L3 | Linking on. Drag the photo's right handle left by 40 mm, release, press Ctrl+Z once | Photo and kalendárium both return to their original sizes in one step. |
| L4 | Linking on. Drag the photo's right handle left, and without releasing drag back to the start | Both elements are exactly as before. No drift. |
| L5 | Linking on. Drag the photo's right handle to the right as far as it goes | The kalendárium shrinks to its minimum width and stops. The photo continues. Dragging back without releasing restores both. |
| L6 | Linking on. Drag the photo's right handle with Alt held | No snapping, but the kalendárium still follows. |
| L7 | Linking on. Drag the header's bottom handle down | Observe the followers live during the drag, not only after release. |
| L8 | Linking on. Repeat the first row of 7.4 in Fotka vpravo and in the 14-day layout | The equivalent neighbours follow. |
| L9 | Linking on. Move the logo with arrow keys and by typing X and Y. Then give the photo a new Š (width) by typing | Moving changes nothing else. The typed width moves the photo's right edge and the kalendárium's left edge follows, as in a handle drag. With linking off, only the photo changes. |

### 9.4 Content, preview and warnings

| # | Steps | Expected |
| --- | --- | --- |
| C1 | Step 4: upload three photos named 01, 02, 10 with the bulk upload | They land on weeks 1, 2 and 3 of the list in that order. The counter reads 3 of 53. |
| C2 | Step 5: upload a photo, set zoom to 200 %, drag it | The crop changes inside the frame and persists after reload. History has entries. |
| C3 | Upload a small or portrait photo into the landscape frame | The matching warnings appear on the page, on the thumbnail and in the warnings list. |
| C4 | Waste: set Bio odpad to every Thursday, weekly, 1 April to 30 November | Icons appear on exactly those Thursdays and on none outside the range. |
| C5 | Waste: switch a kind to Konkrétní data and click three days | Icons appear on exactly those days. The month strip shows the count. |
| C6 | Open Odpady and Akce a spolky in step 4 | No Nahrát z Excelu control is shown. Dates and events can be entered by hand. |
| C7 | Add an event for club ZŠ on a date | The day prints ZŠ · name. With the event icon on, the icon shows in that row. |
| C8 | Add events until one day has more text than the box holds | A warning appears for that day. |
| C9 | Step 5: plus button on a day, add Odpad, then Akce, then Komentář | Each appears on that day. The comment bubble shows 1. History lists three entries. |
| C10 | Step 5 as designer and as client | The designer sees bleed and crop marks. The client sees the trimmed page. |
| C11 | Switch bleed off and crop marks off in step 1 | The step 5 preview shows neither. |
| C12 | Set a page-specific caption in step 4, then use Vrátit na styl | The page shows the caption and the dot on its thumbnail. After reset both are gone. |

### 9.5 Roles and persistence

| # | Steps | Expected |
| --- | --- | --- |
| R1 | Open the client link | Step 5 only, no step bar, four content tools. |
| R2 | As client, try to reach steps 1 to 4 or to send a layout change directly | Refused. |
| R3 | As client, change a caption. As designer, open Historie | The change is listed as Klient. |
| R4 | Change something in each step, save, reload | Everything is preserved, including selection-independent settings such as linking. |
| R5 | Finish a calendar, reopen it from the list | It opens in step 5. The list card no longer shows Rozpracovaný vzhled. |
| R6 | Create a second calendar and load the layout of the first | The second looks like the first. Changing the second afterwards does not change the first. |

### 9.6 Scope guards

- No AI control from section 2 is present anywhere in the UI.
- A 14-day calendar never shows the Fotka vlevo or Fotka vpravo choice.
- A search of the code finds no hard-coded page dimensions outside the one place that defines the default 310 × 145 mm. A test renders a calendar with different dimensions and confirms that layout limits, snapping to page edges and centre, mm values and the preview all follow.

### 9.7 Final report

Finish with a short report: which checks pass, which fail and why, every assumption you made where this document or the prototype was silent, and every recommended behaviour from section 8 that you implemented or changed.

## 10. Open questions and assumptions

These points are not settled by the prototype or the brief. Each has a default so that work is not blocked. Implement the default, keep it easy to change, and list it in the final report.

1. **Default geometry of the 14-day page.** Confirmed: photo in the middle, first week on the left, second week on the right, one fixed layout. Not in the prototype, so still to be designed: exact placement of header, month, logo and caption, and how the two day columns appear in the editor. Default: the two columns are two frames of the one **Kalendárium** element (first week, second week), each movable and resizable, sharing all styles and parts; the header spans the page as in the 7-day layouts.
2. **Switching type after customising.** Default: confirm, keep styles, reset positions to the new type's default layout.
3. **Excel import without AI.** Decided: the import is rule-based, not AI, and the whole import moves to the second iteration.
4. **Unsaved changes.** The prototype has an explicit **Uložit** and a status text, and silently loses unsaved work on leaving. Default: keep explicit saving, and ask before leaving the workspace with unsaved changes.
5. **Layout file.** The prototype can load a layout from a file but offers no way to save one. Default: add **Uložit layout do souboru** to the same menu so the load option is usable.
6. **Deleting a calendar.** The prototype deletes on one click. Default: ask for confirmation, since photos and content are lost.
7. **Changing bleed after layout.** Default: when bleed is switched on or resized, edges that sat exactly on the trim edge or on the old bleed edge move to the new bleed edge; when switched off they return to the trim edge. Other edges do not move.
8. **Designer and client editing at the same time.** Default: content changes are saved item by item, so two people changing different things do not overwrite each other; for the same item the last save wins and both entries appear in **Historie**.
9. **Print output.** The prototype ends at an on-screen preview with bleed and crop marks. A print-ready PDF export is the obvious next need but is not shown. Default: not part of this iteration; the preview must already be dimensionally exact so an export can reuse it.
10. **Uploaded special pages.** **Nahrát grafiku** places a PDF or image as a full page. **Text z Wordu** typesets a document's text in the calendar style. Expected fidelity is not defined. Default: full-page image or first PDF page scaled to cover the page including bleed; plain paragraphs of the document's text under a heading in the header style.
11. **Client link.** Whether the link works without signing in, and whether it can be revoked or expires, is not defined. Default: an unguessable link that grants the client view of that one calendar, which the designer can regenerate.
12. **Binding band.** Its exact height in mm at 310 × 145 mm and the hole pattern come from the print shop. Default: keep the prototype's proportion until real values are supplied, and keep it as a property of the calendar next to the page size.

Added after a line-by-line review against the prototype:

13. **Content of cover, back and special pages.** In the prototype these pages are wireframes: grey placeholder lines, a dashed LOGO box, the client name and the year. There is no way to enter the cover photo, the intro text, the contact rows or the back-page contacts. Only the layout choice and the page background are real. Default: build these pages exactly as far as the prototype goes (generated structure, real year grid, real waste grid and event legend from the calendar's data, placeholders elsewhere) and treat editing their content as a separate task that needs a design.
14. **Removing things added in step 5.** A waste collection added to a single day with the plus button cannot be removed anywhere in the prototype, and comments cannot be deleted or marked as done. Default: the plus menu of a day lists that day's extra collections and events with a remove button; comments stay permanent.
15. **QR code source.** The prototype takes QR codes as uploaded images. Its code also carries an unused path for a QR generated from a URL. Default: uploaded images only.
16. **Photo and logo source.** Uploads come from the user's disk. Whether they should also be picked from the project's **Galerie** and brand assets is not shown. Default: disk upload only.
