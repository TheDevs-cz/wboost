import { Controller } from "@hotwired/stimulus";

/**
 * Fill-page WYSIWYG for ONE rich-text placeholder. Hand-rolled contenteditable
 * (deliberately no editor library — importmap vendoring history) whose source
 * of truth is the "runs" model shared with the server
 * (src/Value/RichText.php) and the render pipeline
 * (assets/editor/rich_text_runs.js, loaded as a classic script → the
 * window.WBoostRichTextRuns global).
 *
 * Data flow: user edits the contenteditable → DOM is parsed back into runs
 * (whitelist parser: only our own span[data-rt-run] carry style, everything
 * else inherits) → the runs are written into the input's Live-bound mirror
 * field as a {"runs":[...]} JSON envelope (or a PLAIN string while unstyled,
 * so untouched values keep today's wire shape) → the mirror's `input` event
 * drives the debounced server re-render, and a bubbling
 * `rich-text-editor:changed` event lets the overlay recompute container
 * reflow instantly.
 *
 * UX contract (see the feature plan): toolbar actions apply to the SELECTION
 * when one exists, and to the WHOLE text when the caret is collapsed —
 * micro-texts make per-character styling with a collapsed caret a confusing
 * no-op otherwise. B/I buttons switch between font FACES of the same family
 * (faces are standalone families in this app), driven by the face metadata in
 * fontsValue; the face dropdown remains the source of truth.
 *
 * Reliability guards: IME composition (no DOM rebuild between
 * compositionstart/end), paste forced to plain text (external formatting
 * stripped; newlines KEPT — multi-line values are supported), Enter / Shift+Enter
 * insert a newline (rendered via the editor's white-space: pre-wrap, and by
 * Fabric's /\r?\n/ line split in the export), runs-snapshot undo stack
 * (programmatic re-renders kill native undo), maxLength enforced on the
 * PLAIN text length in code points (PHP mb_strlen parity).
 */
export default class extends Controller {
    static targets = ["editor", "counter", "fontSelect", "bold", "italic", "underline", "colorInput", "ulButton", "olButton"];
    static values = {
        inputId: String,
        maxLength: { type: Number, default: 0 },
        uppercase: { type: Boolean, default: false },
        runs: { type: Array, default: [] },
        // Per-line list types ('p'|'ul'|'ol') seeding + `lists` gate — only a
        // lists-enabled input renders the list buttons and emits `lines`.
        lines: { type: Array, default: [] },
        lists: { type: Boolean, default: false },
        designFont: { type: String, default: "" },
        fonts: { type: Array, default: [] },
        colors: { type: Array, default: [] },
    };

    connect() {
        this.runs = this._module().normalize(this.runsValue);
        this.lineTypes = this._fitTypes(this.linesValue);
        this._undoStack = [];
        this._redoStack = [];
        this._composing = false;

        this._render();
        this._updateCounter();
        this._updateToolbarState();

        this._onSelectionChange = () => this._updateToolbarState();
        document.addEventListener("selectionchange", this._onSelectionChange);
    }

    disconnect() {
        document.removeEventListener("selectionchange", this._onSelectionChange);
    }

    // --- editor events --------------------------------------------------------

    /** Enter / Shift+Enter insert a newline (multi-line fill values). Inside
     *  a list the new line inherits the item type; Enter on an EMPTY item
     *  exits the list instead (converts the line to a plain paragraph — the
     *  standard editor convention). Handles undo/redo + B/I/U shortcuts. */
    keydown(event) {
        if (event.key === "Enter") {
            event.preventDefault();
            if (this.listsValue) {
                const sel = this._selectionOffsets();
                if (sel && sel.start === sel.end) {
                    const li = this._lineIndexAt(sel.start);
                    const type = this.lineTypes[li];
                    if ((type === "ul" || type === "ol") && this._lineText(li) === "") {
                        this._pushUndo();
                        this.lineTypes[li] = "p";
                        this._render();
                        this._restoreSelection(sel);
                        this._sync();
                        this._updateToolbarState();
                        return;
                    }
                }
            }
            this._insertText("\n");
            return;
        }

        const meta = event.metaKey || event.ctrlKey;
        if (!meta) return;

        const key = event.key.toLowerCase();
        if (key === "z") {
            event.preventDefault();
            if (event.shiftKey) {
                this._redo();
            } else {
                this._undo();
            }
        } else if (key === "b") {
            event.preventDefault();
            this.toggleBold();
        } else if (key === "i") {
            event.preventDefault();
            this.toggleItalic();
        } else if (key === "u") {
            event.preventDefault();
            this.toggleUnderline();
        }
    }

