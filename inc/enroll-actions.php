<?php
declare(strict_types=1);

/**
 * Editor self-enrollment: DB-touching actions composed at enrolment-confirm time.
 * Factored out of utils/enroll.php so the confirm flow can be exercised by an
 * integration test without driving the HTTP/session layer.
 */

require_once __DIR__ . '/enroll-helpers.php';

/**
 * Apply the auto-enroll config to a freshly confirmed editor: create their
 * personal galaxy per the naming convention (or defer it for user_choice), and
 * grant the configured galaxies at the configured access level.
 *
 * Single-seat adds (db_add_user_constellation) preserve any seat created earlier
 * in this call. $possessiveTemplate is the localized "%s's galaxy" string,
 * passed in so this stays callable outside a request/locale context.
 *
 * @param array<string,mixed> $cfg auto-enroll config (already normalized)
 * @return array{personal_galaxy_id:?int,deferred:bool,granted:list<int>}
 */
function enroll_apply_config(string $userId, string $email, string $firstname, array $cfg, string $possessiveTemplate = "%s's galaxy"): array {
    $result = ['personal_galaxy_id' => null, 'deferred' => false, 'granted' => []];

    if (!empty($cfg['create_personal_galaxy'])) {
        $name = enroll_personal_galaxy_name((string)($cfg['naming_convention'] ?? ENROLL_NAMING_DEFAULT), $email, $firstname, $possessiveTemplate);
        if ($name !== null && trim($name) !== '') {
            try {
                // Deduplicate the slug so a shared name (two editors named the
                // same, or one editor enrolling twice) never collides on the
                // UNIQUE slug and silently loses the personal galaxy.
                $gid = db_create_constellation($name, '', db_unique_constellation_slug($name), 'abstract', $userId);
                db_add_user_constellation($userId, $gid, 'read_write');
                $result['personal_galaxy_id'] = $gid;
                // Standard personal-galaxy setup (features + Abstract theme +
                // per-installation cluster). Best-effort so a hiccup never loses
                // the galaxy.
                enroll_setup_personal_galaxy($gid);
            } catch (Throwable $e) {
                error_log('enroll_apply_config: personal galaxy creation failed: ' . $e->getMessage());
            }
        } else {
            // user_choice (or unnameable): defer; the editor is prompted on first load.
            db_set_pending_personal_galaxy($userId);
            $result['deferred'] = true;
        }
    }

    $level = in_array($cfg['access_level'] ?? '', ENROLL_ACCESS_LEVELS, true) ? (string)$cfg['access_level'] : ENROLL_ACCESS_DEFAULT;
    foreach ((array)($cfg['galaxy_ids'] ?? []) as $cid) {
        $cid = (int)$cid;
        if ($cid <= 0) {
            continue;
        }
        // A configured galaxy may have been deleted after the config was saved;
        // skip it (the seat FK insert would throw) and keep granting the rest,
        // mirroring the try-catch around personal-galaxy creation above.
        try {
            db_add_user_constellation($userId, $cid, $level);
            $result['granted'][] = $cid;
        } catch (Throwable $e) {
            error_log('enroll_apply_config: skipped granting galaxy ' . $cid . ': ' . $e->getMessage());
        }
    }

    return $result;
}

/**
 * Standard setup applied to every personal galaxy an auto-enrolled editor gets,
 * regardless of how it came to exist (named at enrolment, or created by the
 * editor in the deferred user_choice flow):
 *
 *   - turn on the visitor-experience features (keyword chips, related wormholes,
 *     2D view switch, idle spotlight over all nodes), which new galaxies default
 *     off;
 *   - default the visual theme to Abstract; and
 *   - gather it into the single per-installation cluster named after the
 *     subdomain (e.g. "[GRSJ306]"), created on first use and reused after.
 *
 * Each step is guarded independently so a feature/theme/grouping hiccup is
 * logged but never propagates to the caller.
 */
function enroll_setup_personal_galaxy(int $galaxyId): void {
    if ($galaxyId <= 0) {
        return;
    }
    try {
        db_enable_personal_galaxy_default_features($galaxyId);
    } catch (Throwable $e) {
        error_log('enroll_setup_personal_galaxy: enabling features failed: ' . $e->getMessage());
    }
    try {
        db_set_constellation_theme($galaxyId, 'abstract');
    } catch (Throwable $e) {
        error_log('enroll_setup_personal_galaxy: setting theme failed: ' . $e->getMessage());
    }
    $clusterName = enroll_installation_cluster_name();
    if ($clusterName !== null) {
        try {
            $clusterId = db_find_or_create_named_cluster($clusterName);
            db_add_cluster_member($clusterId, $galaxyId);
        } catch (Throwable $e) {
            error_log('enroll_setup_personal_galaxy: cluster grouping failed: ' . $e->getMessage());
        }
    }
}

/**
 * Deferred personal-galaxy binding. When an editor's personal-galaxy creation
 * was deferred (user_choice naming), the FIRST galaxy they create themselves
 * becomes their personal one: apply the standard setup above and consume the
 * pending flag. No-op when the user has no pending flag (e.g. an ordinary editor
 * creating an additional galaxy), so it is safe to call on every editor-side
 * galaxy creation.
 */
function enroll_bind_deferred_personal_galaxy(string $userId, int $galaxyId): void {
    if ($userId === '' || $galaxyId <= 0) {
        return;
    }
    if (!db_has_pending_personal_galaxy($userId)) {
        return;
    }
    enroll_setup_personal_galaxy($galaxyId);
    db_take_pending_personal_galaxy($userId); // consume: only the first galaxy is the personal one
}
