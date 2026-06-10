# Build a "Export social templates" feature against the WBoost Brand-Manuals API

> Paste this whole file as the brief for the consuming application. It describes the
> API contract **and** the user-facing feature to build. Credentials below are a
> **local dev** client and are safe to use on localhost only.

## What you're building

A self-contained **"Export" feature** in our app. The project is fixed/pre-provided
(one project id, see Constants). Flow:

1. User clicks an **"Export"** button → opens a dedicated page/route.
2. The page lists the project's **social-network templates** (cards: name, category,
   thumbnail). User **picks a template**.
3. The template has one or more **variants** (different dimensions, e.g. `1:1`
   1080×1080, `9:16` 1080×1920). User **picks a variant**.
4. For the chosen variant the page renders a **form of inputs**, one field per
   placeholder, **respecting the rules the API returns** (label, help text, max
   length, uppercase, locked, hidable — see "Rendering the input form").
5. As the user fills the inputs, show a **live preview** of the rendered image
   (debounced) — or a **"Preview"** button. Preview and the final image are the
   **same render**.
6. A **"Download"** button saves the rendered PNG.

The whole thing is powered by **two API endpoints** plus an OAuth2 token call.

---

## Constants (local dev)

```
# API base — see "Networking" for which host to use where
API_BASE        = http://host.docker.internal:8099   # from a backend container
                = http://localhost:8099              # from the host / the user's browser

OAUTH_CLIENT_ID     = da89105352d907220051561d2721ed21
OAUTH_CLIENT_SECRET = dd1ec42232fa9a41fef876dd1490f129ea9af9ffe5bf2bc1dcac75cdbbb25983

PROJECT_ID      = 01915ca9-07be-70e9-bf1b-3c84dbedde40
```

---

## Networking (Docker)

**All API calls are made server-to-server from your Node.js backend** (never from the
browser). The API runs in Docker on the host, published on port **8099**. Pick the
host by where your Node process runs:

- **Node running in its own container** → `http://host.docker.internal:8099`.
  - Docker Desktop (macOS/Windows): resolves out of the box.
  - Linux: add `extra_hosts: ["host.docker.internal:host-gateway"]` to your service.
- **Node running directly on the host** → `http://localhost:8099`.

