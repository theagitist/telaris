#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI: build a backup file.
 *
 * Usage:
 *   php admin/cli/backup_export.php --output=FILE [options]
 *
 * Options:
 *   --output=FILE       Required. Path to write the .telaris-backup file.
 *   --galaxies=all      Include all galaxies (default).
 *   --galaxies=1,5,7    Include only these galaxy IDs.
 *   --no-galaxies       Skip galaxies.
 *   --no-users          Skip users.
 *   --media=embedded    (default) Embed media files in the dump.
 *   --media=refs        Keep media URLs but don't embed file contents.
 *   --media=none        Strip media URLs entirely.
 *   --quiet             Print only errors.
 */

require_once __DIR__ . '/cli_auth.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../inc/backup.php';

$opts = getopt('', ['output:', 'galaxies::', 'no-galaxies', 'no-users', 'media::', 'quiet', 'help']);

if (isset($opts['help']) || empty($opts['output'])) {
    echo "Usage: php admin/cli/backup_export.php --output=FILE [options]\n";
    echo "  --output=FILE       Required. Path to write the .telaris-backup file.\n";
    echo "  --galaxies=all      Include all galaxies (default).\n";
    echo "  --galaxies=1,5,7    Include only these galaxy IDs.\n";
    echo "  --no-galaxies       Skip galaxies.\n";
    echo "  --no-users          Skip users.\n";
    echo "  --media=embedded    (default) Embed media files in the dump.\n";
    echo "  --media=refs        Keep media URLs but don't embed file contents.\n";
    echo "  --media=none        Strip media URLs entirely.\n";
    echo "  --quiet             Print only errors.\n";
    exit(empty($opts['output']) ? 1 : 0);
}

$quiet = isset($opts['quiet']);

$includeGalaxies = !isset($opts['no-galaxies']);
$includeUsers = !isset($opts['no-users']);
$mediaMode = $opts['media'] ?? 'embedded';
if (!in_array($mediaMode, ['embedded', 'refs', 'none'], true)) {
    fwrite(STDERR, "Invalid --media value: must be embedded, refs, or none.\n");
    exit(2);
}

$galaxyIds = [];
if ($includeGalaxies) {
    $arg = $opts['galaxies'] ?? 'all';
    if ($arg !== 'all' && $arg !== '') {
        foreach (explode(',', (string)$arg) as $piece) {
            $piece = trim($piece);
            if ($piece !== '' && ctype_digit($piece)) $galaxyIds[] = (int)$piece;
        }
    }
}

if (!$includeGalaxies && !$includeUsers) {
    fwrite(STDERR, "Nothing to back up: both galaxies and users are excluded.\n");
    exit(2);
}

$output = (string)$opts['output'];
if (file_exists($output)) {
    fwrite(STDERR, "Refusing to overwrite existing file: $output\n");
    exit(2);
}

if (!$quiet) echo "Building dump...\n";
try {
    $dump = backup_build_dump([
        'include_galaxies' => $includeGalaxies,
        'include_users' => $includeUsers,
        'galaxy_ids' => $galaxyIds,
        'media_mode' => $mediaMode,
    ]);
    $info = backup_write_to_file($dump, $output);
} catch (Throwable $e) {
    fwrite(STDERR, "Export failed: " . $e->getMessage() . "\n");
    exit(1);
}

if (!$quiet) {
    $g = count($dump['galaxies']);
    $u = count($dump['users']);
    $m = count($dump['media_blobs']);
    $mb = number_format($info['size_bytes'] / 1048576, 2);
    echo "Wrote {$output} ({$mb} MB) — {$g} galaxies, {$u} users, {$m} media files.\n";
}
exit(0);
