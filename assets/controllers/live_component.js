import { getComponent } from "@symfony/ux-live-component";

/**
 * The Live component wrapping `element` (the fill form sits INSIDE the
 * component root), or null when the surface is not Live-rendered — the group
 * fill page posts plain fetches. Resolves through Live's own registry, which
 * polls briefly, so it is safe to call from a connect() that may run before
 * (or long after) the `live` controller's own.
 */
export function liveComponentFor(element) {
    const root = element.closest('[data-controller~="live"]');
    if (!root) {
        return Promise.resolve(null);
    }

    return getComponent(root).catch(() => null);
}
