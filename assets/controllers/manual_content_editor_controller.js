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
 * `background` = null | {type: "color", color} | {type: "gradient", shape:
 * linear|radial, from, to, angle, center} | {type: "image", path,
 * galleryImageId, fit: cover|contain} — the whole-page background
 * (ContentPageBackground). Colours come from the project palette only; a
 * picture is picked / uploaded exactly like an image element.
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
        size: { attrs: { size: {} }, toDOM: (mark) => ["span", { style: "font-size: " + Number(mark.attrs.size) + "px" }, 0] },
        color: { attrs: { color: {} }, toDOM: (mark) => ["span", { style: "color: " + mark.attrs.color }, 0] },
    },
});

// Font-size presets (px on the 845 px page; body text is 18 px). The server
// accepts any whole number RichTextDoc::MIN_FONT_SIZE–MAX_FONT_SIZE (8–96).
const FONT_SIZES = [12, 14, 16, 20, 24, 28, 32, 40, 48, 56, 64, 72];
const DEFAULT_FONT_SIZE = 18;

const KIND_LABEL = { heading: "Nadpis", richText: "Text", image: "Obrázek" };

const CENTER_POSITION = {
    "top-left": "0% 0%", top: "50% 0%", "top-right": "100% 0%",
    left: "0% 50%", center: "50% 50%", right: "100% 50%",
    "bottom-left": "0% 100%", bottom: "50% 100%", "bottom-right": "100% 100%",
};

/** Mirrors ContentPageBackground::paintCss() for a gradient. */
function gradientCss(bg) {
    return bg.shape === "radial"
        ? `radial-gradient(circle farthest-corner at ${CENTER_POSITION[bg.center] || "50% 50%"}, ${bg.from}, ${bg.to})`
        : `linear-gradient(${bg.angle}deg, ${bg.from}, ${bg.to})`;
}

/** Mirrors ContentPageBackground::paintCss() (colour / gradient). */
function paintCss(bg) {
    if (bg.type === "color") return `background-color: ${bg.color}`;
    if (bg.type === "gradient") return `background-image: ${gradientCss(bg)}`;
    return "";
}

