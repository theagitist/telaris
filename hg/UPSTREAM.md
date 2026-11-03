# Upstream provenance

This repository is a fork of **[k0a1a/hotglue2](https://github.com/k0a1a/hotglue2)**, the HOTGLUE content-manipulation system by Gottfried Haider and Danja Vasiliev with the people from WORM (worm.org). HOTGLUE is licensed **GPL-3.0**, and this fork retains that licence (see `COPYING`). All credit for HOTGLUE itself belongs upstream.

## Fork point

- Forked from `k0a1a/hotglue2`, branch `dev`, at commit `210b87981257d52e039e361750e7147740d450f9` (2026-05-30).

## Why this fork exists

This fork adapts HOTGLUE to run as an embedded, per-wormhole media composition surface inside [Telaris](https://www.telaris.ca) (a 3D relational knowledge-network application). Telaris-specific work lives on the **`telaris`** branch; `dev` and `master` track upstream.

## What we change (and what we deliberately do not)

- **PHP 8.3 + modern tooling compatibility.** Keep the code running cleanly on PHP 8.3 and current PHPUnit.
- **Authentication.** Replace HOTGLUE's single global password with Telaris's own session, per-galaxy seat, and CSRF checks, so editing is gated by Telaris's multi-editor model. Viewing stays public and read-only.
- **Safe embedding.** Rendered pages are embedded in a hard-sandboxed iframe inside Telaris; editor-authored code is contained.
- **Localisation.** The editor UI and server messages are localised (EN/ES/PT/FR) by the fork's own i18n layer (`module_i18n`, catalogs in `lang/*.json`); Telaris supplies only the active locale code. See "Editor i18n bridge" below. HOTGLUE's internal identifiers stay as they are.
- **We do not change HOTGLUE's on-disk content format**, so self-hosted HOTGLUE pages import by directory copy and render identically.

## Tracking upstream

```
git remote add upstream https://github.com/k0a1a/hotglue2.git
git fetch upstream
```

Merge upstream changes into a topic branch and reconcile against the `telaris` patches.

## Editor i18n bridge (telaris branch)

The editor UI localization itself is generic and lives on `main`:

- `module_i18n.inc.php` + `modules/i18n/i18n-edit.{js,css}` + `lang/{en,es,fr,pt}.json`
  (symbolic namespaced keys; `en.json` is the source of truth; fallback chain
  locale -> en -> key). Server strings use the PHP helper `t($key, ...$args)`;
  editor JS uses `$.glue.t(key, ...args)`. Adding a language = drop one
  `lang/<code>.json`.
- Standalone resolves the locale from a corner language **selector** (cookie),
  then `?lang=`, `Accept-Language`, `DEFAULT_LOCALE`.

The **`telaris` branch** adds one additive file, `module_telaris_locale.inc.php`,
which (a) hides the selector and (b) drives the locale from the Telaris account.
It introduces no upstream edits.

**CONTRACT - the Telaris embedding MUST provide the active user locale** in ONE of
(checked in order):

1. PHP constant:  `define('TELARIS_LOCALE', 'es');`  (e.g. from the auth bridge,
   before HOTGLUE renders)
2. `$_SERVER`:    `fastcgi_param TELARIS_LOCALE $your_locale_var;` (nginx -> php-fpm)
3. environment:   `TELARIS_LOCALE=es` (read via `getenv`)

The value is a language code (`es`/`fr`/`pt`/`en`) or a tag (`pt-BR`, `fr_FR`);
it is matched against the available `lang/*.json` catalogs (full tag, then
primary subtag). If Telaris provides nothing valid, the locale falls back to
`Accept-Language`/`DEFAULT_LOCALE`, but the **selector stays hidden** regardless
(a Telaris editor never picks a UI language; it follows their account).

`USE_MIN_FILES` must be **false** on Telaris installs too (the i18n wrap edits the
`.js` sources, not the `.min.js` twins). Self-check: `php tests/i18n-telaris.test.php`.

Verify on an embedded editor page once Telaris sets `TELARIS_LOCALE`:

```sh
# tooltips/menus localized, no language <select> in the editor chrome:
curl -s 'https://<telaris-host>/hg/<page>/edit' | grep -c 'glue-lang-select'   # expect 0
# the injected dict carries the account locale:
curl -s 'https://<telaris-host>/hg/<page>/edit' | grep -o '\$.glue.locale = "[a-z-]*"'
```
