---
name: verify
description: How to run and drive this app for runtime verification — launch, login, email capture, async queue.
---

# Verifying changes in the running app

## Launch / ports

The stack is already running via `docker compose up` in most sessions (`docker compose ps` to check). Host ports are remapped from the defaults documented in CLAUDE.md:

- Web app: check with `docker compose port web 8080` (commonly **:8081**, set via `WEB_PORT`)
- Mail capture: **Mailpit** (not MailCatcher) — web UI + API on host **:8026**, SMTP :1026. API: `GET http://localhost:8026/api/v1/messages?limit=5`, message body via `GET /api/v1/message/{ID}` (`HTML` key).
- Postgres: `docker compose exec -T postgres psql -U postgres -d wboost`

## Getting a browser login

Dev sessions expire often. Create a throwaway user and promote it so voters pass on existing data:

```bash
docker compose exec -T web bin/console app:user:register verify@test.cz SomePass123
docker compose exec -T postgres psql -U postgres -d wboost \
  -c "UPDATE \"user\" SET roles='[\"ROLE_ADMIN\"]' WHERE email='verify@test.cz';"
```

Log in at `/login`, delete the user row afterwards.

## Emails

`SendEmailMessage` is routed to the **async** transport and no consumer runs in dev — sent mail sits in the queue until you flush it:

```bash
docker compose exec -T web bin/console messenger:consume async --limit=10 --time-limit=15
```

Then check Mailpit (:8026).

## Gotchas

- After editing JS/CSS or adding asset files: `rm -rf var/cache/dev public/assets` in the container (stale digests / stale compiled assets silently serve old code).
- Local "headers already sent" on every page = corrupt `var/cache/dev/*Deprecations.log` — `rm -f` the file, do NOT cache:clear.
- Native `confirm()` dialogs (delete buttons) freeze browser automation — avoid clicking them.
