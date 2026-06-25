<?php

/*
 *	module_soundboard.inc.php
 *	Parallel-video "soundboard" for VIEW mode (theagitist/hotglue2 fork).
 *
 *	THE IDEA
 *	Turn a page of video tiles into a sound board: tap a tile to (re)trigger its
 *	clip, and let several clips sound at once. Two kinds of tile are supported by
 *	the runtime (modules/soundboard/soundboard.js):
 *	  - self-hosted <video>: routed through a single Web Audio AudioContext, so
 *	    multiple clips mix reliably, including on iOS (which is unreliable mixing
 *	    several media elements' own audio);
 *	  - embeds (.webvideo iframe), YouTube for now: driven through the YouTube
 *	    IFrame Player API with a tap overlay per tile. Embeds are cross-origin,
 *	    so they cannot be routed through Web Audio; simultaneous audio from
 *	    several embeds is a browser matter (fine on desktop, limited on iOS).
 *
 *	OPT-IN (per page)
 *	A page is a soundboard only when its page object carries page-soundboard. The
 *	editor toggle for that lives in modules/soundboard/soundboard-edit.js (a page
 *	menu item registered through the public $.glue API, so no core file is
 *	touched). On a flagged page every video object becomes a one-shot tap tile.
 *
 *	WHY A MODULE (no upstream edits)
 *	load_modules() auto-discovers module_*.inc.php and invoke_hook() auto-calls
 *	<module>_<hook>, so these two functions are enough; removing the feature is
 *	deleting this file plus modules/soundboard/. Same approach as module_mobile.
 *
 *	NOTE Web Audio needs same-origin media to output sound. Hotglue serves
 *	uploaded clips same-origin through the controller, so this holds; a future
 *	cross-origin clip URL would play silently.
 *
 *	Same GPL license as the rest of hotglue. See the file COPYING.
 */

@require_once('config.inc.php');
require_once('html.inc.php');


/**
 *	hook: render_page_early - in EDIT mode, add the page-menu "soundboard mode"
 *	toggle so the author can flag the page.
 */
function soundboard_render_page_early($args)
{
	if (empty($args['edit'])) {
		return false;
	}
	// ponytail: ship a single un-minified edit file and load it directly,
	// regardless of USE_MIN_FILES, to avoid hand-maintaining a .min.js twin.
	html_add_js(base_url().'modules/soundboard/soundboard-edit.js');
	html_add_css(base_url().'modules/soundboard/soundboard-edit.css');
	return true;
}


/**
 *	hook: render_page_late - on the published view of a flagged page, enqueue the
 *	Web Audio runtime. Fired for both edit and view; we act on view only.
 *
 *	@param array $args has 'page' (page.rev) and 'edit'
 */
function soundboard_render_page_late($args)
{
	// never interfere with the editor
	if (!empty($args['edit'])) {
		return false;
	}

	// per-page opt-in: page-soundboard lives on the <page>.<rev>.page object
	load_modules('glue');
	$obj = load_object(array('name'=>$args['page'].'.page'));
	if ($obj['#error']) {
		return false;
	}
	$obj = $obj['#data'];
	if (empty($obj['page-soundboard'])) {
		return false;
	}

	// ponytail: single un-minified runtime file, loaded directly (see above).
	html_add_js(base_url().'modules/soundboard/soundboard.js');
	return true;
}
