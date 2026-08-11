import { Circle, Color, Ellipse, Gradient, Polygon, Rect, Triangle } from "fabric";

/**
 * Vector SHAPES for the admin canvas editor — the third "put something on the
 * stage" primitive next to text and images ("Přidat tvar" in the left panel).
 *
 * Not a Stimulus controller (the missing `_controller` suffix keeps it out of
 * auto-registration): a pure factory + fill helpers shared by the orchestrator
 * (which adds shapes), the shape-properties popover (which edits them) and the
 * group-sync engine (which fans their styling out across dimensions).
 *
 * Shapes are DECORATIVE by design: they are never fillable inputs, so they do
 * not appear in the textInputs / imageInputs DTOs and the API contract is
 * untouched. They still carry an `inputId` like every other object, because
 * that is the join key the group editor propagates and the container engine
 * addresses members by.
 *
 * Everything a shape looks like — fill (solid or gradient), stroke, corner
 * radius, opacity — is a NATIVE Fabric property, so it serializes into the
 * canvas JSONB and re-renders through the headless export with no server-side
 * registration whatsoever (`canvas.loadFromJSON` enlivens every built-in type).
 */

/** Fabric type names (lower-cased) that count as a shape. */
const SHAPE_FABRIC_TYPES = new Set([
    'rect', 'circle', 'ellipse', 'triangle', 'polygon', 'polyline', 'line', 'path',
]);

/**
 * Is this a shape object? Deliberately type-based rather than flag-based, so
 * shapes that arrive from anywhere (a legacy canvas, a paste, a future import)
 * are recognised without depending on a custom property being present.
 */
export function isShapeObject(obj) {
    return Boolean(obj) && SHAPE_FABRIC_TYPES.has(String(obj.type || '').toLowerCase());
}

/**
 * The kinds offered in the "Přidat tvar" picker. `shapeKind` is persisted as a
 * custom property purely so the layers panel can name the row honestly — a
 * "Čára" and a "Čtverec" are both a Fabric `Rect`, and "Obdélník" for both
 * would make the panel useless. Nothing renders off it.
 */
export const SHAPE_KINDS = {
    rectangle: { label: 'Obdélník', icon: 'mdi-rectangle-outline' },
    square: { label: 'Čtverec', icon: 'mdi-square-outline' },
    circle: { label: 'Kruh', icon: 'mdi-circle-outline' },
    ellipse: { label: 'Elipsa', icon: 'mdi-ellipse-outline' },
    triangle: { label: 'Trojúhelník', icon: 'mdi-triangle-outline' },
    line: { label: 'Čára', icon: 'mdi-minus' },
    star: { label: 'Hvězda', icon: 'mdi-star-outline' },
};

/** Fallback fill when the project's manuals declare no brand colors. */
const DEFAULT_SHAPE_FILL = '#6c757d';

/**
 * Interaction flags every shape is created with — and that
 * `applyShapeDefaults` re-applies on load, because Fabric serializes none of
 * them (the same reason `applyTextboxDefaults` exists for textboxes).
 *
 * Shapes scale freely on every axis and rotate, unlike textboxes: they have no
 * glyph metrics to distort and no wrap width to keep meaningful.
 * `strokeUniform` keeps the border a constant on-screen weight while the
 * designer resizes, which is what every design tool does — the cost is that
 * the group projector has to scale `strokeWidth` itself (see group_projection).
 */
const SHAPE_INTERACTION = {
    originX: 'left',
    originY: 'top',
    strokeUniform: true,
    cornerStyle: 'circle',
    cornerSize: 10,
    selectable: true,
};

/**
 * Re-apply the flags above to a shape that came back from the canvas JSON, so
 * a reloaded shape behaves exactly like a freshly added one. Origins are
 * included on purpose: Fabric v7 defaults to centre origins, and a shape saved
 * by an older path (or hand-edited JSON) without explicit origins would
 * otherwise jump by half its size on load.
 */
export function applyShapeDefaults(obj) {
    if (!obj) return;
    obj.strokeUniform = true;
    obj.cornerStyle = 'circle';
    obj.cornerSize = 10;
    if (typeof obj.setCoords === 'function') obj.setCoords();
}

/**
 * Build a shape sized relative to the CANVAS, not in absolute pixels: the same
 * editor serves 1080×1080 social posts and A4-at-300dpi print canvases
 * (2480×3508), where a fixed 300 px rectangle is respectively half the design
 * and an invisible speck.
 *
 * Freshly added shapes land centred, cascaded by a few percent per add so a
 * second shape does not hide perfectly behind the first.
 *
 * @param {string} kind          one of SHAPE_KINDS
 * @param {Object} options
 * @param {number} options.canvasWidth
 * @param {number} options.canvasHeight
 * @param {string} options.fill  solid colour for the new shape
 * @param {number} options.cascade  how many shapes were added before this one
 * @returns {Object|null} a Fabric object, or null for an unknown kind
 */
