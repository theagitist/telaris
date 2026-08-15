/**
 * Telaris Soundscape
 * Ethereal, glitchy, meditative ambient audio for telaris.polivoxia.ca
 *
 * Pure Web Audio API — no dependencies, no build step.
 * Starts automatically on first user interaction (browser autoplay policy).
 *
 * Usage:
 *   import TelarisSoundscape from './telaris-soundscape.js';
 *   const soundscape = new TelarisSoundscape();
 *   soundscape.start();   // call after a user gesture
 *   soundscape.stop();    // fade out and suspend
 *   soundscape.setVolume(0.8); // 0.0 – 1.0
 *
 * Or just drop the script tag and it auto-starts on first click/touch:
 *   <script src="telaris-soundscape.js"></script>
 */

// ── Sound themes (presets) ───────────────────────────────────────────────────
// Independent per-galaxy axis, chosen in the galaxy edit window and carried on
// window.TELARIS_TOUR_CONFIG.sound_theme (same channel as group_nodes /
// heavy_inertia). 'default' reproduces the original soundscape unchanged; add a
// new key here + a <option> in inc/partials/galaxy-edit-modal.php to add a theme.
const SOUND_PRESETS = {
  default: {
    pitchMul: 1.0,        // multiplies the ambient bed frequencies
    oscMixGain: 0.18,     // tonal pad level
    noiseGain: 0.06,      // white-noise breath level
    noiseLpFreq: 800,     // noise brightness (lowpass cutoff, Hz)
    noiseLfoRate: 0,      // 0 = static level; >0 Hz = slow breathing
    noiseLfoDepth: 0,     // gain added/removed by the level LFO
    noiseSweepRate: 0,    // 0 = static brightness; >0 Hz = slow cutoff drift
    noiseSweepDepth: 0,   // cutoff sweep amount, Hz
    glitchPitchMul: 1.0,  // scales one-shot glitch frequencies
    glitchGain: 0.15,
    glitchNoiseBias: false, // bias glitch type toward the noise burst
    autoGlitch: false,    // ambient auto-fired bings/pings
    autoGlitchMinMs: 0,
    autoGlitchMaxMs: 0,
  },
  // Rhizome: glitchy, high-pitched bings and pings over a quiet, evolving hiss.
  rhizome: {
    pitchMul: 2.0,        // bed an octave up
    oscMixGain: 0.10,     // quieter tonal pad, more room for the glitches
    noiseGain: 0.06,      // quiet white noise (was 0.16, operator: too loud)
    noiseLpFreq: 3200,    // bright, airy hiss
    noiseLfoRate: 0.05,   // ~20s breathing cycle
    noiseLfoDepth: 0.04,  // level drifts ~0.02..0.10
    noiseSweepRate: 0.03, // ~33s brightness cycle
    noiseSweepDepth: 1500,// cutoff drifts ~1700..4700 Hz
    glitchPitchMul: 2.5,  // higher-pitched pings
    glitchGain: 0.16,
    glitchNoiseBias: true,
    autoGlitch: true,     // ping/bing on a random timer, no interaction needed
    autoGlitchMinMs: 700,
    autoGlitchMaxMs: 3500,
  },
};

class TelarisSoundscape {
  constructor(options = {}) {
    this.volume = options.volume ?? 0.7;
    this.fadeTime = options.fadeTime ?? 3.0;
    const presetId = options.preset
      || (typeof window !== 'undefined' && window.TELARIS_TOUR_CONFIG && window.TELARIS_TOUR_CONFIG.sound_theme)
      || 'default';
    this.presetId = SOUND_PRESETS[presetId] ? presetId : 'default';
    this.preset = SOUND_PRESETS[this.presetId];
    this._ctx = null;
    this._master = null;
    this._nodes = [];
    this._running = false;
    this._started = false;
    this._autoGlitchTimer = null;
  }

  // ── Public API ────────────────────────────────────────────────────────────

  async start() {
    if (this._running) return;

    if (!this._ctx) {
      this._ctx = new (window.AudioContext || window.webkitAudioContext)({
        sampleRate: 44100,
        latencyHint: 'playback',
      });
    }

    if (this._ctx.state === 'suspended') {
      await this._ctx.resume();
    }

    if (!this._started) {
      this._build();
      this._started = true;
    }

    // Fade master in
    const now = this._ctx.currentTime;
    this._master.gain.cancelScheduledValues(now);
    this._master.gain.setValueAtTime(0, now);
    this._master.gain.linearRampToValueAtTime(this.volume, now + this.fadeTime);
    this._running = true;
    this._scheduleAutoGlitch();
  }

