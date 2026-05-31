<?php
/**
 * MagicLinkLogin — Admin settings page.
 *
 * Reached via Administration → Plugins → Magic Link Login, which links to
 * admin.php?page=plugin-MagicLinkLogin. Piwigo's admin/plugin.php includes
 * this file with $template, $conf, $page and the admin session already set up.
 */

if (!defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

global $template, $conf, $page;

// ---------------------------------------------------------------------------
// Handle the settings form submission
// ---------------------------------------------------------------------------
if (isset($_POST['mll_submit'])) {
    check_pwg_token(); // CSRF protection (token rendered in the form)

    $verify_ua = !empty($_POST['mll_verify_ua']);
    conf_update_param('mll_verify_ua', $verify_ua, true);

    $page['infos'][] = l10n('Settings saved.');
}

// ---------------------------------------------------------------------------
// Gather current state for display
// ---------------------------------------------------------------------------
$verify_ua = array_key_exists('mll_verify_ua', $conf)
    ? (bool) $conf['mll_verify_ua']
    : true;

// Effective value actually enforced this request (a constant in
// config.inc.php overrides the stored setting — surface that to the admin).
$ua_overridden_by_constant = defined('MLL_VERIFY_UA') && (bool) MLL_VERIFY_UA !== $verify_ua;

// Mail deliverability hint: is an SMTP relay configured?
$smtp_configured = !empty($conf['smtp_host']);
$sender_email    = !empty($conf['mail_sender_email'])
    ? $conf['mail_sender_email']
    : get_webmaster_mail_address();

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------
$template->set_filename('mll_admin', dirname(__FILE__) . '/admin/settings.tpl');

$template->assign([
    'MLL_VERIFY_UA'      => $verify_ua,
    'MLL_UA_OVERRIDDEN'  => $ua_overridden_by_constant,
    'MLL_SMTP_OK'        => $smtp_configured,
    'MLL_SENDER_EMAIL'   => $sender_email,
    'MLL_TOKEN_MINUTES'  => 15,
    'MLL_FORM_ACTION'    => get_root_url() . 'admin.php?page=plugin-MagicLinkLogin',
    'PWG_TOKEN'          => get_pwg_token(),
]);

$template->assign_var_from_handle('ADMIN_CONTENT', 'mll_admin');
