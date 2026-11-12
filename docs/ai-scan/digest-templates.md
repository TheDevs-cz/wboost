# AI scan — digest of the templates / MCP design stack

Read-only exploration, 2026-11-12, at commit `371410e6`. Purpose: everything a
design for "Šablona z obrázku" (picture → Fabric template with real layers)
needs to know about what already exists. Paths are repo-relative; `file:line`
points at the code that was read. Statements marked **(inferred)** were NOT read
in code or docs — they are conclusions.

Note on the brief: `.claude/skills/templates-session` does not exist. The code
map lives in the slash command `.claude/commands/templates-session.md` (read),
and the agent-facing skill is `plugin/wboost/skills/wboost/SKILL.md` +
`references/tools.md` (read in full).

---

## 0. The one-paragraph picture

The MCP "design DSL" is a strict JSON document (`canvas` + `elements[]`) that
`src/Mcp/Design/` parses, lints, compiles to the SAME Fabric canvas JSON the
admin editor saves, renders through Gotenberg, and commits through the app's
single canvas-save message. Every step below the MCP tool classes is a plain
Symfony service with no dependency on the MCP transport or on the security
token — an in-process scanner can call them directly. What does NOT exist:
any LLM client in the app (`composer.json` has only `mcp/sdk` and
`symfony/mcp-bundle`, no Anthropic/OpenAI SDK), any MCP tool or service that
creates a standalone template + variant (only the web controller and the
document `add_page` path do), and any DSL vocabulary for rotation, shadows,
masks, letter spacing, text decoration or vector paths.

---

## 1. The DSL in full

