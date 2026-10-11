# App shell — left navigation only, NO topbar

### App shell — left navigation only, NO topbar (2026-08-08)

`base.html.twig` renders the Hyper theme's `.leftside-menu` and nothing above
the content: the theme's `.navbar-custom` was **deleted**, and the two things it
carried moved into the left navigation.

- **Account menu** = `.leftbar-account`, a drop-UP pinned to the sidebar's
  bottom edge (avatar + display name + the same Můj účet / Propojené aplikace /
  Odhlásit se items). It lives OUTSIDE `#leftside-menu-container` so simplebar
  can never clip the menu, and it occupies the bottom padding the sidebar
  already reserved — `padding-bottom` is retargeted to
  `--wb-leftbar-account-height`, which keeps the container's `h-100` resolving
  to exactly the space above it (percentages resolve against the content box),
  so the scrolling menu and the block can never overlap. Icon-only sidebar
  states (`condensed`, `compact`, unhovered `sm-hover`) hide the name + caret
  and the 19px left padding centers the 32px avatar on the 70px rail.
- **Drawer toggle**: the sidebar still collapses below xl (fully off-canvas
  under 768px), so `.mobile-topbar` (`d-xl-none`) carries the theme's
  `.button-toggle-menu` — the theme binds the FIRST one it finds in the DOM, so
  keep exactly one. It takes the SIDEBAR's colours, because the repo ships one
  logo asset whose wordmark is white.
- Nothing else depended on the topbar's height: `.leftside-menu` is
  `position: fixed; top: 0`, `.content-page` never had a top padding, and the
  editor shell measures its own offset (`canvas_zoom_controller`) rather than
  subtracting a constant. The one hard-coded mirror was
  `.editor-left-panel-card`'s below-lg sticky `top`, now the mobile bar's 56px.
