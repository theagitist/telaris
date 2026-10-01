<?php

/*
 *	module_page.inc.php
 *	Module for managing pages
 *
 *	Copyright Gottfried Haider, Danja Vasiliev 2010.
 *	This source code is licensed under the GNU General Public License.
 *	See the file COPYING for more details.
 *
 *	Modified 2026-06-25 by the theagitist/hotglue2 fork: accept WebP page
 *	background uploads; and wrap user-facing response() messages in t()
 *	(module_i18n) for UI localization (English catalog values byte-identical).
 */

@require_once('config.inc.php');
require_once('common.inc.php');
require_once('html.inc.php');
require_once('util.inc.php');


// module_image.inc.php has more information on what's going on inside modules 
// (they can be easier than that one though)


/**
 *	clear the page's current background image
 *
 *	@param array $args arguments
 *		key 'page' is the page (i.e. page.rev)
 *	@return array response
 */
function page_clear_background_img($args)
{
	if (!isset($args['page'])) {
		return response(t('errors.arg_page_missing'), 400);
	}
	if (!page_exists($args['page'])) {
		return response(t('page.not_exist', quot($args['page'])), 400);
	}
	
	load_modules('glue');
	$obj = load_object(array('name'=>$args['page'].'.page'));
	if ($obj['#error']) {
		// page object does not exist, hence no background image to clear
		return response(true);
	} else {
		$obj = $obj['#data'];
	}
	
	if (!empty($obj['page-background-file'])) {
		// delete file
		delete_upload(array('pagename'=>get_first_item(expl('.', $args['page'])), 'file'=>$obj['page-background-file'], 'max_cnt'=>1));
		// and remove attributes
		return object_remove_attr(array('name'=>$obj['name'], 'attr'=>array('page-background-file', 'page-background-mime')));
	} else {
		return response(true);
	}
}

register_service('page.clear_background_img', 'page_clear_background_img', array('auth'=>true));


function page_delete_page($args)
{
	$page = $args['page'];
	
	// check if there is a page object
	$obj = load_object(array('name'=>$page.'.page'));
	if ($obj['#error']) {
		return false;
	} else {
		$obj = $obj['#data'];
	}
	// check if there is a background-image
	if (!empty($obj['page-background-file'])) {
		// delete it
		delete_upload(array('pagename'=>get_first_item(expl('.', $page)), 'file'=>$obj['page-background-file'], 'max_cnt'=>1));
		return true;
	} else {
		return false;
	}
}


function page_has_reference($args)
{
	$obj = $args['obj'];
	$tmp = expl('.', $obj['name']);
	if (array_pop($tmp) != 'page') {
		return false;
	}
	
	if (!empty($obj['page-background-file']) && $obj['page-background-file'] == $args['file']) {
		return true;
	} else {
		return false;
	}
}


/**
 *	get the current grid size
 *
 *	@param array $args arguments
 *	@return array response
 *		'x', 'y' the grid size
 */
function page_get_grid($args)
{
	if (($s = @file_get_contents(CONTENT_DIR.'/grid')) !== false) {
		$a = expl(' ', $s);
		return response(array('x'=>intval($a[0]), 'y'=>intval($a[1])));
	} else {
		return response(array('x'=>PAGE_DEFAULT_GRID_X, 'y'=>PAGE_DEFAULT_GRID_Y));
	}
}

register_service('page.get_grid', 'page_get_grid');