    /** Hard cap typing at maxLength BEFORE the DOM mutates (mirrors the plain
     *  textarea's maxlength attribute). Deleting/selection-replacing stays
     *  allowed — only pure insertions at the limit are blocked. */
    beforeInput(event) {
        if (!this.maxLengthValue) return;
        if (!event.inputType || !event.inputType.startsWith("insert")) return;

        const selection = this._selectionOffsets();
        const selectionLength = selection ? selection.end - selection.start : 0;
        const inserted = typeof event.data === "string" ? event.data.length : 0;
        const plainLength = this._module().codePointLength(this._module().plainText(this.runs));

        if (plainLength - selectionLength + (inserted || 1) > this.maxLengthValue && inserted !== 0) {
            event.preventDefault();
        }
    }

    /** Parse the mutated DOM back into runs. NON-destructive during typing —
     *  the DOM is left exactly as the browser built it (rebuilding on every
     *  keystroke would break the caret, IME and autocorrect); a rebuild only
     *  happens on toolbar actions / paste / undo. */
    input() {
        if (this._composing) return;
        this._commitDomState();
    }

    compositionStart() {
        this._composing = true;
    }

    compositionEnd() {
        this._composing = false;
        this._commitDomState();
    }

    /** Paste as PLAIN text only (external formatting must never leak into the
     *  runs model), newlines KEPT (CRLF/CR canonicalized to LF), inserted with
     *  the style of the run at the caret so mid-run pastes look seamless. */
    paste(event) {
        event.preventDefault();
        const raw = (event.clipboardData || window.clipboardData)?.getData("text/plain") || "";
        this._insertText(raw.replace(/\r\n?/g, "\n"));
    }

    /** Insert plain text (possibly multi-line) at the caret / over the
     *  selection, styled like the run at the insertion point. Shared by Enter
     *  (a single "\n") and paste. Respects maxLength: nothing is inserted once
     *  the value is full, and an over-long insert is truncated to fit. */
    _insertText(text) {
        if (text === "") return;

        const module = this._module();
        const selection = this._selectionOffsets() || this._endOffsets();

        if (this.maxLengthValue) {
            const plainLength = module.codePointLength(module.plainText(this.runs));
            const selectionLength = selection.end - selection.start;
            if (plainLength - selectionLength >= this.maxLengthValue) return;
        }

        this._pushUndo();

        const plain = module.plainText(this.runs);
        const before = this._sliceRuns(0, selection.start);
        const after = this._sliceRuns(selection.end, plain.length);
        const styleSource = before.length > 0 ? before[before.length - 1] : (after[0] || {});
        const inserted = {
            text,
            fontFamily: styleSource.fontFamily || null,
            color: styleSource.color || null,
            underline: styleSource.underline === true,
        };

        // Line-type bookkeeping BEFORE the runs change: lines added by the
        // inserted text inherit the type of the line the caret sits on
        // (Enter inside a list item = next item); lines swallowed by a
        // multi-line selection replacement drop out.
        const liStart = this._lineIndexAt(Math.min(selection.start, selection.end), plain);
        const liEnd = this._lineIndexAt(Math.max(selection.start, selection.end), plain);
        const insertedLines = (text.match(/\n/g) || []).length;
        const inherit = this.lineTypes[liStart] || "p";
        this.lineTypes = [
            ...this.lineTypes.slice(0, liStart + 1),
            ...Array(insertedLines).fill(inherit),
            ...this.lineTypes.slice(liEnd + 1),
        ];

        let runs = module.normalize([...before, inserted, ...after]);
        if (this.maxLengthValue) {
            runs = module.truncate(runs, this.maxLengthValue);
        }
        this.runs = runs;
        this.lineTypes = this._fitTypes(this.lineTypes);

        this._render();
        const caret = Math.min(selection.start + text.length, module.plainText(this.runs).length);
        this._restoreSelection({ start: caret, end: caret });
        this._sync();
    }

