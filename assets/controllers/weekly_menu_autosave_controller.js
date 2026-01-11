import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

export default class extends Controller {
    static targets = ['input'];
    static values = {
        saveAction: String,
        debounce: { type: Number, default: 2000 }
    };

    connect() {
        this.timeout = null;
        this.pendingData = null;
        this.initializeLiveComponent();
    }

    async initializeLiveComponent() {
        const liveElement = this.element.closest('[data-controller*="live"]');
        if (liveElement) {
            this.component = await getComponent(liveElement);
        }
    }

    collectData() {
        const data = {};
        this.inputTargets.forEach(input => {
            const name = input.getAttribute('name');
            if (name) {
                data[name] = input.value;
            }
        });
        return data;
    }

    scheduleAutosave() {
        if (this.timeout) {
            clearTimeout(this.timeout);
        }

        // Cache data immediately in case we disconnect before timeout fires
        this.pendingData = this.collectData();

        this.timeout = setTimeout(() => {
            this.performAutosave();
        }, this.debounceValue);
    }

    save() {
        if (this.timeout) {
            clearTimeout(this.timeout);
        }
        this.pendingData = this.collectData();
        this.performAutosave();
    }

    async performAutosave() {
        if (!this.component) {
            await this.initializeLiveComponent();
        }

        if (!this.component || !this.pendingData) {
            return;
        }

        const data = this.pendingData;
        this.pendingData = null;
        this.timeout = null;

        try {
            await this.component.action(this.saveActionValue, data);
        } catch (error) {
            console.error('Autosave failed:', error);
        }
    }

    disconnect() {
        if (this.timeout) {
            clearTimeout(this.timeout);
        }
        // Save any pending changes before disconnecting (e.g., when switching days)
        if (this.pendingData) {
            this.performAutosave();
        }
    }
}
