import { Controller } from '@hotwired/stimulus';
import grapesjs from 'grapesjs';
import 'grapesjs-preset-newsletter';

export default class extends Controller {
    connect() {
        this.editor = grapesjs.init({
            container: this.element,
            height: '600px',
            storageManager: false,
            plugins: ['gjs-preset-newsletter'],
            pluginsOpts: {
                'gjs-preset-newsletter': {
                    inlineCss: true,
                }
            },
        });

        // Custom placeholder block
        this.editor.BlockManager.add('placeholder', {
            label: 'Placeholder',
            category: 'Basic',
            attributes: { class: 'fa fa-tag' },
            content: {
                type: 'text',
                content: '{{placeholder}}',
                style: { padding: '5px', color: '#555', 'font-style': 'italic' },
                editable: true,
            }
        });

        // Background image table block
        this.editor.BlockManager.add('bg-image-table', {
            label: 'Table with BG Image',
            category: 'Layout',
            attributes: { class: 'fa fa-table' },
            content: `
            <table width="600" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td background="https://yourimageurl.com/image.png" width="600" height="200" valign="top" style="background-size:cover; background-repeat:no-repeat;">
                  Insert text here
                </td>
              </tr>
            </table>`
        });
    }

    exportTemplate() {
        const html = this.editor.getHtml();
        const css = this.editor.getCss();
        const completeHtml = `<style>${css}</style>${html}`;
        console.log(completeHtml);
    }

    disconnect() {
        if (this.editor) this.editor.destroy();
    }
}