function page_render_object($args)
{
	$obj = $args['obj'];
	$a = expl('.', $obj['name']);
	if ($a[2] != 'page') {
		return false;
	}
	
	// background-attachment
	if (!empty($obj['page-background-attachment'])) {
		html_css('background-attachment', $obj['page-background-attachment']);
	}
	// background-color
	if (!empty($obj['page-background-color'])) {
		html_css('background-color', $obj['page-background-color']);
	}
	// background-image
	if (!empty($obj['page-background-file'])) {
		if (SHORT_URLS) {
			html_css('background-image', 'url('.base_url().htmlspecialchars(urlencode($obj['name']), ENT_NOQUOTES, 'UTF-8').')');
		} else {
			html_css('background-image', 'url('.base_url().'?'.htmlspecialchars(urlencode($obj['name']), ENT_NOQUOTES, 'UTF-8').')');
		}
	}
	// background-image-position
	if (!empty($obj['page-background-image-position'])) {
		html_css('background-position', $obj['page-background-image-position']);
	}
	// set the html title
	if (isset($obj['page-title'])) {
		html_title($obj['page-title']);
	}
}


function page_render_page_early($args)
{
	if ($args['edit']) {
		if (USE_MIN_FILES) {
			html_add_js(base_url().'modules/page/page-edit.min.js');
		} else {
			html_add_js(base_url().'modules/page/page-edit.js');
		}
		html_add_css(base_url().'modules/page/page-edit.css');
		
		// set default grid
		$grid = page_get_grid(array());
		$grid = $grid['#data'];
		html_add_js_var('$.glue.conf.page.default_grid_x', $grid['x']);
		html_add_js_var('$.glue.conf.page.default_grid_y', $grid['y']);
				
		// set guides
		$guide = expl(' ', PAGE_GUIDES_X);
		for ($i=0; $i < count($guide); $i++) {
			$guide[$i] = intval(trim($guide[$i]));
		}
		html_add_js_var('$.glue.conf.page.guides_x', $guide);
		$guide = expl(' ', PAGE_GUIDES_Y);
		for ($i=0; $i < count($guide); $i++) {
			$guide[$i] = intval(trim($guide[$i]));
		}
		html_add_js_var('$.glue.conf.page.guides_y', $guide);
	}

	// set the html title to the page name by default
	html_title(page_short($args['page']));
}


function page_serve_resource($args)
{
	$obj = $args['obj'];
	$tmp = expl('.', $obj['name']);
	if (array_pop($tmp) != 'page') {
		return false;
	}
	$pn = get_first_item($tmp);
	
	if (!empty($obj['page-background-file'])) {
		$fn = CONTENT_DIR.'/'.$pn.'/shared/'.$obj['page-background-file'];
		if (isset($obj['page-background-mime'])) {
			$mime = $obj['page-background-mime'];
		} else {
			$mime = '';
		}
		serve_file($fn, false, $mime);
	}
	
	// if everything fails
	return false;
}


/**
 *	get the current grid size
 *
 *	@param array $args arguments
 *		key 'x', 'y' is the grid size
 *	@return array response
 *		true if successful
 */
function page_set_grid($args)
{
	if (($x = @intval($args['x'])) == 0) {
		return response(t('page.arg_x_invalid'), 400);
	}
	if (($y = @intval($args['y'])) == 0) {
		return response(t('page.arg_y_invalid'), 400);
	}
	
	$m = umask(0111);
	if (!@file_put_contents(CONTENT_DIR.'/grid', $x.' '.$y)) {
		umask($m);
		return response(t('page.grid_save_error'), 500);
	} else {
		umask($m);
		return response(true);
	}
}

register_service('page.set_grid', 'page_set_grid', array('auth'=>true));


function page_upload($args)
{
	// only handle the file if the frontend wants us to
	if (empty($args['preferred_module']) || $args['preferred_module'] != 'page') {
		return false;
	}
	// check if supported file
	if (!in_array($args['mime'], array('image/jpeg', 'image/png', 'image/gif', 'image/webp')) || ($args['mime'] == '' && !in_array(filext($args['file']), array('jpg', 'jpeg', 'png', 'gif', 'webp')))) {
		return false;
	}

	// check if there is already a background-image and delete it
	$obj = load_object(array('name'=>$args['page'].'.page'));
	if (!$obj['#error']) {
		$obj = $obj['#data'];
		if (!empty($obj['page-background-file'])) {
			delete_upload(array('pagename'=>get_first_item(expl('.', $args['page'])), 'file'=>$obj['page-background-file'], 'max_cnt'=>1));
		}
	}
	
	// set as background-image in page object
	$obj = array();
	$obj['name'] = $args['page'].'.page';
	$obj['page-background-file'] = $args['file'];
	$obj['page-background-mime'] = $args['mime'];
	
	// update page object
	load_modules('glue');
	$ret = update_object($obj);
	if ($ret['#error']) {
		log_msg('page_upload: error updating page object: '.quot($ret['#data']));
		return false;
	} else {
		// we don't actually render the object here, but signal the 
		// frontend that everything went okay
		return true;
	}
}