Authoritative sources: the parser's key constants
(`src/Mcp/Design/Dsl/DslParser.php:63-129`), the value objects in
`src/Mcp/Design/Dsl/`, the compiler (`src/Mcp/Design/DesignCompiler.php`), and
the generated reference `plugin/wboost/skills/wboost/references/tools.md:930-1093`
(stated there to be the parser's own key list).

### 1.1 Document shape, units, coordinates, order

- Root: `{"canvas": {...}, "elements": [...]}` — both required
  (`DslParser.php:63`, `:192-198`). `elements` may be `[]`.
- **Units: canvas pixels**, top-left origin, +y down, axis-aligned. Print
  formats (mm/cm) are authored at their 300 DPI raster size (A4 = 2480 × 3508),
  never in mm (`DslParser.php:273-278`). `canvas.width/height`: integers
  1…20000 (`Dsl/CanvasSpec.php:33`); the web form caps a variant side at
  10 000 px (`src/FormData/TemplateGroupDimensionFormData.php:17`; WebP's
  16 383 px limit depends on it — `TemplateVariantImageRenderer.php:626-628`).
- `canvas` must equal the variant's size; a mismatch is only a WARNING
  (`canvas_size_mismatch`, stage `variant`) and the render uses the variant's
  size anyway (`DesignIssue.php:167-187`, `DesignPreflight.php:130-140`).
- **Layer order = array order, bottom → top** (`DslParser.php:296`). The
  background is always emitted at stack index 0 regardless of its position in
  the array (`DesignCompiler.php:226-237`). Container flow order is re-derived
  from the designed `top`, not from `members` order (`DesignCompiler.php:953-955`).
- Negative coordinates and coordinates past the canvas are accepted (`x`/`y`
  are plain numbers, `DslParser.php:1087-1088`); on print variants artwork
  meant to reach the paper edge must extend `dimension.bleedPx` past each edge
  (`SetDesignTool.php:165-169`). The linter reports `out_of_canvas_bounds` as a
  warning, bleed-aware (`CompilationContext.php:45-55`).
- Element `id`: slug `^[a-z0-9][a-z0-9_-]*$`, ≤ 64 chars, unique
  (`DslParser.php:136-138`). **Slugs are identity**: a slug that matches an
  existing input keeps that input's UUID across re-saves
  (`DesignPreflight.php:54-61`, `DesignCompiler.php:337-349`).
- Unknown keys are rejected with a nearest-key suggestion; all violations are
  reported at once (`DslParser.php:36-51`, `:2171-2191`).

### 1.2 Placement (text, image, shape)

Either absolute or semantic (`DslParser.php:1082-1113`):

- **Absolute**: `x`, `y`, `width` (all three required), plus `height` for
  image and shape. Text has NO `height` — Fabric computes it from the wrapped
  content (`DesignCompiler.php:561-565`). `x`/`y` are the top-left of the box.
- **Semantic** `at: {area, col, marginX, marginY, offsetX, offsetY}`
  (`DslParser.php:72`). `area` ∈ `top|upper|middle|lower|bottom|full`; `col`
  = `[start, end]` inclusive on a 12-column gutterless grid, default `[1,12]`.
  Exact maths (a public contract): `src/Mcp/Design/Geometry/GridResolver.php:25-56`
  — thirds (`top/middle/bottom`), halves (`upper/lower`), `full`;
  `y = round(fracTop·H + marginY) + offsetY`,
  `x = round(marginX + (colStart−1)·(W−2·marginX)/12) + offsetX`.

For a scanner that measures pixel boxes off a picture, absolute placement is
the natural fit; `at` exists for agents designing from a brief.

### 1.3 Element kinds and every key

`kind` ∈ `text | image | shape | background | container` (`Dsl/ElementKind.php`).

**`text`** — keys `kind, id, text, font, size, color, align, lineHeight,
opacity, at | x,y,width, input, userMove` (`DslParser.php:75`, parse at
`:388-415`).

- Required: `text` (the designed stand-in copy; `\n` = line break), `font`
  (exact face string), `size` (px, > 0), placement.
- Defaults: `color` `#000000`, `align` `left` (`left|center|right|justify`),
  `lineHeight` `1.16`, `opacity` `1` (fraction 0…1).
- Compiles to a Fabric `Textbox`, `originX/Y` left/top, `width` = wrap width,
  no height, `angle: 0`, `charSpacing: 0`, `scaleX/Y: 1`
  (`DesignCompiler.php:550-595`). There is no weight/italic key: the face IS
  the `font` string.
- `input` block (`DslParser.php:78`, `Dsl/TextInputSpec.php:27-72`): `name`,
  `maxLength` (≥ 1; rejects, never truncates), `uppercase`, `hidable`,
  `locked`, `richText`, `sampleValue`, `allowedFonts[]`, `fontChoice`,
  `allowedColors` (null = any, `[]` = locked, list = swatches).
  **Every text element becomes a text input** — the compiler appends one
  `EditorTextInput` per text in the same walk as the object
  (`DesignCompiler.php:263-268`); "fixed copy" is expressed as
  `input.locked: true`. (SKILL.md says "without `input` it is fixed copy";
  the compiler still emits an unlocked, unnamed input for it —
  `parseTextInput` returns a default spec, `DslParser.php:1253-1255`. Treat
  `locked: true` as the explicit way to freeze copy.)

**`image`** — keys `kind, id, asset, at | x,y,width,height, input, mirror,
opacity, userMove` (`DslParser.php:81`, `:420-455`).

- `asset` = gallery `FileUpload` UUID (never a URL or file name).
- Without `input` → decorative: CONTAIN-fitted and centred in the rect
  (uniform scale). With `input` → fillable slot: stretched to fill the rect
  exactly, the rect IS the slot frame (`DesignCompiler.php:660-718`).
- `height` omitted → derived from the picture's ratio; no picture → square
  (`DesignCompiler.php:896-909`).
- No `asset` → 1×1 transparent pixel as `src` (`DesignCompiler.php:179-190`,
  `:746`); an image with neither asset nor input is linted
  (`image_without_asset_or_placeholder`).
- `input` block (`DslParser.php:84`): `name`, `placeholder` (default true),
  `allowMove` (true), `allowResize` (true), `allowRotate` (false), `hidable`
  (false), `allowedDirectories[]` (gallery folder UUIDs; `[]` = whole gallery).
- `mirror: true` = horizontal flip, decorative only (`DslParser.php:429-438`).
- `opacity` fraction 0…1.
- SVG assets have no natural size (`CompilationContextFactory.php:174-178`):
  the rect becomes the object's width/height at scale 1
  (`DesignCompiler.php:680-683`).

**`shape`** — keys `kind, id, shape, fill, stroke, strokeWidth, strokeStyle,
cornerRadius, opacity, name, locked, userColor, at | x,y,width,height,
userMove` (`DslParser.php:87`, `:465-521`).

- `shape` ∈ `rectangle|square|circle|ellipse|triangle|line|star`
  (`src/Value/CanvasShapeKind.php`) → Fabric `Rect/Circle/Ellipse/Triangle/Polygon`
  (`DesignCompiler.php:830-840`). A `line` is a thin Rect.
- `fill`: hex string or gradient object `{type: linear|radial, angle, from, to}`
  or `{type, angle, stops: [{at, color, opacity}]}` with 2–8 stops, `at` and
  `opacity` fractions 0…1 (`DslParser.php:104-111`, `:836-1027`;
  `src/Value/CanvasShapeGradient.php`). `angle` degrees clockwise, 0 = left→right,
  90 = top→bottom; ignored for radial (always centre-out). Gradient stop
  opacity is the ONLY alpha in the DSL — colours themselves are `#rrggbb` /
  `#rgb`, no alpha (tools.md:1080).
- `stroke` hex, `strokeWidth` ≥ 0, `strokeStyle` `solid|dashed|dotted`
  (`src/Value/CanvasShapeStroke.php`); on a `line` the style dots/dashes the
  line body itself (tools.md:1083-1091; commit `5d8526d4`).
- `cornerRadius` only on `rectangle|square|line` (`DslParser.php:488-500`).
- `opacity` fraction; `name` = layer label; `locked` = editor lock only.
- `userColor: {target: fill|stroke|both, colors: [...], free: bool}` makes the
  shape recolourable at fill time (`DslParser.php:94`, `:532-578`).
- Height omitted → square (`Dsl/ShapeElement.php` `frameHeight`).

**`background`** — keys `kind, id, asset, fill, fillable`
(`DslParser.php:114`, `:1032-1050`). At most one. `asset` (gallery picture,
cover-fitted, anchored top-left, covering the bleed box) OR `fill` (hex or
gradient object), never both. `fillable: true` (pictures only) makes it a
whole-canvas cover slot; fillable without asset is a compile error
(`inert_declaration`, `DesignCompiler.php:448-458`). Compiles to the editor's
locked "Pozadí" layer (`isBackground`) through
`Services/Editor/BackgroundLayer::buildObject()` / `buildColorObject()`
(`DesignCompiler.php:469-500`). No placement, no opacity.
Shorthand `canvas.background: {image, fill}` exists (`DslParser.php:69`,
`:238-253`); `canvas.background.fill` is hex only and, under a picture
background, stays a canvas-level colour no editor panel shows (linted
`canvas_color_under_background`, `DesignCompiler.php:315-323`).

**`container`** — keys `kind, id, members, children, maxHeight, gap,
spaceAfter, hidable, name, userMove, repeat` (`DslParser.php:117`,
`:1055-1073`; checks `:1525-1820`). A vertical reflow area: `members` are
slugs of texts / shapes / images (decorative or slots), `children` are nested
container slugs. ≥ 2 items in total; a top-level container must carry
`maxHeight`; tree only. `gap` = uniform spacing, `spaceAfter` = clearance
below. `hidable` + `name` = one toggle hiding the whole tree.
`repeat: {max, min, default}` on a NESTED container = repeatable row
(`1 ≤ min ≤ default ≤ max ≤ 30`; `slots` is never authored). Details:
`docs/architecture/templates-containers.md`, tools.md:1035-1063.

**`userMove`** on text / shape / decorative image / top-level container:
`{vertical, horizontal, maxUp, maxDown, maxLeft, maxRight}`
(`DslParser.php:129`, `:579-637`).

### 1.4 Fonts, colours, rich text in the DSL

- `font` must be byte-for-byte one of the project's face strings
  `"<Font name> (<Face name>)"` (`src/Entity/Font.php:75-78`), e.g.
  `"Rubik (Rubik Bold)"`. Unknown = blocking error (see §5).
- Colours: any hex is accepted; a colour outside the project palette is only
  the warning `color_not_in_palette` (`Lint/LintCode.php:74`; black and white
  are exempt, `DesignLinter.php:122`).
- **Rich text is a fill capability, not a design capability.** The element's
  `text` is one plain string in one font/size/colour. `input.richText: true`
  lets the END USER style runs (face / colour / underline) on the fill page.
  Per-run styling in the design itself can only be smuggled in through
  `input.sampleValue` as the fill envelope `{"runs":[…],"lines":[…]}` encoded
  as a string (`Dsl/TextInputSpec.php:40-46`) — the sample is what renders
  when nothing is filled (`CandidateRenderer.php:66-80`). Lists, checkbox
  lists and the checklist component have no DSL keys
  (`DesignCompiler.php:121-123`).

### 1.5 Complete example (composed for this digest, NOT run through the parser — validity inferred from the grammar above)

A 1080 × 1350 event poster: photo background, dark gradient scrim, logo,
headline + perex in a container, date pill, footer line. Asset ids and fonts
are placeholders that must exist in the project.

```json
{
  "canvas": { "width": 1080, "height": 1350 },
  "elements": [
    { "kind": "background", "id": "pozadi",
      "asset": "6f9619ff-8b86-d011-b42d-00cf4fc964ff", "fillable": true },

    { "kind": "shape", "id": "scrim", "shape": "rectangle", "name": "Ztmavení",
      "x": 0, "y": 600, "width": 1080, "height": 750, "locked": true,
      "fill": { "type": "linear", "angle": 90, "stops": [
        { "at": 0, "color": "#101820", "opacity": 0 },
        { "at": 1, "color": "#101820", "opacity": 0.92 } ] } },

    { "kind": "image", "id": "logo",
      "asset": "3f2504e0-4f89-11d3-9a0c-0305e82c3301",
      "x": 72, "y": 64, "width": 220, "height": 90 },

    { "kind": "shape", "id": "datum-pill", "shape": "rectangle",
      "x": 72, "y": 720, "width": 300, "height": 68, "cornerRadius": 34,
      "fill": "#f2c94c",
      "userColor": { "target": "fill", "colors": ["#ffffff", "#eb5757"], "free": false } },

    { "kind": "text", "id": "datum", "text": "14. března 2027",
      "x": 72, "y": 737, "width": 300,
      "font": "Rubik (Rubik Medium)", "size": 30, "color": "#101820", "align": "center",
      "input": { "name": "Datum", "maxLength": 24 } },

    { "kind": "text", "id": "nadpis", "text": "Jarní workshop keramiky",
      "x": 72, "y": 830, "width": 936,
      "font": "Rubik (Rubik Bold)", "size": 92, "color": "#ffffff",
      "lineHeight": 1.05,
      "input": { "name": "Nadpis", "maxLength": 48, "uppercase": true } },

    { "kind": "text", "id": "perex",
      "text": "Přijďte si vyzkoušet práci s hlínou. Vše potřebné bude na místě.",
      "x": 72, "y": 1050, "width": 780,
      "font": "Rubik (Rubik Regular)", "size": 36, "color": "#ffffff",
      "lineHeight": 1.3,
      "input": { "name": "Perex", "richText": true, "hidable": true } },

    { "kind": "container", "id": "texty",
      "members": ["nadpis", "perex"], "maxHeight": 400, "gap": 28 },

    { "kind": "shape", "id": "linka", "shape": "line",
      "x": 72, "y": 1262, "width": 936, "height": 2, "fill": "#ffffff", "opacity": 0.4 },

    { "kind": "text", "id": "paticka", "text": "www.studiobrno.cz",
      "x": 72, "y": 1284, "width": 936,
      "font": "Rubik (Rubik Regular)", "size": 24, "color": "#ffffff",
      "input": { "name": "Patička", "locked": true } }
  ]
}
```

A parser-verified document exercising all five kinds is in
`plugin/wboost/skills/wboost/SKILL.md:616-671`.

### 1.6 The exact shape of validation errors

One merged list `issues[]` for the whole pipeline
(`src/Mcp/Design/DesignIssue.php:214-232`):

```jsonc
{
  "severity": "error" | "warning",          // only "error" blocks
  "stage": "parse" | "variant" | "lint" | "compile" | "overwrite",
  "code": "unknown_key",                    // stage-specific vocabulary
  "path": "elements[2].fontSize",           // JSON-pointer-like, dotted/bracketed
  "message": "elements[2].fontSize is not a valid key for a \"text\" element. Did you mean \"size\"? Allowed keys: kind, id, text, …",
  "slug": "headline",                       // only when known (lint)
  "allowed": ["Rubik (Rubik)", "…"]         // only on some codes (fonts)
}
```

Codes per stage:

| stage | codes | source |
|---|---|---|
| `parse` (always error) | `unknown_key`, `missing_key`, `invalid_type`, `invalid_value`, `duplicate_id`, `unknown_reference`, `invalid_structure`, `malformed_document` | `Dsl/DslErrorCode.php` |
| `variant` (always warning) | `canvas_size_mismatch` | `DesignIssue.php:167` |
| `lint` errors | `font_not_allowed`, `repeat_not_nested`, `repeat_multiple_in_parent`, `repeat_inside_repeat` | `Lint/LintCode.php:53,133-146,202` |
| `lint` warnings | `out_of_canvas_bounds`, `text_overlap`, `color_not_in_palette`, `font_size_too_small`, `container_overflow_predicted`, `container_too_few_items`, `container_hidable_without_name`, `user_move_on_container_member`, `user_move_on_nested_container`, `repeat_max_overflows`, `image_without_asset_or_placeholder`, `max_length_below_stand_in`, `missing_background`, `background_drawn_as_element`, `canvas_color_under_background` | `Lint/LintCode.php:60-194` |
| `compile` (always error) | `font_not_allowed`, `asset_not_found`, `inert_declaration` | `CompileErrorCode.php` |
| `overwrite` (error unless acknowledged) | `object_dropped`, `object_restacked`, `transform_dropped`, `style_dropped`, `input_feature_dropped`, `canvas_feature_dropped`, `asset_unresolved`, `identity_remapped` | `DesignLossCode.php` |

In process the same data is PHP objects: `DslParser::parse()` throws
`InvalidDesignDocument` carrying `DslViolation{path, code, message}`
(`Dsl/DslViolation.php`); the compiler throws `DesignCompilationFailed` with
`CompileViolation{path, code, message, allowed}`; `DesignPreflight::review()`
folds everything into `DesignReview{issues, ?compiled}`
(`DesignPreflight.php:78-125`, `DesignReview.php`). **An error means no
`CompiledDesign` exists.**

Exact wordings worth matching on: unknown key (`DslParser.php:2183-2189`),
font (`DesignCompiler.php:1380-1398`), asset (`:1400-1411`).

---

## 2. What the DSL cannot express

"Editor" = what the Fabric admin editor can author or what a stored canvas may
hold; the decompiler's loss codes (`DesignLossCode.php`) are the app's own
list of what the DSL drops.

| Feature | DSL | Editor / stored canvas | Notes |
|---|---|---|---|
| Rotation of text / shape / image | No — every object compiles with `angle: 0` (`DesignCompiler.php:568,736,801`) | Images: yes, a rotation field in "Velikost a poloha" (`docs/architecture/templates-editor-ui.md:123-137`, `canvas_image_properties_controller.js:521`). Text: width-only resize, no rotation (comment at `canvas_custom_properties.js:98`) | Rotated text has no home in either; rotation on a stored object is `transform_dropped` |
| Flip | Horizontal `mirror` on decorative images only | Yes | |
| Image crop inside its frame (`cropX/cropY`) | No (always 0, `DesignCompiler.php:732-733`); decorative = contain, slot = stretch | Stored canvases may carry it (`transform_dropped`) | A scanner must pre-crop the bitmap to the frame's ratio |
| Masks / clip paths / rounded image corners / circular photos | No | Canvas clip path is a `canvas_feature_dropped`; no editor UI found **(inferred: not offered)** | Bake the mask into a PNG with alpha |
| Shadows (text or box), glow, blur, filters | No | `style_dropped` lists shadows and image filters; no editor UI found **(inferred)** | Bake into raster or drop |
| Blend modes | No | Not found anywhere **(inferred: unsupported)** | |
| Gradients | Shapes and the background only; linear by angle or radial centre-out, 2–8 stops with per-stop opacity | Same (shape popover) | No gradient text, no conic, no off-centre radial |
| Alpha colours | No (`#rrggbb` only); element `opacity` + gradient stop opacity | Same model | |
| Text stroke / outline | No | `style_dropped` ("strokes") | |
| Letter spacing | No (`charSpacing: 0`) | `style_dropped` ("letter spacing") | Tracked-out headlines will measure wrong |
| Underline / strikethrough in the design | No | Editor text toolbar has "decoration" (`templates-editor-ui.md` controller table); DSL drops it (`style_dropped`, "text decoration") | End users can underline rich runs at fill time |
| Mixed styles inside one text | Not in `text`; only via a rich `sampleValue` envelope | Per-character `styles` exist on stored canvases (`style_dropped`) | Otherwise split into several text elements |
| Bold / italic | Only by choosing another face string | Same | Needs that cut uploaded |
| Curved text / text on path | No | No **(inferred)** | Raster only |
| Vertical alignment in a box, auto-shrink | No (text height is content-driven) | No **(inferred)** | |
| Lists / checkbox lists / checklist | No DSL keys (`input_feature_dropped`) | Yes (`templates-text-inputs.md`) | |
| Vector logos / arbitrary paths | Only the 7 primitives; `Path`, `Group`, `IText`, hand-made `Rect` are `object_dropped` | Shapes via "Přidat tvar" only; SVG files as gallery images | A logo = an `image` whose asset is an SVG or PNG |
| Polygons other than triangle / 5-point star | No | No | |
| Per-corner radius | No (one `cornerRadius`) | No **(inferred)** | |
| Layer hidden in design | No (`object_dropped` for design-hidden layers) | Yes (layers panel eye) | |
| Ruler guides | No (`canvas_feature_dropped`) | Yes | |
| Groups (synchronized dimensions) | `set_design` refuses grouped variants (`DesignVariants.php:38-55`) | Group editor | Scan produces ONE variant; other sizes are a later step |

Versus real posters: photographic backgrounds, flat/gradient panels, pills,
rules, logos, plain headlines and body copy map well. Drop shadows on text,
rotated badges/stickers, curved or outlined type, duotone/blend effects,
masked photos (circles, blobs), hand-drawn ornaments and illustrations do
not — the realistic fallback is "bake it into a raster layer" (a transparent
PNG crop placed as a decorative `image`), at the cost of editability.

---

## 3. The build path for an in-process scanner

Nothing below needs the MCP transport, an OAuth token or `Security`.

1. **Create template + first variant** — two messages on the default
   `command_bus`, exactly as `AddTemplateController` does
   (`src/Controller/Template/AddTemplateController.php:49-77`):
   - `Message\Template\AddTemplate(projectId, templateId, ?categoryId, name, ?UploadedFile image)`
     (`src/Message/Template/AddTemplate.php`, handler `AddTemplateHandler.php:34-66`).
   - `Message\Template\AddTemplateVariant(templateId, variantId, TemplateDimension, ?backgroundImageId)`
     (`AddTemplateVariant.php`, handler `AddTemplateVariantHandler.php:35-77`;
     new variants are always `BackgroundMode::Layer`).
   - Ids come from `Services\ProvideIdentity::next()`.
   - `TemplateDimension(unit, unitWidth, unitHeight, ?preset, bleedMm, cropMarks)`
     (`src/Value/TemplateDimension.php:43-62`) or
     `TemplateDimension::fromPreset(DimensionPreset)` (`:107`). px / mm / cm;
     mm and cm rasterize at 300 DPI; presets are 4:5 and 9:16 (1:1 retired for
     new formats — `docs/architecture/templates-module-and-groups.md:32-59`).
2. **Upload assets** — `Message\Image\UploadFile(fileId, projectId,
   FileSource::ProjectImage, UploadedFile, ?directoryId)`
   (`src/Message/Image/UploadFile.php`, handler
   `src/MessageHandler/Image/UploadFileHandler.php:38-85`). The `fileId` you
   mint IS the DSL `asset` id. `UploadImageTool.php:262-314` shows the
   temp-file + `new UploadedFile($path, $name, $mime, null, true)` pattern for
   bytes that did not arrive over HTTP.
3. **Review** — `Mcp\Design\DesignPreflight::review(TemplateVariant, mixed $design): DesignReview`
   (`src/Mcp/Design/DesignPreflight.php:78`): parse → variant fit → lint →
   compile. Dependencies: `CompilationContextFactory`, `DesignLinter`,
   `DesignCompiler`, `GetProjectPalette` — no security, no request.
4. **Look** — `Mcp\Design\CandidateRenderer::renderToBytes(variant, compiled,
   strictContainerOverflow: false, format)` (`CandidateRenderer.php:112-131`):
   renders a detached clone, persists nothing.
5. **Commit** — the two dispatches of `SetDesignTool::commit()`
   (`src/Mcp/Tool/SetDesignTool.php:269-284`):
   `EditTemplateVariantCanvasEditor(variantId, canvasJson, textInputs,
   imageInputs, previewImageDataUri: '')` (the app's only canvas writer), then
   `StoreTemplateVariantPreviewImage(variantId, bytes)` with the render
   downscaled to 1000 px (`DownscaleImage::toLongEdge`, `SetDesignTool.php:130`).
6. **Optional helpers**: `Mcp\Design\Measure\TextMeasurer` (font-metric line
   estimate without a browser, ±1 line by contract —
   `Measure/TextMeasurer.php:14-60`); `Mcp\Design\DesignDecompiler::forVariant()`
   (`DesignDecompiler.php:171`) reads a stored canvas back as a DSL document
   plus losses — it exists in code although no MCP tool exposes it
   (docs/mcp/connect.md:358-361 says "no tool reads a design back").

What the MCP tool classes add on top of those services:

- Authentication + scope gating (`src/Mcp/Security/`), voter checks
  (`DesignVariants::editable()/writable()` use `Security::isGranted`,
  `DesignVariants.php:57-84`) and "not found ≡ not yours" wording.
- The grouped-variant refusal (`DesignVariants.php:38-55`).
- The overwrite guard (`DesignOverwriteGuard::review`, `:112`) — irrelevant
  for a freshly created empty variant **(inferred: an empty canvas has
  nothing to lose)**.
- base64 decoding, the ~3 MB transport ceiling (`UploadImageTool.php:141`),
  SSRF refusal of URLs.
- Response shaping: JSON summary + downscaled image (≤ 1200 px long edge,
  WebP for preview, PNG for set — `PreviewDesignTool.php:101-108`,
  `SetDesignTool.php:120-138`), `editorUrl`.
- `get_context` (fonts / palette / dimensions, cached 60 s),
  `describe_variant`, `list_gallery` — read models an in-process scanner can
  get from `GetFonts`, `GetProjectPalette`, repositories directly.

Not available anywhere as a service: "create a standalone template" as one
call (compose the two messages), and any deletion from MCP (web handlers
`DeleteTemplate` / `DeleteTemplateVariant` exist for cleaning up a failed scan).

---

## 4. The look path (render a variant to an image in process)

- Entry: `Services\Editor\TemplateVariantImageRenderer::renderToBytes(variant,
  ResolvedInputOverrides, ?imageOverrides, strictContainerOverflow, ?slice,
  RenderImageFormat = Png, …)` (`src/Services/Editor/TemplateVariantImageRenderer.php:134-143`);
  for an unsaved design use `CandidateRenderer` (§3.4), which resolves the
  stand-in overrides for you.
- How: builds the canvas JSON, inlines images and fonts as base64 (headless
  Chromium has no route to Minio), renders
  `templates/api/template_variant_render.html.twig` through Gotenberg's
  screenshot route with the same Fabric 7.3.1 build as the editor, waits for
  `window.canvasRendered === true` (`:596-633`;
  `docs/architecture/templates-render.md:3-17`).
- Output: PNG (lossless, default) or WebP (lossy, no quality knob,
  ≤ 16 383 px/side) at the variant's full pixel size; transparent where
  nothing is drawn (layer mode → `omitBackground()`, `:635-641`). Downscale
  with `Services\Image\DownscaleImage::toLongEdge()`.
- Latency (documented, not measured here): A4 2480 × 3508 — WebP 1.00 s,
  PNG 1.56 s (`templates-render.md:30`); "a render is ~2 s"
  (`config/packages/sensiolabs_gotenberg.yaml`); 1.0–1.5 s per render
  (`docs/plans/mcp-server.md` §0.7). Large embedded photos add base64 +
  decode time **(inferred)**.
- Failure typing: client timeout 20 s / max 25 s →
  `TemplateRenderUnavailable` (503-class, retryable); strict container
  overflow → `ContainerOverflow`; anything else is a render error
  (`:193-236`, `templates-render.md:91-108`).
- Concurrency: Gotenberg is a synchronous shared dependency, **nothing is
  queued** and no in-app limiter was found; the fill page already fires 2–3
  renders per keystroke (`templates-render.md:91-93`). The Gotenberg container
  once OOM-killed Chromium inside a 2 GiB cgroup (`templates-render.md:103-105`).
  The slice cache never applies to a full render or an unsaved candidate
  (`CandidateRenderer.php:51-64`).
- Memory on the PHP side: the full PNG and the base64'd assets are held as
  strings; no figures for template renders were found. The CMYK print path
  measured A2 at ~640 MB with a capped pixel cache (`templates-render.md:207-210`)
  — not this path.
- **From the messenger consumer**: the renderer has no request / session /
  security dependency (constructor `:71-95`) and explicitly exempts CLI from
  its `set_time_limit(60)` (`:204-206`); the calendar PDF export already
  drives Gotenberg from the `async` consumer
  (`docs/architecture/calendars.md:847-867`). So it works **(inferred — no
  existing code renders a template variant from the consumer)**. Constraints
  to respect: long jobs go on `job_bus` (no transaction, so progress is
  visible; `config/packages/messenger.php:22-27`), production runs ONE
  consumer with `--memory-limit 256M` in a 768 MB container and e-mails /
  events queue behind a running job (`calendars.md:1111-1112,1159-1161`),
  dev and CI run no consumer and tests route the job `sync`
  (`calendars.md:863-868`).

---

## 5. Fonts

- Source: `Query\GetFonts::allForProject(projectId)` → `Font[]`, each with
  `faces: FontFace[]` (`src/Query/GetFonts.php:62-73`,
  `src/Entity/Font.php:22-28`). `FontFace{name, weight:int, style:string,
  filePath, ?fileSize}`, `isItalic()` (`src/Value/FontFace.php`). Files are
  TTF / OTF / WOFF (no WOFF2).
- Wire format: `Font::faceFamily($face)` = `"<Font name> (<Face name>)"`
  (`Font.php:75-78`) — the canvas `fontFamily`, the render template's
  `@font-face` name and the DSL `font`. The allowlist a design is checked
  against is built in `CompilationContextFactory::allowedFonts()` (`:76-88`).
- Brand-manual preference: `Services\Editor\ResolveEditorFontDefaults::forProject()`
  returns `EditorFontDefaults{defaultFamily, manualFaces}` — the primary
  manual font's regular cut and every face a manual enables
  (`src/Services/Editor/ResolveEditorFontDefaults.php:37-95`). That is the
  natural "project font" ranking for a scanner.
- Picking "project font if matching, else nearest available" is NOT
  implemented anywhere. Building blocks: family name + `weight` + italic flag
  per face (enough for "same family, nearest weight, same slant"), manual
  primary/secondary order, and `TextMeasurer` / `FontMetricsLoader`
  (`src/Mcp/Design/Measure/`) to compare how wide a candidate face sets the
  recognised text against the measured box **(inferred as an approach)**.
- Adding a font: `Message\Font\AddFont` per static cut; a variable font goes
  through `Services\Font\AddVariableFontFaces` (`:48`), expanding every named
  instance (Inter → 18 faces, ~7 s, synchronous —
  `docs/architecture/templates-text-inputs.md` "Variable fonts"). MCP twin:
  `upload_font` (`src/Mcp/Tool/UploadFontTool.php:85`).
- **A design naming a missing font** is refused before any render:
  `font_not_allowed` as a lint ERROR (and the identical compile violation),
  with `allowed[]` listing the project's faces (`DesignPreflight.php:39-45,103-108`,
  `DesignCompiler.php:1380-1398`). A project with no fonts at all cannot hold
  any text element (message `:1384-1388`). The reason for hard refusal:
  Chromium would silently substitute a face. Outside the DSL, a canvas that
  references a missing family shows up in the fonts page's "Písma mimo
  projekt" via `Query\GetFontUsage` (`templates-text-inputs.md` "Font usage scan").
- Glyph coverage: a codepoint the face lacks falls back to Chromium's default
  face at render time (`calendars.md:1163-1164` says so for calendars;
  **inferred** to hold for templates too).

---

## 6. Assets

- A crop becomes a gallery picture through `UploadFile` (§3.2) → a
  `file_upload` row (`FileUpload`, `FileSource::ProjectImage`) + an object at
  **`file-upload/{projectId}/{fileId}.{ext}`** (`UploadFileHandler.php:69`).
  It can be filed into a gallery folder (`directoryId`); the root is a real
  location. Pictures are visible to every project member in the gallery.
- It becomes a layer by naming that `fileId` as `asset` on an `image` or
  `background` element. The compiler writes `src` (public URL), `assetPath`
  (storage key — required, or the render paints nothing) and `assetId`
  (`DesignCompiler.php:740-757`); resolution is project-scoped and excludes
  trashed rows (`CompilationContextFactory.php:139-165`).
- Upload limit: **10 000 000 bytes** (`PlaceholderImageUploader::MAX_FILE_SIZE_BYTES`,
  `src/Services/SocialNetwork/PlaceholderImageUploader.php:44`), mirrored in
  many places (`docs/architecture/gallery-and-uploads.md:202-241`). Dispatching
  `UploadFile` directly bypasses the check — the caller must enforce it
  (`UploadImageTool.php:212`). PHP/Caddy allow 50 MB.
- Normalisation (`Services\Image\NormalizeImageFormat::normalize`, `:63`, run
  inside `UploadFileHandler`): PNG / JPEG / GIF / WebP pass byte-for-byte
  (except EXIF-rotated ones, re-encoded upright); HEIC/HEIF and anything else
  Imagick decodes become PNG (alpha) or JPEG q90; SVG is stored untouched;
  text never reaches ImageMagick; transcode candidates above 60 MP are
  refused (`gallery-and-uploads.md:243-289`). Non-text PDF / EPS bytes are
  handed to ImageMagick → Ghostscript on this path (documented as still open,
  `:291-296`).
- Storage reference index: `Services\Storage\BuildStorageReferenceIndex`
  finds canvas-embedded paths by prefix regex — `file-upload` is in
  `PATH_PREFIXES` (`BuildStorageReferenceIndex.php:39`) and `file_upload.path`
  is an indexed column, so gallery-stored crops need NO change there. The rule
  ("add every new path-storing column", `docs/architecture/storage-inventory.md:36-49`)
  bites only if the scanner keeps the uploaded SOURCE file or intermediate
  artefacts under a new prefix or in a new table.
- Trash: gallery deletes go to "Koš"; purge refuses pictures a template still
  uses (commit `c42c2b64`); a purged picture renders as a stand-in.
- The template listing thumbnail is `preview_image_path`, written by
  `StoreTemplateVariantPreviewImage` (§3.5); `AddTemplate.image` is a separate
  optional listing image stored at `custom-templates/templates/{id}/image-{ts}.{ext}`
  (`AddTemplateHandler.php:42-48`).

---

## 7. The create-template modal as built

- Partial: `templates/_template_new_dialog.html.twig` (61 lines), a Bootstrap
  modal `#templateNewModal`, included by `templates/templates.html.twig:163-165`
  when `can_create`; opened by the two "Nová šablona" buttons
  (`templates.html.twig:32,59`, `data-bs-toggle="modal"`). The empty state
  links straight to `add_template` (`:86`).
- Cards (`.tp-types` grid of `.tp-type`), each one stretched link — no form,
  no JS, no POST from the modal itself:
  1. **Prázdná šablona** → `add_template` (`/project/{id}/add-template`,
     `AddTemplateController`, `ProjectVoter::EDIT`) — a normal page with
     `AddTemplateFormType` (name, category, image + first variant's dimension,
     background pick, print row), template `templates/add_template.html.twig`;
     on success redirects to `template_variant_editor`.
  2. **Synchronizovaná šablona** (designers) → `add_template_group`, plus
     "Vyjít z existující šablony" → `template_group_from_template`.
  3. **Vícestránkový dokument** (designers) → `add_template_document`.
  4. **Šablona z obrázku** — ALREADY PRESENT as a disabled teaser
     (`_template_new_dialog.html.twig:49-56`): `.tp-type.prem.is-soon`, the
     "Premium" pill (`.tp-prem` + `icon('sparkle')`), copy "Nahrajte hotovou
     grafiku (JPG nebo PNG) a systém z ní sám vytvoří upravitelnou šablonu
     v editoru — texty, obrázky i barvy.", and a disabled button "Brzy" with
     `icon('upload')`. It is outside the `is_designer` block (shown to every
     creator).
- CSS: `assets/styles/nocturne/templates.css:87-132` (`.tp-types` is a
  4-column grid; `.prem` border colour; `.is-soon` disables the stretched
  `::after` and the hover).
- Where the new card plugs in: replace the disabled button with a real CTA
  ("Nahrát obrázek") and drop `is-soon`. Because the other cards are links to
  full pages, the lowest-friction shape is a link to a new upload page/route
  (dimension choice + file field + progress), not an in-modal upload
  **(inferred design note)**. There is no "Premium" entitlement in code — the
  word appears only in this partial; the nearest gating mechanisms are
  `ProjectModule` (`src/Value/ProjectModule.php`, incl. the admin-only preview
  flag used by Kalendáře) and roles.

---

## 8. Existing traces of Claude Code driving these tools (the proven baseline)

- `plugin/wboost/skills/wboost/SKILL.md` (738 lines) — the skill a Claude
  session loads: the authoring loop (`get_context` → `find_templates` →
  `describe_variant` → `list_gallery`/`upload_image` → `upload_font` →
  `preview_design` until right → `set_design` once; `:49-65`), rule 6
  (authoring pitfalls: unknown font is an error, canvas must match, slugs are
  identity; `:203-236`), rule 7 (`acknowledgeLosses`), the DSL brief with a
  parsing example (`:573-675`), "common mistakes" (`:707-733`).
- `plugin/wboost/skills/wboost/references/tools.md` — field reference and the
  exhaustive DSL key tables (`:930-1093`).
- `docs/mcp/prompts.md` — copy-paste prompts; #12–#14 are the design loop,
  including the background-layer rule and its history (`:178-254`).
- `docs/mcp/connect.md` — connection, scopes, tool table.
- `config/packages/mcp.php:63-106` — the server `instructions` every client
  receives (the shortest statement of the loop).
- Tool docblocks = the tool descriptions the model sees, e.g.
  `src/Mcp/Tool/SetDesignTool.php:150-205`, `PreviewDesignTool.php`.
- `plugin/wboost/commands/{projects,export}.md` — slash commands (fill loop only).
- `docs/plans/mcp-server.md` — the build plan: locked decision §0.2 "**the
  reference image never reaches the server**; the host model sees it natively"
  (`:15`), §3.4 grammar, §4 compiler invariants, Stage 6 "accuracy scaffolding"
  with archetypes that were never built (`:537-563`), risks (`:583-599`).
- Evidence of real import sessions and what they broke:
  - "Poukaz linie" (2026-11-05): colour background landed on Fabric's
    canvas-level colour, then was "fixed" with a hand-drawn rect → now a real
    "Pozadí" layer + lints (`docs/mcp/prompts.md:199-202`,
    `docs/architecture/templates-data-model.md:283-305`, commit `34a34b35`).
  - Sagena voucher, MCP-imported from an **InDesign/IDML** source: the
    "Canned Dotted" 1 pt rule became a caterpillar → line dot rendering fixed
    (commit `5d8526d4`, `tests/Mcp/Design/DottedLineDesignTest.php:23`,
    tools.md:1088-1091 documents the 300 DPI mapping "1 pt dotted rule =
    height 4").
  - Follow-up commits of 2026-11-08/09 (variable fonts via `upload_font`,
    rich sample run colours on the stand-in, element opacity, gradient stops
    with opacity "as an overlay over a photo") read like gaps found while
    reproducing real artwork **(inferred from commit subjects and doc wording)**.
- No recorded transcripts, prompt logs or example session files were found in
  the repo (`docs/`, `plugin/`, `tests/` searched for idml / indesign / psd /
  poukaz / sagena).

---

## 9. Open questions and traps for a scanner

Architecture

1. **No LLM client exists in the app.** Which model, which SDK, where the key
   lives (project rule: secrets inline in env vars), cost metering per
   project/Premium, and whether the product loop is "model emits DSL →
   `DesignPreflight` → render → model compares with the source → repeat"
   are all undecided. The MCP plan deliberately kept vision out of the server
   (`mcp-server.md:15`) — this feature reverses that decision.
2. **Sync vs async.** One render is 1–2 s, a multi-round loop plus model
   latency will exceed a web request (PHP 30 s wall clock under FrankenPHP,
   Gotenberg client cap 25 s). The house pattern is a `job_bus` job on the
   `async` transport with a polled status row (calendar export). Traps: one
   consumer, 256 MB soft limit, e-mails queue behind the job, dev/CI have no
   consumer, never return a flushing `StreamedResponse`.
3. **Security context in a consumer.** `DesignVariants`, the MCP tools and
   `GetContextTool` rely on `Security`; a handler has no token. Use the
   services of §3 directly and authorise at the controller that enqueues.
4. **Render capacity.** Every look is a Gotenberg call competing with fill
   pages; nothing queues or rate-limits. Decide a per-scan render budget and
   retry policy for `TemplateRenderUnavailable`.

Fidelity

5. **Text height is not authored** — Fabric wraps at `width`. Wrong face,
   size, `lineHeight` (default 1.16) or a missing letter-spacing changes the
   line count and pushes everything in a container. `TextMeasurer` is ±1 line
   and ignores kerning; only the render is exact.
6. **Fonts are the hardest part.** The picture's typeface is usually not in
   the project; an unknown face is a hard error; there is no fallback font
   and no font-matching service. A project without fonts cannot receive any
   text. Decide: nearest project face, ask the user, or require an upload.
7. `y` is the top of the text box, not the baseline; Fabric adds its own
   internal leading **(inferred — measure against a render when calibrating)**.
8. No rotation, shadows, masks, letter spacing, outlines, mixed styles (§2).
   Decide per feature: approximate, bake into a raster crop, or drop — and
   tell the user what was flattened.
9. **Raster crops need inpainting.** A background or photo crop taken from a
   flattened picture still contains the text/logo drawn over it; the DSL has
   no way to hide that. Clean plates are an image-generation problem outside
   this codebase.
10. Decorative images are contain-fitted, slots are stretched, and there is
    no crop inside a frame — crops must be cut to the exact frame ratio.
11. Logos extracted from a raster are raster; an SVG asset has no natural
    size (the rect is used as-is).
12. Colour: exact hex outside the palette only warns (`color_not_in_palette`);
    snapping near-misses to brand colours is a product decision. JPEG
    artefacts make "the" colour of a flat area ambiguous.
13. Gradient vocabulary is small (linear by angle / radial centre-out, 2–8
    stops).

Data and contracts

14. **Exactly one `background` element**, never a full-canvas shape or image
    (lints `missing_background`, `background_drawn_as_element`); without one
    the export is transparent.
15. **Every text becomes an input.** Decide which are user-fillable and which
    get `locked: true`; set `name`, `maxLength`, `sampleValue` sensibly
    (`max_length_below_stand_in` lint). Input order on the fill page follows
    stack order unless reordered.
16. Positional textbox↔input contract (`DesignCompiler.php:32-40`) — never
    write canvas JSON by hand; always go through the compiler.
17. Dimension: the uploaded picture's pixel size rarely equals a product
    format. Who chooses px vs mm/cm, preset 4:5 / 9:16, DPI (a 1240 px wide
    A4 scan must be scaled ×2 to 2480), bleed (a flat picture has none —
    edge-reaching art must be extended `bleedPx` past the trim)?
18. Canvas side ≤ 10 000 px in the web form, 20 000 in the DSL; WebP previews
    die above 16 383.
19. Upload limit 10 MB decimal must be enforced by the caller when
    dispatching `UploadFile` directly; the >60 MP refusal applies to
    transcodes.
20. Gallery pollution: every crop is a visible gallery picture; a dedicated
    folder (`directoryId`) per scan keeps them together **(inferred
    suggestion)**. A failed scan leaves pictures, a template and a variant
    behind unless cleaned up.
21. If the source upload or debug artefacts are stored under a new key
    prefix / table, add them to `BuildStorageReferenceIndex` and to project
    deletion (`storage-inventory.md:36-49,101`).
22. Grouped variants cannot be written by `set_design`-style saves; multi-size
    output is a separate step (group creation from a variant —
    `template_group_from_template`).
23. Later formats: PDF bytes reach ImageMagick/Ghostscript through the
    normaliser (an open hardening note); PSD/IDML parsing does not exist in
    the app. IDML was only ever handled by a human-driven Claude session
    reading the file outside wboost **(inferred from §8)**.
24. Premium gating has no implementation (§7).
25. Canvas/DSL changes need the usual mirrors: `CANVAS_CUSTOM_PROPERTIES`
    drift test (`DesignCompilerTest` parses the JS list), the skill's generated
    key tables, `docs/mcp/`, and "everything must be MCP-controllable" for new
    template features (project memory).
