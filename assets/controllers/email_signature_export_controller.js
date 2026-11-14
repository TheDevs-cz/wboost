import { Controller } from '@hotwired/stimulus';

/*
 * „Varianta podpisu“: the texts typed on the left go into the template's HTML;
 * the result is shown (preview) and offered to copy / download (code).
 *
 * SECURITY — the HTML is authored content: the template by the project's owner
 * or an admin, a variant's texts by anyone the project is shared with, and the
 * page is opened by all of them. It must never be written into this document
 * (an `onerror=` handler ran here, a `<style>` restyled the app). The preview
 * is an <iframe sandbox> WITHOUT allow-scripts whose document also carries a
 * Content-Security-Policy (previewPolicy, from the template) — nothing in it
 * runs, loads a frame, sends a form or a request other than pictures / styles /
 * fonts. `allow-same-origin` is there only so this page can read the frame's
 * size and patch its body while the user types.
 *
 * The code field and the download keep the authored HTML exactly: the policy,
 * the <base> and the page styles below exist in the frame only.
 */
export default class extends Controller {
    static targets = ['preview', 'input', 'codeInput'];

    static values = {
        sourceHtml: String,
        variantName: String,
        vcardQrUrl: String,
        previewPolicy: String,
    };

    connect() {
        this.frameHead = null;
        this.onFrameLoad = () => this.fitFrame();
        this.previewTarget.addEventListener('load', this.onFrameLoad);

        // The box gets narrower / wider (window, sidebar) → the content reflows.
        this.resizeObserver = new ResizeObserver(() => this.fitFrame());
        this.resizeObserver.observe(this.previewTarget.parentElement);

        // The frame mirrors the app's theme tokens (as the old in-page preview did).
        this.themeObserver = new MutationObserver(() => {
            this.frameHead = null;
            this.update();
        });
        this.themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

        this.update();
    }

    disconnect() {
        this.previewTarget.removeEventListener('load', this.onFrameLoad);
        this.resizeObserver.disconnect();
        this.themeObserver.disconnect();
    }

    update() {
        // Use DOMParser to preserve the original HTML structure including root elements
        const parser = new DOMParser();
        const doc = parser.parseFromString(this.sourceHtmlValue, 'text/html');

        this.inputTargets.forEach(input => {
            const id = input.dataset.textInputId;
            const val = input.value;

            // Replace by data attribute
            const span = doc.querySelector(`#${id}[data-text-placeholder]`);
            if (span) span.innerHTML = val;
        });

        // Get the complete HTML including the root elements
        let updatedHtml = doc.documentElement.outerHTML;

        // Replace fulltext placeholders (new functionality)
        this.inputTargets.forEach(input => {
            const id = input.dataset.textInputId;
            const val = input.value;
            const placeholder = `___${id}___`;
            updatedHtml = updatedHtml.replaceAll(placeholder, val);
        });

        // Replace vCard QR code placeholder
        updatedHtml = updatedHtml.replaceAll('___vcard_qr___', this.vcardQrUrlValue);

        this.renderPreview(updatedHtml);
        this.codeInputTarget.value = updatedHtml;
    }

    /**
     * Shows the signature in the sandboxed frame. The first time (and whenever
     * its <head> changes) the frame is loaded anew through `srcdoc`; while only
     * the body changes — typing — the body is swapped in place, so the pictures
     * do not blink. Either way the content is inert: scripting is switched off
     * for the frame's document, however its nodes got there.
     */
    renderPreview(html) {
        const frame = this.previewTarget;
        const signature = new DOMParser().parseFromString(html, 'text/html');
        const head = this.previewHead() + signature.head.innerHTML;
        const frameDocument = frame.contentDocument;

        if (this.frameHead === head && frameDocument && frameDocument.body && frameDocument.readyState === 'complete') {
            Array.from(frameDocument.body.attributes).forEach((attribute) => frameDocument.body.removeAttribute(attribute.name));
            Array.from(signature.body.attributes).forEach((attribute) => frameDocument.body.setAttribute(attribute.name, attribute.value));
            frameDocument.body.replaceChildren(...Array.from(signature.body.childNodes).map((node) => frameDocument.importNode(node, true)));
            this.fitFrame();
            // pictures that arrive later change the height
            frameDocument.querySelectorAll('img').forEach((image) => {
                if (!image.complete) {
                    image.addEventListener('load', () => this.fitFrame(), { once: true });
                    image.addEventListener('error', () => this.fitFrame(), { once: true });
                }
            });

            return;
        }

        this.frameHead = head;
        signature.head.innerHTML = head;

        const theme = document.documentElement.getAttribute('data-theme');
        if (theme) {
            signature.documentElement.setAttribute('data-theme', theme);
            signature.documentElement.setAttribute('data-bs-theme', theme);
        }

        frame.srcdoc = '<!DOCTYPE html>' + signature.documentElement.outerHTML;
    }

    /**
     * What the frame's document gets in front of the signature's own <head>:
     * the policy (first — it governs everything after it), a <base> that keeps
     * a clicked link from replacing the preview (new windows are not allowed
     * either, so a link does nothing), and the page's look the signature used
     * to inherit when it was drawn inside the page: the app's stylesheets and
     * the box's font and colour.
     */
    previewHead() {
        const escape = (text) => String(text).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
        const box = getComputedStyle(this.previewTarget.parentElement);
        const sheets = Array.from(document.querySelectorAll('link[rel="stylesheet"][href]'))
            .map((link) => `<link rel="stylesheet" href="${escape(link.href)}">`)
            .join('');

        return `<meta http-equiv="Content-Security-Policy" content="${escape(this.previewPolicyValue)}">`
            + '<meta charset="utf-8"><base target="_blank">'
            + sheets
            + '<style>html:root { color-scheme: light; } html:root, html:root > body { background: transparent; min-height: 0; height: auto; overflow: hidden; }'
            + ` html:root > body { margin: 0; padding: 0; color: ${box.color}; font-family: ${box.fontFamily.replace(/[<>]/g, '')}; font-size: ${box.fontSize}; line-height: ${box.lineHeight}; }</style>`;
    }

    /** The frame is as tall as its content; content wider than the box makes the BOX scroll. */
    fitFrame() {
        const frame = this.previewTarget;
        const frameDocument = frame.contentDocument;

        if (!frameDocument || !frameDocument.documentElement || !frameDocument.body) {
            return;
        }

        frame.style.width = '100%';
        const available = frame.clientWidth;
        const wanted = Math.max(frameDocument.documentElement.scrollWidth, frameDocument.body.scrollWidth);

        if (wanted > available + 1) {
            frame.style.width = wanted + 'px';
        }

        frame.style.height = '0px';
        frame.style.height = Math.max(frameDocument.documentElement.scrollHeight, frameDocument.body.scrollHeight) + 'px';
    }

    downloadHtml() {
        const htmlContent = this.codeInputTarget.value;
        const blob = new Blob([htmlContent], { type: 'text/html' });
        const url = URL.createObjectURL(blob);
        
        const filename = `${this.variantNameValue} - email podpis.html`;
        
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }
}