  async stop() {
    if (!this._running || !this._ctx) return;
    clearTimeout(this._autoGlitchTimer);
    this._autoGlitchTimer = null;
    const now = this._ctx.currentTime;
    this._master.gain.cancelScheduledValues(now);
    this._master.gain.setValueAtTime(this._master.gain.value, now);
    this._master.gain.linearRampToValueAtTime(0, now + this.fadeTime);
    setTimeout(() => {
      this._ctx.suspend();
      this._running = false;
    }, this.fadeTime * 1000 + 200);
  }

  // Ambient bings/pings on a random timer (rhizome and any preset with autoGlitch).
  _scheduleAutoGlitch() {
    const p = this.preset;
    if (!p.autoGlitch || this._autoGlitchTimer) return;
    const delay = p.autoGlitchMinMs + Math.random() * (p.autoGlitchMaxMs - p.autoGlitchMinMs);
    this._autoGlitchTimer = setTimeout(() => {
      this._autoGlitchTimer = null;
      if (this._running && this.volume > 0) this.playGlitch();
      if (this._running) this._scheduleAutoGlitch();
    }, delay);
  }

  setVolume(v) {
    this.volume = Math.max(0, Math.min(1, v));
    if (this._master && this._running) {
      const now = this._ctx.currentTime;
      this._master.gain.setTargetAtTime(this.volume, now, 0.5);
    }
  }

  /**
   * Play a short, randomized glitch/electric sound.
   * Aesthetic: Low-fi, airy, varied.
   */
  playGlitch() {
    if (!this._ctx || this._ctx.state === 'suspended') return;
    const ctx = this._ctx;
    const now = ctx.currentTime;
    const p = this.preset;
    const pm = p.glitchPitchMul;

    // Randomize glitch type: 0 = tonal blip, 1 = noise burst, 2 = FM chirp.
    // glitchNoiseBias skews toward the noise burst (glitchier, hiss-forward).
    let type = Math.floor(Math.random() * 3);
    if (p.glitchNoiseBias && Math.random() < 0.5) type = 1;
    const g = ctx.createGain();
    g.connect(this._master);

    const dur = 0.05 + Math.random() * 0.15;
    g.gain.setValueAtTime(0, now);
    g.gain.linearRampToValueAtTime(p.glitchGain * this.volume, now + 0.005);
    g.gain.exponentialRampToValueAtTime(0.001, now + dur);

    if (type === 0) {
        // Tonal square/saw blip
        const osc = ctx.createOscillator();
        osc.type = Math.random() > 0.5 ? 'square' : 'sawtooth';
        osc.frequency.setValueAtTime((100 + Math.random() * 800) * pm, now);
        osc.frequency.exponentialRampToValueAtTime((20 + Math.random() * 100) * pm, now + dur);

        const filter = ctx.createBiquadFilter();
        filter.type = 'lowpass';
        filter.frequency.value = 1000 * pm;

        osc.connect(filter);
        filter.connect(g);
        osc.start(now);
        osc.stop(now + dur);
    } else if (type === 1) {
        // Filtered noise burst (static/spark)
        const bufferSize = ctx.sampleRate * 0.2;
        const buffer = ctx.createBuffer(1, bufferSize, ctx.sampleRate);
        const data = buffer.getChannelData(0);
        for (let i = 0; i < bufferSize; i++) data[i] = Math.random() * 2 - 1;

        const noise = ctx.createBufferSource();
        noise.buffer = buffer;

        const filter = ctx.createBiquadFilter();
        filter.type = 'bandpass';
        filter.frequency.value = (2000 + Math.random() * 3000) * pm;
        filter.Q.value = 10;

        noise.connect(filter);
        filter.connect(g);
        noise.start(now);
        noise.stop(now + dur);
    } else {
        // FM Glitch Chirp
        const mod = ctx.createOscillator();
        const modGain = ctx.createGain();
        const car = ctx.createOscillator();

        mod.frequency.value = (50 + Math.random() * 500) * pm;
        modGain.gain.value = 100 + Math.random() * 1000;
        car.frequency.value = (200 + Math.random() * 1000) * pm;
        
        mod.connect(modGain);
        modGain.connect(car.frequency);
        car.connect(g);
        
        mod.start(now);
        car.start(now);
        mod.stop(now + dur);
        car.stop(now + dur);
    }
    
    setTimeout(() => g.disconnect(), (dur + 0.1) * 1000);
  }

