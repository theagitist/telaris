/**
 *	modules/i18n/i18n.js
 *	Translates the hotglue editor chrome from the English-keyed $.glue.i18n
 *	catalog injected by module_i18n.inc.php.
 *
 *	Only editor UI is touched: text inside .glue-ui elements, the title /
 *	placeholder / aria-label of any element (page objects carry none of the
 *	catalog's strings), and alert/confirm/prompt messages. Strings built by
 *	concatenation ('file too large (max ' + n + ')') are covered by also
 *	replacing the catalog's multi-word fragments inside them.
 *
 *	This source code is licensed under the GNU General Public License.
 *	See the file COPYING for more details.
 */

(function () {
	// hotglue emits its js vars AFTER the script tags, so the catalog is only
	// there once the document is parsed: everything starts on DOMContentLoaded
	// (registered before edit.js's own handler, so its menus are observed)
	var dict = {}, frags = [];

	function tr(s) {
		if (typeof s !== 'string' || !s.trim()) {
			return s;
		}
		if (Object.prototype.hasOwnProperty.call(dict, s)) {
			return dict[s];
		}
		var t = s.trim();
		if (t !== s && Object.prototype.hasOwnProperty.call(dict, t)) {
			return s.replace(t, dict[t]);
		}
		for (var i = 0; i < frags.length; i++) {
			if (s.indexOf(frags[i]) !== -1) {
				s = s.split(frags[i]).join(dict[frags[i]]);
			}
		}
		return s;
	}
	$.glue.tr = tr;

	var ATTRS = ['title', 'placeholder', 'aria-label'];

	function attrs(el) {
		for (var i = 0; i < ATTRS.length; i++) {
			var v = el.getAttribute(ATTRS[i]);
			if (v) {
				var n = tr(v);
				if (n !== v) {
					el.setAttribute(ATTRS[i], n);
				}
			}
		}
		// button-type inputs show their value
		if (el.tagName === 'INPUT' && /^(button|submit)$/i.test(el.type) && el.closest('.glue-ui')) {
			var b = tr(el.value);
			if (b !== el.value) {
				el.value = b;
			}
		}
	}

	function text(node) {
		var p = node.parentElement;
		if (!p || !p.closest('.glue-ui') || p.isContentEditable || /^(SCRIPT|STYLE|TEXTAREA)$/.test(p.tagName)) {
			return;
		}
		var n = tr(node.nodeValue);
		if (n !== node.nodeValue) {
			node.nodeValue = n;
		}
	}

	function walk(root) {
		if (root.nodeType === 3) {
			text(root);
			return;
		}
		if (root.nodeType !== 1) {
			return;
		}
		attrs(root);
		var it = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT);
		for (var n = it.nextNode(); n; n = it.nextNode()) {
			if (n.nodeType === 1) {
				attrs(n);
			} else {
				text(n);
			}
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		dict = $.glue.i18n || {};
		// fragments for concatenated strings: multi-word keys only, longest
		// first, so a short key never eats part of a longer one
		frags = Object.keys(dict).filter(function (k) {
			return k.length >= 8 && k.indexOf(' ') !== -1;
		}).sort(function (a, b) { return b.length - a.length; });
		walk(document.body);
		new MutationObserver(function (records) {
			records.forEach(function (r) {
				if (r.type === 'childList') {
					r.addedNodes.forEach(walk);
				} else if (r.type === 'attributes') {
					attrs(r.target);
				} else {
					text(r.target);
				}
			});
		}).observe(document.body, {
			childList: true, subtree: true, characterData: true,
			attributes: true, attributeFilter: ATTRS.concat(['value'])
		});
	});

	['alert', 'confirm'].forEach(function (f) {
		var orig = window[f];
		window[f] = function (msg) {
			return orig.call(window, tr(msg));
		};
	});
	var origPrompt = window.prompt;
	window.prompt = function (msg, def) {
		return origPrompt.call(window, tr(msg), def);
	};
})();
