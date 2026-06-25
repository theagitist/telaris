<?php

/*
 *	module_mobile.inc.php
 *	Mobile "scale-to-fit" adaptation for VIEW mode (k0a1a/hotglue2 1.0.4).
 *
 *	THE PROBLEM
 *	Hotglue lays every object out with absolute pixel coordinates. A page
 *	authored at, say, 2000px wide is therefore unreadable on a phone: vanilla
 *	hotglue emits no <meta name="viewport">, so mobile browsers assume the
 *	legacy ~980px layout viewport and shrink the whole page to a sliver, with
 *	tiny text and sideways scrolling.
 *
 *	THE FIX (this module)
 *	On the published view only, wrap the page body and shrink the whole canvas
 *	to fit the screen width with CSS `transform: scale()`, clamped to a tunable
 *	floor (MOBILE_MIN_SCALE). Above the floor the page fits exactly (no
 *	horizontal scroll); at the floor it stops shrinking and the page scrolls
 *	horizontally at a still-readable size. The spatial layout is preserved
 *	exactly (no reflow) - this is a faithful zoom-out, not a re-layout. The
 *	drag-and-drop editor is never touched.
 *
 *	WHY A MODULE (no core edits)
 *	load_modules() (modules.inc.php) auto-discovers any module_*.inc.php in the
 *	program directory, and invoke_hook() auto-calls a function named
 *	<module>_<hook> if it exists. So defining mobile_render_page_late() is enough
 *	to run at the end of view rendering (module_glue.inc.php render_page(), which
 *	fires the `render_page_late` hook with `edit` in $args). Nothing else in the
 *	tree is modified; removing the feature = deleting this one file.
 *
 *	TUNING
 *	Override the floor in user-config.inc.php, e.g.
 *		@define('MOBILE_MIN_SCALE', '0.5');
 *	0.6 keeps text readable while fitting most pages; lower = fits wider pages
 *	before horizontal scroll kicks in, at the cost of smaller text.
 *
 *	Same GPL license as the rest of hotglue.
 */

@require_once('config.inc.php');
require_once('html.inc.php');

// minimum scale factor (1.0 = never shrink, 0.0 = always fit exactly).
@define('MOBILE_MIN_SCALE', '0.6');


/**
 *	hook: render_page_late - fired after all objects are rendered, for both
 *	view and edit. We act on the public view only.
 *
 *	@param array $args has 'page' and 'edit'
 */
function mobile_render_page_late($args)
{
	// never interfere with the editor
	if (!empty($args['edit'])) {
		return false;
	}

	// 1) responsive viewport (vanilla hotglue emits none). prio 1 = early in <head>.
	html_add_head_inline('<meta name="viewport" content="width=device-width, initial-scale=1">', 1);

	// 2) the scaler. sanitise the floor define into a safe JS float literal.
	$floor = floatval(MOBILE_MIN_SCALE);
	if ($floor < 0) {
		$floor = 0;
	}
	if (1 < $floor) {
		$floor = 1;
	}

	html_add_js_inline(mobile_scaler_js($floor), 7, 'mobile scale-to-fit (module_mobile)');

	return true;
}


/**
 *	client-side scaler. Wraps the body in a clip+scale pair and, when the
 *	viewport is narrower than the authored design width, applies
 *	transform: scale() clamped to $floor. Recomputes on resize/orientation and
 *	again on window load (after images settle the real height).
 *
 *	Runs from <head> (html_add_js_inline output lives there), so it defers all
 *	DOM work to DOMContentLoaded - the body does not exist yet at parse time.
 *
 *	@param float $floor sanitised minimum scale, safe to inline
 *	@return string javascript (without <script> wrapper)
 */
function mobile_scaler_js($floor)
{
	return <<<JS
(function () {
	var FLOOR = {$floor};
	var clip, scale, designW, designH;

	function measure() {
		// design size = furthest right/bottom edge of the absolutely-positioned
		// objects. offsetParent is #hg-scale (position:relative, at 0,0).
		var w = 0, h = 0, k = scale.children, i, el, r, b;
		for (i = 0; i < k.length; i++) {
			el = k[i];
			r = el.offsetLeft + el.offsetWidth;
			b = el.offsetTop + el.offsetHeight;
			if (r > w) w = r;
			if (b > h) h = b;
		}
		designW = w;
		designH = h;
		// abs-positioned children give the wrapper no intrinsic size, so set it
		// explicitly or the clip would collapse to zero height.
		scale.style.width = designW + 'px';
		scale.style.height = designH + 'px';
	}

	function apply() {
		if (!designW) return;
		var vpW = document.documentElement.clientWidth;
		var s = vpW / designW;
		if (s >= 1) {                  // screen already wide enough: untouched
			scale.style.transform = 'none';
			clip.style.width = '';
			clip.style.height = '';
			return;
		}
		if (s < FLOOR) s = FLOOR;      // floor: stop shrinking, allow h-scroll
		scale.style.transform = 'scale(' + s + ')';
		clip.style.width = (designW * s) + 'px';
		clip.style.height = (designH * s) + 'px';
	}

	function init() {
		clip = document.createElement('div');
		clip.id = 'hg-clip';
		clip.style.overflow = 'hidden';
		clip.style.margin = '0 auto';
		scale = document.createElement('div');
		scale.id = 'hg-scale';
		scale.style.position = 'relative';
		scale.style.transformOrigin = '0 0';
		while (document.body.firstChild) {
			scale.appendChild(document.body.firstChild);
		}
		clip.appendChild(scale);
		document.body.appendChild(clip);
		measure();
		apply();
		window.addEventListener('resize', apply);
		window.addEventListener('orientationchange', apply);
		// re-measure once images have loaded (heights can shift)
		window.addEventListener('load', function () { measure(); apply(); });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
JS;
}
