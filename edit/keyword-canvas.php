<?php
declare(strict_types=1);

/**
 * Keyword canvas page — editor surface for authoring keyword-to-keyword
 * relationships (positions + discrete named lines) per galaxy.
 *
 * Located under /edit/ rather than /admin/ because both editors (with a
 * galaxy seat) and admins can use it. The convention "anything in /admin/
 * is admin-only" stays clean this way.
 *
 * See `Academia/Projects/Telaris/Features/Keyword canvas/design.md` for the full
 * design rationale.
 */

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../utils/auth.php';
requireEditorOrAdminLogin();

header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: blob: https:; connect-src 'self' https://cdn.jsdelivr.net https://cloudflareinsights.com; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config.php';

$appVersion = trim(@file_get_contents(__DIR__ . '/../VERSION') ?: '0.0.0');
$pdo = getDB();
$apiKey = getDefaultApiKey($pdo);
$currentUserId = $_SESSION['admin_user_id'] ?? null;
$isAdmin = isAdminLoggedIn();

$galaxyId = isset($_GET['galaxy_id']) ? (int) $_GET['galaxy_id'] : 0;
if ($galaxyId <= 0) {
    http_response_code(400);
    echo '<!doctype html><meta charset="utf-8"><title>Keyword canvas</title>';
    echo '<p style="font-family:sans-serif;padding:2rem">Missing <code>?galaxy_id=N</code>.</p>';
    exit();
}

$galaxyInfo = db_get_constellation_by_id($galaxyId);
if (!$galaxyInfo) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><title>Keyword canvas</title>';
    echo '<p style="font-family:sans-serif;padding:2rem">Galaxy not found.</p>';
    exit();
}

// Clusters have no native keywords — canvas does not apply.
if (($galaxyInfo['type'] ?? 'galaxy') === 'cluster') {
    http_response_code(400);
    echo '<!doctype html><meta charset="utf-8"><title>Keyword canvas</title>';
    echo '<p style="font-family:sans-serif;padding:2rem">Clusters have no native keywords; the canvas only applies to galaxies. Open the canvas on a member galaxy instead.</p>';
    exit();
}

// Editors need a seat on this galaxy; admins always pass.
if (!$isAdmin) {
    $seatIds = $currentUserId !== null ? db_get_user_constellation_ids($currentUserId) : [];
    if (!in_array($galaxyId, array_map('intval', $seatIds), true)) {
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><title>Keyword canvas</title>';
        echo '<p style="font-family:sans-serif;padding:2rem">You do not have edit access to this galaxy.</p>';
        exit();
    }
}

$galaxySlug = (string)($galaxyInfo['slug'] ?? '');
// The back link aims at "wherever the user came from." The client-side script
// at the bottom of this page rewrites the href to document.referrer when that
// referrer is same-origin — so admin entry → admin, editor entry → editor,
// and any other origin path "just works." This PHP fallback only matters when
// referrer is empty (direct URL, refresh after a long time, privacy mode).
//
// `?back=visitor` flips the fallback to the visitor wormhole view, so the
// burger-menu "Keyword view" item resolves back to where the visitor came
// from even if their browser drops the Referer header.
$backToVisitor = (($_GET['back'] ?? '') === 'visitor');
if ($backToVisitor) {
    $backUrl = $galaxySlug !== ''
        ? '/' . rawurlencode($galaxySlug)
        : '/?constellation_id=' . $galaxyId;
} else {
    $backUrl = $galaxySlug !== ''
        ? '/edit/?slug=' . rawurlencode($galaxySlug)
        : '/edit/?constellation_id=' . $galaxyId;
}

// Locale detection: use the shared resolver. The supported set is
// PROJECT_INFO_LOCALES; English is the fallback.
$kcLocale = locale_resolve_from_request($_GET['lang'] ?? null, $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);

