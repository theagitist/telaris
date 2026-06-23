<?php
declare(strict_types=1);

// Standalone self-check for inc/quota.php (no framework; run: php tests/quota_selfcheck.php).
// Constants are process-global, so we fix one finite-quota configuration and
// exercise the directory walk + the boundary. The unlimited (QUOTA_BYTES == 0)
// short-circuit is a one-line guard verified by inspection.

$tmp = sys_get_temp_dir() . '/telaris-quota-' . getmypid();
@mkdir($tmp, 0700, true);
@mkdir($tmp . '/sub', 0700, true);
file_put_contents($tmp . '/a.bin', str_repeat('x', 1000));
file_put_contents($tmp . '/sub/b.bin', str_repeat('y', 500)); // nested, must be counted

define('UPLOAD_DIR', $tmp);
define('QUOTA_BYTES', 2000); // limit

require __DIR__ . '/../inc/quota.php';

function check(string $what, bool $cond): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "ok: $what\n";
}

check('recursive size counts nested files (1500)', quota_dir_size_bytes(UPLOAD_DIR) === 1500);
check('usage == upload dir size', quota_usage_bytes() === 1500);
check('limit reported', quota_limit_bytes() === 2000);
check('under quota: 499 more is allowed (1999 <= 2000)', quota_would_exceed(499) === false);
check('at quota boundary: 500 more is allowed (2000 <= 2000)', quota_would_exceed(500) === false);
check('over quota: 501 more is refused (2001 > 2000)', quota_would_exceed(501) === true);

// cleanup
@unlink($tmp . '/sub/b.bin'); @rmdir($tmp . '/sub');
@unlink($tmp . '/a.bin'); @rmdir($tmp);
echo "ALL OK\n";
