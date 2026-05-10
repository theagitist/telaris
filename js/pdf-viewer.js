/**
 * PDF rich-media renderer. Uses Mozilla PDF.js (vendored under js/vendor/pdfjs/).
 * Loaded on demand the first time a wormhole with a pdf_url opens; the worker
 * is spun up at the same time and reused for subsequent renders.
 */

let pdfjsLib = null;
let pdfjsLoadPromise = null;

/**
 * Resolve the PDF.js library, loading + configuring it once per page.
 */
async function ensurePdfJs() {
    if (pdfjsLib) return pdfjsLib;
    if (!pdfjsLoadPromise) {
        // Resolve the absolute origin so the worker can be loaded across nested paths.
        // Vendored under js/vendor/pdfjs/ as plain .js so the server's default MIME
        // ('application/octet-stream' for .mjs) doesn't break ES module imports.
        const base = `${window.location.origin}/js/vendor/pdfjs/`;
        pdfjsLoadPromise = import(/* @vite-ignore */ base + 'pdf.js').then(mod => {
            mod.GlobalWorkerOptions.workerSrc = base + 'pdf.worker.js';
            pdfjsLib = mod;
            return mod;
        });
    }
    return pdfjsLoadPromise;
}

/**
 * Render every page of a PDF into the given container. Replaces previous content.
 * Cancels in flight if a newer call to renderPdf supersedes this one (token check).
 */
let activeToken = 0;

export async function renderPdf({ pagesEl, statusEl, downloadEl, url }) {
    const myToken = ++activeToken;
    if (!pagesEl) return;
    pagesEl.innerHTML = '';
    if (statusEl) statusEl.textContent = 'Loading PDF…';
    if (downloadEl) downloadEl.href = url;

    let lib;
    try {
        lib = await ensurePdfJs();
    } catch (err) {
        if (statusEl) statusEl.textContent = 'PDF library failed to load.';
        console.error('PDF.js load failed:', err);
        return;
    }
    if (myToken !== activeToken) return; // superseded

    let doc;
    try {
        const loadingTask = lib.getDocument({ url });
        doc = await loadingTask.promise;
    } catch (err) {
        if (myToken !== activeToken) return;
        if (statusEl) statusEl.textContent = 'Couldn\'t open PDF.';
        console.error('PDF load failed for', url, err);
        return;
    }
    if (myToken !== activeToken) return;

    if (statusEl) statusEl.textContent = `Rendering ${doc.numPages} page${doc.numPages === 1 ? '' : 's'}…`;

    // Pixel ratio scaling so text stays crisp on high-DPI screens.
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const wrapWidth = pagesEl.clientWidth || 600;

    for (let i = 1; i <= doc.numPages; i++) {
        if (myToken !== activeToken) return;
        let page;
        try { page = await doc.getPage(i); }
        catch (e) { console.warn('PDF page', i, 'failed:', e); continue; }
        if (myToken !== activeToken) return;

        const viewportRaw = page.getViewport({ scale: 1 });
        const scale = (wrapWidth / viewportRaw.width) * dpr;
        const viewport = page.getViewport({ scale });

        const canvas = document.createElement('canvas');
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        // CSS sizing scales the rendered bitmap back down for display; keeps text crisp.
        canvas.style.width = (viewport.width / dpr) + 'px';
        canvas.style.height = (viewport.height / dpr) + 'px';
        canvas.style.display = 'block';
        canvas.style.margin = '0 auto 0.75rem';
        canvas.style.background = '#fff';
        pagesEl.appendChild(canvas);

        const ctx = canvas.getContext('2d');
        try {
            await page.render({ canvasContext: ctx, viewport }).promise;
        } catch (e) {
            if (myToken !== activeToken) return;
            console.warn('PDF page', i, 'render failed:', e);
        }
    }
    if (myToken !== activeToken) return;
    if (statusEl) statusEl.textContent = doc.numPages === 1 ? '1 page' : `${doc.numPages} pages`;
}

/**
 * Tear down: clears the container and bumps the active token so any in-flight
 * renders abort on their next async boundary.
 */
export function clearPdf({ pagesEl, statusEl }) {
    activeToken++;
    if (pagesEl) pagesEl.innerHTML = '';
    if (statusEl) statusEl.textContent = '';
}
