import { Controller } from "@hotwired/stimulus";
import * as fabric from "fabric";

/**
 * Client-side text ECHO for the single-variant fill page.
 *
 * While the user types, the echo-capable texts are drawn locally (the shared
 * assets/editor/fill_text_echo.js painter — the same code the golden tests
 * screenshot) over the text-transparent BASE render, so feedback is instant;
 * the lazily debounced Live re-render (the "settle") remains the displayed
 * truth at rest. Two modes, driven by the `fill-echo-active` class on the
 * form root (plus a `variant-text-echo:mode` event the image-fill controller
 * uses to swap its backdrop/overlays between settle and base sources):
 *
 *  - ECHO (typing): the settle preview hides, the base + echo canvas show.
 *    Entered on any text/hide mirror edit.
 *  - REST: entered when a settle render lands whose `data-state-hash` equals
 *    the hash of the mirrors as they are NOW — i.e. the server pixels reflect
 *    exactly what the user sees typed. A stale settle (raced by typing) keeps
 *    the echo up; Live guarantees one more re-render for the final state.
 *
 * The hash is djb2 over the canonical UTF-8 fill state; the PHP twin is
 * AbstractVariantFiller::fillStateHash() and the two must stay byte-identical.
 */
export default class extends Controller {
    static targets = ["canvas", "baseImage"];
    static values = {
        payload: Object,
        fonts: Array,
        hasImages: Boolean,
    };

    connect() {
        this._painter = null;
        this._disposed = false;
        this._repaintQueued = false;
        this._active = false;

        const module = window.WBoostFillTextEcho;
        if (!module || !this.hasCanvasTarget || !this.hasPayloadValue || !this.payloadValue.objects) {
            return;
        }

        module.create({
            fabric,
            canvasEl: this.canvasTarget,
            width: this.payloadValue.width,
            height: this.payloadValue.height,
            canvasHeight: this.payloadValue.canvasHeight,
            objects: this.payloadValue.objects,
            containers: this.payloadValue.containers || [],
        }).then((painter) => {
            if (this._disposed) {
                painter.dispose();
                return;
            }
            this._painter = painter;
            this._fitToPreview();
            // If the user typed before the painter finished booting, catch up.
            if (this._active) {
                this._repaint();
            }
        }).catch(() => {
            // No echo — the page degrades to settle-only behavior.
        });

        // Mirror edits (the overlay dispatches bubbling events on them).
        this._onInput = (event) => {
            if (event.target && event.target.matches && event.target.matches("[data-text-mirror]")) {
                this._edited();
            }
        };
        this._onChange = (event) => {
            if (event.target && event.target.matches && event.target.matches("[data-hide-mirror]")) {
                this._edited();
            }
        };
        this.element.addEventListener("input", this._onInput);
        this.element.addEventListener("change", this._onChange);

        // Settle renders land as data-* mutations on the Live-updated source
        // element (previewSource span, or the backdrop span on the image
        // branch). Each landing decides echo vs rest by the state hash.
        this._settleSource = this._findSettleSource();
        if (this._settleSource) {
            this._settleObserver = new MutationObserver(() => this._settleLanded());
            this._settleObserver.observe(this._settleSource, {
                attributes: true,
                attributeFilter: ["data-src", "data-base-src", "data-state-hash"],
            });
            this._applyBaseSrc();
        }

        // Track the preview's on-screen size (the echo canvas must raster at
        // the same display width; the CSS zoom transform scales both together).
        const preview = this._previewElement();
        if (preview && "ResizeObserver" in window) {
            this._resizeObserver = new ResizeObserver(() => this._fitToPreview());
            this._resizeObserver.observe(preview);
        }

        // Glyph parity: measure/paint with the project faces, then repaint
        // (the overlay does the same for its measurement boxes).
        const families = this.hasFontsValue ? this.fontsValue : [];
        Promise.all(families.map((family) => document.fonts.load(`16px "${family}"`).catch(() => {})))
            .then(() => (document.fonts && document.fonts.ready) || null)
            .then(() => {
                try {
                    fabric.cache.clearFontCache();
                } catch (err) {
                    // Non-fatal.
                }
                if (this._active) {
                    this._repaint();
                }
            });
    }

