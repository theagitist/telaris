<?php
declare(strict_types=1);

/**
 * First-login consent gate (BACKLOG ^consent-gate-first-login).
 *
 * On an editor's first login the app must present the Terms of Use + Privacy
 * Policy and require explicit consent before granting editor access; refusal
 * blocks entry; consent is persisted with the document VERSION so a later
 * version bump re-prompts. Consent is the stated GDPR/LGPD lawful basis for
 * editor data, so this gate is what makes that basis real rather than
 * aspirational (intake Q34 / Q43).
 *
 * Scope: EDITORS only (USER_TYPE_EDITOR). The operator who provisions the
 * instance effectively sets its terms, so admin/operator logins are not gated.
 *
 * ENFORCED BY DEFAULT (since the Terms/Privacy were finalized to v1.0 on
 * 2026-06-04). Out of the box, every instance gates editors on first login and
 * on a version bump; new installs inherit this with no configuration. An
 * operator may override in config.php or the environment: pin a different
 * version, or define CONSENT_TOS_VERSION / CONSENT_PRIVACY_VERSION as '' in
 * config.php to disable the gate for that instance. With both versions empty the
 * gate is a complete no-op (consent_enforced() is false).
 *
 * Hybrid content model (operator decision 2026-06-04): the in-app consent page
 * shows a short localized SUMMARY plus links to the full Terms + Privacy on the
 * public site, and binds acceptance to a version constant. The summary lives in
 * this file (not in operator-editable project_info) because it is version-bound
 * content that an operator must not silently rewrite; it pairs with the versions
 * below and should be finalized together with the published documents.
 */

require_once __DIR__ . '/db.php';

// --- Document version + URL configuration -------------------------------
// The gate is ON by default: both documents enforce their current published
// version (1.0, effective 2026-06-04). This default must track the version of
// the published Terms/Privacy on www.telaris.ca; bump it here in lockstep when
// those documents are revised, which re-prompts editors. An operator may
// override in config.php (or the container environment): set a different
// version to pin one, or define the constant as '' in config.php to disable
// the gate for that instance (operator sovereignty). An EMPTY version string
// means that document is not enforced.
if (!defined('CONSENT_TOS_VERSION'))     define('CONSENT_TOS_VERSION', (string)(getenv('CONSENT_TOS_VERSION') ?: '1.0'));
if (!defined('CONSENT_PRIVACY_VERSION')) define('CONSENT_PRIVACY_VERSION', (string)(getenv('CONSENT_PRIVACY_VERSION') ?: '1.0'));

// The full public documents the in-app summary links to (hybrid model).
if (!defined('CONSENT_TOS_URL'))     define('CONSENT_TOS_URL', (string)(getenv('CONSENT_TOS_URL') ?: 'https://www.telaris.ca/terms'));
if (!defined('CONSENT_PRIVACY_URL')) define('CONSENT_PRIVACY_URL', (string)(getenv('CONSENT_PRIVACY_URL') ?: 'https://www.telaris.ca/privacy'));

const CONSENT_DOC_TOS = 'tos';
const CONSENT_DOC_PRIVACY = 'privacy';

/**
 * The documents currently enforced, as [documentType => version]. Only
 * documents with a non-empty version appear. Empty array => gate inert.
 *
 * @return array<string, string>
 */
function consent_required_documents(): array {
    $docs = [];
    if (CONSENT_TOS_VERSION !== '')     $docs[CONSENT_DOC_TOS] = CONSENT_TOS_VERSION;
    if (CONSENT_PRIVACY_VERSION !== '') $docs[CONSENT_DOC_PRIVACY] = CONSENT_PRIVACY_VERSION;
    return $docs;
}

/** True when at least one document is enforced. */
function consent_enforced(): bool {
    return consent_required_documents() !== [];
}

/** Public URL for a document type (for the in-app links). */
function consent_document_url(string $documentType): string {
    return $documentType === CONSENT_DOC_PRIVACY ? CONSENT_PRIVACY_URL : CONSENT_TOS_URL;
}

/**
 * True when $userId still owes consent: enforcement is on AND they have not
 * accepted the current version of every required document. Inert (false) when
 * nothing is enforced. $required defaults to the configured documents; tests
 * pass an explicit map to exercise version-bump logic without redefining the
 * process-global version constants.
 *
 * @param array<string,string>|null $required documentType => version
 */
