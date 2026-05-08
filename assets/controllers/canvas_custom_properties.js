// Single source of truth for the custom properties Fabric serialises into
// canvas JSON (and that clone() must preserve). Imported by every Stimulus
// controller that round-trips canvas state — orchestrator, history,
// clipboard, etc. — so they stay in lockstep.
export const CANVAS_CUSTOM_PROPERTIES = ['name', 'maxLength', 'locked', 'uppercase', 'description', 'hidable', 'inputId'];
