import { Controller } from '@hotwired/stimulus';

/**
 * Forces input values to sync with their data-server-value after Live Component updates.
 * This fixes the issue where morphing preserves user-typed values instead of showing
 * the server-rendered values when switching between days.
 */
export default class extends Controller {
    connect() {
        console.log('weekly-menu-sync connected');

        // Listen for Live Component update event
        this.boundSync = this.syncInputValues.bind(this);
        this.element.addEventListener('live:update', this.boundSync);
    }

    disconnect() {
        console.log('weekly-menu-sync disconnected');
        this.element.removeEventListener('live:update', this.boundSync);
    }

    syncInputValues() {
        console.log('live:update event received, syncing inputs');

        // Small delay to ensure DOM is fully updated
        setTimeout(() => {
            const inputs = this.element.querySelectorAll('[data-server-value]');
            console.log('Found', inputs.length, 'inputs to sync');

            inputs.forEach(input => {
                const serverValue = input.dataset.serverValue || '';
                if (input.value !== serverValue) {
                    console.log('Updating:', input.name, 'from', input.value, 'to', serverValue);
                    input.value = serverValue;
                }
            });
        }, 10);
    }
}
