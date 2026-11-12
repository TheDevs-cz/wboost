# AI scan — supporting techniques (research, 2026-11-12)

Scope: which techniques are worth adding around a cloud vision-language model (VLM) to turn an
uploaded raster (later PDF, maybe PSD / IDML) of a desk-calendar page or a social post / poster /
flyer into an editable layered object, given **one shared Hetzner box, no GPU, scan worker capped at
about 3 CPUs and a few GB of RAM**.

How to read this file:

- Verdicts are for *this* setting: **use** (v1), **maybe later**, **skip**.
- Evidence tags: **[R]** = licence / last release / last push read from the GitHub API or the npm
  registry on 2026-11-12; **[P]** = read from the paper or official docs page cited; **[S]** = only a
  secondary source (search summary, blog, aggregator) — treat as a lead, not a fact; **[M]** = from
  memory, not re-checked today.
- Everything I could not verify is collected in section 14.

Local check of the dev `web` container (2026-11-12): Ghostscript 10.05.1, node 24.22, Python 3.13.5,
Inkscape 1.4 and the PHP Imagick extension (ImageMagick 7.1.1-43) are present. The ImageMagick
**CLI** (`magick` / `convert` / `compare`), Poppler (`pdftotext`, `pdftocairo`, `pdfimages`),
`mutool`, `tesseract`, `potrace` and `vips` are **not** installed. So "ImageMagick `compare`" means
either installing the CLI package or using `Imagick::compareImages()` from PHP.

---

## 0. Summary of verdicts

| Area | Use in v1 | Maybe later | Skip |
|---|---|---|---|
| Decomposition models | — (learn from them) | Qwen-Image-Layered through a hosted endpoint, as an optional photo/illustration splitter | LayerD, OmniPSD, Accordion, SAM 3 self-hosted (all GPU-bound) |
| VLM strategy | Structured single shot + deterministic snapping + a short, guarded repair loop (max 2–3 rounds, keep-best) | Candidate pools with a judge | Unbounded "regenerate everything" loops |
| OCR | PP-OCRv5 mobile (det + `latin` rec) on ONNX Runtime; Tesseract 5 `ces` as second opinion for clean exports | Google Cloud Vision as a paid fallback | Surya (licence + speed), EasyOCR (stale), docTR (no clear win) |
| Fonts | Render-and-compare against the project's own fonts (+ a Google Fonts shortlist from the VLM) | WhatFontIs API for "unknown font" hints; small open classifier | VLM-only font naming, WhatTheFont (no API found), Lens (non-commercial) |
| Segmentation | Classical: OCR text mask, colour quantisation + connected components, photo-vs-flat test | rembg (MIT) for subject cut-outs; MobileSAM with VLM boxes | SAM 2/3 on CPU, FastSAM (AGPL), BRIA RMBG weights (CC BY-NC) |
| Text removal | Flat-colour fill where background is flat; otherwise crop the photo region and let the user replace it | LaMa ONNX (512 px tiles) or Recraft "erase region" ($0.002) | Generative inpainting (hallucinates objects) |
| Vectorisation | VTracer (MIT) for logos / flat shapes; primitives emitted directly (rect, ellipse, line) | Vectorizer.ai / Recraft vectorize for difficult logos | potrace / autotrace as libraries (GPL), though Inkscape's CLI is already in the image |
| Metrics | Low-res MSE/PSNR, SSIM, edge IoU, OCR text match (CER), ΔE00, per-element IoU, element count | LPIPS / DreamSim in an offline eval harness (Python, dev machine) | CLIP score as the main fidelity metric |
| PDF | pdf.js (`pdfjs-dist`, Apache-2.0) + Poppler CLI as a separate process | qpdf for structure checks | MuPDF / `mupdf` npm / PyMuPDF (AGPL) inside the worker |
| PSD / IDML | Keep the intermediate scene format source-agnostic | ag-psd (MIT) for PSD; plain XML parsing for IDML | psd.js (stale) |
| Photos of physical calendars | Require clean exports; detect and warn | 4-point homography (OpenCV.js) with user-confirmed corners | Learned dewarping (UVDoc etc.) |

---

## 1. Image → editable layered design: research and products

### 1.1 Research

**LayerD** (CyberAgent, ICCV 2025) — <https://arxiv.org/abs/2509.25134>, code
<https://github.com/CyberAgentAILab/LayerD> (Apache-2.0, v0.1.0 2026-01-05, repository **archived**
[R]).
Iteratively extracts the unoccluded top layer with a matting model (BiRefNet, Swin-L, trained on
Crello), completes the background with **LaMa**, repeats (max 3 iterations). Refinement relies on
the flat-colour assumption: background snapped to a palette of ≤ 2 colours, foreground to ≤ 10 [P].
Lessons that transfer:

- They chose LaMa over generative inpainting because the latter "often insert[s] unnecessary
  objects"; FLUX.1 Fill made results worse [P].
- Palette snapping of flat regions gave the largest RGB-L1 gain in the ablation [P] — a cheap,
  CPU-only step that we can copy without the model.
- Failure modes: very small objects and small detailed text; ambiguity of layer granularity (humans
  disagree too); errors in early iterations propagate; transparency not handled; inputs resized to a
  512 px short side [P].
- Evaluation idea: align predicted and ground-truth layer sequences with DTW, score alpha soft-IoU
  and RGB L1, and report quality as a function of allowed *merge* edits so that granularity mismatch
  is separated from visual quality [P].
- Output is RGBA layers — **text stays raster**. It does not give live text, fonts or shapes.

**Accordion — "Rethinking Layered Graphic Design Generation with a Top-Down Approach"** (ICCV 2025)
— <https://arxiv.org/abs/2507.05601>, <https://jingyechen.github.io/accordion>.
A VLM plays three roles (reference creation, design planning, layer generation); layers are refined
with "vision experts such as SAM and inpainting"; output is background, foreground objects and
*vectorised text* layers; trained on an in-house Design39K set [P/S]. Relevant as confirmation of
the architecture "VLM plans, specialist tools execute"; not usable directly (trained model, in-house
data).

