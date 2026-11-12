/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { ScanApp } from '../ai-scan/page/app.js';

/*
 * "Šablona z obrázku" — the AI scan wizard's page (templates/ai_scan/page.html.twig;
 * docs/ai-scan/ux.md S1–S5; docs/architecture/ai-scan.md § "UI").
 *
 * The controller only hands the element and the boot JSON to ScanApp
 * (assets/ai-scan/page/app.js), which draws every screen and talks to the
 * scan's JSON endpoints: status every 2 s while a scan is in progress,
 * nothing otherwise.
 */
export default class extends Controller {
    static values = { boot: Object };

    connect() {
        this.app = new ScanApp(this.element, this.bootValue);
        this.app.start();
        // Test and debugging hook (tests/e2e/ai-scan): the live state of the page.
        window.__aiScan = this.app;
    }

    disconnect() {
        if (this.app) this.app.stop();
        if (window.__aiScan === this.app) delete window.__aiScan;
    }
}
