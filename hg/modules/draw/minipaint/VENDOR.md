# Vendored: miniPaint

This directory is a **pinned, self-hosted copy** of the miniPaint web image
editor, embedded by `module_draw` (see `../../module_draw.inc.php`) so users can
draw / paint / create images inside the Hotglue editor.

- **Upstream:** https://github.com/viliusle/miniPaint
- **Version:** v4.14.3 (tag `v4.14.3`, commit `a79733eb803fc97084ef0ee4faa96b031e69e1c0`)
- **Licence:** MIT (see `MIT-LICENSE.txt`; third-party notices in
  `dist/bundle.js.LICENSE.txt`). MIT is compatible with this fork's GPL-3.0.

## What is included (and what was dropped)

Only the prebuilt static app needed to run it:

- `index.html` - the app shell (relative asset paths, so it runs from this subdir).
- `dist/bundle.js` (+ `bundle.js.LICENSE.txt`) - the prebuilt application.
- `images/icons/`, `images/favicon.png`, `images/logo*.{svg,png}` - runtime UI assets.

Dropped from the release (not needed to run, keeps the vendor lean): `src/`,
`tools/`, `webpack.config.js`, `package*.json`, `dist/bundle.js.map`,
`images/preview.{gif,jpg}` (README media), `images/manifest/` (PWA, disabled),
`examples/`, `service-worker.js`.

## Network / sovereignty

Core drawing and PNG/WebP export run **fully client-side, no network**. The
bundle does contain *optional* integrations that reach external services
**only on explicit user action** (e.g. Google Fonts in the Text tool, Pixabay
search, remove.bg, TinyPNG). They are not invoked by our draw -> place flow.
The social-preview `<meta>` tags in `index.html` point at the upstream demo site
but are not fetched when embedded in an iframe.

## Language

`module_draw`'s launcher (`../draw-edit.js`) opens the iframe with `?lang=<code>`
so miniPaint's UI matches the Hotglue editor's active locale. miniPaint reads
this at boot (`Helper.get_url_parameters().lang` -> `AppConfig.LANG`) and bundles
en/es/fr/pt (plus de/it/ja/ko/nl/ru/tr/uk/zh); unknown codes fall back to `en`.
If a future update changes that URL-param behaviour, update `mp_lang()` /
`MP_URL_SUFFIX` use in `../draw-edit.js`.

## How to update

Re-vendor from a newer release tag (do not float):

```
tag=v4.14.3   # set to the new tag
curl -4 -sL -o mp.tgz "https://github.com/viliusle/miniPaint/archive/refs/tags/$tag.tar.gz"
tar xzf mp.tgz
src="miniPaint-${tag#v}"
cp "$src/index.html" "$src/MIT-LICENSE.txt" .
cp "$src/dist/bundle.js" "$src/dist/bundle.js.LICENSE.txt" dist/
cp -r "$src/images/icons/." images/icons/
cp "$src/images/favicon.png" "$src/images/logo.svg" "$src/images/logo-colors.png" images/
```

Then bump the version/commit above and re-test the draw -> place flow.
