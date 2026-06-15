<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../inc/keyword-fuzzy.php';

/**
 * Unit tests for the database-free fuzzy keyword matching pipeline
 * (keyword_fuzzy_tokenize, keyword_fuzzy_is_fuzzable,
 * keyword_fuzzy_tokens_equivalent, keyword_fuzzy_build_groups).
 *
 * Connectivity = two nodes' cluster-key sets intersect (the rule
 * db_get_connections / the 3D view uses to draw a relationship line).
 */
final class KeywordFuzzyTest extends TestCase
{
    /** @param array<int, list<string>> $nodeKeywords */
    private function connected(array $nodeKeywords, int $a, int $b): bool
    {
        $res = keyword_fuzzy_build_groups($nodeKeywords);
        $ga = $res['groups'][$a] ?? [];
        $gb = $res['groups'][$b] ?? [];
        return count(array_intersect($ga, $gb)) > 0;
    }

    // --- Tokenization & normalization ---

    public function testTokenizeSplitsAllDelimiters(): void
    {
        $this->assertSame(['gender', 'colonialism'], keyword_fuzzy_tokenize('gender-colonialism'));
        $this->assertSame(['settler', 'colonialism'], keyword_fuzzy_tokenize('settler_colonialism'));
        $this->assertSame(['power', 'gender'], keyword_fuzzy_tokenize('power/gender'));
        $this->assertSame(['queer', 'theory'], keyword_fuzzy_tokenize('queer theory'));
    }

    public function testTokenizeDropsStopwords(): void
    {
        // "of" is a stopword; "coloniality" and "gender" survive.
        $this->assertSame(['coloniality', 'gender'], keyword_fuzzy_tokenize('coloniality-of-gender'));
        $this->assertSame(['theory', 'gender'], keyword_fuzzy_tokenize('theory-of-gender'));
        // A keyword that is only a stopword yields no tokens.
        $this->assertSame([], keyword_fuzzy_tokenize('of'));
    }

    public function testNormalizeStripsDiacriticsAndPunctuation(): void
    {
        $this->assertSame('colonizacion', keyword_fuzzy_normalize_token('Colonización'));
        $this->assertSame('womens', keyword_fuzzy_normalize_token("women's"));
        $this->assertSame('decolonial', keyword_fuzzy_normalize_token('  Décolonial!  '));
    }

    public function testIsFuzzable(): void
    {
        $this->assertTrue(keyword_fuzzy_is_fuzzable('colonialism'));
        $this->assertTrue(keyword_fuzzy_is_fuzzable('race')); // exactly 4
        $this->assertFalse(keyword_fuzzy_is_fuzzable('gay'));  // 3 chars
        $this->assertFalse(keyword_fuzzy_is_fuzzable('war'));
        $this->assertFalse(keyword_fuzzy_is_fuzzable('123'));  // numeric
        $this->assertFalse(keyword_fuzzy_is_fuzzable('2024')); // numeric, even if long
    }

    public function testTokensEquivalentRules(): void
    {
        // Morphology via shared prefix >= 5.
        $this->assertTrue(keyword_fuzzy_tokens_equivalent('colonial', 'colonialism'));
        $this->assertTrue(keyword_fuzzy_tokens_equivalent('colonialism', 'coloniality'));
        // Typo via Levenshtein (1 edit on a longer word; deletion mid-word).
        $this->assertTrue(keyword_fuzzy_tokens_equivalent('colonialism', 'clonialism'));
        $this->assertTrue(keyword_fuzzy_tokens_equivalent('gender', 'gencer')); // typo, 1 edit
        // Not equivalent: short shared prefix and too many edits.
        $this->assertFalse(keyword_fuzzy_tokens_equivalent('colour', 'colon'));
        $this->assertFalse(keyword_fuzzy_tokens_equivalent('theory', 'history'));
        // NOTE (accepted collateral of typo tolerance under Balanced): two unrelated
        // 6-char words that differ by a single edit, e.g. "gender" vs "render", DO link.
        // Edit distance cannot distinguish a typo from a real near-spelling; the operator
        // chose Balanced over Conservative knowing this. Documented in the vault note.
    }

    // --- Compound token sharing ---

