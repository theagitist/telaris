<?php
declare(strict_types=1);

/**
 * Editor self-enrollment: the transactional emails the feature sends, all on the
 * Telaris brand shell (inc/email-template.php) and in the system-email voice
 * (sentence case, no exclamation, lead with the action, then the link).
 *
 *   send_magic_login_email   - emailed sign-in link (15 min)
 *   send_welcome_email       - first-login welcome (no link; orientation)
 *   send_enroll_confirm_email- confirm a self-enrolment (24 h)
 *   send_vetting_email       - "you can set a password now" after an admin vets (7 d)
 *
 * Copy is keyed by locale (en, es, pt, fr), gender-neutral throughout.
 * enroll_mail_t() falls back to English only when a locale is entirely absent,
 * never per-string at steady state.
 */

require_once __DIR__ . '/email-template.php';
require_once __DIR__ . '/mail.php';

/**
 * Pinned site base URL for email links. Resolved from trusted settings, never the
 * request Host header (which is attacker-controllable and is also empty/localhost
 * when mail is sent from the CLI). Order: site_base_url (explicit override) ->
 * https://telaris_hostname -> Host header (logged last-resort only). Both values
 * resolve through instance_setting_get(), so an operator can set them from the
 * admin Global Settings (system_meta) with the config.php constants as fallback.
 */
