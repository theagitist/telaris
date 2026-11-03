/**
 *	modules/transform/transform.js
 *	Frontend code for flipping and rotating objects
 *
 *	Copyright Gottfried Haider, Danja Vasiliev 2010.
 *	Rotation + standard-property rewrite: Telaris, 2026.
 *	This source code is licensed under the GNU General Public License.
 *	See the file COPYING for more details.
 */

$(document).ready(function() {
	// An object's transform is kept as one matrix = rotate(angle) * scale(fx, fy).
	// It is stored in the object file as transform-flip (the same key upstream
	// ng uses), so pages rotated or flipped in either editor round-trip.
	function get_state(obj) {
		var m = window.getComputedStyle(obj).transform;
		if (!m || m === 'none') {
			return { angle: 0, fx: 1, fy: 1 };
		}
		var v = m.replace(/^matrix\(|\)$/g, '').split(',').map(parseFloat);
		var a = v[0], b = v[1], c = v[2], d = v[3];
		var det = a * d - b * c;
		// a mirror is expressed as a vertical flip; the rest is rotation
		var fy = det < 0 ? -1 : 1;
		var angle = Math.atan2(b, a) * 180 / Math.PI;
		return { angle: angle, fx: 1, fy: fy };
	}

	function set_state(obj, st) {
		var r = st.angle * Math.PI / 180;
		var cos = Math.cos(r), sin = Math.sin(r);
		var a = cos * st.fx, b = sin * st.fx, c = -sin * st.fy, d = cos * st.fy;
		var round = function(n) { return Math.round(n * 1e6) / 1e6; };
		var m = 'matrix(' + [round(a), round(b), round(c), round(d), 0, 0].join(', ') + ')';
		if (m === 'matrix(1, 0, 0, 1, 0, 0)') {
			m = '';
		}
		// drop any legacy prefixed copies so only the standard property is saved
		$(obj).css({ '-webkit-transform': '', '-moz-transform': '', 'transform': m });
	}

	var elem;
	elem = $('<img src="'+$.glue.base_url+'modules/transform/transform-flip.png" alt="btn" title="'+$.glue.t('transform.flip')+'" width="32" height="32">');
	$(elem).bind('click', function(e) {
		// mirror the object left-right on top of its rotation; click again to undo
		var obj = $(this).data('owner');
		var st = get_state(obj);
		st.fy = -st.fy;
		st.angle = st.angle + 180;
		set_state(obj, st);
		$.glue.object.save(obj);
	});
	$.glue.contextmenu.register('object', 'object-transform-flip', elem, 5);

	elem = $('<img src="'+$.glue.base_url+'modules/transform/transform-rotate.png" alt="btn" title="'+$.glue.t('transform.rotate', '0')+'" width="32" height="32">');
	$(elem).bind('glue-menu-activate', function(e) {
		var obj = $(this).data('owner');
		$(this).attr('title', $.glue.t('transform.rotate', Math.round(get_state(obj).angle)));
	});
	$(elem).bind('mousedown', function(e) {
		var that = this;
		var obj = $(this).data('owner');
		var st = get_state(obj);
		var start = st.angle;
		$.glue.slider(e, function(x, y, ev) {
			var a = start + x / 2;
			if (ev && ev.shiftKey) {
				a = Math.round(a / 15) * 15;
			}
			st.angle = a;
			set_state(obj, st);
		}, function(x, y) {
			$.glue.object.save(obj);
			$(that).trigger('glue-menu-activate');
		});
		return false;
	});
	$.glue.contextmenu.register('object', 'object-transform-rotate', elem, 6);
});
