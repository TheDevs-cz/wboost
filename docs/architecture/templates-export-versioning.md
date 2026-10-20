# Templates — export versioning ("Historie exportů")

**Export versioning — "Historie exportů" (2026-09-01).** Every SUCCESSFUL
export snapshots its fill values as a re-usable version so users can re-load
what they exported before. Entity `TemplateExportVersion` (table
`template_export_version`, JSONB `fill_values` via
`ExportFillValuesDoctrineType` → the `ExportFillValues` VO) — LIVE functional
data with real FKs, unlike the denormalised `ExportEvent` analytics row that
is written right next to it: variant/group/template deletion CASCADEs the
history away, a deleted user only SET-NULLs `exported_by`. Exactly one of
`variant`/`group` is set — the ZIP export AND the per-dimension group
download both snapshot the same group fill form onto ONE group-scoped
version.

- **Recording**: `Services/Template/RecordExportVersion` (the
  RecordExportUsage twin — swallow+log, dispatched AFTER a successful render)
  is called at all six chokepoints with the surface's raw values;
  `ExportFillValues::fromVariantWebForm/fromGroupWebForm/fromApiRequest`
  normalise them into the WEB WIRE SHAPE (API rich `runs` re-encode as the
  envelope STRING the mirrors carry); both web forms KEEP empty strings
  (= "blank the text" — the group form dropped them as "keep designed"
  until the 2026-09-03 fill-page unification). `toArray()` is canonical (recursive ksort, floats
  rounded to 4 decimals) and `hash()` (sha256 over it) is the dedup key: the
  handler (`RecordTemplateExportVersionHandler`) bumps
  `lastExportedAt`/`exportCount`/`exportedBy`/`channel` on a same-(subject,
  hash) row instead of inserting, and prunes each subject's UNPINNED
  versions to `MAX_VERSIONS` (100 since 2026-09-07; was 30 while the
  dropdown was the only surface). No unique constraint on purpose — a lost
  race merely duplicates a history row, a violation would abort the export's
  transaction. An all-defaults export still records (dedup collapses the
  repeats).