function enroll_mail_base_url(): string {
    $baseUrl = function_exists('instance_setting_get') ? instance_setting_get('site_base_url') : '';
    if ($baseUrl !== '' && preg_match('#^https?://#', $baseUrl)) {
        return rtrim($baseUrl, '/');
    }
    $hostname = function_exists('instance_setting_get') ? instance_setting_get('telaris_hostname') : '';
    if ($hostname !== '') {
        return 'https://' . rtrim($hostname, '/');
    }
    error_log('inc/enroll-mail.php: neither site_base_url nor telaris_hostname is set; falling back to Host header (Host-injection risk).');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

/** Human label for this instance in copy: its hostname (e.g. starmaps.polivoxia.ca). */
function enroll_mail_instance_name(): string {
    return (string)preg_replace('#^https?://#', '', enroll_mail_base_url());
}

/**
 * URL of a documentation PDF in the recipient's locale. The docs are centrally
 * hosted on the Pluriverse website (www.telaris.ca/docs), not the instance.
 * Filenames are <slug>.pdf for English and <slug>-{es,pt,fr}.pdf otherwise.
 */
function enroll_mail_doc_url(string $slug, string $locale): string {
    $suffix = in_array($locale, ['es', 'pt', 'fr'], true) ? '-' . $locale : '';
    return 'https://www.telaris.ca/docs/' . $slug . $suffix . '.pdf';
}

/**
 * Localized email copy. %s placeholders are filled with the instance name.
 * Each email: subject, heading, paragraphs[], cta_label, note.
 *
 * @return array<string,array<string,mixed>>
 */
function enroll_mail_copy(): array {
    return [
        'en' => [
            'magic_subject'   => 'Your Telaris sign-in link',
            'magic_heading'   => 'Sign in to Telaris',
            'magic_paras'     => ['You asked for a sign-in link for the Telaris instance at %s. Use the button below to sign in.'],
            'magic_cta'       => 'Sign in',
            'magic_note'      => 'The link works once and expires in 15 minutes. If you did not request it, you can ignore this message; nothing changes until the link is used.',

            'welcome_subject' => 'Welcome to Telaris',
            'welcome_heading' => 'Your editor account is ready',
            'welcome_paras'   => [
                'Your editor account on Telaris is active. You can create and edit wormholes in the galaxies you have been given access to.',
                "Two things worth knowing. Your editorial work is yours: no one reviews or rewrites an editor's keywords or connections. And you edit only the content you are assigned to.",
                'The Editor Manual walks through galaxies, wormholes, keywords, portals, and tours.',
            ],
            'welcome_cta'     => 'Open the editor',
            'welcome_doc_quickstart' => 'Editor Quick Start (PDF)',
            'welcome_doc_manual'     => 'Editor Manual (PDF)',
            'welcome_personal_galaxy' => 'You can view your personal galaxy here:',
            'welcome_explore_instance' => 'You can explore this Telaris here:',
            'welcome_note'    => 'You can sign in any time with an emailed link, or with a password once you have set one.',

            'confirm_subject' => 'Confirm your Telaris editor account',
            'confirm_heading' => 'Confirm your email to finish enrolling',
            'confirm_paras'   => ['This address was used to enrol as an editor on the Telaris instance at %s. Confirm it with the button below to finish setting up your account.'],
            'confirm_cta'     => 'Confirm and continue',
            'confirm_note'    => 'The link works once and expires in 24 hours. If you did not request this, you can ignore this message; the request expires on its own.',

            'vetting_subject' => 'You can now set a Telaris password',
            'vetting_heading' => 'Set a password for faster sign-in',
            'vetting_paras'   => ['An administrator of the Telaris instance at %s has vetted your editor account. You can now set a password, so you no longer need an emailed link each time you sign in.'],
            'vetting_cta'     => 'Set a password',
            'vetting_note'    => 'Setting a password is optional; the emailed sign-in link keeps working either way. This link works once and expires in 7 days.',
        ],
        'es' => [
            'magic_subject'   => 'Tu enlace de acceso a Telaris',
            'magic_heading'   => 'Inicia sesión en Telaris',
            'magic_paras'     => ['Pediste un enlace de acceso para la instancia de Telaris en %s. Usa el botón de abajo para iniciar sesión.'],
            'magic_cta'       => 'Iniciar sesión',
            'magic_note'      => 'El enlace funciona una sola vez y caduca en 15 minutos. Si no lo solicitaste, puedes ignorar este mensaje; nada cambia hasta que se use el enlace.',

            'welcome_subject' => 'Te damos la bienvenida a Telaris',
            'welcome_heading' => 'Tu cuenta de edición está lista',
            'welcome_paras'   => [
                'Tu cuenta de edición en Telaris está activa. Puedes crear y editar agujeros de gusano en las galaxias a las que tienes acceso.',
                'Dos cosas que conviene saber. Tu trabajo editorial es tuyo: nadie revisa ni reescribe las palabras clave o las conexiones de quien edita. Y editas solo el contenido que se te ha asignado.',
                'El Manual de edición recorre galaxias, agujeros de gusano, palabras clave, portales y recorridos.',
            ],
            'welcome_cta'     => 'Abrir el editor',
            'welcome_doc_quickstart' => 'Inicio rápido para editoras (PDF)',
            'welcome_doc_manual'     => 'Manual del editor (PDF)',
            'welcome_personal_galaxy' => 'Puedes ver tu galaxia personal aquí:',
            'welcome_explore_instance' => 'Puedes explorar este Telaris aquí:',
            'welcome_note'    => 'Puedes iniciar sesión en cualquier momento con un enlace enviado por correo, o con una contraseña una vez que la hayas configurado.',

            'confirm_subject' => 'Confirma tu cuenta de edición de Telaris',
            'confirm_heading' => 'Confirma tu correo para terminar de inscribirte',
            'confirm_paras'   => ['Esta dirección se usó para solicitar una cuenta de edición en la instancia de Telaris en %s. Confírmala con el botón de abajo para terminar de configurar tu cuenta.'],
            'confirm_cta'     => 'Confirmar y continuar',
            'confirm_note'    => 'El enlace funciona una sola vez y caduca en 24 horas. Si no lo solicitaste, puedes ignorar este mensaje; la solicitud caduca por sí sola.',

            'vetting_subject' => 'Ya puedes establecer una contraseña en Telaris',
            'vetting_heading' => 'Establece una contraseña para acceder más rápido',
            'vetting_paras'   => ['Quien administra la instancia de Telaris en %s ha validado tu cuenta de edición. Ahora puedes establecer una contraseña, para no necesitar un enlace por correo cada vez que inicies sesión.'],
            'vetting_cta'     => 'Establecer una contraseña',
            'vetting_note'    => 'Establecer una contraseña es opcional; el enlace de acceso por correo sigue funcionando de todas formas. Este enlace funciona una sola vez y caduca en 7 días.',
        ],
        'pt' => [
            'magic_subject'   => 'Seu link de acesso ao Telaris',
            'magic_heading'   => 'Entre no Telaris',
            'magic_paras'     => ['Você pediu um link de acesso para a instância do Telaris em %s. Use o botão abaixo para entrar.'],
            'magic_cta'       => 'Entrar',
            'magic_note'      => 'O link funciona uma única vez e expira em 15 minutos. Se você não solicitou, pode ignorar esta mensagem; nada muda até o link ser usado.',

            'welcome_subject' => 'Boas-vindas ao Telaris',
            'welcome_heading' => 'Sua conta de edição está pronta',
            'welcome_paras'   => [
                'Sua conta de edição no Telaris está ativa. Você pode criar e editar buracos de minhoca nas galáxias às quais recebeu acesso.',
                'Duas coisas que vale a pena saber. Seu trabalho editorial é seu: ninguém revisa nem reescreve as palavras-chave ou as conexões de quem edita. E você edita apenas o conteúdo que lhe foi atribuído.',
                'O Manual de edição percorre galáxias, buracos de minhoca, palavras-chave, portais e percursos.',
            ],
            'welcome_cta'     => 'Abrir o editor',
            'welcome_doc_quickstart' => 'Início rápido para editoras (PDF)',
            'welcome_doc_manual'     => 'Manual do editor (PDF)',
            'welcome_personal_galaxy' => 'Você pode ver sua galáxia pessoal aqui:',
            'welcome_explore_instance' => 'Você pode explorar este Telaris aqui:',
            'welcome_note'    => 'Você pode entrar a qualquer momento com um link enviado por e-mail, ou com uma senha depois de defini-la.',

            'confirm_subject' => 'Confirme sua conta de edição do Telaris',
            'confirm_heading' => 'Confirme seu e-mail para concluir a inscrição',
            'confirm_paras'   => ['Este endereço foi usado para solicitar uma conta de edição na instância do Telaris em %s. Confirme-o com o botão abaixo para concluir a configuração da sua conta.'],
            'confirm_cta'     => 'Confirmar e continuar',
            'confirm_note'    => 'O link funciona uma única vez e expira em 24 horas. Se você não solicitou, pode ignorar esta mensagem; a solicitação expira sozinha.',

            'vetting_subject' => 'Agora você pode definir uma senha no Telaris',
            'vetting_heading' => 'Defina uma senha para entrar mais rápido',
            'vetting_paras'   => ['Quem administra a instância do Telaris em %s validou sua conta de edição. Agora você pode definir uma senha, para não precisar de um link por e-mail toda vez que entrar.'],
            'vetting_cta'     => 'Definir uma senha',
            'vetting_note'    => 'Definir uma senha é opcional; o link de acesso por e-mail continua funcionando de qualquer forma. Este link funciona uma única vez e expira em 7 dias.',
        ],
        'fr' => [
            'magic_subject'   => 'Ton lien de connexion à Telaris',
            'magic_heading'   => 'Connecte-toi à Telaris',
            'magic_paras'     => ["Tu as demandé un lien de connexion pour l'instance Telaris sur %s. Utilise le bouton ci-dessous pour te connecter."],
            'magic_cta'       => 'Se connecter',
            'magic_note'      => "Le lien fonctionne une seule fois et expire dans 15 minutes. Si tu n'en es pas à l'origine, tu peux ignorer ce message; rien ne change tant que le lien n'est pas utilisé.",

            'welcome_subject' => 'Bienvenue sur Telaris',
            'welcome_heading' => "Ton compte d'édition est prêt",
            'welcome_paras'   => [
                "Ton compte d'édition sur Telaris est actif. Tu peux créer et modifier des trous de ver dans les galaxies auxquelles tu as accès.",
                "Deux choses utiles à savoir. Ton travail éditorial t'appartient: personne ne révise ni ne réécrit les mots-clés ou les connexions de la personne qui édite. Et tu modifies uniquement le contenu qui t'est attribué.",
                "Le Manuel d'édition parcourt les galaxies, les trous de ver, les mots-clés, les portails et les visites.",
            ],
            'welcome_cta'     => "Ouvrir l'éditeur",
            'welcome_doc_quickstart' => "Démarrage rapide d'édition (PDF)",
            'welcome_doc_manual'     => "Manuel d'édition (PDF)",
            'welcome_personal_galaxy' => 'Tu peux voir ta galaxie personnelle ici :',
            'welcome_explore_instance' => 'Tu peux explorer ce Telaris ici :',
            'welcome_note'    => 'Tu peux te connecter à tout moment avec un lien envoyé par courriel, ou avec un mot de passe une fois que tu en as défini un.',

            'confirm_subject' => "Confirme ton compte d'édition Telaris",
            'confirm_heading' => "Confirme ton courriel pour terminer l'inscription",
            'confirm_paras'   => ["Cette adresse a été utilisée pour demander un compte d'édition sur l'instance Telaris sur %s. Confirme-la avec le bouton ci-dessous pour terminer la configuration de ton compte."],
            'confirm_cta'     => 'Confirmer et continuer',
            'confirm_note'    => "Le lien fonctionne une seule fois et expire dans 24 heures. Si tu n'en es pas à l'origine, tu peux ignorer ce message; la demande expire d'elle-même.",

            'vetting_subject' => 'Tu peux maintenant définir un mot de passe Telaris',
            'vetting_heading' => 'Définis un mot de passe pour te connecter plus vite',
            'vetting_paras'   => ["La personne qui administre l'instance Telaris sur %s a validé ton compte d'édition. Tu peux maintenant définir un mot de passe, pour ne plus avoir besoin d'un lien par courriel à chaque connexion."],
            'vetting_cta'     => 'Définir un mot de passe',
            'vetting_note'    => "Définir un mot de passe est facultatif; le lien de connexion par courriel continue de fonctionner dans tous les cas. Ce lien fonctionne une seule fois et expire dans 7 jours.",
        ],
    ];
}

/** Fetch a copy value for $locale, falling back to English only if the locale is absent. */
function enroll_mail_t(string $locale, string $key): mixed {
    $all = enroll_mail_copy();
    $bundle = $all[$locale] ?? $all['en'];
    return $bundle[$key] ?? ($all['en'][$key] ?? $key);
}

/** Apply the instance name to a paragraph list (or single string) carrying %s. */
function enroll_mail_fill(mixed $value, string $instance): array {
    $list = is_array($value) ? $value : [$value];
    return array_map(static fn($s) => sprintf((string)$s, $instance), $list);
}

function send_magic_login_email(string $to, string $token, string $locale = 'en'): bool {
    $instance = enroll_mail_instance_name();
    $url = enroll_mail_base_url() . '/utils/login.php?token=' . urlencode($token) . '&purpose=magic_login';
    $rendered = telaris_email_render([
        'heading'    => (string)enroll_mail_t($locale, 'magic_heading'),
        'paragraphs' => enroll_mail_fill(enroll_mail_t($locale, 'magic_paras'), $instance),
        'cta'        => ['label' => (string)enroll_mail_t($locale, 'magic_cta'), 'url' => $url],
        'body_links' => [$instance => $url],
        'note'       => (string)enroll_mail_t($locale, 'magic_note'),
        'locale'     => $locale,
    ]);
    return mail_send($to, (string)enroll_mail_t($locale, 'magic_subject'), $rendered['html'], $rendered['text']);
}

/**
 * First-login welcome. $personalGalaxyUrl, when set, is the absolute URL of the
 * editor's own galaxy: the email then links it ("view your personal galaxy
 * here: host/slug"). When null (no personal galaxy), it links the instance's
 * main 3D view instead so the editor still has somewhere to land.
 */
function send_welcome_email(string $to, string $locale = 'en', ?string $personalGalaxyUrl = null): bool {
    $instance = enroll_mail_instance_name();
    $url = enroll_mail_base_url() . '/edit/';

    $paras = enroll_mail_fill(enroll_mail_t($locale, 'welcome_paras'), $instance);
    $bodyLinks = [];
    if ($personalGalaxyUrl !== null && $personalGalaxyUrl !== '') {
        $display = (string)preg_replace('#^https?://#', '', $personalGalaxyUrl);
        $paras[] = (string)enroll_mail_t($locale, 'welcome_personal_galaxy') . ' ' . $display;
        $bodyLinks[$display] = $personalGalaxyUrl;
    } else {
        // No personal galaxy: point at the instance's main 3D view (its root).
        $rootUrl = enroll_mail_base_url() . '/';
        $paras[] = (string)enroll_mail_t($locale, 'welcome_explore_instance') . ' ' . $instance;
        $bodyLinks[$instance] = $rootUrl;
    }

    // Editor-facing documentation PDFs, in the recipient's locale.
    $docLinks = [
        ['label' => (string)enroll_mail_t($locale, 'welcome_doc_quickstart'), 'url' => enroll_mail_doc_url('editor-quick-start', $locale)],
        ['label' => (string)enroll_mail_t($locale, 'welcome_doc_manual'),     'url' => enroll_mail_doc_url('editor-manual', $locale)],
    ];
    $rendered = telaris_email_render([
        'heading'    => (string)enroll_mail_t($locale, 'welcome_heading'),
        'paragraphs' => $paras,
        'cta'        => ['label' => (string)enroll_mail_t($locale, 'welcome_cta'), 'url' => $url],
        'links'      => $docLinks,
        'body_links' => $bodyLinks,
        'note'       => (string)enroll_mail_t($locale, 'welcome_note'),
        'locale'     => $locale,
    ]);
    return mail_send($to, (string)enroll_mail_t($locale, 'welcome_subject'), $rendered['html'], $rendered['text']);
}

function send_enroll_confirm_email(string $to, string $token, string $locale = 'en'): bool {
    $instance = enroll_mail_instance_name();
    $url = enroll_mail_base_url() . '/utils/enroll.php?token=' . urlencode($token) . '&purpose=enroll_confirm';
    $rendered = telaris_email_render([
        'heading'    => (string)enroll_mail_t($locale, 'confirm_heading'),
        'paragraphs' => enroll_mail_fill(enroll_mail_t($locale, 'confirm_paras'), $instance),
        'cta'        => ['label' => (string)enroll_mail_t($locale, 'confirm_cta'), 'url' => $url],
        'body_links' => [$instance => $url],
        'note'       => (string)enroll_mail_t($locale, 'confirm_note'),
        'locale'     => $locale,
    ]);
    return mail_send($to, (string)enroll_mail_t($locale, 'confirm_subject'), $rendered['html'], $rendered['text']);
}

function send_vetting_email(string $to, string $token, string $locale = 'en'): bool {
    $instance = enroll_mail_instance_name();
    $url = enroll_mail_base_url() . '/utils/reset.php?token=' . urlencode($token) . '&purpose=vetting';
    $rendered = telaris_email_render([
        'heading'    => (string)enroll_mail_t($locale, 'vetting_heading'),
        'paragraphs' => enroll_mail_fill(enroll_mail_t($locale, 'vetting_paras'), $instance),
        'cta'        => ['label' => (string)enroll_mail_t($locale, 'vetting_cta'), 'url' => $url],
        'body_links' => [$instance => $url],
        'note'       => (string)enroll_mail_t($locale, 'vetting_note'),
        'locale'     => $locale,
    ]);
    return mail_send($to, (string)enroll_mail_t($locale, 'vetting_subject'), $rendered['html'], $rendered['text']);
}