$kcStrings = [
    'en' => [
        'help_button' => 'Help',
        'help_title' => 'Quick guide',
        'help_purpose' => 'Use this view to map how keywords in this galaxy relate to each other. The closer they are, the stronger their relationship. Drag chips to set their proximity, and draw lines between them to record specific semantic connections.',
        'help_intro' => 'How to use it:',
        'help_move_label' => 'Move a keyword',
        'help_move_body' => 'Drag a chip to reposition it.',
        'help_connect_label' => 'Connect two keywords',
        'help_connect_body' => 'Click an anchor dot on one chip, then click an anchor on another. Or drag from anchor to anchor.',
        'help_edit_label' => 'Edit or delete a line',
        'help_edit_body' => 'Click an existing line to open it.',
        'help_pan_label' => 'Pan the view',
        'help_pan_body' => 'Hold Space and drag, or middle-click and drag.',
        'help_zoom_label' => 'Zoom',
        'help_zoom_body' => 'Use the mouse wheel. Zooms toward the cursor.',
        'help_cancel_label' => 'Cancel',
        'help_cancel_body' => 'Press Esc while drawing a line to cancel.',
        'help_close' => 'Close',
    ],
    'es' => [
        'help_button' => 'Ayuda',
        'help_title' => 'Guía rápida',
        'help_purpose' => 'Usa esta vista para mapear cómo se relacionan entre sí las keywords de esta galaxia. Cuanto más cerca estén, más fuerte es su relación. Arrastra los chips para definir su proximidad y dibuja líneas entre ellos para registrar conexiones semánticas específicas.',
        'help_intro' => 'Cómo usarlo:',
        'help_move_label' => 'Mover una keyword',
        'help_move_body' => 'Arrastra el chip para reubicarlo.',
        'help_connect_label' => 'Conectar dos keywords',
        'help_connect_body' => 'Haz clic en un punto de anclaje de un chip y luego en uno de otro. O arrastra de un punto al otro.',
        'help_edit_label' => 'Editar o eliminar una línea',
        'help_edit_body' => 'Haz clic en una línea existente para abrirla.',
        'help_pan_label' => 'Mover la vista',
        'help_pan_body' => 'Mantén Espacio y arrastra, o arrastra con el botón central del ratón.',
        'help_zoom_label' => 'Zoom',
        'help_zoom_body' => 'Usa la rueda del ratón. El zoom se centra en el cursor.',
        'help_cancel_label' => 'Cancelar',
        'help_cancel_body' => 'Pulsa Esc mientras dibujas una línea para cancelarla.',
        'help_close' => 'Cerrar',
    ],
    'pt' => [
        'help_button' => 'Ajuda',
        'help_title' => 'Guia rápido',
        'help_purpose' => 'Use esta vista para mapear como as palavras-chave desta galáxia se relacionam entre si. Quanto mais próximas estiverem, mais forte é a sua relação. Arraste os chips para definir a proximidade e desenhe linhas entre eles para registar conexões semânticas específicas.',
        'help_intro' => 'Como usar:',
        'help_move_label' => 'Mover uma palavra-chave',
        'help_move_body' => 'Arraste o chip para reposicioná-lo.',
        'help_connect_label' => 'Conectar duas palavras-chave',
        'help_connect_body' => 'Clique num ponto de ancoragem de um chip e depois noutro de outro chip. Ou arraste de um ponto ao outro.',
        'help_edit_label' => 'Editar ou apagar uma linha',
        'help_edit_body' => 'Clique numa linha existente para abri-la.',
        'help_pan_label' => 'Mover a vista',
        'help_pan_body' => 'Segure Espaço e arraste, ou arraste com o botão do meio do rato.',
        'help_zoom_label' => 'Zoom',
        'help_zoom_body' => 'Use a roda do rato. O zoom centra-se no cursor.',
        'help_cancel_label' => 'Cancelar',
        'help_cancel_body' => 'Pressione Esc enquanto desenha uma linha para cancelar.',
        'help_close' => 'Fechar',
    ],
][$kcLocale];
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($kcLocale); ?>">
<head>
    <meta charset="utf-8">
    <title>Keyword canvas — <?php echo htmlspecialchars($galaxyInfo['name'] ?? ''); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../js/tailwind.min.js">
    <script src="../js/tailwind.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.12.10/dist/full.min.css" rel="stylesheet">
    <style>
        html, body { height: 100%; margin: 0; background: #000; color: #e5e5e7; }
        #kc-canvas { display: block; width: 100%; height: 100%; touch-action: none; background: #000; }
        .kc-shell { display: flex; flex-direction: column; height: 100vh; background: #000; }
        .kc-header { flex: 0 0 auto; padding: 0.5rem 1rem; background: #111; border-bottom: 1px solid #1f1f1f; display: flex; align-items: center; gap: 1rem; }
        .kc-header a { color: #93c5fd; }
        .kc-header a:hover { color: #bfdbfe; }
        .kc-header h1 { color: #e5e5e7; }
        .kc-stage { flex: 1 1 auto; position: relative; overflow: hidden; background: #000; }
        .kc-empty { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #9ca3af; font-family: ui-sans-serif, system-ui, sans-serif; }
        .kc-status { font-size: 0.75rem; color: #9ca3af; margin-left: auto; }
        .kc-mobile-block { display: none; }

        /* Anchor pulse during a line draw. When the SVG carries .kc-drawing,
           every anchor dot that isn't on the source chip pulses slowly to
           advertise "you can drop here". The source chip is tagged with
           data-kc-draw-source so its anchors are explicitly excluded. */
        svg.kc-drawing [data-kc-node]:not([data-kc-draw-source="1"]) circle[pointer-events="none"] {
            animation: kc-anchor-pulse 1.4s ease-in-out infinite;
        }
        @keyframes kc-anchor-pulse {
            0%   { opacity: 0.4; }
            50%  { opacity: 1; }
            100% { opacity: 0.4; }
        }

        /* New-line creation flash. The kc-flash class is added to the visible
           line right after the relation lands in state, then removed after the
           animation completes. Uses width/opacity not the colour itself so the
           per-line glow colour keeps reading consistently. */
        line.kc-flash {
            animation: kc-line-flash 720ms ease-out;
        }
        @keyframes kc-line-flash {
            0%   { stroke-opacity: 1; stroke-width: 4; filter: drop-shadow(0 0 10px currentColor); }
            100% { stroke-opacity: 0.6; stroke-width: 1.25; }
        }

        @media (max-width: 767px) {
            .kc-stage { display: none; }
            .kc-mobile-block { display: flex; align-items: center; justify-content: center; padding: 2rem; font-family: ui-sans-serif, system-ui, sans-serif; color: #d1d5db; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="kc-shell">
        <header class="kc-header">
            <a id="kc-back-link" href="<?php echo htmlspecialchars($backUrl); ?>" class="text-sm hover:underline">← Back</a>
            <h1 class="text-base font-semibold">
                Keyword canvas — <?php echo htmlspecialchars($galaxyInfo['name'] ?? ''); ?>
            </h1>
            <button type="button" id="kc-help-btn"
                    class="btn btn-ghost btn-xs text-gray-300 hover:text-white"
                    title="<?php echo htmlspecialchars($kcStrings['help_button']); ?>"
                    aria-label="<?php echo htmlspecialchars($kcStrings['help_button']); ?>">
                ?
            </button>
            <span id="kc-status" class="kc-status">Loading…</span>
        </header>

        <div class="kc-stage">
            <svg id="kc-canvas" xmlns="http://www.w3.org/2000/svg"
                 viewBox="0 0 2000 2000" preserveAspectRatio="xMidYMid meet">
                <!-- Canvas content rendered by /js/keyword-canvas.js -->
            </svg>
            <div id="kc-empty" class="kc-empty" hidden>
                <p>No keywords in this galaxy yet. Add some wormholes with keywords first.</p>
            </div>
        </div>

        <div class="kc-mobile-block">
            <p>Open the keyword canvas on a desktop browser to author keyword relationships. The interactions need a larger screen and a mouse / trackpad.</p>
        </div>
    </div>

    <!-- Note input modal: used for both create-new-relation and edit-existing-note flows. -->
    <dialog id="kc-note-modal" class="modal">
        <div class="modal-box bg-white text-gray-800 max-w-md">
            <h3 id="kc-note-modal-title" class="font-bold text-lg">Relation note</h3>
            <p id="kc-note-modal-pair" class="text-sm text-gray-500 mt-1 font-mono"></p>
            <p class="text-xs text-gray-500 mt-3">Optional editorial framing — what does this relation carry that a shared keyword can't say alone?</p>
            <textarea id="kc-note-modal-input" class="textarea textarea-bordered w-full mt-2 bg-white" rows="3" placeholder="e.g. travel together but aren't interchangeable"></textarea>
            <div class="modal-action">
                <button type="button" id="kc-note-modal-cancel" class="btn btn-ghost">Cancel</button>
                <button type="button" id="kc-note-modal-save" class="btn btn-success">Save</button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Keyword inspector modal: opens when an editor clicks (not drags) a
         chip. Offers rename and delete. Rename detects a same-galaxy name
         collision and opens the conflict modal to choose "change my input"
         vs. "merge into the existing keyword". -->
    <dialog id="kc-keyword-modal" class="modal">
        <div class="modal-box bg-white text-gray-800 max-w-md">
            <h3 class="font-bold text-lg">Keyword</h3>
            <p id="kc-keyword-modal-current" class="text-base font-semibold mt-1 font-mono"></p>
            <p class="text-xs text-gray-500 mt-3">Rename or delete this keyword. Renaming applies everywhere the keyword is used — every wormhole tagged with it, every line on this canvas. Deleting removes the keyword from this galaxy and untags every wormhole that carries it.</p>
            <label for="kc-keyword-modal-input" class="text-xs text-gray-500 mt-3 block">New name</label>
            <input type="text" id="kc-keyword-modal-input"
                   class="input input-bordered w-full mt-1 bg-white font-mono"
                   maxlength="100" autocomplete="off" />
            <p id="kc-keyword-modal-error" class="text-xs text-red-600 mt-2" hidden></p>
            <div class="modal-action">
                <button type="button" id="kc-keyword-modal-cancel" class="btn btn-ghost">Cancel</button>
                <button type="button" id="kc-keyword-modal-delete" class="btn btn-error">Delete</button>
                <button type="button" id="kc-keyword-modal-rename" class="btn btn-success">Rename</button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Rename-conflict modal: opens when the target name is already taken by
         another keyword in the same galaxy. Two paths: change the typed name,
         or merge the source keyword into the existing target (every reference
         to source gets repointed at target; source is deleted). -->
    <dialog id="kc-conflict-modal" class="modal">
        <div class="modal-box bg-white text-gray-800 max-w-md">
            <h3 class="font-bold text-lg">Keyword already exists</h3>
            <p class="text-sm mt-2">
                <span id="kc-conflict-modal-target" class="font-mono font-semibold"></span>
                already exists in this galaxy.
            </p>
            <p class="text-xs text-gray-500 mt-3">
                <strong>Change name</strong>: keep this keyword separate and pick a different name.<br>
                <strong>Merge</strong>: fold this keyword into the existing one — every wormhole tagged with it, every line on the canvas, gets repointed at the existing keyword. This one will be deleted. No undo.
            </p>
            <div class="modal-action">
                <button type="button" id="kc-conflict-modal-change" class="btn btn-ghost">Change name</button>
                <button type="button" id="kc-conflict-modal-merge" class="btn btn-warning">Merge</button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Existing-relation inspector modal: shows pair, author, date, note, and
         (when the user can edit) Edit / Delete actions. -->
    <dialog id="kc-line-modal" class="modal">
        <div class="modal-box bg-white text-gray-800 max-w-md">
            <h3 class="font-bold text-lg">Relation</h3>
            <p id="kc-line-modal-pair" class="text-base font-semibold mt-1 font-mono"></p>
            <p id="kc-line-modal-meta" class="text-xs text-gray-500 mt-1"></p>
            <div id="kc-line-modal-note-wrap" class="mt-3 p-3 bg-gray-50 border border-gray-200 rounded text-sm" hidden>
                <span id="kc-line-modal-note" class="italic"></span>
            </div>
            <p id="kc-line-modal-noauth" class="text-xs text-gray-500 mt-3" hidden>Only the original author or an admin can edit or delete this relation.</p>
            <div class="modal-action">
                <button type="button" id="kc-line-modal-close" class="btn">Close</button>
                <button type="button" id="kc-line-modal-edit" class="btn btn-outline" hidden>Edit note</button>
                <button type="button" id="kc-line-modal-delete" class="btn btn-error" hidden>Delete</button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>

    <!-- Help modal: short usage guide, localized server-side. -->
    <dialog id="kc-help-modal" class="modal">
        <div class="modal-box bg-white text-gray-800 max-w-lg">
            <h3 class="font-bold text-lg"><?php echo htmlspecialchars($kcStrings['help_title']); ?></h3>
            <p class="text-sm text-gray-700 mt-2"><?php echo htmlspecialchars($kcStrings['help_purpose']); ?></p>
            <p class="text-sm text-gray-600 mt-3 font-semibold"><?php echo htmlspecialchars($kcStrings['help_intro']); ?></p>
            <ul class="mt-3 space-y-2 text-sm">
                <li><strong><?php echo htmlspecialchars($kcStrings['help_move_label']); ?>.</strong> <?php echo htmlspecialchars($kcStrings['help_move_body']); ?></li>
                <li><strong><?php echo htmlspecialchars($kcStrings['help_connect_label']); ?>.</strong> <?php echo htmlspecialchars($kcStrings['help_connect_body']); ?></li>
                <li><strong><?php echo htmlspecialchars($kcStrings['help_edit_label']); ?>.</strong> <?php echo htmlspecialchars($kcStrings['help_edit_body']); ?></li>
                <li><strong><?php echo htmlspecialchars($kcStrings['help_pan_label']); ?>.</strong> <?php echo htmlspecialchars($kcStrings['help_pan_body']); ?></li>
                <li><strong><?php echo htmlspecialchars($kcStrings['help_zoom_label']); ?>.</strong> <?php echo htmlspecialchars($kcStrings['help_zoom_body']); ?></li>
                <li><strong><?php echo htmlspecialchars($kcStrings['help_cancel_label']); ?>.</strong> <?php echo htmlspecialchars($kcStrings['help_cancel_body']); ?></li>
            </ul>
            <div class="modal-action">
                <button type="button" id="kc-help-modal-close" class="btn btn-neutral"><?php echo htmlspecialchars($kcStrings['help_close']); ?></button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop"><button>close</button></form>
    </dialog>


    <script>
        // Back link: prefer document.referrer when same-origin — that's literally
        // "the view I came from", regardless of which entry point opened this tab.
        (function () {
            const link = document.getElementById('kc-back-link');
            if (!link || !document.referrer) return;
            try {
                const u = new URL(document.referrer);
                if (u.origin === window.location.origin) {
                    link.href = document.referrer;
                }
            } catch (e) { /* keep the PHP-set fallback */ }
        })();

        // Help modal open/close.
        (function () {
            const btn = document.getElementById('kc-help-btn');
            const dlg = document.getElementById('kc-help-modal');
            const closeBtn = document.getElementById('kc-help-modal-close');
            if (!btn || !dlg) return;
            btn.addEventListener('click', () => dlg.showModal());
            if (closeBtn) closeBtn.addEventListener('click', () => dlg.close());
        })();

        window.TELARIS_KC = Object.freeze({
            API_KEY: <?php echo json_encode($apiKey); ?>,
            GALAXY_ID: <?php echo (int)$galaxyId; ?>,
            GALAXY_NAME: <?php echo json_encode($galaxyInfo['name'] ?? ''); ?>,
            GALAXY_SLUG: <?php echo json_encode($galaxySlug); ?>,
            IS_ADMIN: <?php echo $isAdmin ? 'true' : 'false'; ?>,
            CURRENT_USER_ID: <?php echo json_encode($currentUserId); ?>,
            BACK_URL: <?php echo json_encode($backUrl); ?>,
            APP_VERSION: <?php echo json_encode($appVersion); ?>,
            STRINGS: <?= json_encode([
                'statusLoading' => t('editor_kc_status_loading', 'Loading…'),
                'statusNoKeywords' => t('editor_kc_status_no_keywords', 'No keywords yet'),
                'statusReady' => t('editor_kc_status_ready', 'Ready'),
                'statusSaving' => t('editor_kc_status_saving', 'Saving…'),
                'statusSaved' => t('editor_kc_status_saved', 'Saved'),
                'statusDeleting' => t('editor_kc_status_deleting', 'Deleting…'),
                'statusDeleted' => t('editor_kc_status_deleted', 'Deleted'),
                'statusMerging' => t('editor_kc_status_merging', 'Merging…'),
                'statusMerged' => t('editor_kc_status_merged', 'Merged'),
                'statusRenamed' => t('editor_kc_status_renamed', 'Renamed'),
                'statusAlreadyRelated' => t('editor_kc_status_already_related', 'Already related'),
                'statusDragOrClick' => t('editor_kc_status_drag_or_click', 'Drag to another anchor, or click one (Esc to cancel)'),
                'statusLoadFailed' => t('editor_kc_status_load_failed', 'Load failed: %s'),
                'statusSaveFailed' => t('editor_kc_status_save_failed', 'Save failed: %s'),
                'statusCreateFailed' => t('editor_kc_status_create_failed', 'Create failed: %s'),
                'statusDeleteFailed' => t('editor_kc_status_delete_failed', 'Delete failed: %s'),
                'statusRenameFailed' => t('editor_kc_status_rename_failed', 'Rename failed: %s'),
                'statusMergeFailed' => t('editor_kc_status_merge_failed', 'Merge failed: %s'),
                'statusUpdateFailed' => t('editor_kc_status_update_failed', 'Update failed: %s'),
                'modalTitleNewRelation' => t('editor_kc_modal_title_new_relation', 'New relation'),
                'modalTitleEditRelation' => t('editor_kc_modal_title_edit_relation', 'Edit relation note'),
                'labelAuthoredBy' => t('editor_kc_label_authored_by', 'Authored by %s'),
                'labelNoAuthorRecorded' => t('editor_kc_label_no_author_recorded', 'No author recorded'),
                'labelNoAuthorShort' => t('editor_kc_label_no_author_short', '(no author)'),
                'errEmptyName' => t('editor_kc_err_empty_name', 'Pick a non-empty name.'),
                'errNameTakenGalaxy' => t('editor_kc_err_name_taken_galaxy', 'That name is already taken in this galaxy'),
                'errNameTakenConflict' => t('editor_kc_err_name_taken_conflict', 'That name is already taken; change it or merge.'),
                'errMissingConfig' => t('editor_kc_err_missing_config', 'Page configuration is missing (window.TELARIS_KC)'),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>,
        });
    </script>
    <script src="../js/keyword-canvas.js?v=<?php echo htmlspecialchars($appVersion); ?>"></script>
</body>
</html>
