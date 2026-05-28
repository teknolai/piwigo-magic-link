
You are doing a QA audit of a Piwigo PHP plugin called MagicLinkLogin. The plugin adds passwordless magic link login to a Piwigo photo gallery. Read every plugin file carefully and identify all bugs, mistakes, and issues. Do NOT fix anything — just report findings.

## Context on known symptoms
1. The magic link email arrives in Mailpit but has no clickable link. Instead the body shows: "Sign in to http://localhost/plugins/MagicLinkLogin/ / Your magic login link" — this suggests `get_absolute_root_url()` is returning the wrong URL (the handler's path instead of the gallery root), and the Smarty template variables may not be rendering.
2. The plugin is running inside a Docker setup where the plugins directory is a symlink: `/app/www/public/plugins` → `/config/www/plugins`. PHP resolves `__DIR__` to the real path, so `PHPWG_ROOT_PATH` is set via `$_SERVER['DOCUMENT_ROOT']` instead.

## Piwigo APIs to verify against (fetched from source)
- `pwg_mail($to, $args, $tpl)` — `$tpl` is an array with keys: `filename` (no extension), `dirname` (directory containing the .tpl file), `assign` (variables to assign to Smarty). The function calls `$template->set_filenames()` and `$template->assign()` internally.
- `get_absolute_root_url()` — Returns the gallery's absolute root URL. It is computed from `$_SERVER` variables, so when called from a plugin endpoint file (not the gallery root), it may return the wrong path.
- `add_event_handler('loc_begin_identification', 'mll_inject_form')` — fires before the identification page renders.
- Smarty template variables in `.tpl` files use `{$VARIABLE_NAME}` syntax.

## Files to read and audit

Read all of these files:
1. /Users/erik.korme/Documents/Personal/Piwigo/plugins/MagicLinkLogin/main.inc.php
2. /Users/erik.korme/Documents/Personal/Piwigo/plugins/MagicLinkLogin/maintain.class.php
3. /Users/erik.korme/Documents/Personal/Piwigo/plugins/MagicLinkLogin/include/functions.php
4. /Users/erik.korme/Documents/Personal/Piwigo/plugins/MagicLinkLogin/magic_link_handler.php
5. /Users/erik.korme/Documents/Personal/Piwigo/plugins/MagicLinkLogin/verify.php
6. /Users/erik.korme/Documents/Personal/Piwigo/plugins/MagicLinkLogin/template/magic_link_form.tpl
7. /Users/erik.korme/Documents/Personal/Piwigo/plugins/MagicLinkLogin/template/magic_link_email.tpl

Also fetch the actual Piwigo pwg_mail source to verify how $tpl works:
- https://raw.githubusercontent.com/Piwigo/Piwigo/master/include/functions_mail.inc.php

And check get_absolute_root_url():
- https://raw.githubusercontent.com/Piwigo/Piwigo/master/include/functions.inc.php (search for get_absolute_root_url)

## What to look for
- Incorrect `pwg_mail()` usage (wrong $tpl array structure, wrong template path)
- `get_absolute_root_url()` returning wrong value when called from a plugin endpoint
- Smarty template syntax errors
- Missing `l10n()` definitions or incorrect translation calls
- CSRF token injection: does `mll_inject_form()` correctly embed the token in the Smarty template?
- The `footer_elements` append mechanism — is that the right way to inject into the identification page?
- `find_user_by_username_or_email()` — does it accept an email address or only a username?
- `log_user()` call in verify.php — correct signature?
- `register_user()` call — correct parameter order and types?
- `never` return type on `mll_error_page()` — is this supported by the PHP version in the Docker image?
- Any security issues not previously caught
- Any other bugs

## Output format
For each issue found, report:
- **File and line number**
- **Severity**: Critical (breaks functionality) / Medium (wrong behaviour) / Low (minor)  
- **Description** of the bug
- **What the fix should be**

Be thorough. Read every line.

Here is the complete audit report for the MagicLinkLogin plugin.

---

# MagicLinkLogin Plugin — QA Audit Report

---

## CRITICAL BUGS

---

### BUG-01 — Wrong template directory structure for `pwg_mail()`

**File:** `magic_link_handler.php`, lines 136–144  
**Severity:** Critical (email arrives with no rendered template content)

`pwg_mail()` appends the content-type string (`'text/html'` or `'text/plain'`) as a subdirectory to whatever `dirname` you pass:

```
$template->set_template_dir($tpl['dirname'] . '/' . $content_type);
```

This means with `'dirname' => MLL_PATH . 'template/'`, Piwigo looks for the template file at:

```
.../plugins/MagicLinkLogin/template/text/html/magic_link_email.tpl
```

That path does not exist. The template file is actually at:

```
.../plugins/MagicLinkLogin/template/magic_link_email.tpl
```

When `templateExists()` returns false, `pwg_mail()` falls through without rendering the custom template. The email is sent using the raw `$args` content instead, which explains the symptom of the email body showing `"Sign in to http://localhost/… / Your magic login link"` — that is `mail_title` concatenated with `subject`, not the template.

**Fix:** Create subdirectories `template/text/html/` and `template/text/plain/` and place copies of the template in each, OR pass `'dirname' => MLL_PATH . 'template'` (no trailing slash) and remove the subdirectory prefix — but the real fix is to match what Piwigo expects: the `.tpl` file must live at `{dirname}/{content_type}/magic_link_email.tpl`, so create:

- `template/text/html/magic_link_email.tpl`
- `template/text/plain/magic_link_email.tpl`

---

### BUG-02 — `get_absolute_root_url()` returns the wrong URL in plugin endpoint files

**File:** `magic_link_handler.php`, lines 127, 134, 142; `verify.php`, lines 32, 154, 158  
**Severity:** Critical (magic link points to wrong URL; redirect after login goes wrong)

`get_absolute_root_url()` appends `cookie_path()` to the hostname. `cookie_path()` is derived from `PHPWG_ROOT_PATH`. In normal gallery pages, `PHPWG_ROOT_PATH` is `'./'` (set in `index.php`) and `$page['root_path']` corrects the relative depth. However, in the plugin endpoint files (`magic_link_handler.php` and `verify.php`), `PHPWG_ROOT_PATH` is set to `$_SERVER['DOCUMENT_ROOT'] . '/'` — an absolute filesystem path, not a web path. The `cookie_path()` function uses `PHPWG_ROOT_PATH` to compute the cookie/URL path, and `$page['root_path']` is never populated in these standalone endpoint files because `section_init.inc.php` is not included.

The result is that `get_absolute_root_url()` returns something like `http://localhost/app/www/public/` instead of `http://localhost/`. This is the root cause of the symptom described: the link URL contains the handler's path instead of the gallery root.

**Fix:** In both `magic_link_handler.php` and `verify.php`, after `include/common.inc.php` is loaded, explicitly set `$page['root_path']` to the correct gallery root before calling `get_absolute_root_url()`. The cleanest fix is to read the configured `$conf['gallery_url']` if set, or to hardcode the URL root relative to `DOCUMENT_ROOT`. Alternatively, compute the verify URL inside `main.inc.php` (where the gallery context is correct) and pass it to the handler via a different mechanism.

---

### BUG-03 — `register_user()` return value is ignored; new user ID fetch is redundant and fragile

**File:** `verify.php`, lines 118–143  
**Severity:** Critical (race condition — could log in a different user with the same email)

`register_user()` returns the new user's integer ID on success, or `false` on failure. The code ignores this return value and instead calls `find_user_by_username_or_email($email)` immediately after to get the ID. This is a race condition: if two users with the same email somehow trigger registration simultaneously (possible between the `find_user_by_username_or_email` check at line 106 in the handler and the registration in verify.php), the second call could return a different user's ID.

Also the `$errors` check at line 127 is checking the right variable, but since `register_user()` returns `false` on failure rather than populating `$errors` in all cases, the check on `$errors` alone is not reliable — the return value of `false` must also be checked.

**Fix:**

```
$user_id = register_user($username, $random_password, $email, false, $errors, false);if ($user_id === false || !empty($errors)) {    mll_error_page(...);}// $user_id is now already correct — no need to call find_user_by_username_or_email again
```

---

### BUG-04 — `mll_inject_form()` uses wrong Smarty injection mechanism

**File:** `main.inc.php`, line 64  
**Severity:** Critical (magic link form never appears on login page)

The code does:

```
$template->append('footer_elements', $template->parse('magic_link_block', true));
```

`footer_elements` is a generic page-footer slot — it is not part of the identification page template. The identification page template (`identification.tpl`) has a specific block where additional login methods should be injected; appending to `footer_elements` will place the form at the very bottom of the page's footer, which is almost certainly not rendered inside the login form area, or may not be rendered at all depending on the theme.

The correct approach is to use the Piwigo hook/event that the identification template actually exposes, which is typically `'login_bottom_buttons'` or similar, or to use `$template->concat('PLUGIN_INDEX_ACTIONS', ...)` for the index page, or to prepend to the identification template's own named block. Using `footer_elements` is not the documented way to inject content into the identification page.

**Fix:** Identify the correct Smarty block variable used by the identification template (commonly `PLUGIN_IDENTIFICATION_BEFORE` or via the correct `$template->concat()` target). Alternatively, use a `loc_end_identification` event or the `set_filenames` + `concat` pattern targeting the identification template's named injection point.

---

### BUG-05 — `never` return type requires PHP 8.1; Docker image may run PHP 7.x or 8.0

**File:** `magic_link_handler.php`, lines 34, 40; `verify.php`, line 28  
**Severity:** Critical (fatal parse error on PHP < 8.1)

The `never` return type was introduced in PHP 8.1. The linuxserver/piwigo Docker image has historically shipped with PHP 7.4 or PHP 8.0. If the container runs anything older than PHP 8.1, every request to `magic_link_handler.php` and `verify.php` will produce a fatal parse error, making the entire plugin non-functional.

**Fix:** Remove the `never` return type declarations and use `: void` instead, or simply omit the return type. The `exit` calls still guarantee the functions never return.

---

### BUG-06 — `single_insert()` does not escape values — raw values are embedded in SQL

**File:** `magic_link_handler.php`, line 116–122  
**Severity:** Critical (SQL injection via `$email` and `$ua_hash`)

Looking at `single_insert()`'s source:

```
$query .= "'".$value."'";
```

Values are **not** escaped — they are embedded directly into the SQL query with only surrounding single quotes. The `$email` value and `$ua_hash` value passed to `single_insert()` are used raw. While `$email` was validated as a valid email (so injection is unlikely via that field), `$ua_hash` is derived from `$_SERVER['HTTP_USER_AGENT']` which is passed through `hash('sha256', ...)` first and therefore safe. However, the more important issue is `$email` itself: an email like `a'b@c.com` would break the SQL. Strictly speaking, `single_insert` values should be pre-escaped via `pwg_db_real_escape_string()` when inserting untrusted data.

**Fix:** Escape values before passing to `single_insert()`:

```
single_insert(MAGIC_LINK_TOKENS_TABLE, [    'user_id'    => $user_id,    'email'      => pwg_db_real_escape_string($email),    'token_hash' => $token_hash,    'ua_hash'    => $ua_hash,    'expires_at' => date('Y-m-d H:i:s', strtotime('+15 minutes')),]);
```

---

## MEDIUM BUGS

---

### BUG-07 — `MAGIC_LINK_TOKENS_TABLE` constant is not defined in endpoint files

**File:** `magic_link_handler.php`, line 83, 95, 116; `verify.php`, line 67, 89  
**Severity:** Medium (fatal undefined constant error)

`MAGIC_LINK_TOKENS_TABLE` is defined in `main.inc.php` using `$prefixeTable`. That file is only loaded by Piwigo's plugin system when the plugin is activated through `common.inc.php`. In `magic_link_handler.php` and `verify.php`, the bootstrap is:

```
require_once PHPWG_ROOT_PATH . 'include/common.inc.php';
```

`common.inc.php` loads active plugins via `include/plugins.inc.php`, so `main.inc.php` **will** be loaded — **provided the plugin is currently activated**. However, if the plugin is deactivated, `MAGIC_LINK_TOKENS_TABLE` will be undefined and any query will cause a fatal error. More importantly, `main.inc.php` defines `MAGIC_LINK_TOKENS_TABLE` via `define()` at file-scope, and if `common.inc.php` loads main.inc.php, there is a double-definition risk when `magic_link_handler.php` also loads `include/functions.php` separately (line 21). While this won't cause a fatal error (constants can't be redefined silently), it is fragile.

