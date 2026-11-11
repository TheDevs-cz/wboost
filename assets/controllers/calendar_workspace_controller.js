/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { bleedOf } from '../calendar/model/defaults.js';
import { visibleBoxKeys } from '../calendar/model/elements.js';
import { normalizeDocument } from '../calendar/model/normalize.js';
import { pluralPages } from '../calendar/generate/czech.js';
import { createCanvasMeasurer, ensureFontsLoaded, usedFamilies } from '../calendar/render/measure.js';
import { createApi } from '../calendar/state/api.js';
import { createStore } from '../calendar/state/store.js';
import { choiceDialog, confirmDialog, h, toast } from '../calendar/ui/dom.js';
import { mountTopBar } from '../calendar/ui/topbar.js';
import { mountStep1 } from '../calendar/ui/step1.js';
import { mountStep2 } from '../calendar/ui/step2.js';
import { mountStep4 } from '../calendar/ui/step4.js';
import { mountStep5 } from '../calendar/ui/step5.js';
import { mountEditor } from '../calendar/ui/editor/index.js';
import { openTool } from '../calendar/ui/tools/index.js';

/**
 * The calendar workspace (docs/calendar/architecture.md §D.3): parses the
 * bootstrap JSON, creates the store and the `Ctx`, mounts the top bar and
 * the module of the current step into `.cal-ws`, and owns everything that
 * is about the page as a whole — Ctrl+S, the leave confirmation,
 * `beforeunload`, Dokončit, and the read-only test hook `window.__calendar`.
 *
 *   bootstrap   <script type="application/json"> with { mode, calendar, assets, env }
 *   mode value  designer | client (client = step 5 only, reduced top bar)
 *
 * `Ctx` handed to every UI module:
 *   store, env, api, measurer
 *   toast(message, error?)                 kit toast
 *   confirm({ title, text, confirmLabel, cancelLabel?, danger? }) → Promise<boolean>
 *   openTool(key)                          opens a content tool (ui/tools/index.js)
 *   setStageProbe(probe | null)            the module showing "the page on the stage" registers
 *                                          { scale(): px per mm, toClient(xMm, yMm): { x, y }, page(): Page | null }
 *                                          so window.__calendar can answer scale() / toClient() / geometry()
 *   actions.save() / .leave() / .finish()  explicit save with toasts · back to the list (asks when
 *                                          unsaved) · Dokončit / Uložit vzhled
 */
export default class extends Controller {
    static targets = ['bootstrap'];

    static values = { mode: String };

