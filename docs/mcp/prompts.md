# Prompts that work

Copy-paste prompts for a client connected to the wboost MCP server (see
[`connect.md`](connect.md)). They cover the jobs the nine shipped tools actually
do — orienting in an account, filling a template variant, getting a finished PNG
out, adding a picture to the gallery, and authoring a design.

**The prompts are in Czech**; wboost's users and their template copy are Czech,
and asking for Czech copy in Czech is what keeps the assistant from drafting
English headlines. The commentary around them is English, matching the rest of
`docs/`. An English prompt works exactly as well — the tools are
language-agnostic.

Prompts 1–10 need `templates:read` (plus `templates:export` for #6); 11 needs
`gallery:write`; 12–14 need `templates:design`.

---

## 1. What do I have

> Vypiš mi moje wboost projekty — u každého kolik má šablon a variant, jaké má
> brandové fonty a barvy.

One `get_context` call. This is the right opening move in any wboost session; it
is also the fastest way to confirm the connection works.

There is a slash command for it: `/wboost:projects`.

---

## 2. Find a template

> V projektu Studio Brno najdi šablony na plakáty. U každé mi řekni, jaké má
> varianty a v jakém rozměru.

`get_context` → `find_templates` with a query. The query matches the template
name **and** its category, so "Instagram" finds a category even when no template
name says it.

---

## 3. What goes into this template

> Vezmi šablonu Plakát A4 a řekni mi, co všechno se do ní vyplňuje — jaká pole,
> jaké mají limity a co je povinné.

`describe_variant`. You get the fillable inputs with their `maxLength`, which are
locked, which can be hidden, and which share a container (and therefore compete
for the same vertical space).

---

## 4. Fill it and show me

> Do šablony Plakát A4 dej: nadpis "Jarní workshop", podtitul "Práce s
> keramikou pro začátečníky", datum "14. března 2026", místo "Studio Brno".
> Ukaž mi náhled.

The core loop. The assistant should call `describe_variant`, map your copy onto
the input ids, and call `render_variant` — a cheap downscaled preview, not the
deliverable.

---

## 5. Iterate on the copy

> Podtitul je moc dlouhý, zkrať ho na dva řádky a znovu ukaž náhled.

Repeat renders are the point of `render_variant`: lossy WebP, ≤ 1200 px on the
long edge, and not counted as an export. Iterate here as much as you like.

---

## 6. Give me the file

> Vypadá to dobře, vyexportuj to.

`export_variant` — full-size lossless PNG at the variant's designed size (2480 ×
3508 for A4 at 300 DPI). Needs the `templates:export` scope, and **is** recorded
in the project's usage report, so ask for it once, at the end.

`/wboost:export` runs prompts 2 → 6 as one command:

> /wboost:export plakát A4 na jarní keramický workshop, 14. 3. 2026, Studio Brno

---

## 7. Same message, every format

> Máme post na Instagram ve třech rozměrech. Vyplň do všech tří text
> "Otevíráme novou pobočku" a "Přijďte 20. dubna" a ukaž mi náhledy vedle sebe.

Variants of one template are its dimensions. Each has its **own** input ids, so
the assistant has to `describe_variant` each one — it cannot reuse the first
variant's ids. Watch for a `grouped: true` flag: those variants are kept in sync
by the designer through the group editor, which does not change how you fill
them (only how they are *designed*).

---

## 8. Use a real picture

> Ukaž mi, jaké obrázky jsou v galerii projektu Studio Brno, a do fotky na
> plakátu dej tu s keramikou.

`list_gallery` walks the folder tree one level at a time; each picture reports
its id, URL and pixel size. The image slot says which folders it accepts and
whether the picture may be moved, resized or rotated. Match the aspect ratio to
the slot's frame — a portrait photo in a landscape slot gets cropped, not
letterboxed.

---

## 9. When the text does not fit

> Nevejde se to. Co s tím?

A container overflow. The server names the container's fillable inputs and by how
many pixels the content is too long — but when several texts share one flow it
genuinely **cannot** say which one is at fault, and it will not invent a
character count. The honest answers are: shorten a text, hide an input the
designer marked hidable, or ask the designer to raise the container's `maxHeight`
in the wboost editor. `render_variant` shows you the overflow while you work;
`export_variant` refuses to produce a broken file.

