<?php

/*
 *	module_i18n.inc.php
 *	Editor UI localization for the theagitist/hotglue2 fork.
 *
 *	THE IDEA
 *	Hotglue's editor chrome and server messages were hardcoded English. This
 *	module adds a tiny i18n layer so they render in the user's language.
 *	Translations live in lang/<code>.json (symbolic, namespaced keys); en.json is
 *	the source of truth. Adding a language = drop one lang/<code>.json file, no
 *	code change (locales are discovered with glob()).
 *
 *	TWO HELPERS
 *	  - PHP  t($key, ...$args): used at server response() / welcome call sites.
 *	  - JS   $.glue.t(key, ...args): used in the editor JS (defined in
 *	    modules/i18n/i18n-edit.js, fed by the $.glue.i18n dict injected below).
 *	Both fall back: requested locale -> en -> the key itself, so a missing
 *	translation never shows a blank or a raw "object.delete".
 *
 *	LOCALE RESOLUTION
 *	  1. i18n_locale_override hook (the telaris branch returns the embedding
 *	     user's account language here, bypassing the rest);
 *	  2. ?lang= (also persisted to a cookie);
 *	  3. the cookie;
 *	  4. Accept-Language;
 *	  5. DEFAULT_LOCALE.
 *	Standalone shows a language selector (SHOW_LANG_SELECTOR); telaris turns it
 *	off and drives the locale through the override hook.
 *
 *	WHY A MODULE (mostly additive)
 *	load_modules() auto-discovers module_*.inc.php and invoke_hook() auto-calls
 *	<module>_<hook>, so the render hook below needs no registration. The catalogs
 *	+ helpers are all new files; the only upstream edits are the call sites that
 *	wrap their literals in t()/$.glue.t() (each carries a dated change notice).
 *
 *	Same GPL license as the rest of hotglue. See the file COPYING.
 */

@require_once('config.inc.php');
require_once('html.inc.php');

@define('DEFAULT_LOCALE', 'en');		// fallback / base catalog
@define('SHOW_LANG_SELECTOR', true);	// telaris sets this false (locale = account)
@define('LANG_COOKIE', 'hg_lang');		// remembers the standalone selector choice


/**
 *	available locales = lang/*.json basenames (data-driven, no hardcoded list)
 *	@return array of locale codes, sorted
 */
function i18n_available_locales()
{
	static $loc = null;
	if ($loc !== null) {
		return $loc;
	}
	$loc = array();
	foreach (glob(__DIR__.'/lang/*.json') as $f) {
		$loc[] = basename($f, '.json');
	}
	sort($loc);
	return $loc;
}


/**
 *	load a single catalog file (no merge); missing/invalid => empty array
 */
function i18n_load_file($locale)
{
	$path = __DIR__.'/lang/'.basename($locale).'.json';
	if (!is_file($path)) {
		return array();
	}
	$data = json_decode(file_get_contents($path), true);
	return is_array($data) ? $data : array();
}


/**
 *	merged catalog for a locale: en base with the locale's strings layered over,
 *	so any untranslated key falls back to English. Cached per locale.
 */
function i18n_catalog($locale)
{
	static $cache = array();
	if (isset($cache[$locale])) {
		return $cache[$locale];
	}
	$base = i18n_load_file(DEFAULT_LOCALE);
	if ($locale === DEFAULT_LOCALE) {
		$cat = $base;
	} else {
		$cat = array_merge($base, i18n_load_file($locale));
	}
	$cache[$locale] = $cat;
	return $cat;
}


/**
 *	resolve the active locale once per request (see header for the order)
 *	@return string a locale code that is guaranteed to have a catalog
 */
function i18n_locale()
{
	static $active = null;
	if ($active !== null) {
		return $active;
	}
	$avail = i18n_available_locales();

	// 1. embedding override (telaris account language)
	$ov = invoke_hook_while('i18n_locale_override', false);
	if (!empty($ov)) {
		$cand = reset($ov);
		if (in_array($cand, $avail, true)) {
			return $active = $cand;
		}
	}

	// 2. explicit ?lang= (persist to cookie for next time)
	if (isset($_GET['lang']) && in_array($_GET['lang'], $avail, true)) {
		$active = $_GET['lang'];
		if (!headers_sent()) {
			@setcookie(LANG_COOKIE, $active, time() + 31536000, '/');
		}
		return $active;
	}

	// 3. cookie
	if (isset($_COOKIE[LANG_COOKIE]) && in_array($_COOKIE[LANG_COOKIE], $avail, true)) {
		return $active = $_COOKIE[LANG_COOKIE];
	}

	// 4. Accept-Language (match full tag then primary subtag)
	if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
		foreach (explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']) as $part) {
			$code = strtolower(trim(substr($part, 0, strcspn($part, ';'))));
			if ($code === '') {
				continue;
			}
			$primary = substr($code, 0, strcspn($code, '-'));
			if (in_array($code, $avail, true)) {
				return $active = $code;
			}
			if (in_array($primary, $avail, true)) {
				return $active = $primary;
			}
		}
	}

	// 5. default
	if (in_array(DEFAULT_LOCALE, $avail, true)) {
		return $active = DEFAULT_LOCALE;
	}
	return $active = (count($avail) ? $avail[0] : 'en');
}


/**
 *	translate a key for the active locale, with %s placeholder interpolation.
 *	Fallback: locale -> en -> the key itself (never blank).
 *	@param string $key catalog key (e.g. 'page.not_exist')
 *	@param mixed ...$args values for %s placeholders (optional)
 *	@return string
 */
if (!function_exists('t')) {
	function t($key)
	{
		$cat = i18n_catalog(i18n_locale());
		$str = isset($cat[$key]) ? $cat[$key] : $key;
		$args = array_slice(func_get_args(), 1);
		if (count($args)) {
			$str = vsprintf($str, $args);
		}
		return $str;
	}
}


/**
 *	hook: render_page_early - in EDIT mode, inject the JS string dict + helper.
 *	The dict lands in the same auto-generated $.glue bootstrap block as
 *	$.glue.base_url (json-encoded for free), so it exists before any ready handler.
 */
function i18n_render_page_early($args)
{
	if (empty($args['edit'])) {
		return false;
	}
	$locale = i18n_locale();

	// native language names for the selector (each catalog's own lang.name)
	$names = array();
	foreach (i18n_available_locales() as $l) {
		$c = i18n_catalog($l);
		$names[$l] = isset($c['lang.name']) ? $c['lang.name'] : $l;
	}

	html_add_js_var('$.glue.locale', $locale);
	html_add_js_var('$.glue.i18n', i18n_catalog($locale));
	html_add_js_var('$.glue.conf.show_lang_selector', SHOW_LANG_SELECTOR ? true : false);
	html_add_js_var('$.glue.conf.locales', $names);
	html_add_js(base_url().'modules/i18n/i18n-edit.js');
	html_add_css(base_url().'modules/i18n/i18n-edit.css');
	return true;
}