**Fix:** Define `MAGIC_LINK_TOKENS_TABLE` explicitly at the top of both endpoint files after `$prefixeTable` is available from `common.inc.php`, or add a guard:

```
if (!defined('MAGIC_LINK_TOKENS_TABLE')) {    global $prefixeTable;    define('MAGIC_LINK_TOKENS_TABLE', $prefixeTable . 'magic_link_tokens');}
```

---

### BUG-08 — `mll_inject_form()` calls `get_absolute_root_url()` on the login page, which may return wrong path

**File:** `main.inc.php`, line 59  
**Severity:** Medium (handler URL in the form may be wrong)

The `MLL_HANDLER_URL` is built as:

```
get_absolute_root_url() . 'plugins/MagicLinkLogin/magic_link_handler.php'
```

When called from the login page (correctly bootstrapped through `index.php` or `identification.php`), `get_absolute_root_url()` should return the correct gallery root. However, `cookie_path()` behavior depends on `$page['root_path']` which is set by `section_init.inc.php`. If the identification page goes through `section_init.inc.php`, the URL should be correct. But if not (e.g., a direct load of `identification.php`), the URL may be wrong. This is a secondary concern compared to BUG-02, but worth noting.

**Fix:** Construct the handler URL using a known-good base, or verify that `identification.php` always includes `section_init.inc.php`.

