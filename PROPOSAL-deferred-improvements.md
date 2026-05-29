# Proposal: Deferred Improvements for MagicLinkLogin

This document records improvements that were **investigated but intentionally
NOT implemented** in the publish-readiness PR, because each one changes the
authentication flow or core injection mechanism and needs an explicit decision
plus supervised testing before it ships. Nothing here is a regression in the
current code — these are hardening and maintainability options to weigh before
publishing publicly.

The PR that accompanies this doc only made **safe, reversible** changes:
JS injection simplification, a `Referrer-Policy: no-referrer` header on
`verify.php`, publishing metadata/packaging, LICENSE, and README.

---

## A. Security hardening (needs decision + supervised testing)

### A1. Origin / Referer host check on the handler — *High*

`magic_link_handler.php` accepts the POST and a session CSRF token but does not
verify that the request originated from our own site. CSRF token already blocks
the classic cross-site case, but adding a same-origin check on `Origin` /
`Referer` (when present) is defence-in-depth and cheap.

- **Why deferred:** must be tested against both themes and any reverse-proxy
  setups (Dreamhost) that might strip/rewrite these headers, to avoid locking
  out legitimate users.
- **Sketch:** if `Origin` (or fall back to `Referer`) is present and its host
  ≠ gallery host → reject with the same generic success response (no
  enumeration signal).

### A2. Per-IP rate limit + auto-registration cap/toggle — *High*

Today rate limiting is **per-email, 1 request / 60s, max 3 pending tokens**.
There is no per-IP or global ceiling, so a script iterating many email
addresses could (a) send a burst of emails through the configured SMTP relay
(Brevo free tier = 300/day — exhaustible) and (b) auto-create many accounts.

- **Why deferred:** needs a storage decision (reuse token table vs. a small
  counter table), a sensible threshold, and load testing so real users aren't
  throttled. Auto-registration is a deliberate product feature, so any cap or
  admin toggle is a product decision.
- **Options:**
  - Per-IP token cap (e.g. N requests / hour / IP).
  - Global daily ceiling aligned with the SMTP provider's quota.
  - Admin toggle: `MLL_ALLOW_AUTO_REGISTER` (when off, unknown emails get the
    generic success response but no account is created).

---

## B. Medium / Low findings (from the security re-audit)

| Sev | Area | Note |
|-----|------|------|
| Med | `main.inc.php:97-102` | standard_pages injection uses an `ob_start` callback doing `str_replace('</body>', ...)`. Works, but is fragile if the theme emits no `</body>` or emits it oddly. The prefilter refactor (section C) would remove this. |
| Med | `verify.php` UA binding | `MLL_VERIFY_UA` binds the link to the requesting User-Agent. It is spoofable (low security value) and can break users who open email on a different device. Consider defaulting it **off** for published builds, documented as opt-in. |
| Med | Enumeration timing | Response is content-identical for existing vs. unknown emails (good), but the unknown-email path does less work, so timing *could* differ. Low practical risk; could be evened out if desired. |
| Low | `verify.php` token in URL | Addressed in this PR via `Referrer-Policy: no-referrer`. Tokens are single-use and short-lived, so residual risk (browser history) is minimal. |
| Low | `include/functions.php:105-112` | `mll_is_internal_url()` uses a prefix match against the root URL rather than strict host equality. Prefix match is safe against the protocol-relative and external cases (covered by unit tests), but host-equality parsing would be marginally stricter. |

**Confirmed clean:** SQL access is escaped via `pwg_db_real_escape_string()`;
template output is HTML-escaped (`htmlspecialchars` / `|escape:'html'`); token
comparison is constant-time hash lookup; token is marked used **before** login.

---

## C. Maintainability: server-side injection via Smarty prefilter

The most fragile part of the plugin is how the form gets onto the page. Today:

1. `main.inc.php` appends to `footer_elements` (default theme) **and**
2. registers an `ob_start` callback that string-replaces `</body>`
   (standard_pages), and then
3. `magic_link_form.tpl` ships ~90 lines of JS that **move** the block from the
   page bottom up to its final spot above the password form.

**Proposal:** inject server-side with a Smarty prefilter on the `identification`
template:

```php
$template->set_prefilter('identification', function ($content, $smarty) {
    // insert the block markup immediately before the password <form> in the
    // template source, so it renders in the right place with no client-side move
    return $injected;
});
```

- **Upside:** removes the DOM-move JS entirely (the block renders in place),
  removes the `ob_start`/`</body>` hack, and is far more robust to theme
  reshuffles. The AJAX submit JS would stay; only the *positioning* JS goes.
- **Why deferred:** changes the injection mechanism for both themes at once and
  must be verified visually in default **and** standard_pages (and ideally a
  third-party theme) before replacing the working approach. The attribute order
  of the `<form>` tag differs between themes, so the prefilter must anchor on a
  stable token (e.g. `name="login_form"`) rather than the full tag.

---

## D. Housekeeping

- `template/magic_link_email.tpl` (top level) is **dead** — `pwg_mail` loads
  `template/text/html/` and `template/text/plain/` only. Safe to delete; left in
  place pending owner confirmation (deletion not done autonomously).
- Author identity in `composer.json` / `main.inc.php` (`teknolai` /
  `erik.korme@silverfin.com`) should be confirmed/normalised before publishing.

---

*Generated as part of the publish-readiness review. Items A and C should be
picked up as separate, individually-tested PRs.*