/*
 *	per-page password protection (SOW-page-password.md)
 *
 *	The password hash is a per-page property in the flat-file store (on the
 *	page's own settings object, page.rev.page - no db), pure-PHP bcrypt.
 *	The gate is the PHP session: entering the password authorizes THAT page
 *	for the session. Protected pages route their static assets through the
 *	'asset' controller below, and the php-served object resources gate at
 *	serve_resource() (controller.inc.php). Authenticated users (the editor)
 *	pass every gate: the owner does not need the page password.
 */


/**
 *	return the page's own settings object (page.rev.page), or an empty
 *	array when it does not exist yet
 *
 *	@param string $page full page name (i.e. page.rev)
 *	@return array
 */
function _page_password_obj($page)
{
	$o = load_object(['name'=>$page.'.page']);
	return ($o['#error']) ? [] : $o['#data'];
}


/**
 *	whether the page carries a password
 *
 *	@param string $page full page name
 *	@return bool
 */
function page_password_required($page)
{
	return !empty(_page_password_obj($page)['page-password']);
}


/**
 *	whether the current request may see this page: unprotected, or the
 *	session was authorized for it, or the visitor is an authenticated
 *	user (the owner edits and previews without the page password)
 *
 *	@param string $page full page name
 *	@return bool
 */
function page_password_allowed($page)
{
	if (!page_password_required($page)) {
		return true;
	}
	if (is_auth()) {
		return true;
	}
	if (session_status() !== PHP_SESSION_ACTIVE) {
		return false;
	}
	return !empty($_SESSION['hotglue_page_pass'][$page]);
}


/**
 *	the flat-file brute-force guard: per page and ip, at most
 *	PAGE_PASSWORD_MAX_TRIES attempts within PAGE_PASSWORD_WINDOW seconds.
 *	The counter file is a dotfile in the page directory - it holds a
 *	counter and a timestamp, nothing sensitive.
 *
 *	@param string $page full page name
 *	@return bool whether another attempt is allowed
 */
function page_password_try_allowed($page)
{
	$pn = get_first_item(expl('.', $page));
	$f = CONTENT_DIR.'/'.$pn.'/.password_tries';
	$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
	$data = @json_decode(@file_get_contents($f), true);
	if (!is_array($data)) {
		$data = [];
	}
	$now = time();
	if (empty($data[$ip]) || !is_array($data[$ip]) || $now - intval($data[$ip][0]) > PAGE_PASSWORD_WINDOW) {
		return true;
	}
	return intval($data[$ip][1]) < PAGE_PASSWORD_MAX_TRIES;
}


/**
 *	record a failed attempt
 *
 *	@param string $page full page name
 */
function page_password_try_failed($page)
{
	$pn = get_first_item(expl('.', $page));
	$f = CONTENT_DIR.'/'.$pn.'/.password_tries';
	$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
	$data = @json_decode(@file_get_contents($f), true);
	if (!is_array($data)) {
		$data = [];
	}
	$now = time();
	if (empty($data[$ip]) || !is_array($data[$ip]) || $now - intval($data[$ip][0]) > PAGE_PASSWORD_WINDOW) {
		$data[$ip] = [$now, 0];
	}
	$data[$ip][1] = intval($data[$ip][1]) + 1;
	@file_put_contents($f, json_encode($data));
}


