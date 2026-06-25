/**
 *	modules/soundboard/soundboard.js
 *	Soundboard runtime: tap a tile to (re)trigger its clip; several can play at
 *	once. Handles two kinds of tile:
 *
 *	  1. Self-hosted <video> (.video video): mixed through one Web Audio
 *	     AudioContext, so multiple clips sound at once reliably, including on iOS
 *	     (which is unreliable mixing several media elements' own audio).
 *
 *	  2. Embeds (.webvideo iframe), YouTube for now: driven through the YouTube
 *	     IFrame Player API. A transparent overlay turns each tile into a one-tap
 *	     trigger (tap = restart + play + unmute). NOTE embeds are cross-origin
 *	     iframes, so they cannot be routed through Web Audio; simultaneous audio
 *	     from several embeds is therefore a browser matter (fine on desktop,
 *	     limited on iOS, which tends to allow one audio source). If you need
 *	     reliable multi-audio on mobile, use self-hosted clips (mode 1).
 *
 *	Enqueued by module_soundboard.inc.php only on the published view of a page
 *	flagged page-soundboard.
 *
 *	This source code is licensed under the GNU General Public License.
 *	See the file COPYING for more details.
 */

(function () {

	// --- mode 1: self-hosted clips, mixed via Web Audio ---------------------
	function wireSelfHosted(videos) {
		var AC = window.AudioContext || window.webkitAudioContext;
		var ctx = null;

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

		// Must start the AudioContext inside a user gesture (esp. iOS), so wire
		// the mixer lazily on the first tap.
		function ensureMixer() {
			if (ctx || !AC) {
				return;
			}
			ctx = new AC();
			videos.forEach(function (v) {
				try {
					ctx.createMediaElementSource(v).connect(ctx.destination);
				} catch (e) {
					// already wired or not permitted; element keeps its own audio
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

	// --- mode 2: YouTube embeds, driven via the IFrame Player API -----------
	function isYouTube(f) {
		return (f.getAttribute('src') || '').indexOf('youtube.com/embed/') !== -1;
	}

	function addTriggerOverlay(frame, onTap) {
		var tile = frame.parentNode; // the .webvideo object div
		if (!tile) {
			return;
		}
		if (getComputedStyle(tile).position === 'static') {
			tile.style.position = 'absolute';
		}
		var ov = document.createElement('div');
		ov.className = 'soundboard-trigger';
		ov.style.cssText = 'position:absolute;left:0;top:0;right:0;bottom:0;z-index:5;cursor:pointer;background:transparent;';
		ov.addEventListener('click', function (e) {
			e.preventDefault();
			onTap();
		});
		tile.appendChild(ov);
	}

	function wireYouTubeEmbeds(frames) {
		// the API needs enablejsapi=1 in the src and an id to attach to
		frames.forEach(function (f, i) {
			var s = f.getAttribute('src') || '';
			if (s.indexOf('enablejsapi=1') === -1) {
				f.setAttribute('src', s + (s.indexOf('?') > -1 ? '&' : '?') + 'enablejsapi=1');
			}
			if (!f.id) {
				f.id = 'sb-yt-' + i;
			}
		});

		var players = {};
		// global callback the IFrame API calls once loaded
		window.onYouTubeIframeAPIReady = function () {
			frames.forEach(function (f) {
				players[f.id] = new YT.Player(f.id);
			});
			// add tap overlays only now that players exist; if the API never
			// loads we add none, so the embeds keep their native play controls
			frames.forEach(function (f) {
				addTriggerOverlay(f, function () {
					var p = players[f.id];
					if (!p || !p.playVideo) {
						return;
					}
					try {
						p.unMute();
						p.seekTo(0);
						p.playVideo();
					} catch (e) {}
				});
			});
		};

		var tag = document.createElement('script');
		tag.src = 'https://www.youtube.com/iframe_api';
		(document.head || document.body).appendChild(tag);
	}

	function init() {
		var videos = [].slice.call(document.querySelectorAll('.video video'));
		if (videos.length) {
			wireSelfHosted(videos);
		}
		var frames = [].slice.call(document.querySelectorAll('.webvideo iframe')).filter(isYouTube);
		if (frames.length) {
			wireYouTubeEmbeds(frames);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