> ⚠️ **Absolute URLs in responses point at `localhost`.** `variants[].exportUrl` →
> `http://localhost:8099/...`, `variants[].backgroundImageUrl` /
> `previewImageUrl` → `http://localhost:19000/...` (the S3/Minio store), built from
> the *server's* config.
> - **Don't POST to `exportUrl` verbatim** — rebuild it from `API_BASE` + variant id
>   (its `localhost` host won't resolve from your container).
> - **Thumbnails** (`backgroundImageUrl`/`previewImageUrl`): if your **Node backend**
>   fetches them, rewrite the host to your `API_BASE` host on port **19000** (e.g.
>   `http://host.docker.internal:19000/...`). If you instead pass these URLs to a
>   browser running on the host, `localhost:19000` works there as-is.

---

## Architecture — you are the Node.js backend

Your Node backend is the API consumer. It **holds `OAUTH_CLIENT_SECRET`**, obtains
and caches the bearer token, and makes all calls server-side. **Never expose the
secret or the bearer token to your frontend.** Your frontend talks to *your* Node
endpoints; Node talks to the Brand-Manuals API:

```
Your frontend  ──>  Your Node backend (holds secret + token)  ──>  Brand-Manuals API
                      • GET  /templates  (calls endpoint 1, returns JSON)
                      • POST /preview|/download (calls endpoint 2, returns the PNG)
```

How Node surfaces the rendered PNG to your own UI is your choice — stream the bytes
through, return a base64 data URI, or save to a temp/static path and return its URL.
(CORS on the Brand-Manuals API is open, but it's irrelevant here since the browser
never calls it.)

---

## Step 1 — OAuth2 token (`client_credentials`)

`POST {API_BASE}/api/token`, **form-encoded** (not JSON):

```bash
curl -sX POST $API_BASE/api/token \
  -d 'grant_type=client_credentials' \
  -d 'client_id=da89105352d907220051561d2721ed21' \
  -d 'client_secret=dd1ec42232fa9a41fef876dd1490f129ea9af9ffe5bf2bc1dcac75cdbbb25983'
```

Response:

```json
{ "token_type": "Bearer", "expires_in": 3600, "access_token": "eyJ0eXAi...JWT..." }
```

- Treat `access_token` as an opaque bearer string. Valid **1 hour**.
- **No refresh tokens.** Cache the token in memory; refresh when it nears expiry
  (~90% of `expires_in`) or on the first `401`, then retry once. Don't fetch a new
  token per request.
- Send it on every API call: `Authorization: Bearer <access_token>`.
- Wrong/unknown/revoked client → `401 {"error":"invalid_client"}`.

---

## Step 2 — List templates (endpoint 1)

```
GET {API_BASE}/api/projects/{PROJECT_ID}/social-network-templates
Authorization: Bearer <token>
Accept: application/json
```

Returns a **plain JSON array** (no pagination; null fields are kept on purpose):

```jsonc
[
  {
    "id": "01919974-6ccb-70a8-911e-396002730eb3",
    "name": "asdf",
    "position": 0,                                   // sort ascending for display order
    "categoryId": "01923322-9f8f-71b5-bfda-ed9374b09a0b",  // nullable
    "categoryName": "Kat 1",                          // nullable — group templates by this
    "createdAt": "2024-08-28T14:47:09+00:00",
    "variants": [
      {
        "id": "0191f2be-74be-72e3-95a2-5e5df08897da", // variant id → used for preview/export
        "dimension": "1:1",                            // label for the variant chooser
        "width": 1080,
        "height": 1080,                                // use width/height for the preview box aspect ratio
        "previewImageUrl": null,                        // nullable — cached DEFAULT render (zero user input)
        "backgroundImageUrl": "http://localhost:19000/.../background-....png",  // thumbnail
        "exportUrl": "http://localhost:8099/api/social-network-template-variants/0191f2be-.../export",
        "inputs": [
          {
            "id": "95b025f2-9ea9-40ed-a1e9-737e23a4a953", // inputId UUID → the KEY you send to export
            "name": "Názevxyza",                       // nullable; NOT unique — never bind by name
            "maxLength": null,                          // nullable int; enforce in the field if set
            "locked": false,                            // true → not user-editable (renders its default)
            "uppercase": false,                         // true → value is uppercased on render
            "description": "Popissss",                  // nullable; use as help text / placeholder
            "hidable": false                            // true → offer a "hide this element" toggle
          }
        ],
        "imageInputs": [                                 // fillable IMAGE slots (may be empty)
          {
            "id": "a1b2c3d4-...",                        // imageInputId UUID → the KEY you send in `images`
            "name": "Photo",                             // nullable label
            "description": "Your photo",                 // nullable help text
            "allowMove": true,                            // user may pan the picture in the frame
            "allowResize": true,                          // user may zoom (uniform)
            "allowRotate": false,                         // user may rotate
            "hidable": true,                              // offer a "hide" toggle
            "allowedDirectoryIds": ["0192..."],          // raw designer allow-list ([] = unrestricted)
            "directories": [                              // RESOLVED upload/pick folders with names
              { "id": "0192...", "name": "Photos" }
            ],
            "includesRoot": false,                        // unrestricted slots also use the gallery root
            "frame": { "x": 100, "y": 120, "width": 400, "height": 300 }, // designer's fixed frame (canvas px), nullable
            "defaultImageUrl": "http://.../standin.png"  // stand-in shown if left empty, nullable
          }
        ]
      }
    ]
  }
]
```

UI use:
- Group templates by `categoryName`; order by `position`.
- Variant chooser: show `dimension` + `width`×`height`; use `backgroundImageUrl` or
  `previewImageUrl` as a thumbnail.
- **Bind inputs by `id` (UUID), never by `name`** — names are not unique.

---

## Step 3 — Render (endpoint 2) — used for BOTH preview and download

```
POST {API_BASE}/api/social-network-template-variants/{variantId}/export
Authorization: Bearer <token>
Content-Type: application/json
```

> Build this URL from `API_BASE` + the variant `id`. Don't reuse the response's
> `exportUrl` string from a container (its host is `localhost`).

**Body** — a single `inputs` object **keyed by inputId UUID**:

```jsonc
{
  "inputs": {
    "95b025f2-9ea9-40ed-a1e9-737e23a4a953": "Hello world",          // plain string sets the text
    "db3a6588-d604-4c1b-9440-8412d8624bab": { "value": "Line two" },// object form
    "0a0de75d-57bc-4e35-a2cc-33c8dc5ecd4d": { "hide": true }        // hide (only if hidable)
  }
}
```

Server rules (mirror these in the UI):
- Each value is a **string** *or* `{ "value": "...", "hide": true|false }`.
- `hide` honored only if that input is `hidable: true`; ignored otherwise.
- `maxLength` enforced → over-length value returns **`400`**.
- `uppercase: true` inputs are uppercased automatically.
- **Locked inputs cannot be addressed** (always render their canvas default).
- **Unknown input ids are silently ignored.** Omitted inputs keep the default text.
- `{ "inputs": {} }` (or omitting fields) renders the variant with all defaults.

**Images** — fill IMAGE slots with an optional `images` object, keyed by
`imageInputId` (from `variants[].imageInputs[].id`); combine it with `inputs` in the
same request:

```jsonc
{
  "images": {
    "a1b2c3d4-...": "f0e1d2c3-...",                  // shorthand: gallery image id → centered, object-contain
    "b2c3d4e5-...": { "imageId": "f0e1d2c3-...",     // object form with placement:
                      "scale": 1.4,                  //   × the contain-fit (1 = contain)
                      "offsetX": 20, "offsetY": -10, //   pan from the frame centre, canvas px
                      "rotation": 8 },               //   degrees
    "c3d4e5f6-...": { "hide": true }                 // blank a hidable slot
  }
}
```

- `imageId` must be an image from one of that slot's `allowedDirectoryIds` (list them
  via the gallery endpoint below) — otherwise **`400`**.
