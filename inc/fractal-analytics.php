<?php
declare(strict_types=1);

/**
 * Fractal analytics for the keyword-shared graph of a galaxy.
 *
 * The analytic/measurement layer from the fractal brainstorm: it measures the
 * structure already present in a galaxy's co-occurrence graph (edge = two
 * wormholes share at least one keyword), so it authors nothing and satisfies
 * the decolonial named-authorship constraint trivially.
 *
 * The pure functions here take an adjacency map (array<int,int[]>) and do no
 * DB access, so they are testable standalone (see tests/php/fractal-analytics-selfcheck.php).
 * Only fractal_profile() touches the DB, reusing db_get_connections().
 *
 * Downstream consumers (substrate themes rendering measured structure, the
 * spatial fractal-dimension map, the restitution narrative) call fractal_profile().
 */

// Ceilings. Box covering is O(diameter * V^2) with an all-pairs distance matrix,
// heavy in PHP past ~1k nodes on a memory-tight host.
// ponytail: hard cap; add node sampling or a sparse covering if huge galaxies ever need this.
const FRACTAL_MAX_NODES = 1000;
const FRACTAL_MIN_COMPONENT = 4; // need a few points to fit a slope
const FRACTAL_MIN_DIAMETER = 3;  // need >= 3 distinct box sizes for log-log fit
// Small enough to draw the literal wormhole network legibly (past this it is a
// hairball, so only the degree distribution + spectrum are shown).
const FRACTAL_NETWORK_MAX = 80;

/**
 * BFS single-source shortest paths on an unweighted adjacency map.
 * Returns [nodeId => distance] for reachable nodes only.
 */
function fractal_bfs_distances(array $adj, int $src): array
{
    $dist = [$src => 0];
    $queue = [$src];
    $head = 0;
    while ($head < count($queue)) {
        $u = $queue[$head++];
        foreach ($adj[$u] ?? [] as $v) {
            if (!isset($dist[$v])) {
                $dist[$v] = $dist[$u] + 1;
                $queue[] = $v;
            }
        }
    }
    return $dist;
}

/**
 * Connected components. Returns a list of components, each a list of node ids,
 * sorted largest-first.
 */
function fractal_components(array $adj): array
{
    $seen = [];
    $comps = [];
    foreach (array_keys($adj) as $start) {
        if (isset($seen[$start])) {
            continue;
        }
        $comp = [];
        $stack = [$start];
        $seen[$start] = true;
        while ($stack) {
            $u = array_pop($stack);
            $comp[] = $u;
            foreach ($adj[$u] ?? [] as $v) {
                if (!isset($seen[$v])) {
                    $seen[$v] = true;
                    $stack[] = $v;
                }
            }
        }
        $comps[] = $comp;
    }
    usort($comps, fn($a, $b) => count($b) <=> count($a));
    return $comps;
}

/**
 * All-pairs shortest-path distances restricted to a set of node ids.
 * Returns [u => [v => distance]] and the diameter (max finite distance).
 */
function fractal_all_pairs(array $adj, array $nodeIds): array
{
    $set = array_flip($nodeIds);
    $D = [];
    $diam = 0;
    foreach ($nodeIds as $u) {
        $du = fractal_bfs_distances($adj, $u);
        $row = [];
        foreach ($du as $v => $d) {
            if (isset($set[$v])) {
                $row[$v] = $d;
                if ($d > $diam) {
                    $diam = $d;
                }
            }
        }
        $D[$u] = $row;
    }
    return ['dist' => $D, 'diameter' => $diam];
}

/**
 * Song-Havlin-Makse box covering via greedy assignment: a node joins an existing
 * box only if it is within distance < l_B of every current member (so each box
 * has diameter < l_B), else it opens a new box. N_B(l_B) = number of boxes.
 * One covering feeds the box dimension and the multifractal spectrum.
 *
 * Returns [l_B => ['n' => count, 'masses' => [fraction, ...]]] for l_B in [1..diameter].
 * ponytail: greedy is the canonical SHM approximation; exact MEMB burning if it ever matters.
 */
