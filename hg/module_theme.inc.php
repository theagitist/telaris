<?php

/*
 *	module_theme.inc.php
 *	Per-deployment editor skin for the theagitist/hotglue2 fork.
 *
 *	THE IDEA
 *	The editor icons were modernised (Lucide line glyphs, see tools/icons/) and
 *	baked per brand: this branch ships them in one colour. The only CSS the new
 *	icons need is a touch of chrome glue, kept in css/theme.css:
 *	  - the icons render at 64px (2x) for retina; the action icons are <img
 *	    width=32> so they downscale on their own, but the toggle icons are 32px
 *	    background-image <div>s, so theme.css pins their background-size to 32px.
 *	  - state now reads from each toggle's own check/x badge, so the old green
 *	    "enabled" box is switched off.
 *
 *	css/theme.css is THE per-deployment skin seam: standalone ships navy-on-light
 *	here; the telaris branch overrides this same file with the Void/Aurora/mint
 *	brand chrome. Keeping it one additive file means no upstream CSS edits and no
 *	merge conflicts between the branches.
 *
 *	WHY A MODULE (no upstream edits)
 *	load_modules() auto-discovers module_*.inc.php and invoke_hook() auto-calls
 *	<module>_<hook>, so this one function is enough. Same approach as
 *	module_mobile / module_soundboard. Skin only loads in edit mode (the icons it
 *	styles only exist in the editor).
 *
 *	Same GPL license as the rest of hotglue. See the file COPYING.
 */

@require_once('config.inc.php');
require_once('html.inc.php');


/**
 *	hook: render_page_early - in EDIT mode, load the editor skin.
 */
function theme_render_page_early($args)
{
	if (empty($args['edit'])) {
		return false;
	}
	// ponytail: single un-minified css, loaded directly regardless of
	// USE_MIN_FILES; it is tiny and avoids a hand-kept .min.css twin.
	html_add_css(base_url().'css/theme.css');
	return true;
}
