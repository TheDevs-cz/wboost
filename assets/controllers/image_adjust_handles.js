/**
 * Resize / rotate handles for the picked picture on the SINGLE fill page,
 * drawn in a `position: fixed` layer on <body> instead of by Fabric.
 *
 * Why not Fabric's own controls: Fabric paints them INTO the canvas bitmap,
 * so a corner that lies past the canvas edge (the user pushed the photo off
 * the artwork — the usual crop move) is simply not drawn and can never be
 * grabbed again; the stage's scrolling viewport clips the rest. This layer
 * lives outside every clipping ancestor:
 *
 *  - the outline traces the WHOLE picture (also the part beyond the canvas),
 *    clipped only to the viewport's on-screen box plus a small margin;
 *  - a corner that falls outside that box is CLAMPED onto its edge
 *    (`.is-clamped`, hollow) — still grabbable;
 *  - every resize scales about the picture's CENTRE by the pointer's
 *    distance ratio, so a clamped handle behaves exactly like a real one and
 *    the pan (the placement's offset) never drifts while resizing — the same
 *    rule as the toolbar slider and the typed width/height.
 *
 * The layer is appended to <body> (not the Live component, whose morph would
 * remove foreign children) and removed on destroy().
 */
const MARGIN = 10;          // px the clip box extends past the viewport
const KNOB_DISTANCE = 26;   // px from the top edge to the rotate knob

export class ImageAdjustHandles {
    /**
     * @param {object} options
     * @param {() => HTMLElement|null} options.canvasElement the Fabric lower canvas
     * @param {() => HTMLElement|null} options.clipElement   the scrolling viewport
     * @param {number} options.canvasWidth                   canvas px width
     * @param {(factor: number, final: boolean) => void} options.onScale  factor vs. drag start
     * @param {(degrees: number, final: boolean) => void} options.onRotate absolute angle
     */
    constructor({ canvasElement, clipElement, canvasWidth, onScale, onRotate }) {
        this.canvasElement = canvasElement;
        this.clipElement = clipElement;
        this.canvasWidth = canvasWidth;
        this.onScale = onScale;
        this.onRotate = onRotate;
        this.object = null;
        this.drag = null;

        const layer = document.createElement('div');
        layer.className = 'fill-adjust-handles';
        layer.hidden = true;
        layer.setAttribute('aria-hidden', 'true');

        const svgNs = 'http://www.w3.org/2000/svg';
        this.svg = document.createElementNS(svgNs, 'svg');
        this.svg.classList.add('fill-adjust-handles__svg');
        this.outline = document.createElementNS(svgNs, 'polygon');
        this.outline.classList.add('fill-adjust-handles__outline');
        this.stem = document.createElementNS(svgNs, 'line');
        this.stem.classList.add('fill-adjust-handles__stem');
        this.svg.append(this.outline, this.stem);
        layer.appendChild(this.svg);

        this.corners = ['tl', 'tr', 'br', 'bl'].map((corner) => {
            const handle = document.createElement('div');
            handle.className = `fill-adjust-handle fill-adjust-handle--${corner}`;
            handle.dataset.corner = corner;
            handle.title = 'Táhněte pro změnu velikosti';
            handle.addEventListener('pointerdown', (event) => this._start(event, 'scale'));
            layer.appendChild(handle);
            return handle;
        });

        this.knob = document.createElement('div');
        this.knob.className = 'fill-adjust-handle fill-adjust-handle--rotate';
        this.knob.title = 'Táhněte pro otočení (Shift = po 15°)';
        this.knob.addEventListener('pointerdown', (event) => this._start(event, 'rotate'));
        layer.appendChild(this.knob);

        document.body.appendChild(layer);
        this.layer = layer;

        this._onMove = (event) => this._move(event);
        this._onEnd = (event) => this._end(event);
        this._tick = () => {
            this._raf = null;
            if (!this.object) return;
            this.update();
            this._raf = requestAnimationFrame(this._tick);
        };
    }

    destroy() {
        this.hide();
        this.layer.remove();
    }

    /** Track one Fabric object (centre origin) until hide(). */
    show(object, { allowResize, allowRotate }) {
        this.object = object;
        this.allowResize = Boolean(allowResize);
        this.allowRotate = Boolean(allowRotate);
        this.corners.forEach((handle) => { handle.hidden = !this.allowResize; });
        this.knob.hidden = !this.allowRotate;
        this.stem.style.display = this.allowRotate ? '' : 'none';
        this.layer.hidden = false;
        this.update();
        // A rAF loop rather than a pile of listeners: the geometry moves with
        // Fabric drags, the overlay's CSS zoom, page AND viewport scrolling,
        // and window resizes — one cheap per-frame read covers all of them.
        if (!this._raf) this._raf = requestAnimationFrame(this._tick);
    }

    hide() {
        this.object = null;
        this.layer.hidden = true;
        if (this._raf) cancelAnimationFrame(this._raf);
        this._raf = null;
        this._end();
    }

    isDragging() {
        return this.drag !== null;
    }

    /** Canvas px → client px mapper for the current on-screen canvas box. */
    _mapper() {
        const canvas = this.canvasElement();
        if (!canvas) return null;
        const rect = canvas.getBoundingClientRect();
        if (!(rect.width > 0)) return null;
        const k = rect.width / this.canvasWidth;

        return { k, map: (x, y) => ({ x: rect.left + x * k, y: rect.top + y * k }) };
    }

