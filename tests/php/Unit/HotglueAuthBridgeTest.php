<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../hg/telaris-auth.inc.php';

/**
 * Unit tests for the vendored-hotglue Telaris auth bridge (hg/telaris-auth.inc.php).
 *
 * The bridge replaces hotglue's single-password auth with Telaris' per-galaxy
 * seat rule. These cover the PURE, security-critical pieces that decide WHICH
 * node a write authorizes against:
 *   - node-<id> token parsing,
 *   - telaris_hg_node_ids_from_args(): the resolver that turns a json write's
 *     arguments into the set of node ids the request touches. Every id it
 *     returns is enforced; a mutating request that resolves to NO node id is
 *     denied (fail-closed). A miss here is a privilege-escalation bug, so the
 *     fail-closed and "every touched node" behaviours are asserted directly.
 *   - the CSRF check.
 *
 * The enforce/authorize wrappers (which call into Telaris auth + exit) are
 * covered by the live security sweep, not here.
 */
final class HotglueAuthBridgeTest extends TestCase
{
    // --- node-<id> token parsing -------------------------------------------

    public function testNodeIdParsesOnlyExactNodeTokens(): void
    {
        $this->assertSame(53, telaris_hg_node_id('node-53'));
        $this->assertSame(0, telaris_hg_node_id('node-0'));
        $this->assertNull(telaris_hg_node_id('node-'));
        $this->assertNull(telaris_hg_node_id('node-53x'));
        $this->assertNull(telaris_hg_node_id('xnode-53'));
        $this->assertNull(telaris_hg_node_id('start'));
        $this->assertNull(telaris_hg_node_id('node-53.head'), 'a token still carrying a dot is not a bare node id');
        $this->assertNull(telaris_hg_node_id(''));
    }

    public function testFirstTokenStripsRevisionAndObjectSuffixes(): void
    {
        $this->assertSame('node-53', telaris_hg_first_token('node-53'));
        $this->assertSame('node-53', telaris_hg_first_token('node-53.head'));
        $this->assertSame('node-53', telaris_hg_first_token('node-53.head.178102390659'));
        $this->assertSame('start', telaris_hg_first_token('start.auto-20260101'));
    }

    // --- the write-target resolver (telaris_hg_node_ids_from_args) ----------

    public function testResolverReadsScalarTargetKeys(): void
    {
        $this->assertSame([53], telaris_hg_node_ids_from_args(['page' => 'node-53.head']));
        $this->assertSame([53], telaris_hg_node_ids_from_args(['name' => 'node-53.head.178102390659']));
        $this->assertSame([7], telaris_hg_node_ids_from_args(['obj' => 'node-7.head.1']));
        $this->assertSame([9], telaris_hg_node_ids_from_args(['pagename' => 'node-9']));
    }

    public function testResolverReadsBothEndsOfRenameAndCopy(): void
    {
        // rename/copy services name a source and a destination; BOTH must be
        // authorized so an editor cannot copy out of / into a galaxy they lack.
        $ids = telaris_hg_node_ids_from_args(['old' => 'node-3.head', 'new' => 'node-4.head']);
        sort($ids);
        $this->assertSame([3, 4], $ids);

        $ids2 = telaris_hg_node_ids_from_args(['from' => 'node-5.head.1', 'to' => 'node-6.head.1']);
        sort($ids2);
        $this->assertSame([5, 6], $ids2);
    }

    public function testResolverReadsNamesArray(): void
    {
        $ids = telaris_hg_node_ids_from_args(['names' => ['node-1.head.1', 'node-2.head.2', 'node-1.head.3']]);
        sort($ids);
        $this->assertSame([1, 2], $ids, 'distinct node ids only');
    }

    public function testResolverReadsSaveStateHtmlRootId(): void
    {
        // save_state sends the object as serialized html whose root id IS the
        // object name; the resolver must authorize against it (this was a real
        // bug: save_state carries no page/name, so without this it hit 403.030).
        $this->assertSame([42], telaris_hg_node_ids_from_args(['html' => '<div id="node-42.head.178102390659" class="text">hi</div>']));
        $this->assertSame([42], telaris_hg_node_ids_from_args(['html' => "<div id='node-42.head.1'>hi</div>"]));
        $this->assertSame([42], telaris_hg_node_ids_from_args(['html' => '<div id=node-42.head.1>hi</div>']));
    }

    public function testResolverUnionsKeysAndHtml(): void
    {
        $ids = telaris_hg_node_ids_from_args([
            'page' => 'node-5.head',
            'html' => '<div id="node-6.head.1"></div>',
        ]);
        sort($ids);
        $this->assertSame([5, 6], $ids);
    }

    public function testResolverFailsClosedOnNonNodeTargets(): void
    {
        // Any of these resolving to a node id would let an editor write to a
        // page outside the node-<id> namespace. They must resolve to NOTHING,
        // so the json authorizer denies the write with 403.030.
        $this->assertSame([], telaris_hg_node_ids_from_args([]));
        $this->assertSame([], telaris_hg_node_ids_from_args(['page' => 'start']));
        $this->assertSame([], telaris_hg_node_ids_from_args(['name' => 'admin']));
        $this->assertSame([], telaris_hg_node_ids_from_args(['page' => '../../etc/passwd']));
        $this->assertSame([], telaris_hg_node_ids_from_args(['html' => '<div id="evil-1.head.1"></div>']));
        $this->assertSame([], telaris_hg_node_ids_from_args(['page' => 123]), 'non-string values are ignored');
    }

    // --- CSRF check --------------------------------------------------------

    public function testCsrfAcceptsMatchingHeaderOrField(): void
    {
        $token = 'tok_' . bin2hex(random_bytes(8));
        $savedSession = $_SESSION ?? [];
        $savedHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        $savedField = $_POST['csrf_token'] ?? null;
        try {
            $_SESSION['csrf_token'] = $token;

            // matching header
            $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
            unset($_POST['csrf_token']);
            $this->assertTrue(telaris_hg_csrf_ok());

            // matching field (no header)
            unset($_SERVER['HTTP_X_CSRF_TOKEN']);
            $_POST['csrf_token'] = $token;
            $this->assertTrue(telaris_hg_csrf_ok());

            // mismatch
            $_SERVER['HTTP_X_CSRF_TOKEN'] = 'wrong';
            unset($_POST['csrf_token']);
            $this->assertFalse(telaris_hg_csrf_ok());

            // nothing submitted
            unset($_SERVER['HTTP_X_CSRF_TOKEN']);
            $this->assertFalse(telaris_hg_csrf_ok());

            // empty session token never matches even an empty submission
            $_SESSION['csrf_token'] = '';
            $_SERVER['HTTP_X_CSRF_TOKEN'] = '';
            $this->assertFalse(telaris_hg_csrf_ok());
        } finally {
            $_SESSION = $savedSession;
            if ($savedHeader === null) { unset($_SERVER['HTTP_X_CSRF_TOKEN']); } else { $_SERVER['HTTP_X_CSRF_TOKEN'] = $savedHeader; }
            if ($savedField === null) { unset($_POST['csrf_token']); } else { $_POST['csrf_token'] = $savedField; }
        }
    }
}