- A `scale`/`offset`/`rotation` the slot doesn't permit (`allowResize`/`allowMove`/
  `allowRotate: false`) returns **`400`**.
- Omit a slot → its designer stand-in renders.

**Response:** raw **PNG binary**, `Content-Type: image/png`. **Don't JSON-parse it.**
- **Download:** save the bytes (suggest filename `<template-name>-<dimension>.png`).
- **Preview:** display the same bytes (see "Live preview").

**Status codes:** `200` PNG · `400` malformed / value too long · `401` bad token ·
`403` variant not visible to this client's user · `404` variant not found ·
`500` render backend failure.

---

## Rendering the input form (per selected variant)

For each entry in `variant.inputs`:

| Field | UI behavior |
|---|---|
| `name` | Field label. If `null`, fall back to `description`, else a generic "Text N". |
| `description` | Help text / placeholder under the label. |
| `maxLength` | If set, set the input's `maxlength` and show a counter; block sending over-length (server returns `400` otherwise). |
| `uppercase` | Optional: visually uppercase the field (server uppercases anyway). A hint like "shown in UPPERCASE" is nice. |
| `locked` | **Don't render an editable field.** Either omit it, or show it read-only/disabled with the description — its value can't be overridden. |
| `hidable` | Render a checkbox/toggle "Hide this element". When on, send `{ "hide": true }` for that id (you may still include `value`). |

Build the `inputs` payload as `{ <inputId>: <string|{value,hide}> }`, including only
the inputs the user actually edited/toggled (omitted = default).

### Image placeholders (`variant.imageInputs`)

For each image slot, let the user pick a picture (and, if allowed, position it):

1. **List pickable images** for the slot (its allowed folders; for an
   unrestricted slot — `includesRoot: true` — the whole gallery, where root
   images carry a null/absent `directoryId`/`directoryName`):
   ```
   GET {API_BASE}/api/social-network-template-variants/{variantId}/placeholders/{imageInputId}/images
   ```
   → `[{ "id", "url", "directoryId", "directoryName", "uploadedAt" }]`. Show a
   thumbnail picker; the chosen `id` is the `imageId` you send in `images`.

2. **(Optional) upload a new one** — the target folder is the USER'S choice:
   ```
   POST {API_BASE}/api/social-network-template-variants/{variantId}/placeholders/{imageInputId}/images
   Content-Type: multipart/form-data    (field `file`; field `directoryId` — see below)
   ```
   → `{ "id", "url", "directoryId" }` (null `directoryId` = gallery root). Use
   the returned `id` as the `imageId`.

   `directoryId` rules — render the slot's `directories` (+ a "Galerie" root
   option when `includesRoot`) as a folder select next to the upload control:
   - several folders in `directories` (restricted slot) → `directoryId`
     **required**, otherwise **`400`**;
   - exactly one folder, not `includesRoot` → optional (auto-resolved);
   - `includesRoot: true` (unrestricted) → optional; omitted = gallery root;
   - a folder outside `directories` → **`403`**.

