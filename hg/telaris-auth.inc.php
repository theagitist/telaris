<?php

declare(strict_types=1);

/*
 *	telaris-auth.inc.php
 *	Telaris session + per-galaxy seat + CSRF gate for the vendored hotglue.
 *
 *	This file is NOT part of upstream hotglue (k0a1a/hotglue2). It bridges
 *	hotglue's request handling into Telaris's own editor authentication and
 *	authorization, so that hotglue's global single-password auth is replaced
 *	by Telaris's rule: an editor may only modify hotglue pages they have access
 *	to. Each wormhole maps to one hotglue page named node-<id>; authorization
 *	is resolved from that node's galaxy (constellation).
 *
 *	The two hotglue front controllers (index.php for GET, json.php for the AJAX
 *	write API) require this file early and call the authorize functions in place
 *	of hotglue's is_auth()/prompt_auth(). See the vault note
 *	"Hotglue integration plan" (phase 3).
 */

if (!defined('TELARIS_HG_BRIDGE')) {
	define('TELARIS_HG_BRIDGE', 1);

	// Telaris repo root is the parent of the vendored hg/ directory. The Telaris
	// includes use absolute __DIR__ paths internally, so hotglue's working
	// directory (used by its own scandir('.') module loader) is unaffected.
	$telarisRoot = dirname(__DIR__);

	// utils/auth.php starts the PHP session with Telaris' cookie params
	// (same name + path '/', so a same-origin /hg/ request resumes the editor's
	// session) and defines the isEditorLoggedIn()/checkEditorConstellationAccess()
	// helpers. config.php carries the DB credentials and pulls in inc/db.php.
	require_once $telarisRoot . '/utils/auth.php';
	require_once $telarisRoot . '/config.php';

	// Mirror edit/index.php: ensure a per-session CSRF token exists so the edit
	// page can hand it to hotglue's AJAX layer.
	if (empty($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}
}


/**
 *	parse a node id out of a hotglue page-name token
 *
 *	per-wormhole pages are named node-<id>; anything else is not an editable
 *	Telaris surface.
 *
 *	@param string $token a single page-name token (no dots)
 *	@return int|null the node id, or null if the token is not node-<id>
 */
function telaris_hg_node_id(string $token): ?int
{
	if (preg_match('/^node-([0-9]+)$/', $token, $m) === 1) {
		return (int)$m[1];
	}
	return null;
}


/**
 *	return the leading page-name token of a hotglue page or object name
 *
 *	hotglue names are page.rev for pages and page.rev.obj for objects; the page
 *	name is always the substring before the first dot.
 *
 *	@param string $nameOrPage
 *	@return string
 */
function telaris_hg_first_token(string $nameOrPage): string
{
	$p = strpos($nameOrPage, '.');
	return ($p === false) ? $nameOrPage : substr($nameOrPage, 0, $p);
}


/**
 *	best-effort: resolve the target node id from a json service's arguments
 *
 *	different services name their target under different keys; we scan the ones
 *	that can carry a page or object name. Returns null when none resolve to a
 *	node-<id> page (in which case a mutating service is denied, fail-closed).
 *
 *	@param array $args decoded json arguments
 *	@return int|null
 */
function telaris_hg_node_id_from_args(array $args): ?int
{
	foreach (array('page', 'name', 'obj', 'to', 'from') as $k) {
		if (!empty($args[$k]) && is_string($args[$k])) {
			$id = telaris_hg_node_id(telaris_hg_first_token($args[$k]));
			if ($id !== null) {
				return $id;
			}
		}
	}
	// some services take an array of object names (e.g. save_state)
	if (!empty($args['names']) && is_array($args['names'])) {
		foreach ($args['names'] as $n) {
			if (is_string($n)) {
				$id = telaris_hg_node_id(telaris_hg_first_token($n));
				if ($id !== null) {
					return $id;
				}
			}
		}
	}
	return null;
}


/**
 *	verify the Telaris CSRF token (X-CSRF-Token header or csrf_token field)
 *
 *	@return bool
 */
function telaris_hg_csrf_ok(): bool
{
	$expected = $_SESSION['csrf_token'] ?? '';
	$header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
	$field = $_POST['csrf_token'] ?? '';
	$submitted = ($header !== '') ? $header : $field;
	return $expected !== '' && $submitted !== '' && hash_equals((string)$expected, (string)$submitted);
}


/**
 *	emit a clean deny for the GET (page/edit) path and stop
 *
 *	the body is a locale-invariant numeric code only (no prose, no source), in
 *	keeping with Telaris' decolonial-identifier convention; an editor with the
 *	right access never reaches this.
 *
 *	@param int $status HTTP status
 *	@param string $code locale-invariant code
 */
function telaris_hg_deny_get(int $status, string $code): void
{
	http_response_code($status);
	header('Content-Type: text/plain; charset=UTF-8');
	header('X-Telaris-Hg-Deny: ' . $code);
	echo $code;
	exit;
}


/**
 *	emit a clean deny for the json (AJAX) path and stop
 *
 *	uses hotglue's own error envelope shape so the editor JS surfaces it; the
 *	payload is a locale-invariant numeric code.
 *
 *	@param int $status HTTP status
 *	@param string $code locale-invariant code
 */
function telaris_hg_deny_json(int $status, string $code): void
{
	http_response_code($status);
	header('Content-Type: application/json; charset=UTF-8');
	header('X-Telaris-Hg-Deny: ' . $code);
	echo json_encode(array('#error' => true, '#data' => $code));
	exit;
}


/**
 *	resolve and enforce write access for a node page; stops on denial
 *
 *	@param int $nodeId
 *	@param callable $deny function(int $status, string $code): void
 */
function telaris_hg_enforce_node_access(int $nodeId, callable $deny): void
{
	$cid = db_get_node_constellation_id($nodeId);
	if ($cid === null) {
		// node does not exist (or carries no galaxy)
		$deny(404, '404.001');
	}
	// editors must hold a seat on the galaxy; admins always pass (the helper
	// returns null for admins and for seat-holders, a string when denied).
	if (checkEditorConstellationAccess($cid) !== null) {
		$deny(403, '403.002');
	}
	// imported / mirrored galaxies are read-only (403.009 parity with the API).
	if (db_constellation_is_readonly($cid)) {
		$deny(403, '403.009');
	}
}


/**
 *	gate the GET page/edit and page/create_page controllers
 *
 *	requires an editor/admin session, a node-<id> page, a seat on the node's
 *	galaxy, and that the galaxy is not read-only. No CSRF check on GET: the
 *	SameSite=Strict session cookie plus hotglue's referer check cover it, and a
 *	navigation/iframe load cannot carry a token header.
 *
 *	@param string $page the requested page (args[0][0])
 */
function telaris_hg_authorize_get(string $page): void
{
	if (!isEditorOrAdminLoggedIn()) {
		// bounce to the Telaris editor login (site-absolute; /hg/ is same-origin)
		header('Location: /utils/login.php?redirect=edit');
		exit;
	}
	$nodeId = telaris_hg_node_id(telaris_hg_first_token($page));
	if ($nodeId === null) {
		// only node-<id> pages are editable in the embed (blocks the page
		// browser, the login controller, arbitrary page names, etc.)
		telaris_hg_deny_get(403, '403.030');
	}
	telaris_hg_enforce_node_access($nodeId, 'telaris_hg_deny_get');
}


/**
 *	gate the json (AJAX) write API
 *
 *	every service requires an editor/admin session and a valid CSRF token.
 *	Mutating (auth-flagged) services additionally require the request to resolve
 *	to a node-<id> page the editor has a seat on and that is not read-only;
 *	mutating services with no resolvable node page are denied (fail-closed).
 *	Non-mutating helper services (render/list reads) need only the session and
 *	token, since node pages are otherwise publicly viewable in show mode.
 *
 *	@param string $method the json method (for logging)
 *	@param bool $isMutating whether the service is auth-flagged (a write)
 *	@param array $args decoded json arguments
 */
function telaris_hg_authorize_json(string $method, bool $isMutating, array $args): void
{
	if (!isEditorOrAdminLoggedIn()) {
		telaris_hg_deny_json(401, '401.001');
	}
	if (!telaris_hg_csrf_ok()) {
		telaris_hg_deny_json(403, '403.003');
	}
	if (!$isMutating) {
		return;
	}
	$nodeId = telaris_hg_node_id_from_args($args);
	if ($nodeId === null) {
		// a write we cannot scope to an authorized node: refuse
		telaris_hg_deny_json(403, '403.030');
	}
	telaris_hg_enforce_node_access($nodeId, 'telaris_hg_deny_json');
}
