/**
 *	modules/draw/draw-edit.js
 *	"Draw image" launcher for the Hotglue editor (theagitist/hotglue2 fork).
 *
 *	Two entry points, both opening the vendored miniPaint app
 *	(modules/draw/minipaint/, MIT, same-origin iframe) in a modal:
 *	  - NEW: a button in the editor's NEW menu (click the page background) opens a
 *	    blank canvas; "place on page" drops the result where the menu was opened.
 *	  - EDIT: a button in an image object's context menu opens that image in
 *	    miniPaint; "place on page" drops the edited result back into the SAME box
 *	    (left/top/width/height/z-index) and removes the original object.
 *
 *	On "place" we composite miniPaint's layers to a canvas, export a PNG, and hand
 *	it to hotglue's existing upload path (glue.upload_files -> image_upload), so the
 *	result is a normal image object. The iframe is reloaded on every open, so each
 *	session starts clean (the bundle is browser-cached, so this is cheap).
 *
 *	miniPaint same-origin API (from its examples/): Layers.insert(),
 *	Layers.get_dimensions(), Layers.convert_layers_to_canvas(). Upload/placement
 *	reuse $.glue.upload.files + $.glue.upload.handle_response (js/edit.js); delete
 *	mirrors the object-delete handler (modules/object/object-edit.js).
 *
 *	Registered only in edit mode by module_draw.inc.php. No upstream files changed.
 *	jQuery 1.5.2 (editor's bundled version) has no .on(); use .bind().
 *
 *	This source code is licensed under the GNU General Public License.
 *	See the file COPYING for more details.
 */

