<?php
/**
 * Plugin Name: MagicLinkLogin
 * Version: 1.0.0
 * Description: Passwordless login via one-time email magic links. Works for both existing and new users.
 * Plugin URI: https://github.com/yourname/piwigo-magic-link
 * Author: teknolai
 * Author URI: https://silverfin.com
 * Has Settings: false
 */

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

define('MLL_PATH',    PHPWG_PLUGINS_PATH . 'MagicLinkLogin/');
define('MLL_VERSION', '1.0.0');

// ---------------------------------------------------------------------------
// Token table name (respects Piwigo's configurable table prefix)
// ---------------------------------------------------------------------------
global $prefixeTable;
define('MAGIC_LINK_TOKENS_TABLE', $prefixeTable . 'magic_link_tokens');

// ---------------------------------------------------------------------------
// Developer allow-list — REMOVE or set to [] before going public.
// When non-empty, only the listed email addresses can request magic links.
// This lets you test safely on a live gallery without exposing the feature.
// ---------------------------------------------------------------------------
define('MLL_DEV_ALLOWED_EMAILS', []);

// ---------------------------------------------------------------------------
// Load our pure helper functions (these have no Piwigo dependency and are
// also used by the unit tests).
// ---------------------------------------------------------------------------
require_once MLL_PATH . 'include/functions.php';

// ---------------------------------------------------------------------------
// Hook into the login page to inject the magic-link email form.
// ---------------------------------------------------------------------------
add_event_handler('loc_begin_identification', 'mll_inject_form');

/**
 * Injects the "Login with email" block into the identification page template.
 * Called by Piwigo's event system just before the identification page renders.
 */
function mll_inject_form()
{
    global $template;

    // Only inject on the identification page
    if (script_basename() !== 'identification') {
        return;
    }

    $template->set_filenames([
        'magic_link_block' => MLL_PATH . 'template/magic_link_form.tpl',
    ]);

    $template->assign([
        'MLL_HANDLER_URL' => get_absolute_root_url() . 'plugins/MagicLinkLogin/magic_link_handler.php',
        'MLL_CSRF_TOKEN'  => mll_get_csrf_token(),
    ]);

    // Append our block just before </body> using Piwigo's standard mechanism
    $template->append('footer_elements', $template->parse('magic_link_block', true));
}

/**
 * Returns a per-session CSRF token, generating one if not yet set.
 */
function mll_get_csrf_token()
{
    if (empty($_SESSION['mll_csrf'])) {
        $_SESSION['mll_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['mll_csrf'];
}