/**
 *	clear the counter after a successful entry
 *
 *	@param string $page full page name
 */
function page_password_try_clear($page)
{
	$pn = get_first_item(expl('.', $page));
	$f = CONTENT_DIR.'/'.$pn.'/.password_tries';
	$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
	$data = @json_decode(@file_get_contents($f), true);
	if (!is_array($data)) {
		return;
	}
	unset($data[$ip]);
	@file_put_contents($f, json_encode($data));
}


/**
 *	verify a submitted password against the page's stored hash
 *
 *	@param string $page full page name
 *	@param string $pw
 *	@return bool
 */
function page_password_verify($page, $pw)
{
	$hash = _page_password_obj($page)['page-password'] ?? '';
	if (empty($hash)) {
		return false;
	}
	return password_verify($pw, $hash);
}


/**
 *	the styled password prompt: a standalone page, no dependencies on the
 *	protected page's own assets. $error keeps the message generic - it
 *	never says whether the page is protected or the password was wrong.
 *
 *	@param string $page full page name
 *	@param bool $error
 */
function page_password_prompt($page, $error)
{
	$url = '?'.htmlspecialchars($page, ENT_QUOTES, 'UTF-8');
	echo '<!DOCTYPE html>'.nl();
	echo '<html>'.nl();
	echo '<head>'.nl();
	echo '<meta charset="utf-8">'.nl();
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">'.nl();
	echo '<title>Password required</title>'.nl();
	echo '<style>'.nl();
	echo 'body { font-family: sans-serif; background: #fff; color: #000; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }'.nl();
	echo '.glue-password { max-width: 320px; width: 100%; padding: 24px; border: 1px solid #000; }'.nl();
	echo '.glue-password h1 { font-size: 18px; margin: 0 0 12px; font-weight: normal; }'.nl();
	echo '.glue-password p { font-size: 13px; }'.nl();
	echo '.glue-password input { width: 100%; box-sizing: border-box; padding: 6px 8px; border: 1px solid #000; font-size: 14px; margin-bottom: 10px; }'.nl();
	echo '.glue-password button { padding: 6px 12px; border: 1px solid #000; background: #fff; color: #000; font-size: 13px; cursor: pointer; }'.nl();
	echo '.glue-password .glue-password-error { color: #c00; }'.nl();
	echo '</style>'.nl();
	echo '</head>'.nl();
	echo '<body>'.nl();
	echo '<form class="glue-password" method="post" action="'.$url.'">'.nl();
	echo '<h1>This page is protected</h1>'.nl();
	if ($error) {
		echo '<p class="glue-password-error">The password was not accepted.</p>'.nl();
	} else {
		echo '<p>Enter the password to view it.</p>'.nl();
	}
	echo '<input type="password" name="page_password" autocomplete="current-password" autofocus>'.nl();
	echo '<button type="submit">Show the page</button>'.nl();
	echo '</form>'.nl();
	echo '</body>'.nl();
	echo '</html>'.nl();
	die();
}


/**
 *	the page-view gate: returns when the visitor may see the page, serves
 *	the prompt (and dies) otherwise. Runs before the cache and before the
 *	render, so nothing of a protected page is ever served to a visitor
 *	without the session flag.
 *
 *	@param string $page full page name
 */
function page_password_gate($page)
{
	if (!page_password_required($page)) {
		return;
	}
	if (page_password_allowed($page)) {
		return;
	}
	if (isset($_POST['page_password'])) {
		$pw = strval($_POST['page_password']);
		if (!page_password_try_allowed($page)) {
			page_password_prompt($page, true);
		}
		if (page_password_verify($page, $pw)) {
			page_password_try_clear($page);
			$_SESSION['hotglue_page_pass'][$page] = true;
			return;
		}
		page_password_try_failed($page);
		page_password_prompt($page, true);
	}
	page_password_prompt($page, false);
}