export function createShapeObject(kind, { canvasWidth, canvasHeight, fill = null, cascade = 0 }) {
    const width = canvasWidth > 0 ? canvasWidth : 1000;
    const height = canvasHeight > 0 ? canvasHeight : 1000;
    // A quarter of the SHORT edge: big enough to grab and restyle immediately,
    // small enough never to swallow the design it was dropped onto.
    const unit = Math.max(24, Math.round(Math.min(width, height) * 0.25));
    const paint = fill || DEFAULT_SHAPE_FILL;

    const base = {
        ...SHAPE_INTERACTION,
        fill: paint,
        stroke: null,
        // Zero, not Fabric's default 1: a hairline border nobody asked for
        // would also inflate every bounding box (snapping, container flow,
        // the highlight overlay) by half a pixel on each side.
        strokeWidth: 0,
        shapeKind: kind,
        inputId: crypto.randomUUID(),
    };

    let shape = null;

    switch (kind) {
        case 'rectangle':
            shape = new Rect({ ...base, width: Math.round(unit * 1.5), height: unit, rx: 0, ry: 0 });
            break;
        case 'square':
            shape = new Rect({ ...base, width: unit, height: unit, rx: 0, ry: 0 });
            break;
        case 'circle':
            shape = new Circle({ ...base, radius: Math.round(unit / 2) });
            break;
        case 'ellipse':
            shape = new Ellipse({ ...base, rx: Math.round(unit * 0.6), ry: Math.round(unit * 0.4) });
            break;
        case 'triangle':
            shape = new Triangle({ ...base, width: Math.round(unit * 1.15), height: unit });
            break;
        case 'line':
            // A divider is a thin filled bar rather than a Fabric `Line`: a
            // real Line has zero height on one axis, which makes it
            // un-resizable in that direction and gives the snapping engine and
            // the container flow a degenerate box to reason about. As a Rect
            // it snaps, scales, rounds its ends (corner radius) and projects
            // across dimensions like everything else.
            shape = new Rect({
                ...base,
                width: Math.round(width * 0.6),
                height: Math.max(2, Math.round(Math.min(width, height) * 0.006)),
                rx: 0,
                ry: 0,
            });
            break;
        case 'star':
            shape = new Polygon(starPoints(Math.round(unit / 2)), { ...base });
            break;
        default:
            return null;
    }

    centerOnCanvas(shape, width, height, cascade);
    shape.setCoords();

    return shape;
}

/** Five-pointed star, points in a 2R × 2R box (Fabric derives its own bbox). */
function starPoints(outerRadius, points = 5, innerRatio = 0.45) {
    const result = [];
    for (let i = 0; i < points * 2; i += 1) {
        const radius = i % 2 === 0 ? outerRadius : outerRadius * innerRatio;
        const angle = (Math.PI / points) * i - Math.PI / 2;
        result.push({
            x: outerRadius + radius * Math.cos(angle),
            y: outerRadius + radius * Math.sin(angle),
        });
    }
    return result;
}

/**
 * Centre a freshly built shape, offset by a small cascade step so repeated
 * adds are visibly distinct. Clamped to the canvas so an oversized shape on a
 * small canvas still starts at the origin rather than off-stage.
 */
function centerOnCanvas(shape, canvasWidth, canvasHeight, cascade) {
    const displayedWidth = shape.width * (shape.scaleX || 1);
    const displayedHeight = shape.height * (shape.scaleY || 1);
    const step = (cascade % 6) * Math.round(Math.min(canvasWidth, canvasHeight) * 0.02);

    shape.set({
        left: Math.max(0, Math.round((canvasWidth - displayedWidth) / 2) + step),
        top: Math.max(0, Math.round((canvasHeight - displayedHeight) / 2) + step),
    });
}

/**
 * A shape's default stroke weight once the designer picks a border colour —
 * canvas-relative for the same reason the sizes above are.
 */
export function defaultStrokeWidth(canvasWidth, canvasHeight) {
    return Math.max(1, Math.round(Math.min(canvasWidth || 1000, canvasHeight || 1000) * 0.004));
}

// --- fills: solid colour vs gradient -------------------------------------

