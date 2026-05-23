# Telaris

*weaving memory*

A decolonial knowledge archive project. Relational, peer-to-peer, non-hierarchical, threaded by meaning. Content lives in **galaxies**: clusters of small content units called **wormholes** (a passage, an image, a sound, a film clip, a document) that connect through shared **keywords**. There are no folders, no parents, no breadcrumbs, no algorithm; the structure is a rhizome maintained by editors and read by visitors as a three-dimensional scene.

Telaris is a graduate research project at the University of British Columbia's Institute for Gender, Race, Sexuality and Social Justice. It is open-source software run by independent operators across countries and communities, not a platform run by a single owner.

The public-facing project page is at <https://www.telaris.ca>. This repository is the **Telaris instance software**: what an operator deploys to run a Telaris node.

## What Telaris is

A decolonial archive in method, not metaphor. The practice has concrete consequences in how the software is designed and operated:

- **Refusal of imposed categorical reductions.** No central vocabulary, no required ontology, no editor-in-chief. Each instance carries the keyword graph its editors and source communities build, not a tree imposed from above.
- **Data sovereignty for source communities.** The people whose material is hosted on a Telaris instance retain authority over it. Withdrawal of consent is final and is acted on by the operator without negotiation.
- **Editorial sovereignty for editors.** Editors decide what to publish in the galaxies they tend. There is no review queue, no centrally-approved vocabulary, no "wrong" keyword. The software does not police editorial choice.
- **Operator sovereignty for instance operators.** Each Telaris instance is run by an independent operator under their own rules. There is no central authority over operators. They agree to a small set of cross-network commitments (cryptographic identity, honouring federation withdrawals, abiding by these principles) but otherwise govern their instances independently.

These are method choices, not slogans. They show up in the code (no review-queue feature, no central-vocabulary table, no platform-administrator override) as readily as in the documentation.

## What Telaris is not

A clear refusal is a clearer position than a long manifesto.

- **Not a platform.** No single owner. No central catalogue. No advertising. No tracking. No data sold or shared for commercial purposes.
- **Not an AI training corpus.** Telaris content is not used to train AI models, internally or externally. The corpus is not piped to language-model providers for moderation, classification, summarisation, or any other purpose.
- **Not a hierarchy.** No tree structure on the content. No editorial review queue. No "best content" promoted by an algorithm.
- **Not extractive.** Content contributed by source communities does not become Telaris's property. Editors do not lose authorship by publishing on Telaris. Operators do not have rights over editors' content beyond what each instance's contract makes explicit.