**Qwen-Image-Layered** (Alibaba, Dec 2025) — <https://arxiv.org/abs/2512.15603>,
<https://github.com/QwenLM/Qwen-Image-Layered> (Apache-2.0, last push 2025-12-31 [R]).
End-to-end diffusion model (RGBA-VAE + variable-layer MMDiT) that splits an RGB image into N RGBA
layers, recursively if wanted; 640 px bucket recommended, 1024 available; examples call `.cuda()`
with 50 steps; no VRAM or timing figures in the README [P]. Hosted on Replicate
(<https://replicate.com/qwen/qwen-image-layered>) [S]. It is a 20B-class diffusion model — not
CPU-feasible. Output is raster RGBA; text is not recovered as text.

**OmniPSD** (Dec 2025) — <https://arxiv.org/abs/2512.09247>, <https://showlab.github.io/OmniPSD>.
Flux-Kontext-based diffusion transformer with an RGBA-VAE; image-to-PSD by iteratively "extracting
and erasing textual and foreground components" [P]. GPU-only research model.

**LayerDecomp** — referenced in searches as "a generative framework for image layer decomposition";
I did not find authors, venue or code today [S]. Same family (generative RGBA decomposition of
photos), same verdict.

**ReDesign — "Recovering Editable Design Structures from Images via Agentic Decomposition"**
(ECCV 2026) — <https://arxiv.org/abs/2607.25565>. **The closest published system to what WBoost
wants**, and the best source for pipeline design:

- A VLM controller (Gemini 3; GPT-5 mini as alternative) grows a tree from the full canvas and picks
  tool chains per node: **PaddleOCR v5** for text, **HiSAM** for text pixels, **LaMa / ObjectClear**
  to fill under text, **WhatFontIs** for font families, **Grounding DINO + SAM 2.1** for objects,
  **Qwen-Image-Layered** for layer splitting, **connected-component labelling** (SciPy), **VTracer**
  for shape-like leaves; photographic leaves stay raster with placement metadata [P].
- Verification after each expansion is a three-step VLM check (hallucination against the parent,
  redundancy across children, coverage of the parent) returning **accept / prune / retry** — a
  *local* check, not a global regenerate. Deterministic tools (CCL, text extraction) can only be
  accepted or retried [P].
- Output: a JSON layer hierarchy with vector shapes, colours, text content, fonts, groups, z-order.
- Numbers on their Figma set: ReDesign L1 0.0431 / PSNR 26.3 / LPIPS 0.088; Qwen-Image-Layered
  0.0493 / 26.2 / 0.107; LayerD 0.0704 / 16.1 / 0.338; plain VTracer 0.0977 / 20.5 / 0.192; a
  ReAct-style serial "tool agent" got similar L1 but PSNR 13.9 / LPIPS 0.387, i.e. unverified
  serial tool use accumulates errors [P].
- Cost: on average 28.8 tool calls and about **$0.027 VLM API cost per image**; local tools ran on
  an A6000 ("under 8 GB VRAM") [P]. No code release mentioned.
- New evaluation idea: an **edit-replay benchmark** (909 Figma files, 14,796 scripted edits —
  delete, recolour, move, z-order, text change — replayed on the reconstruction, then compared) —
  measures *editability*, not just looks [P].
- Their failure modes: generative tools hallucinate; decompositions blurred or incomplete;
  granularity differs from the ground truth even when the render looks right; results depend on the
  VLM [P].

Verdict for all decomposition **models**: **skip** self-hosting (GPU). **Maybe later**: call
Qwen-Image-Layered through a hosted endpoint for the one job classical methods cannot do —
separating an illustration/photo foreground from its background *with the occluded part filled in*.
What to **use** is their recipe: OCR → text mask → fill → detect/segment → vectorise the flat parts
→ keep photos raster → verify locally.

### 1.2 Products

| Product | What it does | What is known about quality / limits | Source |
|---|---|---|---|
| **Canva Magic Layers** (public beta 2026-03-11; in ChatGPT / Gemini from June 2026) | Flat PNG/JPG → separated objects, background, and text restored as live text boxes, layout preserved. Runs on Canva's own "design model". | Single-page PNG/JPG only. Canva's help says it works best on graphic designs, illustrations and stylised visuals; photo-realistic images separate less cleanly. Third-party tests: dense text and small fonts come out with missing characters or blur; guides advise re-typing final text. | <https://www.canva.com/help/editable-magic-layers/>, <https://petapixel.com/2026/03/11/canvas-new-ai-tool-turns-flat-images-into-editable-layers>, <https://diginomica.com/how-canva-design-specific-foundation-model-edit-any-ai-generated-image> [P/S] |
| **Canva Grab Text** | Turns text in an image into editable text boxes layered over the image; the original text is erased underneath. | Canva's own help: nearby text can shift or distort — grab all text or text that sits together; faint artefacts may remain and need Magic Eraser. | <https://www.canva.com/help/using-grab-text/> [P] |
| **Adobe Illustrator Retype / Photoshop Match Font** | Font suggestion for raster or outlined text from the Adobe Fonts library (30,000+ fonts) or local fonts, then "Edit Text" converts to live text. | Suggestions, not identification; user reports of poor matches. No public API found. | <https://helpx.adobe.com/illustrator/desktop/design-with-text/fonts-and-scripts/identify-fonts-using-retype.html> [P] |
| **Adobe Firefly "Layered Image Editing"** | Announced with Firefly Image 5 (Oct 2025): edit elements of an image as layers. | An Adobe community reply from April 2026 says Firefly output opened in Photoshop is still flattened. Status unclear. | <https://techcrunch.com/2025/10/28/adobe-firefly-image-5-brings-support-for-layers-will-let-creators-make-custom-models/> [S] |
| **Lovart "Edit Elements"** | Splits an image into subject / background / text layers; text editable (font, colour, layout); PSD export. | Docs: best on images with clear subjects, text and backgrounds. | <https://www.lovart.ai/docs/edit-your-design/advanced-ai-editing> [P/S] |
| **Figma** (built-in agent; plugins Codia, image.to.design, Layerize) | Screenshot → editable frames, auto-layout, OCR text. Aimed at UI, not print design. | Vendors themselves say the result needs manual review of text and layout. | <https://www.figma.com/solutions/screenshot-to-design-converter/>, <https://www.codia.ai/screenshot-to-figma> [S] |
| **Recraft** | Vector-first generator; API has vectorize ($0.01), remove background ($0.01), erase region ($0.002), inpaint ($0.04). | No layer-decomposition feature found. | <https://www.recraft.ai/docs/api-reference/pricing> [P] |
| **Kittl** | Typography-heavy design tool with a vectoriser. | No image-to-layers feature confirmed. | [S] |

What the products teach:

1. Everybody restricts the input (single page, PNG/JPG, "graphic designs work best").
2. Text is the weak spot everywhere: small and dense text loses characters; neighbouring text gets
   disturbed by the erase step.
3. Nobody claims font identity — they offer "similar font" and expect the user to fix it. A product
   that knows the *project's* fonts (WBoost does) has an advantage the generic tools lack.
4. Residue under removed text is a known, accepted artefact, with a manual eraser as the escape
   hatch.

---

## 2. VLM-centred approaches: single shot vs render-and-compare

### 2.1 Evidence

| Work | Setting | Finding |
|---|---|---|
| **Design2Code** (<https://arxiv.org/abs/2403.03163>) | Screenshot → HTML; one self-revision round with reference + own render + own code | Feeding **OCR-extracted text** into the prompt ("text-augmented") raised block-match for GPT-4V 85.8 → 87.6 and Gemini 1.0 80.2 → 84.8, and text scores for most models; position and colour mostly flat. **Self-revision**: small gains for GPT-4V (block 87.6 → 88.8), Claude 3 Opus (position 75.9 → 78.1), GPT-4o (+0.3); Gemini got slightly worse; open models collapsed (DeepSeek-VL 66.1 → 30.1). On the HARD subset GPT-4o gained much more: block 56.6 → 67.7 (text-augmented) → 72.1 (self-revision). The authors attribute the weakness to the model having to "understand the differences between the two given images" [P]. |
| **UI2CodeN** (<https://arxiv.org/abs/2511.08195>) | UI → code, a model *trained* for polishing; inputs: target image, code, render | Real set 66 → 68 → 70 → 73 → 74 over rounds 1–5 (no plateau by 5); synthetic set 92 → 97 → 97 (plateau at 2–3). Off-the-shelf models on a single polish step: Claude-4-Sonnet 78 / 65, GPT-5 85 / 68, Gemini-2.5-Pro 74 / 68 [P]. |
| **VASCAR** (<https://arxiv.org/abs/2412.04237>) | Layout generation with GPT-4o / Gemini; candidates rendered as boxes and fed back with **numeric scores and text hints**; top-5 kept per round | Metrics improve with iterations (5 for GPT-4o, 15 for Gemini) but FID got worse (1.05 → 2.13): the model *over-adjusts* to the hints; candidates converge to near-duplicates. Cost: < $100 and 1.5 h per 1,000 samples [P]. |
| **RubSE** (NeurIPS 2026, <https://arxiv.org/abs/2608.24138>) | UI → code self-refinement across six VLMs | Names the core problem **"visual repair coupling"**: a local edit propagates through shared layout/style and breaks regions that already matched, so naive loops are non-monotonic and can collapse. Fix: generate candidate rubrics, pick **one prioritised repair target per round**, keep a history [P, abstract only]. |
| **VisRefiner** (<https://www.themoonlight.io/en/review/visrefiner-learning-from-visual-differences-for-screenshot-to-code-generation>) | Screenshot → code | Baseline models "often degrade during refinement turns"; training on visual differences stabilises it [S]. |
| **"See it. Say it. Sorted."** (<https://arxiv.org/abs/2508.15222>) | Sketch → SVG diagram, training-free | Critic VLM → several candidate edits → judge VLM picks. The critic gives **qualitative, relational feedback rather than numeric estimates** [P/S]. Tiny evaluation (10 sketches). |
| **ReDesign** (section 1.1) | Image → editable design | Local accept / prune / retry per step beats a serial tool agent by a wide margin in PSNR / LPIPS [P]. |
| **StarVector / OmniSVG** (<https://github.com/joanrod/star-vector>, <https://github.com/OmniSVG/OmniSVG>, both Apache-2.0 [R]) | Image → SVG code with 1B–8B models | GPU models for icons / simple graphics, no CPU figures. Not a fit: the output is paths, not live text or design semantics. **Skip.** |

On spatial precision:

- A 2025 localisation study measured GPT-4o at mIoU 0.181 against 0.773 for a dedicated detector
  (Grounding DINO 1.5); the errors were coarse (off-centre, loose boxes) and got worse on finer
  grids (<https://arxiv.org/abs/2509.21922>) [S]. Older model, synthetic benchmark — direction is
  what matters.
- Anthropic's own guidance (<https://platform.claude.com/docs/en/build-with-claude/vision-coordinates>)
  [P]: ask for **absolute pixel coordinates** (normalised 0–1000 works poorly); **pre-resize** the
  image to exactly what the model will see (standard tier 1568 px edge and 1568 visual tokens;
  high-resolution tier 2576 px and 4784 tokens; the token limit, not the edge, usually decides — a
  1920×1080 image becomes 1456×819); "small elements lose precision when an image is downscaled:
  for fine targets, crop the region of interest and send the crop"; `transformations:
  {oversized_image: "error"}` turns silent resizing into a 400; PDF pages are rasterised
  server-side at unknown sizes, so rasterise them yourself. The page ships a PHP reference
  implementation of the resize rule.

I found **no ablation** comparing side-by-side images vs an overlay vs a difference image as the
feedback format. What exists is indirect: Design2Code's observation that comparing two images is
the hard part; VASCAR's success with numeric scores plus text hints (and its over-adjustment);
"Sorted"'s preference for relational wording.

### 2.2 What to conclude

1. **Single shot is the baseline and already does most of the work** for strong models on easy
   inputs. Giving the model OCR text and boxes is a cheap, proven boost (Design2Code).
2. **A loop helps most on hard inputs and when it is constrained.** Published gains range from
   +0.3 to +15 points; unconstrained loops regress. Design rules that follow from the literature:
   - cap at **2–3 rounds**; stop as soon as the score does not improve;
   - **keep-best**: never replace the current scene with a worse-scoring one (protects against
     repair coupling);
   - **one repair target per round** (RubSE), expressed as a patch to named elements, not a
     regeneration of the whole scene;
   - feed back **numbers computed by code** (per-element IoU, dx/dy in px, ΔE, text mismatch), plus
     a short ranked list of the worst elements — the model reasons well over numbers and badly over
     "spot the 6 px difference";
   - show **crops** of the offending element (input crop next to render crop, same scale) instead
     of two full pages.
3. **Do not ask the VLM to do what code can measure.** Sub-10 px offsets, exact colours, font size
   and tracking should be solved deterministically after the VLM has produced the structure:
   - position / scale: match the rendered element against the input crop (template matching or
     phase correlation on edge maps) and apply the offset directly;
   - colour: sample from the input pixels inside the element mask (median or dominant cluster);
   - font metrics: section 4.3.
   This is my engineering recommendation derived from points above, not a result from a paper.
4. A structural trick worth testing: **numbered overlays** ("set-of-mark" prompting,
   <https://arxiv.org/abs/2310.11441> [M]) — draw the OCR boxes and candidate regions with ids on
   the image and let the VLM refer to ids instead of inventing coordinates.

Verdict: **use** structured single shot with OCR grounding + deterministic snapping; **use** a
short guarded loop behind a flag and measure its value on our own test set; **skip** open-ended
regeneration loops and SVG-code models.

---

## 3. OCR and text geometry, CPU-only

### 3.1 Candidates

| Tool | Licence, maturity [R] | Czech | Geometry | CPU cost | Integration | Verdict |
|---|---|---|---|---|---|---|
| **PaddleOCR / PP-OCRv5** <https://github.com/PaddlePaddle/PaddleOCR> | Apache-2.0; v3.7.0 2026-06-11; 92k stars | `latin_PP-OCRv5_mobile_rec` covers Czech; 84.7 % on their 3,111-image Latin set [P]. No Czech-specific figure. | Line polygons + boxes; scene-text capable (rotated, on photos) | Official CPU numbers (Xeon Gold 6271C, PaddlePaddle 3.0, threads not stated): mobile pipeline **1.75 s/image, peak RAM 2.2 GB**; server pipeline 4.34 s, 4.0 GB [P] — the server models do **not** fit our caps | Python package; heavy runtime (PaddlePaddle) | Use the *models*, not the Paddle runtime |
| **RapidOCR** <https://github.com/RapidAI/RapidOCR> | Apache-2.0; v3.10.0 2026-10-08; pushed today | Ships PP-OCRv5 incl. the Latin recogniser since v3.3 [S] | Same models; word-level boxes option exists [M] | ONNX Runtime CPU; no official RAM / speed figures — expect well below the Paddle numbers, **benchmark needed** | Python (`pip install rapidocr onnxruntime`); C++/Java/C# ports | **Use** (small Python sidecar; Python 3.13 is already in the image) |
| **ppu-paddle-ocr** <https://github.com/PT-Perkasa-Pilar-Utama/ppu-paddle-ocr> | MIT; v6.7.0 2026-11-10; 150 stars (small project) | Uses the same ONNX models | Line boxes | Author's figure: ~188 ms per receipt on an Apple M1 [S] | npm, `onnxruntime-node` (MIT, 1.31.1 [R]); developed mainly against Bun | **Use** if we want node-only; verify under node 24 |
| `@gutenye/ocr-node` | MIT; 1.4.9 2026-09-19; PP-OCRv4 only | v4 Latin | Line boxes | — | npm | Skip (older models) |
| **Tesseract 5** <https://github.com/tesseract-ocr/tesseract> | Apache-2.0; 5.5.4 2026-10-28; very mature | `ces` traineddata (fast / best). Good on clean, horizontal, high-contrast print; weak on text over photos, decorative display type, rotated or curved text [M] | **Word and character boxes, baselines, x-height / font-size estimates in hOCR** — richer geometry than PP-OCR | Light: one process, low hundreds of MB, single-threaded per page [M] | `apt install tesseract-ocr tesseract-ocr-ces`; CLI → hOCR/TSV; `tesseract.js` 7.0.0 (WASM, Apache-2.0, 2025-12 [R]) | **Use** as second opinion on clean exports and for per-word geometry |
| **docTR** <https://github.com/mindee/doctr> | Apache-2.0; v1.1.0 2026-08-21 | Multilingual vocabularies exist; Czech coverage not checked | Word boxes | PyTorch dependency (≈ 1 GB+ install) | Python | Skip — no clear advantage over PP-OCRv5 for the weight |
| **EasyOCR** <https://github.com/JaidedAI/EasyOCR> | Apache-2.0; last release v1.7.2 **2024-09-24** | `cs` supported [M] | Line/word boxes | PyTorch; slow on CPU | Python | Skip — stale |
| **Surya** <https://github.com/datalab-to/surya> | Code Apache-2.0, **weights modified OpenRAIL-M: free only for research, personal use and companies under $5M funding/revenue; otherwise paid** [P]; v0.23.0 2026-10-29 | 91-language benchmark, 74.9 % overall pass rate [P] | Line detection + layout + reading order | Now a VLM on llama.cpp: 38.9 s median per page on an **M4 Max** [P]; "photos and natural scenes are not the goal" | Python | **Skip** (speed, licence, document focus) |

### 3.2 Cloud OCR

| Service | Czech | Price | Geometry | Note |
|---|---|---|---|---|
| **Google Cloud Vision** `TEXT_DETECTION` / `DOCUMENT_TEXT_DETECTION` | Yes, listed as supported, hint `cs` [P] <https://docs.cloud.google.com/vision/docs/languages> | First 1,000 units/month free, then **$1.50 per 1,000**, $0.60 above 5M [P] <https://cloud.google.com/vision/pricing> | Block → paragraph → word → symbol polygons [M] | Strong on scene text. Best paid fallback. |
| **Azure AI Vision Read** | Yes [M] | About $1.00 per 1,000 transactions (0–1M) [S] | Line + word polygons [M] | Official price page returned placeholders today |
| **AWS Textract** `DetectDocumentText` | The FAQ now lists Czech among 40+ languages [P] <https://aws.amazon.com/textract/faqs/> | About $1.50 per 1,000 pages [S] | Line + word boxes | Document-oriented; less suited to posters |

At $1–1.50 per 1,000 images the cost is negligible next to the VLM call; the reasons to prefer
local OCR are data flow (one fewer processor of customer uploads), latency and no extra vendor.
Verdict: **maybe later**, as an optional fallback when local OCR confidence is low.

### 3.3 Is OCR geometry a real boost over VLM coordinates?

Yes, for three separate reasons:

1. **Precision.** Detectors localise far better than general VLMs (section 2.1), and Claude's docs
   themselves warn about small elements after downscaling. An OCR line box is typically within a
   few pixels; a VLM box is "roughly there".
2. **It turns guessing into measuring.** Line-box height → font size (section 4.3); line-box width
   → tracking; left / centre / right edges across lines → alignment; gaps between baselines → line
   height. None of these need a model.
3. **Grounding.** Design2Code's text-augmented prompting and ReDesign's PaddleOCR-first chain both
   show that handing the VLM the text and boxes improves the result; the VLM's job shrinks to
   grouping lines into text objects, fixing OCR misreads from context (it reads Czech diacritics
   better than any CPU OCR [M]), and assigning roles.

Recommended division of labour: **OCR owns geometry, the VLM owns the string and the semantics.**
Send the OCR output as JSON with ids; ask the VLM to return corrected text per id plus grouping.

Caveats to test on real material: diacritics in PP-OCR's Latin model (háčky and čárky on capitals,
`ů`, `ď`/`ť` with apostrophe-like carons), tiny calendar digits (upscale ×2 before OCR), white
text on photos, vertical or rotated text, and display fonts.

---

## 4. Font identification

### 4.1 What exists

| Option | Status | Verdict |
|---|---|---|
| **VLM names the font** | "Texture or Semantics?" (<https://arxiv.org/abs/2503.23768>): on 15 common fonts the best model reached about **30 %** (Claude 3.5 Sonnet, easy set), ~15 % on the hard set; few-shot and chain-of-thought did not help [P/S]. FontBench (Sep 2026, <https://edwardbenson.com/2026/09/fontbench>): best configuration 40.1 % exact match over six attributes; family is the hardest, the other attributes (category, weight, modifier, letter spacing, line height) reach 83.2 % together [P]. | **Skip** for the family name; **use** for category (serif / sans / slab / script / mono), rough weight, italic, caps — as a prior for the shortlist. |
| **WhatTheFont** (MyFonts / Monotype) | Consumer web and mobile tool; no developer API found [S]. | Skip |
| **WhatFontIs API** | Exists and was used by ReDesign for font families [P]. Terms seen only second-hand: free for personal use up to ~200 requests/day, commercial use by arrangement; vendor claims the exact font is among the top results in 77 % of tests [S]. | **Maybe later** — only as a hint for fonts the project does not have; get the commercial terms in writing first. |
| **Adobe Retype / Match Font** | In-app only (section 1.2). | Skip |
| **DeepFont descendants / open classifiers** | Storia `font-classify` (~3,000 Google Fonts; **no licence file**, last push 2024-03) [R]; `gaborcselle/font-identifier` (MIT, 2023, toy size) [R]; **Lens** by Mixfont (ResNet-18, 1,000+ families / 5,000+ variants, uses Tesseract to pick the largest word; **non-commercial licence**) [P]; **GoogleFontsBench / `Create-Inc/font-model`** (<https://arxiv.org/abs/2602.13889>: LoRA on DINOv2-B, 394 variants in 32 families, 99.0 % top-1 on *synthetic* test renders, confuses near-identical weights at low resolution; no licence on the repo) [P/R]; Collabora FasterViT-2 write-up (Nov 2025) [S]. | **Maybe later.** None is both commercially licensed and broad. If a classifier is ever wanted, training our own on renders of the fonts WBoost actually has is straightforward (synthetic data is free) — the GoogleFontsBench recipe shows a ~3 MB adapter is enough for a few hundred classes. |

### 4.2 Render-and-compare against the available fonts — the recommended path

WBoost's situation is unusual and favourable: the set of fonts that can be *used* in the output is
closed (project fonts + whatever catalogue we allow). The useful question is therefore not "what
font is this?" but "**which available font renders this string most like the crop?**" — a ranking
problem over tens of candidates, solvable by brute force.

Prior art: comparing a captured character against stored bitmaps of each font is as old as
US 6,337,924; US 11,763,583 (Adobe) renders target glyphs for candidate fonts and compares them to
glyphs from the document image [S]. I found no recent paper that evaluates this approach against
classifiers — it is folk practice (font tools tell users to "render each candidate at the same
text, size and weight") [S]. We would have to measure it ourselves.

Procedure (per text line, cached per text object):

1. Take the OCR line crop; binarise it (Otsu on the channel with the highest text / background
   contrast) and trim to the ink bounding box.
2. For each candidate font (and weight / italic): render the *same string* (VLM-corrected) in black
   on white at a size chosen so the cap height matches (4.3), trim to ink box, scale to the same
   height.
3. Score: IoU or normalised cross-correlation of the two binary images after a small alignment
   search (± a few px, ± 3 % horizontal scale); add the **ink-box aspect ratio** difference as a
   second term (width at equal height is a strong family and weight signal).
4. Rank; keep the top 3 with scores so the UI can offer alternatives.

Notes:

- Rendering can go through the existing Chromium (one page with all candidates, one screenshot) or
  node (`fontkit` 3.0.0 / `opentype.js` 2.0.0, both MIT [R], give glyph outlines and metrics, so
  the comparison can even be done on outlines without a browser).
- Longer strings discriminate better; pool evidence across all lines the VLM grouped into the same
  style.
- Letters that discriminate most: `a g R Q y t 1 7` and, for Czech, the shape of the caron and
  acute. If a string has few of them, lower the confidence.
- Cost: tens of candidates × a few lines × a tiny bitmap comparison — milliseconds each. This is
  the cheapest high-value component in the whole pipeline.

### 4.3 Size, weight, tracking, line height from geometry

All of these fall out of font metrics (`unitsPerEm`, `capHeight`, `xHeight`, `ascender`,
`descender`, advance widths — readable with fontkit / opentype.js):

- **Font size**: measure the cap height (lines with capitals or digits) or x-height (lower-case
  only) of the ink in px; `fontSize = measuredCapHeightPx / (capHeight / unitsPerEm)`. Do not use
  the OCR box height directly — it includes ascenders, descenders and padding and varies by engine.
  Tesseract's hOCR reports baseline and x-size, which helps.
- **Weight**: among the weights of the chosen family, pick the one whose render has the closest
  ink ratio (dark pixels / box area) and stem width (median horizontal run length); the
  render-and-compare score already encodes this.
- **Tracking (letter spacing)**: `letterSpacing = (measuredInkWidth − naturalInkWidth(fontSize)) /
  (glyphCount − 1)`.
- **Line height**: distance between consecutive baselines of lines in one text object.
- **Alignment**: compare variance of left edges, centres and right edges across the lines.
- **Colour**: median of input pixels under the binarised ink mask (erode by 1 px to avoid
  anti-aliasing fringe).

Verdict: **use** — render-and-compare for family / weight, metric arithmetic for size and spacing,
the VLM only for the category prior and for "this is a script logo, treat as image".

---

## 5. Segmentation and asset extraction on CPU

### 5.1 Learned segmentation

| Tool | Licence [R] | CPU reality | Verdict |
|---|---|---|---|
| **SAM 2 / 2.1** <https://github.com/facebookresearch/sam2> | Apache-2.0 | One informal Colab-CPU comparison: Hiera-Tiny 7–12 s, Small 9–11 s, Base+ 15–18 s, Large 38–47 s per image [S] | Skip on CPU |
| **SAM 3 / 3.1** <https://github.com/facebookresearch/sam3> | "SAM License" (custom — read before use); 848M params; README requires a CUDA GPU [P] | — | Skip |
| **MobileSAM** <https://github.com/ChaoningZhang/MobileSAM> | Apache-2.0 | ~4.4–6.6 s in the same comparison; NanoSAM (ResNet-18 ONNX encoder) 1.6–2.9 s [S] | **Maybe later**, prompted with VLM boxes, for "cut this object out of the photo" |
| **EfficientSAM** <https://github.com/yformer/EfficientSAM> | Apache-2.0, last push 2024-12 | Similar class to MobileSAM [S] | Maybe later (alternative) |
| **FastSAM** <https://github.com/CASIA-LMC-Lab/FastSAM> | **AGPL-3.0** (YOLOv8 base) | Fast (tens of ms) | **Skip** — licence |
| **Grounding DINO** | Apache-2.0, last push 2024 | Heavy on CPU | Skip; the VLM gives boxes |

SAM-class models answer "where exactly is the outline of this object in a photo". Most design
elements we need are not that: they are rectangles of photo, flat shapes, logos on flat backgrounds
and text — all of which classical methods handle exactly.

### 5.2 Background removal

| Tool | Licence | Notes | Verdict |
|---|---|---|---|
| **rembg** <https://github.com/danielgatis/rembg> | MIT code; v2.0.87 2026-11-06 [R]. Model licences differ per model — check the one used (U²-Net Apache-2.0, IS-Net / BiRefNet variants MIT [M]; the `bria-rmbg` option is non-commercial) | ONNX Runtime, CPU fine for u2net / isnet (≈ 1–3 s, few hundred MB [M]); BiRefNet variants are much heavier (Swin-L) | **Maybe later** — for product / person cut-outs placed on a flat background |
| **BiRefNet** <https://github.com/ZhengPeng7/BiRefNet> | MIT [R] | High quality at 1024 px, slow and RAM-hungry on CPU; lite variants exist [M] | Maybe later (lite) |
| **BRIA RMBG-2.0** <https://huggingface.co/briaai/RMBG-2.0> | **CC BY-NC 4.0 weights; commercial use only via a BRIA agreement or their API** [P] | — | **Skip** self-hosted |
| `@imgly/background-removal-js` | **AGPL-3.0** [R] | — | Skip |
| Recraft API "remove background" | $0.01 per image [P] | — | Maybe later (cloud alternative) |

### 5.3 Classical methods — the v1 workhorse

For clean exports (not photos of prints) the design is piecewise: flat or gradient fills, photos,
and anti-aliased glyphs. That structure can be read directly:

1. **Text mask** from OCR polygons, dilated a few px (plus binarisation inside the box to get the
   exact ink mask).
2. **Flat-region detection**: quantise colours (median cut or k-means in Lab at ≤ 1024 px; `sharp`
   0.35.5 gives raw pixels and stats; `colorthief` 3.5.0 / `node-vibrant` 4.0.4, MIT [R], give
   palettes), then connected components per colour. Large components with low internal variance are
   **background / shapes**; LayerD's palette-snapping result (section 1.1) says this step also
   *improves* reconstruction, not only speeds it.
3. **Photo regions**: tiles with high colour count / high local entropy / many gradient directions;
   merge neighbouring tiles; fit the bounding rectangle (photos in templates are overwhelmingly
   axis-aligned rectangles, sometimes rounded or circular masks — test the mask against rect /
   rounded-rect / ellipse by IoU).
4. **Shape fitting**: for each flat component try rectangle, rounded rectangle, ellipse, line (IoU
   of the component mask with the fitted primitive ≥ ~0.97) before falling back to tracing
   (section 6).
5. **Gradients**: inside a "flat" candidate, fit a plane per Lab channel along the best direction
   (least squares); low residual with non-zero slope ⇒ linear gradient with two sampled end
   colours; radial falloff from a centre ⇒ radial gradient. Otherwise treat as image.
6. **Logos / icons**: small-to-medium components (or clusters of them) with few colours and sharp
   edges on a flat background ⇒ cut out with alpha (background colour → transparent, un-premultiply
   edge pixels) and optionally vectorise.
7. **GrabCut** (OpenCV; `@techstark/opencv-js` 5.0.0, Apache-2.0 [R]) seeded by a VLM box is the
   classical fallback for a subject on a busy background; quality is mediocre next to learned
   matting — only as a stopgap.

The VLM then **labels and orders** these regions (numbered overlay, section 2.2) rather than
inventing them: "region 7 is a logo, 3 is the hero photo, 12–14 are decorative shapes".

Edge cases where classical methods fail and a learned model would help: JPEG-compressed inputs
(colour noise — denoise / bilateral filter before quantisation), photos with flat areas (sky),
shapes with texture, soft shadows and glows, overlapping translucent shapes.

### 5.4 Text removal / inpainting under text

Options:

- **Flat or gradient background under the text**: fill the ink mask with the fitted background —
  exact, free. This covers most headings on shapes and most calendar text.
- **Text over a photo**:
  - **LaMa** <https://github.com/advimman/lama> (Apache-2.0; last push 2025-02 [R]); ONNX exports
    exist (<https://huggingface.co/Carve/LaMa-ONNX>, Apache-2.0, fixed 512×512 input, ~200 MB fp32;
    OpenCV Zoo has an int8 variant) [P/S]. I found **no x86 CPU benchmark**; the only timing seen is
    ~0.9 s per 512² inpaint on an M1 Pro with WebGPU [S]. Expect seconds per 512² tile on 3 vCPUs
    and 1–2 GB RAM — needs measuring. Its training data (Places2) carries its own terms; one
    derivative flags research-only terms for Places365 [S] — a point for a licence check.
    IOPaint (the usual wrapper) was **archived** in 2025 [R].
  - **Cloud**: Recraft "erase region" at **$0.002** per call [P]; generic inpainting endpoints on
    Replicate / fal (GPU-seconds pricing).
  - LayerD's finding applies: LaMa-style fill beats generative inpainting, which adds objects.
- **Do nothing**: crop the photo region *with* the text in it and mark it as "replace me".

Is it worth it? For WBoost's use case the reconstruction is a **template**: the photo slot is
typically an image placeholder that users will replace, and the reconstructed text sits on top of
the original position anyway. Canva's own experience (residual artefacts, neighbouring text
disturbed) shows the erase step is where quality complaints come from. So:

- v1: **flat fill where provably flat; otherwise keep the crop and flag the layer** ("contains
  original text"). The render-vs-input comparison is unaffected, because the live text is drawn
  over the same pixels.
- Later: LaMa (or the $0.002 call) as an opt-in "clean this photo" action per layer.

Verdict: classical segmentation **use**; rembg / MobileSAM **maybe later**; LaMa **maybe later**;
SAM 2/3, FastSAM, BRIA weights **skip**.

---

## 6. Vectorisation, palette, gradients

| Tool | Licence, maturity [R] | Quality / fit | Cost | Verdict |
|---|---|---|---|---|
| **VTracer** <https://github.com/visioncortex/vtracer> | MIT (crate: MIT OR Apache-2.0); `1.0.0-alpha.4` 2026-08-29, pushed today; Rust CLI, Python wheel, WASM web app; third-party node bindings (`@neplex/vectorizer` 0.1.0, MIT, 2026-05) | Multi-colour tracing with stacked or cut-out layers, spline fitting; good on flat logos and shapes, produces many paths on noisy / JPEG input (quantise first). ReDesign uses it for shape leaves; alone it scores poorly as a whole-image reconstruction (section 1.1) — it is a leaf tool, not a pipeline. | Free, fast, CPU | **Use** |
| **potrace** <https://potrace.sourceforge.net/> | **GPL-2.0-or-later**; commercial "Potrace Professional" from Icosasoft; 1.16 is years old but stable | Excellent smooth curves, **two-colour only** (threshold first; colour needs one pass per colour) [P] | Free | Use only as a **separate CLI process** if at all (see GPL note in section 8); the npm `potrace` port is GPL-2.0 too and last published 2020 [R] |
| **Inkscape CLI** (already in the image, 1.4) | GPL (separate process) | "Trace bitmap" is potrace-based and multi-scan capable; scripting it headless is clumsy and slow to start [M] | Free | Fallback worth a quick test since it costs nothing to try |
| **autotrace** <https://github.com/autotrace/autotrace> | GPL-2.0; release 0.31.10 2024-01 | Centre-line tracing is its one distinctive feature | Free | Skip |
| `imagetracerjs` | Unlicense; last publish 2020 | Pure JS, lower quality | Free | Skip |
| **Vectorizer.ai** <https://vectorizer.ai/api> | Proprietary cloud | Widely regarded as the best automatic result on logos (clean geometry, shape fitting) [M] | 1 credit per image; 50 credits/month = 224 CZK (4.48 CZK each), 1,000 = 2,999 CZK (3.00 CZK each), 10,000 = 19,999 CZK (2.00 CZK each); previews 0.2 credit; test mode free [P] | **Maybe later** — as an explicit "improve this logo" action, not per scan |
| **Recraft vectorize** | Proprietary cloud | Good, generative flavour | **$0.01** per call [P] | Maybe later (cheaper alternative) |

Practical rules:

- Emit **primitives** (rect, rounded rect, ellipse, line, polygon with few vertices) whenever the
  fit is good — they stay editable in the canvas editor; traced paths are for logos and irregular
  shapes.
- Upscale small logos ×2–4 and quantise to their k dominant colours before tracing.
- Often the right answer for a logo is "this is the project's logo" — match the crop against the
  project's known logo assets (perceptual hash / template match) and link the real asset instead of
  tracing anything.

**Palette extraction**: k-means or median cut in Lab on a ≤ 256 px thumbnail; merge clusters closer
than ΔE00 ≈ 3; `culori` 4.0.2 (MIT) does Lab / ΔE00 [R]. Then **snap to the project palette**: if an
extracted colour is within a small ΔE of a project palette colour, bind to it — the same idea as
fonts: prefer what the project already has.

**Gradients**: as in 5.3 step 5. Report as "linear, angle, two or three stops"; anything more
complex stays an image.

---

## 7. Image similarity metrics

### 7.1 What each metric sees

| Metric | Sensitive to | Blind to / weakness | CPU / tooling |
|---|---|---|---|
| **MSE / PSNR at low resolution** (e.g. 64–256 px, after blur) | Global colour and layout, big missing blocks | Small text, fine detail | Trivial: `sharp` raw buffers, `Imagick::compareImages`, ImageMagick `compare -metric RMSE/PSNR` |
| **SSIM / MS-SSIM** | Local structure, edges, contrast | Over-penalises 1–2 px shifts of text; insensitive to pure colour shifts | ImageMagick 7 has `SSIM` / `DSSIM` metrics [M]; npm `ssim.js` 3.5.0 works but the repo is **archived**, last publish 2020 [R]; 30 lines to implement on raw buffers |
| **Pixel diff count** (`pixelmatch` 8.0.0 ISC, `odiff-bin` 4.5.0 MIT, `looks-same` 10.0.1 MIT [R]) | Exact pixel changes with anti-aliasing tolerance | Any small shift lights up everything — a regression-test tool, weak as a similarity score | Fast, node-native |
| **Perceptual hash** (ImageMagick `PHASH`) | Gross sameness | Everything fine | Trivial |
| **Edge-map IoU / chamfer distance** (Sobel or Canny, dilate by tolerance, compare) | Geometry: positions and sizes of shapes and text blocks, independent of colour | Colour, fills | Simple on raw buffers; OpenCV.js if wanted |
| **LPIPS** <https://github.com/richzhang/PerceptualSimilarity> (BSD-2-Clause) | Perceptual patch similarity | Needs a CNN (AlexNet / VGG); PyTorch or an ONNX export; CPU-feasible at 256 px (tens–hundreds of ms [M]) | Python; for the offline harness |
| **DISTS** | Structure + texture | As LPIPS | Python |
| **DreamSim** <https://github.com/ssundaram21/dreamsim> (MIT) | Mid-level similarity: layout, pose, semantics; ~96 % agreement with human 2AFC judgments on its NIGHTS set, where CLIP / DINO backbones outperform LPIPS and DISTS [P/S] | Trained on generated *photos*, not designs; ViT ensemble — heavier | Python; offline harness |
| **CLIP / DINO cosine** | "Same kind of picture" | Saturates: Design2Code's CLIP score sat at ~90 while block-match ranged widely; ReDesign's baselines differ little | Python / ONNX; not for the loop |
| **Per-element metrics** (when ground truth layers exist, or between scene JSONs) | Exactly what matters: box IoU, type match, text match, font match, colour ΔE, z-order | Need matching (Hungarian on IoU + type) | Pure code |
| **OCR round trip** | Text correctness: OCR the *render*, compare with OCR of the input (CER / word accuracy, matched by box IoU) | OCR noise on both sides | Reuses the OCR worker |
| **Colour ΔE00** | Palette fidelity per element and overall | Geometry | `culori` |

### 7.2 What correlates with "looks like the same design"

- I found **no study** that validates image-similarity metrics against human judgment specifically
  for *reconstructed* graphic designs. The nearest evidence:
  - Design2Code: human raters weight high-level layout and content over text similarity; automatic
    and human rankings do not always agree; the **element-level** metrics (block-match, text,
    position, colour) were far more discriminative than CLIP [P].
  - ReDesign and LayerD both report several metrics because none suffices: ReDesign uses L1, PSNR,
    LPIPS, panoptic quality (PQ), F1 and edit-replay SSIM; LayerD uses DTW-aligned alpha IoU + RGB
    L1 under a merge-edit budget [P].
  - Graphic-Design-Bench (<https://arxiv.org/abs/2604.04192>) uses LPIPS, SSIM and DreamSim [S].
  - "Can GPTs Evaluate Graphic Design…" (<https://arxiv.org/abs/2410.08885>): GPT-based judgments
    track human ratings better than heuristic layout metrics [S] — a VLM judge is a legitimate
    *evaluation* signal (not a loop signal).
- Working conclusion: **a composite of element-level scores is the primary metric; pixel metrics
  are guards.** A reconstruction with the right elements in the right places and the wrong font
  renders "close" by SSIM yet is wrong; one with a 3 px global shift renders "far" by SSIM yet is
  right.

### 7.3 Recommended metric sets

**In the loop (cheap, deterministic, per render):**

1. `S_global` — RMSE on a blurred 128 px thumbnail in Lab (layout + colour).
2. `S_edge` — edge-map IoU with 2 px tolerance at ~512 px (geometry).
3. `S_text` — OCR round trip: share of input OCR lines found in the render with IoU ≥ 0.5 and
   normalised edit distance ≤ 0.1.
4. Per element (the feedback payload): bbox IoU / dx, dy, dw, dh in px of the element's rendered
   mask vs its source region; ΔE00 of fill; font-fit score.
5. Residual map: tile the absolute difference (e.g. 16×16 grid), report the worst tiles with
   coordinates — points the VLM (or a human) at what is missing or extra.

**Offline evaluation harness (dev machine or CI, Python allowed):**

- everything above, plus SSIM, LPIPS, DreamSim;
- when ground truth exists (see 12.3 — our own templates rendered to PNG are free ground truth):
  element matching → precision / recall / F1 of elements by type, text exact-match rate, font
  family and weight accuracy, size error %, ΔE00, z-order agreement (Kendall τ on overlapping
  pairs);
- **editability check** in the spirit of ReDesign's edit replay: apply scripted edits (change a
  text, recolour a shape, swap an image) to both the ground truth and the reconstruction, render,
  compare;
- cost and latency per scan (VLM tokens, wall clock, peak RSS);
- a small human-rated set (e.g. 50 scans, 1–5 "would I keep this?") to check which automatic score
  actually predicts acceptance. This calibration is the only way to settle 7.2 for our material.

---

## 8. PDF input

### 8.1 Parsers

| Tool | Licence [R] | Text | Images | Vector / colours | Notes |
|---|---|---|---|---|---|
| **pdf.js** (`pdfjs-dist` 6.5.438, 2026-11-01) | **Apache-2.0** | `getTextContent()`: runs with string, transform matrix (position, size, rotation), width / height, a `fontName` key + style (family guess, ascent / descent); real PostScript font names and embedded font data via the page's common objects [M] | Image XObjects reachable through the operator list (decoded bitmaps) [M] | `getOperatorList()`: full drawing program — paths, fills, strokes, colours, clipping, transforms. Low-level: you rebuild shapes from operators yourself | Runs in node; no native deps. The natural core for a node worker. |
| **Poppler utils** | **GPL-2.0-or-later** (GPLv2 or v3) [S] | `pdftotext -bbox-layout` → XHTML with boxes for blocks, lines, words; `-tsv` same as TSV [P]; `pdffonts` lists fonts (name, type, embedded, subset) | `pdfimages -list` (size, colour space, encoding, ppi) and `-all` / `-png` to extract [M] | `pdftocairo -svg` converts a page to SVG — a quick route to paths with fills (text can become glyph outlines) [M]; `pdftoppm` / `pdftocairo -png` rasterise | Not in the image today: `apt install poppler-utils`. No font **size** in `-bbox` output (derive from box height) [M]. |
| **MuPDF** (`mutool`, `mupdf` npm 1.28.6, **PyMuPDF** 2.1) | **AGPL-3.0** or commercial (Artifex) | Best-in-class structured text: blocks → lines → spans with font name, size, colour, bbox (`mutool draw -F stext`, PyMuPDF `get_text("dict")`) [P/S] | `mutool extract`, PyMuPDF image blocks with bbox | PyMuPDF `get_drawings()` gives paths with fill / stroke colours — the most convenient vector API of all [M] | Technically the best; licence is the issue (below). |
| **pdfplumber** 0.11.10 (on pdfminer.six) | MIT | Characters with font name, size, position, **non-stroking colour** | Image boxes (not the best extractor) | `rects`, `lines`, `curves` with colours | Pure Python, slowish, permissive — the best **permissively licensed** route to chars + shapes + colours in one tool. |
| **Ghostscript** 10.05.1 (in the image) | **AGPL-3.0** or commercial (Artifex) | `-sDEVICE=txtwrite -dTextFormat=0/1` → XML-ish output with per-character / per-run positions, font name and size [M] | — | Rasterisation, colour conversion | Already used by the print export. |
| **qpdf** | Apache-2.0 | — | — | Structure inspection, decompression, page splitting | Handy for diagnostics. |

### 8.2 AGPL and a closed SaaS — why it matters

Not legal advice; the points to weigh:

- **GPL** (Poppler, potrace, autotrace, Inkscape): obligations are triggered by *distribution* of
  the software. A SaaS that only *runs* the program on its own server distributes nothing, and
  invoking a GPL command-line tool as a separate process does not make the calling application a
  derivative work under the usual reading ("mere aggregation"). Linking a GPL *library* into our
  code would still be harmless while nothing is distributed, but it constrains the future (an
  on-premise edition, shipping a Docker image to a customer).
- **AGPL** adds section 13: if you run a **modified** version and users interact with it over a
  network, you must offer those users the corresponding source of that version. Two consequences:
  - running an *unmodified* Ghostscript / `mutool` binary as a separate process is, on the text of
    the licence, not caught by section 13;
  - **linking** an AGPL library into the worker (`mupdf` npm / WASM, PyMuPDF `import`) makes the
    worker a combined work; if it is offered over the network, the argument that its whole source
    must be offered under AGPL becomes strong.
- **Artifex's position is stricter than that reading**: their licensing pages say commercial
  entities that make Ghostscript or MuPDF "available to SaaS or ASP customers" should take the
  commercial licence [S] (<https://artifex.com/licensing>). They have enforced the AGPL in court
  before (Artifex v. Hancom, 2017 [M]).
- Practical stance: keep AGPL components **out of the new feature** — pdf.js + Poppler +
  (optionally) pdfplumber cover everything needed. The existing Ghostscript use in the print
  export is a pre-existing question for the owner (unmodified binary, separate process — the
  defensible configuration — but worth a conscious decision or a quote from Artifex).

### 8.3 Deciding between the exact path and the image path

Per page:

1. `pdftotext -tsv` (or pdf.js text content): count words and the area their boxes cover.
2. `pdfimages -list`: number of images, each image's placed size vs the page.
3. Count path-painting operators (pdf.js operator list).

Classification:

- **Native** — words present, text is real (not render mode 3 / invisible), images are parts of
  the page → parse exactly.
- **Scan / flattened** — one image covering ≥ ~90 % of the page, no vector paths; text absent *or*
  present only as an invisible OCR layer (text render mode 3 over the image — typical for scanned
  PDFs with OCR) → rasterise and take the image path. The invisible OCR layer can be reused as a
  free OCR result.
- **Outlined** — no text, many filled paths, few images: text was converted to curves (very common
  in print-ready PDFs from InDesign / Illustrator). Vector shapes and images parse exactly, but
  text must come from OCR on the raster → **hybrid path**: exact geometry for images and shapes,
  image path for text.
- **Mixed** — treat per region.

Other PDF traps: fonts embedded as **subsets** with prefixed names (`ABCDEF+Montserrat-Bold` —
strip the prefix; the family name is then exact, which makes font identification trivial for
native PDFs); missing or broken `ToUnicode` maps (garbled text, common with Czech diacritics —
verify extracted text against OCR of the raster); text positioned glyph by glyph (kerning arrays —
merge runs on the same baseline); CMYK and spot colours (convert with the ICC profile already used
for print export); clipping paths and soft masks on images; transparency groups; Form XObjects
(nested content); crop box vs media box vs bleed box (the print-export code already handles page
boxes); rotated pages.

Even for native PDFs the VLM still has a job: grouping runs into text objects, roles, mapping to
calendar structure. But geometry, fonts, colours and assets are exact — so PDF, when it arrives,
should be **more** reliable than raster, and is a good source of ground truth for testing the
raster path (render the PDF to PNG, scan it, compare against the parsed structure).

Verdict: pdf.js + Poppler **use** (when PDF is in scope); pdfplumber **maybe** (if a Python sidecar
exists anyway); MuPDF family **skip** without a commercial licence.

---

## 9. PSD and IDML (design considerations only)

### 9.1 PSD

| Library | Licence, status [R] | Gives |
|---|---|---|
| **ag-psd** (npm 34.2.0, 2026-11-11) | MIT (npm) | The most complete JS reader/writer: layer tree, bounds, opacity, blend modes, masks, pixel data per layer, text layers (text, transform, style runs: font PostScript name, size, colour, tracking, leading, paragraph alignment), vector masks / shapes, layer effects as data, smart-object placement + embedded linked files. README limits: "text layers implementation is incomplete", no 3D, not all smart-filter data, and **it does not re-render** anything — no compositing of effects or text [S]. |
| **psd-tools** (PyPI v1.26.1, 2026-11-11) | MIT | Python reader with a compositor (optional extras for vector shapes, gradient / pattern fills, effects); "font rendering" and many effects unsupported; type-layer engine data exposed as dicts [S]. |
| `@webtoon/psd` | MIT, last push 2024-02 | Fast WASM-assisted reader; pixels and basic layer info, little text detail. |
| **psd.js** | MIT, npm last publish 2021 | Stale. Skip. |

Difficulties:

- **Text**: the real description lives in the "engine data" blob (font list, style runs, paragraph
  runs). Size must be multiplied by the layer's transform; reported sizes can differ from what
  Photoshop shows by the transform scale. Point text vs box text; warp; text on path.
- **Smart objects**: embedded PSB / AI / PDF / image with a transform (possibly non-affine) and
  smart filters; the embedded file may itself be layered. Linked (external) smart objects are just
  paths that will be missing.
- **Effects** (drop shadow, stroke, glow, overlays), **adjustment layers**, **blend modes**,
  **clipping masks**, layer comps: data is readable, reproducing the look is not possible in a
  simple canvas model. Fallback: every layer has rasterised pixels in the file (if saved with
  "maximise compatibility" there is also a flattened composite) — use the layer's own pixels as
  the image and keep text live only where the layer has no effects we cannot reproduce.
- Fonts are referenced by PostScript name only — same matching problem as elsewhere, but the name
  is exact.
- CMYK / 16-bit / Lab documents, large files (memory: a 300 dpi A3 PSD can exceed the worker cap
  when decoded — stream or refuse).

### 9.2 IDML

IDML is a ZIP of XML: `designmap.xml` (manifest), `Spreads/Spread_*.xml` (page items: text frames,
rectangles, ovals, polygons, groups, with `ItemTransform` matrices and path geometry),
`Stories/Story_*.xml` (text content with `ParagraphStyleRange` / `CharacterStyleRange` carrying
`AppliedFont`, `FontStyle`, `PointSize`, `FillColor`, tracking, leading), `Resources/Styles.xml`,
`Resources/Graphic.xml` (swatches, gradients, inks), `Resources/Fonts.xml`,
`Resources/Preferences.xml`, `MasterSpreads/` (<https://paged-docs.pages.dev/docs/package-anatomy/>)
[S]. Libraries: SimpleIDML (Python), php-idml (thin DOM wrapper), a Rust reader ("Paged"); all
small projects — plain XML parsing in PHP or node is realistic.

What parsing gives: exact frames, exact text with fully resolved styles (after walking the
"based on" chain of paragraph and character styles), exact swatches (CMYK / spot / tints), layers,
master pages.

Main difficulties:

- **Linked images are not in the IDML** — only a `Link` with a file URI and the frame's crop /
  transform. Embedded images are possible but rare. Realistic input is an InDesign *package* (IDML
  + `Links/` folder + `Document fonts/`) uploaded as a ZIP; otherwise image frames become
  placeholders (which, for a template, is acceptable).
- **Fonts are not included** either (names only).
- **No layout result**: IDML stores stories and frames, not line breaks. Text reflow depends on
  InDesign's composer (hyphenation, justification, optical margins, threaded frames, text wrap,
  anchored objects) — our renderer will break lines differently. A companion PDF export from the
  same document is the ground truth for positions.
- Nested transforms (group → frame → content), master-page overrides, effects (transparency, drop
  shadows), tables, and style inheritance.

### 9.3 What to keep open now

- Define **one intermediate scene format** (elements with type, geometry, style, asset reference,
  `source` and `confidence` per property) that the raster scanner, a PDF parser, a PSD reader and
  an IDML reader can all emit, and that one "scene → WBoost object" compiler consumes. Exact
  importers then set confidence 1.0 and skip the VLM geometry steps.
- Colour as a structured value (sRGB + optional CMYK / spot original) from the start.
- Font references by **PostScript name + family + style**, resolved against project fonts by one
  shared resolver (with the render-and-compare ranking as the fallback for unknown names).
- Units: store source units and DPI; do not bake pixels.

---

## 10. Photographs of physical calendars vs clean exports

What a photo adds on top of the clean-export problem: perspective, lens distortion, page curl
(spiral-bound desk calendars are rarely flat), uneven lighting and shadows, glare on coated paper,
colour cast (white balance), halftone / moiré, blur, the spiral binding and background clutter.
Every one of these breaks an assumption the CPU-only pipeline relies on (flat colours are flat,
rectangles are rectangles, sampled colours are the design's colours).

Options:

| Technique | What it fixes | Cost / status | Verdict |
|---|---|---|---|
| **4-point homography** (OpenCV `findContours` / `approxPolyDP` → `getPerspectiveTransform` → `warpPerspective`; `@techstark/opencv-js` 5.0.0 Apache-2.0 [R]; jscanify wraps the same in JS) | Perspective of a flat page | Cheap, deterministic. Automatic corner detection fails on low-contrast backgrounds — let the user drag the four corners in the UI (or let the VLM propose corners and the user confirm). | **Maybe later** (first step when photos are admitted) |
| Illumination flattening (divide by a heavily blurred copy / morphological background), white balance from the paper white | Shadows, cast | Cheap; helps OCR and quantisation; colours remain approximate | With the above |
| **Learned dewarping**: UVDoc (<https://github.com/tanguymagne/UVDoc>, MIT, last push 2024 [R]; also shipped as PaddleOCR's optional "text image unwarping" module [P]), DocTr / DocTr++, DocScanner, and a 2025 classical-plus-YOLO method (<https://arxiv.org/abs/2501.03145>) | Curl, folds | Trained on *documents* (text pages), not colourful designs; CPU cost and behaviour on calendars unverified | **Skip** for now |
| Asking the VLM to "imagine the flat design" | Everything, in principle | Then exact geometry and colour are unrecoverable by measurement; the result is a *re-creation in the style of*, which may be a perfectly good product — but a different one | Separate mode, later |

Recommendation for v1: **require clean images** (exports, screenshots, scans from a flatbed) and
say so in the upload UI. Add a cheap gate: the VLM's first response includes
`input_kind: export | scan | photo` and a quality note; classical checks back it up (is there a
page quadrilateral inside the frame? do the borders have uniform colour? blur measure via Laplacian
variance; JPEG blockiness). On `photo`: explain and offer to continue in a clearly labelled
"approximate" mode once that exists. The products surveyed in section 1.2 draw the same line.

---

## 11. Recommended minimal toolset for v1 (within ~3 CPUs and a few GB RAM)

| Job | Tool | Where it runs | RAM / CPU expectation |
|---|---|---|---|
| Decode, resize (to the VLM's exact input size), crop, raw pixels, thumbnails | `sharp` (Apache-2.0) | node worker | Small; streaming |
| OCR, primary | PP-OCRv5 **mobile** det + `latin` rec on **ONNX Runtime** — via RapidOCR (Python sidecar, Apache-2.0) *or* ppu-paddle-ocr (node, MIT). Decide by a one-day benchmark. | sidecar or node | Target < 1 GB, 1–3 s per image; **unverified** — official Paddle-runtime figure is 1.75 s / 2.2 GB peak |
| OCR, second opinion + word geometry | Tesseract 5 with `ces` (`apt`), hOCR / TSV | CLI | Low hundreds of MB |
| Understanding | Cloud VLM with structured output; image pre-resized per the vendor's rule; pixel coordinates; OCR JSON and numbered overlays in the prompt; crops for small regions | API | — |
| Region proposals | Own code: quantisation + connected components + photo / flat test + primitive fitting + gradient fit | node worker (typed arrays), ≤ 1024 px working size | Tens of MB, < 1 s |
| Fonts | Render-and-compare against project fonts; `fontkit` / `opentype.js` for metrics; render via node or the existing Chromium | node worker | Negligible |
| Colours | Pixel sampling under masks; `culori` for Lab / ΔE00; snap to project palette | node worker | Negligible |
| Vector shapes | Primitives first; **VTracer** for logos / irregular shapes | CLI / binding | Small |
| Text under photos | Flat fill when provably flat; else keep crop + flag | — | — |
| Render of the reconstruction | Existing app renderer (Gotenberg / Chromium; calendar page renderer) | existing | existing |
| Loop + evaluation metrics | Own code on raw buffers (thumbnail RMSE, SSIM, edge IoU, residual tiles), OCR round trip, per-element geometry; `pixelmatch` / `odiff` for debug diff images | node worker | Small |
| Offline evaluation extras | LPIPS, DreamSim, human ratings | dev machine / CI, Python | not on the box |

Operational notes for the shared box:

- One scan at a time per worker (queue on the existing `job_bus`); ONNX Runtime thread pool pinned
  to the cap (`intra_op_num_threads`), otherwise it grabs every core on the box.
- Hard input limits (e.g. ≤ 25 MP, ≤ 10 MB — the existing upload limit), decode at reduced size
  first.
- Keep model files (PP-OCR mobile det + rec ≈ tens of MB) in the image, not downloaded at runtime.
- Everything in the table is Apache-2.0 / MIT / ISC; no AGPL, no non-commercial weights.

Explicitly **not** in v1: SAM-family models, background-removal models, LaMa, hosted decomposition
models, font-ID APIs, cloud OCR, vectorisation APIs, learned dewarping, LPIPS in the loop.

---

## 12. Candidate pipeline designs to prototype against each other

All share stage 0 (ingest: decode, classify `export | scan | photo`, resize) and the final stage
(scene → WBoost object → render → metrics). They differ in **who proposes the structure** and
**whether there is a loop**.

### 12.1 Templates (social post / poster / flyer)

**T-A — VLM single shot, grounded and snapped ("measure after")**

1. OCR → lines with boxes and ids.
2. One VLM call: image + OCR JSON → scene (text objects referencing OCR ids with corrected
   strings, role, style guess; image regions; shapes; background; z-order), pixel coordinates.
3. Deterministic refinement: text geometry from OCR; font by render-and-compare; size / tracking
   from metrics; colours from pixels; shape boxes snapped to the nearest strong edges; palette
   snap.
4. Compile, render, score. No loop.

Cheapest (one VLM call), fastest, the baseline everything else must beat.

**T-B — T-A plus a guarded repair loop ("measure, then ask again")**

After T-A's render: compute per-element deviations and the residual tiles. If the score is under a
threshold, send the VLM a compact report (worst N elements with numbers, crops of input vs render
for those, list of unexplained residual regions) and ask for a **patch** (add / remove / modify
named elements). Apply, re-run step 3 for touched elements, render, **keep only if the composite
score improves**. Max 2–3 rounds.

Tests the central question of section 2: does the loop pay for itself on *our* inputs, and on
which class (dense flyers?) — report gain per round and regressions prevented by keep-best.

**T-C — CV-first proposals, VLM as labeller ("measure first")**

1. OCR + classical region proposals (5.3): text lines, flat regions with fitted primitives and
   gradients, photo rectangles, logo candidates — each with exact geometry and an id.
2. Draw the numbered overlay; one VLM call: "for each id: type, role, group, z-order, keep / merge
   / drop; list anything visible that has no id" (with a box for the missing items only).
3. Same deterministic refinement for fonts; compile, render, score. Optional single repair round
   for the "no id" leftovers.

Geometry never passes through the VLM, so it cannot be wrong by 6 px; the risk moves to the
proposal stage (over-segmentation on JPEG noise, photos with flat areas, soft shadows). Likely the
best on clean flat designs and the worst on photo-heavy posters — which is exactly what the
comparison should reveal. A hybrid (T-C proposals offered to T-A's VLM as hints) is the probable
end state.

### 12.2 Calendars

A calendar page is not a free composition: it is an instance of a **parametric model the app
already has** (design JSON + content; `docs/architecture/calendars.md`). The date grid is fully
determined by month, year, week start and a handful of style parameters, so reconstructing it as
42 independent text layers would be both worse and harder.

**C-A — Parameter fitting ("which calendar design is this?")**

1. OCR (digits, weekday and month names — Tesseract is strong here on clean exports) + grid
   detection by code: cluster digit boxes into rows and columns → cell pitch, origin, column
   count, week start (from weekday headers), which month / year (from the header text and the
   weekday of the 1st), week numbers present or not, how adjacent-month days are shown.
2. VLM call: image + detected grid facts → the remaining design parameters as a constrained
   schema (enumerated options of the calendar model: header position, weekday label style,
   weekend / holiday highlighting, photo area, name-day / note lines, …).
3. Deterministic: fonts by render-and-compare on header, weekday row and digits; colours by
   sampling (weekday vs weekend vs holiday digits separately); photo area crop.
4. Emit calendar **design JSON**, render with the existing headless page renderer, score. The
   score can be very strict here: every digit has a known expected position.

**C-B — C-A plus numeric optimisation of continuous parameters**

Same, then tune the continuous parameters (grid origin, pitch, font sizes, paddings) with a
derivative-free optimiser (coordinate descent or Nelder–Mead) against the edge-IoU / digit-position
error, rendering each trial. No VLM in the loop at all — renders are local and cheap, and the
problem is low-dimensional. Tests whether "render-and-compare" is better done by an optimiser than
by a VLM when the model is parametric (I expect yes).

**C-C — Free-form layers (T-A applied to a calendar page), as the control**

Shows what is lost without the parametric model (editability: changing the month should re-flow
the grid) and what is gained (designs the calendar model cannot express). Its failure cases are
the input for extending the calendar model — or for a "page background as template + calendar
grid as a smart component" hybrid.

### 12.3 Test material and protocol

- **Free ground truth**: render existing WBoost templates and calendars to PNG, scan them, compare
  the reconstruction with the original canvas JSON / calendar JSON element by element. This gives
  exact per-element labels, fonts and colours at zero labelling cost and should be the first test
  set (aim for ~50 templates and ~20 calendar pages across projects).
- **Foreign designs**: a smaller set of real customer-like inputs (exports from Canva / InDesign /
  Illustrator, JPEG at various qualities, different sizes) — no ground truth; pixel + OCR metrics
  and human ratings only. This guards against overfitting to WBoost's own rendering.
- **Degradations** of the first set: JPEG quality 60–90, down-scaling to 1080 px, slight blur —
  measures robustness cheaply.
- Report per pipeline: composite score, per-property accuracies (7.3), VLM calls and tokens, wall
  clock, peak RSS, and the share of scans a human would accept without edits / with < 2 minutes of
  edits.

---

## 13. Evaluation metrics to use (short form)

1. **Element F1** by type (text / image / shape / background) after matching by IoU — with ground
   truth.
2. **Text**: exact-match rate and CER per text object (Czech diacritics counted strictly).
3. **Geometry**: mean IoU of matched elements; median |dx|, |dy|, size error %.
4. **Typography**: family accuracy (top-1 / top-3), weight accuracy, font-size error %, alignment
   accuracy.
5. **Colour**: ΔE00 per element (median and 95th percentile); share within ΔE00 ≤ 3.
6. **Render fidelity**: thumbnail RMSE (Lab), SSIM, edge-map IoU; LPIPS and DreamSim offline.
7. **OCR round trip** on renders — the only text metric available without ground truth.
8. **Editability**: scripted edit replay (change text, recolour, replace image, move) — do the
   edits apply cleanly and does the result match the same edit on the original?
9. **Calendar-specific**: grid parameters correct (month, year, week start, columns); digit
   position error (px, relative to cell pitch); correct classification of weekend / holiday
   styling.
10. **Cost**: VLM calls and tokens per scan, seconds, peak memory.
11. **Human acceptance** on a rated subset — and its correlation with 1–9, to choose the composite
    weights.

---

## 14. Things I could not verify

- **PP-OCRv5 on ONNX Runtime with 3 vCPUs**: no trustworthy speed / RAM figures; the only official
  CPU numbers are for the Paddle runtime with unstated thread settings. Accuracy on **Czech** is
  not published separately (only "Latin" 84.7 %). RapidOCR's exact word-box option and whether its
  bundled Latin model is PP-OCRv5 mobile were not confirmed from primary docs. ppu-paddle-ocr on
  node 24 (it is developed against Bun) untested.
- **Tesseract** accuracy on Czech display type and its memory use: from experience, not measured;
  no benchmark found.
- **Cloud OCR prices**: Google confirmed from the official table; Azure Read (~$1 / 1,000) and
  Textract (~$1.50 / 1,000) only from secondary sources; Azure's Czech support and box
  granularity from memory. Textract's Czech support comes from a summary of the AWS FAQ page —
  historically Textract supported only six Western languages, so re-check before relying on it.
- **WhatFontIs API** terms, price and accuracy (only third-party descriptions; the 77 % figure is
  the vendor's claim). **WhatTheFont**: found no API, which is not proof that none exists.
- **Render-and-compare font matching**: no published accuracy; my expectation that it beats
  classifiers within a closed font set is untested.
- **LaMa on x86 CPU**: no timing or memory numbers found; licence status of weights with respect
  to Places2 training data not resolved.
- **SAM-family CPU timings** come from one informal blog comparison on Colab; Ultralytics' table
  was ambiguous. SAM 3's licence text was not read.
- **rembg model licences** per model and CPU timings: from memory.
- **VTracer**: status of an official npm / WASM package for server use (third-party bindings only
  were seen); output quality on our logo material untested. Vectorizer.ai quality claim is
  reputation, not a benchmark.
- **ImageMagick `compare -metric SSIM`** availability: from memory; and the CLI is not in the
  container anyway. Whether `Imagick::compareImages` exposes SSIM in the installed build was not
  checked.
- **Feedback format for VLM loops** (side-by-side vs overlay vs difference image vs numbers): no
  controlled comparison found. RubSE's per-round numbers and its exact feedback format were not
  read (abstract only). VisRefiner only via a third-party review.
- **Several 2026 items were seen only through one source**: ReDesign's project page / code
  release; Canva Magic Layers' plan availability and regions; Adobe "Layered Image Editing"
  shipping status; Lovart's pricing; FontBench's model list; Kittl and Recraft having no
  layer-decomposition feature (absence of evidence).
- **LayerDecomp**: could not locate the paper itself.
- **Set-of-mark prompting**, **Artifex v. Hancom**, pdf.js / Poppler / Ghostscript `txtwrite` /
  PyMuPDF / pdfplumber output details, and the PSD / IDML internals in section 9 are from memory
  and general knowledge; only licences, versions and the cited pages were checked today.
- **The AGPL / GPL reading** in 8.2 is a summary of the licence texts and common interpretation,
  not legal advice; Artifex states a stricter position for SaaS.
- **Current Claude / OpenAI pricing and model line-up** were not looked up; the only per-scan cost
  figure is ReDesign's ~$0.027 with Gemini / GPT-5 mini over ~29 tool calls.
- **Qwen-Image-Layered hosted pricing** and latency (Replicate listing seen, not read).
- No metric has been validated against human judgment for reconstructed designs; section 7.2 is
  inference from adjacent literature.
