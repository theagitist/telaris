# Multigalaxy — Multi-galaxy views

Working doc for letting visitors see wormholes from more than one galaxy in the same 3D scene. Today every view is scoped to a single constellation; this is about lifting that boundary in a few different ways, each with its own use case.

## The two seed ideas (Adri)

**Idea 1 — Prefix-grouped galaxies.** Galaxy titles can carry a bracketed prefix like `[XXX] My Galaxy`. If a slug or URL fragment matches a known prefix, the view unions all galaxies that share it. Implicit grouping; no schema change needed; editors group/regroup just by renaming.

**Idea 2 — Galaxy Cluster (new galaxy type).** A first-class object that aggregates other galaxies. The user picks N member galaxies; visiting the cluster shows the union of their wormholes. Schema change, dedicated UI — but the cluster has its own slug, title, theme, description, and permalink.

The two are not mutually exclusive; they answer different needs.

## Two more options worth keeping on the table

**Idea 3 — Galaxy-level tags.** A galaxy carries one or more tags; `/cluster/foo` (or equivalent route) unions everything tagged `foo`. Same flexibility as the prefix scheme but explicit, multi-membership (a galaxy can be in several clusters), and reusable later for galaxy-side search/filter. A generalization of Idea 1 — the prefix is really a tag encoded in the title.

**Idea 4 — Multi-select query string.** `?galaxies=a,b,c` for ad-hoc combinations without persisting any cluster object. Cheap; pairs nicely with any of the above.

## The framing question

These ideas split along one axis: should clusters be **emergent** (falling out of how editors name or tag galaxies — Mocambos mucua groupings, theme prefixes, etc.) or **curated** (an editorial act with its own page, theme, and story)?

- Emergent → Idea 1 (prefix), Idea 3 (tags), Idea 4 (query string)
- Curated → Idea 2 (Galaxy Cluster object)

Both feel real, which is why the plan is to do most of them, one at a time.

## Recommendation (initial lean)

Idea 2 is the heaviest lift but the most expressive — a cluster *is something*, not just a synthetic union. It slots in cleanly because `/{slug}` already routes to a constellation; a cluster is essentially a constellation whose nodes come from a UNION query over its members.

Ideas 3 and 4 are good complements — tags give emergent grouping with explicit metadata; query string gives ad-hoc combinations without persisting state.

Idea 1 is the lightest but loses identity (no cluster name, no theme, no permalink beyond the prefix); arguably superseded by Idea 3, but it has the lowest cost and the prefix-in-title affordance is genuinely useful as a way of communicating membership in the editor and the visitor view title.

## Open sub-questions to pin down before designing

1. **Read-only or editable?** Can a cluster have wormholes added directly to it (orphan nodes), or is it strictly a view over member galaxies?
2. **Theme inheritance?** Cluster picks its own theme, or borrows from the dominant member?
3. **Cross-galaxy connections?** Today connections are computed within a constellation by shared keyword. In a cluster view, do nodes from galaxy A connect to nodes from galaxy B when they share a keyword string? This is probably the most interesting feature clusters unlock — identical keyword text in different galaxies becomes a bridge.
4. **Auto-clustering behavior?** Cluster views with N member galaxies almost certainly need the existing auto-cluster engine (`inc/clustering.php`) to kick in, with galaxy-of-origin as the natural first cascade level.
5. **Editor permissions?** If editor X owns galaxy A and editor Y owns galaxy B, who can create/edit a Galaxy Cluster that includes both? What happens if a member galaxy gets reassigned mid-life?
6. **Backup/restore.** Galaxy Clusters and galaxy tags need to ride through `.telaris-backup` files; cluster membership should reference galaxy slugs (not auto-increment IDs), consistent with the existing `ref` scheme in `inc/backup.php`.
7. **Performance.** Member-galaxy unions could yield large node sets; pagination, batching, and the inverted-index connection algorithm should keep working without N+1 regressions.

## Plan

Adri's call (2026-05-10): do both seed ideas, plus most of mine, one at a time. Tentative order:

1. **Idea 4 — query string** (cheapest; smoke test for cross-galaxy rendering)
2. **Idea 1 — prefix matching** (low risk, opportunistic UX win)
3. **Idea 3 — galaxy-level tags** (introduces tag schema, generalizes Idea 1)
4. **Idea 2 — Galaxy Cluster type** (the big one; benefits from lessons in 1–3)

Order is a sketch, not a commitment — revisit after each step.

## Status

- 2026-05-10 — design discussion captured.
- 2026-05-10 — **Idea 4 (query string) shipped on starmaps.** `?galaxies=slug-or-id,slug-or-id,...` unions the listed galaxies; theme defaults to first-listed, `&theme=<id>` overrides. Bridges (cross-galaxy connections via shared keyword text) render as subtle dashed lines (`THREE.LineDashedMaterial`); same hue as intra-galaxy lines. Auto-clustering uses the existing cascade unmodified. Per-galaxy discovery features (auto-tour, idle spotlight, keyword chips, related-nodes) are disabled in union mode. Visitor-only — editor pages are unaffected.
- 2026-05-10 — **Idea 1 (prefix matching) shipped on starmaps.** `/[XXX]` (also accepts `%5BXXX%5D`) unions every galaxy whose name starts with the literal `[XXX]` token. Reuses the same downstream pipeline as `?galaxies=`; title is `[XXX]`, tagline is the member count. Case-insensitive (MySQL `LIKE` collation).

### Implementation reference

- Backend resolution: `inc/bootstrap.php` — `?galaxies=` block sets `$multiGalaxyIds` / `$multiGalaxyTitle`; `/[XXX]` block delegates to the same shape.
- DB helpers: `db_get_nodes_for_constellations(array $ids)` and `db_get_constellations_by_name_prefix(string $prefix)` in `inc/db.php`.
- API: `api/nodes.php` accepts `?galaxies=1,5,7` (numeric IDs only — slugs are resolved server-side).
- Frontend: `js/telaris-3d.js` reads `window.TELARIS_MULTI_GALAXY_IDS`. `createConnections()` flags bridges by comparing `userData.constellation_id`; `updateConnections()` updates line geometry vertices for bridges and the cylinder transform for intra-galaxy connections.
- Globals: `window.TELARIS_MULTI_GALAXY_IDS`, `window.TELARIS_MULTI_GALAXY_TITLE` (set in `inc/main-view.php`).

### Still to ship

- Idea 3 (galaxy-level tags) — generalizes the prefix scheme; introduces a tag schema reusable for galaxy search/filter.
- Idea 2 (Galaxy Cluster type) — first-class object with own slug/title/theme/permalink.
