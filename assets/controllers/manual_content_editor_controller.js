/* stimulusFetch: 'lazy' */
import { Controller } from "@hotwired/stimulus";
import Sortable from "sortablejs";
import { withMoveCursor } from "./sort_drag_cursor.js";
import {
    Schema,
    EditorState,
    EditorView,
    keymap,
    baseKeymap,
    toggleMark,
    chainCommands,
    exitCode,
    history,
    undo,
    redo,
    wrapInList,
    splitListItem,
    liftListItem,
    sinkListItem,
    inputRules,
    wrappingInputRule,
    undoInputRule,
} from "../vendor-pinned/prosemirror.bundle.js";

/**
 * Manual content page editor (intro page, P1 of
 * docs/plans/2026-11-manual-content-pages.md; architecture in
 * docs/architecture/manuals.md "Manual content pages").
 *
 * State = `{name, layout, segments: [{verticalAlign, elements: [...]}]}` —
 * the very document SaveManualIntroPageController accepts, one segment per
 * slot of the chosen MockupPageLayout. Elements: heading {level 1–3, text},
 * richText {doc: ProseMirror JSON}, image {path, galleryImageId, fit, alt}.
 *
 * The stage reuses the public render's classes (`.mcp-grid` / `.mcp-seg` /
 * `.mcp-h*` / `.mcp-rt` / `.mcp-img-*`, app.css) so it looks like the manual.
 * Every element node is built ONCE and only MOVED afterwards (SortableJS
 * between segments, layout switches) — a ProseMirror view lives on its DOM
 * node, re-creating it would lose the caret and the undo history.
 *
 * The rich-text schema mirrors src/Value/ManualContent/RichTextDoc.php
 * (node + mark allow-list; the server re-validates and renders it through
 * RichTextHtmlRenderer). Marks are declared in RichTextDoc::MARK_ORDER so
 * `toJSON()` emits them in the server's canonical order. Paste keeps only
 * structure + bold/italic/underline/safe links — font / colour marks have no
 * parse rules, so pasted styling never smuggles a foreign font or colour in.
 */