The full position statement is the [Manifest](https://www.telaris.ca/docs/manifest.pdf), kept short on purpose.

## Documentation

The documentation set is published at <https://www.telaris.ca/docs/> in English, Spanish, and Portuguese. Source markdown lives in a separate repository at [theagitist/telaris-documentation](https://github.com/theagitist/telaris-documentation).

| Document | Audience |
|---|---|
| [Manifest](https://www.telaris.ca/docs/manifest.pdf) | Anyone reading the project from outside |
| [Editor Quick Start](https://www.telaris.ca/docs/editor-quick-start.pdf) | New editors who want the shortest path to a first wormhole |
| [Editor Manual](https://www.telaris.ca/docs/editor-manual.pdf) | Editors authoring galaxies, wormholes, keywords, portals, tours |
| Admin Manual | Operators running a Telaris instance. Draft pending |
| [Privacy](https://www.telaris.ca/docs/privacy.pdf), [Terms](https://www.telaris.ca/docs/tos.pdf) | Public-facing legal posture (draft) |

Every public document is available in three sibling editions: `<slug>.pdf` (English), `<slug>-es.pdf` (Spanish), `<slug>-pt.pdf` (Portuguese). The three languages are sibling phrasings of the same voice, not translations of the English.

## Concepts

| Concept | Code identifier | What it is |
|---|---|---|
| **Galaxy** | `constellation` | A cluster of wormholes. Each galaxy is a tended space, with its own editors, theme, and editorial framing. |
| **Wormhole** | `node` | The smallest unit of content. A wormhole carries a name, a description, optional media (image, audio, video, PDF), and a set of keywords. |
| **Keyword** | `keyword` | A short tag attached to wormholes. Two wormholes that share a keyword are connected in the visualization; there is no other connection mechanism. |
| **Portal** | `portal` (wormhole type) | A doorway between galaxies. A portal wormhole points at another galaxy; visitors can travel through it. |
| **Keyword canvas** | `/edit/keyword-canvas.php` | The editor surface for sketching keyword-to-keyword relationships within a galaxy. |
| **Tour** | (per-galaxy setting) | A curated path through a galaxy that visitors can replay. |

The code uses the internal identifiers (`constellation`, `node`, `portal`); user-facing strings use the public vocabulary (Galaxy, Wormhole, Portal). The mapping is documented in the [brand book](https://github.com/theagitist/telaris-documentation/tree/main/src/brand-book) and enforced in `inc/locale/`.

## Current state

Latest version: **v6.9.x** on the deployed instances.

Active design and implementation threads (May 2026):

- **Federation, peer-to-peer.** Implementation-ready design (the "Pluriverse" coordination layer). Bilateral, consent-based federation between independent operators; cryptographic identity per peer; consent withdrawal honoured network-wide. The Pluriverse is the central coordination layer that hosts operator registry, key rotation, and consent-withdrawal propagation; the application proper lands at <https://www.telaris.ca> when federation ships.
- **Documentation set.** Editor Manual v0.1 first draft, fifteen chapters, available in English / Spanish / Portuguese.
- **Brand book v1.** Visual identity, voice canon, naming conventions, typography (monospace throughout), palette.

Active deployed instances are listed at <https://www.telaris.ca/instances/>.

## Install

For operators who want to run a Telaris instance. The Admin Manual will carry the depth; this section is enough to get a development install running.

### Requirements

- **PHP 8.3+** with PDO MySQL extension
- **MySQL 8+**
- **Nginx** (or Apache with mod_rewrite)
- Web server with SSL (recommended for any non-development use)

### Setup

Setup happens in two phases — application install (web-context, no privileges) and host provisioning (CLI, requires root).

**1. Application install:**

```sh
git clone git@github.com:theagitist/telaris.git
# Configure your web server to point at the repo root with PHP-FPM enabled.
```

Then open the setup script in a browser at `https://your-domain.com/admin/setup.php` (or visit `/admin/` and follow the redirect). The web setup is four steps:

1. **Database connection.** Host, port, database name, user, password. The script lists PHP version and required extensions on this screen.
2. **Schema.** Tables are created automatically. The default galaxy (id 0) is created here and cannot be deleted.
3. **Site identity.** Name, tagline.
4. **First admin user.** Email, password, name.

If the setup script cannot write `config.php` due to filesystem permissions, it displays the configuration content in a text area for manual creation.

**2. Host provisioning:**

After the web install, run the host setup script as root. It writes the Cloudflare real-IP nginx snippet, installs the logrotate rule for snapshot logs, tightens the permissions on `config.php`, and reloads nginx. It's idempotent and Ubuntu/Debian-only.

```sh
sudo php bin/setup-host.php --check   # report what's installed / missing
sudo php bin/setup-host.php           # install / rewrite to canonical
```

The host script handles the bits `admin/setup.php` cannot reach because it runs as the web user without sudo. Run `--check` any time to verify the host is in canonical state.

### Access points

After setup:

- **Main view (visitor):** `/` shows the default galaxy. A specific galaxy: `/{id}` or `?constellation_id={id}`. A specific wormhole: `/{slug-or-id}/{wormhole-id}` opens the wormhole's info card.
- **Login:** `/utils/login.php`
- **Editor surface:** `/edit/` (editor or admin login required)
- **Admin console:** `/admin/` (admin login required)

### CLI scripts

Administrative scripts live in `admin/cli/`:

- `create_user.php`: create new admin or editor users interactively
- `hard_reset.php`: complete reset, drops all tables and deletes `config.php`

See `admin/cli/README.md` for detail.

## Roles

Three roles, defined by the editorial sovereignty principle (not by privilege tier):

- **Visitor.** Reads the work editors have published. No account required.
- **Editor.** Tends one or more galaxies. Adds wormholes, assigns keywords, draws keyword-canvas relations, places portals, designs tours. Editorial decisions are final within the galaxies they tend; no review queue, no approval flow.
- **Admin.** Operates the instance. Manages galaxies, users, themes, backups, snapshots, federation peers (when federation ships). Per the operator-sovereignty principle, each admin governs their instance under their own rules.

The data column `users.type` stores `0` (regular / visitor), `1` (editor), `2` (admin). Passwords are bcrypt-hashed via `password_hash()`.

## Architecture

The schema is authoritative in `SCHEMA.sql`; the runtime helpers in `inc/db.php` ensure every table and column exists on startup (idempotent `db_ensure_*` patterns). Major tables:

- `constellations` (galaxies): name, tagline, theme, editorial framing
- `nodes` (wormholes): name, description, URL, media references, animation parameters, the galaxy they belong to
- `keywords`: scoped per galaxy, unique within their galaxy
- `node_keywords`: many-to-many between wormholes and keywords; connections in the 3D scene are computed from shared keywords (inverted index)
- `users`, `api_keys`, `project_info` (multilingual: one row per locale), `snapshots`, `snapshot_schedule`

For the rhizome-vs-tree design choice and how that lands in the schema, see [`Architecture/Rhizomatic database.md`](https://github.com/theagitist/telaris-documentation) in the documentation working notes.

## Bridges

A **Bridge** pulls content from a non-Telaris source into a local galaxy. The first Bridge is **Mocambos**, which imports from a [Baobáxia](https://baobaxia.org/) instance (the Brazilian quilombola digital archive system). Bridges run on a single Telaris instance and are not federation; the federation that subsequently shares the imported galaxies between Telaris instances is a separate layer that lands later.

### Enabling a bridge

Bridges are off by default. To enable one, edit `config.php`:

```php
define('TELARIS_BRIDGES', ['mocambos']);
```

The constant is a flat array of handler names. Each name corresponds to a subdirectory `inc/bridges/{name}/` with a standard file layout (see [`BRIDGES.md`](BRIDGES.md)). With no enabled bridges, the admin *Import from...* surface is hidden and the CLI dispatcher refuses unknown names.

### Importing from Mocambos

With `mocambos` enabled, an operator can import either through the admin UI (the *Import from Mocambos* button in `/admin/`, which walks through URL validation, galaxia selection, and live progress) or from the command line:

```sh
# Interactive
php admin/cli/import_bridge.php mocambos

# List galaxias available from a source
php admin/cli/import_bridge.php mocambos --api-base=https://oya.mocambos.net/api/v2 --list

# Import one galaxia by slug
php admin/cli/import_bridge.php mocambos \
    --api-base=https://oya.mocambos.net/api/v2 \
    --galaxia=acervo-do-coletivo-x
```

Useful flags: `--no-media` (skip downloads, fast preview), `--limit=N` (test runs), `--quiet` (machine-readable output), `--full` (delete and re-import rather than incremental diff).

Re-imports are incremental by default: nodes are matched on `import_slug`, and only added / modified / deleted items hit the database. A galaxy that was imported earlier can be refreshed with `admin/cli/refresh_constellation.php`.

### Writing a new bridge

The framework is a plug-in surface. Adding a new provider is one subdirectory under `inc/bridges/{name}/` with two standard filenames (`handler.php` required, `admin.php` optional) plus whatever internal helpers the bridge needs. No generic file in the codebase changes when a new bridge ships.

The full procedure, file-by-file contract, handler interface, hook list, naming conventions, and reference patterns are documented in [`BRIDGES.md`](BRIDGES.md).

## Testing

```sh
php composer install              # one-time, installs PHPUnit
npm run test:all                  # PHP + JS

php vendor/bin/phpunit            # PHP only
php vendor/bin/phpunit --testsuite unit
php vendor/bin/phpunit --testsuite integration
node --test tests/js/*.test.js    # JS only (Node 22+, no npm deps)
```

PHP unit tests validate pure functions in `inc/validation.php`, `inc/media-optimize.php`, and `utils/auth.php`: URL validation, embed-code sanitization, wormhole-type handling, password hashing, slug generation, media optimization, API output format. The CSP compatibility test scans public-facing HTML templates for inline event handlers that break the nonce-based Content Security Policy.

PHP integration tests exercise runtime database migrations against a real MySQL connection using temporary tables suffixed `_aitest` / `_test`. The critical test reproduces the `AUTO_INCREMENT` migration that must drop and re-add foreign keys (the scenario that broke production once).

JS tests validate the theme registry and the `NetworkManager`'s focus / opacity / visibility behaviour. They use Node's built-in test runner; no `npm install` is needed.

## Features (current)

The application carries the following editor-facing and visitor-facing surfaces. See the [Editor Manual](https://www.telaris.ca/docs/editor-manual.pdf) for in-depth coverage; what follows is an inventory.

**Visitor side:**

- 3D scene with organic animation, pastel wormhole icons, semi-transparent connections drawn from shared-keyword inverted index
- 2D wormhole view as an alternative layout (Poisson-disc placement, opt-in per galaxy)
- Theme system (cosmic, abstract, rectangles, stripes, tech) per galaxy
- Multigalaxy views: prefix-family unions (`/[XXX]`), tag unions (`/tag/<slug>`), explicit Galaxy Cluster type, query-string ad-hoc unions
- Cross-galaxy related-wormholes panel in the info card
- Auto-rotation when idle, fuzzy search, keyword-chip filter strip
- Permalinks to galaxies and to individual wormholes
- Tactile launch animation when navigating to external URLs
- PDF wormhole media via PDF.js

**Editor side:**

- `/edit/` console with paginated, sortable, searchable wormhole and galaxy lists
- New / edit modal with image, audio, video, PDF fields; in-place media optimization (image resize 1344 px, audio 128 kbps mono, video 720p H.264)
- Video frame extraction (first frame becomes JPEG thumbnail)
- Keyword canvas (`/edit/keyword-canvas.php`): drag chips, draw attributed relations, physics-based settling, click-to-rename / merge / delete
- Bulk operations by keyword (move, delete) and by selection (move, duplicate, delete)
- Wormhole duplication, within or across galaxies
- Bracket-prefix selection (`[TE]`, `[FT]`) for bulk operations across galaxy families

**Admin side:**

- `/admin/` console with paginated galaxy list, theme management, user management, bulk user creation
- Backup and restore: `.telaris-backup` portable archive (gzipped JSON, optional embedded media), per-galaxy conflict resolution
- Snapshot system: local on-disk full-system backups with daily scheduler, age-based retention, manual + scheduled triggers
- Outbound mail via SMTP (Mailgun + PHPMailer): password reset, bulk-user welcome emails

## License

Telaris is released under a tiered license per the manifest's sixth principle.

- **The Telaris instance software in this repository** is licensed under **GPL v3**. See `LICENSE` for the full text.
- **The Pluriverse coordination layer** (when the [`telaris-portal`](https://github.com/theagitist/telaris) repository is published) will be licensed under **AGPL v3**. The stronger license reflects the network-coordination role of the Pluriverse: source modifications served over the network must be made available to users.
- **Editorial content** carried by Telaris instances (wormholes, descriptions, media, keyword relations, tours) is licensed by the editor or source community who publishes it, attached to each piece of content. The software is given away; the content is not annexed to give-away.

This split is load-bearing. It is part of how Telaris refuses the platform pattern: the means of presenting knowledge is open, the knowledge itself stays with its editors and source communities.

## See also

- [Manifest (PDF)](https://www.telaris.ca/docs/manifest.pdf): position statement
- [Editor Manual (PDF)](https://www.telaris.ca/docs/editor-manual.pdf): complete editor-side reference
- [Editor Quick Start (PDF)](https://www.telaris.ca/docs/editor-quick-start.pdf): five-step walkthrough
- [theagitist/telaris-documentation](https://github.com/theagitist/telaris-documentation): canonical home for all Telaris documentation that ships as a PDF, including the brand book and the documentation source markdown
- [theagitist/telaris_website](https://github.com/theagitist/telaris_website): source for <https://www.telaris.ca>
- Federation design lives in the documentation working notes (private until v1 ships); the implementation-ready version is plan v10, around 1800 lines of OpenAPI 3.1 + RFC 9421 HTTP Signatures + RFC 7515 JWS + libsodium + push-and-pull key-event propagation.

---

Built by Adri M. and Manuel Piña at UBC, in dialogue with the Mocambos / Baobáxia quilombola archive tradition and the body of decolonial theory cited in the [Manifest](https://www.telaris.ca/docs/manifest.pdf).