    // --- lists ------------------------------------------------------------------

    toggleBulletList() {
        this._toggleList("ul");
    }

    toggleNumberedList() {
        this._toggleList("ol");
    }

    /** Flip the lines covered by the selection (whole text for a collapsed
     *  caret on empty value; the caret's line otherwise) to the list type —
     *  or back to plain paragraphs when they all already are that type. */
    _toggleList(type) {
        if (!this.listsValue) return;
        const module = this._module();
        const plain = module.plainText(this.runs);
        const offsets = this._selectionOffsets();
        const start = offsets ? Math.min(offsets.start, offsets.end) : plain.length;
        const end = offsets ? Math.max(offsets.start, offsets.end) : plain.length;
        const liStart = this._lineIndexAt(start, plain);
        const liEnd = this._lineIndexAt(end, plain);

        this._pushUndo();
        let allAlready = true;
        for (let i = liStart; i <= liEnd; i += 1) {
            if (this.lineTypes[i] !== type) allAlready = false;
        }
        for (let i = liStart; i <= liEnd; i += 1) {
            this.lineTypes[i] = allAlready ? "p" : type;
        }

        this._render();
        this._restoreSelection(offsets || this._endOffsets());
        this._sync();
        this._updateToolbarState();
    }

    /** 0-based line index of a plain-text offset. */
    _lineIndexAt(offset, plain = null) {
        const text = plain === null ? this._module().plainText(this.runs) : plain;
        return (text.slice(0, Math.max(0, offset)).match(/\n/g) || []).length;
    }

    _lineText(index) {
        return this._module().plainText(this.runs).split("\n")[index] || "";
    }

    /** Slice/pad line types to the current text's line count ('p' fill);
     *  non-list values collapse to all-'p' when lists are disabled. */
    _fitTypes(types) {
        const count = this._module().plainText(this.runs).split("\n").length;
        const result = [];
        for (let i = 0; i < count; i += 1) {
            const t = Array.isArray(types) ? types[i] : "p";
            result.push(this.listsValue && (t === "ul" || t === "ol") ? t : "p");
        }
        return result;
    }

    // --- toolbar actions ------------------------------------------------------

    applyFont(event) {
        const family = event.target.value || null;
        this._applyStyle((run) => ({ ...run, fontFamily: family }));
    }

    toggleBold() {
        this._toggleFace("bold");
    }

    toggleItalic() {
        this._toggleFace("italic");
    }

    toggleUnderline() {
        const range = this._effectiveRange();
        if (!range) return;
        const allUnderlined = this._rangeRuns(range).every((run) => run.underline === true);
        this._applyStyle((run) => ({ ...run, underline: !allUnderlined }));
    }

    /** Swatch click (data-rich-text-editor-color-param) or "default" chip (empty string → inherit). */
    pickColor(event) {
        const color = event.params?.color || null;
        this._applyStyle((run) => ({ ...run, color }));
    }

    /** Free color picker. */
    applyCustomColor(event) {
        const color = event.target.value || null;
        this._applyStyle((run) => ({ ...run, color }));
    }

    /** "Výchozí styl" — the escape hatch: drop ALL formatting, keep the text. */
    clearFormatting() {
        const module = this._module();
        this._pushUndo();
        this.runs = module.normalize([{ text: module.plainText(this.runs) }]);
        this._render();
        this._restoreSelection(this._endOffsets());
        this._sync();
    }

