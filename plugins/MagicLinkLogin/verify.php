<?php
/**
 * MagicLinkLogin — Token verification & login page.
 *
 * The user lands here by clicking the magic link in their email.
 * Flow:
 *   1. Validate token format
 *   2. Look up token hash in DB (must be unused and not expired)
 *   3. Mark token as used immediately (before any login/registration)
 *   4. Auto-register the user if they are new
 *   5. Log the user in via Piwigo's own log_user()
 *   6. Redirect to the gallery
 */

// Locate Piwigo's root using DOCUMENT_ROOT set by nginx.
// We cannot use __DIR__ or a relative path here because the plugins directory
// is a symlink in the linuxserver/piwigo Docker image: PHP resolves __DIR__
// to the real /config/www/... path, which is in a different tree from Piwigo's
// actual root at the document root. DOCUMENT_ROOT gives the correct unresolved path.
define('PHPWG_ROOT_PATH', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/');

// Fix session cookie path: Piwigo's functions_session.inc.php calls
// session_set_cookie_params(0, cookie_path()) BEFORE session_start(). cookie_path()
// derives the path from $_SERVER['SCRIPT_NAME'], which here is
// /plugins/MagicLinkLogin/verify.php — so the session cookie would be scoped to
// /plugins/MagicLinkLogin/ and silently dropped when the browser follows the
// post-login redirect to /. Override SCRIPT_NAME so cookie_path() returns /,
// meaning the session cookie is valid gallery-wide.
$_SERVER['SCRIPT_NAME'] = '/index.php';

require_once PHPWG_ROOT_PATH . 'include/common.inc.php';
require_once PHPWG_ROOT_PATH . 'include/functions_mail.inc.php';
require_once PHPWG_PLUGINS_PATH . 'MagicLinkLogin/include/functions.php';

// BUG-07: Guard in case the plugin is deactivated but this endpoint is hit directly
if (!defined('MAGIC_LINK_TOKENS_TABLE')) {
    global $prefixeTable;
    define('MAGIC_LINK_TOKENS_TABLE', $prefixeTable . 'magic_link_tokens');
}

// ---------------------------------------------------------------------------
// Helper: render a simple, safe error page and stop execution.
// BUG-17: Removed dead `global $template` — we render raw HTML here, no
//         Piwigo template engine involved.
// BUG-02: Uses mll_gallery_url() instead of get_absolute_root_url() —
//         the latter reads cookie_path() which uses SCRIPT_NAME and returns
//         the plugin endpoint path, not the gallery root.
// ---------------------------------------------------------------------------
function mll_error_page(string $heading, string $body): never
{
    $login_url = mll_gallery_url() . 'identification.php';

    // Use a minimal inline template rather than Piwigo's full page so that
    // this page never crashes even if the theme is broken.
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(400);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
       . '<title>' . htmlspecialchars($heading) . '</title>'
       . '<style>body{font-family:sans-serif;max-width:480px;margin:80px auto;padding:0 20px}'
       . 'h1{font-size:1.4rem}a{color:#0070f3}</style>'
       . '</head><body>'
       . '<h1>' . htmlspecialchars($heading) . '</h1>'
       . '<p>' . htmlspecialchars($body) . '</p>'
       . '<p><a href="' . htmlspecialchars($login_url) . '">← Back to login</a></p>'
       . '</body></html>';
    exit;
}

// ---------------------------------------------------------------------------
// 1. Read and validate the token from the query string
// ---------------------------------------------------------------------------
$raw_token = trim($_GET['token'] ?? '');

if (!mll_is_valid_token_format($raw_token)) {
    mll_error_page(
        'Invalid link',
        'This link is not valid. Please request a new one.'
    );
}

// ---------------------------------------------------------------------------
// 2. Look up the token (by its SHA-256 hash)
// ---------------------------------------------------------------------------
$token_hash = mll_hash_token($raw_token);

$row = pwg_db_fetch_assoc(pwg_query(sprintf(
    "SELECT id, user_id, email, ua_hash
       FROM `%s`
      WHERE token_hash = '%s'
        AND used = 0
        AND expires_at > NOW()
      LIMIT 1",
    MAGIC_LINK_TOKENS_TABLE,
    pwg_db_real_escape_string($token_hash)
)));

if (!$row) {
    mll_error_page(
        'Link expired or already used',
        'Magic links expire after 15 minutes and can only be used once. Please request a new one.'
    );
}

// ---------------------------------------------------------------------------
// 3. Mark the token as used IMMEDIATELY — before doing anything else.
//    This prevents replay attacks even under concurrent requests.
// ---------------------------------------------------------------------------
single_update(
    MAGIC_LINK_TOKENS_TABLE,
    ['used' => 1],
    ['id'   => (int) $row['id']]
);

// ---------------------------------------------------------------------------
// 4. Auto-register if this is a new user (user_id is NULL in the token row)
// ---------------------------------------------------------------------------
$user_id = $row['user_id'] ? (int) $row['user_id'] : null;
$email   = $row['email'];

if ($user_id === null) {
    // Derive a unique username from the email address
    $base_username = mll_derive_username($email);

    // Fetch existing usernames to avoid collisions
    $existing = query2array(
        "SELECT username FROM " . USERS_TABLE,
        null,
        'username'
    );

    $username = mll_unique_username($base_username, $existing);

    // Generate a random password — the user will use magic links to log in
    $random_password = bin2hex(random_bytes(16));

    $errors = [];
    register_user(
        $username,
        $random_password,
        $email,
        false,  // do not notify admin
        $errors,
        false   // do not notify the new user (we just logged them in)
    );

    if (!empty($errors)) {
        // Registration failed — show a safe message without leaking details
        mll_error_page(
            'Could not create account',
            'There was a problem creating your account. Please try again or contact the gallery administrator.'
        );
    }

    // BUG-12: Query by mail_address directly rather than find_user_by_username_or_email()
    // which could match a username that happens to equal another user's email.
    $new_user_row = pwg_db_fetch_assoc(pwg_query(sprintf(
        "SELECT id FROM %s WHERE mail_address = '%s' LIMIT 1",
        USERS_TABLE,
        pwg_db_real_escape_string($email)
    )));

    if (!$new_user_row) {
        mll_error_page(
            'Could not create account',
            'There was a problem creating your account. Please try again or contact the gallery administrator.'
        );
    }
    $user_id = (int) $new_user_row['id'];
}

// ---------------------------------------------------------------------------
// 5. Log the user in using Piwigo's own session management
// ---------------------------------------------------------------------------
log_user($user_id, false /* remember_me = false — magic link is single-use */);

// ---------------------------------------------------------------------------
// 6. Redirect to the gallery (or a pre-stored internal redirect).
//    BUG-02: Use mll_gallery_url() instead of get_absolute_root_url() —
//    the latter reads cookie_path() which is computed from SCRIPT_NAME and
//    returns the plugin endpoint path, not the gallery root.
// ---------------------------------------------------------------------------
$gallery_root = mll_gallery_url();
$redirect     = $gallery_root;

// If the user was trying to visit a specific page before hitting the login
// form, Piwigo stores it in the session — honour it if it's internal.
if (!empty($_SESSION['redirect_to']) && mll_is_internal_url($_SESSION['redirect_to'], $gallery_root)) {
    $redirect = $_SESSION['redirect_to'];
    unset($_SESSION['redirect_to']);
}

redirect($redirect);
