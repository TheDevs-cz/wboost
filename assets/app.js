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
import './styles/nocturne/gallery.css';
import './styles/nocturne/fonts.css';
import './styles/nocturne/admin.css';

import './styles/app.css';

// window.bootstrap before Stimulus starts.
import './bootstrap_global.js';

import './bootstrap.js'
