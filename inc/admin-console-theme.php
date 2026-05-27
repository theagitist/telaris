<?php
declare(strict_types=1);

/*
 * Dark "console" chrome for the operator-facing surfaces (admin/, edit/, the
 * login screen).
 *
 * These surfaces are styled with inline Tailwind utility classes plus daisyUI
 * components loaded from a prebuilt CDN stylesheet. This partial ports them
 * onto the brand's dark console register (Void ground, Aurora type, Wormhole
 * mint as the single signal colour) without rewriting ~900 inline class
 * attributes:
 *
 *   1. The host page sets <html data-theme="dark"> so daisyUI's own dark
 *      structural rules apply to every component (btn, input, modal, tab,
 *      table, badge, checkbox, toggle). The [data-theme="dark"] variable
 *      overrides below pull daisyUI's base toward Void and its primary to mint.
 *   2. The .bg-* / .text-* / .border-* overrides remap the raw light-mode
 *      utility classes the components don't cover, across the full numeric
 *      ramp, AND their hover:/focus:/focus-within: state variants (Tailwind
 *      emits those as separate classes, so the base-class rules miss them).
 *   3. Coloured utilities (red/green/amber/...) collapse onto the four-colour
 *      status semantics from the brand book (Palette: operator & admin console).
 *
 * Inline pale-pastel "group" plates on the galaxy/cluster lists are NOT styled
 * here (CSS cannot recolour an arbitrary inline hex per-hue); the $pastelPalette
 * arrays in admin/edit are swapped for dark-console tints at source instead.
 *
 * Canonical spec: Academia vault, Documentation/Brand book/Palette.md.
 * Source-of-truth surface: the Pluriverse's own /dashboard + /admin chrome.
 */
?>
<style>
:root {
  --tel-void: #000000;
  --tel-fg: #e8eef0;
  --tel-fg-60: rgba(232, 238, 240, 0.6);
  --tel-fg-40: rgba(232, 238, 240, 0.4);
  --tel-accent: #00ffcc;
  --tel-accent-40: rgba(0, 255, 204, 0.4);
  --tel-accent-85: rgba(0, 255, 204, 0.85);
  /* Opaque near-Void panels: robust for nested DOM and the loading overlay,
     visually equivalent to the canon's faint white-over-Void lifts. */
  --tel-panel: #0a0a0c;
  --tel-panel-2: #15151a;
  --tel-panel-3: #1f1f26;
  --tel-border: rgba(255, 255, 255, 0.18);
  --tel-border-soft: rgba(255, 255, 255, 0.12);
  /* Four-colour status set */
  --tel-attention: rgba(255, 210, 110, 0.92);
  --tel-live: rgba(140, 220, 150, 0.92);
  --tel-refused: rgba(230, 130, 130, 0.92);
  --tel-terminal: rgba(180, 180, 180, 0.78);
  --tel-info: rgba(140, 200, 220, 0.92);
}

/* --- daisyUI dark base pulled toward Void / Aurora / mint (OKLCH channels) --- */
[data-theme="dark"] {
  --b1: 0% 0 0;            /* base-100  -> Void */
  --b2: 4% 0 0;            /* base-200  -> faint lift */
  --b3: 8% 0 0;            /* base-300  -> hover lift */
  --bc: 94% 0.006 230;     /* base-content -> Aurora white */
  --n: 14% 0 0;            /* neutral (btn-neutral) -> dark panel */
  --nc: 94% 0.006 230;     /* neutral-content -> Aurora */
  --p: 86% 0.17 175;       /* primary -> Wormhole mint */
  --pc: 18% 0 0;           /* primary-content -> near-Void text on mint */
}

/* --- Ground --- */
body.font-sans {
  background: var(--tel-void) !important;
  color: var(--tel-fg);
}

/* =========================================================================
   Backgrounds (full ramp + white). Light surfaces -> opaque dark panels.
   ========================================================================= */