---

### BUG-09 — `query2array()` call in `verify.php` uses wrong argument order

**File:** `verify.php`, lines 106–110  
**Severity:** Medium (wrong data structure returned; username uniqueness check broken)

The call is:

```
$existing = query2array(    "SELECT username FROM " . USERS_TABLE,    null,    'username');
```

Looking at `query2array()`'s signature: `query2array($query, $key_name=null, $value_name=null)`. With `$key_name=null` and `$value_name='username'`, this returns an array of `['username' => value]` strings (a flat array of values). That's actually correct for what `mll_unique_username()` expects (an array of username strings).

However, there is a performance issue: this fetches every username from the database with no limit. On a large gallery, this could be thousands of rows. This is not a correctness bug but a scalability issue.

Also, `USERS_TABLE` constant — this is a Piwigo core constant, which should be available after `common.inc.php` is loaded.

---

### BUG-10 — CSRF token check will fail on first request (empty session)

**File:** `magic_link_handler.php`, lines 50–53  
**Severity:** Medium (all magic link requests rejected until session is established)

```
if (!hash_equals($_SESSION['mll_csrf'] ?? '', $csrf)) {    mll_error('Invalid request.', 403);}
```

The CSRF token is generated in `mll_get_csrf_token()` (called in `mll_inject_form()` during the login page render) and stored in `$_SESSION['mll_csrf']`. However, `magic_link_handler.php` is a standalone endpoint that bootstraps via `common.inc.php`. If `common.inc.php` starts a PHP session correctly (which it does via `session_start()`), the session data from the login page should be available.