---

## 10. Draft the copy for me

> Napiš tři varianty nadpisu pro plakát na jarní workshop — musí se vejít do
> limitu toho pole — a všechny mi vyrenderuj, ať si vyberu.

Where an assistant is genuinely better than the editor: it reads `maxLength` from
`describe_variant`, writes to it, and renders each option. `maxLength` **rejects**
an over-long value rather than truncating it, so "must fit the limit" is a real
constraint the assistant has to respect, not a hint.

---

## 11. Put this picture in the gallery

> Tady je fotka z toho workshopu (přikládám ji) — nahraj ji do galerie projektu
> Studio Brno do složky Fotky a použij ji na plakátu.

`upload_image` takes the bytes base64-encoded and returns an `imageId` usable
immediately in a fill or a design. Two practical limits: the assistant must have
the **bytes** (it never fetches a URL you give it — that would be an SSRF against
wboost's own network), and one MCP request body caps out around **3 MB** of
picture, well below wboost's own 10 MB. A phone photo in HEIC is converted to
JPEG on the way in.

Nothing can be deleted, moved or renamed from a client — uploads only ever add.

---

## 12. Design something new

> V projektu Studio Brno máme prázdnou variantu 1080 × 1350 na Instagram. Navrhni
> do ní plakát na jarní keramický workshop — nadpis, podnadpis, datum a místo,
> fotku nahoře — a ukaž mi, jak to vypadá. Zatím nic neukládej.

`preview_design`: the assistant writes a design document and has it drawn without
saving anything. It should take font faces from `get_context` **verbatim** (an
unknown face is an error that stops the render, because Chromium would otherwise
silently substitute one), set `canvas` to the variant's own pixel size, and read
back the `issues[]` — `severity`, `stage` and a `path` like `elements[2].font`.

Iterate here as much as you like; nothing is persisted.

---

## 13. Save it

> Dobré, ulož to do té varianty.

`set_design` — the commit, once. It writes the canvas, the inputs and a fresh
thumbnail, and returns the picture that was stored plus an `editorUrl` a human
can open.

Two things it may say instead:

- **`overwrite` issues.** The design being replaced holds something the DSL
  cannot express — typically a background uploaded through the variant form
  rather than the gallery — and saving would destroy it. This is the one refusal
  that is not about the new document, so changing the design will not clear it.
  The right move is the repair the message names (upload that picture with
  `upload_image`, reference it by id), not `acknowledgeLosses: true`. Only
  acknowledge when you have read the list and want the replacement anyway.
- **A grouped variant is refused outright.** Its design is shared across the
  group's dimensions and is authored only in the wboost group editor.

Note the asymmetry with export: a design whose containers are predicted to
overflow still **saves** (it is a warning — a work in progress is worth keeping),
but `export_variant` will still refuse to produce a file from it.

---

## 14. Design, then immediately use it

> Navrhni tu variantu, ulož ji, a rovnou mi z ní vyexportuj PNG s tím textem,
> co jsme vymysleli.

The two loops back to back: `preview_design` → `set_design` → `describe_variant`
(the input ids exist only **after** the design is saved) → `render_variant` →
`export_variant`. Worth asking for explicitly, because the assistant has to
re-describe the variant in the middle — the slugs it chose in the document are
what became the inputs, but their UUIDs come from `describe_variant`.

---

## Two things to say out loud in any prompt

- **The project or template name.** The account may hold dozens of projects;
  naming one saves a round of questions.
- **What you want at the end** — a preview to look at, a file to download, or a
  saved design. Preview, export and save are different calls with different
  costs: only the export shows up in the usage report, and only the save changes
  anything.

## What these prompts will not do

- **Delete anything.** No tool removes a template, a variant or a picture.
  Gallery pictures can be added but never removed, moved or renamed from a
  client.
- **Edit an existing design in place.** Nothing reads a design back as a
  document, so an assistant asked to "just move the logo a bit" would be
  replacing the whole design blind. That belongs in the wboost editor.
- **Design a synchronized group.** A grouped variant's design is shared across
  its dimensions and is authored only in the group editor.
- **Design in a project that is merely shared with you.** Rendering and exporting
  work; designing requires owning the project.
