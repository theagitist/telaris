<?php
declare(strict_types=1);

// Standalone self-check for inc/quota.php (no framework; run: php tests/quota_selfcheck.php).
// Constants are process-global, so we fix one finite-quota configuration and
// exercise the directory walk + the boundary. The unlimited (QUOTA_BYTES == 0)
// short-circuit is a one-line guard verified by inspection.

$root = sys_get_temp_dir() . '/telaris-quota-' . getmypid();
@mkdir($root . '/uploads/sub', 0700, true);
@mkdir($root . '/hg/content/page/shared', 0700, true);
file_put_contents($root . '/uploads/a.bin', str_repeat('x', 1000));
file_put_contents($root . '/uploads/sub/b.bin', str_repeat('y', 500));   // nested upload, counted
file_put_contents($root . '/hg/content/page/shared/c.bin', str_repeat('z', 300)); // hotglue content, counted

define('UPLOAD_DIR', $root . '/uploads');
define('QUOTA_BYTES', 2000); // limit

require __DIR__ . '/../inc/quota.php';

function check(string $what, bool $cond): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "ok: $what\n";
}

check('recursive size counts nested files (1500)', quota_dir_size_bytes(UPLOAD_DIR) === 1500);
check('usage counts uploads + hotglue content (1500 + 300)', quota_usage_bytes() === 1800);
check('limit reported', quota_limit_bytes() === 2000);
check('under quota: 199 more is allowed (1999 <= 2000)', quota_would_exceed(199) === false);
check('at quota boundary: 200 more is allowed (2000 <= 2000)', quota_would_exceed(200) === false);
check('over quota: 201 more is refused (2001 > 2000)', quota_would_exceed(201) === true);

// cleanup
array_map('unlink', [
    $root . '/uploads/a.bin', $root . '/uploads/sub/b.bin', $root . '/hg/content/page/shared/c.bin',
]);
foreach ([
    $root . '/uploads/sub', $root . '/uploads',
    $root . '/hg/content/page/shared', $root . '/hg/content/page', $root . '/hg/content', $root . '/hg', $root,
] as $d) { @rmdir($d); }
echo "ALL OK\n";
