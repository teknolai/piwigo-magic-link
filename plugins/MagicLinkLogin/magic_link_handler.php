<?php
/**
 * MagicLinkLogin — Magic link request handler.
 *
 * Receives a POST with an email address, generates a one-time token,
 * stores it (hashed) in the DB, and emails the magic link.
 *
 * Always returns the same JSON response regardless of whether the email
 * exists — this prevents attackers from enumerating registered accounts.
 */

// Locate Piwigo's root using DOCUMENT_ROOT set by nginx.
// We cannot use __DIR__ or a relative path here because the plugins directory
// is a symlink in the linuxserver/piwigo Docker image: PHP resolves __DIR__
// to the real /config/www/... path, which is in a different tree from Piwigo's
// actual root at the document root. DOCUMENT_ROOT gives the correct unresolved path.
define('PHPWG_ROOT_PATH', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/');

require_once PHPWG_ROOT_PATH . 'include/common.inc.php';
require_once PHPWG_ROOT_PATH . 'include/functions_mail.inc.php';
require_once PHPWG_PLUGINS_PATH . 'MagicLinkLogin/include/functions.php';

// BUG-07: Guard in case the plugin is deactivated but this endpoint is hit directly
if (!defined('MAGIC_LINK_TOKENS_TABLE')) {
    global $prefixeTable;
    define('MAGIC_LINK_TOKENS_TABLE', $prefixeTable . 'magic_link_tokens');
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// ---------------------------------------------------------------------------
// Generic success response — returned in ALL cases to prevent enumeration
// ---------------------------------------------------------------------------
function mll_ok(): never
{
    echo json_encode(['status' => 'ok']);
    exit;
}

function mll_error(string $message, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

// ---------------------------------------------------------------------------
// 1. CSRF check (BUG-10: also reject if session token is empty)
// ---------------------------------------------------------------------------
$session_token = $_SESSION['mll_csrf'] ?? '';
$csrf          = $_POST['csrf_token'] ?? '';

if (empty($session_token) || !hash_equals($session_token, $csrf)) {
    mll_error('Invalid request.', 403);
}

// ---------------------------------------------------------------------------
// 2. Validate email format
// ---------------------------------------------------------------------------
$email = trim($_POST['email'] ?? '');
if (!mll_validate_email($email)) {
    // Return ok — don't reveal that the email was invalid (enumeration risk)
    mll_ok();
}

// ---------------------------------------------------------------------------
// 3. Developer allow-list check (remove MLL_DEV_ALLOWED_EMAILS for production)
// ---------------------------------------------------------------------------
if (!empty(MLL_DEV_ALLOWED_EMAILS) && !in_array(strtolower($email), array_map('strtolower', MLL_DEV_ALLOWED_EMAILS), true)) {
    mll_ok();
}

// ---------------------------------------------------------------------------
// 4. Rate limiting — check if ANY token (used or not) was issued for this
//    email in the last 60 seconds (BUG-18: removed used=0 filter so a user
//    cannot bypass the limit by immediately clicking their link)
// ---------------------------------------------------------------------------
$rate_check = pwg_db_fetch_assoc(pwg_query(sprintf(
    "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS seconds_ago
       FROM `%s`
      WHERE email = '%s'
      ORDER BY created_at DESC
      LIMIT 1",
    MAGIC_LINK_TOKENS_TABLE,
    pwg_db_real_escape_string($email)
)));

if ($rate_check && mll_is_rate_limited((int) $rate_check['seconds_ago'])) {
    mll_ok();
}

// ---------------------------------------------------------------------------
// 5. Housekeeping — remove expired/used tokens for this email
// ---------------------------------------------------------------------------
pwg_query(sprintf(
    "DELETE FROM `%s`
      WHERE email = '%s'
        AND (used = 1 OR expires_at <= NOW())",
    MAGIC_LINK_TOKENS_TABLE,
    pwg_db_real_escape_string($email)
));

// ---------------------------------------------------------------------------
// 6. Look up whether this email belongs to an existing user.
//    BUG-12: Query by email directly rather than find_user_by_username_or_email()
//    which could match a username that happens to equal another user's email.
// ---------------------------------------------------------------------------
$user_row = pwg_db_fetch_assoc(pwg_query(sprintf(
    "SELECT id FROM %s WHERE mail_address = '%s' LIMIT 1",
    USERS_TABLE,
    pwg_db_real_escape_string($email)
)));
$user_id = $user_row ? (int) $user_row['id'] : null;

// ---------------------------------------------------------------------------
// 7. Generate token and store its SHA-256 hash
// ---------------------------------------------------------------------------
$raw_token  = mll_generate_token();
$token_hash = mll_hash_token($raw_token);
$ua_hash    = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');

// BUG-06: escape values before passing to single_insert()
single_insert(MAGIC_LINK_TOKENS_TABLE, [
    'user_id'    => $user_id,
    'email'      => pwg_db_real_escape_string($email),
    'token_hash' => $token_hash,
    'ua_hash'    => $ua_hash,
    'expires_at' => date('Y-m-d H:i:s', strtotime('+15 minutes')),
]);

// ---------------------------------------------------------------------------
// 8. Build the magic link URL and send the email.
//    BUG-02: Use mll_gallery_url() instead of get_absolute_root_url() —
//    the latter reads cookie_path() which is computed from SCRIPT_NAME and
//    returns the plugin endpoint path, not the gallery root.
// ---------------------------------------------------------------------------
$gallery_url = mll_gallery_url();
$verify_url  = $gallery_url . 'plugins/MagicLinkLogin/verify.php?token=' . rawurlencode($raw_token);

// mail_title intentionally omitted — Piwigo defaults it to $conf['gallery_title']
// (e.g. "Kormes galleri") which is the big bold header in the email, matching the
// look of other Piwigo system emails. mail_subtitle defaults to $args['subject'].
pwg_mail(
    $email,
    [
        'subject' => l10n('Your magic login link'),
    ],
    [
        // BUG-01: pwg_mail appends '/' . $content_type to dirname, so the
        // templates must live at template/text/html/ and template/text/plain/.
        'filename' => 'magic_link_email',
        'dirname'  => MLL_PATH . 'template',
        'assign'   => [
            'VERIFY_URL'  => $verify_url,
            'EXPIRES_MIN' => 15,
            'GALLERY_URL' => $gallery_url,
        ],
    ]
);

// ---------------------------------------------------------------------------
// 9. Always return the same success response
// ---------------------------------------------------------------------------
mll_ok();
