# AI scan — provider research (Claude, OpenAI) and how to run the agent

Research date: **2026-11-12**. Every figure below was read from the cited page on that date unless a
different date is given. Nothing here is from memory; where a page could not be fetched or a claim
comes from a secondary source, it says so. Section 8 lists what could not be verified.

How to read the sources:

- **[A]** = read directly from the official page (raw Markdown of `platform.claude.com/docs/…​.md`,
  `code.claude.com/docs/…​.md`, `anthropic.com/legal/…`, `privacy.claude.com`, `support.claude.com`,
  the npm / Packagist registries).
- **[B]** = read by a research sub-agent from the official page (OpenAI developer docs, Google docs).
  `openai.com/*` (pricing, enterprise-privacy, policies) returned HTTP 403 to automated fetches, so all
  OpenAI facts come from `developers.openai.com/api/docs/*` and the DPA PDF.
- **[C]** = secondary source (benchmark aggregator, vendor leaderboard, arXiv abstract). Treat as
  indicative only.

Short version of the conclusions is in [section 7](#7-recommendation-and-confidence).

---

## 1. Claude: models, limits, features, spatial accuracy

### 1.1 Current models [A]

Source: <https://platform.claude.com/docs/en/about-claude/models/overview>,
<https://platform.claude.com/docs/en/about-claude/pricing>.

| Model | API ID | Input $/MTok | Output $/MTok | 5-min cache write | 1-h cache write | Cache read | Batch in / out | Default effort | Latency |
|---|---|---|---|---|---|---|---|---|---|
| Claude Fable 5.2 | `claude-fable-5-2` | 8 | 40 | 10 | 16 | 0.20 | 4 / 20 | high | moderate |
| Claude Opus 5.5 | `claude-opus-5-5` | 4 | 20 | 5 | 8 | 0.20 | 2 / 10 | medium | moderate |
| Claude Sonnet 5.5 | `claude-sonnet-5-5` | 2 | 10 | 2.50 | 4 | 0.10 | 1 / 5 | high | fast |
| Claude Haiku 5.5 (prompt ≤ 100k tokens) | `claude-haiku-5-5` | 0.10 | 0.50 | 0.125 | 0.20 | 0.01 | 0.05 / 0.25 | medium | fastest |
| Claude Haiku 5.5 (prompt > 100k tokens) | same | 0.50 | 2.50 | 0.625 | 1 | 0.05 | — | | |

- All four: **1M-token context, 128k max output**, text + image in, text out, adaptive thinking.
- IDs are dateless pinned snapshots; do not append dates.
- Still served and vision-capable: Fable 5.1 / 5 ($10/$50), Opus 5 / 4.8 / 4.7 / 4.6 ($5/$25),
  Sonnet 5 ($2/$10), Sonnet 4.6 ($3/$15), Haiku 4.5 ($1/$5, 200k context). No reason to pick them for a
  new build.
- The models page's own guidance: "start with Claude Opus 5.5 for most workloads. Use Claude Fable 5.2
  for demanding reasoning and long-horizon agentic work, or when your evals on Claude Opus 5.5 at higher
  effort still fall short."
- Tokenizer note: Claude 4.7+ models produce "approximately 30% more tokens for the same text" than
  Sonnet 4.6 and earlier — matters only if old token estimates are reused.

### 1.2 Vision input limits [A]

Source: <https://platform.claude.com/docs/en/build-with-claude/vision>.

| Limit | Value |
|---|---|
| Formats | JPEG, PNG, GIF, WebP (first frame only for animations) |
| Max dimensions | 8000×8000 px per image |
| Max size | 10 MB per image, base64-encoded (first-party API); request cap 128 MB |
| Images per request | 600 (100 on 200k-context models). Above **20** images in one request a stricter per-image limit applies (keep each ≤ 3000 px on both sides). Images inside `tool_result` blocks and images resent from earlier turns all count. |
| Sources | base64, URL, or Files API `file_id` |
| Metadata | not read (no EXIF, no DPI) |

**How images are tokenised and downscaled.** An image costs `ceil(w/28) × ceil(h/28)` visual tokens.
Each model has two limits; an image over either is downscaled, aspect preserved, to the largest size
that fits both:

| Tier | Models | Max long edge | Max visual tokens |
|---|---|---|---|
| High-resolution | Claude 4.7 and later (so all four current models) | 2,576 px | 4,784 |
| Standard | everything older (incl. Haiku 4.5, Sonnet 4.6) | 1,568 px | 1,568 |

After resizing, the image is padded on the bottom/right to a multiple of 28 px (padding carries no
content and does not move the origin).

Token cost at sizes relevant to this feature (computed with the reference algorithm from the
coordinates guide, see 1.6):

| Image | High-res tier: processed as → tokens | Standard tier: processed as → tokens |
|---|---|---|
| Social square 1080×1080 | unchanged → 1,521 | unchanged → 1,521 |
| Social portrait 1080×1350 | unchanged → 1,911 | 980×1225 → 1,540 |
| A4 @ 150 dpi, 1240×1754 (either orientation) | unchanged → **2,835** | 924×1307 → 1,551 |
| A4 @ 300 dpi, 2480×3508 | 1623×2296 → 4,756 | 924×1307 → 1,551 |
| Phone photo 3024×4032 | 1659×2212 → 4,740 | 952×1270 → 1,564 |
| Rendered check image 1000×1414 | unchanged → 1,836 | 924×1307 → 1,551 |
| Rendered check image 800×1131 | unchanged → 1,189 | unchanged → 1,189 |

So one input image is 1.5k–4.8k tokens: $0.006–0.019 on Opus 5.5, under $0.0005 on Haiku 5.5.

### 1.3 PDF input [A]

Source: <https://platform.claude.com/docs/en/build-with-claude/pdf-support>.

- Supported on every current model. Up to 600 pages per request (100 on < 1M-context models), within
  the 128 MB request cap; no encrypted PDFs.
- Each page is sent as **extracted text plus a page image**; typical 1,500–3,000 text tokens per page
  plus the image tokens.
- **Not usable for coordinates**: "pages are rasterized to images server-side at dimensions you don't
  control, so the returned coordinates can't be reliably mapped back onto the page. To work with
  coordinates on PDF content, rasterize the pages to images yourself."
  (<https://platform.claude.com/docs/en/build-with-claude/vision-coordinates>)
- Consequence for AI scan: if a user uploads a PDF, rasterise it in the app (Imagick/Ghostscript are
  already there) and send an image. A PDF that still has live text is better handled by parsing the PDF
  directly for text, fonts and positions — no model needed for those.

### 1.4 Structured outputs and strict tool use [A]

Source: <https://platform.claude.com/docs/en/build-with-claude/structured-outputs>.

- GA on all current models: `output_config: {format: {type: "json_schema", schema}}` for the final
  answer, `strict: true` on a tool for its inputs. Both use constrained decoding and can be combined.
- Schema subset. Supported: all basic types, `enum`, `const`, `anyOf`, `$ref`/`$defs`, `required`,
  `additionalProperties: false` (mandatory on objects), a few string `format`s, simple `pattern`.
  **Not supported: recursive schemas, `minimum`/`maximum`/`multipleOf`, `minLength`/`maxLength`, array
  limits other than `minItems` 0 or 1.**
- Hard limits per request: 20 strict tools, **24 optional parameters in total**, **16 parameters with
  union types** (`anyOf` or `["string","null"]`), plus an internal grammar-size limit ("Schema is too
  complex for compilation", 180 s compile timeout).
- Caveats: required properties are emitted before optional ones; enum values can come back with
  different capitalisation (compare case-insensitively); a `max_tokens` cut-off or a `refusal` can
  yield non-conforming output; a property that asks for step-by-step reasoning can trigger a
  `reasoning_extraction` refusal.
- Forced tool choice (`tool_choice: any|tool`) returns 400 on Fable 5.2/5.1, Opus 5.5 and Sonnet 5.5 —
  use `output_config.format` when the goal is "give me JSON" (source: the `claude-api` reference
  bundled with Claude Code, cached 2026-11-03; consistent with the pricing page listing no
  "any/tool" token count for Opus 5.5).

What this means for the two targets:

- **Calendar layout JSON** (fixed vocabulary, boxes in mm, type in pt): a good fit for
  `output_config.format`, but number ranges cannot be expressed — validate ranges in PHP. A nested
  layout with many nullable fields can hit the 16-union / 24-optional limits; prefer required fields
  with sentinel values, or a flat list of elements with a `kind` enum.
- **Template DSL**: the DSL is recursive (containers nest), and recursive schemas are unsupported.
  Keep doing what the MCP tools already do — accept the design as a string/loose object and return
  structured validation errors for the model to fix.

### 1.5 Thinking, caching, batch, rate limits [A]

- **Thinking** (<https://platform.claude.com/docs/en/about-claude/models/overview>): adaptive on all
  four; **always on** for Fable 5.2 and Opus 5.5 (cannot be disabled); depth is steered by
  `output_config.effort` (`low` … `max`). Thinking tokens are billed as output. Default effort is
  `medium` on Opus 5.5 and Haiku 5.5, `high` on Fable 5.2 and Sonnet 5.5 — set it explicitly.
- **Prompt caching** (<https://platform.claude.com/docs/en/build-with-claude/prompt-caching>): 5-minute
  and 1-hour TTL; writes cost 1.25× / 2× input; reads cost 0.025× (Fable 5.2), 0.05× (Opus 5.5, Sonnet
  5.5), 0.1× (Haiku 5.5). Minimum cacheable prefix is **512 tokens** on all four current models. Up to
  4 breakpoints; images are part of the cacheable prefix. Cached reads do not count toward the
  input-tokens-per-minute limit.
- **Batch API** (<https://platform.claude.com/docs/en/build-with-claude/batch-processing>): 50% off
  input and output; "most batches completing within 1 hour", hard limit 24 h; results kept 29 days; up
  to 100,000 requests / 256 MB per batch; works with structured outputs and images. Not ZDR-eligible.
  Only useful for the single-call variant when the user can wait.
- **Rate limits** (<https://platform.claude.com/docs/en/api/rate-limits>). Tiers are now named. Monthly
  spend caps: Start $500, Build $1,000, Scale $200,000, Custom none. New organisations may begin in an
  "Evaluation" tier with lower, unpublished limits.

  | Model | Start: RPM / ITPM / OTPM | Build | Scale |
  |---|---|---|---|
  | Fable 5.2 | 4,000 / 500k / 100k | 10,000 / 1.5M / 300k | 13,000 / 4M / 800k |
  | Opus 5.5, Sonnet 5.5, Haiku 5.5 | 4,000 / 2M / 400k | 10,000 / 5M / 1M | 13,000 / 10M / 2M |

  Even the Start tier is far above what this feature needs (one typical agent scan ≈ 150k input tokens
  in total, most of it cache reads that do not count).

### 1.6 Spatial / coordinate accuracy — what Anthropic publishes [A]

Source: <https://platform.claude.com/docs/en/build-with-claude/vision-coordinates> (a dedicated guide),
plus the Limitations section of the vision page. This is the most relevant official material for the
feature, so the key statements are quoted:

1. **Ask for absolute pixels, not normalised coordinates.** "Claude works best with absolute pixel
   coordinates. Ask for them explicitly in your prompt… Claude does not work well when you ask for
   normalized coordinates, for example: 'Return bounding box coordinates between 0 and 1000.' Always ask
   for pixel coordinates and normalize in your own code." (This is the opposite of Gemini, see 2.5.)
2. **Coordinates refer to the image after Claude's resize.** Origin top-left, x right, y down. If the
   image was downscaled server-side, the returned pixels are in the downscaled image.
3. **Pre-resize yourself.** "The most reliable approach is to resize your image yourself before
   uploading, so the image you have is exactly the image Claude processes." The guide ships a reference
   implementation (including PHP) of the exact resize rule. "The token limit can also trigger a resize
   when neither side exceeds the edge limit. Overlooking this is the most common cause of misaligned
   coordinates." Normalise by the resized size, never by the padded size.
4. **Make silent resizing an error.** An image block accepts
   `"transformations": {"oversized_image": "error"}`; the request is then rejected with the largest size
   that fits instead of being downscaled. Use it on every image whose coordinates matter.
5. **On 4.7+ models coordinates are 1:1.** "Pointing and bounding-box coordinates returned by the model
   are 1:1 with actual image pixels on Claude Opus 4.7 [and later], so no scale-factor conversion is
   required." (<https://platform.claude.com/docs/en/models/opus-5-5/migration-guide>)
6. **Crop for small things.** "Small elements lose precision when an image is downscaled: for fine
   targets, crop the region of interest and send the crop (offset returned coordinates by the crop
   origin), or use a high-resolution-tier model."
7. **The honest limitation.** "Claude's coordinate and localization outputs are approximate… verify
   outputs before relying on them." Also: may be inaccurate on rotated or very small (< 200 px) images;
   counting is approximate.
8. **Zoom tools help.** "Testing has shown consistent uplift on image evaluations when Claude is able
   to 'zoom' in on relevant regions of an image" — crop-tool recipe:
   <https://platform.claude.com/cookbook/multimodal-crop-tool>
   (<https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/claude-prompting-best-practices>).
   For Opus 5.5 specifically: it "reads charts, diagrams, and screenshots considerably more precisely
   than Claude Opus 5 without tools… For the densest inputs, two things still add accuracy.
   Higher-resolution images help… So do image-processing tools: run the model as an agent with access to
   a container that holds the raw images and has libraries such as PIL and OpenCV installed, so that it
   can crop, zoom, measure, and verify its work. If a container is too much overhead, a cropping tool
   alone still helps… The model uses these tools more effectively at higher effort levels."
   (<https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/prompting-claude-opus-5-5>)
9. **Prompt layout.** Images before text; label several images ("Image 1:", "Image 2:").

Anthropic publishes **no numeric accuracy figure** (no IoU, no pixel error) for bounding boxes. The only
numbers are third-party / benchmark ones in section 2.5.

Tricks, ranked by how well they are supported:

| Trick | Evidence | Verdict for AI scan |
|---|---|---|
| Pre-resize to the model's native size + `oversized_image: "error"` | Official, explicit | Do it always. Free. |
| Absolute pixel coordinates, converted to mm in PHP | Official, explicit | Do it always. The model should never do the px→mm arithmetic. |
| Crop / zoom tool (model asks for a region at higher zoom) | Official ("consistent uplift"), plus GUI-grounding papers reporting +7 to +17 points on open models [C] | Add as an MCP tool: `scan_crop(x,y,w,h)` returning the crop at full resolution. Cheap. |
| Render → compare loop (the app's own renderer) | The team's own successful manual runs; matches the "verify its work" advice | The core of the template path. |
| Overlay: render the candidate layout's boxes on top of the source, or a side-by-side / difference image | Not documented by Anthropic; logical extension of "verify" | Worth testing — gives the model a direct visual error signal. |
| Grid / ruler overlay on the source image | Only GPT-4V-era papers (Scaffold, 2024) [C]; no evidence on current models | Low priority; test only if box error stays high. |
| Set-of-Mark (number pre-segmented regions, model picks IDs) | 2023–24 papers on GPT-4V [C]; needs a segmenter | Only relevant with a classical pre-pass (2.6). |
| Normalised 0–1000 coordinates | Officially **discouraged** for Claude | Do not use with Claude. |

### 1.7 Which Claude model for what

| Job | Sensible choice | Why |
|---|---|---|
| (a) Precise layout extraction, agent loop with render/compare | **Opus 5.5** at `high` effort as the default; **Fable 5.2** as the "quality" candidate to A/B | High-res tier, described by the docs as the vision-heavy / computer-use model, half the price of Fable. Fable 5.2 is what the docs suggest "when your evals on Opus 5.5 at higher effort still fall short", but it needs 30-day retention (no ZDR) and has the lowest rate limits. |
| (a′) Same, cost-sensitive | **Sonnet 5.5** | Same resolution tier and limits at half of Opus. Whether box accuracy holds is an eval question. |
| (b) Cheap sub-steps: classify the upload (calendar page vs. poster, photo vs. flat export), detect language, name colours, transcribe text from crops, judge "does render match source: yes/no + worst region" | **Haiku 5.5** | $0.10/$0.50, same high-res tier, structured outputs supported. A crop transcription costs ~$0.0002. |
| Single structured-output call for calendars | **Opus 5.5** or **Sonnet 5.5**, optionally via Batch | One call, ~$0.07–0.14, see section 6. |

Which model the manual Claude Code runs used should be written down next to this document; the model
is probably a larger factor in "it worked well" than the harness (see 3.3).

---

## 2. OpenAI, comparison, benchmarks, Gemini reference

### 2.1 Current OpenAI models [B]

Sources: <https://developers.openai.com/api/docs/models>, <https://developers.openai.com/api/docs/pricing>
and the per-model pages under `/api/docs/models/…`.

| Model ID | Role | Input / cached / output per MTok | Batch & Flex |
|---|---|---|---|
| `gpt-6-astra` | most capable | $10 / $1 / $50 | $5 / $0.50 / $25 |
| `gpt-6.1-sol` | near-Astra, cheaper | $2 / $0.10 / $10 | $1 / $0.05 / $5 |
| `gpt-6.1-terra` | fast; described as "visual reasoning, precise computer use" | $1 / $0.05 / $5 | $0.50 / $0.025 / $2.50 |
| `gpt-6-luna` | cheapest, high volume | $0.10 / $0.01 / $0.50 | $0.05 / $0.005 / $0.25 |

- All: text + image in, text out, 1,050,000-token context (922k max input), 128k max output, reasoning
  effort `low`…`max` (Terra and Luna also `none`), structured outputs, Batch (50% off, 24 h).
- Over 272k input tokens the whole request costs 2× input / 1.5× output.
- Responses API is recommended for new work; Astra and 6.1 Sol *require* it for tool calling. The
  Assistants API was shut down on 2026-08-26.

### 2.2 OpenAI vision limits [B]

Source: <https://developers.openai.com/api/docs/guides/images-vision>.

- PNG, JPEG, WebP, non-animated GIF; URL, base64 or file ID. 512 MB total payload, 1,500 images per
  request.
- `detail`: `low` (fits 512×512), `high` (budget of 2,500 patches), `original` (original size, up to
  65,535 px per side; images over 30,000 patches are rejected), `auto` (default).
- Tokens (patch models): `patches = ceil(w/32) × ceil(h/32)`, scaled down if over the budget;
  `tokens = ceil(patches × 1.2)` for gpt-6-astra. The multiplier for 6.1 Sol / Terra / Luna is **not
  stated** on the page.

  | Image | `original` / `auto` | `high` | `low` |
  |---|---|---|---|
  | 1024×1024 | 1,229 tokens | 1,229 | 308 |
  | 1240×1754 (A4 @ 150 dpi) | 2,574 | 2,574 | ≈ 231 |
  | 2000×2000 | 4,763 | 3,000 (downscaled to 1600×1600) | 308 |

  Practically the same token cost per image as Claude's high-resolution tier (28 px vs 32 px patches,
  × 1.2).
- PDF input: text plus page images, 50 MB per file and per request.

### 2.3 OpenAI structured outputs, SDKs, caching, limits [B]

- **Structured outputs** (<https://developers.openai.com/api/docs/guides/structured-outputs>): strict
  `json_schema` on all current models. Compared with Claude it is **more permissive**: recursion via
  `$ref` is allowed, `minimum`/`maximum`/`multipleOf`/`minItems`/`maxItems`/`pattern` are supported, up
  to 10 nesting levels and 5,000 properties. Every field must be `required` (optional = nullable union).
- **PHP**: there is **no official OpenAI PHP SDK**. Official SDKs are JS/TS, Python, .NET, Java, Go,
  Ruby; `openai-php/client` is listed as a community library ("OpenAI does not verify the correctness or
  security") (<https://developers.openai.com/api/docs/libraries>).
- **Agents SDK**: Python (`openai-agents`, 3.10+) and TypeScript (`@openai/agents`, Node 22+).
- **Caching**: automatic, 1,024-token minimum, reads 0.1× (0.05× on 6.1 Sol/Terra); GPT-5.6+ charge
  1.25× for writes (30-minute TTL).
- **Rate limits** (tiers Build $5 / Launch $100 / Grow $500 purchased): Astra, 6.1 Sol, 6.1 Terra
  5,000 RPM / 1M TPM at Build — not a constraint here.

### 2.4 Honest comparison for this task

| Dimension | Claude (Opus 5.5 / Fable 5.2 / Sonnet 5.5) | OpenAI (Astra / 6.1 Sol / 6.1 Terra) | Edge |
|---|---|---|---|
| Price per scan at the mid tier | Sonnet 5.5 $2/$10, cache read $0.10 | 6.1 Sol $2/$10, cache read $0.10 | Tie (identical) |
| Price at the top | Fable 5.2 $8/$40; Opus 5.5 $4/$20 | Astra $10/$50 | Claude |
| Cheap tier | Haiku 5.5 $0.10/$0.50 | Luna $0.10/$0.50 | Tie |
| Native image resolution | 2,576 px long edge, 4,784 tokens; silent downscale above, can be turned into an error | `original` detail keeps the full image (up to 30,000 patches ≈ 5,500×5,500) | OpenAI can take a larger image; for an A4 scan at 150–200 dpi it makes no difference |
| Official coordinate guidance | A full guide: pixel coordinates, resize algorithm, error-on-resize flag, crop advice | Two sentences: resize before sending, map back; "The model struggles with tasks requiring precise spatial localization" | Claude (documentation, not measured accuracy) |
| Strict JSON schema | No recursion, no numeric ranges, 24-optional / 16-union caps | Recursion, ranges, 5,000 properties | OpenAI, for the single-call variant with a rich schema |
| Official PHP SDK | Yes (`anthropic-ai/sdk` 1.8.0, tool runner, structured outputs) | No (community `openai-php/client`) | Claude |
| Existing integration | App already speaks MCP; the team's manual runs used Claude Code and worked | Would need the tool surface re-tested and the prompts re-tuned | Claude |
| EU data residency on the first-party API | **None** (`inference_geo` is `global` or `us`) — EU only via Bedrock / Vertex | `eu.api.openai.com` exists, but only with an approved ZDR / modified-retention amendment, +10% | OpenAI on paper; neither is self-serve EU |
| Published grounding numbers | see 2.5 | see 2.5 | No clear winner |

Conclusion: nothing public shows OpenAI being *clearly better* for any part of this problem. The one
concrete OpenAI advantage is the more expressive strict schema; the one concrete Claude advantage
(besides the owner's preference and the proven manual runs) is tooling fit for a PHP app. Stay on
Claude; keep the scanner's model call behind one interface so an OpenAI model can be A/B-tested on the
app's own evaluation set later.

### 2.5 Public benchmarks — what exists and how much it is worth

No public benchmark scores current Claude or GPT models on "raster poster / calendar page → editable
layout", and none was found that tests Claude Fable 5.2 or Opus 5.5 at all. The nearest evidence:

**GUI grounding — ScreenSpot-Pro** (click the right UI element on high-resolution professional
screenshots) [C]. Aggregators: <https://benchlm.ai/benchmarks/screenspot-pro> (updated 2026-11-11),
<https://benchmarklist.com/benchmarks/screenspot_pro/>.

| Model | Score | Reported by |
|---|---|---|
| GPT-6 Astra | 92.7% | vendor launch post (2026-09) |
| Claude Mythos Preview | 93.0% with tools / 79.3% without | vendor system card (2026-06) |
| Claude Opus 4.8 | ~88–89.5% with tools / 82.4% without | vendor |
| Claude Fable 5 | 87.3% | vendor launch post |
| GPT-5.4 | 85.4% (one aggregator) vs 67.4% (the other) | third-party tables |
| Gemini 3.1 Pro | 84.4% vs 68.1% | third-party tables |
| Claude Opus 4.5 | 45.7% | third-party table |

Caveats: vendor-reported, the two aggregators disagree on older models, tool setups (crop/zoom) are not
stated per row and are worth 7–14 points, and UI screenshots are not graphic design. What it does show:
(1) both vendors' current models localise far better than models a year older; (2) **crop/zoom tools
add roughly ten points** — consistent with Anthropic's own advice.

**Object detection** [C]: Roboflow, 2026-08-04, on a 20-dataset subset of RF100-VL — "GPT-6 Astra leads
overall, followed by Gemini 3.8 Flash, Qwen3.8-Max, and Claude Fable 5.1"
(<https://roboflow.com/blog/vision-language-model-benchmarks>; numbers only in a video). Natural
photos, not flat designs; the one independent current-generation comparison found, and it ranks Claude
last of four.

**Text spotting (text + box)** [C]: OCRBench v2 (<https://99franklin.github.io/ocrbench_v2/>) — Gemini
3 Pro Preview 80.8; GPT-5 6.5; Claude Opus 4.6 7.4 (2026-03 round). Old GPT and Claude models were very
weak at *tight text boxes* while Gemini was strong. Whether GPT-6 / Fable 5.x / Opus 5.5 closed that gap
is unmeasured. This is the single most relevant warning for the feature: do not assume text boxes from
the model are tight — verify by rendering.

**Design2Code / UI-to-code**: no current numbers could be confirmed; the original paper tested
GPT-4o-era models.

**Raster design → layers** (closest task, none benchmarks Claude or GPT) [C]: LayerD (CyberAgent, ICCV
2025, <https://arxiv.org/abs/2509.25134>, code available) — iterative matting + inpainting on the
Crello dataset; Qwen-Image-Layered (<https://arxiv.org/abs/2512.15603>) — image → RGBA layers;
CreatiParser (<https://arxiv.org/abs/2604.19632>) — VLM parses text into a rendering protocol,
diffusion produces background and sticker layers. These are research systems that solve the part a VLM
cannot: **recovering the pixels of an image layer hidden behind text** (inpainting). Worth knowing they
exist; not something to build into v1.

**OCR on Czech** [C]: the only Czech-specific numbers found are from CHURRO (EMNLP 2025,
<https://arxiv.org/abs/2509.19768>) on *historical printed* Czech: Gemini 2.5 Pro 95.6, Azure OCR 91.7,
Claude Opus 4.1 91.5, GPT-5 86.9, Claude Sonnet 4 86.6, Mistral OCR 86.5 (normalised Levenshtein
similarity). All models are 2+ generations old, and historical print is harder than a modern poster.
OmniDocBench and OCRBench v2 are English/Chinese only. **Czech diacritics accuracy on modern display
typography is unmeasured for every current model** — it has to be measured on the app's own data. The
task is favourable (large, clean, high-contrast text), and the render/compare loop catches a wrong
háček if the model is asked to compare text specifically.

### 2.6 Gemini (reference point only) and classical building blocks

- **Gemini has documented native box output** [B]
  (<https://ai.google.dev/gemini-api/docs/image-understanding>): "The 2D bounding box of the item as
  `[ymin, xmin, ymax, xmax]` normalized to 0-1000", plus segmentation (`box_2d`, `mask` as a base64 PNG
  probability map, `label`). It is the only one of the three vendors that documents detection as a
  feature, and it leads the text-spotting benchmark above. If box accuracy from Claude turns out to be
  the bottleneck, a Gemini (or classical) *pre-pass that proposes boxes* is the obvious experiment — not
  a reason to switch the agent.
- **Classical pieces that need no LLM** (licences from memory unless noted — verify before use):
  OCR with word boxes and Czech: Tesseract `ces` (Apache-2.0), PaddleOCR PP-OCRv5 Latin model (lists
  `cs`; Apache-2.0)
  (<https://www.paddleocr.ai/latest/en/version3.x/algorithm/PP-OCRv5/PP-OCRv5_multi_languages.html>).
  Dominant colours: k-means on the image, or sample pixels inside the OCR glyph mask for exact text
  colour — **colour should always be measured from pixels, never asked from the model**. Segmentation:
  SAM 2 (Apache-2.0). Layout detectors (DocLayout-YOLO is AGPL-3.0 — a problem for SaaS) are trained on
  documents, not posters. Font identification: open models exist (e.g. a DINOv2 Google-Fonts
  classifier, <https://arxiv.org/abs/2602.13889>), but for this app the candidate set is the project's
  own font list, so "render the text in each candidate font and compare" is more reliable than any
  classifier.

A hybrid worth prototyping after the pure agent loop: Imagick-measured colours + (optionally) Tesseract
word boxes handed to the model as *hints* in the first message. It costs nothing per scan and removes
two things models are approximate at.

---

## 3. How to run the agent loop

Context that shapes this section: every successful manual run so far was **Claude Code (the CLI)
driving the app's own MCP server** (`/_mcp`, streamable HTTP, authenticated by a `wb_mcp_…` personal
token or the app's OAuth bearer; render tools such as `preview_design` and `render_calendar_page`
return a JSON summary plus a base64 image block). The app image has Node v24.22.1. The question is
whether that exact setup can run in production, and what is optimal.

### 3.1 The five options at a glance

| | Who runs the loop | Runtime in the WBoost image | Tools reach the app by | Works with subscription login for local dev | Status |
|---|---|---|---|---|---|
| **A. Claude Agent SDK** (TypeScript) | Claude Code harness, as a library | Node ≥ 18.14 + bundled native binary | HTTP MCP to `/_mcp`, or in-process SDK tools | **Yes** (same binary, same auth chain) | GA |
| **B. `claude -p`** (headless CLI) spawned from PHP | Same harness, as a subprocess | native `claude` binary | HTTP MCP (`--mcp-config`) | **Yes** (not with `--bare`) | GA |
| **C. Messages API, hand-written loop or SDK tool runner** (PHP or node) | Your code | nothing extra (PHP SDK) | direct PHP service calls | No — API key only | GA (tool runner: beta) |
| **D. Messages API + MCP connector** | Anthropic calls your MCP server | nothing extra | Anthropic → public `/_mcp` | No | beta |
| **E. Claude Managed Agents** | Anthropic | nothing extra | custom-tool events or MCP via vault | No | beta |

### 3.2 (a) Running the Claude Code harness in production with an API key

**Is it supported and licensed for a SaaS serving its own customers?** [A]

- **Agent SDK: yes, explicitly.** The overview is titled "Build production AI agents with Claude Code
  as a library" and states: "Use of the Claude Agent SDK is governed by Anthropic's Commercial Terms of
  Service, including when you use it to power products and services that you make available to your own
  customers and end users" (<https://code.claude.com/docs/en/agent-sdk/overview>). Condition: API-key
  auth — "Unless previously approved, Anthropic does not allow third party developers to offer claude.ai
  login or rate limits for their products, including agents built on the Claude Agent SDK."
- **Branding** (same page): allowed — "Claude Agent", "{YourAgentName} Powered by Claude"; **not
  permitted** — "Claude Code" or "Claude Code Agent", or visuals that mimic Claude Code. "Your product
  should maintain its own branding." So the feature is "AI sken"; a "Powered by Claude" note is optional.
- **Raw `claude -p` binary in the product: ambiguous.** The legal page has a section "Can customers
  offer Claude Code in their products?": running Claude Code in your products "requires agreeing to our
  Commercial Terms" and two conditions — the binary must not be modified, and "Customers may not pay
  for, resell, or intermediate Claude usage on their end users' behalf. Each end user must authenticate
  with their own Anthropic API key, Claude subscription plan credentials, or 3P inference provider
  credential" (<https://code.claude.com/docs/en/legal-and-compliance>). That section is written for
  platforms that put Claude Code *in front of their users* (hosted sandboxes). A backend job where no end
  user operates Claude Code, paid by WBoost's own API key, is closer to the Agent SDK case — and the SDK
  overview itself says: "To drive the same agent loop from a language other than Python or TypeScript,
  run the CLI as a subprocess with the `-p` flag". The two statements pull in different directions. The
  SDK wording is the unambiguous permission, so **production should go through the Agent SDK package**
  (which spawns the same binary) rather than a hand-installed `claude`, unless Anthropic confirms
  otherwise.
- npm metadata: `@anthropic-ai/claude-agent-sdk` 0.3.322, licence field "SEE LICENSE IN README.md"
  (proprietary, Commercial Terms — not open source), engines `node >= 18.14.0`
  (<https://registry.npmjs.org/@anthropic-ai/claude-agent-sdk/latest>).

**Runtime requirements** [A] (<https://code.claude.com/docs/en/agent-sdk/hosting>):

- Node.js 18.14+ (TypeScript SDK) or Python 3.10+. "Both the TypeScript and Python SDKs bundle a native
  Claude Code binary for most installs, and the spawned CLI needs no separate Node.js install." The
  binary arrives as a platform-specific optional dependency (`…-linux-x64`, `…-linux-x64-musl`,
  `…-linux-arm64`, …); the `linux-x64` package is **≈ 342 MB unpacked** (npm registry `unpackedSize`
  341,914,717), the JS wrapper ≈ 5 MB. `npm install --omit=optional` leaves the binary out. Peer
  dependencies: `zod ^4`, `@anthropic-ai/sdk`, `@modelcontextprotocol/sdk`.
- "The bundled binary is pinned to the SDK package version, so updating the SDK is how you update the
  CLI."
- Architecture: "When your code calls `query()`, the SDK spawns a separate `claude` CLI process and
  talks to it over stdio." One session = one subprocess.
- Resources: "1 GiB RAM, 5 GiB disk, and 1 CPU per agent is a reasonable starting point… Memory usage
  grows with session length and tool activity." For a short tool-only session expect less, but measure
  peak RSS. On one Hetzner box shared with FrankenPHP, Postgres and Gotenberg this means **capping
  concurrent scans** (one or two `job_bus` consumers), not a new server.
- Network: outbound HTTPS to `api.anthropic.com` only (plus the MCP URL).
- Cold start: no figure is published. The docs offer `startup()` / `prewarm()` to pre-spawn
  subprocesses for long-lived servers; for a background job a few seconds of spawn + MCP connect is
  irrelevant next to a 1–3 minute scan. `claude -p --mcp-config` waits up to `MCP_TIMEOUT` (30 s
  default) for servers to connect before the first turn.
- Known limitation, stated by the docs: "No top-level session timeout" — bound the run with `maxTurns`
  and your own timer.

**Restricting it to ONLY the app's tools** [A]
(<https://code.claude.com/docs/en/agent-sdk/custom-tools>,
<https://code.claude.com/docs/en/agent-sdk/permissions>,
<https://code.claude.com/docs/en/agent-sdk/claude-code-features>,
<https://code.claude.com/docs/en/agent-sdk/typescript>):

| Goal | Option |
|---|---|
| No Bash / Read / Write / Web / subagent tools at all | `tools: []` — "All built-ins are removed. Claude can only use your MCP tools." (CLI: `--tools ""`) |
| Only the app's MCP server, nothing from `.mcp.json`, plugins or claude.ai connectors | `mcpServers: { wboost: {...} }` + `strictMcpConfig: true` (CLI: `--mcp-config … --strict-mcp-config`) |
| Pre-approve the app's tools, deny everything else without prompting | `allowedTools: ["mcp__wboost__*"]` (or the explicit list) + `permissionMode: "dontAsk"` ("Any call that would otherwise prompt is denied"). Do **not** use `bypassPermissions`. |
| No `CLAUDE.md`, user/project settings, hooks, skills | `settingSources: []` |
| No global config or memory leaking in | `env: { ...process.env, CLAUDE_CONFIG_DIR: "<dedicated dir>", CLAUDE_CODE_DISABLE_AUTO_MEMORY: "1" }` — `~/.claude.json` and auto memory "are read regardless" of `settingSources` otherwise |
| No transcript left on disk | `persistSession: false` (CLI: `--no-session-persistence`) |
| Own instructions instead of the coding-agent prompt | `systemPrompt: "<scanner prompt>"` (see 3.3 — the SDK default is a *minimal* prompt, the CLI default is the full Claude Code prompt) |
| Clean working directory | `cwd` set to an empty per-job temp dir |

`--bare` (CLI) does most of the isolation in one flag and "is the recommended mode for scripted and SDK
calls", **but "in bare mode, Claude Code never reads OAuth credentials"** — it needs an API key, so it
cannot be used for subscription-backed local runs. Use the explicit options above so the same
configuration works on both backends.

Residual caveat: with `tools: []` the harness still has no filesystem access, so a user-supplied image
containing text like "ignore your instructions" can at worst make the model call the app's own tools —
which are already scoped to one user/project by the MCP token. Mint a token scoped to the scan's
project.

**Usage and cost per run** [A] (<https://code.claude.com/docs/en/agent-sdk/cost-tracking>): the final
`result` message carries `total_cost_usd`, `usage`, `modelUsage` (per model: tokens and cost),
`num_turns`, `duration_ms`, `duration_api_ms`, `session_id`, `permission_denials`, `structured_output`,
and a `subtype` (`success`, `error_max_turns`, `error_max_budget_usd`, `error_during_execution`,
`error_max_structured_output_retries`). Warning from the docs: `total_cost_usd` is "a client-side
estimate, not authoritative billing data… Don't bill end users or trigger financial decisions from these
fields." Store the token counts and price them in PHP if scans are ever metered. `claude -p
--output-format json` returns the same fields.

**Caps, timeouts, cancellation, concurrency** [A]:

- `maxTurns` (CLI `--max-turns`): hard stop, result `error_max_turns`.
- `maxBudgetUsd` (CLI `--max-budget-usd`): checked after each response against the client-side
  estimate; "spend can pass the cap by up to the cost of that one response" — leave headroom.
- `taskBudget: { total }` (alpha): tells the model its remaining token budget so it paces itself.
- Wall-clock timeout: none built in. Use `abortController` (TypeScript option) with a timer; from PHP,
  `SIGTERM` to the process is the documented way to stop a `-p` run.
- Cancellation: `abortController.abort()`; `interrupt()` in streaming-input mode.
- Concurrency: one subprocess per scan; limit by the number of consumers on `job_bus`.

**Progress events for a UI** [A] (<https://code.claude.com/docs/en/agent-sdk/streaming-output>): the
stream yields a `system`/`init` message (with MCP server status), an `assistant` message per completed
content block — including each `tool_use` (tool name + input) — the tool results, and the final
`result`. That is enough for "Building layout… rendering check 2 of 4…" without token-level streaming.
`includePartialMessages: true` adds raw API deltas (CLI: `--output-format stream-json --verbose
--include-partial-messages`). The sidecar can write each event as one JSON line to stdout; the PHP job
handler reads lines and flushes progress to the scan row (the `job_bus` exists precisely so that such
flushes are visible while the handler runs).

**How images get in** [A]:

1. **In the prompt.** A user message may contain
   `{type: "image", source: {type: "base64", media_type, data}}`, but only in **streaming input mode**
   (an async generator of messages); single-string `query()` "does not support direct image
   attachments" (<https://code.claude.com/docs/en/agent-sdk/streaming-vs-single-mode>). No Read tool
   needed.
2. **From an MCP tool result.** "A PNG, JPEG, GIF, or WebP image reaches Claude as visual input"; the
   block is `{type: "image", data: <base64>, mimeType}`
   (<https://code.claude.com/docs/en/agent-sdk/custom-tools>). This is how the app's render tools already
   feed the model, and it works identically for remote HTTP MCP servers (the block shape is MCP's
   `CallToolResult`).
3. **Read tool on a file path** — works in Claude Code, but would require enabling a filesystem tool;
   not wanted here.

**Recommended: add one MCP tool, `scan_source(scanId)`**, that returns the uploaded image (already
pre-resized per 1.6) plus its pixel size, physical size in mm and measured palette. Then the prompt is
plain text ("scan 123: reproduce it"), the image travels the same path as the renders, and the same
invocation works for the SDK, for `claude -p`, and for a human debugging in interactive Claude Code. One
limit to respect: a text MCP result over 25,000 tokens is spilled to a file (`MAX_MCP_OUTPUT_TOKENS`) —
keep JSON summaries small; image results are exempt from that rule.

Structured final answer: `outputFormat: {type: "json_schema", schema}` → `structured_output` on the
result, with automatic re-prompting on validation failure
(<https://code.claude.com/docs/en/agent-sdk/structured-outputs>). For the template path the real output
is the saved draft in the app, so the final JSON only needs `{status, templateId, notes, confidence}`.

### 3.3 (b) What the harness adds over a hand-written loop — and what is cheap to reproduce

| What Claude Code / the SDK does | Likely contribution to the good manual results | Reproduce in a plain loop? |
|---|---|---|
| **The model and effort** the session ran on (Claude Code defaults to a top model at high/xhigh effort, adaptive thinking on) | **Large.** Probably the main factor. | Trivial: one request parameter. |
| **Project context**: `CLAUDE.md`, `docs/mcp/*`, the architecture docs, skills — loaded from the repo | **Large.** The manual sessions knew the DSL, the fonts rules, the container contract. | It is *not* part of the harness in production (`settingSources: []`). It must be distilled into the scanner's system prompt and the MCP tool descriptions **in every option**, including the SDK. This is the real porting work. |
| The **MCP tool surface itself** — validated build operations with structured errors, render-to-image | **Large.** This is the app's work, not the harness's. | Already exists; identical for every option. |
| **Tool-result images** passed to the model as vision input | Essential | Cheap: a `tool_result` content array may hold `image` blocks in the Messages API (the vision docs explicitly count "images nested inside `tool_result` content"). ~20 lines. |
| **Agent loop mechanics**: call → execute tools (in parallel) → append results → repeat; preserve thinking blocks verbatim | Essential but mechanical | Cheap: ~150 lines in PHP, or the official SDK tool runner (beta, available in the PHP SDK). Must append the full `content` (thinking blocks included) unchanged. |
| **Automatic prompt caching** of system prompt, tools and history | Cost only (2× cheaper, see section 6) | Cheap: one `cache_control` on the request. |
| **Retries / backoff** on 429, 529, network errors | Reliability | Cheap: the SDKs retry twice by default. |
| **Structured final output with validation + re-prompt** | Convenience | Cheap: `output_config.format`, or validate in PHP and send the errors back. |
| **Claude Code system prompt** (the `claude_code` preset) | Small or negative for this job — it is written for a coding agent with a human watching. The SDK default is already a *minimal* prompt; "if you're migrating from the CLI and want matching behavior, set the `claude_code` preset" (<https://code.claude.com/docs/en/agent-sdk/modifying-system-prompts>). | Not needed. If the first SDK runs are worse than the manual ones, test `preset: "claude_code"` + `append` before anything else. |
| **Automatic compaction** near the context limit | None for 5–10 turns (≈ 30–60k tokens of 1M) | Not needed. |
| **Todo / task tracking, subagents, plan mode** | Small for a 5–10 step task | Skip. |
| **Tool search** (MCP tool schemas deferred, loaded on demand — on by default in the harness) | Neutral; saves context when there are many tools | Not needed with a short, fixed tool list. Note the scanner should expose only the handful of tools it needs, not the whole MCP server. |
| **MCP client** (connect, list tools, call, auth header) | Plumbing | Unnecessary in PHP — the job can call the same PHP services the MCP tools wrap, in-process. In node it would need `@modelcontextprotocol/sdk`. |
| **Interactive permission prompts, a human steering mid-run** | Present in the manual runs, **absent in production in every option** | Cannot be reproduced; replace with hard caps and a "needs review" outcome. |

Reading of this table: nothing the harness does for this workload is expensive to reproduce. What made
the manual runs good is the model, the project knowledge and the app's tools. The harness's value in
production is therefore *not* quality — it is **(1) zero new loop code, (2) identical behaviour to the
setup that is already proven, and (3) the subscription/API-key switch in 3.6**.

### 3.4 (c) Connecting to the app's tools

| | Remote MCP over HTTP to the app's `/_mcp` | In-process SDK MCP tools (`tool()` + `createSdkMcpServer`) | Messages API "MCP connector" |
|---|---|---|---|
| Who calls the app | The Claude Code subprocess on the same box (or the developer's laptop) | Your node code, which must then call the app anyway (HTTP) because the tools live in PHP | Anthropic's servers |
| Network | localhost / internal Docker network; nothing new exposed | same | "The server must be publicly exposed through HTTP" — prod `/_mcp` already is; a dev machine is not |
| Auth | `headers: { Authorization: "Bearer <token>" }` in the server config. "The SDK doesn't open a browser or run an interactive OAuth flow… complete the OAuth flow in your own application and pass the resulting access token in the server's `headers`" (<https://code.claude.com/docs/en/agent-sdk/mcp>). The PHP handler mints a short-lived token for the scan's user/project and passes it to the sidecar via env. | whatever your node code uses | `authorization_token` in the request — the token is sent to Anthropic |
| Code to write | none (tools exist) | a second definition of every tool in TypeScript | none |
| Parity with manual runs | exact | drift risk | tool behaviour same, loop differs |
| Status / data | GA; ZDR-eligible path | GA | **beta** (`mcp-client-2026-09-15`), **not ZDR-eligible**, tool calls only (<https://platform.claude.com/docs/en/agents-and-tools/mcp-connector>) |
| Overhead | one local HTTP round-trip per tool call (negligible next to a render) | none extra | Anthropic ↔ Hetzner round-trip per tool call |

Auth detail: `/_mcp` accepts the app's `wb_mcp_…` personal tokens and OAuth 2.1 bearers from the app's
own authorization server. The `client_credentials` JWTs belong to the `/api` firewall; whether `/_mcp`
accepts them as-is depends on `McpOAuthAuthenticator` and the scope checks — to be checked when
implementing. The simplest robust path is a server-minted, short-lived MCP token bound to the scan's
user, with only the scopes the scan tools need.

For a job on the same box: **remote HTTP MCP to the existing endpoint** wins on every row except raw
overhead, which does not matter. In-process SDK tools only make sense for node-native helpers (none are
needed). The MCP connector is the only way to get "MCP tools without any loop code in pure PHP", at the
price of beta status, retention, a public endpoint and no local-dev story.

For option C (PHP loop) the question disappears: tools are plain PHP calls to the services behind the
MCP tools; the MCP tool classes' schemas/descriptions can be reused as the tool definitions.

### 3.5 (d) Claude Managed Agents

[A] <https://platform.claude.com/docs/en/managed-agents/overview>,
<https://platform.claude.com/docs/en/managed-agents/tools>,
<https://platform.claude.com/docs/en/about-claude/pricing>.

- What it is: "Pre-built, configurable agent harness that runs in managed infrastructure. Best for
  long-running tasks and asynchronous work." Beta (`managed-agents-2026-04-01`). You create a versioned
  agent, start a session per run, and consume an event stream.
- Tools in the app: supported two ways. *Custom tools* — "Claude does not run a custom tool itself. It
  emits a structured request, your code runs the operation, and you return the result to the session" —
  the PHP job must hold the session's event stream and answer each call. Or *MCP servers* declared on
  the agent with credentials from a vault — Anthropic calls the public `/_mcp`.
- Cost: tokens at normal model rates (caching applies; no Batch discount) **plus $0.08 per
  session-hour** of `running` time — about $0.004 for a 3-minute scan, i.e. negligible.
- Limits: 300 create / 1,200 read requests per minute per organisation.
- Data: "Sessions are stateful resources; transcripts persist until you delete them" — **not
  ZDR-eligible**; workspace storage geo is US only.
- Fit: **poor.** It solves hosting a sandbox and a long-lived loop, neither of which this feature needs
  (no filesystem, no shell, 1–3 minutes). It adds a beta dependency, puts every transcript — including
  customers' uploaded designs and renders — at rest at Anthropic until deleted, cannot run against a
  local dev instance without exposing it, and cannot use the subscription. The one thing it would save is
  the 342 MB binary and ~1 GiB of RAM per concurrent scan.

### 3.6 (e) One scanner implementation, subscription locally and API key in production

| Option | Local dev on the owner's subscription | Production on an API key | Switch |
|---|---|---|---|
| **A. Agent SDK sidecar** | Yes — the spawned binary uses the same credential chain as the CLI: the stored `claude` login, or `CLAUDE_CODE_OAUTH_TOKEN` from `claude setup-token` | Yes — `ANTHROPIC_API_KEY` | **Environment only.** Precedence is documented: `ANTHROPIC_API_KEY` beats the OAuth token; "in non-interactive mode (`-p`), the key is always used when present" (<https://code.claude.com/docs/en/authentication>). So: key set → API billing; key unset → subscription. |
| **B. `claude -p` from PHP** | Yes (without `--bare`) | Yes (licensing caveat in 3.2) | Environment only |
| C. Messages API loop | No. API key only. | Yes | — |
| D. MCP connector | No | Yes | — |
| E. Managed Agents | No | Yes | — |

Two notes:

- A `setup-token` token "can only make model requests… MCP servers you configure locally still work" —
  exactly what the sidecar needs. Inside the dev Docker container the stored macOS login is not
  available, so local runs either execute the sidecar on the host against `http://localhost:8080/_mcp`,
  or pass `CLAUDE_CODE_OAUTH_TOKEN` into the container. Whether that is *permitted* is section 4.
- Options C–E are not excluded from cheap local prototyping: since 2026-10-07 "Claude Max, Ultra, and
  Team plans now include monthly API credits. They cover the Claude API, Claude Managed Agents, and the
  Claude Agent SDK and `claude -p` when you run them with an API key from your linked Claude Console
  organization"
  (<https://support.claude.com/en/articles/15036540-use-the-claude-agent-sdk-with-your-claude-plan>).
  If the owner is on Max, an API key drawing on those credits works with every option and sidesteps the
  terms question entirely. The credit amount was not verified.

### 3.7 Is there an official PHP SDK? PHP vs. node sidecar

[A] Yes. `anthropic-ai/sdk` — <https://github.com/anthropics/anthropic-sdk-php>, listed as one of seven
official client SDKs at <https://platform.claude.com/docs/en/cli-sdks-libraries/overview>. Packagist:
**v1.8.0, released 2026-11-10**, requires PHP ^8.1 and any PSR-18 client, licence Apache-2.0, 75
releases since v0.1.0 (2025-08-26). It covers Messages, streaming, Batches, Files, Managed Agents;
the tool-runner docs list a PHP example
(<https://github.com/anthropics/anthropic-sdk-php/blob/main/examples/beta/beta_tool_runner.php>, beta),
and the reference bundled with Claude Code documents `BetaRunnableTool` + `toolRunner()` and
`StructuredOutputModel` classes. There is **no Agent SDK for PHP** (TypeScript and Python only).

| | PHP (official SDK, in the Messenger handler) | Node sidecar (Agent SDK) |
|---|---|---|
| New runtime pieces | none | 342 MB binary + npm deps in the image; a `.mjs` script |
| Memory per concurrent scan | the PHP worker itself | + up to ~1 GiB for the `claude` subprocess |
| Tools | direct service calls, same transaction/logging as the app | HTTP to `/_mcp` with a minted token |
| Loop code | ~150 lines (or beta tool runner) | none |
| Progress, cost, cancellation | whatever you write; exact token usage per call | provided: stream events, result totals, abort |
| Upgrades | composer; API versioned and stable | SDK minor releases can change harness behaviour — pin the version |
| Local dev on subscription | no | yes |
| Parity with the proven manual setup | re-implementation (behaviour must be re-validated) | same harness |
| ZDR / data path | Messages API only | Messages API only (the harness calls it) |
| Testability in PHPUnit | easy (fake the client) | needs a fake sidecar |

### 3.8 Ranked recommendation for running the loop

1. **Agent SDK (TypeScript) sidecar, started by a `job_bus` handler, talking to `/_mcp` over HTTP.**
   Replicates the proven setup, is explicitly licensed for products, satisfies the "subscription
   locally, API key in production" requirement with an env switch, and needs no loop code. Configure it
   as in 3.2 (no built-in tools, strict MCP config, `dontAsk`, no setting sources, isolated config dir,
   custom system prompt, `maxTurns`, `maxBudgetUsd`, abort timer). Pin the SDK version. Cap concurrency
   at 1–2.
2. **Messages API loop in PHP with the official SDK** — the *target* if the sidecar's footprint,
   upgrade churn or testability becomes a nuisance, and the right tool **now** for the calendar
   single-call path and for cheap Haiku sub-steps (no agent needed there). Because the harness adds
   nothing quality-relevant (3.3), moving from 1 to 2 later is a contained change **provided the
   scanner's prompt, tool list and result schema live in the app, not in the sidecar**.
3. **`claude -p` spawned from PHP** — the fastest way to prototype (it is literally the manual setup
   with flags), fine on the owner's machine; for production prefer option 1 because of the licensing
   ambiguity and because the SDK gives typed events and abort handling.
4. **Messages API + MCP connector** — only if a pure-PHP, no-loop integration is wanted and beta status,
   a public endpoint and standard retention are acceptable.
5. **Managed Agents** — not for this feature.

Design rule that keeps all doors open: the scanner is defined by three app-owned artefacts — a system
prompt, a short list of scan tools on the MCP server (`scan_source`, `scan_crop`, the existing
build/preview tools, `scan_finish`), and a result schema. The runner (sidecar, PHP loop, or a human in
Claude Code) is interchangeable.

---

## 4. May the owner's personal subscription be used for local prototyping?

Sources, all read 2026-11-12:

- Claude Code "Legal and compliance": <https://code.claude.com/docs/en/legal-and-compliance>
- Agent SDK overview note: <https://code.claude.com/docs/en/agent-sdk/overview>
- Authentication: <https://code.claude.com/docs/en/authentication>; headless:
  <https://code.claude.com/docs/en/headless>
- Help centre, "Use the Claude Agent SDK with your Claude plan":
  <https://support.claude.com/en/articles/15036540-use-the-claude-agent-sdk-with-your-claude-plan>
- Consumer Terms of Service, effective 2025-10-08, version served to an EU visitor (contracting party
  "Anthropic Ireland, Limited"): <https://www.anthropic.com/legal/consumer-terms>
- Commercial Terms, effective 2025-06-17: <https://www.anthropic.com/legal/commercial-terms>

### What the documents say

1. **Headless and SDK use on a plan is a documented, supported thing.** Help centre: "when you're signed
   in with your Claude plan, the Claude Agent SDK and `claude -p` still draw from your subscription
   limits" (update of 2026-10-07; the changes announced earlier in 2026 were "paused" on 2026-06-15:
   "nothing has changed: Claude Agent SDK, `claude -p`, and third-party app usage still draw from your
   subscription limits"). Authentication docs: `claude setup-token` creates a one-year token "for CI
   pipelines, scripts, or other environments where interactive browser login isn't available… requires a
   Pro, Max, Ultra, Team, or Enterprise plan."
2. **Scope: ordinary individual use.** Legal page: "OAuth authentication is intended exclusively for
   purchasers of Claude Free, Pro, Max, Ultra, Team, and Enterprise subscription plans and is designed
   to support ordinary use of Claude Code and other native Anthropic applications." and "Advertised
   usage limits for Pro, Max, and Ultra plans assume ordinary, individual usage of Claude Code and the
   Agent SDK."
3. **Products must use API keys.** Legal page: "Developers building products or services that interact
   with Claude's capabilities, including those using the Agent SDK, should use API key authentication…
   Anthropic does not permit third-party developers to offer Claude.ai login into their own
   applications, or to route requests through Free, Pro, Max, or Ultra plan credentials on behalf of
   their users." SDK overview: "Unless previously approved, Anthropic does not allow third party
   developers to offer claude.ai login or rate limits for their products, including agents built on the
   Claude Agent SDK." Enforcement: "Anthropic reserves the right to take measures to enforce these
   restrictions and may do so without prior notice."
4. **Consumer Terms.** Prohibited: "Except when you are accessing our Services via an Anthropic API Key
   or where we otherwise explicitly permit it, to access the Services through automated or non-human
   means, whether through a bot, script, or otherwise." Also: "You may not share your Account login
   information, Anthropic API key, or Account credentials with anyone else." And, in the liability
   section of the EU version: "**Non-commercial use only.** You agree that you will not use our Services
   for any commercial or business purposes and we and our Providers have no liability to you for any
   loss of profit, loss of business, business interruption, or loss of business opportunity."
5. **Commercial Terms** (API): "Anthropic gives Customer permission to use the Services, including to
   power products and services Customer makes available to its own customers and end users."

### Allowed / not allowed / ambiguous

| Scenario | Verdict | Reasoning |
|---|---|---|
| **1. The owner, on his own machine, runs `claude -p` or an Agent SDK script signed in with his own plan, against his own dev instance, to develop and test the scanner** | **Allowed as far as the product documentation goes; one ambiguity.** | The "automated means" ban has the carve-out "where we otherwise explicitly permit it", and the docs explicitly document `claude -p`, the SDK and `setup-token` on a plan and say they draw on plan limits. It is one individual using Claude Code's harness for his own development — the same thing he does interactively. **Ambiguity:** the EU Consumer Terms' "non-commercial use only" sentence, read literally, covers any business use of a Pro/Max plan — including ordinary professional coding with Claude Code, which Anthropic markets to individual developers. It sits in the liability clause and reads as a liability limiter, but it is the text. No Anthropic page was found that resolves this. |
| **2. The same script running unattended on a server or in CI with a `setup-token`** | **Grey — avoid.** | `setup-token` is documented for "CI pipelines, scripts", so the mechanism is permitted; but an always-on server job is not "ordinary, individual usage", and the legal page tells developers building products to use API keys. A staging server processing other people's uploads is scenario 3. |
| **3. The production feature, or any environment where other users' uploads are processed** | **Not allowed.** | "Route requests through … plan credentials on behalf of their users" is expressly prohibited; Consumer Terms forbid making the account available to others. Also wrong on data terms: consumer-plan data handling (retention, training settings) is not the DPA-covered commercial processing in section 5. |
| **Using real customer designs as test inputs while on the subscription** | **Do not.** | Same data-terms reason: test with the team's own designs on the subscription; anything from a customer goes through the API key. |

### Practical answer

- Yes for genuinely local, hands-on prototyping by the owner himself — it is the documented behaviour of
  `claude -p` and the Agent SDK on a plan, and volume will be a few dozen scans.
- Keep it to his machine and his own test images; never put the plan token on the server, in CI, or in
  the repo.
- The cleanest route, if he is on Max: use the **monthly API credits** included in the plan through a
  linked Console organisation (API key auth, Commercial Terms, no ambiguity).
- Otherwise the cost of doing it "by the book" is small: at ~$0.40 per agent scan on Opus 5.5
  (section 6), 100 prototype scans are about $40 of API credit.
- If a written answer is wanted for the "non-commercial" sentence, the legal page points to sales:
  "For questions about permitted authentication methods for your use case, please contact sales."

---

## 5. Privacy and data terms for API use

### 5.1 Anthropic (commercial / API) [A]

| Topic | What the documents say | Source |
|---|---|---|
| Roles | On the Claude API "Anthropic is the data processor". EEA customers contract with **Anthropic Ireland, Limited**. | <https://platform.claude.com/docs/en/manage-claude/api-and-data-retention>, <https://www.anthropic.com/legal/commercial-terms> |
| Training | "Anthropic may not train models on Customer Content from Services." Privacy centre: "By default, we will not use your inputs or outputs from our commercial products… to train our models", unless you send feedback or opt in. | Commercial Terms §B; <https://privacy.claude.com/en/articles/7996868-is-my-data-used-for-model-training> (dated 2026-08-18) |
| Standard retention | "For Anthropic API users, we automatically delete inputs and outputs on our backend **within 30 days** of receipt or generation, except" longer-retention features you control (Files API), a ZDR agreement, Usage Policy enforcement, or legal requirements. | <https://privacy.claude.com/en/articles/7996866-how-long-do-you-store-my-organization-s-data> (dated 2026-07-01) |
| (wording differs) | The API docs page says: "Conversation content (your prompts and Claude's outputs) is not retained by default; the exception is Covered Models, which require 30-day retention." This reads as stricter than the privacy-centre article. **Use "up to 30 days" in user-facing text** until Anthropic clarifies. | <https://platform.claude.com/docs/en/manage-claude/api-and-data-retention> |
| Flagged content | "We retain inputs and outputs for up to 2 years and trust and safety classification scores for up to 7 years if your chat is flagged… as violating our Usage Policy." Applies even under ZDR. | both pages above |
| Covered Models | Fable 5.2 / 5.1 / 5 (and Mythos) "require 30-day data retention and are not available under ZDR unless expressly authorized". Opus 5.5, Sonnet 5.5, Haiku 5.5 are not Covered Models. | API retention page |
| Zero data retention | "Anthropic does not store customer prompts or responses at rest after the API response is returned." By arrangement only: "contact the Anthropic sales team". Covers Messages API (incl. Claude Code / Agent SDK run with a commercial API key); structured outputs are "qualified" (only the JSON schema is cached, up to 24 h). **Not** covered: Batch API (29 days), Files API, MCP connector, Managed Agents, the Console. | API retention page; structured-outputs page |
| EU data residency | **Not available on the first-party API.** `inference_geo` accepts `"global"` (default — "inference may run in any available geography") or `"us"` (+10%). Workspace geo (data at rest): "Currently, `us` is the only available workspace geo." EU processing is offered only on partner platforms (Amazon Bedrock regional endpoints, Google Cloud regional / multi-region endpoints), where the cloud provider is the processor and has its own terms (+10% for regional endpoints). | <https://platform.claude.com/docs/en/manage-claude/data-residency>, pricing page |
| DPA | "Anthropic's DPA with Standard Contractual Clauses (SCCs) is automatically incorporated into our Commercial Terms of Service… When you accept Anthropic's Commercial Terms of Service, you also accept our DPA." DPA effective 2025-02-24; includes EU SCCs, a sub-processor schedule (Schedule 4), 15-day objection window for new sub-processors. | <https://privacy.claude.com/en/articles/7996862-how-do-i-view-and-sign-your-data-processing-addendum-dpa> (2026-03-16), <https://www.anthropic.com/legal/data-processing-addendum> |
| Image-specific | "Claude does not parse or receive any metadata from images." Claude "cannot be used to name people in images and refuses to do so." Uploaded images cannot be individually deleted by API (retention policy applies), unless they went through the Files API. | Vision page FAQ |

### 5.2 What a Czech (EU / GDPR) SaaS must tell users and do

This is an engineering checklist, not legal advice — the wording belongs to the company's lawyer.

- **Sub-processor.** Add Anthropic (Anthropic Ireland, Limited as contracting entity; processing in the
  USA and other regions) to the list of sub-processors / recipients in the privacy policy and in
  WBoost's own DPA with business customers, with purpose "automated analysis of uploaded designs for
  the AI scan feature". Customers with a DPA normally have to be notified of a new sub-processor in
  advance.
- **International transfer.** State that uploaded images are transferred outside the EEA, on the basis of
  Standard Contractual Clauses included in Anthropic's DPA. There is no EU-only option on the direct API;
  if a customer requires EU-only processing, the route is Claude on Bedrock or Google Cloud in an EU
  region (different contract, same models) — design the model client so the endpoint is configurable.
- **What is sent.** The uploaded image, the generated layout, the rendered previews, and the tool
  traffic (project fonts, palette, gallery image names — whatever the scan tools return). Keep tool
  outputs minimal; do not send user e-mail addresses or names.
- **Retention at the provider.** "Deleted within 30 days; kept longer only if flagged for a policy
  violation (up to 2 years) or required by law." If Fable 5.2 is used, 30-day retention is a hard
  requirement; if ZDR is ever wanted, the model must be Opus/Sonnet/Haiku and Batch / Files / MCP
  connector / Managed Agents must not be used.
- **No training.** State plainly that uploads are not used to train the provider's models.
- **Personal data in the images.** Posters and calendars contain names, faces, phone numbers. The user
  (controller of that content) should be told the image is analysed by an AI provider at the moment of
  upload — a short notice at the upload control, not only in the policy — and the feature should be
  opt-in per upload (it is: the user clicks "AI sken").
- **AI transparency.** Label the result as AI-generated and requiring review (it is a draft by nature).
- **Internal records.** Update the record of processing activities; a short legitimate-interest /
  transfer assessment is prudent. Do not log image bytes or full prompts in Sentry.
- **Local subscription prototyping** is outside all of the above (consumer terms, different data
  handling) — another reason to keep customer material out of it.

### 5.3 OpenAI, briefly [B]

Sources: <https://developers.openai.com/api/docs/guides/your-data>,
<https://cdn.openai.com/pdf/openai-data-processing-addendum.pdf>.

- Training: API data is "not used to train or improve OpenAI models" unless opted in.
- Retention: abuse-monitoring logs "up to 30 days, unless longer retention is required by law".
  Responses API stores responses by default — set `store: false`.
- ZDR / modified abuse monitoring: by approval through sales. Image and file inputs are scanned for
  CSAM and flagged images are retained for review even under ZDR.
- **EU data residency exists**: `eu.api.openai.com`, storage and processing in the EEA/Switzerland,
  +10% on current models — but only for organisations approved for ZDR / modified retention, so not
  self-serve; "system data" (explicitly including the structured-output schema) is excluded.
- DPA (v. 2026-01-01) incorporated by use; EEA customers contract with OpenAI Ireland Ltd; SCCs;
  30-day sub-processor objection window.
- A past US court preservation order affected API data without ZDR; the fetched official pages only
  mention the "required by law" exception, and its current status could not be verified.

Net: the two providers are equivalent for a small EU SaaS in practice (no training, ~30 days, DPA with
SCCs, US-based processing by default). OpenAI has an EU endpoint on paper that Anthropic's first-party
API lacks; both require a sales conversation for anything beyond the defaults.

---

## 6. Rough cost model

Prices from section 1.1 / 2.1. Everything else here is an **assumption** to be replaced by measured
`usage` from the first real runs.

### 6.1 Tokens per image

From 1.2: input design 1,521 (square social) – 2,835 (A4 @ 150 dpi) – 4,784 (max, large photo) tokens;
a rendered check image 1,189 (800 px wide) – 1,836 (1000 px) – 2,835 (same size as the source).
OpenAI is within ±10% of these at `original` detail.

Tip: render check images at the *same pixel size as the pre-resized source* when pixel-exact comparison
matters, at ~800 px when only overall structure is being checked — a 2.4× difference in tokens per
render.

### 6.2 (i) One structured-output call

Assumptions: system prompt + vocabulary + schema instructions 3,000 tokens; image 2,835; rounded to
**6,000 input**. Output: layout JSON ≈ 3,000 tokens + thinking ≈ 3,000 = **6,000 output** (thinking is
billed as output; at `high` effort on a dense page it can be more).

`cost = 6,000 × input price + 6,000 × output price`

| Model | Arithmetic | Per scan | Via Batch (−50%) |
|---|---|---|---|
| Fable 5.2 | 6k × $8/M + 6k × $40/M = 0.048 + 0.240 | **$0.288** | $0.144 |
| Opus 5.5 | 6k × $4/M + 6k × $20/M = 0.024 + 0.120 | **$0.144** | $0.072 |
| Sonnet 5.5 | 6k × $2/M + 6k × $10/M = 0.012 + 0.060 | **$0.072** | $0.036 |
| Haiku 5.5 | 6k × $0.10/M + 6k × $0.50/M = 0.0006 + 0.003 | **$0.0036** | $0.0018 |
| GPT-6 Astra | 6k × $10/M + 6k × $50/M | $0.36 | $0.18 |
| GPT-6.1 Sol | same prices as Sonnet 5.5 | $0.072 | $0.036 |
| GPT-6.1 Terra | 6k × $1/M + 6k × $5/M | $0.036 | $0.018 |

Caching barely matters here: only the 3,000-token prefix is reusable, and only if another scan follows
within the TTL — on Opus 5.5 it saves 3,000 × ($4 − $0.20)/M ≈ $0.011 per cache hit. Output dominates
(83% of the cost), so the lever is effort level and a compact output format, not input.

A sensible calendar pipeline — one extraction call plus **one** render-and-verify call that sees source
and render and returns corrections (≈ 2 × the above, minus little) — is about **$0.25–0.30 on Opus 5.5,
$0.13–0.15 on Sonnet 5.5**.

### 6.3 (ii) Agent loop, ~5–10 turns, 3–5 rendered comparisons

Model of the loop. Fixed prefix `S` (system prompt + tool definitions), source image `I`, and per turn:
`o` output tokens (thinking + tool call carrying the design), plus tool results (a text summary every
turn, a render image on some turns). Every request resends the whole history, so the input of turn `k`
is `S + I + (k−1) × a`, where `a` = output per turn + average tool-result tokens per turn.

**Typical scenario** — 8 turns, 4 renders of 1,836 tokens, `S` = 6,000, `I` = 2,835, `o` = 1,500, text
results 300 per turn:

- `a = 1,500 + (4 × 1,836 + 8 × 300) / 8 = 1,500 + 1,218 = 2,718`
- Total input processed = `8 × 8,835 + 2,718 × (0+1+…+7) = 70,680 + 76,104 = 146,784` tokens
- Total output = `8 × 1,500 = 12,000` tokens
- With prompt caching, each turn re-reads the previous prefix from cache and writes only the new part:
  cache writes = `8,835 + 7 × 2,718 = 27,861` tokens (at 1.25× input price); cache reads =
  `146,784 − 27,861 = 118,923` tokens (at the cache-read price).

| Model | Without caching: 146,784 × in + 12,000 × out | With caching: 27,861 × write + 118,923 × read + 12,000 × out |
|---|---|---|
| Fable 5.2 | 1.174 + 0.480 = **$1.65** | 0.279 + 0.024 + 0.480 = **$0.78** |
| Opus 5.5 | 0.587 + 0.240 = **$0.83** | 0.139 + 0.024 + 0.240 = **$0.40** |
| Sonnet 5.5 | 0.294 + 0.120 = **$0.41** | 0.070 + 0.012 + 0.120 = **$0.20** |
| Haiku 5.5 | 0.015 + 0.006 = **$0.021** | 0.003 + 0.001 + 0.006 = **$0.011** |
| GPT-6 Astra | 1.468 + 0.600 = $2.07 | 0.348 + 0.119 + 0.600 = $1.07 |
| GPT-6.1 Sol | $0.41 | $0.20 |
| GPT-6.1 Terra | $0.21 | $0.10 |

**Range** (same formula):

| Scenario | Total in / out | Opus 5.5 cached | Sonnet 5.5 cached | Fable 5.2 cached |
|---|---|---|---|---|
| Light: 5 turns, 3 renders of 1,521, `S` 5,000, `I` 1,911, `o` 1,200 | 58,681 / 6,000 | $0.21 | $0.11 | $0.41 |
| Typical (above) | 146,784 / 12,000 | $0.40 | $0.20 | $0.78 |
| Heavy: 10 turns, 5 renders of 2,835, `S` 10,000, `I` 4,784, `o` 2,500 | 342,128 / 25,000 | $0.83 | $0.41 | $1.59 |

Observations:

- **Caching halves the cost** of the loop and must be on (the Agent SDK does it automatically; in a
  hand-written loop it is one `cache_control` field). The 5-minute TTL is enough — turns are seconds
  apart.
- With caching, **output tokens are 60% of the bill** (Opus 5.5 typical: $0.24 of $0.40). The biggest
  lever is how the model edits: resending the entire design DSL every turn is expensive; a tool that
  applies targeted changes ("move element X", "set font size") keeps `o` small. Effort level is the
  second lever.
- Render size is the third lever (6.1).
- **Claude Code harness overhead**: with `tools: []` and a custom system prompt the harness adds
  little to the prefix. With the `claude_code` preset and built-in tools left on, the prefix grows by an
  estimated 10–20k tokens (not measured — an assumption); cached, that is roughly +$0.05–0.10 per scan
  on Opus 5.5.
- **Haiku 5.5 sub-steps are free in practice** — an upload classifier or a "which region is worst"
  judge at ~3,000 input / 300 output tokens is $0.0005.
- At 500 scans a month on the typical scenario: ≈ $200 (Opus 5.5), ≈ $100 (Sonnet 5.5), ≈ $390
  (Fable 5.2). The Start tier's $500/month cap is the first limit that could bite with Fable.

Budget guard-rails to configure from day one: `maxTurns` 12, `maxBudgetUsd` ≈ 1.50 (Opus) with
headroom, a wall-clock abort at ~5 minutes, a monthly spend limit in the Console, and a per-project scan
quota in the app.

---

## 7. Recommendation and confidence

**Provider and models**

- Stay on **Claude**. Nothing public shows OpenAI clearly better for any part of the problem, price is
  identical at the mid tier, Anthropic has the official PHP SDK and by far the better coordinate
  documentation, and the app's MCP surface is already proven with it. *Confidence: medium-high* — it
  rests on fit and absence of contrary evidence, not on a measured accuracy win.
- Default model **Opus 5.5 at `high` effort**; A/B **Sonnet 5.5** (half the cost) and **Fable 5.2**
  (2× the cost, 30-day retention mandatory) on the app's own evaluation set; **Haiku 5.5** for
  sub-steps. *Confidence: medium* — the choice between the three must be made on data.
- Build a small evaluation set first: 30–50 designs **exported from the app's own templates and
  calendars**, so ground-truth boxes, fonts and text exist for free, with Czech text. Score box IoU,
  text exactness (diacritics), font match, colour distance. Every open question in this document
  (which model, hints or no hints, render size, single call vs. loop) is answered by that set and by
  nothing else. *Confidence: high.*

**Architecture**

- **Templates (free-form designs): agent loop** — look, build through the validated tools, render,
  compare, correct. *Confidence: high* (the team has done it by hand).
- **Calendars (constrained vocabulary): start with a single structured-output call plus one
  render-and-verify pass**, from PHP, and promote to the agent loop only if the evaluation says so.
  *Confidence: medium.*
- Always: pre-resize the image to the model's native size and send it with
  `oversized_image: "error"`; ask for absolute pixels and convert to mm/pt in PHP; measure colours from
  pixels with Imagick; resolve fonts against the project's own font list by rendering; give the model a
  crop tool. *Confidence: high* (official guidance).
- Rasterise PDFs yourself; for PDFs with live text, extract text/fonts/positions classically first.

**How to run it** (detail and ranking in 3.8)

1. **Claude Agent SDK (TypeScript) sidecar** launched by a `job_bus` handler, connected to the app's
   existing `/_mcp` over HTTP with a short-lived scoped token, locked down to the app's tools only.
   It is the proven setup, explicitly licensed for products, and the only family of options where one
   implementation runs on the owner's subscription locally and on an API key in production by changing
   environment variables. *Confidence: medium-high.*
2. Keep the scanner's prompt, tool list and result schema **in the app**, so the runner can later be
   replaced by a ~150-line **PHP Messages-API loop** (official SDK) if the sidecar's 342 MB binary,
   ~1 GiB per concurrent scan or upgrade churn becomes a burden. The harness adds no quality-relevant
   magic for a 5–10-turn, tools-only task. *Confidence: medium-high.*
3. `claude -p` from PHP for quick prototyping only; MCP connector and Managed Agents: no.

**Subscription**: fine for the owner's own hands-on local prototyping with his own test images (it is
documented behaviour), not for a server, CI, other users, or customer material; one textual ambiguity
(the EU Consumer Terms' "non-commercial use only") remains. Plan API credits (Max) or ~$40 of API
credit make the question moot. *Confidence: medium-high on the practical reading, low on how Anthropic
would read the ambiguous sentence.*

**Privacy**: add Anthropic as a sub-processor with a US transfer under SCCs, state "no training,
deleted within 30 days", notify at upload. No EU-only processing on the direct API — if a customer needs
it, Bedrock or Google Cloud in an EU region. *Confidence: high on the facts, the wording is for a
lawyer.*

**Cost**: about **$0.40 per agent scan on Opus 5.5** (range $0.21–0.83), $0.20 on Sonnet 5.5, $0.78 on
Fable 5.2, with caching; **$0.07–0.14 per single-call scan**. *Confidence: medium on the order of
magnitude, low on the exact figure until real `usage` numbers exist.*

---

## 8. Things that could not be verified

Benchmarks and accuracy

- No public benchmark tests Claude **Fable 5.2 or Opus 5.5**, or any model, on poster / calendar →
  editable layout. All accuracy statements about current models on this task are inference.
- ScreenSpot-Pro figures come from two aggregators that disagree on older models; the vendors' primary
  posts / system cards were not read. Tool setups per row are unknown.
- Roboflow's object-detection ranking has no published numbers (video only).
- No current Design2Code / UI-to-code leaderboard numbers could be confirmed.
- **Czech OCR on modern typography is unmeasured for every current model.** The only Czech data is
  historical print on models two generations old.
- Whether grid / ruler overlays or Set-of-Mark still help current models (evidence is GPT-4V-era).
- Whether current Claude / GPT models have closed the tight-text-box gap to Gemini shown in OCRBench v2.

Anthropic

- The two official statements on default API retention differ ("within 30 days" on the privacy centre
  vs. "not retained by default" on the API docs page). Not resolved.
- The DPA's current sub-processor list (Schedule 4) was not extracted.
- Whether Anthropic considers a backend job running the raw `claude -p` binary on the operator's own
  API key to fall under "offering Claude Code in your products". Unanswered by the docs.
- How Anthropic applies the EU Consumer Terms' "non-commercial use only" sentence to individual
  developers on Pro/Max. Unanswered by the docs.
- The size of the monthly API credits included in Max plans, and whether Pro has any.
- Agent SDK cold-start time and real memory use for a tools-only session (docs give only "1 GiB / 1 CPU
  / 5 GiB as a starting point"). Needs measuring on the box.
- How many prefix tokens the Claude Code harness adds (minimal prompt vs. `claude_code` preset); the
  10–20k figure in 6.3 is an assumption.
- Image input for `claude -p` (the headless page does not document passing an image; the
  `--input-format stream-json` route is presumed, not confirmed). The `scan_source` MCP tool avoids the
  question.
- Whether the Agent SDK / Claude Code downsizes images returned by MCP tools before sending them to the
  API (matters for coordinate fidelity of renders). Test with a known image.
- Whether the base image `ghcr.io/thedevs-cz/php:8.5-wboost` is glibc or musl (both binary variants are
  published).
- Whether `/_mcp` accepts the `/api` `client_credentials` JWTs unchanged (depends on
  `McpOAuthAuthenticator`); a scoped short-lived MCP token is assumed instead.
- The PHP SDK's stability label: no "beta" marker is shown on Packagist / README for the package as a
  whole; the tool runner specifically is labelled beta in the docs. The PHP tool-runner example file was
  linked from the docs but not opened.
- Rate limits of the "Evaluation" tier a new API organisation may start in (unpublished).

OpenAI

- `openai.com/api/pricing`, `/enterprise-privacy`, `/policies/*` and the sub-processor list returned
  HTTP 403; prices come from `developers.openai.com/api/docs/pricing`.
- The image-token multiplier for `gpt-6.1-sol`, `gpt-6.1-terra`, `gpt-6-luna` (1.2 assumed).
- Dated snapshot IDs for the gpt-6 family; limits above the "Grow" tier.
- Current status of the litigation-related retention of API data.
- All OpenAI and Google facts were read by a sub-agent, not re-read line by line for this document.

Cost model

- Every token count other than image tokens and prices (system prompt size, output per turn, number of
  turns, thinking volume) is an assumption. Replace with measured `usage` after the first ten real
  scans.
