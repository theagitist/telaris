<?php

/*
 *	module_draw.inc.php
 *	In-app image editor for the theagitist/hotglue2 fork.
 *
 *	THE IDEA
 *	Let an author draw / paint / create an image without leaving the editor, then
 *	drop it on the page as a normal image object. We do NOT build an editor: we
 *	embed the vendored miniPaint app (modules/draw/minipaint/, MIT, pinned; see
 *	its VENDOR.md) in a modal iframe. On "place", modules/draw/draw-edit.js
 *	composites miniPaint's layers to a canvas, turns it into a file, and hands it
 *	to hotglue's EXISTING upload path ($.glue.upload.files -> glue.upload_files ->
 *	image_upload), so the result is an ordinary image object. No new server code.
 *
 *	WHY A MODULE (no upstream edits)
 *	load_modules() auto-discovers module_*.inc.php and invoke_hook() auto-calls
 *	<module>_<hook>, so this one edit-mode function is enough. Same approach as
 *	module_mobile / module_soundboard. Nothing loads in view mode.
 *
 *	Same GPL license as the rest of hotglue. See the file COPYING. (The embedded
 *	miniPaint app keeps its own MIT licence, see modules/draw/minipaint/.)
 */

@require_once('config.inc.php');
require_once('html.inc.php');


/**
 *	hook: render_page_early - in EDIT mode, load the "draw image" launcher (a new
 *	menu button that opens the embedded editor). Nothing in view mode.
 */
function draw_render_page_early($args)
{
	if (empty($args['edit'])) {
		return false;
	}
	// ponytail: ship a single un-minified edit file and load it directly,
	// regardless of USE_MIN_FILES, to avoid hand-maintaining a .min.js twin.
	html_add_js(base_url().'modules/draw/draw-edit.js');
	html_add_css(base_url().'modules/draw/draw-edit.css');
	return true;
}