function consent_user_needs(string $userId, ?array $required = null): bool {
    $required ??= consent_required_documents();
    if ($required === []) return false;
    if ($userId === '') return false;
    $accepted = db_get_user_accepted_consents($userId);
    foreach ($required as $docType => $version) {
        if (empty($accepted[$docType][$version])) return true;
    }
    return false;
}

/**
 * Record $userId's acceptance of the current version of every enforced
 * document. Idempotent. Returns true if all records were written.
 */
function consent_record_all(string $userId): bool {
    $ok = true;
    foreach (consent_required_documents() as $docType => $version) {
        if (!db_record_user_consent($userId, $docType, $version)) $ok = false;
    }
    return $ok;
}

/**
 * Gate to call right after the auth check on every editor-surface entry point.
 * If the current session is an EDITOR who still owes consent, redirect to the
 * consent page and exit. No-op for admins, for non-enforced state, and for
 * editors who are already current. $basePath is the prefix to reach utils/
 * from the caller (e.g. '../' from edit/).
 */
function consent_gate_or_redirect(string $basePath = '../'): void {
    if (!consent_enforced()) return;
    if (!defined('USER_TYPE_EDITOR')) return; // auth.php not loaded; nothing to gate
    $type = isset($_SESSION['admin_user_type']) ? (int)$_SESSION['admin_user_type'] : -1;
    if ($type !== USER_TYPE_EDITOR) return; // editors only
    $userId = (string)($_SESSION['admin_user_id'] ?? '');
    if ($userId !== '' && consent_user_needs($userId)) {
        header('Location: ' . $basePath . 'utils/consent.php');
        exit();
    }
}

/**
 * Localized strings for the consent page. Self-contained 4-locale map (EN/ES/
 * PT/FR all present), so there is never an English-only fallback at render
 * (decolonial-identifier rule). Gender-neutral throughout; tú (ES), você (PT),
 * tu (FR). The summary is version-bound content to finalize with the docs.
 *
 * @return array<string, array<string, string>>
 */