function fractal_box_covering(array $nodeIds, array $D, int $diameter): array
{
    $total = count($nodeIds);
    // Greedy quality depends on node order, so try a few deterministic orderings and
    // keep the fewest boxes per l_B (the standard SHM best-of-K robustness trick,
    // kept deterministic so results and tests are reproducible).
    $orderings = fractal_covering_orderings($nodeIds, $D);
    $out = [];
    for ($lB = 1; $lB <= $diameter; $lB++) {
        $best = null;
        foreach ($orderings as $order) {
            $boxes = fractal_greedy_boxes($order, $D, $lB);
            if ($best === null || count($boxes) < count($best)) {
                $best = $boxes;
            }
        }
        $masses = [];
        foreach ($best as $members) {
            $masses[] = count($members) / $total;
        }
        $out[$lB] = ['n' => count($best), 'masses' => $masses];
    }
    return $out;
}

/** One greedy box covering pass: a node joins the first box all of whose members
 *  are within distance < l_B, else opens a new box. */
function fractal_greedy_boxes(array $order, array $D, int $lB): array
{
    $boxes = [];
    foreach ($order as $u) {
        $placed = false;
        foreach ($boxes as &$members) {
            $fits = true;
            foreach ($members as $m) {
                if (($D[$u][$m] ?? PHP_INT_MAX) >= $lB) {
                    $fits = false;
                    break;
                }
            }
            if ($fits) {
                $members[] = $u;
                $placed = true;
                break;
            }
        }
        unset($members);
        if (!$placed) {
            $boxes[] = [$u];
        }
    }
    return $boxes;
}

/** A small set of deterministic node orderings for the best-of-K covering:
 *  natural, reverse, degree-descending, degree-ascending, and BFS from the
 *  most-central node (processing dense/central nodes first packs boxes better). */
function fractal_covering_orderings(array $nodeIds, array $D): array
{
    $natural = array_values($nodeIds);
    $reverse = array_reverse($natural);

    // Degree within the component = number of distance-1 neighbours in $D.
    $deg = [];
    foreach ($nodeIds as $u) {
        $d = 0;
        foreach ($D[$u] ?? [] as $dist) {
            if ($dist === 1) {
                $d++;
            }
        }
        $deg[$u] = $d;
    }
    $byDegDesc = $natural;
    usort($byDegDesc, fn($a, $b) => $deg[$b] <=> $deg[$a]);
    $byDegAsc = array_reverse($byDegDesc);

    // BFS order from the node with smallest eccentricity (most central).
    $center = $natural[0];
    $bestEcc = PHP_INT_MAX;
    foreach ($nodeIds as $u) {
        $ecc = 0;
        foreach ($D[$u] ?? [] as $dist) {
            if ($dist > $ecc) {
                $ecc = $dist;
            }
        }
        if ($ecc < $bestEcc) {
            $bestEcc = $ecc;
            $center = $u;
        }
    }
    $bfs = $natural;
    usort($bfs, fn($a, $b) => ($D[$center][$a] ?? PHP_INT_MAX) <=> ($D[$center][$b] ?? PHP_INT_MAX));

    return [$natural, $reverse, $byDegDesc, $byDegAsc, $bfs];
}

/**
 * Scaling region: the box sizes to actually fit. At large l_B the covering
 * saturates (N_B flattens to 1-2), which biases the log-log slope toward 0, so
 * we drop that tail. Prefer l_B where N_B >= 3; relax to >= 2, then all, so a
 * small graph still yields at least a few points.
 */