  /**
   * Short musical ping for rolling over a node. Picks one of several notes
   * (bright pentatonic set) with a random waveform and a small up/down chirp,
   * so repeated hovers sound varied rather than identical. Follows the preset's
   * pitch character (glitchPitchMul), softer and more tonal than playGlitch.
   */
  playHover() {
    if (!this._ctx || this._ctx.state === 'suspended' || this.volume <= 0) return;
    const ctx = this._ctx;
    const now = ctx.currentTime;
    const pm = this.preset.glitchPitchMul || 1.0;
    const notes = [523.25, 587.33, 659.25, 783.99, 880.0, 1046.5]; // C5 D5 E5 G5 A5 C6
    const f = notes[Math.floor(Math.random() * notes.length)] * pm;
    const dur = 0.12 + Math.random() * 0.1;

    const g = ctx.createGain();
    g.connect(this._master);
    g.gain.setValueAtTime(0, now);
    g.gain.linearRampToValueAtTime(0.12 * this.volume, now + 0.008);
    g.gain.exponentialRampToValueAtTime(0.001, now + dur);

    const osc = ctx.createOscillator();
    osc.type = Math.random() > 0.5 ? 'sine' : 'triangle';
    osc.frequency.setValueAtTime(f, now);
    osc.frequency.exponentialRampToValueAtTime(f * (Math.random() > 0.5 ? 1.5 : 0.75), now + dur);
    osc.connect(g);
    osc.start(now);
    osc.stop(now + dur);
    setTimeout(() => g.disconnect(), (dur + 0.1) * 1000);
  }

  // ── Internal build ────────────────────────────────────────────────────────