    disconnect() {
        this._disposed = true;
        this.element.removeEventListener("input", this._onInput);
        this.element.removeEventListener("change", this._onChange);
        if (this._settleObserver) this._settleObserver.disconnect();
        if (this._resizeObserver) this._resizeObserver.disconnect();
        if (this._painter) {
            this._painter.dispose();
            this._painter = null;
        }
    }

    // --- Mode ----------------------------------------------------------------

    _edited() {
        if (!this._active) {
            this._active = true;
            this.element.classList.add("fill-echo-active");
            this.dispatch("mode", { detail: { echo: true } });
        }
        this._scheduleRepaint();
    }

    _settleLanded() {
        this._applyBaseSrc();
        if (!this._active) {
            return;
        }
        const serverHash = this._settleSource ? this._settleSource.getAttribute("data-state-hash") : null;
        // No hash (pre-echo markup, mid-deploy) → rest, the pre-echo behavior.
        if (serverHash !== null && serverHash !== this._clientHash()) {
            return; // stale settle — the echo stays up, a fresher one is coming
        }
        this._active = false;
        this.element.classList.remove("fill-echo-active");
        this.dispatch("mode", { detail: { echo: false } });
    }

    // --- Painting ------------------------------------------------------------

    /** Coalesce keystroke bursts; setTimeout (not rAF — hidden tabs). */
    _scheduleRepaint() {
        if (this._repaintQueued) return;
        this._repaintQueued = true;
        setTimeout(() => {
            this._repaintQueued = false;
            this._repaint();
        }, 30);
    }

    _repaint() {
        if (!this._painter) return;
        const module = window.WBoostFillTextEcho;
        const rules = this.payloadValue.inputs || {};
        const values = {};
        Object.keys(rules).forEach((inputId) => {
            const mirror = this.element.querySelector(`[data-text-mirror="${inputId}"]`);
            const hideMirror = this.element.querySelector(`[data-hide-mirror="${inputId}"]`);
            values[inputId] = {
                resolved: module.resolveValue(mirror ? mirror.value : "", rules[inputId]),
                hidden: Boolean(hideMirror && hideMirror.checked),
            };
        });
        this._painter.update(values);
    }

    _fitToPreview() {
        if (!this._painter) return;
        const preview = this._previewElement();
        if (!preview) return;
        const width = preview.clientWidth;
        if (width > 0) {
            this._painter.setDisplayWidth(width);
        }
    }

    // --- Sources -------------------------------------------------------------

    _findSettleSource() {
        if (this.hasImagesValue) {
            return document.getElementById("variant-backdrop-source");
        }
        return this.element.querySelector('[data-variant-fill-overlay-target="previewSource"]');
    }

    /** Text branch only: keep the base <img> on the freshest base render. */
    _applyBaseSrc() {
        if (!this.hasBaseImageTarget || !this._settleSource) return;
        const src = this._settleSource.getAttribute("data-base-src");
        if (src && this.baseImageTarget.getAttribute("src") !== src) {
            this.baseImageTarget.setAttribute("src", src);
        }
    }

    // --- State hash (PHP twin: AbstractVariantFiller::fillStateHash) ----------

    _clientHash() {
        const parts = [];

        const texts = [];
        this.element.querySelectorAll("[data-text-mirror]").forEach((mirror) => {
            texts.push([mirror.getAttribute("data-text-mirror"), mirror.value]);
        });
        texts.sort((a, b) => (a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : 0));
        texts.forEach(([id, value]) => parts.push(`T:${id}=${value}`));

        const hidden = [];
        this.element.querySelectorAll("[data-hide-mirror]").forEach((mirror) => {
            hidden.push([mirror.getAttribute("data-hide-mirror"), mirror.checked ? "1" : "0"]);
        });
        hidden.sort((a, b) => (a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : 0));
        hidden.forEach(([id, flag]) => parts.push(`H:${id}=${flag}`));

        const bytes = new TextEncoder().encode(parts.join("\n"));
        let hash = 5381;
        for (let i = 0; i < bytes.length; i += 1) {
            hash = (Math.imul(hash, 33) ^ bytes[i]) >>> 0;
        }

        return String(hash);
    }

    _previewElement() {
        return this.element.querySelector('[data-variant-fill-overlay-target="preview"]');
    }
}