/**
 * Build a Fabric gradient in PERCENTAGE units — coordinates are 0…1 of the
 * object's own bounding box, so the gradient survives every scale the object
 * will see: the designer resizing it, the group projector scaling it into
 * another dimension, and the export rendering it at print resolution. A
 * pixel-unit gradient would be baked to the size the shape happened to have
 * when it was picked and would visibly slide off on any of those.
 *
 * @param {Object} spec
 * @param {string} spec.type   'linear' | 'radial'
 * @param {string} spec.from   start colour
 * @param {string} spec.to     end colour
 * @param {number} spec.angle  degrees, 0 = left→right, 90 = top→bottom (linear only)
 */
export function buildGradient({ type = 'linear', from = '#000000', to = '#ffffff', angle = 90 }) {
    const colorStops = [
        { offset: 0, color: from },
        { offset: 1, color: to },
    ];

    if (type === 'radial') {
        return new Gradient({
            type: 'radial',
            gradientUnits: 'percentage',
            coords: { x1: 0.5, y1: 0.5, r1: 0, x2: 0.5, y2: 0.5, r2: 0.5 },
            colorStops,
        });
    }

    const radians = (Number(angle) || 0) * (Math.PI / 180);
    const dx = Math.cos(radians) / 2;
    const dy = Math.sin(radians) / 2;

    return new Gradient({
        type: 'linear',
        gradientUnits: 'percentage',
        coords: { x1: 0.5 - dx, y1: 0.5 - dy, x2: 0.5 + dx, y2: 0.5 + dy },
        colorStops,
    });
}

/**
 * Read an object's current fill back into the shape of the popover's controls,
 * so opening the popover shows what is actually on the canvas (including a
 * gradient authored in an earlier session).
 *
 * @returns {{mode: 'solid'|'gradient', color: string, type: string, from: string, to: string, angle: number}}
 */
export function describeFill(obj) {
    const fill = obj ? obj.fill : null;

    if (fill && typeof fill === 'object') {
        const stops = Array.isArray(fill.colorStops)
            ? [...fill.colorStops].sort((a, b) => (a.offset || 0) - (b.offset || 0))
            : [];
        const coords = fill.coords || {};
        const isRadial = fill.type === 'radial';

        return {
            mode: 'gradient',
            color: toHexColor(stops.length ? stops[0].color : null),
            type: isRadial ? 'radial' : 'linear',
            from: toHexColor(stops.length ? stops[0].color : null),
            to: toHexColor(stops.length > 1 ? stops[stops.length - 1].color : null, '#ffffff'),
            angle: isRadial ? 90 : gradientAngle(coords),
        };
    }

    const color = toHexColor(typeof fill === 'string' ? fill : null);

    return { mode: 'solid', color, type: 'linear', from: color, to: '#ffffff', angle: 90 };
}

/** Angle (0…359°) of a linear gradient's coordinate pair. */
function gradientAngle(coords) {
    const dx = (coords.x2 || 0) - (coords.x1 || 0);
    const dy = (coords.y2 || 0) - (coords.y1 || 0);
    if (dx === 0 && dy === 0) return 90;
    const degrees = Math.round((Math.atan2(dy, dx) * 180) / Math.PI);
    return ((degrees % 360) + 360) % 360;
}

/**
 * Normalise any CSS colour Fabric accepts (named, rgb(), rgba(), short hex)
 * into `#rrggbb` — `<input type="color">` and the swatch comparison accept
 * nothing else. Alpha is deliberately dropped: shapes express transparency
 * through the `opacity` control, which applies to fill AND stroke together.
 */
export function toHexColor(value, fallback = '#000000') {
    if (typeof value !== 'string' || value.trim() === '') return fallback;
    if (/^#[0-9a-f]{6}$/i.test(value)) return value.toLowerCase();
    try {
        const hex = new Color(value).toHex();
        return /^[0-9a-f]{6}$/i.test(hex) ? `#${hex.toLowerCase()}` : fallback;
    } catch {
        return fallback;
    }
}

/**
 * Deep-copy a paint value when it crosses canvases (group-sync propagation) or
 * baselines. A Fabric `Gradient` is a live object: handing the SAME instance to
 * every sibling variant's shadow canvas would alias state the engine assumes is
 * per-canvas, and comparing two of them by identity would report "unchanged"
 * for a gradient that was edited in place.
 */
export function clonePaintValue(value) {
    if (Array.isArray(value)) return value.slice();
    if (value && typeof value === 'object' && typeof value.toObject === 'function') {
        return new Gradient(value.toObject());
    }
    return value;
}

/** Value-comparable form of a paint value (plain object or scalar). */
export function serializePaintValue(value) {
    if (value && typeof value === 'object' && typeof value.toObject === 'function') {
        return value.toObject();
    }
    return value;
}
