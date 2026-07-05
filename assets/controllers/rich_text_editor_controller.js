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
 * compositionstart/end), paste forced to plain text (newlines flattened),
 * Enter blocked (no newlines in fill values), runs-snapshot undo stack
 * (programmatic re-renders kill native undo), maxLength enforced on the
 * PLAIN text length in code points (PHP mb_strlen parity).
 */
export default class extends Controller {
    static targets = ["editor", "counter", "fontSelect", "bold", "italic", "underline", "colorInput"];
    static values = {
        inputId: String,
        maxLength: { type: Number, default: 0 },
        uppercase: { type: Boolean, default: false },
        runs: { type: Array, default: [] },
        designFont: { type: String, default: "" },
        fonts: { type: Array, default: [] },
        colors: { type: Array, default: [] },
    };

    connect() {
        this.runs = this._module().normalize(this.runsValue);
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

    /** Block Enter (fill values carry no newlines — parity with the plain
     *  textarea's blockEnter) and handle undo/redo + B/I/U shortcuts. */
    keydown(event) {
        if (event.key === "Enter") {
            event.preventDefault();
            this.dispatch("commit", { detail: { inputId: this.inputIdValue } });
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
     *  runs model), newlines flattened, inserted with the style of the run at
     *  the caret so mid-run pastes look seamless. */
    paste(event) {
        event.preventDefault();
        const raw = (event.clipboardData || window.clipboardData)?.getData("text/plain") || "";
        const text = raw.replace(/[\r\n]+/g, " ");
        if (text === "") return;

        const module = this._module();
        const selection = this._selectionOffsets() || this._endOffsets();
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

        let runs = module.normalize([...before, inserted, ...after]);
        if (this.maxLengthValue) {
            runs = module.truncate(runs, this.maxLengthValue);
        }
        this.runs = runs;

        this._render();
        const caret = Math.min(selection.start + text.length, module.plainText(this.runs).length);
        this._restoreSelection({ start: caret, end: caret });
        this._sync();
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

    _render() {
        const editor = this.editorTarget;
        editor.textContent = "";
        this.runs.forEach((run) => {
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
            editor.appendChild(span);
        });
    }

    /** Whitelist parser: only span[data-rt-run] attributes carry style; any
     *  markup the browser (or an extension) sneaks in is flattened to the
     *  nearest styled ancestor. <br>/block breaks contribute nothing — the
     *  value has no newlines. */
    _parseDom() {
        const runs = [];
        const walk = (node, inherited) => {
            if (node.nodeType === Node.TEXT_NODE) {
                runs.push({ ...inherited, text: node.data });
                return;
            }
            if (node.nodeType !== Node.ELEMENT_NODE) return;
            let style = inherited;
            if (node.dataset && node.dataset.rtRun !== undefined) {
                style = {
                    fontFamily: node.dataset.font || null,
                    color: node.dataset.color || null,
                    underline: node.dataset.underline === "1",
                };
            }
            node.childNodes.forEach((child) => walk(child, style));
        };
        this.editorTarget.childNodes.forEach((node) => walk(node, { fontFamily: null, color: null, underline: false }));
        return this._module().normalize(runs);
    }

    /** Parse the (browser-mutated) DOM into runs + enforce maxLength + sync.
     *  The DOM itself is only rebuilt when truncation actually bit. */
    _commitDomState() {
        const module = this._module();
        let runs = this._parseDom();
        let truncated = false;
        if (this.maxLengthValue && module.codePointLength(module.plainText(runs)) > this.maxLengthValue) {
            runs = module.truncate(runs, this.maxLengthValue);
            truncated = true;
        }

        const changed = JSON.stringify(runs) !== JSON.stringify(this.runs);
        if (changed) {
            this._pushUndo(this.runs, true);
            this.runs = runs;
        }

        if (truncated) {
            this._render();
            this._restoreSelection(this._endOffsets());
        }

        this._sync();
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

    _offsetAt(container, offset) {
        let total = 0;
        const walker = document.createTreeWalker(this.editorTarget, NodeFilter.SHOW_TEXT);
        let node;
        while ((node = walker.nextNode())) {
            if (node === container) {
                return total + offset;
            }
            total += node.data.length;
        }
        // Element container (e.g. the editor itself): count text inside the
        // first `offset` children.
        if (container.nodeType === Node.ELEMENT_NODE) {
            let sum = 0;
            for (let i = 0; i < Math.min(offset, container.childNodes.length); i += 1) {
                sum += container.childNodes[i].textContent.length;
            }
            let prefix = 0;
            const prefixWalker = document.createTreeWalker(this.editorTarget, NodeFilter.SHOW_TEXT);
            let prefixNode;
            while ((prefixNode = prefixWalker.nextNode())) {
                if (container.contains(prefixNode)) break;
                prefix += prefixNode.data.length;
            }
            return container === this.editorTarget ? sum : prefix + sum;
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

    _positionAt(offset) {
        const walker = document.createTreeWalker(this.editorTarget, NodeFilter.SHOW_TEXT);
        let remaining = offset;
        let node;
        let last = null;
        while ((node = walker.nextNode())) {
            last = node;
            if (remaining <= node.data.length) {
                return { node, offset: remaining };
            }
            remaining -= node.data.length;
        }
        if (last) return { node: last, offset: last.data.length };
        return { node: this.editorTarget, offset: 0 };
    }

    _endOffsets() {
        const total = this._module().plainText(this.runs).length;
        return { start: total, end: total };
    }

    // --- undo -----------------------------------------------------------------

    _pushUndo(state = this.runs, coalesce = false) {
        const snapshot = JSON.stringify(state);
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

    _undo() {
        const snapshot = this._undoStack.pop();
        if (snapshot === undefined) return;
        this._redoStack.push(JSON.stringify(this.runs));
        this.runs = this._module().normalize(JSON.parse(snapshot));
        this._render();
        this._restoreSelection(this._endOffsets());
        this._sync();
    }

    _redo() {
        const snapshot = this._redoStack.pop();
        if (snapshot === undefined) return;
        this._undoStack.push(JSON.stringify(this.runs));
        this.runs = this._module().normalize(JSON.parse(snapshot));
        this._render();
        this._restoreSelection(this._endOffsets());
        this._sync();
    }

    // --- mirror sync + toolbar state -------------------------------------------

    _sync() {
        const module = this._module();
        const plain = module.plainText(this.runs);
        // Plain string while unstyled: untouched values keep today's wire
        // shape, and the envelope only exists where styling does.
        const mirrorValue = module.isStyled(this.runs) && plain !== ""
            ? JSON.stringify({ runs: this.runs })
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
    }

    _module() {
        return window.WBoostRichTextRuns;
    }
}
