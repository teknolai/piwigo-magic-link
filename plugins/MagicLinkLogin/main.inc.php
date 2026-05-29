<?php
/**
 * Plugin Name: MagicLinkLogin
 * Version: 1.0.0
 * Description: Passwordless login via one-time email magic links. Works for both existing and new users.
 * Plugin URI: https://github.com/teknolai/piwigo-magic-link
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
// UA fingerprint verification (Passless-style binding).
// When true, the magic link is bound to the browser that requested it:
// clicking the link from a different browser/device is rejected.
// Set to false if your users commonly check email on a different device
// than the one they use to browse the gallery.
// ---------------------------------------------------------------------------
if (!defined('MLL_VERIFY_UA')) {
    define('MLL_VERIFY_UA', true);
}

// ---------------------------------------------------------------------------
// Register plugin language strings so |translate / l10n() resolve correctly.
// Falls back to the key (English) if a translation is not found.
// ---------------------------------------------------------------------------
load_language('plugin.lang', MLL_PATH);

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
 * Injects the "Login with email" block into the identification page.
 *
 * Dual injection strategy — needed because Piwigo has two themes with
 * different template structures:
 *
 *   • Default theme: footer.tpl iterates $footer_elements just before </body>,
 *     so appending to that array is sufficient.
 *
 *   • Standard_pages theme (Piwigo 16+): footer.tpl only outputs combined
 *     scripts and has NO $footer_elements slot, so we register an ob_start()
 *     callback to inject the block before </body> directly.
 *     The callback checks whether the block is already present (meaning the
 *     default theme already injected it) and skips if so.
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

    $block_html = $template->parse('magic_link_block', true);

    // Path 1 — default theme: footer.tpl iterates footer_elements before </body>
    $template->append('footer_elements', $block_html);

    // Path 2 — standard_pages: inject via output buffer if Path 1 didn't fire
    ob_start(function (string $buf) use ($block_html): string {
        if (strpos($buf, 'id="mll-block"') !== false) {
            return $buf; // Already present (default theme injected it)
        }
        return str_replace('</body>', $block_html . '</body>', $buf);
    });
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