3. **Placement** defaults to centered + object-contain inside `frame`. Expose `scale`
   (zoom), `offsetX`/`offsetY` (pan, canvas px) and `rotation` (deg) only for the
   `allow*` flags the slot sets, and send them in `images`. `frame` + the variant's
   `width`/`height` give you the geometry for a positioning UI. Leave a slot empty to
   keep its `defaultImageUrl` stand-in.

---

## Live preview — supported, but it's a full render

There is **no dedicated lightweight preview endpoint**. Preview uses the **same
`export` render** (headless Chromium via Gotenberg, ~1–2 s, **uncached**, no rate
limit but heavy). Implement it as:

- **Initial state:** show `variant.previewImageUrl` if non-null (the cached default
  render). If null, do one render with `{ "inputs": {} }`.
- **On edit:** **debounce ~600–800 ms** after the user stops typing, then POST to
  `export` with the current inputs and swap the preview image. Show a small spinner
  while rendering. (This mirrors how our own user-fill page debounces at 600 ms.)
- **Or** skip auto-preview and provide an explicit **"Preview"** button — preferred
  if you want to minimize render load. A "Download" button always does a final render.
- **Don't** render on every keystroke — each call is a full PNG.
- **Validate before sending** (respect `maxLength`) so you don't trigger `400`s
  during live preview.

### Rendering the PNG in Node (preview or download)

The render call returns raw PNG **binary** — read it as an `ArrayBuffer`/`Buffer`,
not JSON. Node 18+ has global `fetch`:

```js
async function renderVariant(variantId, inputs) {
  const token = await tokenManager.getToken();
  const res = await fetch(
    `${API_BASE}/api/social-network-template-variants/${variantId}/export`,
    {
      method: 'POST',
      headers: { 'Authorization': `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ inputs }),
    },
  );
  if (!res.ok) throw new Error(`export failed: ${res.status}`); // 400/401/403/404/500
  return Buffer.from(await res.arrayBuffer());                  // PNG bytes
}
```

Surface it to your frontend however you prefer — e.g. stream it back from your own
route:

```js
// GET/POST /preview on YOUR backend
const png = await renderVariant(variantId, inputs);
res.set('Content-Type', 'image/png').send(png);
// download: res.set('Content-Disposition', 'attachment; filename="export.png"').send(png);
// or return JSON: { dataUri: `data:image/png;base64,${png.toString('base64')}` }
```

Your frontend's `<img>` then points at *your* `/preview` route (no token needed
client-side). The debounced live-preview logic lives in your frontend; the actual
render always happens here in Node.

---

## End-to-end smoke test (host shell)

```bash
API_BASE=http://localhost:8099
PROJECT_ID=01915ca9-07be-70e9-bf1b-3c84dbedde40

TOKEN=$(curl -sX POST $API_BASE/api/token \
  -d 'grant_type=client_credentials' \
  -d 'client_id=da89105352d907220051561d2721ed21' \
  -d 'client_secret=dd1ec42232fa9a41fef876dd1490f129ea9af9ffe5bf2bc1dcac75cdbbb25983' \
  | python3 -c 'import json,sys; print(json.load(sys.stdin)["access_token"])')

# 1. list templates → discover variant ids + input ids
curl -s $API_BASE/api/projects/$PROJECT_ID/social-network-templates \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' | python3 -m json.tool | head -60

# 2. render a variant to PNG (preview == download)
curl -s -X POST $API_BASE/api/social-network-template-variants/0191f2be-74be-72e3-95a2-5e5df08897da/export \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"inputs":{"95b025f2-9ea9-40ed-a1e9-737e23a4a953":"Hello world"}}' \
  --output out.png
file out.png   # -> PNG image data, 1080 x 1080
```

---

## Suggested module layout (consuming app)

1. **TokenManager** — `getToken()` returns a cached bearer; refreshes at ~90% TTL or on 401.
2. **`listTemplates(projectId)`** — GET endpoint 1 → parsed array.
3. **`renderVariant(variantId, inputs)`** — POST endpoint 2 (URL from `API_BASE` + id) → PNG bytes. Used for both preview and download.
4. **UI**: Export button → template grid (grouped by `categoryName`) → variant chooser → input form (rules table above) → debounced live preview + Download.
