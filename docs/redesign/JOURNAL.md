# Redesign journal

Running log of the Nocturne redesign: what was done, how, and why. Newest
entry at the bottom. Every agent working on the redesign appends an entry
when it finishes a unit of work (what changed, files, decisions + reasons,
what is NOT verified, follow-ups). Keep entries short and factual.

---

## 2026-11-02 — Phase 0: research & reference capture (orchestrator)

- Read the prototype through the user's Chrome: the artifact is a
  public Claude Design page whose content lives in a cross-origin frame; the
  built single-file HTML was fetched from the frame host
  (`…frame.claudeusercontent.com/_f/<version>/`) and split into
  `prototype/app.css`, `app.js`, `body.html` (+ the original file).
- Clicked through every screen and saved 39 screenshots to
  `reference/screenshots/` (dashboard, card menu, project overview + modules,
  manual logos + mockup pages, templates list + new-template dialog, editor +
  inspector + display menu, fill page + export history + share-to-social,
  users admin + profile + invite dialogs, statistics ×3 tabs, placeholders,
  light theme, profile drop-up with role preview, phone layouts).
- Observations: dark-first "Nocturne" palette (bg `#0B0A0D`, lavender accent
  `#C7B6F2`, rose/mint/peach secondaries), font Outfit, collapsible sidebar
  272/84 px, drawer under 860 px with a top bar (hamburger, logo, + FAB).
  Template editor collapses the sidebar to an icon rail. Prototype sections
  not built yet: Jídelníčky, Facebook a Instagram, Poptávky z webu,
  Propojené aplikace, AI (MCP server) → we design those ourselves in the same
  language.
- Branch `redesign/nocturne` created. Design-system spec and current-UI
  inventory delegated to two research agents (outputs: `design-system.md`,
  `inventory.md`).
- User direction: replicate the design as-is, responsive everywhere, note new
  backend features (`new-features.md`), cheap ones may be implemented, deliver
  end-to-end on the branch + merge request, keep this journal.
