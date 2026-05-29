# MagicLinkLogin

Passwordless login for [Piwigo](https://piwigo.org) via one-time email magic links.

A user enters their email on the login page and receives a single-use link.
Clicking it logs them in immediately — and if the email isn't registered yet, an
account is created automatically. The standard username/password form is left
fully intact; the magic-link form is simply added above it.

## Features

- One-time, time-limited (15 min) login links sent by email
- Auto-registration for first-time emails (uses Piwigo's own `register_user`)
- Works alongside the normal password login — nothing is replaced
- Supports both the classic **default** theme and **standard_pages** (Piwigo 16+)
- Self-contained: no external services or framework dependencies

## Security

- Tokens are 256-bit (`random_bytes(32)`); only their SHA-256 hash is stored
- Tokens are marked used **before** login, preventing replay
- Constant-time hash comparison (`hash_equals`)
- Account-enumeration-safe: identical response whether the email exists or not
- Per-email rate limiting (1 request / 60s, max 3 pending tokens)
- Optional User-Agent binding (`MLL_VERIFY_UA` in `main.inc.php`)
- Open-redirect protection: redirects restricted to the gallery root URL

See [`../../PLAN-magic-link-plugin.md`](../../PLAN-magic-link-plugin.md) for the
full design and threat model.

## Requirements

- Piwigo 14+ (tested on 16)
- PHP 8.1+
- A working mail configuration. On shared hosting, PHP's `mail()` often lands in
  spam — configure SMTP in `local/config/config.inc.php` (e.g. a transactional
  provider) for reliable delivery.

## Installation

1. Copy the `MagicLinkLogin` folder into your Piwigo `plugins/` directory
   (or install the packaged zip via **Admin → Plugins → Manage → Add a plugin**).
2. Activate it in **Admin → Plugins**. The token table is created on activation.
3. Visit the login page — the "Login with email" block appears above the
   password form.

Deactivating leaves the token table in place; uninstalling drops it.

## Configuration

Edit the constants at the top of `main.inc.php`:

| Constant | Default | Purpose |
|----------|---------|---------|
| `MLL_DEV_ALLOWED_EMAILS` | `[]` | If non-empty, only listed emails may request links (safe testing on a live gallery). |
| `MLL_VERIFY_UA` | `true` | Bind a link to the requesting browser. Set `false` if users open email on a different device. |

## Translations

English (UK) ships in `language/en_UK/plugin.lang.php`. To add a locale, copy
that file to `language/<locale>/plugin.lang.php` and translate each value.

## Development

```bash
make up         # start Piwigo + DB + Mailpit (http://localhost, mail at :8025)
make test-unit  # run the PHPUnit unit suite in a dedicated php-cli container
make package    # build dist/MagicLinkLogin.zip (runtime files only)
```

## License

[GPL-2.0-or-later](LICENSE).
