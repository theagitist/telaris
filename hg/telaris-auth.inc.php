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
 *	resolve every page-name token a json service's arguments target
 *
 *	Different services name their target under different keys. We scan all the
 *	keys that can carry a page or object name, the names[] array, and the `html`
 *	element used by save_state (whose root id attribute IS the object name, i.e.
 *	<page>.rev.obj, exactly what save_state derives its target from, so this is
 *	the authoritative target and is safe to authorize against). We keep only
 *	tokens that look like an editable Telaris surface (node-<id> per-wormhole
 *	pages or page-<id> standalone pages); every one found must be authorized,
 *	and a mutating service that resolves to no such token is denied (fail-closed).
 *
 *	@param array $args decoded json arguments
 *	@return list<string> distinct page-name tokens referenced by the arguments
 */
function telaris_hg_target_tokens_from_args(array $args): array
{
	$tokens = array();
	// scalar target keys across the mutating services (page/name/obj/to/from
	// for objects and pages, old/new for rename+copy, pagename for uploads)
	foreach (array('page', 'name', 'obj', 'to', 'from', 'old', 'new', 'pagename') as $k) {
		if (!empty($args[$k]) && is_string($args[$k])) {
			$tokens[telaris_hg_first_token($args[$k])] = true;
		}
	}
	// services that take an array of object names
	if (!empty($args['names']) && is_array($args['names'])) {
		foreach ($args['names'] as $n) {
			if (is_string($n)) {
				$tokens[telaris_hg_first_token($n)] = true;
			}
		}
	}
	// save_state sends the object as a serialized html element; its root id
	// attribute is the object name. Match the first id="node-<id>..."/"page-<id>...".
	if (!empty($args['html']) && is_string($args['html'])) {
		if (preg_match('/id=["\']?((?:node|page)-[0-9]+)/', $args['html'], $m) === 1) {
			$tokens[$m[1]] = true;
		}
	}
	$out = array();
	foreach (array_keys($tokens) as $t) {
		if (preg_match('/^(?:node|page)-[0-9]+$/', $t) === 1) {
			$out[] = $t;
		}
	}
	return $out;
}


/**
 *	authorize edit access to a standalone hotglue page (page-<id>)
 *
 *	resolves the slug through the hotglue_pages registry and enforces Telaris'
 *	ownership rule fail-closed: an unknown slug is denied; the owner and any
 *	admin pass; otherwise access is granted only when the page is assigned to a
 *	wormhole whose galaxy the editor holds a seat on and which is not read-only.
 *
 *	@param string $slug a page-<id> slug
 *	@param callable $deny function(int $status, string $code): void
 */
function telaris_hg_authorize_page_slug(string $slug, callable $deny): void
{
	$page = db_hotglue_page_get_by_slug($slug);
	if ($page === null) {
		// not a known editable page (blocks arbitrary / probed page names)
		$deny(403, '403.030');
	}
	if (isAdminLoggedIn()) {
		return;
	}
	$sessionUserId = $_SESSION['admin_user_id'] ?? null;
	if ($sessionUserId !== null && (string)($page['owner_user_id'] ?? '') === (string)$sessionUserId) {
		return;
	}
	// not owner, not admin: reachable only when assigned to a node whose galaxy
	// the editor has a seat on (and which is not read-only).
	if ($page['node_id'] !== null) {
		telaris_hg_enforce_node_access((int)$page['node_id'], $deny);
		return;
	}
	$deny(403, '403.002');
}


/**
 *	authorize a single hotglue page-name token, stopping on denial
 *
 *	node-<id> tokens resolve through the wormhole's galaxy seat; page-<id> tokens
 *	through the hotglue_pages registry. Anything else is not an editable Telaris
 *	surface and is denied.
 *
 *	@param string $token the page-name token (no dots)
 *	@param callable $deny function(int $status, string $code): void
 */
function telaris_hg_authorize_token(string $token, callable $deny): void
{
	$nodeId = telaris_hg_node_id($token);
	if ($nodeId !== null) {
		telaris_hg_enforce_node_access($nodeId, $deny);
		return;
	}
	if (preg_match('/^page-[0-9]+$/', $token) === 1) {
		telaris_hg_authorize_page_slug($token, $deny);
		return;
	}
	$deny(403, '403.030');
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
	// per-user read-only seat: an editor whose seat on this galaxy is read_only
	// may view but not edit (403.010 parity with the API). Admins always pass.
	if (!isAdminLoggedIn()) {
		$uid = $_SESSION['admin_user_id'] ?? null;
		if ($uid !== null && $uid !== '' && !db_user_can_write_constellation((string)$uid, (int)$cid)) {
			$deny(403, '403.010');
		}
	}
}


/**
 *	gate the GET page/edit and page/create_page controllers
 *
 *	requires an editor/admin session and edit access to the requested page: a
 *	node-<id> per-wormhole page (seat on the node's galaxy, not read-only) or a
 *	page-<id> standalone page (owner/admin/assigned-galaxy seat). Anything else
 *	(the page browser, the login controller, arbitrary page names) is denied. No
 *	CSRF check on GET: the SameSite=Strict session cookie plus hotglue's referer
 *	check cover it, and a navigation/iframe load cannot carry a token header.
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
	telaris_hg_authorize_token(telaris_hg_first_token($page), 'telaris_hg_deny_get');
}


/**
 *	gate the json (AJAX) write API
 *
 *	every service requires an editor/admin session and a valid CSRF token.
 *	Mutating (auth-flagged) services additionally require the request to resolve
 *	to an editable page token (node-<id> the editor has a seat on and not
 *	read-only, or a page-<id> the editor owns / is admin / is galaxy-seated for);
 *	mutating services with no resolvable editable token are denied (fail-closed).
 *	Non-mutating helper services (render/list reads) need only the session and
 *	token, since pages are otherwise publicly viewable in show mode.
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
	$tokens = telaris_hg_target_tokens_from_args($args);
	if (count($tokens) === 0) {
		// a write we cannot scope to an authorized page: refuse
		telaris_hg_deny_json(403, '403.030');
	}
	// every page the request touches must be writable by this editor
	foreach ($tokens as $token) {
		telaris_hg_authorize_token($token, 'telaris_hg_deny_json');
	}
}