function fractal_scaling_region(array $covering): array
{
    ksort($covering);
    // l_B = 1 is always N_B = n (a discreteness artifact, not scaling); the far tail
    // saturates at N_B = 1-2. Fit the middle: prefer l_B >= 2 with N_B >= 3, then
    // relax progressively so a small graph still yields at least 3 points.
    $candidates = [
        fn($lB, $n) => $lB >= 2 && $n >= 3,
        fn($lB, $n) => $lB >= 2 && $n >= 2,
        fn($lB, $n) => $n >= 2,
        fn($lB, $n) => true,
    ];
    foreach ($candidates as $keep) {
        $keys = [];
        foreach ($covering as $lB => $c) {
            if ($keep($lB, $c['n'])) {
                $keys[] = $lB;
            }
        }
        if (count($keys) >= 3) {
            return $keys;
        }
    }
    return array_keys($covering);
}

/** Ordinary least-squares line fit. Returns slope, intercept, r2. */
function fractal_linfit(array $xs, array $ys): array
{
    $n = count($xs);
    if ($n < 2) {
        return ['slope' => 0.0, 'intercept' => $ys[0] ?? 0.0, 'r2' => 0.0];
    }
    $sx = array_sum($xs);
    $sy = array_sum($ys);
    $sxx = 0.0;
    $sxy = 0.0;
    $syy = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $sxx += $xs[$i] * $xs[$i];
        $sxy += $xs[$i] * $ys[$i];
        $syy += $ys[$i] * $ys[$i];
    }
    $den = $n * $sxx - $sx * $sx;
    if ($den == 0.0) {
        return ['slope' => 0.0, 'intercept' => $sy / $n, 'r2' => 0.0];
    }
    $slope = ($n * $sxy - $sx * $sy) / $den;
    $intercept = ($sy - $slope * $sx) / $n;
    $ssTot = $syy - $sy * $sy / $n;
    $ssRes = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $e = $ys[$i] - ($slope * $xs[$i] + $intercept);
        $ssRes += $e * $e;
    }
    $r2 = $ssTot > 0 ? 1.0 - $ssRes / $ssTot : 1.0;
    return ['slope' => $slope, 'intercept' => $intercept, 'r2' => $r2];
}

/**
 * Box-counting fractal dimension d_B = -slope of log N_B vs log l_B (= D(0)).
 * $covering: output of fractal_box_covering. Returns d_B, r2, and (l,n) points.
 */
function fractal_box_dimension(array $covering): array
{
    $region = fractal_scaling_region($covering);
    $regionSet = array_flip($region);
    $xs = [];
    $ys = [];
    $points = [];
    ksort($covering);
    foreach ($covering as $lB => $c) {
        // Report every point (so the chart shows saturation) but fit only the region.
        $points[] = ['l' => $lB, 'n' => $c['n'], 'fit' => isset($regionSet[$lB])];
        if (isset($regionSet[$lB])) {
            $xs[] = log((float)$lB);
            $ys[] = log((float)$c['n']);
        }
    }
    $fit = fractal_linfit($xs, $ys);
    return [
        'd_B' => -$fit['slope'],
        'r2' => $fit['r2'],
        'points' => $points,
    ];
}

/**
 * Multifractal spectrum via the Chhabra-Jensen direct method (numerically stabler
 * than a Legendre transform of tau(q)). For each q, alpha(q) and f(q) are slopes
 * vs ln(l_B) of the weighted-measure sums; the generalized dimensions D(q) come
 * from tau(q) = slope of ln(sum mu^q) vs ln(l_B).
 *
 * Returns the f(alpha) curve, the spectrum width (alpha_max - alpha_min), and
 * D0/D1/D2. By construction D0 == d_B.
 */