    // --- runs surgery ---------------------------------------------------------

    /** Apply a per-run patch to the selection (collapsed caret = whole text). */
    _applyStyle(patch) {
        const range = this._effectiveRange();
        if (!range) return;

        this._pushUndo();
        const module = this._module();
        const plain = module.plainText(this.runs);
        const before = this._sliceRuns(0, range.start);
        const middle = this._sliceRuns(range.start, range.end).map(patch);
        const after = this._sliceRuns(range.end, plain.length);
        this.runs = module.normalize([...before, ...middle, ...after]);

        this._render();
        this._restoreSelection(range.hadSelection ? range : this._endOffsets());
        this._sync();
        this._updateToolbarState();
    }

    /** Swap each selected run's EFFECTIVE face for its family's bold/italic
     *  counterpart (faces are standalone families; metadata comes from the
     *  uploaded font files and is treated as best-effort — runs whose family
     *  has no matching face are left untouched). */
    _toggleFace(axis) {
        const range = this._effectiveRange();
        if (!range) return;

        const shouldEnable = !this._rangeRuns(range).every((run) => this._faceMatches(this._effectiveFamily(run), axis));

        this._applyStyle((run) => {
            const target = this._mappedFace(this._effectiveFamily(run), axis, shouldEnable);
            return target === undefined ? run : { ...run, fontFamily: target };
        });
    }

    _effectiveFamily(run) {
        return run.fontFamily || this.designFontValue || null;
    }

    _fontOption(family) {
        return this.fontsValue.find((option) => option.family === family) || null;
    }

    _faceMatches(family, axis) {
        const option = this._fontOption(family);
        if (!option) return false;
        // `style` is FontLib-parsed subfamily metadata — loose strings like
        // "Bold Italic" are common, so match by substring, never equality.
        return axis === "bold" ? option.weight >= 600 : (option.style || "").toLowerCase().includes("italic");
    }

    /**
     * The face `family` should switch to when toggling `axis` on/off, keeping
     * the OTHER axis as-is. undefined = no candidate (leave the run alone).
     */
    _mappedFace(family, axis, enable) {
        const current = this._fontOption(family);
        if (!current) return undefined;

        const isItalic = (option) => (option.style || "").toLowerCase().includes("italic");
        const isBold = (option) => option.weight >= 600;
        const siblings = this.fontsValue.filter((option) => option.fontName === current.fontName);

        const wantBold = axis === "bold" ? enable : isBold(current);
        const wantItalic = axis === "italic" ? enable : isItalic(current);

        const candidates = siblings.filter((option) => isBold(option) === wantBold && isItalic(option) === wantItalic);
        if (candidates.length === 0) return undefined;

        // Closest weight to the canonical target (700 bold / 400 regular).
        const targetWeight = wantBold ? 700 : 400;
        candidates.sort((a, b) => Math.abs(a.weight - targetWeight) - Math.abs(b.weight - targetWeight));
        return candidates[0].family;
    }

    /** Runs (fragments) covered by [start, end) in plain-text offsets. */
    _rangeRuns(range) {
        return this._sliceRuns(range.start, range.end);
    }

    /** Slice the runs model to the [start, end) plain-text window, splitting
     *  boundary runs. UTF-16 offsets — used only inside this editor, where the
     *  selection APIs speak the same unit. */
    _sliceRuns(start, end) {
        const result = [];
        let offset = 0;
        for (const run of this.runs) {
            const runStart = offset;
            const runEnd = offset + run.text.length;
            offset = runEnd;
            if (runEnd <= start || runStart >= end) continue;
            result.push({
                ...run,
                text: run.text.slice(Math.max(0, start - runStart), Math.min(run.text.length, end - runStart)),
            });
        }
        return result;
    }

    // --- DOM <-> runs ---------------------------------------------------------

