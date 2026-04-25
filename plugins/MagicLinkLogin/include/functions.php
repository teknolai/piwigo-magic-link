<?php
/**
 * MagicLinkLogin — Pure helper functions.
 *
 * This file has NO dependency on Piwigo's core so it can be loaded by unit
 * tests without bootstrapping the full CMS.
 *
 * All function names are prefixed with mll_ to avoid conflicts with other
 * plugins or Piwigo itself.
 */

// ---------------------------------------------------------------------------
// Email validation
// ---------------------------------------------------------------------------

/**
 * Returns true if $email is a syntactically valid email address.
 */
function mll_validate_email(string $email): bool
{
    return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
}

// ---------------------------------------------------------------------------
// Username derivation
// ---------------------------------------------------------------------------

/**
 * Derives a base username from an email address.
 * Takes the local part (before @), strips non-alphanumeric characters,
 * lowercases it, and trims to 30 characters.
 *
 * Examples:
 *   erik.korme@silverfin.com  →  erikkorme
 *   test+tag@example.com      →  testtag
 */
function mll_derive_username(string $email): string
{
    $local = explode('@', $email)[0];
    $clean = preg_replace('/[^a-z0-9]/i', '', $local);
    return strtolower(substr($clean ?: 'user', 0, 30));
}

/**
 * Returns a username that does not appear in $existing_usernames.
 * Appends -2, -3, … until it finds a free slot.
 *
 * @param string   $base              Candidate username (from mll_derive_username)
 * @param string[] $existing_usernames Already-taken usernames to check against
 */
function mll_unique_username(string $base, array $existing_usernames): string
{
    if (!in_array($base, $existing_usernames, true)) {
        return $base;
    }
    $i = 2;
    while (in_array("{$base}-{$i}", $existing_usernames, true)) {
        $i++;
    }
    return "{$base}-{$i}";
}

// ---------------------------------------------------------------------------
// Token generation & validation
// ---------------------------------------------------------------------------

/**
 * Generates a cryptographically secure random token.
 * Returns a 64-character hex string (32 random bytes).
 */
function mll_generate_token(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * Returns the SHA-256 hash of a raw token.
 * This hash is stored in the database; the raw token only lives in the URL.
 */
function mll_hash_token(string $raw_token): string
{
    return hash('sha256', $raw_token);
}

/**
 * Returns true if $token looks like a valid raw token:
 * exactly 64 hexadecimal characters.
 */
function mll_is_valid_token_format(string $token): bool
{
    return (bool) preg_match('/^[0-9a-f]{64}$/', $token);
}

// ---------------------------------------------------------------------------
// URL safety
// ---------------------------------------------------------------------------

/**
 * Returns true if $url is an absolute URL that starts with $root_url.
 * Used to validate redirect targets and prevent open-redirect attacks.
 *
 * @param string $url      URL to validate (e.g. from a query parameter)
 * @param string $root_url The gallery's absolute root URL (from get_absolute_root_url())
 */
function mll_is_internal_url(string $url, string $root_url): bool
{
    if (empty($url) || empty($root_url)) {
        return false;
    }
    // Normalise both to lowercase for comparison
    return str_starts_with(strtolower($url), strtolower($root_url));
}

// ---------------------------------------------------------------------------
// Rate limiting helpers (pure logic — DB queries happen in the handler)
// ---------------------------------------------------------------------------

/**
 * Returns true if $seconds_since_last is within the minimum gap.
 * Used to decide whether to silently drop a duplicate request.
 *
 * @param int|null $seconds_since_last Seconds elapsed since last token was issued (null = no previous token)
 * @param int      $min_gap_seconds    Minimum required gap (default 60 s)
 */
function mll_is_rate_limited(?int $seconds_since_last, int $min_gap_seconds = 60): bool
{
    if ($seconds_since_last === null) {
        return false;
    }
    return $seconds_since_last < $min_gap_seconds;
}
