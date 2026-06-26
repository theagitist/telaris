<?php

/*
 *	module_telaris_locale.inc.php
 *	TELARIS-BRANCH ONLY: drive the editor i18n locale from the Telaris account
 *	language, and hide the standalone language selector.
 *
 *	The standalone Hotglue (main) lets the user pick a language via a corner
 *	selector (remembered in a cookie). When embedded in Telaris the language must
 *	follow the logged-in Telaris user's account end to end, with no per-editor
 *	picker. Telaris owns the user/session, so it tells the embedded Hotglue which
 *	locale to use.
 *
 *	CONTRACT (the Telaris embedding must provide ONE of these; checked in order):
 *	  1. a PHP constant:   define('TELARIS_LOCALE', 'es');   // e.g. in the auth bridge
 *	  2. $_SERVER var:     fastcgi_param TELARIS_LOCALE $...; // nginx -> php-fpm
 *	  3. environment:      TELARIS_LOCALE=es                  // getenv()
 *	The value is a language code ('es','fr','pt','en') or a tag ('pt-BR'); it is
 *	matched against the available lang/*.json catalogs (full tag, then primary
 *	subtag). If Telaris provides nothing valid, the override returns false and
 *	module_i18n falls back to its normal resolution (Accept-Language /
 *	DEFAULT_LOCALE) - but the selector stays hidden here regardless.
 *
 *	Two additive hooks, no upstream edits:
 *	  - i18n_locale_override : feeds the account locale into module_i18n's resolver
 *	    (consumed by invoke_hook_while in i18n_locale()).
 *	  - render_page_early    : force the language selector OFF. Runs AFTER
 *	    module_i18n's render_page_early (modules load alphabetically: "i18n" <
 *	    "telaris_locale"), so it overwrites the shared show_lang_selector js_var.
 *
 *	Same GPL license as the rest of hotglue. See the file COPYING.
 */

@require_once('config.inc.php');
require_once('html.inc.php');


/**
 *	read the Telaris-provided locale (constant -> $_SERVER -> env), or '' if none
 */
function telaris_locale_raw()
{
	if (defined('TELARIS_LOCALE') && TELARIS_LOCALE !== '') {
		return (string)TELARIS_LOCALE;
	}
	if (!empty($_SERVER['TELARIS_LOCALE'])) {
		return (string)$_SERVER['TELARIS_LOCALE'];
	}
	$e = getenv('TELARIS_LOCALE');
	return ($e !== false) ? (string)$e : '';
}


/**
 *	hook: i18n_locale_override - map the Telaris account locale onto an available
 *	catalog. Returns a locale code, or false to let module_i18n resolve normally.
 */
function telaris_locale_i18n_locale_override($args)
{
	$raw = telaris_locale_raw();
	if ($raw === '') {
		return false;
	}
	// normalise e.g. "pt_BR" / "pt-BR" -> "pt-br"
	$code = strtolower(preg_replace('/[^a-zA-Z\-_]/', '', $raw));
	$code = str_replace('_', '-', $code);
	$avail = function_exists('i18n_available_locales') ? i18n_available_locales() : array();
	if (in_array($code, $avail, true)) {
		return $code;
	}
	$primary = substr($code, 0, strcspn($code, '-'));
	if ($primary !== '' && in_array($primary, $avail, true)) {
		return $primary;
	}
	return false;
}


/**
 *	hook: render_page_early - in EDIT mode, force the language selector OFF (the
 *	Telaris user does not pick a language; it follows the account). Runs after
 *	module_i18n's hook, so this wins on the shared $.glue.conf.show_lang_selector.
 */
function telaris_locale_render_page_early($args)
{
	if (empty($args['edit'])) {
		return false;
	}
	html_add_js_var('$.glue.conf.show_lang_selector', false);
	return true;
}
