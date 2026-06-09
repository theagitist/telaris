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
- **Localisation.** Editor-facing strings that surface through Telaris are localised via Telaris's translation framework (EN/ES/PT/FR). HOTGLUE's internal identifiers stay as they are.
- **We do not change HOTGLUE's on-disk content format**, so self-hosted HOTGLUE pages import by directory copy and render identically.

## Tracking upstream

```
git remote add upstream https://github.com/k0a1a/hotglue2.git
git fetch upstream
```

Merge upstream changes into a topic branch and reconcile against the `telaris` patches.
