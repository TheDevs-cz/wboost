import { Controller } from "@hotwired/stimulus";

/**
 * Photoshop-style navigator for the admin canvas editor: a fixed mini-map
 * (bottom-right) showing a live thumbnail of the canvas with a rectangle
 * marking the area currently visible in the browser viewport; dragging or
 * clicking inside the map scrolls the page so that spot moves to the
 * viewport center.
 *
 * Geometry model: zoom is a CSS scale() on .canvas-wrapper and panning is
 * plain WINDOW scrolling (canvas_zoom_controller compensates the layout box,
 * so the page owns the scrollbars). Everything here derives from the live
 * canvas element's getBoundingClientRect() — scale, origin and margins are
 * all folded into that one rect, so no zoom math is duplicated. The visible
 * band starts below the sticky [data-editor-header], which overlays the top
 * of the viewport.
 *
 * The panel auto-hides while the whole canvas fits the viewport — a map of a
 * fully visible canvas is dead chrome (auto-fit starts every template that
 * way; the map appears the moment zooming/scrolling crops the view).
 *
 * Thumbnail: drawImage of Fabric's lowerCanvasEl (the composited scene) onto
 * a small canvas, throttled behind after:render — one scaled blit, no Fabric
 * re-render, cheap even on layer-heavy print canvases. setTimeout (not rAF —
 * rAF is dead in hidden tabs and this must not queue up work there).
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static targets = ["panel", "thumb", "rect"];

    static MAX_THUMB_WIDTH = 190;
    static MAX_THUMB_HEIGHT = 150;

    initialize() {
        // Outlet callbacks can fire before connect() — state must exist.
        this._thumbTimer = null;
        this._dragging = false;
    }

    connect() {
        this._onScroll = () => this.refresh();
        this._onResize = () => this.refresh();
        window.addEventListener('scroll', this._onScroll, { passive: true });
        window.addEventListener('resize', this._onResize);
        this.refresh();
    }

    disconnect() {
        window.removeEventListener('scroll', this._onScroll);
        window.removeEventListener('resize', this._onResize);
        if (this._thumbTimer) {
            clearTimeout(this._thumbTimer);
            this._thumbTimer = null;
        }
    }

    canvasEditorOutletConnected(outlet) {
        this._canvas = outlet.canvas;
        this._onAfterRender = () => this._scheduleThumb();
        this._canvas.on('after:render', this._onAfterRender);
        this._scheduleThumb();
        this.refresh();
    }

    canvasEditorOutletDisconnected(outlet) {
        if (outlet.canvas && this._onAfterRender) {
            outlet.canvas.off('after:render', this._onAfterRender);
        }
        this._canvas = null;
    }

    /** Recompute panel visibility + viewport rectangle. Wired to window
     *  scroll/resize here and to canvas-zoom:changed / canvas:loaded in the
     *  editor templates. */
    refresh() {
        if (!this.hasPanelTarget || !this.hasRectTarget) return;
        const geo = this._geometry();
        if (!geo) {
            this.panelTarget.classList.add('d-none');
            return;
        }

        const fullyVisible = geo.visX <= 0.5 && geo.visY <= 0.5
            && geo.visW >= geo.logicalW - 1 && geo.visH >= geo.logicalH - 1;
        this.panelTarget.classList.toggle('d-none', fullyVisible);
        if (fullyVisible) return;

        this._sizeThumb(geo);

        const navScale = this._navScale(geo);
        this.rectTarget.style.left = `${geo.visX * navScale}px`;
        this.rectTarget.style.top = `${geo.visY * navScale}px`;
        this.rectTarget.style.width = `${geo.visW * navScale}px`;
        this.rectTarget.style.height = `${geo.visH * navScale}px`;
    }

    // --- panning ------------------------------------------------------------

    beginPan(event) {
        event.preventDefault();
        this._dragging = true;
        this._panTo(event);
        this._onPanMove = (e) => { if (this._dragging) this._panTo(e); };
        this._onPanUp = () => {
            this._dragging = false;
            document.removeEventListener('mousemove', this._onPanMove);
            document.removeEventListener('mouseup', this._onPanUp);
        };
        document.addEventListener('mousemove', this._onPanMove);
        document.addEventListener('mouseup', this._onPanUp);
    }

    /** Scroll the window so the clicked/dragged map point lands in the middle
     *  of the visible band (below the sticky header). */
    _panTo(event) {
        const geo = this._geometry();
        if (!geo || !this.hasThumbTarget) return;
        const thumbRect = this.thumbTarget.getBoundingClientRect();
        const navScale = this._navScale(geo);
        if (!navScale) return;

        const px = Math.max(0, Math.min(geo.logicalW, (event.clientX - thumbRect.left) / navScale));
        const py = Math.max(0, Math.min(geo.logicalH, (event.clientY - thumbRect.top) / navScale));

        // Page coordinates of the target canvas point.
        const pageX = window.scrollX + geo.rect.left + px * geo.displayScale;
        const pageY = window.scrollY + geo.rect.top + py * geo.displayScale;
        const bandCenterY = geo.topBound + (window.innerHeight - geo.topBound) / 2;

        window.scrollTo({
            left: Math.max(0, pageX - window.innerWidth / 2),
            top: Math.max(0, pageY - bandCenterY),
            behavior: 'auto',
        });
    }

    // --- thumbnail ----------------------------------------------------------

    _scheduleThumb() {
        if (this._thumbTimer) return;
        this._thumbTimer = setTimeout(() => {
            this._thumbTimer = null;
            this._drawThumb();
        }, 250);
    }

    _drawThumb() {
        if (!this._canvas || !this.hasThumbTarget) return;
        const source = this._canvas.lowerCanvasEl;
        if (!source || !source.width || !source.height) return;
        const geo = this._geometry();
        if (!geo) return;

        this._sizeThumb(geo);
        const thumb = this.thumbTarget;
        const ctx = thumb.getContext('2d');
        if (!ctx) return;
        ctx.clearRect(0, 0, thumb.width, thumb.height);
        ctx.drawImage(source, 0, 0, source.width, source.height, 0, 0, thumb.width, thumb.height);
    }

    /** Fit the thumb canvas to the canvas aspect (2× backing store for
     *  sharpness); no-op when already sized — resizing clears a canvas. */
    _sizeThumb(geo) {
        if (!this.hasThumbTarget) return;
        const ratio = Math.min(
            this.constructor.MAX_THUMB_WIDTH / geo.logicalW,
            this.constructor.MAX_THUMB_HEIGHT / geo.logicalH,
        );
        const cssW = Math.max(1, Math.round(geo.logicalW * ratio));
        const cssH = Math.max(1, Math.round(geo.logicalH * ratio));
        const thumb = this.thumbTarget;
        if (thumb.style.width !== `${cssW}px`) {
            thumb.style.width = `${cssW}px`;
            thumb.style.height = `${cssH}px`;
        }
        if (thumb.width !== cssW * 2 || thumb.height !== cssH * 2) {
            thumb.width = cssW * 2;
            thumb.height = cssH * 2;
            this._scheduleThumb();
        }
    }

    _navScale(geo) {
        if (!this.hasThumbTarget) return 0;
        return (parseFloat(this.thumbTarget.style.width) || this.thumbTarget.clientWidth) / geo.logicalW;
    }

    /** Everything derived from the live canvas element's screen rect. */
    _geometry() {
        if (!this._canvas) return null;
        const el = typeof this._canvas.getElement === 'function' ? this._canvas.getElement() : null;
        if (!el) return null;
        const rect = el.getBoundingClientRect();
        if (!rect.width || !rect.height) return null;

        const logicalW = this._canvas.getWidth();
        const logicalH = this._canvas.getHeight();
        if (!logicalW || !logicalH) return null;
        const displayScale = rect.width / logicalW;

        // The sticky editor header overlays the top of the viewport — the
        // truly visible band starts below it.
        const header = document.querySelector('[data-editor-header]');
        const topBound = header ? Math.max(0, header.getBoundingClientRect().bottom) : 0;

        const visLeftCss = Math.max(rect.left, 0);
        const visTopCss = Math.max(rect.top, topBound);
        const visRightCss = Math.min(rect.right, window.innerWidth);
        const visBottomCss = Math.min(rect.bottom, window.innerHeight);

        return {
            rect,
            logicalW,
            logicalH,
            displayScale,
            topBound,
            visX: Math.max(0, (visLeftCss - rect.left) / displayScale),
            visY: Math.max(0, (visTopCss - rect.top) / displayScale),
            visW: Math.max(0, (visRightCss - visLeftCss) / displayScale),
            visH: Math.max(0, (visBottomCss - visTopCss) / displayScale),
        };
    }
}