    connect() {
        const boot = JSON.parse(this.bootstrapTarget.textContent);
        const palette = Array.isArray(boot.env.palette) ? boot.env.palette : [];
        const env = {
            ...boot.env,
            mode: boot.mode === 'designer' ? 'designer' : 'client',
            fonts: Array.isArray(boot.env.fonts) ? boot.env.fonts : [],
            palette,
            // first project colour that is not pure white / black (prototype `zpAcc`)
            accent: palette.find((c) => c !== '#FFFFFF' && c !== '#000000') || null,
            siblings: boot.env.siblings || [],
            urls: boot.env.urls || {},
            clientUrl: boot.env.clientUrl || null,
            assets: boot.assets && typeof boot.assets === 'object' ? boot.assets : {},
        };
        const measurer = createCanvasMeasurer();
        const api = createApi(env);
        const store = createStore({ doc: normalizeDocument(boot.calendar, env), env, api, measurer });

        this.env = env;
        this.store = store;
        this.probe = null;
        this.leaving = false;
        this.mounted = null;
        this.shownStep = null;

        this.ctx = {
            store,
            env,
            api,
            measurer,
            toast,
            confirm: confirmDialog,
            openTool: (key) => openTool(key, this.ctx),
            setStageProbe: (probe) => {
                this.probe = probe;
            },
            actions: { save: () => this.save(), leave: () => this.leave(), finish: () => this.finish() },
        };

        this.top = h('div', { class: 'cal-ws-top' });
        this.body = h('div', { class: 'cal-ws-body' });
        this.element.append(this.top, this.body);
        this.element.classList.add(`mode-${env.mode}`);

        this.onKeyDown = (event) => {
            if ((event.ctrlKey || event.metaKey) && !event.altKey && event.key.toLowerCase() === 's') {
                event.preventDefault();
                this.save();
            }
        };
        this.onBeforeUnload = (event) => {
            if (this.leaving || !store.isDirty()) return;
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('keydown', this.onKeyDown);
        window.addEventListener('beforeunload', this.onBeforeUnload);

        this.installHook();

        const layout = store.getState().doc.design.layout;
        ensureFontsLoaded(env.fonts, layout ? usedFamilies(layout) : env.fonts.map((f) => f.family))
            .catch(() => null)
            .then(() => {
                if (!this.store) return;
                this.topBar = mountTopBar(this.top, this.ctx);
                this.unsubscribe = store.subscribe((event) => {
                    if (event.type === 'design' || event.type === 'replace' || event.type === 'undo' || (event.type === 'layout' && !event.live)) this.showStep();
                });
                this.showStep();
                this.element.dataset.calReady = '1';
                this.resolveReady();
            });
    }

    disconnect() {
        window.removeEventListener('keydown', this.onKeyDown);
        window.removeEventListener('beforeunload', this.onBeforeUnload);
        if (this.unsubscribe) this.unsubscribe();
        if (this.mounted) this.mounted.destroy();
        if (this.topBar) this.topBar.destroy();
        this.top.remove();
        this.body.remove();
        this.store = null;
        if (window.__calendar === this.hook) delete window.__calendar;
    }

    /** The step whose module is shown: a client always sees step 5; steps 3–5 need a layout. */
    effectiveStep() {
        const { design } = this.store.getState().doc;
        if (this.env.mode !== 'designer') return 5;
        return design.step >= 3 && !design.layout ? 2 : design.step;
    }

    showStep() {
        const step = this.effectiveStep();
        if (step === this.shownStep) return;
        if (this.mounted) this.mounted.destroy();
        this.mounted = null;
        this.probe = null;
        this.shownStep = step;
        this.element.dataset.calShownStep = String(step);
        this.body.replaceChildren();
        try {
            this.mounted = this.mountStep(step);
        } catch (error) {
            console.error('[calendar] step failed to mount', error);
            toast('Tento krok se nepodařilo zobrazit. Obnovte stránku.', true);
        }
    }

    mountStep(step) {
        const { ctx, body } = this;
        if (step === 1) return mountStep1(body, ctx);
        if (step === 2) return mountStep2(body, ctx);
        if (step === 4) return mountStep4(body, ctx);
        if (step === 5) return mountStep5(body, ctx);
        // step 3: the three columns are the slots of the editor (§D.6 "Mounting")
        const layers = h('aside', { class: 'cal-left', 'aria-label': 'Vrstvy' });
        const stage = h('section', { class: 'cal-center' });
        const props = h('aside', { class: 'cal-right', 'aria-label': 'Vlastnosti' });
        body.append(h('div', { class: 'cal-b3' }, layers, stage, props));
        const editor = mountEditor({ layers, stage, props }, ctx, { embedded: false });
        return {
            destroy: () => {
                editor.destroy();
                body.replaceChildren();
            },
        };
    }

    /** A value typed into a field is committed before saving (number fields commit on `change`). */
    commitField() {
        const active = document.activeElement;
        if (active && this.element.contains(active) && active.matches('input, textarea')) active.dispatchEvent(new Event('change', { bubbles: true }));
    }

    async save() {
        this.commitField();
        const result = await this.store.save();
        if (result.ok) toast(this.env.mode === 'designer' ? 'Kalendář uložen — najdete ho v seznamu kalendářů' : 'Změny uloženy');
        else toast(result.error || 'Uložení se nepovedlo', true);
        return result;
    }

    go(url) {
        this.leaving = true;
        window.location.assign(url);
    }

    async leave() {
        const url = this.env.urls.list;
        if (!url) return;
        this.commitField();
        if (!this.store.isDirty()) {
            this.go(url);
            return;
        }
        const answer = await choiceDialog({
            title: 'Neuložené změny',
            text: 'Kalendář má neuložené změny.',
            buttons: [
                { key: 'stay', label: 'Zůstat', kind: 'ghost' },
                { key: 'discard', label: 'Odejít bez uložení', kind: 'tertiary' },
                { key: 'save', label: 'Uložit a odejít', kind: 'primary' },
            ],
        });
        if (answer === 'discard') {
            this.go(url);
        } else if (answer === 'save') {
            const result = await this.store.save();
            if (result.ok) this.go(url);
            else toast(result.error || 'Uložení se nepovedlo', true);
        }
    }

    async finish() {
        this.commitField();
        const first = !this.store.getState().doc.design.finished;
        const result = await this.store.save({ finish: true });
        if (!result.ok) {
            toast(result.error || 'Uložení se nepovedlo', true);
            return;
        }
        const count = this.store.pages().length;
        const message = first ? `Kalendář poskládán — ${count} ${pluralPages(count)}` : 'Vzhled uložen — všechny strany přeskládány';
        if (!this.env.urls.list) {
            toast(message);
            return;
        }
        try {
            sessionStorage.setItem('wb-cal-toast', message);
            // the list offers „Stáhnout PDF“ for this calendar in that toast
            sessionStorage.setItem('wb-cal-toast-calendar', this.store.getState().doc.id || '');
        } catch (e) {
            // private mode: the list simply shows no toast
        }
        this.go(this.env.urls.list);
    }

    /** Read-only hook for the e2e scripts (§D.4 "Test hook"). Never used by the application. */
    installHook() {
        const { store, env } = this;
        const copy = (value) => JSON.parse(JSON.stringify(value));
        const ready = new Promise((resolve) => {
            this.resolveReady = resolve;
        });
        this.hook = {
            ready,
            state: () => {
                const s = store.getState();
                return copy({
                    design: s.doc.design,
                    content: s.doc.content,
                    revision: s.doc.revision,
                    selection: s.ui.selection,
                    zoom: s.ui.zoom,
                    status: s.status,
                    mode: env.mode,
                    canUndo: store.canUndo(),
                    canRedo: store.canRedo(),
                });
            },
            boxes: () => {
                const { design } = store.getState().doc;
                const out = {};
                for (const key of visibleBoxKeys(design)) out[key] = copy(design.layout.boxes[key]);
                return out;
            },
            page: () => {
                const { design } = store.getState().doc;
                return { width: design.page.width, height: design.page.height, bleedMm: bleedOf(design.print), bandHeight: design.page.bandHeight };
            },
            scale: () => (this.probe ? this.probe.scale() : 0),
            toClient: (xMm, yMm) => (this.probe ? this.probe.toClient(xMm, yMm) : { x: 0, y: 0 }),
            pages: () => copy(store.pages()),
            geometry: (pageIndex) => {
                const page = pageIndex === undefined ? (this.probe ? this.probe.page() : null) : store.pages()[pageIndex];
                const geometry = page ? store.geometry(page) : null;
                return geometry ? copy(geometry) : null;
            },
            warnings: () => copy([...store.warnings().values()].flat()),
        };
        window.__calendar = this.hook;
    }
}
