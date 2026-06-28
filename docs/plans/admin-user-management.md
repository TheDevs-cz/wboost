# Implementation Plan — Admin User Management, Invitations, Project-Sharing UI & Signup Requests

> **Audience:** the implementing Claude Code agent. Self-contained hand-off.
> **Repo:** `/Users/janmikes/www/brand-manuals` — Symfony 7/8, FrankenPHP, PostgreSQL 16, namespace `WBoost\Web`.
> **UI language:** Czech. **Quality gates:** `docker compose exec web composer phpstan` (level max) + `docker compose exec web vendor/bin/phpunit` must stay green.
> **Status:** approved in principle (storage = `ProjectShare` entity; sharing UI = project-centric; lifecycle = invite + edit + resend). This plan was hardened by an adversarial review and by inspecting the live production host.

---

## 0. Locked product decisions

1. **Admin-only** for v1: inviting users, managing project sharing, and the user/project overviews are all gated by `ROLE_ADMIN`. Architect the sharing write-side cleanly so it can later be opened to project owners.
2. **Invite flow:** admin invites by email; the invitee clicks an emailed link and sets their own password (which activates the account). Invite form captures **email + display name + role** (User / Designer / Admin) **+ pre-share specific projects** (multi-select, Read level).
3. **Admin user list:** all users with **owned-project count**, **shared-with-them count**, role, and status (Čeká na aktivaci / Aktivní). Plus **edit (name/role)** and **resend invitation**. **No deactivate/delete in v1** → status is a clean two-state derived from `confirmed`.
4. **Project sharing:** keep a single `Read` level for now (design the API to carry a level for future `Edit`). **Migrate the JSONB `Project.sharing` array to a dedicated `ProjectShare` relational entity** (see §4 + §9 for rationale + migration). Sharing is managed **project-centrically** via a per-project "Sdílení" manager (add/remove collaborators) embedded on the **main projects list**, which for admins shows **all** projects (owner shown; the admin's own vs. non-owned styled distinctly). Access to any project already works via the admin god-mode voter; this adds the listing + management UI.
5. **Signup requests** (`/registration`): replace the static info with an email-capture form that **persists a request** and **emails the admins** (`j.mikes@me.com`, `lukas@wantoo.cz`, configurable). Duplicate pending requests for the same email are blocked with a visible "this email already requested" message. Admin sees a requests list and converts a request into a prefilled invite.
6. **Async prod email** is required — and **already works in production** (see §8): a `messenger-consumer` worker consumes the `async` transport and `MAILER_DSN` is a real SMTP relay.

---

## 1. House style (verified — match exactly)

- **Commands** (`src/Message/<Domain>/`): `readonly final class` (`readonly` precedes `final`), constructor-promoted **public** props, no attributes/interfaces. Nullable = `null|string`, not `?string`.
- **Handlers** (`src/MessageHandler/<Domain>/`): `#[AsMessageHandler] readonly final class`, single `__invoke(<Command>): void`, `@throws` documented. **Create-handlers** persist via repo `add()/save()`; **edit-handlers** just mutate the managed entity (the `command_bus` `doctrine_transaction` middleware flushes — never call `flush()`).
- **Dispatch:** inject `MessageBusInterface $bus`; `$this->bus->dispatch(new Cmd(...))`. Bus is synchronous except `WBoost\Web\Events\*`, `SendEmailMessage`, `WarmupCache` (→ `async`).
- **Identity/time:** `WBoost\Web\Services\ProvideIdentity::next()` (UUID v7); `Psr\Clock\ClockInterface::now()`.
- **Read models:** `src/Query/Get<X>.php` `readonly final` services injected into controllers (NOT via bus), EM QueryBuilder / DBAL.
- **Controllers:** one invokable `final class XController extends AbstractController`, `#[Route(path, name)]` (snake_case name), `#[CurrentUser] User $user`, `#[IsGranted(...)]`, `addFlash()` + `redirectToRoute()` after writes.
  - **Route params:** there is **no value resolver for Ramsey `UuidInterface`** — NEVER type a route arg as `UuidInterface`. Use `#[MapEntity] User $user` (proven: `EditDishTypeController` uses `#[MapEntity(id: 'dishTypeId')]`) **or** accept `string $id` and call `Uuid::fromString($id)` (proven: `WeeklyMenuPlannerController`).
- **Forms:** `src/FormData/<X>FormData.php` (mutable `final class`, validation attributes, optional `::fromEntity()`), `src/FormType/<X>FormType.php` (`AbstractType<DTO>`, Czech labels, `data_class`). **Directory is `src/FormType/`** (CLAUDE.md's `Form/` is wrong).
- **Voters** (`src/Services/Security/`): `final class XVoter extends Voter`, inject `Security`, ADMIN god-mode short-circuit `if ($this->security->isGranted(User::ROLE_ADMIN)) return true;`. Autowired (no manual tags).
- **Roles:** `User::ROLE_DESIGNER`, `User::ROLE_ADMIN`; `ROLE_USER` is the bare string `getRoles()` always appends. Hierarchy `ADMIN→DESIGNER→USER`.
- **Templates:** extend `base.html.twig`; `{% set active_menu_item %}`; blocks `title`/`breadcrumb`/`content`. Flash via `_flashes.html.twig` (already in base). **Left nav is hard-coded** in `base.html.twig`'s `<ul class="side-nav">` — new items go there. List/table pattern: copy `templates/meals.html.twig` (card → `table-responsive` → `table table-hover table-striped`, `btn-group btn-group-sm`, `alert alert-info` empty state). **No pagination anywhere** — keep it that way for v1.
- **Delete/confirm:** `<twig:ConfirmModal id url confirmationText />` (GET route).
- **Live Components:** `#[AsLiveComponent('Name')] final class extends AbstractController` + `DefaultActionTrait`/`ComponentWithFormTrait`/`ComponentToolsTrait`; `#[LiveProp]` state; `#[LiveAction]` dispatches then `dispatchBrowserEvent('modal:close')`. **Auth via `#[PostMount]` + a `guard()` helper** (class-level `#[IsGranted]` can't resolve a LiveProp subject — proven by `ImageGallery`). `#[LiveArg]` names must be **lowercase**. Root template must merge `{{ attributes }}`.
- **Email:** inject `MailerInterface` + `Twig\Environment` (+ `UrlGeneratorInterface` for absolute links); render `templates/emails/*.html.twig` into `(new Email())->to(...)->subject(...)->html($html)`; `$mailer->send($email)`. **Never `->from()`** (global default `robot@wboost.cz` in `config/packages/mailer.php`). Canonical: `src/MessageHandler/WeeklyMenu/RequestWeeklyMenuApprovalHandler.php`.
- **PHPStan level max:** narrow `mixed`. DBAL `json_decode($row['roles'], true)` → `assert(is_array(...))` + cast to `list<string>`; `getOneOrNullResult()` → `assert($x instanceof User || $x === null)`.

---

## 2. Work-stream A — Foundation: complete & fix the password / set-password flow

The invite-acceptance page **is** the set-password page, and the existing reset flow is broken (forgotten-password shows "we emailed you" but `RequestPasswordResetHandler` ends `// TODO: send email`; `ResetPasswordHandler` is an empty stub; `ResetPasswordController` is a 404 stub). Completing it is both the foundation and a real bug fix.

**A.1 — Token validity + single-use** (`src/Repository/PasswordResetTokenRepository.php`)
- Add `getValid(string $tokenId, DateTimeImmutable $now): PasswordResetToken` — find by id; throw `InvalidPasswordResetToken` if missing, `usedAt !== null`, or `validUntil < now`.

**A.2 — `User` entity additions** (`src/Entity/User.php`)
- `public function confirm(): void { $this->confirmed = true; }`
- `public function changeRoles(array $roles): void { $this->roles = array_values(array_unique($roles)); }` (`@param list<string> $roles`).

**A.3 — Send the reset email** (fill the TODO in `RequestPasswordResetHandler`)
- Inject `MailerInterface` + `UrlGeneratorInterface` + `Twig\Environment`. After saving the token: render `templates/emails/password_reset.html.twig`, link to `set_password` with `token = $token->id->toString()` (`UrlGeneratorInterface::ABSOLUTE_URL`), subject "Obnovení hesla", send to `$user->email`. Keep validity +8h.

**A.4 — Implement set-password consumption** (`ResetPasswordHandler` for `ResetPassword(string $token, string $newPlainTextPassword)`)
- `$token = $repo->getValid($message->token, $clock->now());` → hash via `UserPasswordHasherInterface` → `$user->changePassword($hash);` → `$user->confirm();` (activates invitees, no-op otherwise) → `$token->use($clock->now());`
- **Auto-login** the (possibly not-yet-authenticated) user via `Symfony\Bundle\SecurityBundle\Security::login($user, 'main')` (modern API; avoids manual token construction). This is sound because `ResetPassword` runs **synchronously** in the request and `/set-password/*` is under the stateful `main` firewall (the firewall persists the token on `kernel.response`). **Cover with a functional test** (the new-user auto-login path is not exercised anywhere today).

**A.5 — Set-password page** (replace the `ResetPasswordController` stub)
- `src/Controller/Authentication/SetPasswordController.php`: `#[Route('/set-password/{token}', name: 'set_password')]`.
  - GET: `getValid(...)`; on `InvalidPasswordResetToken` render a friendly "odkaz vypršel / neplatný" page linking to `forgotten_password`. Otherwise render the form.
  - **Branch the page copy on `$token->user->password === ''`** (never-set ⇒ invitation/activation copy "Vítejte ve WBoost — nastavte si heslo"; already-set ⇒ reset copy "Zvolte si nové heslo"). This keeps the welcome copy correct even if an invitee recovers via the forgotten-password email.
  - POST: dispatch `ResetPassword($token, $data->password)`; catch `HandlerFailedException`→`InvalidPasswordResetToken` (danger flash); success flash + `redirectToRoute('homepage')` (now logged-in).
- `src/FormData/SetPasswordFormData.php`: `#[NotBlank] #[Length(min: 6)] public string $password = '';`
- `src/FormType/SetPasswordFormType.php`: `RepeatedType` of `PasswordType` (Czech labels "Heslo" / "Heslo znovu", Czech `invalid_message`), `data_class`.
- `templates/set_password.html.twig`: auth-card layout (copy `forgotten_password.html.twig`), conditional heading/intro.
- **Delete** `src/Controller/Authentication/ResetPasswordController.php` and the dead `reset_password` route.

**A.6 — Security regex** (`config/packages/security.php`)
- In the public `access_control` entry, replace the `reset-password` token with `set-password/.*` (keep the `/.*` — the route now has a `{token}` segment). Keep `login|registration|forgotten-password`.

**A.7 — Email templates** (`templates/emails/`)
- `password_reset.html.twig`, `invitation.html.twig`, `access_request.html.twig`. Optionally a shared `_layout.html.twig` (logo + button). Model on `weekly_menu_approval_request.html.twig`.

---

## 3. Work-stream B — Login gating: `UserChecker`

- `src/Services/Security/UserChecker.php implements UserCheckerInterface` — `checkPreAuth`: if `$user instanceof User && !$user->confirmed` throw `CustomUserMessageAccountStatusException('Váš účet ještě nebyl aktivován. Zkontrolujte e-mail s pozvánkou.')`.
- Wire into the `main` firewall in `config/packages/security.php`: `'user_checker' => UserChecker::class` (valid because `config/services.php` autoloads `WBoost\Web\Services\**` → the FQCN is a registered service id). Existing users are `confirmed = true` → unaffected.

---

## 4. Work-stream C — Sharing storage migration to `ProjectShare` (decided)

> **Why an entity, not JSONB:** this feature introduces user-lifecycle management (invite → future delete) and an admin "sharing settings" view. In JSONB, a *shared* user exists only as a bare `userId` string inside other projects' arrays — **no FK, no `ON DELETE CASCADE`** — so deleting a user would leave dangling references across projects with no DB-level way to find or scrub them. A `ProjectShare` row with a `user_id` FK makes that correct by construction, adds per-share metadata (`sharedAt`/`sharedBy`) for the admin view, and turns the admin counts into plain indexed joins. **The migration is bounded:** keeping `Project::getUserSharingLevel(User): ?SharingLevel` with the same signature (backed by a `OneToMany` collection) means **all 14 sharing voters and `projects.html.twig` are untouched**; the only N+1 risk is killed with one fetch-join.

**C.1 — Entity** `src/Entity/ProjectShare.php`
```php
#[Entity]
#[Table(name: 'project_share')]
#[UniqueConstraint(columns: ['project_id', 'user_id'])]
class ProjectShare {
  public function __construct(
    #[Id] #[Column(type: UuidType::NAME, unique: true)] public UuidInterface $id,
    #[ManyToOne(inversedBy: 'shares')] #[JoinColumn(nullable: false, onDelete: 'CASCADE')] readonly public Project $project,
    #[ManyToOne] #[JoinColumn(nullable: false, onDelete: 'CASCADE')] readonly public User $user,
    #[Column(type: 'string', enumType: SharingLevel::class)] public SharingLevel $level,
    #[Column(type: Types::DATETIME_IMMUTABLE)] readonly public \DateTimeImmutable $sharedAt,
    #[ManyToOne] #[JoinColumn(nullable: true, onDelete: 'SET NULL')] readonly public null|User $sharedBy = null,
  ) {}
  public function changeLevel(SharingLevel $level): void { $this->level = $level; }
}
```
(`enumType` form mirrors `WeeklyMenu`'s `#[Column(type: 'string', enumType: WeeklyMenuApprovalStatus::class)]`.)

**C.2 — `Project` entity** (`src/Entity/Project.php`) — preserve the read API
```php
#[OneToMany(targetEntity: ProjectShare::class, mappedBy: 'project', cascade: ['persist','remove'], orphanRemoval: true)]
private Collection $shares;            // init `new ArrayCollection()` in constructor

public function getUserSharingLevel(User $user): null|SharingLevel {   // SAME signature → voters untouched
  foreach ($this->shares as $s) if ($s->user->id->equals($user->id)) return $s->level;
  return null;
}
public function share(User $user, SharingLevel $level, \DateTimeImmutable $now, ?User $by = null): void {
  if ($user->id->equals($this->owner->id)) return;                     // never share with owner
  foreach ($this->shares as $s) if ($s->user->id->equals($user->id)) { $s->changeLevel($level); return; }
  $this->shares->add(new ProjectShare(ProvideIdentity::next(), $this, $user, $level, $now, $by));
}
public function unshare(User $user): void {
  foreach ($this->shares as $s) if ($s->user->id->equals($user->id)) { $this->shares->removeElement($s); return; }
}
```
> Note: mutators now take a **managed `User`** (a relation needs the entity), not a `UuidInterface` — the handlers already load/verify the user. `ProvideIdentity::next()` is static.

**C.3 — `GetProjects`** (`src/Query/GetProjects.php`)
- `sharedWithUser(UuidInterface $userId)`: replace the raw `jsonb_array_elements` SQL with `SELECT project_id FROM project_share WHERE user_id = :userId` (indexed).
- `allForUser(UuidInterface $userId)`: keep the two-step shape but **fetch-join shares** to kill the per-card `is_granted` N+1:
  `->select('p','sh')->leftJoin('p.shares','sh')->where('p.owner = :uid')->orWhere('p.id IN (:shared)')`.

**C.4 — Retire the JSONB value-object (staged)** — keep `src/Value/ProjectSharing.php` + `src/Doctrine/ProjectSharingDoctrineType.php` + the `Project::$sharing` column mapping **for one release** (until the prod backfill is verified), then remove them in the column-drop follow-up (see §9.B).

---

## 5. Work-stream D — Sharing write-side + all-projects-for-admin list + inline sharing manager

**D.1 — Messages + handlers** (`src/Message/Project`, `src/MessageHandler/Project`)
- `ShareProject(string $projectId, string $userId, string $level, null|string $sharedById)` + handler: `Uuid::fromString(...)` for each id; `projectRepository->get(uuid)` (note: `ProjectRepository::get(UuidInterface)`), `userRepository->getById(uuid)`, resolve `sharedBy` if present; `$project->share($user, SharingLevel::from($level), $clock->now(), $sharedBy)`. Default level `Read`.
- `UnshareProject(string $projectId, string $userId)` + handler.

**D.2 — Read models / repo**
- `src/Query/GetProjects.php`: add `all(): list<Project>` returning **every** project — `->select('p','sh','o')->leftJoin('p.shares','sh')->leftJoin('p.owner','o')` ordered by `p.createdAt DESC`. The `sh` fetch-join kills the per-card `is_granted` N+1; the `o` join pre-loads the owner for the label. (Also apply the same `leftJoin('p.shares','sh')` fetch-join to the existing `allForUser` for non-admins.)
- `src/Repository/UserRepository.php`: add `findAll(): list<User>` (candidate users for the share picker), `getById(UuidInterface $id): User` (distinct from existing `get(string $email)`), `findByEmailOrNull(string $email): null|User` (dedup; `getOneOrNullResult`).

**D.3 — The projects list shows ALL projects for admins (owner shown, non-owned styled differently)**
The brief: an admin must see **every** project on the normal projects list, with the owner labelled and non-owned projects visually distinct. Access is already correct (admin god-mode in the voters) — this is a listing + presentation change.
- `src/Controller/Project/ProjectsController.php`: if `$this->isGranted(User::ROLE_ADMIN)` → `getProjects->all()`, else `getProjects->allForUser($user->id)` (unchanged for non-admins). The existing "single project → `project_dashboard`" redirect for non-designers is unaffected (admins are designers via hierarchy).
- `templates/projects.html.twig`: on every card render the **owner** (`project.owner.getDisplayName()`); branch styling on `project.owner.id.equals(app.user.id)` — e.g. the admin's **own** projects keep the default look, **non-owned** get a muted/secondary treatment (border/badge "vlastník: {jméno}"). Presentation only.

**D.4 — Inline project-centric sharing manager (admin-only)**
- For admins, render a per-card **"Sdílení"** trigger on `projects.html.twig`, wrapped in `{% if is_granted('ROLE_ADMIN') %}`, mounting `<twig:ManageProjectSharing :project="project" />`.
- Live Component `src/Twig/Components/ManageProjectSharingComponent.php` (`#[AsLiveComponent('ManageProjectSharing')]`, model on `LogoColorsMappingComponent`):
  - `#[LiveProp] Project $project;` `#[PostMount]` + `guard()` enforce `ROLE_ADMIN`.
  - Renders current collaborators (`project.shares` → `user.getDisplayName()` + level + remove) and an "add collaborator" picker (`UserRepository::findAll()` minus owner & already-shared).
  - `#[LiveAction] share(#[LiveArg('userid')] string $userId)` → dispatch `ShareProject($project->id->toString(), $userId, SharingLevel::Read->value, <current admin id>)`; `#[LiveAction] unshare(#[LiveArg('userid')] string $userId)` → `UnshareProject`. Re-render in place.
  - `templates/components/ManageProjectSharing.html.twig` (merge `{{ attributes }}`; empty-states when no candidate users / no collaborators).

> Consolidation note: there is **no separate `/admin/projects` page** — the main `/projects` list, enhanced for admins, is the home for both browsing-all and sharing management.

---

## 6. Work-stream E — Admin users: list, invite, edit, resend

All controllers `#[IsGranted(User::ROLE_ADMIN)]`, prefix `/admin`, route-name prefix `admin_`. **Status is two-state** (no deactivate): `confirmed=false` ⇒ *Čeká na aktivaci* (pending invite, password is `''`), `confirmed=true` ⇒ *Aktivní*.

**E.1 — Read model** `src/Query/GetUsersOverview.php` (DBAL)
```sql
SELECT u.id, u.email, u.name, u.roles, u.confirmed, u.registered_at,
  (SELECT COUNT(*) FROM project p WHERE p.owner_id = u.id) AS owned_count,
  (SELECT COUNT(*) FROM project_share ps WHERE ps.user_id = u.id) AS shared_count
FROM "user" u ORDER BY u.registered_at DESC
```
Map to a `UserOverviewRow` DTO (decode `roles` JSON → `list<string>` with `assert(is_array)`; cast `confirmed` to bool).

**E.2 — Messages + handlers** (`src/Message/User`, `src/MessageHandler/User`)
- `InviteUser(string $email, null|string $name, list<string> $roles, list<string> $projectIds, string $invitedById)` + `InviteUserHandler`:
  - Dedup via `findByEmailOrNull`: **confirmed** user ⇒ throw `UserAlreadyRegistered` (exception exists, currently unused). **Unconfirmed** user (pending) ⇒ **re-invite** (reuse the user, refresh name/roles/pre-shares, re-mint token, resend) — do not error.
  - Else `new User(ProvideIdentity::next(), $email, $clock->now(), confirmed: false)`; `changeRoles($roles)`; `editProfile($name)`; **leave password `''`** (can't authenticate; UserChecker also blocks). `userRepository->save($user)`.
  - Pre-share: load `userRepository->getById($invitedById)` as `$by`; for each `projectId`: `projectRepository->get(Uuid::fromString($projectId))` then `$project->share($user, SharingLevel::Read, $clock->now(), $by)` (**direct entity mutator**, not a dispatched command).
  - Mint `PasswordResetToken` with **+7 days** validity; `save`.
  - Send `templates/emails/invitation.html.twig` (link to `set_password`).
  - If a **pending** `RegistrationRequest` exists for the email → `markInvited()` (see §7).
  - **No auto-login** (admin is the actor).
- `EditUser(string $userId, null|string $name, list<string> $roles)` + handler (`getById`, `editProfile`, `changeRoles`).
- `ResendInvitation(string $userId)` + handler (only for `confirmed=false`: re-mint token + resend invitation email).

Role choice → roles mapping (in the controller/form): `'user' ⇒ []`, `User::ROLE_DESIGNER ⇒ [ROLE_DESIGNER]`, `User::ROLE_ADMIN ⇒ [ROLE_ADMIN]`.

**E.3 — Forms**
- `InviteUserFormData`: `#[NotBlank][Email] public string $email = '';` `public null|string $name = null;` `public string $role = User::ROLE_DESIGNER;` `public array $projectIds = [];`
- `InviteUserFormType`: `EmailType`, `TextType`(name, `required: false`), `ChoiceType`(role: Uživatel/Designer/Administrátor), multi-select projects (`ChoiceType` multiple; choices = all projects labeled `"{name} — {owner}"`; empty-state friendly when none).
- `EditUserFormData` (+ `::fromUser`) + `EditUserFormType` (name + role).

**E.4 — Controllers**
- `AdminUsersController` `/admin/users` name `admin_users` → table from `GetUsersOverview`.
- `InviteUserController` `/admin/users/invite` name `admin_invite_user` (GET/POST). Supports `?email=` prefill (from a registration request). Pass `$user->id->toString()` as `invitedById`. Unwrap `UserAlreadyRegistered` → danger flash.
- `EditUserController` `/admin/users/{id}/edit` name `admin_edit_user` — **use `#[MapEntity] User $user`**.
- `ResendInvitationController` `/admin/users/{id}/resend-invitation` name `admin_resend_invitation` (GET; flash). `#[MapEntity] User $user`.

**E.5 — Templates**
- `templates/admin/users.html.twig` — table: e-mail, jméno, role (badges), stav (Čeká na aktivaci / Aktivní), vlastní projekty (#), sdílené (#), registrován, akce (Upravit; Znovu poslat pozvánku *if pending*). Top-right "Pozvat uživatele".
- `templates/admin/invite_user.html.twig`, `templates/admin/edit_user.html.twig`.
- **Left nav** (`base.html.twig`): new `{% if is_granted('ROLE_ADMIN') %}` "Administrace" group → **Uživatelé** (`admin_users`), **Žádosti o registraci** (`admin_registration_requests`, optional pending-count badge). *(No admin "Projekty" item — the existing "Projekty" nav now shows all projects to admins, per §5/D.3.)*

---

## 7. Work-stream F — Public signup-request flow + admin handling

**F.1 — Entity + enum + repo + migration**
- `src/Value/RegistrationRequestStatus.php` enum: `Pending='pending'`, `Invited='invited'`, `Dismissed='dismissed'`.
- `src/Entity/RegistrationRequest.php`: `UuidInterface $id`, `string $email`, `DateTimeImmutable $createdAt`, `#[Column(type:'string', enumType: RegistrationRequestStatus::class)] $status`, methods `markInvited()/markDismissed()`. Email **not** DB-unique (re-requests allowed after dismissal; **dedup is on `Pending` only**).
- `src/Repository/RegistrationRequestRepository.php`: `save`, `getById`, `findPendingByEmail(string): ?RegistrationRequest`, `allPendingFirst(): list<RegistrationRequest>`.
- Doctrine migration creating `registration_request` (auto-runs on deploy — see §9).

**F.2 — Messages + handlers**
- `RequestAccess(string $email)` + `RequestAccessHandler`:
  - If a **confirmed** `User` with that email exists → throw `EmailAlreadyRegistered` (new exception).
  - Else if a **pending** `RegistrationRequest` exists → throw `AccessAlreadyRequested` (new exception).
  - Else persist `RegistrationRequest(Pending)`; send `templates/emails/access_request.html.twig` to the **admin recipients** (config, §F.4). **Make the email informational** (requester email + "přihlaste se do administrace a uživatele pozvěte") — do **not** put an `/admin/*` deep link as the primary CTA, because a non-admin recipient (`lukas@…`) would hit the login wall.
- `DismissRegistrationRequest(string $id)` + handler (`markDismissed`).

**F.3 — Controllers + template**
- Extend `RegistrationController` (`/registration`): build `RequestAccessFormData` (`#[NotBlank][Email] email`) + `RequestAccessFormType` (`EmailType`); on submit dispatch `RequestAccess`; unwrap `HandlerFailedException`:
  - `AccessAlreadyRequested` → **info** flash "Tento e-mail už o registraci požádal. Brzy se vám ozveme." (the user explicitly wants this signal).
  - `EmailAlreadyRegistered` → treat as **neutral success** ("Děkujeme, brzy se vám ozveme.") to avoid a *new* account-enumeration leak. *(The pre-existing forgotten-password "není zaregistrován" leak is out of scope.)*
  - success → **success** flash "Děkujeme, brzy se vám ozveme." Keep the logged-in→homepage guard.
- `templates/registration.html.twig`: replace the static paragraph with `form_start/form_row/form_end` (copy `forgotten_password.html.twig`); keep auth-card chrome + back-to-login link.
- `AdminRegistrationRequestsController` `/admin/registration-requests` name `admin_registration_requests` → table (e-mail, požádáno, stav, akce: **Pozvat** → `admin_invite_user?email=…` *[one-click to a prefilled invite form; admin still picks role/projects + submits]*, **Zamítnout** → dismiss via ConfirmModal).
- `DismissRegistrationRequestController` GET action (`#[MapEntity]` or `string $id`).

**F.4 — Config: admin notification recipients**
- Add env `SIGNUP_NOTIFICATION_EMAILS` (default `j.mikes@me.com,lukas@wantoo.cz`) to `.env`; bind a parameter `app.signup_notification_recipients` (comma-split to `list<string>`) injected into `RequestAccessHandler`. *(On prod, set this env in the host `docker-compose.yml` web service if you want to override the default.)*

---

## 8. Work-stream G — Async email: test env + prod verification

**Production already delivers async email** (verified on the host): a dedicated `messenger-consumer` service runs `bin/console messenger:consume async -vv --time-limit 3600 --memory-limit 256M`; `deploy.sh` `--force-recreate`s it each deploy (so it reloads new code — no `stop-workers` needed); `MAILER_DSN` is a real Seznam SMTP relay; the `async` transport is `doctrine://default` and the `messenger_messages` table exists via migration. **So sending via `MailerInterface` is sufficient — no infra change required for prod delivery.**

In-repo work (required for CI + dev correctness):
- `config/packages/test/messenger.php` routing `SendEmailMessage` → `sync` **and** add `MAILER_DSN=null://null` to `.env.test`. **Both are required:** without the sync override `assertEmailCount` sees 0 (async-queued mail isn't counted); without the null DSN the sync send hits the dev Mailpit DSN and **fails in CI** (no SMTP server in the test workflow). `NullTransport` still fires the `MessageEvent` the assertions read.
- Dev already routes `SendEmailMessage` → sync (Mailpit). Verify invite/reset/request emails in the dev mail-catcher.
- Do **NOT** introduce a `StreamedResponse`/early flush in the new controllers — prod runs `FRANKENPHP_WORKER=1` (resident PHP), where flushing corrupts the next request (see the `TemplateVariantImageRenderer` note). The email path is async and unaffected.
- *(Optional, out of scope: the `messenger-consumer` reports `unhealthy` because it inherits the web image's HTTP healthcheck but serves no HTTP — cosmetic; it consumes fine.)*

---

## 9. Production migration runbook (backup-gated)

> **TL;DR — the data migration is automatic.** The `project_share` backfill is written
> *inside* the Doctrine migration's `up()`, and migrations **auto-run on every deploy**
> (`.docker/on-startup.sh` → `doctrine:migrations:migrate`). So Release 1 (create table +
> backfill) needs **no manual prod SQL** — it just happens when the image deploys, and it
> is additive (keeps the `sharing` column → no data loss). The **only** step that needs a
> human is the *separate, later* migration that **drops** `project.sharing`: before you
> deploy that release, take a `pg_dump` backup and re-verify the backfill. **This
> implementation pass builds Release 1 ONLY and must not create/commit the drop migration.**

Host (for the eventual Release-2 backup + verification only): `ssh root@spare.srv.thedevs.cz`, `cd /deployment/wboost` (postgres service `postgres`, db `wboost`). All commands below use `docker compose exec` so **no secrets appear in the command line**.

**Release 1 — additive (safe, auto-runs on deploy):** the migration that **creates `project_share`** (+ FKs `CASCADE`/`CASCADE`/`SET NULL`, `UNIQUE(project_id,user_id)`, index on `user_id`) and **backfills from the JSONB**, plus the `registration_request` table. The backfill **must guard against already-dangling userIds** or the FK insert aborts the whole migration:
```sql
INSERT INTO project_share (id, project_id, user_id, level, shared_at, shared_by_id)
SELECT gen_random_uuid(), p.id, (e->>'userId')::uuid, e->>'level', now(), NULL
FROM project p, jsonb_array_elements(p.sharing) AS e
WHERE (e->>'userId')::uuid IN (SELECT id FROM "user");
```
This migration **keeps the `project.sharing` column** and leaves `ProjectSharingDoctrineType` + the `Project::$sharing` mapping in place. *(Raw-SQL migration, house style per `migrations/Version20260610150000.php`; `gen_random_uuid()` is v4 — acceptable for backfilled rows, or loop `Uuid::uuid7()` in a PHP migration if strict v7 is wanted.)*

**Verify Release 1 on prod** (host):
```bash
docker compose exec -T postgres psql -U wboost -d wboost -c \
 "SELECT (SELECT count(*) FROM project_share) AS shares,
         (SELECT count(*) FROM project p, jsonb_array_elements(p.sharing) e
           WHERE (e->>'userId')::uuid IN (SELECT id FROM \"user\")) AS jsonb_valid;"
```
The two counts must match. Spot-check a few projects' collaborators in the app.

**Release 2 — destructive (MANUAL + BACKUP, per your instruction):** a **separate, later** migration that runs `ALTER TABLE project DROP COLUMN sharing` and (in code) removes `ProjectSharingDoctrineType` registration + the `Project::$sharing` mapping + `src/Value/ProjectSharing.php`. **Before it runs in prod:**
1. **Back up** (keep a copy off-host):
   ```bash
   docker compose exec -T postgres pg_dump -U wboost -d wboost \
     > /deployment/wboost/backup_wboost_$(date +%Y%m%d_%H%M%S).sql
   # (or at minimum dump the sharing data so it is never lost:)
   docker compose exec -T postgres psql -U wboost -d wboost -Atc \
     "COPY (SELECT id, sharing FROM project WHERE sharing <> '[]') TO STDOUT" \
     > /deployment/wboost/project_sharing_backup_$(date +%Y%m%d_%H%M%S).tsv
   ```
2. Re-run the Release-1 verification query (counts still match).
3. Only then apply Release 2 — either let the deploy auto-run it **after** the backup, or run it manually:
   `docker compose exec web bin/console doctrine:migrations:migrate --no-interaction`.
4. Provide a `down()` on Release 2 that re-creates the column and re-serializes JSONB from `project_share`, as a paper trail.

> **Sequencing rule:** never ship the Release-2 (drop) migration in the same image as Release 1. Deploy Release 1, verify in prod, take the backup, *then* deploy/run Release 2.

---

## 10. Testing & acceptance

**Everything built here must be covered by tests, following the existing strategy** (CLAUDE.md): PHPUnit with DB transactions via `DAMA\DoctrineTestBundle`, HTTP **controller tests** for endpoints, the separate test DB, and fixtures in `tests/DataFixtures/` (extend `TestDataFixture` with an admin user, a plain user, owned + shared projects, and a pending registration request as needed). New `ProjectShare`/`RegistrationRequest` entities and the `null://null` mailer must be reflected in the test setup.

**Tests** (`docker compose exec web vendor/bin/phpunit`):
- Handlers: `InviteUserHandler` (creates unconfirmed + roles + token + pre-share; re-invite reuses pending user; rejects confirmed dup); `EditUser`; `ResendInvitation`; `RequestAccessHandler` (new persists + emails; pending dup throws; existing user throws); `ResetPasswordHandler` (sets password, flips `confirmed`, single-use token, rejects expired/used, auto-login); `RequestPasswordResetHandler` (sends email); `ShareProject`/`UnshareProject` (collaborator added/removed; `unique(project,user)` respected; sharing-with-owner is a no-op); `DismissRegistrationRequest`.
- Read models: `GetUsersOverview` (owned/shared counts correct against fixtures); `GetProjects::all()` returns every project, `allForUser` still scoped.
- Controllers: every `/admin/*` returns **403 for non-admin** and **200 for admin**; `/projects` as **admin lists ALL projects (incl. non-owned, owner label rendered)** while a non-admin sees only owned+shared; invite happy path; `/registration` new + duplicate-pending + already-registered (and the neutral-success copy for already-registered); set-password via valid/invalid/expired token; `UserChecker` blocks `confirmed=false` login and allows after activation; `ManageProjectSharing` LiveAction share/unshare round-trip (or cover via the handler tests + a smoke controller test).
- Email assertions rely on the §8 test config (sync + `null://null`) using Symfony's mailer assertions.
- Rebuild the test DB after the new migrations; run `composer phpstan` (level max).

**Acceptance criteria** (map to the 5 brief items):
1. Admin invites `x@y.cz` (role Designer, pre-share Project A) → invitee receives an email → set-password link → lands logged-in → sees Project A (Read).
2. `/admin/users` lists all users with owned/shared counts + status; pending users show "Znovu poslat pozvánku".
3. Admin opens `/projects` → sees **all** projects (own in default style, non-owned visually distinct with the owner shown) → a project's "Sdílení" → adds/removes a collaborator → reflected on that user's project list.
4. `/registration` email submit → both admin recipients receive a request mail → `/admin/registration-requests` shows it → "Pozvat" prefills the invite form. Re-submitting the same email shows "už o registraci požádal".
5. On prod, an invite/request email is **actually delivered** (worker drains `messenger_messages`).

---

## 11. Build sequence (dependency-correct)

1. **Foundation** (A + B): token validity, `User::confirm/changeRoles`, reset email, `ResetPasswordHandler` (+ `Security::login`), `SetPasswordController` + form + template, security regex, `UserChecker`, §8 test config. → forgotten-password works end-to-end in dev.
2. **Sharing model** (C + D.1): `ProjectShare` entity, `Project` relation + `share/unshare` + preserved `getUserSharingLevel`, `GetProjects` updates, `ShareProject`/`UnshareProject`, **Release-1 migration** (create + backfill). *(Hard prerequisite of the invite slice — pre-share calls `Project::share`.)*
3. **Admin users + invite** (E): `GetUsersOverview`, `UserRepository` additions, `InviteUser`/`EditUser`/`ResendInvitation`, forms, controllers, templates, nav.
4. **All-projects-for-admin list + inline sharing manager** (D.2–D.4): `GetProjects::all()`, `ProjectsController`/`projects.html.twig` (admin sees all + owner label + non-owned styling), `ManageProjectSharingComponent` + template.
5. **Signup requests** (F): entity/enum/repo/migration, `RequestAccess`/`Dismiss`, public form, admin list + convert + dismiss, recipients config, admin email.
6. **Tests + PHPStan + dev manual verification.**
7. **Prod (automatic):** on deploy, the Release-1 migration auto-creates `project_share` + backfills (no manual SQL). Verify the counts (§9). **Release 2 (drop `sharing`) is a separate, later, backup-gated task — NOT part of this implementation pass.**

---

## 12. New-file inventory

- **Entities:** `ProjectShare`, `RegistrationRequest`. **Values:** `RegistrationRequestStatus`.
- **Messages:** `User/{InviteUser,EditUser,ResendInvitation,RequestAccess,DismissRegistrationRequest}`, `Project/{ShareProject,UnshareProject}`. **Exceptions:** `EmailAlreadyRegistered`, `AccessAlreadyRequested` (reuse existing `UserAlreadyRegistered`, `InvalidPasswordResetToken`).
- **Handlers:** for each message above + filled `ResetPasswordHandler` + patched `RequestPasswordResetHandler`.
- **Controllers:** `Authentication/SetPasswordController`; `Admin/{AdminUsersController,InviteUserController,EditUserController,ResendInvitationController,AdminRegistrationRequestsController,DismissRegistrationRequestController}`; edit `Project/ProjectsController` (admin sees all); extend `RegistrationController`; delete `ResetPasswordController`.
- **Forms:** `SetPasswordForm*`, `InviteUserForm*`, `EditUserForm*`, `RequestAccessForm*`.
- **Queries:** `GetUsersOverview`; edits to `GetProjects` (`all()` + `allForUser` shares fetch-join).
- **Repos:** extend `UserRepository` (`findAll`, `getById`, `findByEmailOrNull`), `PasswordResetTokenRepository` (`getValid`); new `RegistrationRequestRepository`.
- **Security:** `UserChecker` (+ firewall wiring + regex).
- **Components:** `ManageProjectSharingComponent` (+ template).
- **Templates:** `set_password`, `admin/{users,invite_user,edit_user,registration_requests}`, `components/ManageProjectSharing`, `emails/{password_reset,invitation,access_request}`; edit `projects.html.twig` (owner label + non-owned styling + admin "Sdílení" trigger), `registration.html.twig`, `base.html.twig` (nav).
- **Config/migrations:** `security.php` (regex + user_checker), `.env` (+ `SIGNUP_NOTIFICATION_EMAILS`) + parameter, `.env.test` (`MAILER_DSN=null://null`), `config/packages/test/messenger.php`, **Release-1 migration** (project_share + backfill + registration_request), **Release-2 migration** (drop project.sharing — ship later).

## 13. Out of scope (v1) / future

- Owner-managed sharing (architecture supports it: reuse `ShareProject`/`UnshareProject` + `getUserSharingLevel`, relax the `ROLE_ADMIN` guard).
- `Edit` sharing level (add a `SharingLevel` case; branch the voters' currently-`return false` EDIT path).
- User deactivate/reactivate and hard delete (the `ProjectShare` `CASCADE` now makes delete safe; deactivate needs `checkPostAuth`/session revocation).
- Pagination/search on the admin tables; a per-user "which projects can this user access" view.
