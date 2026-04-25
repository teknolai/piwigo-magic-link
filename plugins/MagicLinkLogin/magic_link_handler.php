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

define('PHPWG_ROOT_PATH', '../../');
require_once PHPWG_ROOT_PATH . 'include/common.inc.php';
require_once PHPWG_PLUGINS_PATH . 'MagicLinkLogin/include/functions.php';

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
// 1. CSRF check
// ---------------------------------------------------------------------------
$csrf = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['mll_csrf'] ?? '', $csrf)) {
    mll_error('Invalid request.', 403);
}

// ---------------------------------------------------------------------------
// 2. Validate email format
// ---------------------------------------------------------------------------
$email = trim($_POST['email'] ?? '');
if (!mll_validate_email($email)) {
    // Return ok — don't tell the user the email was invalid (enumeration risk)
    mll_ok();
}

// ---------------------------------------------------------------------------
// 3. Developer allow-list check (remove MLL_DEV_ALLOWED_EMAILS for production)
// ---------------------------------------------------------------------------
if (!empty(MLL_DEV_ALLOWED_EMAILS) && !in_array(strtolower($email), array_map('strtolower', MLL_DEV_ALLOWED_EMAILS), true)) {
    // Silently no-op — looks identical to a successful request
    mll_ok();
}

// ---------------------------------------------------------------------------
// 4. Rate limiting — check if a token was issued for this email in the last 60s
// ---------------------------------------------------------------------------
$rate_check = pwg_db_fetch_assoc(pwg_query(sprintf(
    "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS seconds_ago
       FROM `%s`
      WHERE email = '%s'
        AND used = 0
        AND expires_at > NOW()
      ORDER BY created_at DESC
      LIMIT 1",
    MAGIC_LINK_TOKENS_TABLE,
    pwg_db_real_escape_string($email)
)));

if ($rate_check && mll_is_rate_limited((int) $rate_check['seconds_ago'])) {
    // Issued a token too recently — silently succeed
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
// 6. Look up whether this email belongs to an existing user
// ---------------------------------------------------------------------------
$user = find_user_by_username_or_email($email);
$user_id = $user ? (int) $user['id'] : null;

// ---------------------------------------------------------------------------
// 7. Generate token and store its hash
// ---------------------------------------------------------------------------
$raw_token  = mll_generate_token();
$token_hash = mll_hash_token($raw_token);
$ua_hash    = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');

single_insert(MAGIC_LINK_TOKENS_TABLE, [
    'user_id'    => $user_id,
    'email'      => $email,
    'token_hash' => $token_hash,
    'ua_hash'    => $ua_hash,
    'expires_at' => date('Y-m-d H:i:s', strtotime('+15 minutes')),
]);

// ---------------------------------------------------------------------------
// 8. Build the magic link URL and send the email
// ---------------------------------------------------------------------------
$verify_url = get_absolute_root_url() . 'plugins/MagicLinkLogin/verify.php?token=' . $raw_token;

pwg_mail(
    $email,
    [
        'subject'        => l10n('Your magic login link'),
        'content_format' => 'text/html',
        'mail_title'     => l10n('Sign in to') . ' ' . get_absolute_root_url(),
    ],
    [
        'filename' => 'magic_link_email',
        'dirname'  => MLL_PATH . 'template/',
        'assign'   => [
            'VERIFY_URL'  => $verify_url,
            'EXPIRES_MIN' => 15,
            'GALLERY_URL' => get_absolute_root_url(),
        ],
    ]
);

// ---------------------------------------------------------------------------
// 9. Always return the same success response
// ---------------------------------------------------------------------------
mll_ok();