function consent_strings(): array {
    return [
        'en' => [
            'page_title'   => 'Review and accept - Telaris',
            'heading'      => 'Before you continue',
            'intro'        => 'To use the editor, please review how Telaris handles your information and agree to the Terms of Use and the Privacy Policy. Your agreement is recorded together with the document version.',
            'summary'      => 'Telaris is an archive run by its editing community. The instance stores the account your operator created for you (your name, your email, and any pronouns you provide) and the content you publish. Your editorial work is yours; it is not analyzed by automated systems. The full Terms of Use and Privacy Policy explain what is collected, why, how long it is kept, and your choices, including withdrawing and removing your content.',
            'read_full'    => 'Read the full documents:',
            'tos_label'    => 'Terms of Use',
            'privacy_label'=> 'Privacy Policy',
            'versions_intro' => 'You are agreeing to these versions:',
            'agree_label'  => 'I have read and agree to the Terms of Use and the Privacy Policy.',
            'accept_button'=> 'Accept and continue',
            'decline_button'=> 'Decline and sign out',
            'decline_note' => 'If you decline, you will be signed out. You can return and accept at any time to use the editor.',
            'error_must_agree' => 'Please confirm that you agree to the Terms of Use and the Privacy Policy to continue.',
            'error_invalid_request' => 'Invalid request. Please reload the page and try again.',
        ],
        'es' => [
            'page_title'   => 'Revisar y aceptar - Telaris',
            'heading'      => 'Antes de continuar',
            'intro'        => 'Para usar el editor, revisa cómo Telaris trata tu información y acepta las Condiciones de Uso y la Política de Privacidad. Tu aceptación se registra junto con la versión del documento.',
            'summary'      => 'Telaris es un archivo gestionado por su comunidad de edición. La instancia guarda la cuenta que tu operador creó para ti (tu nombre, tu correo y los pronombres que indiques) y el contenido que publicas. Tu trabajo editorial es tuyo; no lo analizan sistemas automáticos. Las Condiciones de Uso y la Política de Privacidad completas explican qué se recopila, por qué, cuánto tiempo se conserva y tus opciones, incluido retirar y eliminar tu contenido.',
            'read_full'    => 'Lee los documentos completos:',
            'tos_label'    => 'Condiciones de Uso',
            'privacy_label'=> 'Política de Privacidad',
            'versions_intro' => 'Aceptas estas versiones:',
            'agree_label'  => 'He leído y acepto las Condiciones de Uso y la Política de Privacidad.',
            'accept_button'=> 'Aceptar y continuar',
            'decline_button'=> 'Rechazar y cerrar sesión',
            'decline_note' => 'Si rechazas, se cerrará tu sesión. Puedes volver y aceptar cuando quieras para usar el editor.',
            'error_must_agree' => 'Confirma que aceptas las Condiciones de Uso y la Política de Privacidad para continuar.',
            'error_invalid_request' => 'Solicitud no válida. Vuelve a cargar la página e inténtalo de nuevo.',
        ],
        'pt' => [
            'page_title'   => 'Revisar e aceitar - Telaris',
            'heading'      => 'Antes de continuar',
            'intro'        => 'Para usar o editor, revise como o Telaris trata as suas informações e aceite os Termos de Uso e a Política de Privacidade. A sua aceitação é registrada junto com a versão do documento.',
            'summary'      => 'O Telaris é um arquivo mantido pela sua comunidade de edição. A instância guarda a conta que o seu operador criou para você (o seu nome, o seu e-mail e os pronomes que você informar) e o conteúdo que você publica. O seu trabalho editorial é seu; não é analisado por sistemas automáticos. Os Termos de Uso e a Política de Privacidade completos explicam o que é coletado, por quê, por quanto tempo é mantido e as suas opções, incluindo retirar e remover o seu conteúdo.',
            'read_full'    => 'Leia os documentos completos:',
            'tos_label'    => 'Termos de Uso',
            'privacy_label'=> 'Política de Privacidade',
            'versions_intro' => 'Você aceita estas versões:',
            'agree_label'  => 'Li e aceito os Termos de Uso e a Política de Privacidade.',
            'accept_button'=> 'Aceitar e continuar',
            'decline_button'=> 'Recusar e sair',
            'decline_note' => 'Se você recusar, a sua sessão será encerrada. Você pode voltar e aceitar quando quiser para usar o editor.',
            'error_must_agree' => 'Confirme que você aceita os Termos de Uso e a Política de Privacidade para continuar.',
            'error_invalid_request' => 'Solicitação inválida. Recarregue a página e tente novamente.',
        ],
        'fr' => [
            'page_title'   => 'Vérifier et accepter - Telaris',
            'heading'      => 'Avant de continuer',
            'intro'        => "Pour utiliser l'éditeur, vérifie comment Telaris traite tes informations et accepte les Conditions d'utilisation et la Politique de confidentialité. Ton acceptation est enregistrée avec la version du document.",
            'summary'      => "Telaris est une archive gérée par sa communauté d'édition. L'instance conserve le compte que ton opérateur a créé pour toi (ton nom, ton adresse e-mail et les pronoms que tu indiques) ainsi que le contenu que tu publies. Ton travail éditorial t'appartient ; il n'est pas analysé par des systèmes automatiques. Les Conditions d'utilisation et la Politique de confidentialité complètes expliquent ce qui est collecté, pourquoi, pendant combien de temps, et tes choix, y compris le retrait et la suppression de ton contenu.",
            'read_full'    => 'Lis les documents complets :',
            'tos_label'    => "Conditions d'utilisation",
            'privacy_label'=> 'Politique de confidentialité',
            'versions_intro' => 'Tu acceptes ces versions :',
            'agree_label'  => "J'ai lu et j'accepte les Conditions d'utilisation et la Politique de confidentialité.",
            'accept_button'=> 'Accepter et continuer',
            'decline_button'=> 'Refuser et se déconnecter',
            'decline_note' => 'Si tu refuses, ta session sera fermée. Tu peux revenir et accepter à tout moment pour utiliser l\'éditeur.',
            'error_must_agree' => "Confirme que tu acceptes les Conditions d'utilisation et la Politique de confidentialité pour continuer.",
            'error_invalid_request' => 'Requête invalide. Recharge la page et réessaie.',
        ],
    ];
}

/** Localized consent-page string for $locale, falling back to the key itself
 *  (never to English) when absent, per the decolonial-identifier rule. */
function consent_t(string $key, string $locale): string {
    $all = consent_strings();
    $loc = isset($all[$locale]) ? $locale : 'en';
    return $all[$loc][$key] ?? $key;
}

/** Human label for a document type in $locale. */
function consent_document_label(string $documentType, string $locale): string {
    return consent_t($documentType === CONSENT_DOC_PRIVACY ? 'privacy_label' : 'tos_label', $locale);
}