    /** LINE-DIV rendering: one div.rt-line per "\n"-separated line (the
     *  browser's own contenteditable block structure), carrying its list type
     *  as data-type — CSS draws the bullets, JS stamps ordinal numbers for
     *  'ol'. Newlines therefore live BETWEEN line divs, not in text nodes;
     *  the selection helpers add one implicit "\n" per line boundary. An
     *  empty line renders a placeholder <br> so it keeps a caret-able line
     *  box (this replaces the old trailing-<br> hack of the flat model). */
    _render() {
        const editor = this.editorTarget;
        editor.textContent = "";
        const blocksModule = window.WBoostRichTextBlocks;
        const lines = blocksModule ? blocksModule.splitLines(this.runs) : [this.runs.slice()];
        let ordinal = 0;

        lines.forEach((lineRuns, index) => {
            const type = this.lineTypes[index] || "p";
            const lineEl = document.createElement("div");
            lineEl.className = "rt-line";
            lineEl.dataset.type = type;
            if (type === "ol") {
                ordinal = this.lineTypes[index - 1] === "ol" ? ordinal + 1 : 1;
                lineEl.dataset.number = `${ordinal}.`;
            }

            lineRuns.forEach((run) => {
                const span = document.createElement("span");
                span.dataset.rtRun = "1";
                if (run.fontFamily) {
                    span.dataset.font = run.fontFamily;
                    span.style.fontFamily = `"${run.fontFamily}"`;
                }
                if (run.color) {
                    span.dataset.color = run.color;
                    span.style.color = run.color;
                }
                if (run.underline === true) {
                    span.dataset.underline = "1";
                    span.style.textDecoration = "underline";
                }
                span.textContent = run.text;
                lineEl.appendChild(span);
            });

            if (lineRuns.length === 0) {
                lineEl.appendChild(document.createElement("br"));
            }
            editor.appendChild(lineEl);
        });
    }

    /** The editor's line elements (every element child is a line — browsers
     *  clone the .rt-line class + dataset when they split a line natively). */
    _lineElements() {
        return Array.from(this.editorTarget.children).filter((el) => el.nodeType === Node.ELEMENT_NODE);
    }

    /** Whitelist parser over the line-div structure: only span[data-rt-run]
     *  attributes carry style, every line div contributes its data-type, and
     *  lines are re-joined with "\n" separator runs (styled like the
     *  preceding run so normalization merges them away). A line-internal <br>
     *  is the empty-line placeholder and contributes nothing. */
    _parseDom() {
        const runs = [];
        const lineTypes = [];
        let started = false;

        const pushSeparator = () => {
            const prev = runs[runs.length - 1] || {};
            runs.push({
                text: "\n",
                fontFamily: prev.fontFamily || null,
                color: prev.color || null,
                underline: prev.underline === true,
            });
        };

        const walkInline = (node, inherited) => {
            if (node.nodeType === Node.TEXT_NODE) {
                runs.push({ ...inherited, text: node.data });
                return;
            }
            if (node.nodeType !== Node.ELEMENT_NODE || node.tagName === "BR") return;
            let style = inherited;
            if (node.dataset && node.dataset.rtRun !== undefined) {
                style = {
                    fontFamily: node.dataset.font || null,
                    color: node.dataset.color || null,
                    underline: node.dataset.underline === "1",
                };
            }
            node.childNodes.forEach((child) => walkInline(child, style));
        };

        const defaultStyle = { fontFamily: null, color: null, underline: false };
        this.editorTarget.childNodes.forEach((node) => {
            const isBlock = node.nodeType === Node.ELEMENT_NODE
                && (node.tagName === "DIV" || node.tagName === "P");
            if (isBlock) {
                if (started) pushSeparator();
                started = true;
                const type = node.dataset ? node.dataset.type : null;
                lineTypes.push(this.listsValue && (type === "ul" || type === "ol") ? type : "p");
                walkInline(node, defaultStyle);
            } else {
                // Stray top-level inline content — treat as (part of) the
                // current line rather than losing the text.
                if (!started) {
                    started = true;
                    lineTypes.push("p");
                }
                walkInline(node, defaultStyle);
            }
        });
        if (!started) {
            lineTypes.push("p");
        }

        return { runs: this._module().normalize(runs), lineTypes };
    }