/**
 *	set, change or clear the page's password (the owner is authenticated
 *	by the editor auth, so no current password is needed). Returns whether
 *	the page is protected now.
 *
 *	@param array $args page, password ('', unset or missing clears)
 *	@return array response
 */
function page_set_password($args)
{
	if (empty($args['page'])) {
		return response('Required argument "page" missing or empty', 400);
	}
	if (!page_exists($args['page'])) {
		return response('Page '.quot($args['page']).' does not exist', 404);
	}
	$pw = isset($args['password']) ? strval($args['password']) : '';
	load_modules('glue');
	if ($pw === '') {
		$ret = object_remove_attr(['name'=>$args['page'].'.page', 'attr'=>['page-password']]);
	} elseif (!object_exists($args['page'].'.page')) {
		// a fresh page has no settings object yet - create it with the
		// hash on it (the same pattern the editor's undo uses to recreate
		// a missing object file)
		$ret = save_object(['name'=>$args['page'].'.page', 'page-password'=>password_hash($pw, PASSWORD_BCRYPT)]);
	} else {
		$ret = update_object(['name'=>$args['page'].'.page', 'page-password'=>password_hash($pw, PASSWORD_BCRYPT)]);
	}
	if ($ret['#error']) {
		log_msg('error', 'page_set_password: error storing password for '.quot($args['page']).': '.quot($ret['#data']));
		return $ret;
	}
	// the changed page must not be served from the page cache
	clear_cache('page', $args['page']);
	return response(page_password_required($args['page']));
}

register_service('page.set_password', 'page_set_password', ['auth'=>true]);


/**
 *	the gated asset proxy: serves a protected page's shared file after the
 *	session check. Only the path's basename is ever read - no traversal.
 *
 *	@param array $args p (full page name), f (file in shared)
 */
function controller_asset($args)
{
	if (empty($args['p']) || empty($args['f'])) {
		hotglue_error(403);
	}
	$page = $args['p'];
	if (!page_exists($page)) {
		hotglue_error(404);
	}
	// an unprotected page has no proxy urls - and telling is nothing
	if (!page_password_required($page)) {
		hotglue_error(403);
	}
	if (!page_password_allowed($page)) {
		hotglue_error(403);
	}
	$file = strval($args['f']);
	if ($file === '' || strpos($file, '/') !== false || strpos($file, '\\') !== false || strpos($file, '..') !== false) {
		hotglue_error(403);
	}
	$pn = get_first_item(expl('.', $page));
	$fn = CONTENT_DIR.'/'.$pn.'/shared/'.$file;
	if (!is_file($fn)) {
		hotglue_error(404);
	}
	serve_file($fn, false, _page_password_mime($file));
}

register_controller('asset', '', 'controller_asset');


/**
 *	a content type for a gated asset, by extension
 *
 *	@param string $file
 *	@return string mime
 */
function _page_password_mime($file)
{
	$map = [
		'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
		'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
		'ico' => 'image/x-icon', 'mp4' => 'video/mp4', 'm4v' => 'video/mp4',
		'webm' => 'video/webm', 'mov' => 'video/quicktime', 'avi' => 'video/x-msvideo',
		'mkv' => 'video/x-matroska', 'wmv' => 'video/x-ms-wmv', 'ogv' => 'video/ogg',
		'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac',
		'wav' => 'audio/wav', 'flac' => 'audio/flac', 'ogg' => 'audio/ogg',
		'oga' => 'audio/ogg', 'aiff' => 'audio/aiff', 'aif' => 'audio/aiff',
		'pdf' => 'application/pdf', 'woff2' => 'font/woff2', 'woff' => 'font/woff',
		'ttf' => 'font/ttf', 'otf' => 'font/otf', 'css' => 'text/css',
		'js' => 'text/javascript', 'txt' => 'text/plain',
	];
	$ext = strtolower(filext($file));
	return isset($map[$ext]) ? $map[$ext] : 'application/octet-stream';
}
