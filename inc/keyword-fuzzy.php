<?php
declare(strict_types=1);

/**
 * Fuzzy keyword matching pipeline (pure, database-free).
 *
 * Groups editor keywords that name the same concept so that multi-galaxy 3D
 * views draw relationship lines between wormholes whose keywords vary in form.
 * Handles three general cases:
 *   1. Shared tokens in compounds: "settler-colonialism" links to "colonialism".
 *   2. Morphological families: "colonial" / "colonialism" / "coloniality".
 *   3. Typos: "clonialism" links to "colonialism".
 *
 * The single entry point is keyword_fuzzy_build_groups(): given a per-node map
 * of raw keyword strings, it returns, for each node, the set of cluster keys its
 * keywords belong to. Two nodes connect when their cluster-key sets intersect.
 *
 * Aggressiveness preset: Balanced (see the constants below; all tunable).
 *
 * Invariant: enabling fuzzy matching only ever ADDS connections, never removes
 * one that exact matching already produced. Short and purely-numeric tokens are
 * kept as exact-only clusters (never prefix/typo-merged) precisely so that every
 * exact link survives.
 *
 * Canonical design: vault Projects/Telaris/Features/Fuzzy keyword matching.md.
 */

// --- Balanced preset constants (tunable without touching logic) ---

/** Tokens shorter than this never participate in prefix/typo merging (exact-only). */
const KEYWORD_FUZZY_MIN_TOKEN_LEN = 4;

/** Two fuzzable tokens sharing a common prefix at least this long are merged (morphology). */
const KEYWORD_FUZZY_PREFIX_MIN = 5;

/** Levenshtein threshold for short fuzzable tokens (length <= this many chars). */
const KEYWORD_FUZZY_SHORT_LEN = 6;
/** Allowed edits for tokens up to KEYWORD_FUZZY_SHORT_LEN characters. */
const KEYWORD_FUZZY_DIST_SHORT = 1;
/** Allowed edits for tokens longer than KEYWORD_FUZZY_SHORT_LEN characters. */
const KEYWORD_FUZZY_DIST_LONG = 2;

// --- Typo guard (corpus frequency) ---
// The edit-distance arm distinguishes a typo from a real word by how editors
// actually use it: a typo is a rare near-spelling of an established keyword. So a
// near-spelling pair only merges when one token is rare (appears on at most
// KEYWORD_FUZZY_TYPO_RARE_MAX_DF wormholes) AND the other is clearly more common
// (at least KEYWORD_FUZZY_TYPO_DOMINANCE times as frequent). Two established words
// that happen to differ by one edit (gender / render) never merge. The prefix arm
// (morphology) is unaffected by this guard.

/** A typo candidate appears on at most this many wormholes (document frequency). */
const KEYWORD_FUZZY_TYPO_RARE_MAX_DF = 1;
/** The established neighbour must be at least this many times as frequent as the rare token. */
const KEYWORD_FUZZY_TYPO_DOMINANCE = 3;

/**
 * Connective stopwords across EN/ES/PT/FR. Dropped entirely so they never become
 * hubs. Kept deliberately small: only function words, never content words.
 *
 * @return array<string, true>
 */
function keyword_fuzzy_stopwords(): array {
    static $set = null;
    if ($set !== null) {
        return $set;
    }
    $words = [
        // English
        'the', 'and', 'of', 'to', 'in', 'on', 'for', 'with', 'a', 'an', 'as', 'at', 'by', 'or',
        // Spanish
        'el', 'la', 'los', 'las', 'un', 'una', 'de', 'del', 'y', 'en', 'con', 'por', 'para', 'al',
        // Portuguese
        'o', 'os', 'as', 'um', 'uma', 'da', 'do', 'das', 'dos', 'e', 'em', 'com', 'por', 'para', 'no', 'na',
        // French
        'le', 'les', 'un', 'une', 'des', 'du', 'de', 'et', 'en', 'dans', 'avec', 'pour', 'au', 'aux', 'la',
    ];
    $set = [];
    foreach ($words as $w) {
        $set[$w] = true;
    }
    return $set;
}

/**
 * Strip common Latin diacritics (ES/PT/FR coverage) from an already-lowercased string.
 * Non-Latin scripts pass through untouched.
 */
function keyword_fuzzy_strip_diacritics(string $s): string {
    static $map = null;
    if ($map === null) {
        $map = [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ō' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u',
            'ñ' => 'n', 'ç' => 'c', 'ý' => 'y', 'ÿ' => 'y',
            'œ' => 'oe', 'æ' => 'ae',
        ];
    }
    return strtr($s, $map);
}

/**
 * Normalize a single raw token: lowercase, strip diacritics, drop punctuation/symbols
 * (keeping unicode letters and numbers of any script). Returns '' if nothing survives.
 */
