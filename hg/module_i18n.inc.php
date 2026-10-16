<?php

/*
 *	module_i18n.inc.php
 *	Editor UI localization for the Telaris embed (telaris-ng branch).
 *
 *	Drop-in module: no upstream file is edited. The catalogs in lang/<locale>.json
 *	are keyed by the editor's own English strings, and modules/i18n/i18n.js
 *	translates the editor chrome (.glue-ui elements, tooltips, dialogs) in the
 *	browser, so a new upstream release needs only new catalog entries, never a
 *	re-port. Untranslated strings simply stay English.
 *
 *	Locale: TELARIS_LOCALE (defined by telaris-auth.inc.php from the editor's
 *	account language), else ?lang= (the Telaris editor appends it to the iframe
 *	URL). English needs no catalog.
 *
 *	This source code is licensed under the GNU General Public License.
 *	See the file COPYING for more details.
 */

@require_once('config.inc.php');
require_once('html.inc.php');


function i18n_locale()
{
	$raw = defined('TELARIS_LOCALE') ? (string)TELARIS_LOCALE : ($_GET['lang'] ?? '');
	// "es-CR" / "pt_BR" -> "es" / "pt": the catalogs are per primary language
	$code = strtolower(substr($raw, 0, strcspn($raw, '-_')));
	return (preg_match('/^[a-z]{2,3}$/', $code) && is_file(__DIR__.'/lang/'.$code.'.json')) ? $code : '';
}


function i18n_render_page_early($args)
{
	if (empty($args['edit']) || ($locale = i18n_locale()) === '') {
		return false;
	}
	$dict = json_decode((string)file_get_contents(__DIR__.'/lang/'.$locale.'.json'), true);
	if (!is_array($dict)) {
		return false;
	}
	html_add_js_var('$.glue.i18n', $dict);
	// prio 3: before edit.js (4) builds the menus, so its first DOM is caught
	html_add_js(base_url().'modules/i18n/i18n.js', 3);
	return true;
}