    /** Parse the (browser-mutated) DOM into runs + line types, enforce
     *  maxLength + sync. The DOM is only rebuilt when truncation bit. */
    _commitDomState() {
        const module = this._module();
        const parsed = this._parseDom();
        let runs = parsed.runs;
        let truncated = false;
        if (this.maxLengthValue && module.codePointLength(module.plainText(runs)) > this.maxLengthValue) {
            runs = module.truncate(runs, this.maxLengthValue);
            truncated = true;
        }

        const changed = JSON.stringify(runs) !== JSON.stringify(this.runs)
            || JSON.stringify(parsed.lineTypes) !== JSON.stringify(this.lineTypes);
        if (changed) {
            this._pushUndo({ runs: this.runs, lines: this.lineTypes }, true);
            this.runs = runs;
            this.lineTypes = parsed.lineTypes;
            this.lineTypes = this._fitTypes(this.lineTypes);
        }

        if (truncated) {
            this._render();
            this._restoreSelection(this._endOffsets());
        }

        this._sync();
        this._renumberLists();
    }

    /** Keep 'ol' ordinal labels correct after native edits without a full
     *  re-render (which would break the caret mid-typing). */
    _renumberLists() {
        if (!this.listsValue) return;
        let ordinal = 0;
        let previousType = null;
        this._lineElements().forEach((lineEl) => {
            const type = lineEl.dataset ? lineEl.dataset.type : null;
            if (type === "ol") {
                ordinal = previousType === "ol" ? ordinal + 1 : 1;
                const label = `${ordinal}.`;
                if (lineEl.dataset.number !== label) {
                    lineEl.dataset.number = label;
                }
            }
            previousType = type;
        });
    }

    // --- selection ------------------------------------------------------------