function keyword_fuzzy_normalize_token(string $raw): string {
    $t = mb_strtolower(trim($raw), 'UTF-8');
    if ($t === '') {
        return '';
    }
    $t = keyword_fuzzy_strip_diacritics($t);
    // Remove anything that is not a letter or number (any script).
    $t = preg_replace('/[^\p{L}\p{N}]+/u', '', $t);
    return $t === null ? '' : $t;
}

/**
 * Tokenize one keyword into normalized tokens, dropping stopwords.
 * Splits on hyphens, whitespace, underscores, slashes, and en/em dashes.
 * Short and numeric tokens are RETAINED here (they become exact-only clusters later).
 *
 * @return list<string>
 */
function keyword_fuzzy_tokenize(string $keyword): array {
    $parts = preg_split('/[\s\-_\/\x{2010}-\x{2015}]+/u', $keyword);
    if ($parts === false) {
        $parts = [$keyword];
    }
    $stop = keyword_fuzzy_stopwords();
    $out = [];
    foreach ($parts as $p) {
        $tok = keyword_fuzzy_normalize_token($p);
        if ($tok === '' || isset($stop[$tok])) {
            continue;
        }
        $out[] = $tok;
    }
    return $out;
}

/**
 * Whether a normalized token may participate in prefix/typo merging.
 * Short tokens and purely-numeric tokens are exact-only (never fuzzed).
 */
function keyword_fuzzy_is_fuzzable(string $token): bool {
    if (mb_strlen($token, 'UTF-8') < KEYWORD_FUZZY_MIN_TOKEN_LEN) {
        return false;
    }
    if (preg_match('/^\p{N}+$/u', $token) === 1) {
        return false;
    }
    return true;
}

/** Length of the common prefix of two strings, counted in characters. */
function keyword_fuzzy_common_prefix_len(string $a, string $b): int {
    $aChars = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $bChars = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $n = min(count($aChars), count($bChars));
    $i = 0;
    while ($i < $n && $aChars[$i] === $bChars[$i]) {
        $i++;
    }
    return $i;
}

/**
 * Whether two fuzzable tokens are related by FORM: identical, or sharing a common
 * prefix >= KEYWORD_FUZZY_PREFIX_MIN (morphology). This arm is unconditional; it does
 * not depend on corpus frequency. Callers ensure both tokens are fuzzable.
 */
function keyword_fuzzy_tokens_related_by_form(string $a, string $b): bool {
    if ($a === $b) {
        return true;
    }
    return keyword_fuzzy_common_prefix_len($a, $b) >= KEYWORD_FUZZY_PREFIX_MIN;
}

/**
 * Whether two distinct fuzzable tokens are within the length-scaled Levenshtein
 * threshold (a possible typo). Frequency is NOT considered here; the corpus-frequency
 * guard is applied separately (keyword_fuzzy_typo_freq_allows).
 */
function keyword_fuzzy_tokens_within_typo_distance(string $a, string $b): bool {
    if ($a === $b) {
        return false;
    }
    $maxLen = max(mb_strlen($a, 'UTF-8'), mb_strlen($b, 'UTF-8'));
    $threshold = ($maxLen <= KEYWORD_FUZZY_SHORT_LEN) ? KEYWORD_FUZZY_DIST_SHORT : KEYWORD_FUZZY_DIST_LONG;
    // levenshtein() is byte-based; tokens are ASCII after diacritic stripping in the
    // common case. Short-circuit on length gap to avoid needless work.
    if (abs(strlen($a) - strlen($b)) > $threshold) {
        return false;
    }
    return levenshtein($a, $b) <= $threshold;
}

/**
 * Corpus-frequency guard for the typo arm: a near-spelling pair only merges when one
 * token is rare and the other is clearly more common (the rare one is the likely slip).
 * Two established words never merge by edit distance. $dfA/$dfB are document
 * frequencies (distinct wormholes carrying the token).
 */
function keyword_fuzzy_typo_freq_allows(int $dfA, int $dfB): bool {
    $lo = min($dfA, $dfB);
    $hi = max($dfA, $dfB);
    return $hi > $lo
        && $lo <= KEYWORD_FUZZY_TYPO_RARE_MAX_DF
        && $hi >= $lo * KEYWORD_FUZZY_TYPO_DOMINANCE;
}

/**
 * Whether two fuzzable tokens are equivalent IGNORING corpus frequency: related by
 * form, or within typo distance. Retained for callers/tests that reason about form
 * alone; the clustering applies the frequency guard to the typo arm separately.
 */
function keyword_fuzzy_tokens_equivalent(string $a, string $b): bool {
    return keyword_fuzzy_tokens_related_by_form($a, $b)
        || keyword_fuzzy_tokens_within_typo_distance($a, $b);
}

/** Disjoint-set find with path compression. */
function keyword_fuzzy_dsu_find(array &$parent, string $x): string {
    $root = $x;
    while ($parent[$root] !== $root) {
        $root = $parent[$root];
    }
    // Path compression.
    while ($parent[$x] !== $root) {
        $next = $parent[$x];
        $parent[$x] = $root;
        $x = $next;
    }
    return $root;
}

