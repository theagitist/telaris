<?php
declare(strict_types=1);

/**
 * Standalone assert-based self-check for the pure math in inc/fractal-analytics.php.
 * Run: php tests/php/fractal-analytics-selfcheck.php
 *
 * Deliberately NOT a PHPUnit TestCase: it needs no DB and no framework, and the
 * full instance PHPUnit suite has a hazard (deletes the live telaris peer row).
 * ponytail: the math is what can break; the orchestrator is a thin DB loader.
 */

require_once __DIR__ . '/../../inc/fractal-analytics.php';

$failures = 0;
function check(string $label, bool $cond): void
{
    global $failures;
    if ($cond) {
        echo "  ok   $label\n";
    } else {
        echo "  FAIL $label\n";
        $failures++;
    }
}

/** Build an undirected adjacency map from an edge list; include isolates via $nodes. */
function build(array $nodes, array $edges): array
{
    $adj = [];
    foreach ($nodes as $n) {
        $adj[$n] = [];
    }
    foreach ($edges as [$a, $b]) {
        $adj[$a][] = $b;
        $adj[$b][] = $a;
    }
    return $adj;
}

function profile_from_adj(array $adj): array
{
    $components = fractal_components($adj);
    $largest = $components[0];
    $ap = fractal_all_pairs($adj, $largest);
    $covering = fractal_box_covering($largest, $ap['dist'], $ap['diameter']);
    $box = fractal_box_dimension($covering);
    $qs = [];
    for ($q = -5.0; $q <= 5.0 + 1e-9; $q += 0.5) {
        $qs[] = round($q, 1);
    }
    $mf = fractal_multifractal($covering, $qs);
    return ['diameter' => $ap['diameter'], 'covering' => $covering, 'box' => $box, 'mf' => $mf];
}

// --- Path graph P_11: fractal dimension ~ 1 ---
echo "Path graph P_11 (d_B ~ 1):\n";
$nodes = range(0, 10);
$edges = [];
for ($i = 0; $i < 10; $i++) {
    $edges[] = [$i, $i + 1];
}
$p = profile_from_adj(build($nodes, $edges));
check('diameter == 10', $p['diameter'] === 10);
check('N_B(1) == 11 (each node its own box)', $p['covering'][1]['n'] === 11);
check('N_B monotone non-increasing', (function ($c) {
    $prev = PHP_INT_MAX;
    foreach ($c as $x) {
        if ($x['n'] > $prev) {
            return false;
        }
        $prev = $x['n'];
    }
    return true;
})($p['covering']));
check('d_B in [0.7, 1.3] (got ' . round($p['box']['d_B'], 3) . ')', $p['box']['d_B'] >= 0.7 && $p['box']['d_B'] <= 1.3);
check('D0 == d_B', abs($p['mf']['D0'] - $p['box']['d_B']) < 1e-6);
check('path spectrum width modest (< 1.5, got ' . round($p['mf']['width'], 3) . ')', $p['mf']['width'] >= 0 && $p['mf']['width'] < 1.5);

// --- 2D grid 10x10 ---
// Graph SHM box-counting reads a 2D lattice BELOW its continuum dimension of 2
// (small boxes are forced to cliques on a triangle-free grid), converging near
// ~1.5-1.6 with an excellent fit. We assert that reproducible value + a clean fit
// (r2) + the qualitative fact that a grid is more space-filling than a path.
echo "2D grid 10x10 (higher-dimensional than a path):\n";
$m = 10;
$nodes = range(0, $m * $m - 1);
$edges = [];
for ($r = 0; $r < $m; $r++) {
    for ($c = 0; $c < $m; $c++) {
        $id = $r * $m + $c;
        if ($c + 1 < $m) {
            $edges[] = [$id, $id + 1];
        }
        if ($r + 1 < $m) {
            $edges[] = [$id, $id + $m];
        }
    }
}
$g = profile_from_adj(build($nodes, $edges));
check('d_B in [1.4, 1.9] (got ' . round($g['box']['d_B'], 3) . ')', $g['box']['d_B'] >= 1.4 && $g['box']['d_B'] <= 1.9);
check('clean log-log fit r2 > 0.95 (got ' . round($g['box']['r2'], 3) . ')', $g['box']['r2'] > 0.95);
check('D0 == d_B', abs($g['mf']['D0'] - $g['box']['d_B']) < 1e-6);
check('grid d_B > path d_B', $g['box']['d_B'] > $p['box']['d_B']);

// --- Star graph: degree power-law gamma computable ---
echo "Star graph (gamma):\n";
$nodes = range(0, 20);
$edges = [];
for ($i = 1; $i <= 20; $i++) {
    $edges[] = [0, $i];
}
$starAdj = build($nodes, $edges);
$gamma = fractal_degree_power_law($starAdj);
check('gamma not null', $gamma !== null);
check('gamma in [1.5, 3.5] (got ' . ($gamma === null ? 'null' : round($gamma, 3)) . ')', $gamma !== null && $gamma >= 1.5 && $gamma <= 3.5);
check('star diameter 2 => too shallow guard would trip', (function ($adj) {
    $c = fractal_components($adj);
    return fractal_all_pairs($adj, $c[0])['diameter'] === 2;
})($starAdj));

// --- Disconnected graph: component count ---
echo "Disconnected graph (components):\n";
$adj = build([0, 1, 2, 3, 4], [[0, 1], [1, 2], [3, 4]]);
$comps = fractal_components($adj);
check('2 components', count($comps) === 2);
check('largest first (size 3)', count($comps[0]) === 3);
$stats = fractal_graph_stats($adj, $comps);
check('edge_count == 3', $stats['edge_count'] === 3);
check('node_count == 5', $stats['node_count'] === 5);

echo "\n" . ($failures === 0 ? "ALL PASSED\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
