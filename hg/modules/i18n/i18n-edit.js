/**
 *	modules/i18n/i18n-edit.js
 *	Editor-side i18n helper for the theagitist/hotglue2 fork.
 *
 *	Provides $.glue.t(key, ...args): looks the key up in the $.glue.i18n dict
 *	injected by module_i18n.inc.php (already English-merged server-side), with
 *	%s placeholder substitution and fallback to the key itself. Only loaded in
 *	edit mode, so editor JS can call $.glue.t() unconditionally; code that also
 *	runs in view mode (e.g. js/glue.js) must guard with `$.glue.t ? ... : ...`.
 *
 *	Also renders the standalone language selector (suppressed when
 *	$.glue.conf.show_lang_selector is false, i.e. inside telaris, where the
 *	locale follows the user's account language).
 *
 *	jQuery 1.5.2 (the editor's bundled version) has no .on(); use .bind().
 *
 *	This source code is licensed under the GNU General Public License.
 *	See the file COPYING for more details.
 */

(function () {
	// $.glue.t(key, ...args) -> translated string ('%s' filled from args)
	$.glue.t = function (key) {
		var dict = $.glue.i18n || {};
		var s = (dict[key] !== undefined && dict[key] !== null) ? dict[key] : key;
		var rest = Array.prototype.slice.call(arguments, 1);
		if (rest.length) {
			var i = 0;
			s = String(s).replace(/%s/g, function () {
				return (i < rest.length) ? rest[i++] : '';
			});
		}
		return s;
	};

	$(document).ready(function () {
		if (!$.glue.conf || !$.glue.conf.show_lang_selector) {
			return;
		}
		var locales = $.glue.conf.locales || {};
		var sel = $('<select class="glue-lang-select" title="' + $.glue.t('menu.language') + '"></select>');
		for (var code in locales) {
			if (!locales.hasOwnProperty(code)) {
				continue;
			}
			var o = $('<option></option>').attr('value', code).text(locales[code]);
			if (code === $.glue.locale) {
				o.attr('selected', 'selected');
			}
			sel.append(o);
		}
		sel.bind('change', function () {
			var v = $(this).val();
			var d = new Date();
			d.setTime(d.getTime() + 31536000000);  // 1 year
			document.cookie = 'hg_lang=' + encodeURIComponent(v) + '; expires=' + d.toUTCString() + '; path=/';
			location.reload();
		});
		$('body').append(sel);
	});
})();
