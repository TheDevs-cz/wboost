/**
 * Client-side text ECHO for the fill surfaces — paints the echo-capable text
 * inputs onto a transparent Fabric canvas layered over the text-transparent
 * BASE render, so typing repaints locally in milliseconds while the lazily
 * debounced SERVER render stays the displayed truth at rest.
 *
 * Deliberately a dependency-free CLASSIC script (window global), the
 * container_layout.js pattern: loaded via <script src> by the fill pages and
 * inlined verbatim into the Gotenberg golden-test harness — the harness
 * screenshot proving echo ↔ export parity must execute EXACTLY this code.
 *
 * Parity contracts mirrored here (single sources quoted):
 *  - Value resolution mirrors ResolveTextOverrides: truncation counts Unicode
 *    CODE POINTS (Array.from ≙ mb_substr), uppercase is locale-independent
 *    toUpperCase() ≙ mb_strtoupper, both via the shared WBoostRichTextRuns
 *    module for rich runs (truncate-then-uppercase per run).
 *  - Override application mirrors the render template exactly: clear per-char
 *    styles BEFORE setting text (Fabric never remaps the styles grid), rich
 *    runs via WBoostRichTextRuns.applyToTextbox (grapheme-indexed,
 *    styles-before-text, explicit initDimensions).
 *  - Container reflow runs the same two-phase WBoostContainerLayout pass the
 *    headless render runs: phase A ONCE at init over the pristine designed
 *    state (enlivened objects still hold their designed text + per-char
 *    styles), phase B re-run per update — the prepared snapshot anchors on
 *    designed geometry and re-measures live heights, so repeated applies
 *    cannot drift. Only "clean" trees (every member echo-capable) are handed
 *    in — the server's EchoCapableTextInputs guarantees nothing baked moves.
 *
 * A value carrying LIST lines is not drawable here (block stacks are a settle
 * concern — see EchoCapableTextInputs); resolveValue surfaces `lines` so the
 * caller can detect it, but capable inputs never allow lists, so in practice
 * lines stay null.
 */