/** The background as the save endpoint takes it (canonical — dirty tracking compares it). */
function backgroundJson(bg) {
    if (!bg) return null;
    if (bg.type === "color") return { type: "color", color: bg.color };
    if (bg.type === "gradient") return { type: "gradient", shape: bg.shape, from: bg.from, to: bg.to, angle: bg.angle, center: bg.center || "center" };
    if (bg.type === "image" && bg.path) return { type: "image", path: bg.path, galleryImageId: bg.galleryImageId ?? null, fit: bg.fit || "cover" };
    return null;
}

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
        "name", "layoutRadio", "stage", "toolbar", "toolbarHint", "fontButton", "fontLabel", "fontMenu", "sizeSelect", "colorList",
        "linkBox", "linkInput", "linkError", "fileInput", "status", "dirtyBadge", "saveButton",
        "page", "bgRow", "bgColors", "bgGradPreview", "bgAngle", "bgDrop", "bgThumb", "bgMeta", "bgPop", "bgNote",
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
        this.buildBackground();
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

        // The formatting bar sits inside the focused text element (it used to
        // be a sticky bar over the paper); a press outside both closes it.
        this.toolbarHome = document.createComment("mce-toolbar");
        this.toolbarTarget.before(this.toolbarHome);
        this.onOutsidePress = (event) => {
            if (!this.activeView) return;
            const anchor = this.activeView.dom.closest(".mce-el");
            if (this.toolbarTarget.contains(event.target) || anchor?.contains(event.target)) return;
            this.setActiveView(null);
        };
        document.addEventListener("pointerdown", this.onOutsidePress, true);
    }

    disconnect() {
        window.removeEventListener("beforeunload", this.onBeforeUnload);
        window.removeEventListener("asset-selected", this.onAssetSelected);
        document.removeEventListener("keydown", this.onKeydown);
        document.removeEventListener("pointerdown", this.onOutsidePress, true);
        document.removeEventListener("pointerdown", this.onFontMenuOutside, true);
        document.removeEventListener("pointerdown", this.onBgPopOutside, true);
        document.removeEventListener("keydown", this.onBgPopKey, true);
        document.removeEventListener("keydown", this.onFontMenuKey, true);
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
        const bg = state.background ? { type: "image", ...state.background } : null;
        this.bgKind = bg ? bg.type : "none";
        this.background = bg;
        this.bgMemory = bg ? { [bg.type]: bg } : {};
        this.paintBackground();
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

        return { name: this.nameTarget.value.trim(), layout: this.layout, segments, background: backgroundJson(this.background) };
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
        item.ghost = this.buildToolbarGhost(item);
        item.body.before(item.ghost);

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
        await this.uploadFile(file, target);
    }

    async uploadFile(file, target) {
        if (!file || !target) return;

        if (file.size > this.maxFileSizeValue) {
            this.flash("Obrázek je větší než 10 MB. Zmenšete ho a nahrajte znovu.", true);
            return;
        }

        const placeholder = el("div", "mce-uploading", { text: `Nahrávám ${file.name}…` });
        if (target.background) this.bgNoteTarget.after(placeholder);
        else (target.item ? target.item.body : target.segment.list).appendChild(placeholder);

        try {
            const body = new FormData();
            body.append("_token", this.csrfValue);
            body.append("image", file);
            const response = await fetch(this.uploadUrlValue, { method: "POST", body, headers: { Accept: "application/json" } });
            const json = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(json.error || "Obrázek se nepodařilo nahrát. Zkuste to znovu.");

            // Every upload is a gallery picture too (folder Manuál / <manual>).
            this.placeImage(target, { path: json.path, url: json.url, galleryImageId: json.galleryImageId ?? null, name: file.name });
        } catch (error) {
            this.flash(error.message, true);
        } finally {
            placeholder.remove();
        }
    }

    /** Puts a picture into the element being replaced, or a new element at the end of the segment. */
    placeImage(target, { path, url, galleryImageId, name = null, width = null, height = null }) {
        if (target.background) {
            const fit = this.bgMemory.image?.fit || "cover";
            this.background = { type: "image", path, url, galleryImageId, fit, unavailable: null, name, width, height };
            this.bgKind = "image";
            this.bgMemory.image = this.background;
            this.paintBackground();
            if (!width) this.measureBackground(this.background);
        } else if (target.item) {
            Object.assign(target.item.data, { path, url, galleryImageId, unavailable: null });
            this.paintImage(target.item);
        } else {
            const item = this.createItem({ id: newId(), type: "image", path, url, fit: "natural", alt: "", galleryImageId });
            target.segment.list.appendChild(item.node);
            this.paintImage(item);
        }
        this.changed();
    }

    // ---- page background ---------------------------------------------------------
    // One bar: kind pills (none / color / gradient / image) + that kind's
    // controls. Switching kinds keeps each kind's last value for the session
    // (`bgMemory`), so trying "Barva" and going back to "Obrázek" loses nothing.

    buildBackground() {
        const colors = this.colorsValue;
        colors.forEach((entry) => {
            const button = el("button", "mce-swatch", { type: "button", role: "radio", title: entry.name, "aria-label": `Barva ${entry.name}`, "data-color": entry.color, "aria-checked": "false" });
            button.style.setProperty("--sw", entry.color);
            button.addEventListener("click", () => this.setBackground({ type: "color", color: entry.color }));
            this.bgColorsTarget.appendChild(button);
        });
        if (colors.length === 0) {
            this.bgRowTarget.querySelectorAll('[data-kind="color"], [data-kind="gradient"]').forEach((button) => {
                button.disabled = true;
                button.title = "Projekt nemá v paletě žádné barvy — přidejte je v modulu Paleta.";
            });
        }

        this.onBgPopOutside = (event) => {
            if (this.bgPopTarget.hidden) return;
            if (this.bgPopTarget.contains(event.target) || this.bgPopAnchor?.contains(event.target)) return;
            this.closeBgPop();
        };
        document.addEventListener("pointerdown", this.onBgPopOutside, true);
        this.onBgPopKey = (event) => { if (event.key === "Escape" && !this.bgPopTarget.hidden) { event.stopPropagation(); this.closeBgPop(); } };
        document.addEventListener("keydown", this.onBgPopKey, true);
    }

    backgroundKind(event) {
        const kind = event.currentTarget.dataset.kind;
        if (kind === this.bgKind) return;
        this.closeBgPop();
        if (this.background) this.bgMemory[this.background.type] = this.background;
        this.bgKind = kind;

        const colors = this.colorsValue.map((entry) => entry.color);
        if (kind === "none") this.background = null;
        else if (kind === "color") this.background = this.bgMemory.color || (colors[0] ? { type: "color", color: colors[0] } : null);
        else if (kind === "gradient") {
            this.background = this.bgMemory.gradient || (colors[0]
                ? { type: "gradient", shape: "linear", from: colors[0], to: colors[1] || colors[0], angle: 135, center: "center" }
                : null);
        } else this.background = this.bgMemory.image || null;

        this.paintBackground();
        this.changed();
    }

    setBackground(background) {
        this.background = background;
        this.bgKind = background ? background.type : "none";
        if (background) this.bgMemory[background.type] = background;
        this.paintBackground();
        this.changed();
    }

    updateGradient(patch) {
        if (this.background?.type !== "gradient") return;
        this.setBackground({ ...this.background, ...patch });
    }

    gradientShape(event) {
        this.closeBgPop();
        this.updateGradient({ shape: event.currentTarget.dataset.shape });
    }

    swapStops() {
        const bg = this.background;
        if (bg?.type === "gradient") this.updateGradient({ from: bg.to, to: bg.from });
    }

    openStopPicker(event) {
        const stop = event.currentTarget.dataset.stop;
        const pop = this.openBgPop(event.currentTarget, "mce-bg-pop-colors");
        if (!pop) return;
        this.colorsValue.forEach((entry) => {
            const on = this.background?.[stop] === entry.color;
            const button = el("button", "mce-swatch" + (on ? " on" : ""), { type: "button", title: entry.name, "aria-label": `Barva ${entry.name}`, "aria-pressed": String(on) });
            button.style.setProperty("--sw", entry.color);
            button.addEventListener("click", () => { this.updateGradient({ [stop]: entry.color }); this.closeBgPop(); });
            pop.appendChild(button);
        });
    }

    openAnglePicker(event) {
        const pop = this.openBgPop(event.currentTarget, "mce-bg-pop-angle");
        if (!pop) return;
        const angle = this.background?.angle ?? 135;
        const row = el("div", "mce-bg-angle-row");
        const range = el("input", "", { type: "range", min: "0", max: "359", step: "1", value: String(angle), "aria-label": "Úhel přechodu" });
        const out = el("span", "mce-bg-angle-val", { text: `${angle}°` });
        range.addEventListener("input", () => {
            out.textContent = `${range.value}°`;
            this.updateGradient({ angle: parseInt(range.value, 10) || 0 });
        });
        row.append(range, out);
        const presets = el("div", "mce-bg-angle-presets");
        [0, 45, 90, 135, 180, 225, 270, 315].forEach((value) => {
            const button = el("button", "btn btn-ghost xs", { type: "button", text: `${value}°` });
            button.addEventListener("click", () => { range.value = String(value); out.textContent = `${value}°`; this.updateGradient({ angle: value }); });
            presets.appendChild(button);
        });
        pop.append(row, presets);
    }

    openCenterPicker(event) {
        const pop = this.openBgPop(event.currentTarget, "mce-bg-pop-center");
        if (!pop) return;
        const labels = {
            "top-left": "Vlevo nahoře", top: "Nahoře", "top-right": "Vpravo nahoře",
            left: "Vlevo", center: "Uprostřed", right: "Vpravo",
            "bottom-left": "Vlevo dole", bottom: "Dole", "bottom-right": "Vpravo dole",
        };
        Object.entries(labels).forEach(([value, label]) => {
            const on = (this.background?.center || "center") === value;
            const button = el("button", "mce-bg-center" + (on ? " on" : ""), { type: "button", title: label, "aria-label": label, "aria-pressed": String(on) });
            button.appendChild(el("span", "mce-bg-center-dot", { "aria-hidden": "true" }));
            button.addEventListener("click", () => { this.updateGradient({ center: value }); this.closeBgPop(); });
            pop.appendChild(button);
        });
    }

    /** Opens the shared popover under `anchor` (toggles when the same anchor is pressed again). */
    openBgPop(anchor, variant) {
        if (!this.bgPopTarget.hidden && this.bgPopAnchor === anchor) {
            this.closeBgPop();
            return null;
        }
        const pop = this.bgPopTarget;
        pop.innerHTML = "";
        pop.className = `mce-bg-pop ${variant}`;
        pop.hidden = false;
        this.bgPopAnchor = anchor;
        const row = this.bgRowTarget.getBoundingClientRect();
        const box = anchor.getBoundingClientRect();
        pop.style.top = `${box.bottom - row.top + 6}px`;
        // Right-aligned controls open leftwards so the popover stays inside the bar.
        const alignRight = box.left - row.left > row.width / 2;
        pop.style.left = alignRight ? "" : `${Math.max(0, box.left - row.left)}px`;
        pop.style.right = alignRight ? `${Math.max(0, row.right - box.right)}px` : "";
        return pop;
    }

    closeBgPop() {
        if (!this.hasBgPopTarget) return;
        this.bgPopTarget.hidden = true;
        this.bgPopTarget.innerHTML = "";
        this.bgPopAnchor = null;
    }

    pickBackground() {
        this.openGallery({ background: true });
    }

    backgroundDragOver(event) {
        if (!event.dataTransfer || ![...event.dataTransfer.types].includes("Files")) return;
        event.preventDefault();
        this.bgDropTarget.classList.add("is-over");
    }

    backgroundDragLeave() {
        this.bgDropTarget.classList.remove("is-over");
    }

    backgroundDrop(event) {
        event.preventDefault();
        this.bgDropTarget.classList.remove("is-over");
        const file = event.dataTransfer?.files?.[0];
        if (file) this.uploadFile(file, { background: true });
    }

    backgroundFit(event) {
        if (this.background?.type !== "image") return;
        this.setBackground({ ...this.background, fit: event.currentTarget.dataset.fit });
    }

    removeBackground() {
        // Back to the empty picture slot (kind stays "Obrázek").
        delete this.bgMemory.image;
        this.background = null;
        this.paintBackground();
        this.changed();
    }

    /** Pixel size for the "name · W × H px" line when the pick did not carry it. */
    measureBackground(bg) {
        if (!bg.url) return;
        const probe = new Image();
        probe.onload = () => {
            if (this.background !== bg) return;
            bg.width = probe.naturalWidth;
            bg.height = probe.naturalHeight;
            this.paintBackground();
        };
        probe.src = bg.url;
    }

    /** Paints the page like _manual_content_page.html.twig: an unavailable picture is not drawn. */
    paintBackground() {
        if (!this.hasPageTarget) return;
        const kind = this.bgKind || "none";
        const bg = this.background;
        const image = bg?.type === "image" ? bg : null;
        const reason = image?.unavailable || null;
        const shows = !!image && !reason && !!image.url;
        const url = shows ? `url(${cssString(image.url)})` : "";
        const paint = bg && bg.type !== "image" ? paintCss(bg) : "";

        const page = this.pageTarget;
        // Editing chrome switches to frosted chips on a painted page.
        page.classList.toggle("mce-has-bg", shows || !!paint);
        page.classList.toggle("mcp-bg", shows);
        page.classList.toggle("mcp-bg-cover", shows && image.fit === "cover");
        page.classList.toggle("mcp-bg-contain", shows && image.fit === "contain");
        page.style.backgroundImage = url;
        page.style.backgroundColor = "";
        if (paint) page.style.cssText += ";" + paint;

        const row = this.bgRowTarget;
        row.querySelectorAll("[data-kind]").forEach((button) => {
            const on = button.dataset.kind === kind;
            button.classList.toggle("on", on);
            button.setAttribute("aria-checked", String(on));
        });
        const pane = kind === "image" ? (image ? "image-set" : "image-empty") : kind;
        row.querySelectorAll("[data-pane]").forEach((node) => { node.hidden = node.dataset.pane !== pane; });

        this.bgColorsTarget.querySelectorAll(".mce-swatch").forEach((button) => {
            const on = bg?.type === "color" && button.dataset.color === bg.color;
            button.classList.toggle("on", on);
            button.setAttribute("aria-checked", String(on));
        });

        if (bg?.type === "gradient") {
            row.querySelectorAll("[data-shape]").forEach((button) => {
                const on = button.dataset.shape === bg.shape;
                button.classList.toggle("on", on);
                button.setAttribute("aria-checked", String(on));
            });
            row.querySelectorAll("[data-shape-only]").forEach((button) => { button.hidden = button.dataset.shapeOnly !== bg.shape; });
            this.bgGradPreviewTarget.style.backgroundImage = gradientCss(bg);
            row.querySelectorAll("[data-stop]").forEach((button) => button.style.setProperty("--sw", bg[button.dataset.stop]));
            this.bgAngleTarget.textContent = `${bg.angle}°`;
        }

        this.bgThumbTarget.style.backgroundImage = url;
        if (image) {
            const name = image.name || String(image.path || "").split("/").pop();
            this.bgMetaTarget.textContent = image.width && image.height ? `${name} · ${image.width} × ${image.height} px` : name;
            this.bgMetaTarget.title = name;
        }
        row.querySelectorAll("[data-fit]").forEach((button) => {
            const on = !!image && button.dataset.fit === image.fit;
            button.classList.toggle("on", on);
            button.setAttribute("aria-pressed", String(on));
        });

        this.bgNoteTarget.hidden = reason === null;
        this.bgNoteTarget.textContent = reason === "trashed"
            ? "Pozadí je v koši galerie – čtenáři ho nevidí. Obnovte ho v galerii, nebo vyberte jiné."
            : (reason ? "Pozadí už v galerii není – čtenáři ho nevidí. Vyberte jiné." : "");
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
        this.placeImage(target, { path: detail.path, url: detail.url, galleryImageId: detail.id, name: detail.name || null, width: detail.width || null, height: detail.height || null });

        const modalElement = document.querySelector(this.galleryModalValue);
        const modal = modalElement ? window.bootstrap.Modal.getInstance(modalElement) : null;
        if (modal) modal.hide();
    }

    // ---- toolbar ---------------------------------------------------------------

    buildToolbar() {
        const fontItem = (family, label) => {
            const item = el("button", "dropdown-item mce-fontitem", { type: "button", role: "option", "data-family": family, "aria-selected": "false", text: label });
            if (family) item.style.fontFamily = cssString(family);
            item.addEventListener("mousedown", (event) => event.preventDefault());
            item.addEventListener("click", () => {
                this.closeFontMenu();
                this.applyMarkAttr("font", family ? { family } : null);
            });
            return item;
        };
        this.fontMenuTarget.appendChild(fontItem("", "Výchozí písmo"));
        this.fontsValue.forEach((font) => this.fontMenuTarget.appendChild(fontItem(font.family, font.label)));
        this.fontButtonTarget.addEventListener("mousedown", (event) => event.preventDefault());
        this.onFontMenuKey = (event) => { if (event.key === "Escape" && this.fontMenuOpen) { event.stopPropagation(); this.closeFontMenu(); this.activeView?.focus(); } };
        document.addEventListener("keydown", this.onFontMenuKey, true);
        this.onFontMenuOutside = (event) => { if (this.fontMenuOpen && !this.fontButtonTarget.parentNode.contains(event.target)) this.closeFontMenu(); };
        document.addEventListener("pointerdown", this.onFontMenuOutside, true);

        // No fonts linked to the manual: keep the picker visible (so the
        // option is discoverable) but locked, with the reason as its label.
        this.noFonts = this.fontsValue.length === 0;
        if (this.noFonts) {
            this.fontLabelTarget.textContent = "Manuál nemá písma";
            this.fontButtonTarget.closest(".mce-tb-font").title = "Manuál nemá přiřazená žádná písma — přidejte je na stránce Písma.";
        }

        this.sizeSelectTarget.options[0].textContent = `Výchozí (${DEFAULT_FONT_SIZE} px)`;
        FONT_SIZES.forEach((size) => this.sizeSelectTarget.appendChild(el("option", "", { value: String(size), text: `${size} px` })));

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

    // A grayed, inert copy of the formatting bar that every text element
    // shows while it is NOT being edited. It holds exactly the space the live
    // bar takes, so focusing a text swaps one for the other and nothing on
    // the page moves (the bar used to appear on focus and push text down).
    // Pressing it starts editing that text.
    buildToolbarGhost(item) {
        const ghost = this.toolbarTarget.cloneNode(true);
        ghost.className = "mce-toolbar mce-toolbar-ghost is-open is-idle";
        ghost.removeAttribute("role");
        ghost.removeAttribute("aria-label");
        ghost.removeAttribute("aria-disabled");
        ghost.setAttribute("aria-hidden", "true");
        ghost.querySelector(".mce-link")?.remove();
        ghost.querySelector(".mce-tb-hint")?.remove();
        ghost.querySelector(".mce-fontmenu")?.remove();
        const ghostLabel = ghost.querySelector(".mce-tb-fontlabel");
        if (ghostLabel) {
            ghostLabel.textContent = this.noFonts ? "Manuál nemá písma" : "Výchozí písmo";
            ghostLabel.style.fontFamily = "";
        }
        [ghost, ...ghost.querySelectorAll("*")].forEach((node) => {
            [...node.attributes].forEach((attr) => {
                if (attr.name.startsWith("data-manual-content-editor") || attr.name === "data-action" || attr.name === "id") node.removeAttribute(attr.name);
            });
            if (node === ghost) return;
            if (node.matches("button, select, input")) {
                node.disabled = true;
                node.tabIndex = -1;
            }
            node.classList.remove("on");
            if (node.hasAttribute("aria-pressed")) node.setAttribute("aria-pressed", "false");
        });
        ghost.querySelectorAll("select").forEach((select) => { select.selectedIndex = 0; });
        ghost.addEventListener("mousedown", (event) => {
            event.preventDefault();
            item.view?.focus();
        });
        return ghost;
    }

    setActiveView(view) {
        this.activeView = view;
        if (!view) this.linkBoxTarget.hidden = true;
        this.paintToolbar();
        this.toolbarTarget.classList.toggle("is-open", !!view);
        this.placeToolbar();
    }

    // The formatting bar lives INSIDE the focused text element, between its
    // header and the text (one DOM node, moved — its listeners stay). With no
    // active text it is parked hidden in its original slot.
    placeToolbar() {
        const bar = this.toolbarTarget;
        const anchor = this.activeView?.dom.closest(".mce-el");
        const live = anchor && anchor.isConnected ? this.activeView : null;
        this.items.forEach((item) => { if (item.ghost) item.ghost.hidden = item.view === live && live !== null; });
        if (!live) {
            bar.classList.remove("is-open");
            if (this.toolbarHome && bar.parentNode !== this.toolbarHome.parentNode) this.toolbarHome.after(bar);
            return;
        }
        const head = anchor.querySelector(":scope > .mce-el-bar");
        if (head && head.nextSibling !== bar) head.after(bar);
        bar.classList.add("is-open");
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

    toggleFontMenu() {
        if (this.fontMenuOpen) this.closeFontMenu();
        else if (this.activeView && !this.noFonts) {
            this.fontMenuOpen = true;
            this.fontMenuTarget.classList.add("show");
            this.fontButtonTarget.setAttribute("aria-expanded", "true");
        }
    }

    closeFontMenu() {
        this.fontMenuOpen = false;
        this.fontMenuTarget.classList.remove("show");
        this.fontButtonTarget.setAttribute("aria-expanded", "false");
    }

    sizePicked() {
        const size = parseInt(this.sizeSelectTarget.value, 10);
        this.applyMarkAttr("size", size > 0 ? { size } : null);
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
        const known = font ? this.fontsValue.find((f) => f.family === font.attrs.family) : null;
        this.fontButtonTarget.disabled = !view || this.noFonts;
        if (!view) this.closeFontMenu();
        if (!this.noFonts) {
            this.fontLabelTarget.textContent = known ? known.label : "Výchozí písmo";
            this.fontLabelTarget.style.fontFamily = known ? cssString(known.family) : "";
        }
        this.fontMenuTarget.querySelectorAll(".mce-fontitem").forEach((item) => {
            const on = (item.dataset.family || "") === (known ? known.family : "");
            item.classList.toggle("active", on);
            item.setAttribute("aria-selected", String(on));
        });

        const size = state ? this.markAt(state, schema.marks.size) : null;
        const sizeValue = size ? String(size.attrs.size) : "";
        if (sizeValue && ![...this.sizeSelectTarget.options].some((o) => o.value === sizeValue)) {
            this.sizeSelectTarget.appendChild(el("option", "", { value: sizeValue, text: `${sizeValue} px` }));
        }
        this.sizeSelectTarget.disabled = !view;
        this.sizeSelectTarget.value = sizeValue;

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
        const bg = state.background || null;
        return {
            ...state,
            background: backgroundJson(bg ? { type: "image", ...bg } : null),
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
