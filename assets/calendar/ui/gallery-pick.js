// „Vybrat z galerie“ — a picture of the project's gallery as a calendar photo (feedback K14 P2,
// docs/calendar/photo-editor-plan.md). Designer only: the gallery modal (`Project:ImageGallery`,
// `#calScanGalleryModal` in templates/calendar/workspace.html.twig — shared with the calendar scan)
// is rendered for the designer's workspace alone, never on the client link.
//
// `hasGallery(): boolean` — the modal is on the page
// `pickGalleryAsset(ctx, kind): Promise<assetInfo | null>` — opens the modal; the picked picture's
//   BYTES are copied into a new calendar asset by the server (`fileId` on the asset endpoint, the
//   very upload rules); the info is added to the store (`store.addAsset`) and returned. `null` when
//   the modal was closed without a pick or the copy failed (a toast says why).
//
// Only one pick at a time; the scan's own gallery use (`ui/scan.js`) arms its handler only while it
// waits for its place, so the two never both take one `asset-selected`.

const MODAL_ID = 'calScanGalleryModal';
let pending = null;

export function hasGallery() {
    return Boolean(document.getElementById(MODAL_ID)) && Boolean(window.bootstrap);
}

export function pickGalleryAsset(ctx, kind) {
    const el = document.getElementById(MODAL_ID);
    if (!el || !window.bootstrap) {
        ctx.toast('Galerii se nepodařilo otevřít. Načtěte stránku znovu.', true);
        return Promise.resolve(null);
    }
    if (pending) pending.finish(null);
    const modal = window.bootstrap.Modal.getOrCreateInstance(el);
    return new Promise((resolve) => {
        let done = false;
        const finish = (value) => {
            if (done) return;
            done = true;
            pending = null;
            window.removeEventListener('asset-selected', onSelected);
            el.removeEventListener('hidden.bs.modal', onHidden);
            resolve(value);
        };
        const onHidden = () => finish(null);
        const onSelected = async (event) => {
            const id = event.detail && event.detail.id;
            if (!id) return;
            window.removeEventListener('asset-selected', onSelected);
            el.removeEventListener('hidden.bs.modal', onHidden);
            modal.hide();
            try {
                const info = await ctx.api.uploadFromGallery(id, kind);
                ctx.store.addAsset(info);
                finish(info);
            } catch (error) {
                ctx.toast((error && error.message) || 'Obrázek z galerie se nepodařilo použít', true);
                finish(null);
            }
        };
        pending = { finish };
        window.addEventListener('asset-selected', onSelected);
        el.addEventListener('hidden.bs.modal', onHidden);
        modal.show();
    });
}
