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

define('PHPWG_ROOT_PATH', '../../');
require_once PHPWG_ROOT_PATH . 'include/common.inc.php';
require_once PHPWG_PLUGINS_PATH . 'MagicLinkLogin/include/functions.php';

// ---------------------------------------------------------------------------
// Helper: render a simple, safe error page and stop execution
// ---------------------------------------------------------------------------
function mll_error_page(string $heading, string $body): never
{
    global $template;

    $login_url = get_absolute_root_url() . 'identification.php';

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

    // Fetch the newly created user's ID
    $new_user = find_user_by_username_or_email($email);
    if (!$new_user) {
        mll_error_page(
            'Could not create account',
            'There was a problem creating your account. Please try again or contact the gallery administrator.'
        );
    }
    $user_id = (int) $new_user['id'];
}

// ---------------------------------------------------------------------------
// 5. Log the user in using Piwigo's own session management
// ---------------------------------------------------------------------------
log_user($user_id, false /* remember_me = false — magic link is single-use */);

// ---------------------------------------------------------------------------
// 6. Redirect to the gallery (or a pre-stored internal redirect)
// ---------------------------------------------------------------------------
$redirect = get_absolute_root_url();

// If the user was trying to visit a specific page before hitting the login
// form, Piwigo stores it in the session — honour it if it's internal.
if (!empty($_SESSION['redirect_to']) && mll_is_internal_url($_SESSION['redirect_to'], get_absolute_root_url())) {
    $redirect = $_SESSION['redirect_to'];
    unset($_SESSION['redirect_to']);
}

redirect($redirect);
