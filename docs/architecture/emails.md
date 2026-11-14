# Transactional e-mails

Every e-mail the app sends (password reset, invitation, project shared, AI scan,
weekly-menu approval, the admin notices) is a Twig template under
`templates/emails/` that **extends `emails/_layout.html.twig`** and builds its
body from the macros in **`emails/_ui.html.twig`**. Redesigned 2026-11-14
(before/after: https://claude.ai/artifact/4Pr8jz86ZzgKHZhJpTRcSK).

The one exception is `email_signature_demo.html.twig`: it must look like an
ordinary message so the signature shows as it will under a real mail. Keep it
plain.

## Origins

- Skeleton: Cerberus "fluid" (Ted Goas, MIT, github.com/emailmonday/Cerberus):
  600px max-width `<div>` + Outlook ghost table, client resets, preheader with
  the spacing hack. **Changed on purpose:** Cerberus's
  `table { margin: 0 auto !important }` is gone — it overrode every inline
  table margin (centred the button, collapsed the spacing).
- Content pattern: Postmark transactional templates (ActiveCampaign, MIT):
  greeting → one action → link validity → "didn't ask for this?" → fallback
  link → sign-off "tým WBoost". Admin notices: eyebrow "Pro administrátory",
  no greeting/sign-off.

## Rules

- **Inline styles carry the design.** Gmail (non-Google accounts) and classic
  Outlook drop much of `<style>`. The `<style>` block only adds the phone media
  query and dark mode. `ui.a('p')` prints `class="e-p" style="…"`: the class is
  the hook the `<style>` block overrides with `!important`.
- **Colours = Nocturne tokens** (`assets/styles/nocturne/tokens.css`): light
  values inline, dark values in `@media (prefers-color-scheme: dark)` (Apple
  Mail, iOS, Outlook for Mac) and `[data-ogsc]` (Outlook.com). Gmail inverts on
  its own — that is why the logo is the lavender app tile (dark W, legible on
  any background) + the name as live text, never a transparent wordmark PNG.
- **Button** = Postmark's border-based bulletproof button (the padding is a
  border in the button colour), so classic Outlook — which ignores padding on
  `<a>` — still draws a full-size, fully clickable button. Full width on phones.
- **Assets** live in `public/email/` (NOT AssetMapper — URLs must stay stable
  for mails already sitting in inboxes): `wboost-icon.png` (120px for a 36px
  slot) and the Outfit woff2 subsets (OFL). Self-hosted on purpose: a Google
  Fonts link would hand every reader's IP to Google. URLs are built with
  `absolute_url(asset(…))`, which outside a request resolves against
  `DEFAULT_URI` (`AiScanMailLinksOutsideARequestTest` forbids `//localhost`).
- **Brand spelling is "WBoost"**; sender `WBoost <robot@wboost.cz>`
  (`config/packages/mailer.php`).
- **Plain-text part** is added to every HTML mail by
  `Services/Mail/AddPlainTextPartListener` via `HtmlToPlainText` (links become
  "label (url)", the button "label: url", facts rows "Label: value"; elements
  with `data-skip-text` — the preheader — are dropped). A sender that sets its
  own text part is left alone.

## Adding an e-mail

```twig
{% extends 'emails/_layout.html.twig' %}
{% import 'emails/_ui.html.twig' as ui %}

{% block title %}Subject-like title{% endblock %}
{% block preheader %}Inbox preview line, < ~100 chars.{% endblock %}
{% block eyebrow %}Short label{% endblock %}           {# optional #}
{% block heading %}Visible h1{% endblock %}           {# defaults to title #}
{% block content %}
    <p {{ ui.a('p') }}>Dobrý den,</p>
    {{ ui.facts([['Projekt', projectName]]) }}
    {{ ui.button(url, 'Akce') }}
    {{ ui.callout('Text', 'lav', 'Titulek') }}         {# lav | mnt | rose | pch #}
    {{ ui.signoff() }}
    {{ ui.fallback_link(url) }}
{% endblock %}
{% block footer_note %}Why the recipient got this.{% endblock %}
```

`ui.callout()` escapes a plain string; pass trusted markup via
`{% set x %}…{% endset %}` (escape user input inside with `{{ }}`).

## Checking a change

Render with sample data and screenshot at 760 / 390 px in light and dark
(the 2026-11-14 run used a throwaway script in `var/` + puppeteer-core with
request interception serving `public/email/`). Nothing replaces a send to real
Gmail / Outlook / Apple Mail inboxes for client quirks.
