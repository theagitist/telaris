/**
 *	modules/iframe/iframe-depth.js
 *	Visitor view: load embedded pages only a few frames deep.
 *
 *	A page may embed a page that embeds it back (an intentional infinite
 *	"garden"). Every level multiplies the frames, images and memory, so the
 *	server leaves each iframe's address in data-hg-src and this script loads it
 *	only while this page sits fewer than MAX_DEPTH frames below the top window.
 *	Deeper frames show a "go deeper" button that loads one more level on click,
 *	so the loop stays endless, one step at a time.
 *
 *	GPL-3.0 (part of the hotglue fork).
 */
(function () {
	// Frames between the top window and this page at which automatic loading stops.
	// 3 = a page opened on its own shows three levels of nesting (two inside the
	// Telaris card, which is itself a frame). Raise for deeper gardens on fast devices.
	var MAX_DEPTH = 3;

	var depth = 0;
	var w = window;
	try {
		while (w !== w.top && depth < 100) {
			w = w.parent;
			depth++;
		}
	} catch (e) {
		// a cross-origin ancestor: count what we could see
	}

	function load(f) {
		f.src = f.getAttribute('data-hg-src');
		f.removeAttribute('data-hg-src');
	}

	function deeperButton(f) {
		var b = document.createElement('button');
		b.type = 'button';
		b.textContent = '∞ ' + (f.getAttribute('data-hg-deeper') || '');
		b.style.cssText = 'position:absolute;inset:0;margin:auto;width:max-content;height:max-content;' +
			'padding:0.5em 1em;font:inherit;font-size:0.85em;cursor:pointer;' +
			'background:rgba(0,0,0,0.55);color:#fff;border:1px solid rgba(255,255,255,0.5);border-radius:999px;';
		b.addEventListener('click', function () {
			b.remove();
			load(f);
		});
		f.parentNode.appendChild(b);
	}

	function run() {
		var frames = document.querySelectorAll('iframe[data-hg-src]');
		for (var i = 0; i < frames.length; i++) {
			if (depth < MAX_DEPTH) {
				load(frames[i]);
			} else {
				deeperButton(frames[i]);
			}
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', run);
	} else {
		run();
	}
})();