  _build() {
    const ctx = this._ctx;

    // Master output with gain for fade in/out
    this._master = ctx.createGain();
    this._master.gain.setValueAtTime(0, ctx.currentTime);
    this._master.connect(ctx.destination);

    // ── Layer 1: Detuned oscillator cluster ──────────────────────────────
    // Four sine oscillators in an open-fifth stack: A1 E2 A2 E3
    // Each has its own slow LFO for micro-detuning (vibrato depth varies)
    const oscFreqs  = [55.0, 82.41, 110.0, 164.81].map(f => f * this.preset.pitchMul);
    const lfoRates  = [0.031, 0.047, 0.019, 0.061];
    const lfoDepths = [0.8,   0.6,   1.1,   0.5  ];

    const oscMix = ctx.createGain();
    oscMix.gain.value = this.preset.oscMixGain;

    oscFreqs.forEach((freq, i) => {
      const lfo = ctx.createOscillator();
      lfo.type = 'sine';
      lfo.frequency.value = lfoRates[i];

      const lfoGain = ctx.createGain();
      lfoGain.gain.value = lfoDepths[i];

      const freqAdder = ctx.createConstantSource();
      freqAdder.offset.value = freq;

      // LFO modulates the frequency offset
      lfo.connect(lfoGain);
      lfoGain.connect(freqAdder.offset);

      const osc = ctx.createOscillator();
      osc.type = 'sine';
      osc.frequency.value = 0; // driven entirely by freqAdder

      // Connect frequency source to oscillator
      freqAdder.connect(osc.frequency);

      osc.connect(oscMix);

      lfo.start();
      freqAdder.start();
      osc.start();
      this._nodes.push(lfo, freqAdder, osc);
    });

    // ── Noise layer (breath texture) ─────────────────────────────────────
    // White noise through a lowpass — adds organic air beneath the tones
    const noiseNode = this._makeNoise(ctx);
    const noiseGain = ctx.createGain();
    noiseGain.gain.value = this.preset.noiseGain;
    noiseNode.connect(noiseGain);

    // Slow level breathing so the hiss never sits as a constant flat tone.
    if (this.preset.noiseLfoRate > 0) {
      const nLfo = ctx.createOscillator();
      nLfo.type = 'sine';
      nLfo.frequency.value = this.preset.noiseLfoRate;
      const nLfoGain = ctx.createGain();
      nLfoGain.gain.value = this.preset.noiseLfoDepth; // added to noiseGain.gain
      nLfo.connect(nLfoGain);
      nLfoGain.connect(noiseGain.gain);
      nLfo.start();
      this._nodes.push(nLfo);
    }

    const noiseLp = ctx.createBiquadFilter();
    noiseLp.type = 'lowpass';
    noiseLp.frequency.value = this.preset.noiseLpFreq;
    // Slow brightness drift so the noise colour keeps shifting over time.
    if (this.preset.noiseSweepRate > 0) {
      const sLfo = ctx.createOscillator();
      sLfo.type = 'sine';
      sLfo.frequency.value = this.preset.noiseSweepRate;
      const sLfoGain = ctx.createGain();
      sLfoGain.gain.value = this.preset.noiseSweepDepth; // added to cutoff, Hz
      sLfo.connect(sLfoGain);
      sLfoGain.connect(noiseLp.frequency);
      sLfo.start();
      this._nodes.push(sLfo);
    }
    noiseLp.Q.value = 0.5;
    noiseGain.connect(noiseLp);

    // Mix oscillators + noise
    const srcMix = ctx.createGain();
    srcMix.gain.value = 1.0;
    oscMix.connect(srcMix);
    noiseLp.connect(srcMix);

    // ── Layer 2: Dual slow resonant filter sweeps ────────────────────────
    // Two bandpass filters with independent LFO-swept cutoffs
    // Filter 1: sweeps 300 – 2100 Hz at 0.023 Hz
    const bp1 = ctx.createBiquadFilter();
    bp1.type = 'bandpass';
    bp1.frequency.value = 1200;
    bp1.Q.value = 4.0;

    const bp1Lfo = ctx.createOscillator();
    bp1Lfo.type = 'sine';
    bp1Lfo.frequency.value = 0.023;
    const bp1LfoGain = ctx.createGain();
    bp1LfoGain.gain.value = 900;
    const bp1Offset = ctx.createConstantSource();
    bp1Offset.offset.value = 1200;
    bp1Lfo.connect(bp1LfoGain);
    bp1LfoGain.connect(bp1Offset.offset);
    bp1Offset.connect(bp1.frequency);
    bp1Lfo.start();
    bp1Offset.start();
    this._nodes.push(bp1Lfo, bp1Offset);

    // Filter 2: sweeps 200 – 1000 Hz at 0.013 Hz
    const bp2 = ctx.createBiquadFilter();
    bp2.type = 'bandpass';
    bp2.frequency.value = 600;
    bp2.Q.value = 3.5;

    const bp2Lfo = ctx.createOscillator();
    bp2Lfo.type = 'sine';
    bp2Lfo.frequency.value = 0.013;
    const bp2LfoGain = ctx.createGain();
    bp2LfoGain.gain.value = 400;
    const bp2Offset = ctx.createConstantSource();
    bp2Offset.offset.value = 600;
    bp2Lfo.connect(bp2LfoGain);
    bp2LfoGain.connect(bp2Offset.offset);
    bp2Offset.connect(bp2.frequency);
    bp2Lfo.start();
    bp2Offset.start();
    this._nodes.push(bp2Lfo, bp2Offset);

    srcMix.connect(bp1);
    srcMix.connect(bp2);

    const filtMix = ctx.createGain();
    filtMix.gain.value = 0.5;
    bp1.connect(filtMix);
    bp2.connect(filtMix);

    // ── Layer 3: Multi-tap delay with shimmer feedback ───────────────────
    // Uses ConvolverNode + DelayNodes to build prime-time multi-tap delay
    // L channel: 1103 ms + 3701 ms taps
    // R channel: 1607 ms + 5303 ms taps
    // Feedback path through a highpass to keep it airy

    const splitter = ctx.createChannelSplitter(2);
    const merger   = ctx.createChannelMerger(2);

    // Mono filtered signal split to L and R delay lines
    filtMix.connect(splitter);

    const makeDelayTap = (inputNode, delayMs, feedbackAmount, hpFreq) => {
      const delay = ctx.createDelay(6.5);
      delay.delayTime.value = delayMs / 1000;

      const hp = ctx.createBiquadFilter();
      hp.type = 'highpass';
      hp.frequency.value = hpFreq;
      hp.Q.value = 0.5;

      const fb = ctx.createGain();
      fb.gain.value = feedbackAmount;

      inputNode.connect(delay);
      delay.connect(hp);
      hp.connect(fb);
      fb.connect(delay); // feedback loop

      return delay;
    };

    // L taps
    const tapL1 = makeDelayTap(filtMix, 1103, 0.48, 350);
    const tapL2 = makeDelayTap(filtMix, 3701, 0.38, 400);
    const sumL  = ctx.createGain();
    sumL.gain.value = 0.5;
    tapL1.connect(sumL);
    tapL2.connect(sumL);
    filtMix.connect(sumL); // dry signal in L

    // R taps
    const tapR1 = makeDelayTap(filtMix, 1607, 0.46, 350);
    const tapR2 = makeDelayTap(filtMix, 5303, 0.36, 400);
    const sumR  = ctx.createGain();
    sumR.gain.value = 0.5;
    tapR1.connect(sumR);
    tapR2.connect(sumR);
    filtMix.connect(sumR); // dry signal in R

    // ── Layer 4: Lo-fi bit-crush simulation ─────────────────────────────
    // Web Audio API has no native bit crusher, so we simulate it with
    // a WaveShaperNode that quantises to ~10-bit depth, plus a post-LP
    const crusher = ctx.createWaveShaper();
    crusher.curve = this._makeCrushCurve(256); // 256 steps ≈ 8 bit
    crusher.oversample = 'none';

    const crushLp = ctx.createBiquadFilter();
    crushLp.type = 'lowpass';
    crushLp.frequency.value = 3500;
    crushLp.Q.value = 0.3;

    const crushWet = ctx.createGain();
    crushWet.gain.value = 0.25;
    const crushDry = ctx.createGain();
    crushDry.gain.value = 0.75;

    sumL.connect(crusher);
    crusher.connect(crushLp);
    crushLp.connect(crushWet);
    sumL.connect(crushDry);

    const crushOut = ctx.createGain();
    crushOut.gain.value = 1.0;
    crushWet.connect(crushOut);
    crushDry.connect(crushOut);

    // ── Final mix to stereo output ───────────────────────────────────────
    // L: delay sumL + crush (mono crush on L)
    // R: delay sumR (pure delay, no crush on R for subtle stereo difference)
    sumL.connect(merger, 0, 0);
    sumR.connect(merger, 0, 1);
    crushOut.connect(merger, 0, 0); // crush adds to L only

    const finalGain = ctx.createGain();
    finalGain.gain.value = 0.65;
    merger.connect(finalGain);
    finalGain.connect(this._master);
  }