(function () {
	var modal = null;     // overlay (built once)
	var frame = null;     // miniPaint iframe
	var place_x = 0, place_y = 0;  // where to drop the result (page coords)
	var edit = null;      // null = new-image mode; else {id, left, top, w, h, z}
	var ready_token = 0;  // invalidates a pending readiness poll if the modal reopens/closes

	var MP_URL_SUFFIX = 'modules/draw/minipaint/index.html';

	// miniPaint reads ?lang=<code> at boot (Helper.get_url_parameters -> AppConfig.LANG)
	// and bundles these languages. Map our active locale to one it has, else 'en'.
	var MP_LANGS = { en: 1, es: 1, fr: 1, pt: 1, de: 1, it: 1, ja: 1, ko: 1, nl: 1, ru: 1, tr: 1, uk: 1, zh: 1 };
	function mp_lang() {
		var l = String($.glue.locale || 'en').toLowerCase().replace(/[^a-z]/g, '');
		return MP_LANGS[l] ? l : 'en';
	}

	function build_modal() {
		modal = $(
			'<div class="glue-ui glue-draw-overlay" style="display: none;">' +
			'  <div class="glue-draw-panel">' +
			'    <div class="glue-draw-bar">' +
			'      <span class="glue-draw-title">' + $.glue.t('draw.title') + '</span>' +
			'      <span class="glue-draw-actions">' +
			'        <button type="button" class="glue-draw-cancel">' + $.glue.t('draw.cancel') + '</button>' +
			'        <button type="button" class="glue-draw-place">' + $.glue.t('draw.place') + '</button>' +
			'      </span>' +
			'    </div>' +
			'    <iframe class="glue-draw-frame" title="' + $.glue.t('draw.title') + '"></iframe>' +
			'  </div>' +
			'</div>'
		);
		frame = modal.find('.glue-draw-frame').get(0);
		modal.find('.glue-draw-cancel').bind('click', close_modal);
		modal.find('.glue-draw-place').bind('click', place_drawing);
		modal.bind('click', function (e) { e.stopPropagation(); });
		$('body').append(modal);
	}

	// run cb(contentWindow) once miniPaint has booted in the iframe (Layers ready)
	function when_ready(cb) {
		var token = ready_token;
		var tries = 0;
		(function poll() {
			if (token !== ready_token) { return; }  // modal closed/reopened: abandon
			var win = frame ? frame.contentWindow : null;
			if (win && win.Layers && typeof win.Layers.insert === 'function') {
				cb(win);
				return;
			}
			if (++tries > 240) { return; }  // ~12s ceiling
			setTimeout(poll, 50);
		})();
	}

	// open the modal; if load_url is given, load that image for editing
	function open_modal(load_url) {
		if (!modal) { build_modal(); }
		ready_token++;                       // invalidate any prior poll
		frame.src = $.glue.base_url + MP_URL_SUFFIX + '?lang=' + mp_lang();  // reload => clean session, in the active language
		modal.css('display', 'block');
		if (load_url) {
			when_ready(function (win) {
				var img = new Image();       // same-origin clip: no taint, export works
				img.onload = function () {
					try {
						win.Layers.insert({
							name: 'image', type: 'image', data: img,
							width: img.naturalWidth, height: img.naturalHeight,
							width_original: img.naturalWidth, height_original: img.naturalHeight
						});
					} catch (e) { $.glue.error($.glue.t('draw.load_error')); }
				};
				img.src = load_url;
			});
		}
	}

	function close_modal() {
		ready_token++;                       // abandon any pending insert
		if (modal) { modal.css('display', 'none'); }
		if (frame) { frame.src = 'about:blank'; }  // free the editor / its memory
		edit = null;
	}

	// mirrors modules/object/object-edit.js delete handler
	function delete_object(id) {
		var o = $('#' + id);
		if (o.length) {
			$.glue.object.unregister(o);
			o.remove();
		}
		$.glue.backend({ method: 'glue.delete_object', name: id });
		if ($.glue.canvas && $.glue.canvas.update) { $.glue.canvas.update(); }
	}

	function place_drawing() {
		var win = frame ? frame.contentWindow : null;
		if (!win || !win.Layers || typeof win.Layers.convert_layers_to_canvas !== 'function') {
			$.glue.error($.glue.t('draw.still_loading'));
			return;
		}
		var L = win.Layers;
		var dim = L.get_dimensions();
		var canvas = document.createElement('canvas');
		canvas.width = dim.width;
		canvas.height = dim.height;
		L.convert_layers_to_canvas(canvas.getContext('2d'));

		var x = place_x, y = place_y;
		var was_edit = edit;  // capture; close_modal() clears it

		var finish_up = function (blob) {
			if (!blob) { $.glue.error($.glue.t('draw.read_error')); return; }
			var file;
			try {
				file = new File([blob], 'drawing-' + (new Date()).getTime() + '.png', { type: 'image/png' });
			} catch (e) {
				blob.name = 'drawing-' + (new Date()).getTime() + '.png';
				file = blob;
			}
			$.glue.upload.files([file], { method: 'glue.upload_files', page: $.glue.page }, {
				finish: function (data) {
					$.glue.upload.handle_response(data, x, y);
					if (was_edit) {
						// drop the edited result back into the original's exact box,
						// then remove the original. Deferred so the image module's
						// upload-dynamic-late (which auto-sizes to natural) runs first.
						var newId = null;
						try { newId = (String(data['#data'][0]).match(/id="([^"]+)"/) || [])[1]; } catch (e) {}
						setTimeout(function () {
							if (newId) {
								var n = $('#' + newId);
								if (n.length) {
									n.css({ left: was_edit.left + 'px', top: was_edit.top + 'px',
										width: was_edit.w + 'px', height: was_edit.h + 'px',
										'z-index': was_edit.z });
									$.glue.object.save(n);
								}
							}
							delete_object(was_edit.id);
						}, 800);
					}
				},
				error: function () { $.glue.error($.glue.t('draw.place_error')); }
			});
			close_modal();
		};

		if (canvas.toBlob) {
			canvas.toBlob(finish_up, 'image/png');
		} else {
			var d = canvas.toDataURL('image/png');
			var bin = atob(d.split(',')[1]);
			var arr = new Uint8Array(bin.length);
			for (var i = 0; i < bin.length; i++) { arr[i] = bin.charCodeAt(i); }
			finish_up(new Blob([arr], { type: 'image/png' }));
		}
	}

	$(document).ready(function () {
		// NEW-image button in the "new" menu (background click)
		var add = $('<img src="' + $.glue.base_url + 'modules/draw/draw.png" alt="btn" title="' + $.glue.t('draw.new_title') + '" width="32" height="32">');
		$(add).bind('click', function () {
			var p = $.glue.menu.spawn_coords();
			place_x = p ? p.x : $(document).scrollLeft() + 200;
			place_y = p ? p.y : $(document).scrollTop() + 200;
			edit = null;
			$.glue.menu.hide();
			open_modal();
		});
		$.glue.menu.register('new', add);

		// EDIT-image button in an image object's context menu
		var ed = $('<img src="' + $.glue.base_url + 'modules/draw/draw.png" alt="btn" title="' + $.glue.t('draw.edit_title') + '" width="32" height="32">');
		$(ed).bind('click', function () {
			var obj = $(this).data('owner');
			if (!obj) { return; }
			var o = $(obj);
			var left = parseInt(o.css('left'), 10) || 0;
			var top = parseInt(o.css('top'), 10) || 0;
			edit = {
				id: o.attr('id'),
				left: left, top: top,
				w: o.outerWidth(), h: o.outerHeight(),
				z: o.css('z-index')
			};
			// drop the result roughly where the original is, then snap to its box
			place_x = left + o.outerWidth() / 2;
			place_y = top + o.outerHeight() / 2;
			// current image is shown as the object's background-image
			var bg = o.css('background-image') || '';
			var m = bg.match(/url\(["']?(.*?)["']?\)/);
			open_modal(m ? m[1] : null);
		});
		$.glue.contextmenu.register('image', 'draw-edit-image', ed);
	});
})();
