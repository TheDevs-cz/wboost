// Entrypoint for customer-facing pages that must NOT get the Nocturne skin
// (manual_preview.html.twig — PLAN decision 7): Bootstrap + feature rules +
// Stimulus only. The page links its own legacy look.
import 'bootstrap/dist/css/bootstrap.min.css';

import './styles/app.css';

import './bootstrap_global.js';

import './bootstrap.js'