.bg-white, .bg-gray-50, .bg-gray-100 { background-color: var(--tel-panel) !important; }
.bg-gray-200, .bg-gray-300 { background-color: var(--tel-panel-2) !important; }
.bg-gray-400, .bg-gray-500, .bg-gray-600, .bg-gray-700, .bg-gray-800, .bg-gray-900 { background-color: var(--tel-panel-3) !important; }

/* =========================================================================
   Text (full ramp + white). 700-900 = primary, 400-600 = secondary, 300 = dim.
   ========================================================================= */
.text-white, .text-gray-100, .text-gray-200 { color: var(--tel-fg) !important; }
.text-gray-700, .text-gray-800, .text-gray-900 { color: var(--tel-fg) !important; }
.text-gray-500, .text-gray-600 { color: var(--tel-fg-60) !important; }
.text-gray-300, .text-gray-400 { color: var(--tel-fg-40) !important; }

/* =========================================================================
   Borders (full ramp).
   ========================================================================= */
.border-gray-100, .border-gray-200, .border-gray-300, .border-gray-400 { border-color: var(--tel-border-soft) !important; }
.border-gray-500, .border-gray-600, .border-gray-700, .border-gray-800 { border-color: var(--tel-border) !important; }
.divide-gray-100 > :not([hidden]) ~ :not([hidden]),
.divide-gray-200 > :not([hidden]) ~ :not([hidden]) { border-color: var(--tel-border-soft) !important; }

/* =========================================================================
   Hover / focus / focus-within state variants (separate generated classes).
   These are what was "still white on rollover".
   ========================================================================= */
.hover\:bg-gray-50:hover, .hover\:bg-gray-100:hover { background-color: var(--tel-panel-2) !important; }
.hover\:bg-gray-200:hover, .hover\:bg-gray-300:hover, .hover\:bg-gray-600:hover { background-color: var(--tel-panel-3) !important; }
.hover\:text-gray-700:hover, .hover\:text-gray-900:hover, .hover\:text-white:hover { color: var(--tel-fg) !important; }
.hover\:border-gray-400:hover, .hover\:border-gray-500:hover { border-color: var(--tel-border) !important; }

/* =========================================================================
   Mint = the single signal colour (links / primary actions / focus rings).
   ========================================================================= */
.text-blue-500, .text-blue-600, .text-blue-700, .text-blue-800 { color: var(--tel-accent) !important; }
.hover\:text-blue-800:hover { color: var(--tel-accent-85) !important; }
.border-blue-200, .border-blue-300, .border-blue-500 { border-color: var(--tel-accent-40) !important; }
.focus\:border-blue-500:focus, .focus-within\:border-blue-500:focus-within { border-color: var(--tel-accent-85) !important; }
.bg-blue-50, .bg-blue-100, .bg-blue-200 { background-color: rgba(0, 255, 204, 0.06) !important; }
.bg-blue-400, .bg-blue-500, .bg-blue-600 { background-color: rgba(0, 255, 204, 0.18) !important; }
.hover\:bg-blue-200:hover, .hover\:bg-blue-600:hover { background-color: rgba(0, 255, 204, 0.28) !important; }
:focus-visible { outline: 2px solid var(--tel-accent); outline-offset: 2px; }

/* =========================================================================
   Status semantics: collapse the rainbow onto four colours.
   ========================================================================= */