(function () {
    'use strict';

    /**
     * Mirror of ResolveTextOverrides' per-input value pipeline for the echo:
     * raw mirror string in, { text, runs, lines } out. `runs` is non-null only
     * for a rich input whose (possibly envelope) value carries styling; `text`
     * is always the plain, truncated, uppercased projection.
     *
     * def: { richText, lists, maxLength, uppercase }
     */
    function resolveValue(raw, def) {
        const runsModule = window.WBoostRichTextRuns;

        if (def.richText && runsModule) {
            let runs = null;
            let lines = null;
            const trimmed = String(raw).trim();
            if (trimmed.startsWith('{')) {
                try {
                    const decoded = JSON.parse(trimmed);
                    if (decoded && Array.isArray(decoded.runs)) {
                        runs = runsModule.normalize(decoded.runs);
                        if (def.lists && Array.isArray(decoded.lines)) {
                            lines = decoded.lines;
                        }
                    }
                } catch (err) {
                    // Not an envelope — plain text below.
                }
            }
            if (runs === null) {
                runs = raw === '' ? [] : runsModule.normalize([{ text: String(raw) }]);
            }
            if (Number.isInteger(def.maxLength) && def.maxLength > 0) {
                runs = runsModule.truncate(runs, def.maxLength);
            }
            if (def.uppercase) {
                runs = runsModule.upper(runs);
            }
            const blocksModule = window.WBoostRichTextBlocks;
            lines = lines && blocksModule ? blocksModule.normalizeLines(runs, lines) : null;
            return {
                text: runsModule.plainText(runs),
                runs: runsModule.isStyled(runs) || lines ? runs : null,
                lines: lines,
            };
        }

        // Plain path. Code points, not UTF-16 units: 'a💡b'.slice(0, 2) would
        // cut inside the emoji where mb_substr would not.
        let value = String(raw);
        if (Number.isInteger(def.maxLength) && def.maxLength > 0) {
            const points = Array.from(value);
            if (points.length > def.maxLength) {
                value = points.slice(0, def.maxLength).join('');
            }
        }
        if (def.uppercase) {
            value = value.toUpperCase();
        }
        return { text: value, runs: null, lines: null };
    }

    /**
     * Build an echo painter over `canvasEl`.
     *
     * options: {
     *   fabric,                 // the Fabric namespace (page global or import)
     *   canvasEl,               // <canvas> element to own
     *   width, height,          // variant canvas px
     *   canvasHeight,           // reflow page-bottom bound (= height)
     *   objects,                // [{ inputId, object }] designed textbox JSON, stack order
     *   containers,             // clean-tree container definitions (toArray shape)
     * }
     *
     * Returns a Promise of:
     *   update(values)          // { inputId: { raw, hidden } } → repaint
     *   setDisplayWidth(px)     // raster + zoom to the displayed preview width
     *   clear()                 // paint nothing (settle mode)
     *   dispose()
     */
    function create(options) {
        const fabric = options.fabric || window.fabric;
        const layoutModule = window.WBoostContainerLayout;
        if (!fabric || !options.canvasEl) {
            return Promise.reject(new Error('fill_text_echo: fabric + canvasEl required'));
        }

        if (window.WBoostFabricBreakWord) {
            window.WBoostFabricBreakWord.enable(fabric.Textbox);
        }

        const canvas = new fabric.StaticCanvas(options.canvasEl, {
            width: options.width,
            height: options.height,
            renderOnAddRemove: false,
        });

        const sources = (options.objects || []).map(function (entry) {
            return entry.object;
        });

        return fabric.util.enlivenObjects(sources).then(function (instances) {
            const boxes = [];
            instances.forEach(function (box, i) {
                const entry = options.objects[i];
                // Fabric v7 does not hydrate custom props — restore the one
                // the layout engine addresses members by.
                box.inputId = entry.inputId;
                box.selectable = false;
                box.evented = false;
                boxes.push({
                    inputId: entry.inputId,
                    box: box,
                    designedVisible: box.visible !== false,
                });
                canvas.add(box);
            });

            // Phase A over the pristine designed state — once. The snapshot
            // anchors on designed tops/gaps; every applyFabricLayout re-run
            // measures the CURRENT (overridden) heights against those anchors,
            // so repeated applies recompute absolutely and cannot drift.
            const prepared = layoutModule && (options.containers || []).length > 0
                ? layoutModule.prepareFabricContainers(
                    boxes.map(function (entry) { return entry.box; }),
                    options.containers,
                    { canvasHeight: options.canvasHeight || options.height },
                )
                : null;

            let displayWidth = null;

            function applyDisplay() {
                if (!displayWidth || displayWidth <= 0 || !options.width) return;
                const scale = Math.min(1, displayWidth / options.width);
                canvas.setDimensions({ width: options.width * scale, height: options.height * scale });
                canvas.setZoom(scale);
            }

            const api = {
                update: function (values) {
                    const runsModule = window.WBoostRichTextRuns;

                    // Overrides — the render template's exact order per input:
                    // per-char styles cleared BEFORE the text lands, rich runs
                    // through the shared module. Every echo input always has a
                    // value entry (mirrors are seeded), so every box is set.
                    boxes.forEach(function (entry) {
                        const value = values ? values[entry.inputId] : null;
                        if (!value || !value.resolved) {
                            entry.box.set({ visible: false });
                            return;
                        }
                        if (value.resolved.runs && runsModule) {
                            runsModule.applyToTextbox(entry.box, value.resolved.runs, fabric.util.stylesFromArray);
                        } else {
                            if (runsModule) runsModule.clearStyles(entry.box);
                            entry.box.set({ text: value.resolved.text });
                            if (typeof entry.box.initDimensions === 'function') {
                                entry.box.initDimensions();
                            }
                        }
                        entry.box.set({ visible: entry.designedVisible && !value.hidden });
                    });

                    if (prepared) {
                        layoutModule.applyFabricLayout(prepared);
                    }

                    applyDisplay();
                    canvas.renderAll();
                },
                clear: function () {
                    boxes.forEach(function (entry) { entry.box.set({ visible: false }); });
                    canvas.renderAll();
                },
                setDisplayWidth: function (px) {
                    displayWidth = px;
                    applyDisplay();
                    canvas.renderAll();
                },
                repaint: function () {
                    canvas.renderAll();
                },
                dispose: function () {
                    canvas.dispose();
                },
            };

            return api;
        });
    }

    window.WBoostFillTextEcho = {
        resolveValue: resolveValue,
        create: create,
    };
})();
