/**
 *	modules/soundboard/soundboard.js
 *	Soundboard runtime for self-hosted <video> tiles: tap a tile to (re)trigger
 *	its clip, with all clips mixed through one Web Audio AudioContext so several
 *	can sound at once, including on iOS (which is unreliable mixing multiple
 *	<video> elements' own audio).
 *
 *	Embeds (.webvideo iframes: YouTube, PeerTube, Vimeo) are NOT handled here:
 *	they are cross-origin, so they cannot be routed through Web Audio, and we
 *	deliberately ship no provider control scripts. Embeds play through their own
 *	native player controls (tap the player's play button); several can play in
 *	parallel. Note simultaneous audio from multiple embeds is desktop-solid and
 *	limited on iOS (a cross-origin browser constraint). For reliable multi-audio
 *	on mobile, use self-hosted clips.
 *
 *	Enqueued by module_soundboard.inc.php only on the published view of a page
 *	flagged page-soundboard. Web Audio needs same-origin media to produce sound;
 *	hotglue serves uploads same-origin, so this holds.
 *
 *	This source code is licensed under the GNU General Public License.
 *	See the file COPYING for more details.
 */

(function () {
	function init() {
		var videos = [].slice.call(document.querySelectorAll('.video video'));
		if (!videos.length) {
			return;
		}

		var AC = window.AudioContext || window.webkitAudioContext;
		var ctx = null;

		// prepare each tile: inline playback, no autostart, no native controls;
		// we drive playback on tap. Clips are one-shot (re-tap restarts).
		videos.forEach(function (v) {
			v.setAttribute('playsinline', '');
			v.removeAttribute('autoplay');
			v.autoplay = false;
			v.removeAttribute('controls');
			v.controls = false;
			v.loop = false;
			v.muted = false;
			v.preload = 'auto';
			v.style.cursor = 'pointer';
			try { v.pause(); } catch (e) {}
		});

		// Wire every tile into one mixer. Must run inside a user gesture for the
		// AudioContext to start (esp. iOS), so we do it lazily on the first tap.
		function ensureMixer() {
			if (ctx || !AC) {
				return;
			}
			ctx = new AC();
			videos.forEach(function (v) {
				try {
					ctx.createMediaElementSource(v).connect(ctx.destination);
				} catch (e) {
					// already wired or not permitted; the element keeps its own audio
				}
			});
		}

		videos.forEach(function (v) {
			v.addEventListener('click', function () {
				ensureMixer();
				if (ctx && ctx.state === 'suspended') {
					ctx.resume();
				}
				try { v.currentTime = 0; } catch (e) {}
				var p = v.play();
				if (p && p.catch) {
					p.catch(function () {});
				}
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