    /** Current selection as plain-text offsets, or null when it isn't inside
     *  this editor. */
    _selectionOffsets() {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) return null;
        const range = selection.getRangeAt(0);
        if (!this.editorTarget.contains(range.startContainer) || !this.editorTarget.contains(range.endContainer)) {
            return null;
        }
        return {
            start: this._offsetAt(range.startContainer, range.startOffset),
            end: this._offsetAt(range.endContainer, range.endOffset),
        };
    }

    /** The range a toolbar action applies to: the selection when one exists,
     *  the WHOLE text for a collapsed caret. Null when the value is empty. */
    _effectiveRange() {
        const module = this._module();
        const total = module.plainText(this.runs).length;
        if (total === 0) return null;

        const offsets = this._selectionOffsets();
        if (!offsets || offsets.start === offsets.end) {
            return { start: 0, end: total, hadSelection: false };
        }
        return {
            start: Math.min(offsets.start, offsets.end),
            end: Math.max(offsets.start, offsets.end),
            hadSelection: true,
        };
    }

    /** Plain-text offset of a DOM position. Line-div aware: each boundary
     *  between line divs counts as one implicit "\n" (newlines no longer
     *  exist as characters in the DOM). */
    _offsetAt(container, offset) {
        const lines = this._lineElements();

        // Editor-level position: before child `offset` = start of that line.
        if (container === this.editorTarget) {
            let sum = 0;
            for (let i = 0; i < Math.min(offset, lines.length); i += 1) {
                if (i > 0) sum += 1;
                sum += lines[i].textContent.length;
            }
            if (offset > 0 && offset < lines.length) sum += 1;
            return sum;
        }

        let total = 0;
        for (let li = 0; li < lines.length; li += 1) {
            if (li > 0) total += 1;
            const lineEl = lines[li];

            if (container === lineEl) {
                let sum = 0;
                for (let i = 0; i < Math.min(offset, lineEl.childNodes.length); i += 1) {
                    sum += lineEl.childNodes[i].textContent.length;
                }
                return total + sum;
            }

            if (lineEl.contains(container)) {
                const walker = document.createTreeWalker(lineEl, NodeFilter.SHOW_TEXT);
                let node;
                while ((node = walker.nextNode())) {
                    if (node === container) {
                        return total + offset;
                    }
                    if (container.nodeType === Node.ELEMENT_NODE && container.contains(node)) {
                        // Element container inside the line — approximate to
                        // its start; toolbar actions only need line accuracy.
                        return total;
                    }
                    total += node.data.length;
                }
                return total;
            }

            total += lineEl.textContent.length;
        }
        return total;
    }

    _restoreSelection(range) {
        const selection = window.getSelection();
        if (!selection) return;
        const domRange = document.createRange();
        const startPos = this._positionAt(range.start);
        const endPos = this._positionAt(range.end);
        domRange.setStart(startPos.node, startPos.offset);
        domRange.setEnd(endPos.node, endPos.offset);
        selection.removeAllRanges();
        selection.addRange(domRange);
        this.editorTarget.focus();
    }

    /** Inverse of _offsetAt over the line-div structure. */
    _positionAt(offset) {
        const lines = this._lineElements();
        let remaining = offset;
        let lastText = null;
        let lastLine = null;

        for (let li = 0; li < lines.length; li += 1) {
            if (li > 0) {
                remaining -= 1; // the implicit newline between line divs
                if (remaining < 0) {
                    // Boundary itself → start of this line.
                    remaining = 0;
                }
            }
            const lineEl = lines[li];
            lastLine = lineEl;
            const walker = document.createTreeWalker(lineEl, NodeFilter.SHOW_TEXT);
            let node;
            let hasText = false;
            while ((node = walker.nextNode())) {
                hasText = true;
                lastText = node;
                if (remaining <= node.data.length) {
                    return { node, offset: remaining };
                }
                remaining -= node.data.length;
            }
            if (!hasText && remaining === 0) {
                return { node: lineEl, offset: 0 };
            }
        }

        if (lastText) return { node: lastText, offset: lastText.data.length };
        if (lastLine) return { node: lastLine, offset: 0 };
        return { node: this.editorTarget, offset: 0 };
    }

    _endOffsets() {
        const total = this._module().plainText(this.runs).length;
        return { start: total, end: total };
    }

    // --- undo -----------------------------------------------------------------

    _pushUndo(state = null, coalesce = false) {
        const snapshot = JSON.stringify(state || { runs: this.runs, lines: this.lineTypes });
        const top = this._undoStack[this._undoStack.length - 1];
        if (top === snapshot) return;
        // Typing produces one parse per keystroke; coalesce bursts so undo
        // steps back over words, not characters.
        if (coalesce && this._lastTypingPush && Date.now() - this._lastTypingPush < 700) {
            return;
        }
        if (coalesce) this._lastTypingPush = Date.now();
        this._undoStack.push(snapshot);
        if (this._undoStack.length > 50) this._undoStack.shift();
        this._redoStack = [];
    }

    _applySnapshot(snapshot) {
        const state = JSON.parse(snapshot);
        // Pre-lists snapshots were a bare runs array.
        this.runs = this._module().normalize(Array.isArray(state) ? state : state.runs);
        this.lineTypes = this._fitTypes(Array.isArray(state) ? null : state.lines);
        this._render();
        this._restoreSelection(this._endOffsets());
        this._sync();
    }

    _undo() {
        const snapshot = this._undoStack.pop();
        if (snapshot === undefined) return;
        this._redoStack.push(JSON.stringify({ runs: this.runs, lines: this.lineTypes }));
        this._applySnapshot(snapshot);
    }

    _redo() {
        const snapshot = this._redoStack.pop();
        if (snapshot === undefined) return;
        this._undoStack.push(JSON.stringify({ runs: this.runs, lines: this.lineTypes }));
        this._applySnapshot(snapshot);
    }

    // --- mirror sync + toolbar state -------------------------------------------

    _sync() {
        const module = this._module();
        const plain = module.plainText(this.runs);
        // The mirror is an <input type="text">, whose value sanitization
        // STRIPS literal newlines — so a multi-line value must travel as the
        // {"runs":[...]} envelope (JSON escapes "\n" to two chars) or the line
        // breaks silently vanish before they reach the server. Plain string is
        // kept only for single-line, unstyled values (today's wire shape for
        // untouched inputs); styling OR a newline promotes to the envelope,
        // which the server's rich-input envelope detection unwraps (an unstyled
        // one degrades to a plain override that preserves the newline).
        const hasLists = this.listsValue && this.lineTypes.some((t) => t !== "p");
        const needsEnvelope = plain !== "" && (module.isStyled(this.runs) || plain.indexOf("\n") !== -1 || hasLists);
        const mirrorValue = needsEnvelope
            ? JSON.stringify(hasLists ? { runs: this.runs, lines: this.lineTypes } : { runs: this.runs })
            : plain;

        const mirror = document.querySelector(`[data-text-mirror="${this.inputIdValue}"]`);
        if (mirror && mirror.value !== mirrorValue) {
            mirror.value = mirrorValue;
            mirror.dispatchEvent(new Event("input", { bubbles: true }));
        }

        this._updateCounter(plain);
        this.dispatch("changed", { detail: { inputId: this.inputIdValue } });
    }

    _updateCounter(plain = null) {
        if (!this.hasCounterTarget) return;
        const module = this._module();
        const text = plain === null ? module.plainText(this.runs) : plain;
        const length = module.codePointLength(text);
        this.counterTarget.textContent = this.maxLengthValue
            ? `${length} / ${this.maxLengthValue} znaků`
            : `${length} znaků`;
    }

    /** Reflect the effective style at the selection in the toolbar (pressed
     *  B/I/U, face dropdown, color chips). */
    _updateToolbarState() {
        const offsets = this._selectionOffsets();
        if (!offsets && document.activeElement !== this.editorTarget) return;

        const range = this._effectiveRange();
        const runs = range ? this._rangeRuns(range) : [];

        if (this.hasBoldTarget) {
            this.boldTarget.classList.toggle("active", runs.length > 0 && runs.every((run) => this._faceMatches(this._effectiveFamily(run), "bold")));
        }
        if (this.hasItalicTarget) {
            this.italicTarget.classList.toggle("active", runs.length > 0 && runs.every((run) => this._faceMatches(this._effectiveFamily(run), "italic")));
        }
        if (this.hasUnderlineTarget) {
            this.underlineTarget.classList.toggle("active", runs.length > 0 && runs.every((run) => run.underline === true));
        }
        if (this.hasFontSelectTarget) {
            const families = new Set(runs.map((run) => run.fontFamily || ""));
            this.fontSelectTarget.value = families.size === 1 ? [...families][0] : "";
        }

        if (this.listsValue && (this.hasUlButtonTarget || this.hasOlButtonTarget)) {
            const offsets = this._selectionOffsets();
            const plain = this._module().plainText(this.runs);
            const start = offsets ? Math.min(offsets.start, offsets.end) : plain.length;
            const end = offsets ? Math.max(offsets.start, offsets.end) : plain.length;
            const liStart = this._lineIndexAt(start, plain);
            const liEnd = this._lineIndexAt(end, plain);
            const covered = this.lineTypes.slice(liStart, liEnd + 1);
            if (this.hasUlButtonTarget) {
                this.ulButtonTarget.classList.toggle("active", covered.length > 0 && covered.every((t) => t === "ul"));
            }
            if (this.hasOlButtonTarget) {
                this.olButtonTarget.classList.toggle("active", covered.length > 0 && covered.every((t) => t === "ol"));
            }
        }
    }

    _module() {
        return window.WBoostRichTextRuns;
    }
}
