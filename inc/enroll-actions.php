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
                $gid = db_create_constellation($name, '', null, 'cosmic', $userId);
                db_add_user_constellation($userId, $gid, 'read_write');
                $result['personal_galaxy_id'] = $gid;
                // Ship every personal galaxy with the visitor-experience features
                // on: keyword chips, related wormholes, 2D view switch, idle
                // spotlight (all nodes). New galaxies have these off by default.
                // Best-effort: never let a feature/grouping hiccup lose the galaxy.
                try {
                    db_enable_personal_galaxy_default_features($gid);
                } catch (Throwable $e) {
                    error_log('enroll_apply_config: enabling personal-galaxy features failed: ' . $e->getMessage());
                }
                // Gather every auto-created personal galaxy into the per-installation
                // cluster named after the subdomain (e.g. "[GRSJ306]"), creating it
                // on the first enrolment.
                $sub = enroll_installation_subdomain();
                if ($sub !== null) {
                    try {
                        $clusterId = db_find_or_create_named_cluster('[' . $sub . ']');
                        db_add_cluster_member($clusterId, $gid);
                    } catch (Throwable $e) {
                        error_log('enroll_apply_config: per-installation cluster grouping failed: ' . $e->getMessage());
                    }
                }
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