/**
 * Cluster a set of distinct normalized tokens. Fuzzable tokens are merged when
 * equivalent; short/numeric tokens stay singleton (exact-only). Blocking by first
 * character bounds the pairwise work.
 *
 * @param list<string> $tokens distinct normalized tokens
 * @param array<string, int> $df document frequency per token (distinct wormholes
 *                               carrying it); gates the typo arm. Missing => 1.
 * @return array<string, string> token => cluster representative (the shortest, then
 *                                lexicographically first, token in the cluster)
 */
function keyword_fuzzy_cluster_tokens(array $tokens, array $df = []): array {
    $parent = [];
    foreach ($tokens as $t) {
        $parent[$t] = $t;
    }

    // Block fuzzable tokens by first character to keep comparisons local.
    $buckets = [];
    foreach ($tokens as $t) {
        if (!keyword_fuzzy_is_fuzzable($t)) {
            continue;
        }
        $first = mb_substr($t, 0, 1, 'UTF-8');
        $buckets[$first][] = $t;
    }

    foreach ($buckets as $bucket) {
        $count = count($bucket);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $bucket[$i];
                $b = $bucket[$j];
                // Form arm (morphology) is unconditional; typo arm is gated by the
                // corpus-frequency guard so two established words never merge.
                $join = keyword_fuzzy_tokens_related_by_form($a, $b)
                    || (keyword_fuzzy_tokens_within_typo_distance($a, $b)
                        && keyword_fuzzy_typo_freq_allows((int)($df[$a] ?? 1), (int)($df[$b] ?? 1)));
                if ($join) {
                    $ri = keyword_fuzzy_dsu_find($parent, $a);
                    $rj = keyword_fuzzy_dsu_find($parent, $b);
                    if ($ri !== $rj) {
                        $parent[$ri] = $rj;
                    }
                }
            }
        }
    }

    // Collect members per root, then choose a stable representative.
    $members = [];
    foreach ($tokens as $t) {
        $root = keyword_fuzzy_dsu_find($parent, $t);
        $members[$root][] = $t;
    }
    $rep = [];
    foreach ($members as $group) {
        usort($group, function (string $a, string $b): int {
            $la = mb_strlen($a, 'UTF-8');
            $lb = mb_strlen($b, 'UTF-8');
            return $la === $lb ? strcmp($a, $b) : ($la <=> $lb);
        });
        $representative = $group[0];
        foreach ($group as $t) {
            $rep[$t] = $representative;
        }
    }
    return $rep;
}

/**
 * Build fuzzy keyword groups for a set of nodes.
 *
 * @param array<int, list<string>> $nodeKeywords node_id => raw keyword strings
 * @return array{groups: array<int, list<string>>, labels: array<string, string>}
 *         groups: node_id => list of distinct cluster keys (the cluster representative).
 *         labels: cluster key => human-readable representative (same string here).
 */
function keyword_fuzzy_build_groups(array $nodeKeywords): array {
    // 1. Tokenize every keyword once; collect the global distinct token set.
    // Use a list + array_unique(SORT_STRING), NOT token-as-array-key: a purely
    // numeric token ("1") would silently cast to an int array key and break the
    // string typing downstream (the a68d6d4 numeric-keyword regression family).
    $nodeTokens = [];   // node_id => list<token>
    $allTokens = [];
    foreach ($nodeKeywords as $nodeId => $keywords) {
        $toks = [];
        foreach ($keywords as $kw) {
            foreach (keyword_fuzzy_tokenize((string)$kw) as $t) {
                $toks[] = $t;
                $allTokens[] = $t;
            }
        }
        $nodeTokens[(int)$nodeId] = $toks;
    }
    $distinct = array_values(array_unique($allTokens, SORT_STRING));

    // 1b. Document frequency per token (distinct wormholes carrying it), used to tell
    // a typo (rare near-spelling of a common word) from two genuinely distinct words.
    $df = [];
    foreach ($nodeTokens as $toks) {
        foreach (array_unique($toks, SORT_STRING) as $t) {
            $df[$t] = ($df[$t] ?? 0) + 1;
        }
    }

    // 2. Cluster the distinct tokens (typo arm gated by document frequency).
    $rep = keyword_fuzzy_cluster_tokens($distinct, $df);

    // 3. Map each node to its set of cluster keys.
    $groups = [];
    $labels = [];
    foreach ($nodeTokens as $nodeId => $toks) {
        $keys = [];
        foreach ($toks as $t) {
            $key = (string)($rep[$t] ?? $t);
            $keys[] = $key;
            $labels[$key] = $key;
        }
        $groups[$nodeId] = array_values(array_unique($keys, SORT_STRING));
    }

    return ['groups' => $groups, 'labels' => $labels];
}