function fractal_multifractal(array $covering, array $qs): array
{
    $region = array_flip(fractal_scaling_region($covering));
    $ls = [];
    $massSets = [];
    ksort($covering);
    foreach ($covering as $lB => $c) {
        if (!isset($region[$lB])) {
            continue; // same scaling region as the box dimension, for consistency
        }
        $ls[] = log((float)$lB);
        $massSets[] = $c['masses'];
    }

    $alpha = [];
    $falpha = [];
    foreach ($qs as $q) {
        $aNum = [];
        $fNum = [];
        foreach ($massSets as $masses) {
            // normalized measure mu_i(q) = mu_i^q / sum(mu_j^q)
            $Z = 0.0;
            foreach ($masses as $mu) {
                $Z += $mu ** $q;
            }
            $a = 0.0;
            $f = 0.0;
            if ($Z > 0) {
                foreach ($masses as $mu) {
                    $muq = ($mu ** $q) / $Z;
                    if ($muq > 0) {
                        $a += $muq * log($mu);
                        $f += $muq * log($muq);
                    }
                }
            }
            $aNum[] = $a;
            $fNum[] = $f;
        }
        $alpha[] = fractal_linfit($ls, $aNum)['slope'];
        $falpha[] = fractal_linfit($ls, $fNum)['slope'];
    }

    // Generalized dimensions via tau(q) = slope of ln(sum mu^q) vs ln(l).
    $Dq = function (float $q) use ($ls, $massSets): float {
        if (abs($q - 1.0) < 1e-9) {
            // D(1) = slope of sum(mu ln mu) vs ln(l)
            $ys = [];
            foreach ($massSets as $masses) {
                $s = 0.0;
                foreach ($masses as $mu) {
                    if ($mu > 0) {
                        $s += $mu * log($mu);
                    }
                }
                $ys[] = $s;
            }
            return fractal_linfit($ls, $ys)['slope'];
        }
        $ys = [];
        foreach ($massSets as $masses) {
            $Z = 0.0;
            foreach ($masses as $mu) {
                $Z += $mu ** $q;
            }
            $ys[] = log($Z);
        }
        $tau = fractal_linfit($ls, $ys)['slope'];
        return $tau / ($q - 1.0);
    };

    $amin = min($alpha);
    $amax = max($alpha);
    return [
        'width' => $amax - $amin,
        'alpha' => $alpha,
        'falpha' => $falpha,
        'q' => $qs,
        'D0' => $Dq(0.0),
        'D1' => $Dq(1.0),
        'D2' => $Dq(2.0),
    ];
}

/**
 * Degree power-law exponent gamma via the discrete MLE (Clauset et al.):
 * gamma = 1 + n / sum(ln(k_i / (k_min - 0.5))), over nodes with degree >= k_min.
 * k_min = 1. Returns null when there is not enough spread to fit.
 * ponytail: MLE only, no KS goodness-of-fit; add the KS p-value if gamma gets over-trusted.
 */
function fractal_degree_power_law(array $adj): ?float
{
    $kmin = 1;
    $sum = 0.0;
    $count = 0;
    $maxk = 0;
    foreach ($adj as $neighbors) {
        $k = count($neighbors);
        if ($k >= $kmin) {
            $sum += log($k / ($kmin - 0.5));
            $count++;
            if ($k > $maxk) {
                $maxk = $k;
            }
        }
    }
    if ($count < FRACTAL_MIN_COMPONENT || $sum <= 0.0 || $maxk < 2) {
        return null;
    }
    return 1.0 + $count / $sum;
}

/** Basic graph stats. */
function fractal_graph_stats(array $adj, array $components): array
{
    $nodes = count($adj);
    $edges = 0;
    foreach ($adj as $neighbors) {
        $edges += count($neighbors);
    }
    $edges = intdiv($edges, 2);
    $largest = $components ? count($components[0]) : 0;
    // Link density = actual edges / possible edges. Defined for any galaxy with
    // >= 2 wormholes, so it (unlike the fractal dimension) is always shown.
    $possible = $nodes >= 2 ? ($nodes * ($nodes - 1)) / 2 : 0;
    return [
        'node_count' => $nodes,
        'edge_count' => $edges,
        'mean_degree' => $nodes > 0 ? (2.0 * $edges) / $nodes : 0.0,
        'density' => $possible > 0 ? $edges / $possible : 0.0,
        'components' => count($components),
        'largest_component' => $largest,
    ];
}

