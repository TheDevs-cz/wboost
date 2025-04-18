import { Controller } from '@hotwired/stimulus';
import grapesjs from 'grapesjs';
import grapesjsPresetNewsletter from 'grapesjs-preset-newsletter';

export default class extends Controller {
    static targets = ['container', 'codeInput', 'textPlaceholdersInput', 'successMsg'];

    static values = {
        sourceHtml: String,
        background: String,
    };

    disconnect() {
        if (this.editor) this.editor.destroy();
    }

    connect() {
        const config = {
            container: this.containerTarget,
            height: '400px',
            storageManager: false,
            plugins: [grapesjsPresetNewsletter],
            pluginsOpts: {
                grapesjsPresetNewsletter: { inlineCss: true }
            },
        };

        if (this.hasSourceHtmlValue) {
            config.components = this.sourceHtmlValue;
        }

        this.editor = grapesjs.init(config);

        // Background image table block (only if a background URL is provided)
        if (this.hasBackgroundValue && this.backgroundValue) {
            const bgUrl = this.backgroundValue;

            this.editor.AssetManager.add({src: bgUrl});

            this.editor.BlockManager.add('background-image', {
                label: 'Pozadí',
                category: 'Základní',
                attributes: { class: 'fa fa-image' },
                content: `
                    <table background="${bgUrl}" style="background-image: url('${bgUrl}'); background-repeat: no-repeat; background-position: left top; background-attachment: scroll; background-size: auto; box-sizing: border-box; height: 150px; margin: 0 auto 10px auto; padding: 5px; width: 100%;" width="100%" height="150">
                        <tbody style="box-sizing: border-box;">
                            <tr style="box-sizing: border-box;">
                                <td style="box-sizing: border-box; padding: 0; margin: 0; vertical-align: top;" valign="top"></td>
                            </tr>
                        </tbody>
                    </table>`
            });
        }

        // Custom text block for editable spans
        this.editor.BlockManager.add('placeholder-text', {
            label: 'Měnitelný text',
            category: 'Základní',
            attributes: { class: 'fa fa-font' },
            content: {
                type: 'text',
                content: 'Text',
                tagName: 'span',
                attributes: { 'data-text-placeholder': '' },
                editable: true
            }
        });
    }

    serializeTextPlaceholders() {
        const html = this.editor.getHtml();
        const parser = document.createElement('div');
        parser.innerHTML = html;
        const elements = parser.querySelectorAll('[data-text-placeholder]');

        return Array.from(elements).map(el => ({
            id: el.id || null,
            content: el.innerHTML.trim(),
        }));
    }

    exportTemplate() {
        const html = this.editor.runCommand('gjs-get-inlined-html');
        const form = this.containerTarget.closest('form');

        this.codeInputTarget.value = html;
        this.textPlaceholdersInputTarget.value = JSON.stringify(this.serializeTextPlaceholders());

        // Submit the form with fetch, handling possible redirects
        fetch(form.action, {
            method: form.method,
            body: new FormData(form),
            headers: {
                'Accept': 'application/json',
            },
        })
        .then(response => {
            if (response.redirected) {
                window.location.href = response.url;
                return Promise.reject('redirect');
            }

            return response.json();
        })
        .then(data => {
            if (data.status !== 'success') {
                console.error('Ukládání se nepovedlo:', data.message);
                alert('Ukládání se nepovedlo. Prosím zkuste to znovu později.');
            }

            this.successMsgTarget.classList.remove('d-none');

            setTimeout(() => {
              this.successMsgTarget.classList.add('d-none');
            }, 3000);
        })
        .catch(error => {
            if (error === 'redirect') return;
            console.error('Error during autosave:', error);
            alert('Ukládání se nepovedlo. Prosím zkuste to znovu později.');
        });
    }
}
