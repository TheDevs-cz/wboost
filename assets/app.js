// CSS order = <link> order: Bootstrap (mechanics + grid/utilities) → GrapesJS
// → the Nocturne layer (tokens, components, shell — restyles Bootstrap) →
// app.css (feature rules). Nocturne loads AFTER Bootstrap, so equal-specificity
// rules of ours win; there is no Hyper theme sheet any more.
import 'bootstrap/dist/css/bootstrap.min.css';

import 'grapesjs/dist/css/grapes.min.css'

import './styles/nocturne/tokens.css';
import './styles/nocturne/base.css';
import './styles/nocturne/icons.css';
import './styles/nocturne/buttons.css';
import './styles/nocturne/forms.css';
import './styles/nocturne/chips.css';
import './styles/nocturne/cards.css';
import './styles/nocturne/dialogs.css';
import './styles/nocturne/menus.css';
import './styles/nocturne/tables.css';
import './styles/nocturne/feedback.css';
import './styles/nocturne/page.css';
import './styles/nocturne/shell.css';
import './styles/nocturne/auth.css';
import './styles/nocturne/projects.css';
import './styles/nocturne/manual.css';
import './styles/nocturne/manual-mockups.css';
import './styles/nocturne/gallery.css';
import './styles/nocturne/fonts.css';
import './styles/nocturne/palette.css';
import './styles/nocturne/templates.css';
import './styles/nocturne/admin.css';
import './styles/nocturne/email.css';
import './styles/nocturne/weekly-menu.css';
// Kalendáře — one file per phase (docs/calendar/architecture.md §D.9).
import './styles/nocturne/calendar.css';
import './styles/nocturne/calendar-editor.css';
import './styles/nocturne/calendar-controls.css';
import './styles/nocturne/calendar-content.css';
import './styles/nocturne/calendar-tools.css';
import './styles/nocturne/calendar-preview.css';
import './styles/nocturne/calendar-dialogs.css';
// AI sken (docs/architecture/ai-scan.md § "UI").
import './styles/nocturne/ai-scan.css';

import './styles/app.css';
// Fill pages re-skin app.css's fill-overlay rules, so they load after it.
import './styles/nocturne/fill.css';
// Canvas editors re-skin app.css's editor chrome rules, so they load after it too.
import './styles/nocturne/editor.css';
// Hex colour picker popover (color_picker_controller) — editor + fill pages.
import './styles/nocturne/color-picker.css';

// window.bootstrap before Stimulus starts.
import './bootstrap_global.js';

import './bootstrap.js'