/**
 * Orchestrator: build the adjacency for one galaxy from the existing keyword-shared
 * graph (db_get_connections), then compute the full fractal profile.
 *
 * Returns a structured array. On a guard trip (too large / too small / too shallow /
 * empty) returns computed=false with a machine reason key plus the basic stats.
 */
function fractal_profile(int $galaxyId, bool $fuzzy): array
{
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT id FROM nodes WHERE constellation_id = :id');
    $stmt->execute([':id' => $galaxyId]);
    $nodeIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $adj = [];
    foreach ($nodeIds as $id) {
        $adj[$id] = [];
    }
    foreach (db_get_connections($galaxyId, $fuzzy) as $e) {
        $a = (int)$e['node1_id'];
        $b = (int)$e['node2_id'];
        if (!isset($adj[$a]) || !isset($adj[$b]) || $a === $b) {
            continue;
        }
        $adj[$a][] = $b;
        $adj[$b][] = $a;
    }

    $components = fractal_components($adj);
    $stats = fractal_graph_stats($adj, $components);

    // For a small-enough galaxy, include the literal network (dots + shared-keyword
    // links) so the shape can be drawn even when no fractal dimension can be fit.
    if ($stats['node_count'] >= 1 && $stats['node_count'] <= FRACTAL_NETWORK_MAX) {
        $ids = array_keys($adj);
        $index = array_flip($ids);
        $edges = [];
        foreach ($adj as $u => $neighbors) {
            foreach ($neighbors as $v) {
                if ($index[$u] < $index[$v]) {
                    $edges[] = [$index[$u], $index[$v]];
                }
            }
        }
        $deg = [];
        foreach ($ids as $u) {
            $deg[] = count($adj[$u]);
        }
        $stats['graph'] = ['n' => count($ids), 'edges' => $edges, 'deg' => $deg];
    }

    // Degree histogram (how many wormholes have how many connections) is defined and
    // cheap at ANY size, so always include it -> the degree chart shows for every
    // galaxy, alongside the network and/or spectrum whenever those are available too.
    if ($stats['node_count'] >= 1) {
        $hist = [];
        foreach ($adj as $neighbors) {
            $k = count($neighbors);
            $hist[$k] = ($hist[$k] ?? 0) + 1;
        }
        ksort($hist);
        $stats['degree_hist'] = $hist; // { degree: count }
    }

    if ($stats['node_count'] === 0) {
        return array_merge($stats, ['computed' => false, 'reason' => 'empty']);
    }
    if ($stats['node_count'] > FRACTAL_MAX_NODES) {
        return array_merge($stats, ['computed' => false, 'reason' => 'too_large']);
    }

    $largest = $components[0];
    if (count($largest) < FRACTAL_MIN_COMPONENT) {
        return array_merge($stats, ['computed' => false, 'reason' => 'too_small']);
    }

    $ap = fractal_all_pairs($adj, $largest);
    if ($ap['diameter'] < FRACTAL_MIN_DIAMETER) {
        return array_merge($stats, [
            'computed' => false,
            'reason' => 'too_shallow',
            'diameter' => $ap['diameter'],
            'gamma' => fractal_degree_power_law($adj),
        ]);
    }

    $covering = fractal_box_covering($largest, $ap['dist'], $ap['diameter']);
    $box = fractal_box_dimension($covering);
    $qs = [];
    for ($q = -5.0; $q <= 5.0 + 1e-9; $q += 0.5) {
        $qs[] = round($q, 1);
    }
    $mf = fractal_multifractal($covering, $qs);

    return array_merge($stats, [
        'computed' => true,
        'diameter' => $ap['diameter'],
        'd_B' => $box['d_B'],
        'd_B_r2' => $box['r2'],
        'box_points' => $box['points'],
        'mf' => $mf,
        'gamma' => fractal_degree_power_law($adj),
    ]);
}
