# What's new: Image placeholders (API delta)

> For integrators who **already support text placeholders**. This describes only
> what was **added** for image placeholders — nothing about text inputs changed,
> and the change is **fully backward-compatible**: if you send no image fields,
> every existing call behaves exactly as before (image slots render the
> designer's stand-in picture).

A template variant can now contain **image slots** in addition to text inputs. A
slot is a fixed frame the designer drew; the end-user picks (or uploads) a
picture, which is placed **object-contain, centered** inside that frame and may
be moved / zoomed / rotated **within the limits the designer set**. As with text,
everything renders **server-side** — preview and download are the same render.

There are **two API changes** to endpoints you already call, plus **two new
endpoints**.

> **Update (June 2026) — upload folder choice.** The upload endpoint (§4) no
> longer silently drops files into the slot's *first* allowed folder. The
> **uploader chooses** the target folder via `directoryId`:
>
> - slot with **one** allowed folder → `directoryId` optional (unambiguous);
> - slot with **several** allowed folders → `directoryId` **required**
>   (omitting it now returns `400`);
> - **unrestricted** slot (`allowedDirectoryIds: []`) → the whole gallery is
>   open, **including the gallery root**; omitting `directoryId` stores the
>   file in the root (`directoryId: null` in the response).
>
> To render the folder choice, each `imageInputs[]` entry now lists its
> resolved `directories` (id + name) and an `includesRoot` flag (§1), and the
> slot-gallery listing (§3) includes root images (null `directoryId` /
> `directoryName`) for unrestricted slots.

---

## 1. Templates listing — variants gain `imageInputs[]`

`GET /api/projects/{projectId}/social-network-templates` is unchanged except each
variant now also carries an `imageInputs` array (empty when the variant has no
image slots). It sits next to the existing `inputs` array:

```jsonc
"variants": [
  {
    "id": "0191f2be-...",
    "width": 1080, "height": 1080,
    "inputs": [ /* ...unchanged text inputs... */ ],
    "imageInputs": [
      {
        "id": "a1b2c3d4-...",                  // imageInputId UUID — the KEY you send in `images`
        "name": "Photo",                        // nullable label
        "description": "Your photo",            // nullable help text
        "allowMove":   true,                    // user may pan the picture in the frame
        "allowResize": true,                    // user may zoom (uniform scale)
        "allowRotate": false,                   // user may rotate
        "hidable":     true,                    // offer a "hide this slot" toggle
        "allowedDirectoryIds": ["0192...", ...],// raw designer allow-list ([] = unrestricted)
        "directories": [                         // RESOLVED upload/pick folders, with names
          { "id": "0192...", "name": "Photos" }
        ],
        "includesRoot": false,                   // true (unrestricted slots only) → the gallery
                                                 // root is a valid pick source + upload target
        "frame": { "x": 100, "y": 120, "width": 400, "height": 300 }, // designer frame in canvas px; nullable
        "defaultImageUrl": "http://.../standin.png"  // stand-in shown if left empty; nullable
      }
    ]
  }
]
```

- **Bind by `id` (UUID)**, never by `name` (names aren't unique), same as text.
- `frame` is in the variant's canvas pixel space (`width`×`height`); use it to size
  a positioning UI. It's `null` only for malformed variants.
- `allowedDirectoryIds` is the designer's raw allow-list; prefer `directories`,
  which is already resolved (an empty allow-list is expanded to every project
  folder, deleted folders are dropped) and carries display names. Use it +
  `includesRoot` to render the **upload folder choice** (§4); use the **list
  endpoint** (§3) to get the actual pickable images.

---

## 2. Export/render — body gains `images`

`POST /api/social-network-template-variants/{variantId}/export` is unchanged; it
just accepts an additional top-level `images` object alongside `inputs`. Combine
both in one request; the response is still the raw PNG.

```jsonc
{
  "inputs": { /* ...unchanged... */ },
  "images": {
    "a1b2c3d4-...": "f0e1d2c3-...",                  // SHORTHAND: gallery image id → centered, object-contain
    "b2c3d4e5-...": {                                 // OBJECT form, with placement:
      "imageId":  "f0e1d2c3-...",                     //   required — the gallery image id
      "scale":    1.4,                                //   × the contain-fit (1.0 = exactly contain); needs allowResize
      "offsetX":  20,                                 //   pan from the frame centre, canvas px; needs allowMove
      "offsetY":  -10,
      "rotation": 8                                   //   degrees; needs allowRotate
    },
    "c3d4e5f6-...": { "hide": true }                  // blank a hidable slot
  }
}
```

Server rules:

- The value is a **string** (the gallery image id) **or** an object
  `{ imageId, scale?, offsetX?, offsetY?, rotation?, hide? }`.
- **`imageId` must be an image returned by the list endpoint (§3) for that slot.**
  An id from a folder the slot doesn't allow, from another project, or unknown →
  **`400`**.
- Sending `scale` (≠1), `offsetX`/`offsetY` (≠0) or `rotation` (≠0) on a slot whose
  matching `allow*` flag is `false` → **`400`**. Omit them (or send the defaults)
  and any slot accepts the picture centered + contained.
- `scale` is a multiplier on the object-contain fit; `offsetX/Y` are a pan from the
  **frame centre** in canvas pixels; `rotation` is degrees. (Same model your
  positioning UI would compute against `frame`.)
- `hide` is honored only if the slot is `hidable: true`; ignored otherwise.
- **Omit a slot entirely → its `defaultImageUrl` stand-in renders.** Unknown
  imageInputIds are ignored.

---

## 3. NEW — list the images a slot can be filled with

```
GET /api/social-network-template-variants/{variantId}/placeholders/{imageInputId}/images
Authorization: Bearer <token>
Accept: application/json
```

Returns a plain JSON array of the gallery images in **that slot's allowed
folders** only — these are exactly the `imageId`s you may use in `images`:

```jsonc
[
  {
    "id":            "f0e1d2c3-...",      // → use as `imageId`
    "url":           "http://.../photo.jpg",
    "directoryId":   "0192...",           // null for gallery-root images
    "directoryName": "Photos",            // null for gallery-root images
    "uploadedAt":    "2026-06-05 14:10"
  }
]
```

For an **unrestricted** slot (`includesRoot: true`) the listing covers the whole
gallery, including root images (their `directoryId`/`directoryName` are null —
and may be omitted from the JSON entirely).

Show these as a thumbnail picker for the slot. Status: `200` · `401` no token ·
`403` variant not visible to this client · `404` no such placeholder on the variant.

---

## 4. NEW — upload your own image for a slot

So the end-user isn't limited to what's already in the gallery, you can upload a
new picture straight into one of the slot's allowed folders and use it immediately.

```
POST /api/social-network-template-variants/{variantId}/placeholders/{imageInputId}/images
Authorization: Bearer <token>
Content-Type: multipart/form-data
```

Form fields:

| field | required | notes |
|---|---|---|
| `file` | yes | the image part (png / jpg / webp / gif) |
| `directoryId` | see notes | **the uploader's choice of target folder** (one of the slot's `directories[].id`). Required when a restricted slot allows **several** folders. Optional when the slot allows exactly **one** folder (auto) or is **unrestricted** (`includesRoot: true` — omitting it stores the file in the gallery root). |

**Let the user choose**: render `directories` (+ a "root" option when
`includesRoot`) as a folder select next to your upload control and send the
choice as `directoryId` — that is exactly what the wboost web fill page does.

Returns the new gallery image — use its `id` as the `imageId` in `images`:

```jsonc
{ "id": "f0e1d2c3-...", "url": "http://.../uploaded.jpg", "directoryId": "0192..." }
// gallery-root upload (unrestricted slot, no directoryId sent):
{ "id": "f0e1d2c3-...", "url": "http://.../uploaded.jpg", "directoryId": null }
```

Status: `200` · `401` no token · `403` `directoryId` isn't an allowed folder for the
slot · `400` missing `file`, or missing `directoryId` for a restricted slot with
several allowed folders · `404` no such placeholder.

---

## Suggested UI flow (per image slot)

1. For each `variant.imageInputs[]`, render the slot (label `name`, help `description`).
2. Show a picker of `GET …/images` thumbnails; optionally an **upload** control that
   `POST`s a file and appends the returned image.
3. On pick, you have the `imageId`. If the slot allows it, expose move/zoom/rotate
   controls and compute `offsetX/offsetY` (px from the frame centre), `scale`
   (× contain), `rotation` (deg) against `frame`. Otherwise just send the id.
4. Build `images` and send it together with `inputs` to `…/export` for preview and
   download (same render).
5. Leave a slot untouched to keep its stand-in; offer a "hide" toggle for `hidable`
   slots (`{ "hide": true }`).

## End-to-end (shell)

```bash
# discover slots
curl -s "$API_BASE/api/projects/$PROJECT_ID/social-network-templates" \
  -H "Authorization: Bearer $TOKEN" | jq '.[].variants[].imageInputs'

# (optional) upload your own picture into an allowed folder
NEW=$(curl -sX POST "$API_BASE/api/social-network-template-variants/$VARIANT/placeholders/$SLOT/images" \
  -H "Authorization: Bearer $TOKEN" -F "file=@photo.jpg")
IMG=$(echo "$NEW" | jq -r .id)

# render with the image placed (combine with your existing text `inputs`)
curl -sX POST "$API_BASE/api/social-network-template-variants/$VARIANT/export" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d "{\"inputs\":{},\"images\":{\"$SLOT\":{\"imageId\":\"$IMG\",\"scale\":1.2,\"offsetX\":10}}}" \
  -o out.png
```