    public function testCompoundKeywordsShareColonialism(): void
    {
        $nodes = [
            1 => ['settler-colonialism'],
            2 => ['gender-colonialism'],
            3 => ['capitalist-colonialism'],
            4 => ['colonialism'],
        ];
        $this->assertTrue($this->connected($nodes, 1, 4));
        $this->assertTrue($this->connected($nodes, 2, 4));
        $this->assertTrue($this->connected($nodes, 3, 4));
        $this->assertTrue($this->connected($nodes, 1, 2));
    }

    public function testCompoundKeywordsShareGender(): void
    {
        $nodes = [
            1 => ['gender-colonialism'],
            2 => ['gender'],
            3 => ['gender-issues'],
            4 => ['theory-of-gender'],
        ];
        $this->assertTrue($this->connected($nodes, 1, 2));
        $this->assertTrue($this->connected($nodes, 1, 3));
        $this->assertTrue($this->connected($nodes, 1, 4));
    }

    // --- Morphology ---

    public function testMorphologicalFamilyGroups(): void
    {
        $nodes = [
            1 => ['colonial'],
            2 => ['colonialism'],
            3 => ['colonials'],
            4 => ['coloniality'],
            5 => ['coloniality-of-gender'],
        ];
        $this->assertTrue($this->connected($nodes, 1, 2));
        $this->assertTrue($this->connected($nodes, 1, 4));
        $this->assertTrue($this->connected($nodes, 2, 4));
        $this->assertTrue($this->connected($nodes, 1, 5));
    }

    // --- Typos ---

    public function testTypoConnects(): void
    {
        $nodes = [
            1 => ['colonialism'],
            2 => ['clonialism'],
        ];
        $this->assertTrue($this->connected($nodes, 1, 2));
    }

    // --- Short / numeric tokens are exact-only ---

    public function testShortTokensExactOnly(): void
    {
        // Exact short word still connects (add-only invariant).
        $this->assertTrue($this->connected([1 => ['gay'], 2 => ['gay']], 1, 2));
        // But a 1-edit short word does NOT fuzz.
        $this->assertFalse($this->connected([1 => ['gay'], 2 => ['gap']], 1, 2));
        $this->assertFalse($this->connected([1 => ['war'], 2 => ['wax']], 1, 2));
    }

    public function testNumericTokensExactOnly(): void
    {
        $this->assertTrue($this->connected([1 => ['1'], 2 => ['1']], 1, 2));
        $this->assertFalse($this->connected([1 => ['1'], 2 => ['2']], 1, 2));
    }

    // --- No spurious links ---

    public function testUnrelatedWordsDoNotConnect(): void
    {
        $this->assertFalse($this->connected([1 => ['colour'], 2 => ['colon']], 1, 2));
        $this->assertFalse($this->connected([1 => ['gender'], 2 => ['nature']], 1, 2));
    }

    public function testStopwordOnlyKeywordConnectsToNothing(): void
    {
        $res = keyword_fuzzy_build_groups([1 => ['of'], 2 => ['the'], 3 => ['colonialism']]);
        $this->assertSame([], $res['groups'][1]);
        $this->assertSame([], $res['groups'][2]);
        $this->assertNotSame([], $res['groups'][3]);
    }

    // --- Add-only invariant: every exact link survives under fuzzy ---

    public function testExactMatchAlwaysSurvives(): void
    {
        // Identical multi-token keyword in two nodes must connect.
        $this->assertTrue($this->connected([1 => ['settler-colonialism'], 2 => ['settler-colonialism']], 1, 2));
        // Identical short keyword.
        $this->assertTrue($this->connected([1 => ['sex'], 2 => ['sex']], 1, 2));
    }

    // --- Multilingual ---

    public function testCrossLanguageMorphologyByPrefix(): void
    {
        // Spanish/Portuguese/French colonial-family variants share the "colonial" prefix.
        $this->assertTrue($this->connected([1 => ['colonial'], 2 => ['colonialismo']], 1, 2));   // ES
        $this->assertTrue($this->connected([1 => ['colonial'], 2 => ['colonialisme']], 1, 2));   // FR
        $this->assertTrue($this->connected([1 => ['Colonización'], 2 => ['colonizacao']], 1, 2)); // ES <-> PT, diacritics
    }
}