const ALLOWED_HREF = /^(https?:\/\/[^\s/?#\\]+[^\s]*|mailto:[^@\s]+@[^@\s]+)$/i;

const cssString = (value) => '"' + String(value).replace(/["\\\n\r]/g, (c) => "\\" + c.charCodeAt(0).toString(16) + " ") + '"';

const schema = new Schema({
    nodes: {
        doc: { content: "block+" },
        paragraph: { group: "block", content: "inline*", parseDOM: [{ tag: "p" }], toDOM: () => ["p", 0] },
        bullet_list: { group: "block", content: "list_item+", parseDOM: [{ tag: "ul" }], toDOM: () => ["ul", 0] },
        ordered_list: {
            group: "block",
            content: "list_item+",
            attrs: { order: { default: 1 } },
            parseDOM: [{ tag: "ol", getAttrs: (dom) => ({ order: dom.hasAttribute("start") ? Math.max(1, parseInt(dom.getAttribute("start"), 10) || 1) : 1 }) }],
            toDOM: (node) => (node.attrs.order === 1 ? ["ol", 0] : ["ol", { start: node.attrs.order }, 0]),
        },
        list_item: { content: "paragraph block*", defining: true, parseDOM: [{ tag: "li" }], toDOM: () => ["li", 0] },
        text: { group: "inline" },
        hard_break: { inline: true, group: "inline", selectable: false, parseDOM: [{ tag: "br" }], toDOM: () => ["br"] },
    },
    // Order = RichTextDoc::MARK_ORDER (rank → JSON order + nesting).
    marks: {
        link: {
            attrs: { href: {} },
            inclusive: false,
            parseDOM: [{ tag: "a[href]", getAttrs: (dom) => (ALLOWED_HREF.test(dom.getAttribute("href") || "") ? { href: dom.getAttribute("href") } : false) }],
            toDOM: (mark) => ["a", { href: mark.attrs.href, rel: "noopener noreferrer", target: "_blank" }, 0],
        },
        bold: { parseDOM: [{ tag: "strong" }, { tag: "b" }], toDOM: () => ["strong", 0] },
        italic: { parseDOM: [{ tag: "em" }, { tag: "i" }], toDOM: () => ["em", 0] },
        underline: { parseDOM: [{ tag: "u" }], toDOM: () => ["u", 0] },
        font: { attrs: { family: {} }, toDOM: (mark) => ["span", { style: "font-family: " + cssString(mark.attrs.family) }, 0] },
        color: { attrs: { color: {} }, toDOM: (mark) => ["span", { style: "color: " + mark.attrs.color }, 0] },
    },
});

const KIND_LABEL = { heading: "Nadpis", richText: "Text", image: "Obrázek" };

/** JSON with sorted keys — dirty tracking must not care about key order. */
function stableStringify(value) {
    if (Array.isArray(value)) return "[" + value.map(stableStringify).join(",") + "]";
    if (value && typeof value === "object") {
        return "{" + Object.keys(value).sort().map((k) => JSON.stringify(k) + ":" + stableStringify(value[k])).join(",") + "}";
    }
    return JSON.stringify(value);
}

function newId() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") return window.crypto.randomUUID();
    return "el-" + Date.now().toString(36) + "-" + Math.random().toString(36).slice(2, 10);
}

function el(tag, className, attrs = {}) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    for (const [key, value] of Object.entries(attrs)) {
        if (key === "text") node.textContent = value;
        else if (key === "html") node.innerHTML = value;
        else node.setAttribute(key, value);
    }
    return node;
}

function icon(name, size = "s16") {
    return el("span", `i i-${name} ${size}`, { "aria-hidden": "true" });
}

export default class extends Controller {
    static targets = [
        "name", "layoutRadio", "stage", "toolbar", "toolbarHint", "fontSelect", "colorList",
        "linkBox", "linkInput", "linkError", "fileInput", "preview", "status", "dirtyBadge", "saveButton",
    ];

    static values = {
        state: Object,
        geometry: Object,
        fonts: Array,
        colors: Array,
        saveUrl: String,
        uploadUrl: String,
        galleryModal: String,
        csrf: String,
        maxFileSize: Number,
        maxPayloadBytes: Number,
    };

    connect() {
        this.items = new Map(); // id → { type, node, data, view? }
        this.segments = []; // [{ verticalAlign, node, list, sortable }]
        this.activeView = null;
        this.saving = false;
        this.uploadTarget = null;
        this.galleryTarget = null;

        // The project gallery picker (content pages P3): the shared modal
        // dispatches `asset-selected` on window; only a pick this editor ARMED
        // (openGallery) is consumed.
        this.onAssetSelected = (event) => this.assetSelected(event);
        window.addEventListener("asset-selected", this.onAssetSelected);

        this.buildToolbar();
        this.load(this.stateValue);

        this.onBeforeUnload = (event) => {
            if (!this.isDirty()) return;
            event.preventDefault();
            event.returnValue = "";
        };
        window.addEventListener("beforeunload", this.onBeforeUnload);

        this.onKeydown = (event) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "s") {
                event.preventDefault();
                this.save();
            }
        };
        document.addEventListener("keydown", this.onKeydown);
    }

    disconnect() {
        window.removeEventListener("beforeunload", this.onBeforeUnload);
        window.removeEventListener("asset-selected", this.onAssetSelected);
        document.removeEventListener("keydown", this.onKeydown);
        this.destroyStage();
    }

    // ---- state ---------------------------------------------------------------

    load(state) {
        this.destroyStage();
        this.layout = state.layout;
        this.nameTarget.value = state.name || "";
        this.layoutRadioTargets.forEach((radio) => { radio.checked = radio.value === this.layout; });

        const slots = this.geometryValue[this.layout] || [];
        const segments = slots.map((_, index) => state.segments[index] || { verticalAlign: "top", elements: [] });

        segments.forEach((segment) => {
            const seg = this.createSegment(segment.verticalAlign || "top");
            (segment.elements || []).forEach((data) => {
                const item = this.createItem(data);
                if (item) seg.list.appendChild(item.node);
            });
        });

        this.placeSegments();
        this.savedSnapshot = stableStringify(this.serialize());
        this.refreshDirty();
    }

    serialize() {
        const segments = this.segments.map((segment) => ({
            verticalAlign: segment.verticalAlign,
            elements: Array.from(segment.list.children)
                .map((node) => this.items.get(node.dataset.id))
                .filter(Boolean)
                .map((item) => this.elementJson(item)),
        }));

        return { name: this.nameTarget.value.trim(), layout: this.layout, segments };
    }

    elementJson(item) {
        const d = item.data;
        if (item.type === "heading") return { id: d.id, type: "heading", level: d.level, text: (d.text || "").trim() };
        if (item.type === "richText") return { id: d.id, type: "richText", doc: item.view.state.doc.toJSON() };
        return { id: d.id, type: "image", path: d.path, galleryImageId: d.galleryImageId ?? null, fit: d.fit, alt: (d.alt || "").trim() };
    }

    isDirty() {
        return stableStringify(this.serialize()) !== this.savedSnapshot;
    }

    refreshDirty() {
        const dirty = this.isDirty();
        this.dirtyBadgeTarget.classList.toggle("d-none", !dirty);
        if (dirty) this.statusTarget.textContent = "";
    }

    // ---- stage -----------------------------------------------------------------

    destroyStage() {
        for (const segment of this.segments || []) segment.sortable?.destroy();
        for (const item of this.items?.values() || []) item.view?.destroy();
        this.segments = [];
        this.items = new Map();
        if (this.hasStageTarget) this.stageTarget.innerHTML = "";
        this.setActiveView(null);
    }

    createSegment(verticalAlign) {
        const node = el("div", `mcp-seg mce-seg mcp-va-${verticalAlign}`);
        const list = el("div", "mce-els");
        const foot = el("div", "mce-seg-foot");

        const add = el("div", "dropdown mce-add");
        const addButton = el("button", "btn btn-ghost xs mce-add-btn", { type: "button", "data-bs-toggle": "dropdown", "aria-expanded": "false" });
        addButton.append(icon("plus"), document.createTextNode("Přidat prvek"));
        const menu = el("div", "dropdown-menu");
        [["heading", "type", "Nadpis"], ["richText", "list", "Text"], ["imageGallery", "image-search", "Obrázek z galerie"], ["image", "upload", "Nahrát obrázek"]].forEach(([type, iconName, label]) => {
            const item = el("button", "dropdown-item", { type: "button" });
            item.append(icon(iconName), document.createTextNode(label));
            item.addEventListener("click", () => this.addElement(segment, type));
            menu.appendChild(item);
        });
        add.append(addButton, menu);

        const align = el("div", "mce-align", { role: "group", "aria-label": "Svislé zarovnání pole" });
        [["top", "alv-t", "Nahoru"], ["center", "alv-m", "Na střed"], ["bottom", "alv-b", "Dolů"]].forEach(([value, iconName, label]) => {
            const button = el("button", "btn btn-ghost icon xs", { type: "button", title: `Zarovnat ${label.toLowerCase()}`, "aria-label": `Zarovnat ${label.toLowerCase()}`, "data-align": value });
            button.appendChild(icon(iconName));
            button.addEventListener("click", () => this.setAlign(segment, value));
            align.appendChild(button);
        });

        foot.append(add, align);
        node.append(list, foot);

        const segment = { verticalAlign, node, list, alignGroup: align, sortable: null };
        segment.sortable = Sortable.create(list, withMoveCursor({
            group: "mce-elements",
            handle: ".mce-grip",
            animation: 150,
            // Same reason as dragula_controller.js: the fallback clone follows
            // the pointer while the real node stays in flow (native DnD mode
            // reused-mirror-class bug, see memory 2026-07-04).
            forceFallback: true,
            ghostClass: "mce-ghost",
            dragClass: "mce-drag",
            onEnd: () => this.changed(),
        }));
        this.segments.push(segment);
        this.paintAlign(segment);

        return segment;
    }

    /** (Re)attaches the segment nodes to the stage in the layout's grid slots. */
    placeSegments() {
        const slots = this.geometryValue[this.layout] || [];
        this.stageTarget.innerHTML = "";
        this.segments.forEach((segment, index) => {
            const slot = slots[index];
            segment.node.style.gridColumn = `${slot.column} / span ${slot.columnSpan}`;
            segment.node.style.gridRow = `${slot.row} / span ${slot.rowSpan}`;
            segment.node.classList.toggle("mcp-rows-1", slot.rowSpan === 1);
            segment.node.classList.toggle("mcp-rows-2", slot.rowSpan === 2);
            segment.slot = slot;
            this.stageTarget.appendChild(segment.node);
        });
        this.items.forEach((item) => { if (item.type === "image") this.paintImage(item); });
    }

    setAlign(segment, value) {
        segment.verticalAlign = value;
        this.paintAlign(segment);
        this.changed();
    }

    paintAlign(segment) {
        segment.node.classList.remove("mcp-va-top", "mcp-va-center", "mcp-va-bottom");
        segment.node.classList.add(`mcp-va-${segment.verticalAlign}`);
        segment.alignGroup.querySelectorAll("[data-align]").forEach((button) => {
            button.setAttribute("aria-pressed", String(button.dataset.align === segment.verticalAlign));
        });
    }

    layoutChanged(event) {
        const layout = event.target.value;
        const slots = this.geometryValue[layout];
        if (!slots || layout === this.layout) return;

        // Fewer slots: the extra segments' elements move into the last kept
        // one (the server's ManualContentPage::fitSegments does the same).
        while (this.segments.length > slots.length) {
            const removed = this.segments.pop();
            const last = this.segments[this.segments.length - 1];
            Array.from(removed.list.children).forEach((node) => last.list.appendChild(node));
            removed.sortable.destroy();
        }
        while (this.segments.length < slots.length) this.createSegment("top");

        this.layout = layout;
        this.placeSegments();
        this.changed();
    }

    nameChanged() {
        this.changed();
    }

    changed() {
        this.refreshDirty();
    }

    // ---- elements --------------------------------------------------------------

    createItem(data) {
        if (!data || !KIND_LABEL[data.type]) return null;

        const id = data.id || newId();
        const node = el("div", `mce-el mce-el-${data.type}`, { "data-id": id });
        const bar = el("div", "mce-el-bar");
        const grip = el("span", "mce-grip", { title: "Přetažením změníte pořadí", "aria-hidden": "true" });
        grip.appendChild(icon("grip", "s14"));
        const kind = el("span", "mce-el-kind", { text: KIND_LABEL[data.type] });
        const tools = el("span", "mce-el-tools");
        const remove = el("button", "btn btn-ghost icon xs mce-el-del", { type: "button", title: "Odebrat prvek", "aria-label": `Odebrat prvek ${KIND_LABEL[data.type]}` });
        remove.appendChild(icon("trash", "s14"));
        bar.append(grip, kind, tools, remove);
        const body = el("div", "mce-el-body");
        node.append(bar, body);

        const item = { type: data.type, node, body, tools, data: { ...data, id } };
        remove.addEventListener("click", () => this.removeItem(item));

        if (data.type === "heading") this.buildHeading(item);
        else if (data.type === "richText") this.buildRichText(item);
        else this.buildImage(item);

        this.items.set(id, item);
        return item;
    }

    addElement(segment, type) {
        if (type === "imageGallery") {
            this.openGallery({ segment, item: null });
            return;
        }

        if (type === "image") {
            this.uploadTarget = { segment, item: null };
            this.fileInputTarget.value = "";
            this.fileInputTarget.click();
            return;
        }

        const data = type === "heading"
            ? { id: newId(), type, level: 2, text: "" }
            : { id: newId(), type, doc: { type: "doc", content: [{ type: "paragraph" }] } };
        const item = this.createItem(data);
        segment.list.appendChild(item.node);
        this.changed();
        if (type === "heading") item.input.focus();
        else item.view.focus();
    }

    removeItem(item) {
        if (item.view) {
            if (this.activeView === item.view) this.setActiveView(null);
            item.view.destroy();
        }
        item.node.remove();
        this.items.delete(item.data.id);
        this.changed();
    }

    buildHeading(item) {
        const levels = el("span", "seg soft mce-levels", { role: "group", "aria-label": "Úroveň nadpisu" });
        [1, 2, 3].forEach((level) => {
            const button = el("button", "", { type: "button", "data-level": String(level), title: `Nadpis ${level}`, text: `H${level}` });
            button.addEventListener("click", () => {
                item.data.level = level;
                this.paintHeading(item);
                this.changed();
            });
            levels.appendChild(button);
        });
        item.tools.appendChild(levels);
        item.levels = levels;

        const input = el("textarea", "mcp-h mce-heading", { rows: "1", placeholder: "Nadpis", "aria-label": "Text nadpisu", maxlength: "300" });
        input.value = item.data.text || "";
        input.addEventListener("keydown", (event) => { if (event.key === "Enter") event.preventDefault(); });
        input.addEventListener("input", () => {
            input.value = input.value.replace(/[\r\n]+/g, " ");
            item.data.text = input.value;
            this.autosize(input);
            this.changed();
        });
        item.body.appendChild(input);
        item.input = input;
        this.paintHeading(item);
        requestAnimationFrame(() => this.autosize(input));
    }

    paintHeading(item) {
        item.input.classList.remove("mcp-h1", "mcp-h2", "mcp-h3");
        item.input.classList.add(`mcp-h${item.data.level}`);
        item.levels.querySelectorAll("button").forEach((button) => {
            const on = Number(button.dataset.level) === item.data.level;
            button.classList.toggle("on", on);
            button.setAttribute("aria-pressed", String(on));
        });
        this.autosize(item.input);
    }

    autosize(textarea) {
        textarea.style.height = "auto";
        textarea.style.height = textarea.scrollHeight + "px";
    }

    buildRichText(item) {
        const mount = el("div", "mcp-rt mce-rt");
        item.body.appendChild(mount);

        let doc;
        try {
            doc = schema.nodeFromJSON(item.data.doc);
            doc.check();
        } catch (error) {
            console.error("Invalid rich text, starting empty", error);
            doc = schema.nodeFromJSON({ type: "doc", content: [{ type: "paragraph" }] });
        }

        const listType = (type) => schema.nodes[type];
        const plugins = [
            inputRules({ rules: [
                wrappingInputRule(/^\s*([-+*])\s$/, listType("bullet_list")),
                wrappingInputRule(/^(\d+)\.\s$/, listType("ordered_list"), (match) => ({ order: +match[1] }), (match, node) => node.childCount + node.attrs.order === +match[1]),
            ] }),
            history(),
            keymap({
                "Mod-z": undo,
                "Shift-Mod-z": redo,
                "Mod-y": redo,
                Backspace: undoInputRule,
                "Mod-b": toggleMark(schema.marks.bold),
                "Mod-i": toggleMark(schema.marks.italic),
                "Mod-u": toggleMark(schema.marks.underline),
                "Shift-Enter": chainCommands(exitCode, (state, dispatch) => {
                    if (dispatch) dispatch(state.tr.replaceSelectionWith(schema.nodes.hard_break.create()).scrollIntoView());
                    return true;
                }),
                Enter: splitListItem(schema.nodes.list_item),
                "Mod-[": liftListItem(schema.nodes.list_item),
                "Mod-]": sinkListItem(schema.nodes.list_item),
                Tab: sinkListItem(schema.nodes.list_item),
                "Shift-Tab": liftListItem(schema.nodes.list_item),
            }),
            keymap(baseKeymap),
        ];

        const view = new EditorView(mount, {
            state: EditorState.create({ doc, plugins }),
            attributes: { "aria-label": "Text", "aria-multiline": "true", role: "textbox" },
            dispatchTransaction: (transaction) => {
                view.updateState(view.state.apply(transaction));
                if (transaction.docChanged) this.changed();
                if (this.activeView === view) this.paintToolbar();
            },
            handleDOMEvents: {
                focus: () => { this.setActiveView(view); return false; },
            },
        });
        item.view = view;
    }

    buildImage(item) {
        const fit = el("span", "seg soft mce-fit", { role: "group", "aria-label": "Velikost obrázku" });
        [["natural", "Přirozená", "Celý obrázek ve vlastním poměru stran, nejvýš na šířku pole"], ["cover", "Vyplnit", "Vyplní pole a ořízne obrázek na poměr stran pole"]].forEach(([value, label, title]) => {
            const button = el("button", "", { type: "button", "data-fit": value, title, text: label });
            button.addEventListener("click", () => {
                item.data.fit = value;
                this.paintImage(item);
                this.changed();
            });
            fit.appendChild(button);
        });
        const pick = el("button", "btn btn-ghost icon xs", { type: "button", title: "Vybrat jiný obrázek z galerie", "aria-label": "Vybrat jiný obrázek z galerie" });
        pick.appendChild(icon("image-search", "s14"));
        pick.addEventListener("click", () => this.openGallery({ segment: null, item }));
        const replace = el("button", "btn btn-ghost icon xs", { type: "button", title: "Nahrát jiný obrázek", "aria-label": "Nahrát jiný obrázek" });
        replace.appendChild(icon("upload", "s14"));
        replace.addEventListener("click", () => {
            this.uploadTarget = { segment: null, item };
            this.fileInputTarget.value = "";
            this.fileInputTarget.click();
        });
        item.tools.append(fit, pick, replace);
        item.fitGroup = fit;

        const figure = el("figure", "mcp-img");
        const img = el("img", "", { alt: "" });
        figure.appendChild(img);
        // The picture went to the gallery Koš / is gone from the gallery:
        // readers no longer see it (server-side stand-in), say so here too.
        const unavailable = el("div", "mce-img-unavailable", { role: "status" });
        unavailable.hidden = true;
        item.body.appendChild(unavailable);
        item.unavailableNote = unavailable;
        const alt = el("input", "form-control form-control-sm mce-alt", { type: "text", placeholder: "Popis obrázku (pro čtečky a vyhledávače)", "aria-label": "Popis obrázku", maxlength: "300" });
        alt.value = item.data.alt || "";
        alt.addEventListener("input", () => {
            item.data.alt = alt.value;
            this.changed();
        });
        item.body.append(figure, alt);
        item.figure = figure;
        item.img = img;
        this.paintImage(item);
    }

    paintImage(item) {
        item.figure.className = `mcp-img mcp-img-${item.data.fit}`;
        const slot = this.segmentOf(item)?.slot;
        item.figure.style.aspectRatio = item.data.fit === "cover" && slot ? `${slot.width} / ${slot.height}` : "";
        if (item.img.getAttribute("src") !== item.data.url) item.img.src = item.data.url || "";
        item.img.alt = item.data.alt || "";
        const reason = item.data.unavailable || null;
        item.unavailableNote.hidden = reason === null;
        item.unavailableNote.textContent = reason === "trashed"
            ? "Obrázek je v koši galerie – čtenáři manuálu ho nevidí. Obnovte ho v galerii, nebo vyberte jiný."
            : (reason ? "Obrázek už v galerii není – čtenáři manuálu ho nevidí. Vyberte jiný." : "");
        item.fitGroup.querySelectorAll("button").forEach((button) => {
            const on = button.dataset.fit === item.data.fit;
            button.classList.toggle("on", on);
            button.setAttribute("aria-pressed", String(on));
        });
    }

    segmentOf(item) {
        return this.segments.find((segment) => segment.list.contains(item.node));
    }

    async filePicked() {
        const file = this.fileInputTarget.files[0];
        const target = this.uploadTarget;
        this.uploadTarget = null;
        if (!file || !target) return;

        if (file.size > this.maxFileSizeValue) {
            this.flash("Obrázek je větší než 10 MB. Zmenšete ho a nahrajte znovu.", true);
            return;
        }

        const placeholder = el("div", "mce-uploading", { text: `Nahrávám ${file.name}…` });
        (target.item ? target.item.body : target.segment.list).appendChild(placeholder);

        try {
            const body = new FormData();
            body.append("_token", this.csrfValue);
            body.append("image", file);
            const response = await fetch(this.uploadUrlValue, { method: "POST", body, headers: { Accept: "application/json" } });
            const json = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(json.error || "Obrázek se nepodařilo nahrát. Zkuste to znovu.");

            // Every upload is a gallery picture too (folder Manuál / <manual>).
            this.placeImage(target, { path: json.path, url: json.url, galleryImageId: json.galleryImageId ?? null });
        } catch (error) {
            this.flash(error.message, true);
        } finally {
            placeholder.remove();
        }
    }

    /** Puts a picture into the element being replaced, or a new element at the end of the segment. */
    placeImage(target, { path, url, galleryImageId }) {
        if (target.item) {
            Object.assign(target.item.data, { path, url, galleryImageId, unavailable: null });
            this.paintImage(target.item);
        } else {
            const item = this.createItem({ id: newId(), type: "image", path, url, fit: "natural", alt: "", galleryImageId });
            target.segment.list.appendChild(item.node);
            this.paintImage(item);
        }
        this.changed();
    }

    // ---- gallery picker ----------------------------------------------------------

    openGallery(target) {
        const modalElement = this.galleryModalValue ? document.querySelector(this.galleryModalValue) : null;
        if (!modalElement || !window.bootstrap) {
            this.flash("Galerii se nepodařilo otevřít. Obnovte stránku.", true);
            return;
        }

        this.galleryTarget = target;
        // Closing without a pick disarms — a later pick must not land here.
        modalElement.addEventListener("hidden.bs.modal", () => { this.galleryTarget = null; }, { once: true });
        window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
    }

    assetSelected(event) {
        const target = this.galleryTarget;
        const detail = event.detail || {};
        if (!target || !detail.id || !detail.path) return;
        this.galleryTarget = null;

        // The server re-resolves the id (project check) and replaces the path
        // with the gallery row's key — the path here is only a snapshot.
        this.placeImage(target, { path: detail.path, url: detail.url, galleryImageId: detail.id });

        const modalElement = document.querySelector(this.galleryModalValue);
        const modal = modalElement ? window.bootstrap.Modal.getInstance(modalElement) : null;
        if (modal) modal.hide();
    }

    // ---- toolbar ---------------------------------------------------------------

    buildToolbar() {
        this.fontsValue.forEach((font) => {
            const option = el("option", "", { value: font.family, text: font.label });
            option.style.fontFamily = cssString(font.family);
            this.fontSelectTarget.appendChild(option);
        });
        if (this.fontsValue.length === 0) this.fontSelectTarget.closest(".mce-tb-font").hidden = true;

        const swatch = (color, name) => {
            const button = el("button", "mce-swatch", { type: "button", title: name, "aria-label": `Barva ${name}`, "data-color": color, "aria-pressed": "false" });
            if (color) button.style.setProperty("--sw", color);
            else button.classList.add("mce-swatch-none");
            button.addEventListener("mousedown", (event) => event.preventDefault());
            button.addEventListener("click", () => this.applyMarkAttr("color", color ? { color } : null));
            return button;
        };
        this.colorListTarget.appendChild(swatch("", "Výchozí barva"));
        this.colorsValue.forEach((entry) => this.colorListTarget.appendChild(swatch(entry.color, entry.name)));

        this.toolbarTarget.querySelectorAll(".mce-tb").forEach((button) => {
            // Keep the editor's selection: a toolbar click must not blur it.
            button.addEventListener("mousedown", (event) => event.preventDefault());
            button.addEventListener("click", () => this.command(button.dataset.cmd));
        });
        this.paintToolbar();
    }

    setActiveView(view) {
        this.activeView = view;
        if (!view) this.linkBoxTarget.hidden = true;
        this.paintToolbar();
    }

    command(name) {
        const view = this.activeView;
        if (!view) return;
        const { marks, nodes } = schema;

        if (["bold", "italic", "underline"].includes(name)) {
            toggleMark(marks[name])(view.state, view.dispatch);
        } else if (name === "bullet_list" || name === "ordered_list") {
            const inList = this.listTypeAt(view.state);
            if (inList === name) liftListItem(nodes.list_item)(view.state, view.dispatch);
            else if (inList) this.switchList(view, nodes[name]);
            else wrapInList(nodes[name])(view.state, view.dispatch);
        } else if (name === "link") {
            this.openLink();
            return;
        }
        view.focus();
    }

    listTypeAt(state) {
        const { $from } = state.selection;
        for (let depth = $from.depth; depth > 0; depth--) {
            const type = $from.node(depth).type.name;
            if (type === "bullet_list" || type === "ordered_list") return type;
        }
        return null;
    }

    switchList(view, type) {
        const { $from } = view.state.selection;
        for (let depth = $from.depth; depth > 0; depth--) {
            const node = $from.node(depth);
            if (node.type.name === "bullet_list" || node.type.name === "ordered_list") {
                view.dispatch(view.state.tr.setNodeMarkup($from.before(depth), type, type.name === "ordered_list" ? { order: 1 } : null));
                return;
            }
        }
    }

    markAt(state, type) {
        const { from, to, empty, $from } = state.selection;
        if (empty) return type.isInSet(state.storedMarks || $from.marks()) || null;
        let found = null;
        state.doc.nodesBetween(from, to, (node) => {
            if (!found && node.isText) found = type.isInSet(node.marks) || null;
        });
        return found;
    }

    applyMarkAttr(markName, attrs) {
        const view = this.activeView;
        if (!view) return;
        const type = schema.marks[markName];
        const { state } = view;
        const { from, to, empty } = state.selection;
        let tr = state.tr;

        if (empty) {
            tr = tr.removeStoredMark(type);
            if (attrs) tr = tr.addStoredMark(type.create(attrs));
        } else {
            tr = tr.removeMark(from, to, type);
            if (attrs) tr = tr.addMark(from, to, type.create(attrs));
        }
        view.dispatch(tr);
        view.focus();
    }

    fontPicked() {
        const family = this.fontSelectTarget.value;
        this.applyMarkAttr("font", family ? { family } : null);
    }

    openLink() {
        const view = this.activeView;
        if (!view) return;
        const link = this.markAt(view.state, schema.marks.link);
        this.linkInputTarget.value = link ? link.attrs.href : "";
        this.linkErrorTarget.hidden = true;
        this.linkBoxTarget.hidden = false;
        this.linkInputTarget.focus();
    }

    linkKey(event) {
        if (event.key === "Enter") {
            event.preventDefault();
            this.applyLink();
        } else if (event.key === "Escape") {
            this.linkBoxTarget.hidden = true;
            this.activeView?.focus();
        }
    }

    applyLink() {
        const view = this.activeView;
        if (!view) return;
        let href = this.linkInputTarget.value.trim();
        if (href && !/^[a-z]+:/i.test(href)) href = href.includes("@") && !href.includes("/") ? "mailto:" + href : "https://" + href;

        if (!ALLOWED_HREF.test(href)) {
            this.linkErrorTarget.hidden = false;
            return;
        }

        const type = schema.marks.link;
        const { state } = view;
        let { from, to, empty } = state.selection;

        if (empty) {
            // Caret inside an existing link → edit that whole link; otherwise
            // insert the address itself as the linked text.
            const range = this.linkRange(state, from);
            if (range) {
                view.dispatch(state.tr.removeMark(range.from, range.to, type).addMark(range.from, range.to, type.create({ href })));
            } else {
                const text = href.replace(/^mailto:/i, "");
                view.dispatch(state.tr.insert(from, schema.text(text, [type.create({ href })])));
            }
        } else {
            view.dispatch(state.tr.removeMark(from, to, type).addMark(from, to, type.create({ href })));
        }
        this.linkBoxTarget.hidden = true;
        view.focus();
    }

    removeLink() {
        const view = this.activeView;
        if (!view) return;
        const type = schema.marks.link;
        const { state } = view;
        let { from, to, empty } = state.selection;
        if (empty) {
            const range = this.linkRange(state, from);
            if (!range) { this.linkBoxTarget.hidden = true; return; }
            ({ from, to } = range);
        }
        view.dispatch(state.tr.removeMark(from, to, type));
        this.linkBoxTarget.hidden = true;
        view.focus();
    }

    linkRange(state, pos) {
        const $pos = state.doc.resolve(pos);
        const parent = $pos.parent;
        const type = schema.marks.link;
        const start = $pos.parentOffset;
        let found = null;
        parent.forEach((child, childOffset) => {
            if (found) return;
            const end = childOffset + child.nodeSize;
            if (childOffset <= start && start <= end) {
                const mark = type.isInSet(child.marks);
                if (mark) found = { mark, childOffset, end };
            }
        });
        if (!found) return null;
        const base = $pos.start();
        // Extend over neighbouring text nodes carrying the same link.
        let from = found.childOffset;
        let to = found.end;
        parent.forEach((child, childOffset) => {
            const end = childOffset + child.nodeSize;
            if (found.mark.isInSet(child.marks)) {
                if (end === from) from = childOffset;
                if (childOffset === to) to = end;
            }
        });
        return { from: base + from, to: base + to };
    }

    paintToolbar() {
        if (!this.hasToolbarTarget) return;
        const view = this.activeView;
        this.toolbarTarget.setAttribute("aria-disabled", String(!view));
        this.toolbarHintTarget.hidden = !!view;
        this.toolbarTarget.classList.toggle("is-idle", !view);

        const state = view?.state;
        this.toolbarTarget.querySelectorAll(".mce-tb").forEach((button) => {
            const name = button.dataset.cmd;
            let on = false;
            if (state) {
                if (schema.marks[name]) on = !!this.markAt(state, schema.marks[name]);
                else if (name === "bullet_list" || name === "ordered_list") on = this.listTypeAt(state) === name;
            }
            button.disabled = !view;
            button.setAttribute("aria-pressed", String(on));
            button.classList.toggle("on", on);
        });

        const font = state ? this.markAt(state, schema.marks.font) : null;
        this.fontSelectTarget.disabled = !view;
        this.fontSelectTarget.value = font && this.fontsValue.some((f) => f.family === font.attrs.family) ? font.attrs.family : "";

        const color = state ? this.markAt(state, schema.marks.color) : null;
        this.colorListTarget.querySelectorAll(".mce-swatch").forEach((button) => {
            button.disabled = !view;
            const on = !!view && (button.dataset.color || "") === (color ? color.attrs.color : "");
            button.setAttribute("aria-pressed", String(on));
            button.classList.toggle("on", on);
        });
    }

    // ---- save ------------------------------------------------------------------

    async save() {
        if (this.saving) return;
        const state = this.serialize();

        if (!state.name) {
            this.flash("Vyplňte název stránky.", true);
            this.nameTarget.focus();
            return;
        }

        const payload = JSON.stringify(state);
        if (payload.length > this.maxPayloadBytesValue) {
            this.flash("Stránka je příliš velká. Rozdělte text do menších prvků nebo ho zkraťte.", true);
            return;
        }

        this.saving = true;
        this.saveButtonTarget.classList.add("is-loading");
        this.saveButtonTarget.disabled = true;

        try {
            const body = new FormData();
            body.append("_token", this.csrfValue);
            body.append("payload", payload);
            const response = await fetch(this.saveUrlValue, { method: "POST", body, headers: { Accept: "application/json" } });
            const json = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(json.error || "Změnu se nepodařilo uložit. Zkuste to znovu.");

            this.previewTarget.innerHTML = json.preview;
            // The server may have normalised the page (a font / colour the
            // manual no longer allows is dropped): reload only when it did,
            // so the caret and undo history survive an ordinary save.
            const stored = stableStringify(this.stripUrls(json.state));
            if (stableStringify(state) !== stored) this.load(json.state);
            else this.savedSnapshot = stored;
            this.refreshDirty();
            this.flash("Stránka uložena");
        } catch (error) {
            this.flash(error.message, true);
        } finally {
            this.saving = false;
            this.saveButtonTarget.classList.remove("is-loading");
            this.saveButtonTarget.disabled = false;
        }
    }

    stripUrls(state) {
        return {
            ...state,
            segments: state.segments.map((segment) => ({
                ...segment,
                elements: segment.elements.map(({ url, unavailable, ...rest }) => (rest.type === "image" ? { ...rest, galleryImageId: rest.galleryImageId ?? null } : rest)),
            })),
        };
    }

    flash(message, isError = false) {
        this.statusTarget.textContent = message;
        this.statusTarget.classList.toggle("text-danger", isError);
        if (!isError) {
            clearTimeout(this.flashTimer);
            this.flashTimer = setTimeout(() => {
                if (this.statusTarget.textContent === message) this.statusTarget.textContent = "";
            }, 4000);
        }
    }
}
