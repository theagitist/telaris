/**
 *	modules/soundboard/soundboard-edit.js
 *	Editor: a page-menu toggle for "soundboard mode" (the page-soundboard flag on
 *	the page object). Registered through the public $.glue API so no core editor
 *	file is modified. Modeled on the background-scroll toggle in
 *	modules/page/page-edit.js and the attribute toggles in
 *	modules/webvideo/webvideo-edit.js.
 *
 *	This source code is licensed under the GNU General Public License.
 *	See the file COPYING for more details.
 */

$(document).ready(function() {
	var elem = $('<div id="glue-menu-page-soundboard" alt="btn" style="height: 32px; width: 32px;" title="toggle soundboard mode: on the published page, tap video tiles to trigger clips with mixed audio (takes effect after a reload of the published page)">');

	// reflect the current state when the page menu opens
	$(elem).bind('glue-menu-activate', function(e) {
		var that = this;
		$.glue.backend({ method: 'glue.load_object', name: $.glue.page+'.page' }, function(data) {
			if (data && data['page-soundboard']) {
				$(that).addClass('glue-menu-enabled');
				$(that).removeClass('glue-menu-disabled');
			} else {
				$(that).addClass('glue-menu-disabled');
				$(that).removeClass('glue-menu-enabled');
			}
		});
	});

	// toggle + persist
	$(elem).bind('click', function(e) {
		if ($(this).hasClass('glue-menu-enabled')) {
			$(this).removeClass('glue-menu-enabled');
			$(this).addClass('glue-menu-disabled');
			$.glue.backend({ method: 'glue.object_remove_attr', name: $.glue.page+'.page', attr: 'page-soundboard' });
		} else {
			$(this).addClass('glue-menu-enabled');
			$(this).removeClass('glue-menu-disabled');
			$.glue.backend({ method: 'glue.update_object', name: $.glue.page+'.page', 'page-soundboard': '1' });
		}
	});

	$.glue.menu.register('page', elem);
});