- **Drafts — "Uložit rozpracované" (2026-10-12, the "an overflowing text
  blocks the export, so I can't save and must start over" report).** A
  version can be a DRAFT (`draft` bool, additive migration; `exportCount` 0,
  `lastExportedAt` = last save). Both fill pages carry an always-enabled
  "Uložit rozpracované" button (NOT an `exportButton` target, so the overflow
  gate never touches it; Ctrl/⌘+S too) — `fill_draft_controller.js` POSTs the
  fill form AS IT IS to `template_variant_fill_draft` /
  `template_group_fill_draft` (CSRF id `template_fill_draft`, field
  `_fill_draft_token`; VIEW-gated). NO render and no resolver validation —
  only the `ExportFillValues::from*WebForm` normalisation — so any state
  saves; the lenient seeder is what makes any draft re-loadable.
  `SaveTemplateFillDraft` overwrites the page's own draft in place
  (`draftVersionId` field = the loaded version; `findDraftOf` accepts it
  only when it IS a draft of THIS subject — a loaded export, a foreign or a
  pruned id start a new draft). The answer is a Turbo Stream
  (`ExportVersionTurboStream::draftSaved` → `_fill_draft_saved.stream.html.twig`)
  replacing the WHOLE dropdown `#export-history-menu` (always rendered, empty
  while there is no history — the first save creates it; its toggle then
  names the draft) and the anchors, with the draft as the loaded version; the
  `X-Fill-Draft-Version` header feeds the field + `history.replaceState`
  (`?version=<draft>`). Single page: the controller wraps the deferred Live
  component and the two hidden fields sit OUTSIDE it, joined via
  `form="variant-fill-form"`, so no morph resets them. Leaving with unsaved
  changes warns (beforeunload; baseline = form state at the first touch,
  re-based on every save and export submit). **Export semantics**: the four
  web export paths pass `draftVersionId` to `RecordExportVersion`; a draft of
  the subject is PROMOTED (becomes the export's version, name/pin kept) —
  or, when an exported version with the same hash exists, merged into it
  (`absorbCuration`: name if it has none, pin) and deleted. Export dedup
  (`findDuplicate`) never matches drafts, and the listing queries
  (`latestFor*`, "naposledy exportováno") exclude them. Drafts count toward
  the 100 unpinned cap. Badge "Rozpracováno" in the dropdown and on the
  history page; the toggle label reads "Rozpracováno <d.m. H:i>" for an
  unnamed loaded draft.
- **Shape colours (2026-10-20)**: `ExportFillValues::$shapeColors` (shape
  inputId → `#rrggbb`, `shapeColorValues[…]` on the web forms, `shapeColors`
  on the API) is emitted in the canonical form ONLY when non-empty — the
  `fonts` rule, so every stored colour-less fill keeps its hash. Hexes are
  normalized before hashing (`#FFF` ≡ `#ffffff`); "" (the designed colour)
  is not kept. The seeder re-validates picks leniently through
  `ResolveShapeColorOverrides` (`shapeColorValues` for the single page,
  `shapeColors` for the group page, first member carrying the shape wins).
- **Re-loading** (`?version=<id>` on both fill pages; invalid/foreign/pruned
  ids silently ignored): `Services/Template/ExportVersionSeeder` is LENIENT
  where the render resolvers 400 — deleted inputs drop, a rich envelope on a
  now-plain input degrades to its text concat, a trashed/purged/other-project
  picture or one moved out of the slot's allowed folders falls back to the
  designed stand-in, and transform fields the slot no longer permits are
  stripped (a seeded page must always be exportable again). Single-variant
  page: texts/hides seed as `:textValues`/`:hiddenValues` mount props
  (postMount's `??=` fills only what the version left blank) and image state
  as `:seedImageValues`, folded into the `imagePlaceholders()` payload —
  the twig pre-fills the hidden `images[…]` fields + hide checkbox and
  `variant_image_fill_controller._restoreSeed()` redraws the picked pictures
  with their transforms on connect (no activation, fields left as
  server-rendered). Group page: server-rendered form values + a
  `data-group-fill-seed-value` JSON that `group_fill_controller._applySeed()`
  replays AFTER connect()'s neutral-placement reset (server-rendered
  placement fields alone would be wiped by it).
- **Curation — names + pins (2026-09-07, the "I keep going back to one
  version and it scrolls out of reach" report).** `name` (nullable, 120
  chars, replaces the date label everywhere) and `pinnedAt` (nullable;
  pinned = sorts FIRST on every surface, most recent pin on top, and is
  EXEMPT from the prune — `TemplateExportVersionRepository::prune` filters
  `pinnedAt IS NULL`, so the cap counts unpinned rows only). Both are shared
  per template, not per user. Messages `RenameTemplateExportVersion` /
  `PinTemplateExportVersion` (+ handlers); POST endpoints
  `/export-version/{id}/rename` and `/export-version/{id}/pin` (`pinned=1|0`),
  CSRF ids = the route names, gated by `TemplateExportVersionVoter::MANAGE`
  which delegates to the fill surface's VIEW (variant or group voter — anyone
  who can fill can curate). Each form carries a `redirect` field;
  `Services/Template/ExportVersionRedirect` honours LOCAL paths only and
  falls back to the version's history page (never an open redirect).
  `Query/GetExportVersions::forVariant/forGroup` now return a
  `Value/ExportHistory` (pinned + unpinned lists, `recent(5)`, `all()`,
  `total()`) — no LIMIT, the split/sort is PHP-side. **Ordering ties
  break on the id** (query, VO sorts AND the prune's oldest-first offset):
  `last_exported_at` is TIMESTAMP(0), so two exports in one second tie,
  and the id is a UUID v7 — time-ordered — which keeps the dropdown
  stable across reloads (the 2026-09-07 CI flake).
- **UI**: `_export_history_menu.html.twig` (dropdown on both fill pages:
  "Připnuté verze" section + the `ExportHistory::MENU_RECENT` = 5 most recent
  unpinned + "Zobrazit vše (N)" → the history page + "Zpět na výchozí
  hodnoty"; every row has an inline pin toggle AND an inline rename (a
  pencil flips the label into the rename field — `export_version_row_controller.js`,
  Escape / × cancel, Enter / ✓ save) — rows are `div.dropdown-item` wrapping
  the load `<a>` + the controls, since an `<a>` cannot contain form
  controls; the rows live in `_export_history_menu_body.html.twig`, which
  owns `#export-history-menu-body`), `_export_history_toggle_label.html.twig`
  (owns `#export-history-toggle-label`: "Historie exportů", or — with a
  version loaded — its name, else "Export|Rozpracováno <d.m. H:i>", full
  details in the `title`). **There is NO loaded-version banner** (removed
  2026-10-13: a full-width alert repeating the dropdown's controls, which
  users said they never understood nor used — "Ten velký banner … zrušme
  to"); the toggle label is the only "what is loaded" signal, and the
  loaded row (`.active`) carries rename/pin like every row, the dedicated page
  `template_export_history.html.twig` (routes
  `template_variant_export_history` = `/template-variant/{id}/export-history`
  and `template_group_export_history` = `/template-group/{id}/export-history`,
  VIEW-gated; pinned-first table with per-row rename form, pin toggle,
  "Načíst" link and a fill DIGEST — `Services/Template/SummarizeExportVersion`:
  texts labelled by input name in design order, rich envelopes flattened to
  plain text, 60-char cut, picture / hidden counts; deliberately NO thumbnail
  per version, that would be a render per row). Shared form partials
  `_export_version_pin_button.html.twig` / `_export_version_rename_form.html.twig`
  (`data-export-version-pin` / `data-export-version-rename` hooks are what the
  tests read tokens from). **Neither renders a `<form>` of its own**: the
  controls carry `form="export-version-(pin|rename)-<id>"` and submit through
  the empty, hidden anchors of `_export_version_form_anchors.html.twig`,
  included ONCE per page OUTSIDE every other form (`ExportHistory::menu(loaded)`
  on the fill pages, `all()` on the history page). The group fill page wraps
  its dropdown in the fill form, and a nested `<form>` tag is not
  merely invalid: browsers AND libxml drop the start tag and its `</form>`
  closes the OUTER form — every fill field below the header ended up outside
  the fill form (boxes `display:none`, overlay targets unreachable, previews
  and the ZIP export posting six orphaned pin inputs; 2026-09-08, one day
  after the pins shipped). Never include these partials as real forms; the
  group-page regression test asserts the mirrors and surfaces are descendants
  of `form.fill-form`. **The fill pages curate IN PLACE via Turbo Streams
  (2026-09-08)** — a pin / rename used to be a full navigation, which threw
  away everything typed into the fill form. Turbo ships on every page but
  Drive is off site-wide (`<html data-turbo="false">` in `base.html.twig`),
  so the fill pages opt their anchors in (`turbo: true` on the anchors
  include → `data-turbo="true"`) AND their submit buttons (the pin / rename
  partials' `turbo` flag — Turbo takes a submission over only when the form
  and its submitter are both navigatable, and the buttons sit in the page,
  not in the anchor). `Services/Template/ExportVersionTurboStream` answers
  a request whose preferred format is `TurboBundle::STREAM_FORMAT` with
  `_export_version_curation.stream.html.twig`: three `replace` actions —
  the menu body, the toggle label (when the anchors' `loaded` field names a
  version of the same surface) and the anchors wrapper
  `#export-version-form-anchors` (a version that just entered the "recent"
  rows needs an anchor of its own). Every fragment partial renders its own
  id and the stream never touches the dropdown SHELL, which together with
  `data-bs-auto-close="outside"` is what keeps the open menu open; no flash
  is queued on that path (it would surface on the next navigation, out of
  context — the flipped pin / relabelled row is the confirmation). The
  history page keeps plain forms + the redirect: the page IS the list.
  Listing surfaces read
  `Query/GetExportVersions` (`latestForProjectTemplates` /
  `latestForTemplateVariants`, Postgres DISTINCT ON): template cards show
  "Naposledy exportováno", variant tiles add a "Načíst poslední export" menu
  item.