    _clipBox() {
        const viewport = this.clipElement();
        const rect = viewport ? viewport.getBoundingClientRect() : { left: 0, top: 0, right: window.innerWidth, bottom: window.innerHeight };

        return {
            left: Math.max(0, rect.left - MARGIN),
            top: Math.max(0, rect.top - MARGIN),
            right: Math.min(window.innerWidth, rect.right + MARGIN),
            bottom: Math.min(window.innerHeight, rect.bottom + MARGIN),
        };
    }

    /** Client-space geometry of the tracked picture. */
    _geometry() {
        const object = this.object;
        const mapper = this._mapper();
        if (!object || !mapper) return null;

        const center = object.getCenterPoint();
        const halfW = object.getScaledWidth() / 2;
        const halfH = object.getScaledHeight() / 2;
        const rad = ((object.angle || 0) * Math.PI) / 180;
        const cos = Math.cos(rad);
        const sin = Math.sin(rad);
        const at = (dx, dy) => mapper.map(center.x + dx * cos - dy * sin, center.y + dx * sin + dy * cos);

        return {
            k: mapper.k,
            center: mapper.map(center.x, center.y),
            corners: {
                tl: at(-halfW, -halfH),
                tr: at(halfW, -halfH),
                br: at(halfW, halfH),
                bl: at(-halfW, halfH),
            },
            topMid: at(0, -halfH),
            // The knob sits a constant SCREEN distance above the top edge.
            up: { x: sin, y: -cos },
        };
    }

    update() {
        const geometry = this._geometry();
        if (!geometry) {
            this.layer.hidden = true;
            return;
        }
        this.layer.hidden = false;
        const clip = this._clipBox();
        this.layer.style.clipPath = `inset(${clip.top}px ${window.innerWidth - clip.right}px ${window.innerHeight - clip.bottom}px ${clip.left}px)`;

        const { corners } = geometry;
        this.outline.setAttribute('points', ['tl', 'tr', 'br', 'bl'].map((c) => `${corners[c].x},${corners[c].y}`).join(' '));

        const inset = 7;
        const place = (element, point) => {
            const x = Math.min(Math.max(point.x, clip.left + inset), clip.right - inset);
            const y = Math.min(Math.max(point.y, clip.top + inset), clip.bottom - inset);
            element.style.transform = `translate(${x}px, ${y}px)`;
            element.classList.toggle('is-clamped', Math.abs(x - point.x) > 0.5 || Math.abs(y - point.y) > 0.5);
            return { x, y };
        };

        if (this.allowResize) {
            this.corners.forEach((handle) => place(handle, corners[handle.dataset.corner]));
        }
        if (this.allowRotate) {
            const knob = {
                x: geometry.topMid.x + geometry.up.x * KNOB_DISTANCE,
                y: geometry.topMid.y + geometry.up.y * KNOB_DISTANCE,
            };
            const placed = place(this.knob, knob);
            this.stem.setAttribute('x1', String(geometry.topMid.x));
            this.stem.setAttribute('y1', String(geometry.topMid.y));
            this.stem.setAttribute('x2', String(placed.x));
            this.stem.setAttribute('y2', String(placed.y));
        }
    }

    _start(event, kind) {
        if (event.button !== 0 || !this.object) return;
        const geometry = this._geometry();
        if (!geometry) return;
        event.preventDefault();
        event.stopPropagation();
        event.currentTarget.setPointerCapture?.(event.pointerId);

        const dx = event.clientX - geometry.center.x;
        const dy = event.clientY - geometry.center.y;
        this.drag = {
            kind,
            pointerId: event.pointerId,
            element: event.currentTarget,
            center: geometry.center,
            // A grab right on the centre would divide by ~0 — floor it.
            startDistance: Math.max(8, Math.hypot(dx, dy)),
            startPointerAngle: Math.atan2(dy, dx),
            startAngle: this.object.angle || 0,
            last: null,
        };
        this.layer.classList.add('is-dragging');
        document.documentElement.classList.add(kind === 'rotate' ? 'fill-adjust-rotating' : 'fill-adjust-scaling');
        window.addEventListener('pointermove', this._onMove);
        window.addEventListener('pointerup', this._onEnd);
        window.addEventListener('pointercancel', this._onEnd);
    }

    _move(event) {
        const drag = this.drag;
        if (!drag || event.pointerId !== drag.pointerId) return;
        event.preventDefault();
        const dx = event.clientX - drag.center.x;
        const dy = event.clientY - drag.center.y;

        if (drag.kind === 'scale') {
            drag.last = Math.hypot(dx, dy) / drag.startDistance;
            this.onScale(drag.last, false);
        } else {
            let degrees = drag.startAngle + ((Math.atan2(dy, dx) - drag.startPointerAngle) * 180) / Math.PI;
            degrees = ((degrees + 540) % 360) - 180;
            if (event.shiftKey) degrees = Math.round(degrees / 15) * 15;
            drag.last = degrees;
            this.onRotate(degrees, false);
        }
    }

    _end(event) {
        const drag = this.drag;
        if (!drag) return;
        if (event && event.pointerId !== undefined && event.pointerId !== drag.pointerId) return;
        window.removeEventListener('pointermove', this._onMove);
        window.removeEventListener('pointerup', this._onEnd);
        window.removeEventListener('pointercancel', this._onEnd);
        drag.element?.releasePointerCapture?.(drag.pointerId);
        this.drag = null;
        this.layer.classList.remove('is-dragging');
        document.documentElement.classList.remove('fill-adjust-rotating', 'fill-adjust-scaling');
        if (drag.last === null) return;
        if (drag.kind === 'scale') this.onScale(drag.last, true);
        else this.onRotate(drag.last, true);
    }
}