  // ── Utilities ─────────────────────────────────────────────────────────────

  // Generates a white noise AudioBufferSourceNode that loops indefinitely
  _makeNoise(ctx) {
    const bufferSize = ctx.sampleRate * 4; // 4 seconds of noise
    const buffer = ctx.createBuffer(1, bufferSize, ctx.sampleRate);
    const data = buffer.getChannelData(0);
    for (let i = 0; i < bufferSize; i++) {
      data[i] = (Math.random() * 2) - 1;
    }
    const source = ctx.createBufferSource();
    source.buffer = buffer;
    source.loop = true;
    source.start();
    this._nodes.push(source);
    return source;
  }

  // Waveshaper curve that quantises signal to `steps` amplitude levels
  // Simulates bit-depth reduction (lo-fi grit) without an actual bit crusher
  _makeCrushCurve(steps) {
    const n = 256;
    const curve = new Float32Array(n);
    for (let i = 0; i < n; i++) {
      const x = (i * 2) / n - 1;
      curve[i] = Math.round(x * steps) / steps;
    }
    return curve;
  }
}

// ── Auto-start on first user interaction ─────────────────────────────────────
// Respects browser autoplay policy: audio context must be created/resumed
// inside a user gesture handler. After first interaction, the soundscape
// runs silently in the background for the remainder of the session.

(function autoInit() {
  // If loaded as a module, export the class and let the consumer handle init
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = TelarisSoundscape;
    return;
  }
  if (typeof window === 'undefined') return;

  // Otherwise auto-start on first interaction
  let soundscape = null;
  const events = ['click', 'touchstart', 'keydown', 'pointerdown'];

  const onFirstInteraction = async () => {
    if (soundscape) return;
    soundscape = new TelarisSoundscape({ volume: 0.65, fadeTime: 4.0 });
    await soundscape.start();
    // Remove all listeners once started
    events.forEach(e => window.removeEventListener(e, onFirstInteraction, { capture: true }));
  };

  events.forEach(e =>
    window.addEventListener(e, onFirstInteraction, { once: false, passive: true, capture: true })
  );

  // Expose globally for manual control if needed
  window.TelarisSoundscape = TelarisSoundscape;
  window._telarisSoundscape = () => soundscape;
})();
