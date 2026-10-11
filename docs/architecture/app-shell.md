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

### Project modules — nav shows only what the project uses (2026-10-11)

A project lists its switched-on modules in `project.modules` (JSONB list of
`ProjectModule` values: `manuals`, `templates`, `email_signatures`,
`weekly_menus`, kept in enum order). The left navigation and the project
dashboard render a module only when `project.hasModule(...)` — Twig reaches the
enum via `enum('WBoost\\Web\\Value\\ProjectModule')`. **Galerie and Fonty are
not modules** (other modules draw on them — always shown); **Kalendáře** isn't
built yet, so it is gone from the nav/dashboard and only linked from the
discover page (`calendars.html.twig` highlights the discover nav item).

- **New projects** start with `ProjectModule::defaults()` = Manuály + Šablony
  (also the column default). The 2026-10-11 migration backfilled existing
  projects: core two + E-maily / Jídelníčky only where content existed.
- **Discover / upsell**: `project_modules` (`/project/{id}/modules`, "Objevte
  další moduly") lists the switched-off modules; a nav item (`.side-nav-discover`)
  and a dashed dashboard tile (`.module-card--discover`) link to it while any
  module is off. Anyone with `project_edit` switches a module on there
  (`project_module_enable`, POST + CSRF) and lands on it.
- **Admin mail**: `EnableProjectModuleHandler` e-mails the
  `SIGNUP_NOTIFICATION_EMAILS` admins (bound as `$adminNotificationRecipients`)
  when a **non-admin** switches a module on; admins switching don't trigger it.
- **Switching off is admin-only** (`project_module_disable`), from the
  "Moduly projektu" card on `edit_project` (`#project-modules`, ROLE_ADMIN), which
  also shows the `project_module_change` history. Off = hidden, never deleted.
- **Guard** = `#[RequiresProjectModule]` on the module LANDING controllers only
  (Manuals, Templates, EmailSignatureTemplates, WeeklyMenus, Meals, Diets,
  DishTypes); `ProjectModuleGuardListener` (CONTROLLER_ARGUMENTS, after
  `#[IsGranted]`) redirects to the discover page with an info flash. Item pages,
  the render pipeline, the API and MCP are deliberately NOT gated — this is
  decluttering, not access control. A new module = enum case + nav/dashboard
  `if` + attribute on its landing controller + backfill decision.