The real issue: if the user somehow submits the form before the session is created (e.g., direct POST without loading the form), `$_SESSION['mll_csrf']` will be empty string (`''`) and `$_POST['csrf_token']` will also be empty, making `hash_equals('', '')` return `true` — bypassing the CSRF check entirely.

**Fix:**

```
$session_token = $_SESSION['mll_csrf'] ?? '';if (empty($session_token) || !hash_equals($session_token, $csrf)) {    mll_error('Invalid request.', 403);}
```

---

### BUG-11 — `$raw_token` is appended to the verify URL without URL encoding

**File:** `magic_link_handler.php`, line 127  
**Severity:** Medium (token could be corrupted if it contains URL-unsafe characters)

```
$verify_url = get_absolute_root_url() . 'plugins/MagicLinkLogin/verify.php?token=' . $raw_token;
```

`mll_generate_token()` returns a hex string (`[0-9a-f]{64}`), which is URL-safe by definition. However, as a defensive practice the token should be passed through `rawurlencode()`. This is low risk given the current implementation but would become a real bug if the token generation were ever changed.

---

### BUG-12 — `find_user_by_username_or_email()` searches by username first, then email

**File:** `magic_link_handler.php`, line 106; `verify.php`, line 136  
**Severity:** Medium (wrong user returned if a username matches another user's email)

The function first queries by username equality, then by email. If a user has a username that happens to equal another user's email address, the function returns the wrong user. When the plugin calls this function with an email string, the intent is always to look up by email — but if a username happens to match, the function silently returns the wrong user. This is a Piwigo API limitation, but the plugin relies on it for correctness.

**Fix:** Query the database directly by email rather than relying on `find_user_by_username_or_email()`:

```
$user = pwg_db_fetch_assoc(pwg_query(sprintf(    "SELECT u.id, u.username FROM %s u WHERE u.mail_address = '%s'",    USERS_TABLE,    pwg_db_real_escape_string($email))));
```

---

### BUG-13 — `include/functions_mail.inc.php` is explicitly included in endpoint files but is already loaded by `common.inc.php`

**File:** `magic_link_handler.php`, line 20; `verify.php`, line 22  
**Severity:** Low-Medium (redundant, but not harmful since `require_once` is used)

Both endpoint files include `functions_mail.inc.php` explicitly:

```
require_once PHPWG_ROOT_PATH . 'include/functions_mail.inc.php';
```

Since `common.inc.php` already includes this file, the `require_once` guard prevents a double-load. No bug per se, but it is dead code that creates a false impression that the functions would not otherwise be available.

---

## LOW SEVERITY BUGS

---

### BUG-14 — JavaScript button text reset uses invalid Smarty syntax inside `<script>`

**File:** `template/magic_link_form.tpl`, line 78  
**Severity:** Low (button label broken on error; shows raw Smarty syntax or empty string)

```
btn.textContent = '{$smarty.const.l10n["Send login link"]|default:"Send login link"}';
```

This is inside a `<script>` block. Smarty will attempt to parse this as a template expression, but `{$smarty.const.l10n["Send login link"]}` is not valid Smarty syntax — `l10n` is a PHP array (not a constant), and `$smarty.const` is for PHP constants, not arrays. This will either render as empty string or cause a Smarty error.

**Fix:** Assign the translated label as a Smarty variable before the script block:

```
{$MLL_SEND_LABEL = 'Send login link'|translate}
```

Then in the JavaScript:

```
btn.textContent = '{$MLL_SEND_LABEL|escape:"javascript"}';
```

Or use `l10n()` in PHP and pass it via the `assign` array in `mll_inject_form()`.

---

### BUG-15 — Template uses Smarty 2 `translate` filter syntax, not Piwigo's `l10n` convention

**File:** `template/magic_link_form.tpl`, lines 4, 6, 18, 29, 37  
**Severity:** Low (translations may silently fall back to the raw string, depending on Piwigo version)

The form template uses syntax like `{'Passwordless login'|translate}`. Piwigo's Smarty environment typically registers `translate` as an alias for `l10n()`, so this should work. However, the `l10n()` translation keys must be registered via `add_language_file()` or the Piwigo languages system. The plugin never calls `add_language_file()` and has no `language/` directory. Since these strings are not in any translation file, `l10n()` (and the `translate` modifier) will just return the input string unchanged — which means English always, and "no translation" silently for other locales.

**Fix:** Either add a `language/` directory with `.lang.php` files and register them in `main.inc.php` via `load_language()`, or accept English-only and document it.

---

### BUG-16 — `mll_ok()` and `mll_error()` declared as `never` type; double-definition risk

**File:** `magic_link_handler.php`, lines 34–45  
**Severity:** Low (PHP < 8.1 parse error; see BUG-05 for the critical aspect)

Beyond the PHP version issue (BUG-05), `mll_ok()` and `mll_error()` are defined as file-level functions in `magic_link_handler.php`. If for any reason this file were included twice (which `require_once` prevents but error recovery paths might not), a fatal "Cannot redeclare function" error would occur.

---

### BUG-17 — `mll_error_page()` uses `global $template` but `$template` is not guaranteed to exist

**File:** `verify.php`, lines 28–48  
**Severity:** Low (unused variable causes no error, but signals incorrect intent)

`mll_error_page()` declares `global $template;` but never uses `$template` — the function renders its own inline HTML directly. The `global` declaration is dead code and is misleading (it suggests the function was intended to use Piwigo's template engine but doesn't).

**Fix:** Remove the `global $template;` line.

---

### BUG-18 — Rate limiting checks only unused and non-expired tokens; a used token within 60s does not trigger rate limiting

**File:** `magic_link_handler.php`, lines 75–90  
**Severity:** Low (minor rate limit bypass)

The rate-limit query has `AND used = 0 AND expires_at > NOW()`. If a user clicks their magic link immediately (marking the token as `used = 1`) and then requests a new one within 60 seconds, the rate limit does not trigger because the previous token is now `used`. The housekeeping DELETE at line 95 also removes it. The user can thus request a new token immediately after using one.

**Fix:** Remove the `AND used = 0 AND expires_at > NOW()` constraints from the rate-limit query, or add a `last_request_at` column to the token table.

---

### BUG-19 — `maintain.class.php` does not define `MLL_PATH` and relies on it being set

**File:** `maintain.class.php`  
**Severity:** Low (not a direct bug — Piwigo's plugin manager loads `maintain.class.php` after `main.inc.php`)

The class file has no dependency on `MLL_PATH` directly, so this is not a current bug. But the `install()` method uses `$this->table` which is populated in `__construct__` from `$prefixeTable`. If `$prefixeTable` is not yet set at construction time (which should not happen in normal Piwigo flow), the table name would be empty. This is safe in practice.

---

### BUG-20 — `verify.php` does not call `pwg_activity()` or `trigger_notify()` for the login event

**File:** `verify.php`  
**Severity:** Low (login activity not logged; admin activity feed is incomplete)

`log_user()` internally calls `pwg_activity('user', $user['id'], 'login')` and `trigger_notify('user_login', ...)`, so this is actually already handled inside `log_user()`. No bug — just noting it for awareness.

---

### BUG-21 — `single_update()` treats `1` (integer) as truthy but its SQL serialization may be wrong

**File:** `verify.php`, line 89–93  
**Severity:** Low (marking token as used may silently fail)

```
single_update(    MAGIC_LINK_TOKENS_TABLE,    ['used' => 1],    ['id'   => (int) $row['id']]);
```

Looking at `single_update()` source:

```
if (isset($value) and $value !== ''){    $query .= ... protect_column_name($key) . " = '" . $value . "'";}else{    $query .= ... protect_column_name($key) . " = NULL";}
```

The value `1` is an integer, `isset(1)` is `true`, and `1 !== ''` is `true`, so it correctly generates `SET used = '1'`. MySQL accepts `'1'` for a `tinyint(1)` column. This is fine.

---

## SUMMARY TABLE

|#|File|Line(s)|Severity|Issue|
|---|---|---|---|---|
|BUG-01|`magic_link_handler.php`|136–144|**Critical**|`pwg_mail()` $tpl dirname is missing the `text/html` subdirectory — template never renders|
|BUG-02|`magic_link_handler.php`, `verify.php`|127, 134, 154, 158|**Critical**|`get_absolute_root_url()` returns wrong URL in standalone endpoint files|
|BUG-03|`verify.php`|118–143|**Critical**|`register_user()` return value ignored; fragile ID re-fetch introduces race condition|
|BUG-04|`main.inc.php`|64|**Critical**|`footer_elements` is not the correct injection point for the identification page form|
|BUG-05|`magic_link_handler.php`, `verify.php`|34, 40, 28|**Critical**|`never` return type requires PHP 8.1; breaks on PHP 7.x/8.0|
|BUG-06|`magic_link_handler.php`|116–122|**Critical**|`single_insert()` does not escape values; SQL injection risk on email field|
|BUG-07|`magic_link_handler.php`, `verify.php`|multiple|**Medium**|`MAGIC_LINK_TOKENS_TABLE` undefined if plugin is deactivated|
|BUG-08|`main.inc.php`|59|**Medium**|Handler URL may be wrong if `$page['root_path']` is not set|
|BUG-09|`verify.php`|106–110|**Medium**|Fetching all usernames is a scalability problem on large galleries|
|BUG-10|`magic_link_handler.php`|50–53|**Medium**|Empty CSRF token matches empty session token — CSRF bypass on direct POST|
|BUG-11|`magic_link_handler.php`|127|**Medium**|Token not URL-encoded in verify URL (safe now, fragile in future)|
|BUG-12|`magic_link_handler.php`, `verify.php`|106, 136|**Medium**|`find_user_by_username_or_email()` may return wrong user if username=someone's email|
|BUG-13|`magic_link_handler.php`, `verify.php`|20, 22|**Low-Med**|Redundant explicit include of `functions_mail.inc.php`|
|BUG-14|`magic_link_form.tpl`|78|**Low**|Invalid Smarty expression in JavaScript resets button to empty/broken label|
|BUG-15|`magic_link_form.tpl`|4, 6, 18, 29, 37|**Low**|Strings not registered in Piwigo's l10n system; no translation support|
|BUG-16|`magic_link_handler.php`|34–45|**Low**|`never` return type compound issue (see BUG-05)|
|BUG-17|`verify.php`|28|**Low**|Dead `global $template;` in `mll_error_page()`|
|BUG-18|`magic_link_handler.php`|75–90|**Low**|Rate limit bypassed after token is used within 60s window|
|BUG-19|`maintain.class.php`|15|**Low**|Minor dependency ordering concern (safe in practice)|

---

## ROOT CAUSE OF THE REPORTED SYMPTOM

The email body showing `"Sign in to http://localhost/plugins/MagicLinkLogin/ / Your magic login link"` is caused by two bugs acting together:

1. **BUG-01**: The template directory path is wrong (`template/` instead of `template/text/html/`), so Piwigo's `pwg_mail()` cannot find the `.tpl` file. The template is never rendered. Instead, `pwg_mail()` falls back to using only the `$args` array fields: it concatenates `mail_title` (which is `l10n('Sign in to') . ' ' . get_absolute_root_url()`) and `subject` (`l10n('Your magic login link')`) as raw body text.
2. **BUG-02**: `get_absolute_root_url()` is called from inside `magic_link_handler.php`, where `cookie_path()` resolves to the plugin endpoint's path rather than the gallery root, producing `http://localhost/plugins/MagicLinkLogin/` instead of `http://localhost/`.

Fix BUG-01 first (create the subdirectory structure) and BUG-02 second (correct the URL computation) to resolve the reported symptom.  
agentId: a318ea21a5c940d99 (use SendMessage with to: 'a318ea21a5c940d99' to continue this agent)  
<usage>total_tokens: 47968  
tool_uses: 32  
duration_ms: 333349</usage>