/* Refused (red) */
.text-red-200, .text-red-600, .text-red-700, .text-red-800 { color: var(--tel-refused) !important; }
.hover\:text-red-600:hover { color: var(--tel-refused) !important; }
.border-red-200, .border-red-500, .border-red-800 { border-color: rgba(230, 130, 130, 0.45) !important; }
.bg-red-50, .bg-red-100, .bg-red-900, .bg-red-900\/30 { background-color: rgba(230, 130, 130, 0.2) !important; }
.bg-red-500, .bg-red-600 { background-color: rgba(230, 130, 130, 0.7) !important; }
.hover\:bg-red-600:hover { background-color: rgba(230, 130, 130, 0.85) !important; }
/* Live (green / emerald) */
.text-green-200, .text-green-600, .text-green-700, .text-green-800, .text-emerald-700 { color: var(--tel-live) !important; }
.border-green-200, .border-green-300, .border-emerald-200, .border-emerald-300 { border-color: rgba(140, 220, 150, 0.45) !important; }
.bg-green-50, .bg-green-100, .bg-emerald-50 { background-color: rgba(140, 220, 150, 0.2) !important; }
.bg-green-400, .bg-green-500, .bg-green-600 { background-color: rgba(140, 220, 150, 0.7) !important; }
/* Attention (amber / yellow / orange) */
.text-amber-700, .text-amber-800, .text-yellow-600, .text-yellow-700, .text-yellow-800, .text-orange-600, .text-orange-700 { color: var(--tel-attention) !important; }
.border-amber-200, .border-amber-300, .border-yellow-200, .border-orange-200 { border-color: rgba(255, 210, 110, 0.45) !important; }
.bg-amber-50, .bg-amber-50\/40, .bg-amber-100, .bg-yellow-100, .bg-orange-100 { background-color: rgba(255, 210, 110, 0.2) !important; }
.bg-amber-600, .bg-amber-700, .bg-amber-800 { background-color: rgba(255, 210, 110, 0.7) !important; }
.hover\:bg-amber-700:hover, .hover\:bg-amber-800:hover { background-color: rgba(255, 210, 110, 0.85) !important; }
/* Info / reinstate (cyan / sky / indigo / purple collapse to one quiet tint) */
.text-cyan-700, .text-sky-700, .text-indigo-700, .text-purple-600, .text-purple-700, .text-purple-800 { color: var(--tel-info) !important; }
.border-sky-200, .border-indigo-200, .border-purple-200 { border-color: rgba(140, 200, 220, 0.4) !important; }
.bg-cyan-100, .bg-purple-100, .bg-purple-400 { background-color: rgba(140, 200, 220, 0.18) !important; }

/* =========================================================================
   Keyword chips (content, not status): bright constellation pastel text on a
   faint same-hue plate. One class per pastel; getPastelColor() in edit/ maps a
   keyword hash to an index. Border comes from the markup's border-current/20.
   ========================================================================= */
.tel-kw-0  { color: #fca5a5 !important; background-color: rgba(252, 165, 165, 0.16) !important; }
.tel-kw-1  { color: #fdba74 !important; background-color: rgba(253, 186, 116, 0.16) !important; }
.tel-kw-2  { color: #fcd34d !important; background-color: rgba(252, 211, 77, 0.16) !important; }
.tel-kw-3  { color: #fde047 !important; background-color: rgba(253, 224, 71, 0.16) !important; }
.tel-kw-4  { color: #bef264 !important; background-color: rgba(190, 242, 100, 0.16) !important; }
.tel-kw-5  { color: #86efac !important; background-color: rgba(134, 239, 172, 0.16) !important; }
.tel-kw-6  { color: #6ee7b7 !important; background-color: rgba(110, 231, 183, 0.16) !important; }
.tel-kw-7  { color: #5eead4 !important; background-color: rgba(94, 234, 212, 0.16) !important; }
.tel-kw-8  { color: #67e8f9 !important; background-color: rgba(103, 232, 249, 0.16) !important; }
.tel-kw-9  { color: #7dd3fc !important; background-color: rgba(125, 211, 252, 0.16) !important; }
.tel-kw-10 { color: #93c5fd !important; background-color: rgba(147, 197, 253, 0.16) !important; }
.tel-kw-11 { color: #a5b4fc !important; background-color: rgba(165, 180, 252, 0.16) !important; }
.tel-kw-12 { color: #c4b5fd !important; background-color: rgba(196, 181, 253, 0.16) !important; }
.tel-kw-13 { color: #d8b4fe !important; background-color: rgba(216, 180, 254, 0.16) !important; }
.tel-kw-14 { color: #f0abfc !important; background-color: rgba(240, 171, 252, 0.16) !important; }
.tel-kw-15 { color: #f9a8d4 !important; background-color: rgba(249, 168, 212, 0.16) !important; }
.tel-kw-16 { color: #fda4af !important; background-color: rgba(253, 164, 175, 0.16) !important; }
</style>
