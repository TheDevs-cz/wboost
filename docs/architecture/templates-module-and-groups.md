# Templates — merge history, dimensions, groups, group editor sync

### One "Šablony" module — merge history + dimension model

Until 2026-08 the app had three sibling sections: "Sociální sítě" (fixed-format
social templates), "Šablony" (free-form custom templates) and "Skupiny šablon"
(cross-module sync groups). They are now ONE "Šablony" module: entities
`Template` / `TemplateVariant` / `TemplateCategory` / `TemplateGroup`, CQRS
under `Message/Template/` + `Message/TemplateGroup/`, web controllers under
`Controller/Template/` + `Controller/TemplateGroup/`. Every
`SocialNetworkTemplate*` entity/controller/message/API class is DELETED, and
the frozen `social_network_*` tables were dropped by `Version20260804210000`
(the data was merged into the unified tables first; a pre-merge backup lives
on lily).

**Migration chain** (`Version20260804080000` / `081500` / `090000`):

1. Renamed the `custom_template*` tables → `template*` (hand-named group
   indexes/constraints follow) and folded stored `'custom_template'`
   discriminator values into `'template'` (`export_event.template_type`,
   `storage_object.category`). Historical `'social_network'` rows keep their
   value.
2. Added nullable `template_variant.dimension_preset`.
3. THE data merge: copied the whole social-network stack into the unified
   tables — same UUIDs, JSONB canvas columns copied verbatim; categories
   merged **by exact name per project**; each group's social+custom member
   template pair consolidated into ONE template (the custom-side row wins and
   absorbs the social side's variants), so a group maps 1:1 to one template
   with mixed-dimension variants. Down migration restores the renames but the
   copy/consolidation is NOT reversed.

**Dimensions — `WBoost\Web\Value\TemplateDimension`** (Doctrine embeddable,
column prefix `dimension_`): a `DimensionUnit` (px / mm / cm; physical units
rasterize at **300 DPI** = `DimensionUnit::PRINT_DPI`) + `unitWidth` /
`unitHeight` + a NULLABLE `preset` — the `DimensionPreset` enum `'1:1'` /
`'4:5'` / `'9:16'`, i.e. the fixed 1080×1080 / 1080×1350 / 1080×1920 Instagram
formats (`TemplateDimension::fromPreset()`). `width()`/`height()` return canvas
pixels everywhere; `label()` returns the ratio for preset variants and
"210 × 297 mm"-style for free-form ones. NOTE: the stored properties are
`unitWidth`/`unitHeight` on purpose — a public `width` property would shadow
the `width()` px method in Twig attribute lookup. The add-variant form offers
A5/A4/A3 one-click mm presets AND Instagram preset buttons; the latter stamp a
hidden `preset` field which ANY manual unit/size edit clears
(`template_dimension_controller.js`; the group add-dimension form mirrors this
via `template_group_dimension_controller.js` + `TemplateGroupDimensionFormData`).
A "social format" is nothing more than a preset dimension now.

**Publish to FB/IG is preset-gated.** `TemplateVariantPublishController`
(`POST /template-variant/{variantId}/publish`, route `template_variant_publish`)
answers 400 when `dimension->preset === null`; the fill page renders its
publish chrome via `AbstractVariantFiller::canPublish()` (the same check).
That chrome is the Export split button's menu ("Publikovat na Facebook /
Instagram"). **Fake door (2026-10-13):** prod has no Meta app yet, so without a
connected account the items — and the left-nav "Facebook a Instagram"
entry (`facebook_connected()` Twig fn) — open the in-page "coming soon" modal
`_social_publish_fake_door.html.twig` (included by `base.html.twig`). Every
publish entry stays on the page (type="button", modals only) so an unsaved fill
is never lost; a connected account still gets the real `variant-publish` modal.

**Template groups — "synchronized templates".** A `TemplateGroup` maps 1:1 to
ONE `Template` whose variants span multiple dimensions; membership is a
nullable `group` FK on `template` AND `template_variant` (`ON DELETE SET NULL`
— deleting the group row only un-groups), and only GROUP-CREATED variants
carry the FK: manually added variants on a grouped template keep
`group = null`. The standalone group listing is GONE — grouped templates
appear on the unified listing (`templates.html.twig` / `_template.html.twig`)
as **"Synchronizováno"** cards: the card body/menu leads to the group editor
(`template_group_editor`) for designers and to the group fill page
(`template_group_fill`) for everyone else, and delete opens a **two-mode
modal** (`deleteTemplates=0` "Zrušit pouze synchronizaci" un-groups only,
`=1` "Smazat včetně šablony" → `DeleteTemplateGroup`). Individual variant
editing is BLOCKED for group-created variants —
`TemplateVariantEditorController` redirects to the group editor when
`variant->group !== null` (a single-variant edit would be clobbered by the
next group save). Authorisation: `TemplateGroupVoter` — **`VIEW`** (fill,
fill-preview, export, placeholder upload) delegates to `ProjectVoter::VIEW`
(admin, owner, any shared user); **`EDIT`** (group editor, add-dimension)
stays a designer/owner/admin tool.

**Duplicating ("Duplikovat", `copy_template` → `CopyTemplateHandler`).** A deep,
unaffiliated clone — nothing links the copy back to its source (name gets
" (kopie)", `fillFieldOrder` is carried over, storage keys are shared, never
re-uploaded). A grouped template is copied into a **brand-new group of its own**
(same name as the copy, 1:1 as always): only the variants that were group
members join it, manually added ones stay outside, mirroring the source. The
menu item sits in the designer-gated block of the "Synchronizováno" card; the
controller re-checks `TemplateGroupVoter::EDIT` and lands in the copy's group
editor. Per-variant "Duplikovat" (`copy_template_variant`) never sets a group.

**Group editor propagation — two target sets (2026-08-07).** `GroupSync` is
handed `targets()` AND `allTargets()`, and the split is what the rail's UI
means:

- **EDITS** (moves, resizes, styles, metadata, z-order, containers — i.e.
  `syncPass`) go to `targets()`: EMPTY when the designer turns off
  **"Úprava více variant"** (the mode button in the variant rail — ON by
  default with every variant included since 2026-08-10; was OFF by default),
  otherwise the variants whose per-variant switch is on. Those switches are
  chrome of the mode and only render with it (CSS `--multi` on the rail); the
  ACTIVE variant's is forced on + disabled. The template's server-rendered
  initial chrome must stay in lockstep with the controller's `multiEdit`
  default (`_refreshRail` only syncs after hydration). Because the mode is
  live from the first paint, the debounced edit pass DEFERS while shadows
  hydrate (`_shadowsHydrated`) and fans out once at boot-end via
  `_flushPendingSync` — flushing (or plain-rebaselining) earlier would fan
  the diff to the hydrated subset only, or consume it entirely.
- **STRUCTURE** (`projectNewObject`, `removeObject` and the explicit
  per-element "Srovnat podle skupiny" `resync`) goes to
  `allTargets()` — every dimension, no opt-out, mode or not. The background
  PICTURE is the exception since 2026-08-10: `projectBackgroundLayer` goes to
  `targets()` (mode-gated) so per-variant backgrounds stay authorable — with
  the mode on and everything included (the default) a pick still reaches
  every dimension; turning the mode off makes it a single-variant change.
  The object set
  MUST stay identical across dimensions: one silently missing an element (or
  background) renders as a scrambled stack, the exact failure the group model
  exists to prevent. Delete mirrors add so an always-fanned-out object stays
  removable in one go; per-dimension "not shown here" is the layers-panel eye,
  which travels as an ordinary (gated) edit.

Flipping the mode settles the debounced pass under the OLD mode and then
rebaselines — otherwise edits made while it was off would fan out
retroactively on the next mutation. The rail carries NO per-variant preview
and no whole-variant re-sync button (both retired 2026-08-07): it is
navigation (chips: label, dirty dot, overflow badge), the stage is the
preview.

**Structural reliability (2026-08-10).** The "object set identical across
dimensions" invariant used to be enforceable only at the moment of the action,
and three windows let an add/delete land on a subset of variants (the prod
"object added to one variant only" → "image fill exported on one variant only"
report): pre-2026-08-07 adds respected the include switches (divergence from
then is persisted in the DB), the Fabric hooks were live before the sibling
shadows hydrated (`allTargets()` silently filters shadow-less variants), and a
failed `clone()` (image src fetch) aborted the fan-out loop mid-way with no
catch. Now all propagation ops (add / remove / background pick / "Srovnat
podle skupiny") run through `_enqueueStructural` — ONE serialized promise
chain gated on `_shadowsReady` — with per-target try/catch in
`projectNewObject`/`projectBackgroundLayer` (one failing variant never strands
the rest). Tab switch, undo/redo and save first `_drainStructuralOps()` (a
clone landing in a shadow after it was serialized would be clobbered on the
next switch-away), and `_activate` settles the debounced edit pass BEFORE
setting `_switching` (the old in-`try` flush was dead — `_quiet` blocked it).
Persisted divergence is healed by `GroupSync.reconcileStructure()` — ADD-ONLY
(an object missing on the active canvas but present on a sibling heals when
THAT variant is activated; deleting would destroy work) — which runs after
boot, after every tab switch and before save, so a divergent group repairs
itself progressively as a designer opens tabs and saves. Reconcile passes are
`pushHistory: false` (no undo points of their own) but DO mark healed variants
dirty, so the heal rides the next save. A variant whose shadow hydration
throws is nulled back to `shadow: null` — fully inert (excluded from targets,
save and tab switching) — because a half-hydrated shadow would be serialized
over the variant's real saved canvas on save.

**Network-flake hardening (2026-08-31, the "add sometimes misses a variant"
report).** Root mechanism, verified against the committed Fabric 7.3.1 build
in headless Chrome: **`loadFromJSON` does NOT reject when an image src fails
to fetch — it resolves with that object silently DROPPED from the canvas.**
Every canvas (re)load re-fetches its image srcs over the network, so one
transient flake during shadow hydration / tab-switch reload / history restore
produced a shadow missing an object with only a console line as evidence (no
JS Sentry exists — hence "nothing in Sentry, seems random"), and the next
save persisted the loss. Worse, `restoreCustomProperties` mapped source→
loaded objects by POSITIONAL INDEX, so a mid-stack drop re-stamped every
object above the gap with the previous entry's `inputId` and scrambled the
inputId-keyed propagation for that variant. A prod audit found real persisted
divergence (group `019fd23a-ac3b…`: two image placeholders in one variant
only, the sibling's canvas empty). Hardening, all browser-verified via the
same-origin static-harness recipe with an injected broken-src image:

- `restoreCustomProperties` is **drop-aware**: pure index map only when the
  counts agree; on mismatch it console.errors and aligns source→loaded as an
  ordered subsequence on type + position, so the scramble is impossible.
- `_loadShadow` **throws when the loaded object count falls short** of the
  document's, turning the silent drop into a real failure; a lossy shadow is
  never left in play (it would propagate wrong AND save over the variant).
- `_hydrateShadowWithRetry` (boot) retries 3× with 500/1500 ms backoff; a
  variant that still fails is `loadFailed` → visible **"Nenačteno" rail
  badge** (precedence over overflow/off-canvas), excluded from targets and
  save (its stored canvas stays intact). Clicking the chip re-hydrates
  through the structural chain and reconciles FROM the current active variant
  BEFORE activating (reconcile is add-only from the active side). The
  `_loadShadow` calls in `_activate` (switch-away reserialization) and
  `_restoreSnapshot` catch per-variant: `variant.canvas` keeps the serialized
  truth, the shadow is nulled + `loadFailed` for the badge's re-hydration.
- The INTERACTIVE canvas load gets the mirrored guard: `_activate` retries
  the incoming load once when it comes up short of the shadow's count, and
  boot runs `_healActiveCanvasDrop` (reload from the intact shadow) — a lossy
  active canvas would otherwise serialize the loss back over the variant.
- The per-target clone in `projectNewObject`/`projectBackgroundLayer` retries
  once after 400 ms behind an idempotent `attempt()` (presence check inside).
- `submitForm` drains the op chain after its reconcile (everything to the
  fetch is then synchronous) and runs `_structuralGaps()` — active-canvas
  syncable inputIds vs each sibling shadow (`isSyncable` is exported from
  group_sync.js for this) — and ALERTS with the affected variant labels
  instead of silently saving divergence; the save still proceeds (blocking
  would hold the healthy variants' work hostage).

**Gone pictures are stand-ins, not load failures (2026-09-08).** The
hardening above treats every dropped object as a flake, but a picture whose
file is GONE FOR GOOD (a gallery image purged from the Koš — the prod group
`019fe590…` referenced two) fails identically on every attempt: every
variant ended up "Nenačteno", nothing propagated or saved, and the designer
could not even delete the dead object to recover (the single editor was
quieter and worse: it dropped the object and the next save persisted the
deletion). `assets/controllers/canvas_missing_images.js` — dependency-free,
used by BOTH loaders (`loadCanvasWithoutHistory`, `_loadShadow`) — wraps
`loadFromJSON`: only on a count shortfall it aligns source↔loaded (the
ordered-subsequence match `restoreCustomProperties` also uses, now shared as
`alignLoadedObjects`), PROBES each dropped image's src, and reloads with a
red hatched stand-in tile (SVG data URI at the original's pixel size, so
geometry, inputId and metadata are untouched) for every definitive 404/410;
anything else (network error, 5xx, CORS, a dropped non-image) stays a
transient failure with the retry / "Nenačteno" semantics. Contract: the
original src lives in the session-only `missingSrc` custom property
(∈ `CANVAS_CUSTOM_PROPERTIES`, so undo/redo, tab switches and group-sync
clones keep the marker without re-probing — verdicts are cached per src),
and `buildVariantPayload` puts it BACK into `src` and strips the marker, so
**the saved document is never rewritten** and the server never sees the
property (the renderer already drops an unreadable picture, so exports are
unchanged); `withStandInsHidden` keeps the tiles out of the save-time
preview thumbnails. Surfaces: a coalesced warning toast per page load
(`reportMissingImages`, once per src) and a red "chybí soubor" badge on the
layers-panel row. The designer resolves it by deleting the layer or adding
the picture again; the purge guard on the gallery side (below) is what keeps
new cases from arising.

**Web routes** live under `/project/{projectId}/templates` (name `templates`),
`/template/{templateId}/…`, `/template-variant/{variantId}/…` and
`/template-group/{groupId}/…`. The old social/custom URL families are GONE
with **no redirects** — a deliberate decision.

**Namespaces that deliberately did NOT move**: the shared fill-engine services
still live under `Services/SocialNetwork/*` (`ResolveTextOverrides`,
`ResolveImageOverrides`, `ImagePlacement`, `PlaceholderImageUploader`,
`PlaceholderAllowedDirectories`, `TextInputObjectBinder`, `AssetInliner`,
`ResolveRichTextOptions`, `CanvasPlaceholderGeometry`, `FillFormRequestParser`)
— only their variant unions were narrowed to `TemplateVariant`. Group-only
services live under `Services/TemplateGroup/` (`CanvasDesignProjector`,
`GroupFillRenderer`, `GroupFillPlaceholders`). Storage key prefixes are
likewise unmoved — see the storage section.

**Single-module surfaces** (formerly "shared" between two modules):
`templates/template_variant_editor.html.twig` is rendered by
`TemplateVariantEditorController` (labels/routes still arrive as template vars
— `menu_item`, `module_label`, `dimension_label`); the group editor renders its
own `template_group_editor.html.twig` but saves through the same
`EditTemplateVariantCanvasEditor` message per variant. The user-fill engine is
`src/Twig/Components/AbstractVariantFiller.php` + the single template
`templates/components/VariantFiller.html.twig`; **`Template:VariantFiller`**
is its only subclass, binding the entity-typed `$variant` LiveProp,
`TemplateVariantVoter::VIEW`, and the `template_variant_download` /
`template_variant_placeholder_upload` / `template_variant_publish` routes.

**Template API** (`src/Api/Templates/`): canonical
`GET /api/projects/{projectId}/templates` (variants expose the `dimension`
label — the ratio for presets — plus nullable `preset`, `unit`, `unitWidth`,
`unitHeight` and px `width`/`height`),
`POST /api/template-variants/{id}/export`,
`GET|POST /api/template-variants/{variantId}/placeholders/{inputId}/images`,
`GET /api/template-variants/{variantId}/thumbnail`. The old
`…custom-template…` AND `…social-network-template…` path families keep working
as **deprecated aliases** over the very same providers/processors/controllers
(`deprecationReason` on the API Platform operations; `*_legacy_custom` /
`*_legacy_social` route names on the thumbnail + upload controllers). mfkfm is
already ported to the canonical paths.
