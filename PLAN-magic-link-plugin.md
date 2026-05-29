# Plan: Piwigo Magic Link Login Plugin

## Context
Build a Piwigo plugin (`MagicLinkLogin`) that adds passwordless login via email magic links. When a user enters their email on the login page, a one-time link is emailed to them. Clicking it logs them in immediately — creating a new account automatically if the email is not yet registered. The plugin adds on top of the existing login form without replacing it.

---

## 1. PHP Libraries & Dependencies

We write the core logic ourselves (~200 lines of PHP) since:
- The key primitives (`random_bytes`, `hash`, `pwg_mail`, `log_user`) are already available
- No framework dependencies are needed
- Keeping it in-tree makes the plugin auditable and self-contained

**Reference for patterns**: [dminischetti/Passless](https://github.com/dminischetti/Passless) — a framework-free PHP magic link implementation. We'll borrow its architectural ideas (token hashing, fingerprint binding, rate limiting) without importing it as a dependency.

---

## 2. Security Best Practices Applied

| Concern | Implementation |
|---------|---------------|
| Token strength | `random_bytes(32)` → 256-bit entropy, statistically unguessable |
| DB compromise | Store `SHA-256(token)` in DB, not the raw token. Raw token only travels in the URL. |
| Replay attacks | Token invalidated (marked used) before `log_user()` is called |
| Token expiry | 15-minute window |
| Rate limiting | Max 3 pending tokens per email; reject new requests if recent token was issued < 60s ago |
| Account enumeration | Identical HTTP response whether email exists or not |
| Open redirect | Only redirect to absolute URLs starting with `get_absolute_root_url()` |
| HTTPS | Magic link URL built with `get_absolute_root_url()` — if site uses HTTPS, link is HTTPS |
| Fingerprint binding | Optionally bind token to User-Agent hash at issue time, verify at click time (admin toggle — can break mobile users checking email on different device) |

**What we explicitly don't change**: Piwigo's existing password hashing (`pwg_password_hash`), session management (`log_user`), and registration (`register_user`) are used as-is. We don't touch the existing login form.

---

## 3. Not Breaking Piwigo's Security Model

- Plugin hooks into `loc_begin_identification` to **add** a new form block — the standard username/password form is untouched
- Authentication completes via Piwigo's own `log_user($user_id, $remember_me)` — same path as normal login
- New user creation uses Piwigo's own `register_user()` — same validation, same group assignment, same admin notification hooks
- Admin can disable the plugin at any time in Plugin Manager and the original login is fully restored
- Token table is isolated from all core Piwigo tables

---

## 4. Development & Testing Setup

### Local development (recommended)

Use the official Docker Compose setup:
```bash
# In your working directory (e.g. ~/Documents/Personal/Piwigo)
curl -O "https://raw.githubusercontent.com/Piwigo/piwigo-docker/refs/heads/main/compose.yaml"
docker compose up -d
# Piwigo available at http://localhost:80
```

Mount the plugin directory as a volume so edits are live:
```yaml
# Add to compose.yaml under piwigo service:
volumes:
  - ./plugins/MagicLinkLogin:/var/www/html/plugins/MagicLinkLogin
```

For email testing locally, add [Mailpit](https://github.com/axllent/mailpit) to the compose stack — it catches all outgoing mail without sending it:
```yaml
mailpit:
  image: axllent/mailpit
  ports:
    - "8025:8025"   # web UI
    - "1025:1025"   # SMTP
```
Then configure Piwigo's SMTP to point to `mailpit:1025`.

### Safe testing on your live gallery

Add a **developer allow-list** constant in `main.inc.php`. When set, magic link requests are only processed for listed emails:
```php
// Remove or set to [] for production
define('MLL_DEV_ALLOWED_EMAILS', ['your@email.com']);
```
This lets you test the full flow on production without exposing it to other users. Remove the constant to open it up.

### GitHub

Create a public repo (e.g. `github.com/yourname/piwigo-magic-link`). The Piwigo plugin ecosystem is open-source and a public repo lets you:
- Eventually submit to [piwigo.org/ext](https://piwigo.org/ext/)
- Get community feedback on security
- Track issues and improvements

---

## 5. Plugin File Structure

```
plugins/MagicLinkLogin/
  main.inc.php                  # Plugin metadata + event handler registration
  maintain.class.php            # DB table create/drop on install/uninstall
  magic_link_handler.php        # POST endpoint: receive email → send magic link
  verify.php                    # GET endpoint: validate token → log in / register
  template/
    magic_link_form.tpl         # Email input form injected into identification page
    magic_link_email.tpl        # Email body template (Smarty)
```

---

## 6. Database Table

```sql
CREATE TABLE IF NOT EXISTS `{prefix}magic_link_tokens` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `user_id`     int(11)      DEFAULT NULL,        -- NULL = email not yet registered
  `email`       varchar(255) NOT NULL,
  `token_hash`  varchar(64)  NOT NULL UNIQUE,     -- SHA-256 of the raw token
  `expires_at`  datetime     NOT NULL,
  `used`        tinyint(1)   NOT NULL DEFAULT 0,
  `ua_hash`     varchar(64)  DEFAULT NULL,        -- SHA-256 of User-Agent (optional binding)
  `created_at`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_token_hash` (`token_hash`),
  KEY `idx_email_expires` (`email`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 7. Core Logic

### Sending a magic link (`magic_link_handler.php`)
1. Validate POST `email` format; reject malformed addresses.
2. Check rate limit: if a token for this email was issued < 60s ago, return generic success (silently drop).
3. Delete expired/used tokens for this email (housekeeping).
4. Look up user: `find_user_by_username_or_email($email)` → `$user_id` or `null`.
5. Generate raw token: `$raw = bin2hex(random_bytes(32))`.
6. Compute `$hash = hash('sha256', $raw)`.
7. Insert row into tokens table.
8. Build URL: `get_absolute_root_url() . 'plugins/MagicLinkLogin/verify.php?token=' . $raw`.
9. Send via `pwg_mail($email, [...], ['filename' => 'magic_link_email', ...])`.
10. Respond with identical JSON `{"status":"ok"}` regardless of whether email existed.

### Verifying a token (`verify.php`)
1. Bootstrap: `require_once '../../include/common.inc.php'`.
2. Read and validate GET `token` (must be 64 hex chars).
3. Compute `$hash = hash('sha256', $token)`.
4. Query: `SELECT * FROM tokens WHERE token_hash = ? AND used = 0 AND expires_at > NOW()`.
5. If no row → show safe "Link expired or already used" error page.
6. Mark token used immediately (before any login/registration).
7. If `user_id` is null → auto-register:
   - Username = unique-ified email prefix (`mll_unique_username()`)
   - Random password: `bin2hex(random_bytes(16))`
   - `register_user($username, $password, $email, false, $errors, false)`
8. Call `log_user($user_id, false)`.
9. Redirect to `get_absolute_root_url()` (or stored redirect if internal).

---

## 8. Critical Piwigo APIs Used

| Function | File | Purpose |
|----------|------|---------|
| `find_user_by_username_or_email()` | `include/functions_user.inc.php` | Email → user lookup |
| `register_user()` | `include/functions_user.inc.php` | Auto-register new users |
| `log_user($id, $remember)` | `include/functions_user.inc.php` | Programmatic login |
| `pwg_mail($to, $args, $tpl)` | `include/functions_mail.inc.php` | Send magic link email |
| `get_absolute_root_url()` | `include/functions.inc.php` | Build absolute magic link URL |
| `add_event_handler()` | `include/functions_plugins.inc.php` | Hook into login page |
| `pwg_query()` / `single_insert()` / `single_update()` | `include/dblayer/…` | DB access |

---

## 9. Testing Strategy

> **Status (implemented):** We ship **Level 1 unit tests only** as the automated
> layer (run via `make test-unit` in a dedicated php-cli container — the Piwigo
> image lacks the `tokenizer` extension PHPUnit needs). The Level 2 integration
> and a later Codeception/Selenium E2E experiment were **intentionally dropped**:
> they added a heavy, brittle toolchain (Selenium, WebDriver) for low return on a
> solo project. Coverage now relies on unit tests for the security-critical pure
> functions + the Level 3 manual smoke checklist + verified-in-production behaviour.

Piwigo has no built-in test harness, so we layer three levels of testing:

### Level 1 — Unit tests (PHPUnit, no Piwigo dependency)

Extract pure functions into `includes/functions.php` (no `require` of Piwigo core). These are fully testable in isolation:

| Function | What to test |
|----------|-------------|
| `mll_validate_email($email)` | Valid/invalid formats, empty string |
| `mll_derive_username($email)` | Email → prefix, special chars stripped |
| `mll_unique_username($base, $existing[])` | Suffix `-2`, `-3` on collision |
| `mll_generate_token()` | Returns 64-char hex string, different each call |
| `mll_is_valid_token_format($str)` | Rejects short/non-hex strings |
| `mll_is_internal_url($url, $root)` | Rejects external redirects |

Setup:
```bash
composer require --dev phpunit/phpunit
# tests/unit/TokenTest.php, EmailTest.php, etc.
vendor/bin/phpunit tests/unit
```

### Level 2 — Integration tests (PHPUnit + real Piwigo DB)

Use the Docker Compose dev environment. A `tests/bootstrap.php` does:
```php
require_once '/var/www/html/include/common.inc.php';
```

Then test actual DB operations:
- Token insert → lookup → expiry
- `find_user_by_username_or_email()` with real data
- Full `magic_link_handler` flow (send step only — email captured by Mailpit)
- Full `verify.php` flow (token → `log_user` → session state)

### Level 3 — Manual / smoke tests (checklist)

Run on the Docker dev instance before each PR merge:

- [ ] Install plugin in admin → Plugin Manager → table created
- [ ] Login page shows "Login with email" section below password form
- [ ] **Existing user**: enter known email → receive email → click link → logged in correctly
- [ ] **New user**: enter unknown email → click link → new account created and logged in
- [ ] **Expired token**: manually set `expires_at` to past → link shows clear error
- [ ] **Replay**: click same link twice → second click shows "already used"
- [ ] **Invalid token**: visit `verify.php?token=garbage` → safe error page, no crash
- [ ] **Rate limit**: send 4 requests rapidly → 4th request silently no-ops
- [ ] **Uninstall**: deactivate plugin → original login form works normally, table dropped cleanly
- [ ] **Dev allow-list**: with `MLL_DEV_ALLOWED_EMAILS` set, other emails silently no-op

---

## 10. Code Review Workflow (vibe coding safety net)

Since this is security-sensitive code, follow this PR-based workflow even when working solo:

### Git workflow
```
main          # stable, deployed code
dev           # working branch
feature/xyz   # one branch per feature or fix
```
Never commit directly to `main`. Always open a PR from a feature branch → `dev`, then `dev` → `main`.

### Before opening a PR
1. Run unit + integration tests — all must pass
2. Run the manual smoke test checklist above
3. Ask Claude to do a **security review** with this prompt:
   > "Review this diff for: SQL injection, XSS, open redirect, session fixation, token handling flaws, type coercion vulnerabilities (`==` vs `===`), and any logic that could allow account takeover."

### Security review checklist (auth-specific)
- [ ] All DB inputs go through `pwg_db_real_escape_string()` or prepared statements
- [ ] Token comparison uses `hash_equals()` (constant-time), not `==`
- [ ] Token marked used **before** `log_user()` is called (prevents race condition)
- [ ] Redirect target validated against `get_absolute_root_url()` before use
- [ ] Email output in templates is HTML-escaped
- [ ] No `die()` or `exit()` in plugin code (locks out Piwigo admin)
- [ ] `register_user()` errors checked and handled before calling `log_user()`

### Merging to main
Only merge to `main` after:
- [ ] Tests pass
- [ ] Security checklist above signed off
- [ ] Smoke-tested on Docker dev instance
